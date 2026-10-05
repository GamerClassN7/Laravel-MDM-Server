<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\DeviceSecurity;
use App\Livewire\SecurityScan\Page;
use App\Livewire\SecurityScan\RuleForm;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\NotificationSetting;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use App\Models\User;
use App\Support\AlertEvaluator;
use App\Support\SecurityParsers;
use App\Support\SecurityRules;
use App\Support\SecurityScanner;
use App\Support\SmartAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** The security scanner: its rule language, the inventory from the agent, findings and events. */
class SecurityScannerTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private function device(string $token = 'a', string $platform = 'windows'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = "pc-$token";
        $device->data = json_encode(['machine' => ['Hostname' => "pc-$token", 'AgentVersion' => '1.17.0', 'Platform' => $platform, 'Drives' => []]]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    private function inventory(array $overrides = []): array
    {
        return $overrides + [
            'software' => [
                ['Name' => 'AnyDesk', 'Version' => '8.0.9', 'Publisher' => 'AnyDesk Software GmbH', 'Source' => 'registry'],
                ['Name' => 'WinRAR 6.02 (64-bit)', 'Version' => '6.02.0', 'Publisher' => 'win.rar GmbH', 'Source' => 'registry'],
                ['Name' => '7-Zip 24.09 (x64)', 'Version' => '24.09', 'Publisher' => 'Igor Pavlov', 'Source' => 'registry'],
                ['Name' => 'Age of Empires II', 'Version' => '1.0', 'Publisher' => 'Microsoft', 'Source' => 'registry'],
            ],
            'processes' => [
                ['Name' => 'powershell.exe', 'Path' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', 'CommandLine' => 'powershell.exe -nop -w hidden -enc SQBFAFgAIAAoAE4AZQB3AC0ATwBiAGoA', 'User' => 'CORP\\eve', 'Count' => 1],
                ['Name' => 'explorer.exe', 'Path' => 'C:\\Windows\\explorer.exe', 'CommandLine' => 'C:\\Windows\\Explorer.EXE', 'User' => 'CORP\\eve', 'Count' => 1],
            ],
            'listening' => [
                ['Protocol' => 'tcp', 'Address' => '0.0.0.0', 'Port' => 23, 'Process' => 'tlntsvr.exe'],
                ['Protocol' => 'tcp', 'Address' => '127.0.0.1', 'Port' => 3306, 'Process' => 'mysqld.exe'],
            ],
            'admins' => [['Name' => 'Administrator', 'Source' => 'local', 'Enabled' => false], ['Name' => 'eve', 'Source' => 'local', 'Enabled' => true]],
            'startup' => [],
            'posture' => ['FirewallEnabled' => false, 'AntivirusName' => 'Microsoft Defender', 'AntivirusEnabled' => true, 'RealTimeProtection' => true, 'Smb1Enabled' => false],
            // Raw Windows events: 25 failed sign-ins of administrator from outside, 2 of eve, one
            // successful sign-in no parser reads.
            'logs' => [['source' => 'windows.security', 'records' => [
                ...$this->failedLogons(25, 'administrator', '203.0.113.7'),
                ...$this->failedLogons(2, 'eve', '-'),
                ['Time' => now()->toIso8601String(), 'Id' => 4624, 'Provider' => 'Microsoft-Windows-Security-Auditing', 'Data' => ['TargetUserName' => 'eve']],
            ]]],
        ];
    }

    private function failedLogons(int $count, string $user, string $ip): array
    {
        return array_fill(0, $count, [
            'Time' => now()->subMinutes(5)->toIso8601String(), 'Id' => 4625, 'Provider' => 'Microsoft-Windows-Security-Auditing', 'Level' => 0,
            'Data' => ['TargetUserName' => $user, 'IpAddress' => $ip, 'WorkstationName' => 'EVE-PC', 'LogonType' => '10'],
        ]);
    }

    /**
     * Sends a collection as the agent does (inventory and raw logs), which the server only takes
     * into its cache; then processes what waits. What it opened and resolved.
     */
    private function send(Device $device, array $inventory, string $token = 'a'): array
    {
        $logs = $inventory['logs'] ?? [];
        unset($inventory['logs']);
        $open = SecurityFinding::query()->open()->pluck('id');
        $this->signedJson('POST', '/api/device/security', ['inventory' => $inventory, 'logs' => $logs], $token)->assertStatus(202)->assertJson(['queued' => true]);
        \App\Support\SecurityInbox::drain();
        $this->assertSame(0, \App\Support\SecurityInbox::pending());

        return [
            'opened' => SecurityFinding::query()->open()->whereNotIn('id', $open)->count(),
            'resolved' => SecurityFinding::query()->whereIn('id', $open)->whereNotNull('resolved_at')->count(),
        ];
    }

    private function assertCounts(array $expected, array $actual): void
    {
        $this->assertSame($expected, array_intersect_key($actual, $expected));
    }

    private function openKeys(Device $device): array
    {
        return SecurityFinding::query()->open()->where('device_id', $device->id)->with('rule')->get()->map(fn ($finding) => $finding->rule->key)->sort()->values()->all();
    }

    public function test_the_built_in_rules_are_valid(): void
    {
        $rules = json_decode(file_get_contents(resource_path('security/rules.json')), true);
        $this->assertNotEmpty($rules);
        $keys = [];
        foreach ($rules as $rule) {
            $this->assertSame([], SecurityRules::errors($rule), 'Rule '.($rule['key'] ?? '?'));
            $keys[] = $rule['key'];
        }
        $this->assertSame(count($keys), count(array_unique($keys)), 'Rule keys are unique');

        $parsers = json_decode(file_get_contents(resource_path('security/parsers.json')), true);
        foreach ($parsers as $parser) {
            $this->assertSame([], SecurityParsers::errors($parser), 'Parser '.($parser['key'] ?? '?'));
            $keys[] = $parser['key'];
        }
        $this->assertSame(count($keys), count(array_unique($keys)), 'Rule and parser keys are unique');

        SecurityRule::syncBuiltIn();
        $this->assertSame(count($rules), SecurityRule::query()->detection()->where('built_in', true)->count());
        $this->assertSame(count($parsers), SecurityRule::query()->parsers()->where('built_in', true)->count());
        $this->assertFalse(SecurityRule::query()->where('key', 'events.service-installed')->value('enabled'));
    }

    public function test_the_rule_language(): void
    {
        $item = ['Name' => 'WinRAR 6.02', 'Version' => '6.02.0', 'Port' => 23, 'On' => false, 'Nested' => ['Value' => 'Deep']];
        $test = fn (array $condition) => SecurityRules::test($condition, $item);

        $this->assertTrue($test(['field' => 'Name', 'op' => 'contains', 'value' => 'winrar']));
        $this->assertTrue($test(['field' => 'Name', 'op' => 'starts_with', 'value' => 'WINRAR']));
        $this->assertTrue($test(['field' => 'Name', 'op' => 'matches', 'value' => '^win(rar|zip)\b']));
        $this->assertFalse($test(['field' => 'Name', 'op' => 'not_matches', 'value' => 'rar']));
        $this->assertTrue($test(['field' => 'Version', 'op' => 'version_lt', 'value' => '6.23']));
        $this->assertFalse($test(['field' => 'Version', 'op' => 'version_gte', 'value' => '6.23']));
        $this->assertTrue($test(['field' => 'Port', 'op' => 'in', 'value' => [21, 23]]));
        $this->assertTrue($test(['field' => 'Port', 'op' => 'eq', 'value' => '23']));
        $this->assertTrue($test(['field' => 'Port', 'op' => 'lte', 'value' => 23]));
        $this->assertTrue($test(['field' => 'On', 'op' => 'false']));
        $this->assertTrue($test(['field' => 'On', 'op' => 'eq', 'value' => false]));
        // Unknown is neither true nor false.
        $this->assertFalse($test(['field' => 'Missing', 'op' => 'false']));
        $this->assertTrue($test(['field' => 'Missing', 'op' => 'empty']));
        $this->assertTrue($test(['field' => 'Nested.Value', 'op' => 'eq', 'value' => 'deep']));
        $this->assertTrue($test(['all' => [
            ['field' => 'Name', 'op' => 'exists'],
            ['any' => [['field' => 'Port', 'op' => 'eq', 'value' => 80], ['not' => ['field' => 'Port', 'op' => 'gt', 'value' => 100]]]],
        ]]));
        $this->assertSame('WinRAR 6.02 on 23', SecurityRules::render('{Name} on {Port} {Unknown}', $item));
        $this->assertTrue(SecurityRules::test(['field' => 'Version', 'op' => 'version_lt', 'value' => '1:2.0'], ['Version' => '2:1.9-1ubuntu1']));

        $valid = ['key' => 'custom.x', 'name' => 'X', 'severity' => 'low', 'source' => 'software', 'when' => ['field' => 'Name', 'op' => 'eq', 'value' => 'x']];
        $this->assertSame([], SecurityRules::errors($valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => []]));
        $this->assertNotEmpty(SecurityRules::errors(['source' => 'files'] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['field' => 'Name', 'op' => 'matches', 'value' => '(']] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['field' => 'Name', 'op' => 'in', 'value' => 'x']] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['all' => []]] + $valid));
        $this->assertNotEmpty(SecurityRules::errors(['when' => ['field' => 'Name', 'op' => 'eq']] + $valid));
        $deep = ['field' => 'Name', 'op' => 'exists'];
        for ($i = 0; $i < 8; $i++) {
            $deep = ['not' => $deep];
        }
        $this->assertNotEmpty(SecurityRules::errors(['when' => $deep] + $valid));
    }

    public function test_parsers_turn_raw_logs_into_events(): void
    {
        SecurityRule::syncBuiltIn();
        $parsers = SecurityRule::query()->parsers()->enabled()->pluck('definition');
        $line = fn (string $identifier, string $message, string $time = '2026-10-05T10:00:00Z') => ['Time' => $time, 'Identifier' => $identifier, 'Pid' => 1, 'Message' => $message];
        $events = SecurityParsers::run([
            ['source' => 'linux.auth', 'records' => [
                $line('sshd', 'Failed password for invalid user admin from 203.0.113.7 port 4711 ssh2'),
                $line('sshd', 'Failed password for invalid user ADMIN from 203.0.113.7 port 4712 ssh2', '2026-10-05T10:05:00Z'),
                $line('sshd', 'Accepted publickey for alice from 192.0.2.1 port 22 ssh2'),
                $line('sudo', '   alice : 3 incorrect password attempts ; TTY=pts/0 ; PWD=/home/alice ; USER=root ; COMMAND=/bin/bash'),
                $line('useradd', 'new user: name=mallory, UID=1001, GID=1001, home=/home/mallory'),
                $line('usermod', "add 'mallory' to group 'sudo'"),
                $line('usermod', "add 'mallory' to shadow group 'sudo'"),
                'not a record',
            ]],
            ['source' => 'windows.security', 'records' => [
                ['Time' => '2026-10-05T10:00:00Z', 'Id' => 4625, 'Data' => ['TargetUserName' => 'bob', 'IpAddress' => '-', 'WorkstationName' => 'KIOSK', 'LogonType' => '2']],
                ['Time' => '2026-10-05T10:00:00Z', 'Id' => 4732, 'Data' => ['TargetSid' => 'S-1-5-32-555', 'MemberName' => '-', 'MemberSid' => 'S-1-5-21-1-2-3-1001']],
                ['Time' => '2026-10-05T10:00:00Z', 'Id' => 4732, 'Data' => ['TargetSid' => 'S-1-5-32-544', 'MemberName' => '-', 'MemberSid' => 'S-1-5-21-1-2-3-1001', 'SubjectUserName' => 'helpdesk']],
            ]],
            // A log no parser reads.
            ['source' => 'windows.powershell', 'records' => [['Id' => 4104]]],
        ], $parsers);

        $byType = collect($events)->groupBy('Type');
        $this->assertSame(['failed_logon', 'sudo_failed', 'account_created', 'admin_added'], $byType->keys()->all());
        // Two attempts of the same user (case ignored) from the same address are one event.
        $ssh = $byType['failed_logon']->firstWhere('Source', '203.0.113.7');
        $this->assertSame(2, $ssh['Count']);
        $this->assertSame('2026-10-05T10:05:00Z', $ssh['Last']);
        $this->assertSame('ssh: failed password for ADMIN', $ssh['Message']);
        // {IpAddress|WorkstationName}: "-" is not set.
        $this->assertSame('KIOSK', $byType['failed_logon']->firstWhere('User', 'bob')['Source']);
        $this->assertSame(3, $byType['sudo_failed'][0]['Count']);
        $this->assertSame('mallory', $byType['account_created'][0]['User']);
        // Only the Administrators group, and usermod's shadow line is not counted twice.
        $this->assertSame(['mallory', 'S-1-5-21-1-2-3-1001'], $byType['admin_added']->pluck('User')->all());
        $this->assertSame('helpdesk added S-1-5-21-1-2-3-1001 to Administrators', $byType['admin_added'][1]['Message']);

        $this->assertNotEmpty(SecurityParsers::errors(['kind' => 'parser', 'key' => 'x.y', 'name' => 'X', 'source' => 'linux.auth', 'event' => ['type' => 'x_y']]));
        $this->assertNotEmpty(SecurityParsers::errors(['kind' => 'parser', 'key' => 'x.y', 'name' => 'X', 'source' => 'linux.auth', 'pattern' => '(', 'event' => ['type' => 'x_y']]));
        $this->assertNotEmpty(SecurityParsers::errors(['kind' => 'parser', 'key' => 'x.y', 'name' => 'X', 'source' => 'linux.auth', 'pattern' => 'x', 'event' => ['type' => 'X Y', 'extra' => '']]));
    }

    public function test_collections_wait_in_the_cache_until_they_are_processed(): void
    {
        $device = $this->device();
        $this->withoutDefer();
        \App\Support\SecurityInbox::push($device, json_encode(['inventory' => $this->inventory(['logs' => null])]));
        \App\Support\SecurityInbox::push($device, '{"inventory": {"software": []}}');
        $gone = $this->device('gone');
        \App\Support\SecurityInbox::push($gone, json_encode(['inventory' => $this->inventory()]));
        $gone->delete();
        $this->assertSame(3, \App\Support\SecurityInbox::pending());
        $this->assertSame(0, SecurityFinding::query()->count());

        // In order: the second collection has no AnyDesk anymore; the device that is gone is skipped.
        $this->assertSame(3, \App\Support\SecurityInbox::drain());
        $this->assertSame(0, \App\Support\SecurityInbox::pending());
        $this->assertNotContains('software.remote-access', $this->openKeys($device));
        $this->assertSame(1, SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'software.remote-access'))->whereNotNull('resolved_at')->count());
        $this->assertSame(0, \App\Support\SecurityInbox::drain());
    }

    public function test_an_inventory_opens_findings_and_the_next_one_resolves_them(): void
    {
        $device = $this->device();
        $this->assertCounts(['resolved' => 0], $this->send($device, $this->inventory()));

        $this->assertSame([
            'events.brute-force', 'listening.cleartext', 'posture.firewall-windows', 'process.encoded-powershell',
            'software.remote-access', 'software.winrar-cve-2023-38831',
        ], $this->openKeys($device));
        // Age of Empires is no attack framework, 7-Zip 24.09 is fixed, MySQL listens on loopback only.
        $finding = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'software.remote-access'))->first();
        $this->assertSame('AnyDesk 8.0.9 is installed', $finding->message);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame('203.0.113.7', SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'events.brute-force'))->first()->details['Source']);
        // Events of a known type are kept for the timeline.
        $this->assertSame(2, SecurityEvent::query()->where('device_id', $device->id)->count());
        $this->assertSame('AnyDesk', $device->securityInventory()->first()->data['software'][0]['Name']);
        $this->assertArrayNotHasKey('events', $device->securityInventory()->first()->data);

        // Next collection: AnyDesk updated (same finding), WinRAR updated, firewall on, no new events.
        $next = $this->inventory([
            'software' => [['Name' => 'AnyDesk', 'Version' => '9.0.1', 'Source' => 'registry'], ['Name' => 'WinRAR 7.01 (64-bit)', 'Version' => '7.01.0', 'Source' => 'registry']],
            'posture' => ['FirewallEnabled' => true],
            'logs' => [],
        ]);
        $this->assertCounts(['opened' => 0, 'resolved' => 2], $this->send($device, $next));
        $this->assertSame(['events.brute-force', 'listening.cleartext', 'process.encoded-powershell', 'software.remote-access'], $this->openKeys($device));
        $this->assertSame($finding->id, $finding->fresh()->id);
        $this->assertSame('AnyDesk 9.0.1 is installed', $finding->fresh()->message);
        // The brute force finding stays until it is acknowledged; it was seen once.
        $bruteForce = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'events.brute-force'))->first();
        $this->assertNull($bruteForce->resolved_at);

        // The same attack again: the finding counts it.
        $this->send($device, $this->inventory(['logs' => [['source' => 'windows.security', 'records' => $this->failedLogons(40, 'Administrator', '203.0.113.7')]]]));
        $this->assertSame(2, $bruteForce->fresh()->occurrences);
        $this->assertSame('40 failed sign-ins of Administrator from 203.0.113.7', $bruteForce->fresh()->message);
    }

    public function test_the_endpoint_needs_a_signing_agent(): void
    {
        $device = $this->device();
        $this->signedJson('POST', '/api/device/security', ['inventory' => $this->inventory()], 'wrong')->assertUnauthorized();
        $this->signedJson('POST', '/api/device/security', [], 'a')->assertStatus(422);
        $this->assertSame(0, \App\Support\SecurityInbox::pending());
        $this->assertSame(0, SecurityFinding::query()->count());
    }

    public function test_rules_follow_the_platform_and_thresholds(): void
    {
        $linux = $this->device('l', 'linux');
        $inventory = $this->inventory([
            'processes' => [['Name' => 'kworkerds', 'Path' => '/tmp/.x/kworkerds', 'CommandLine' => '/tmp/.x/kworkerds -o stratum+tcp://pool.example:3333', 'User' => 'www-data']],
            'admins' => [['Name' => 'root'], ['Name' => 'alice'], ['Name' => 'bob'], ['Name' => 'carol', 'Enabled' => true], ['Name' => 'old', 'Enabled' => false]],
            'posture' => ['FirewallEnabled' => false, 'SshRootLogin' => 'yes', 'SshPasswordAuthentication' => true],
            'logs' => [],
        ]);
        $this->send($linux, $inventory, 'l');

        $keys = $this->openKeys($linux);
        // Windows rules (WinRAR, the Windows firewall) do not apply.
        $this->assertNotContains('software.winrar-cve-2023-38831', $keys);
        $this->assertNotContains('posture.firewall-windows', $keys);
        $this->assertContains('posture.firewall-linux', $keys);
        $this->assertContains('posture.ssh-root-login', $keys);
        $this->assertContains('process.temp-folder-linux', $keys);
        $this->assertContains('process.miner', $keys);
        $admins = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'admins.many'))->get();
        $this->assertCount(1, $admins);
        $this->assertSame('4 administrator accounts: root, alice, bob, carol', $admins[0]->message);
    }

    public function test_acknowledging_quiets_findings_until_they_are_gone(): void
    {
        Http::fake();
        $user = User::factory()->create();
        NotificationSetting::create(['user_id' => $user->id, 'urls' => ['ntfy://ntfy.sh/topic']]);
        AlertRule::create(['user_id' => $user->id, 'type' => 'security', 'target' => ['all' => true]]);
        $device = $this->device();
        $this->send($device, $this->inventory());

        // High findings (telnet, brute force, encoded PowerShell, WinRAR, firewall) fire the alert.
        $this->assertSame(['triggered' => 1, 'resolved' => 0], AlertEvaluator::run());
        $this->assertContains('security', array_column(SmartAlerts::for($device), 'key'));
        $this->assertGreaterThan(0, SecurityFinding::severeCount());

        $this->actingAs($user);
        $page = Livewire::test(Page::class)->assertSee('AnyDesk 8.0.9 is installed');
        foreach (SecurityFinding::query()->active()->get() as $finding) {
            $page->call('acknowledge', $finding->id);
        }
        $this->assertSame(['triggered' => 0, 'resolved' => 1], AlertEvaluator::run());
        $this->assertNotContains('security', array_column(SmartAlerts::for($device), 'key'));

        // Acknowledged events are done; acknowledged inventory findings stay open, quietly.
        $bruteForce = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'events.brute-force'))->first();
        $this->assertNotNull($bruteForce->resolved_at);
        $telnet = SecurityFinding::query()->whereHas('rule', fn ($q) => $q->where('key', 'listening.cleartext'))->first();
        $this->assertNull($telnet->resolved_at);
        $this->assertCounts(['opened' => 0], $this->send($device, $this->inventory(['logs' => []])));
        $this->assertNull($telnet->fresh()->resolved_at);
        $this->assertNotNull($telnet->fresh()->acknowledged_at);

        // Gone, then back: a new finding that needs attention.
        $this->send($device, $this->inventory(['listening' => [], 'logs' => []]));
        $this->assertNotNull($telnet->fresh()->resolved_at);
        $this->assertCounts(['opened' => 1], $this->send($device, $this->inventory(['logs' => []])));
        $this->assertSame(1, SecurityFinding::query()->active()->whereHas('rule', fn ($q) => $q->where('key', 'listening.cleartext'))->count());

        $page->call('reopen', $bruteForce->id);
        $this->assertNull($bruteForce->fresh()->resolved_at);
        $this->assertNull($bruteForce->fresh()->acknowledged_at);
    }

    public function test_system_admins_manage_the_rules(): void
    {
        $device = $this->device();
        $this->send($device, $this->inventory());
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);

        // A rule switched off takes its findings with it.
        $rule = SecurityRule::query()->where('key', 'software.remote-access')->first();
        $this->actingAs($admin);
        Livewire::test(Page::class, ['tab' => 'rules'])->assertSee($rule->name)->call('toggleRule', $rule->id);
        $this->assertNotContains('software.remote-access', $this->openKeys($device));

        // Its own rule in JSON, tried on the device before it is saved.
        $definition = [
            'key' => 'custom.games', 'name' => 'Games', 'severity' => 'info', 'platform' => 'windows', 'source' => 'software',
            'when' => ['field' => 'Name', 'op' => 'matches', 'value' => 'age of empires'],
            'message' => 'Game: {Name}',
        ];
        Livewire::test(RuleForm::class)
            ->set('json', '{"key": ')
            ->assertSee('Not valid JSON')
            ->set('previewDeviceId', $device->id)
            ->set('json', json_encode($definition))
            ->assertSee('The rule is valid.')
            ->assertSee('Game: Age of Empires II')
            ->call('save')
            ->assertDispatched('securityRuleSaved');
        $this->assertContains('custom.games', $this->openKeys($device));

        // A parser of its own, tried on a sample line.
        Livewire::test(RuleForm::class, ['kind' => 'parser'])
            ->assertSee('The rule is valid.')
            ->assertSee('alice failed su to root')
            ->set('sample', 'something else')
            ->assertSee('The parser does not match this record.')
            ->call('save');
        $this->assertTrue(SecurityRule::query()->parsers()->where('key', 'custom.su-failed')->exists());
        Livewire::test(Page::class, ['tab' => 'rules'])->assertSee('su: wrong password');

        // Built-in rules cannot be changed, copies can.
        $builtIn = SecurityRule::query()->where('key', 'posture.smb1')->first();
        Livewire::test(RuleForm::class, ['ruleId' => $builtIn->id])->assertSet('readOnly', true)
            ->set('json', json_encode(['severity' => 'info'] + $builtIn->definition))->call('save');
        $this->assertSame('high', $builtIn->fresh()->severity);
        Livewire::test(RuleForm::class, ['copyOf' => $builtIn->id])
            ->assertSet('readOnly', false)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue(SecurityRule::query()->where('key', 'custom.posture.smb1-copy')->exists());
        // A key that is taken.
        Livewire::test(RuleForm::class)->set('json', json_encode(['key' => 'posture.smb1'] + $definition))->assertSee('another rule has this key');

        // Users see the rules, but do not change them.
        $this->actingAs(User::factory()->create());
        Livewire::test(Page::class, ['tab' => 'rules'])->call('toggleRule', $builtIn->id)->assertForbidden();
        Livewire::test(RuleForm::class)->assertForbidden();
    }

    public function test_the_pages_show_the_findings(): void
    {
        $this->withoutVite();
        $device = $this->device();
        $this->send($device, $this->inventory());
        $user = User::factory()->create();

        $this->actingAs($user)->get('/security')->assertOk()->assertSee('AnyDesk 8.0.9 is installed');
        $this->actingAs($user)->get('/security?tab=events')->assertOk()->assertSee('203.0.113.7');
        $this->actingAs($user)->get('/security?tab=rules')->assertOk()->assertSee('Remote access tool');
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])->assertSee('security-tab-pane', false);
        Livewire::test(DeviceSecurity::class, ['deviceId' => $device->id])
            ->assertSee('AnyDesk 8.0.9 is installed')
            ->assertSee('Microsoft Defender')
            ->assertSee('tlntsvr.exe');
        Livewire::test(Page::class)->set('search', 'winrar')->assertSee('WinRAR')->assertDontSee('AnyDesk 8.0.9');
        Livewire::test(Page::class)->set('severity', 'critical')->assertSee('Nothing needs attention');
    }

    public function test_scanning_without_an_inventory_does_nothing(): void
    {
        $device = $this->device();
        $this->assertSame(['opened' => 0, 'resolved' => 0], SecurityScanner::scan($device));
        $this->assertSame(['opened' => 0, 'resolved' => 0], SecurityScanner::scanAll());
    }
}
