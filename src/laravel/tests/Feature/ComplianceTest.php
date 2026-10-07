<?php

namespace Tests\Feature;

use App\Livewire\DeviceSecurity;
use App\Livewire\SecurityScan\Page;
use App\Livewire\SecurityScan\PolicyForm;
use App\Models\CompliancePolicy;
use App\Models\ComplianceResult;
use App\Models\Device;
use App\Models\User;
use App\Support\CompliancePolicies;
use App\Support\SecurityFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\Concerns\SimulatesSecurityAgent;
use Tests\TestCase;

/** Compliance policies: their language, the results on the devices, the feed and the pages. */
class ComplianceTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests, SimulatesSecurityAgent;

    private function device(string $token = 'a', string $platform = 'windows'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = "pc-$token";
        $device->data = json_encode(['machine' => ['Hostname' => "pc-$token", 'AgentVersion' => '1.18.0', 'Platform' => $platform, 'Drives' => [], 'Features' => ['security_logs' => 'off']]]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    /** A SQL Server instance as the agent reports it, everything as CIS wants it unless overridden. */
    private function instance(array $overrides = []): array
    {
        return $overrides + [
            'Instance' => 'MSSQLSERVER', 'Version' => '16.0.4135.4', 'Edition' => 'Standard Edition (64-bit)', 'Clustered' => false,
            'AdHocDistributedQueries' => 0, 'ClrEnabled' => 0, 'CrossDbOwnershipChaining' => 0, 'DatabaseMailXps' => 0, 'OleAutomationProcedures' => 0,
            'RemoteAccess' => 0, 'RemoteAdminConnections' => 0, 'ScanForStartupProcs' => 0, 'XpCmdshell' => 0,
            'TrustworthyCount' => 0, 'SaEnabled' => false, 'SaName' => 'admin_x', 'WindowsAuthOnly' => true,
            'SysadminCount' => 2, 'WeakPolicyCount' => 0, 'NoFullBackupCount' => 0, 'NoLogBackupCount' => 0, 'NoCheckDbCount' => 0,
            'LinkedServerSaCount' => 0, 'OwnedBySaCount' => 0, 'ForceEncryption' => true, 'NoTdeCount' => 0, 'ErrorLogCount' => 12, 'LoginAuditLevel' => 'Both',
        ];
    }

    private function sqlPolicy(): array
    {
        return [
            'key' => 'cis-mssql-test',
            'name' => 'CIS SQL Server (test)',
            'platform' => 'windows',
            'source' => 'sqlserver',
            'manual' => ['field' => 'Error', 'op' => 'exists'],
            'checks' => [
                ['id' => 'Sql.XpCmdshellDisabled', 'name' => 'xp_cmdshell is disabled', 'reference' => 'CIS 2.15', 'severity' => 'critical',
                    'fail' => ['field' => 'XpCmdshell', 'op' => 'ne', 'value' => 0], 'message' => '{Instance}: xp_cmdshell is {XpCmdshell}'],
                ['id' => 'Sql.TrustworthyOff', 'name' => 'TRUSTWORTHY is off', 'reference' => 'CIS 2.9', 'severity' => 'high',
                    'fail' => ['field' => 'TrustworthyCount', 'op' => 'gt', 'value' => 0], 'message' => '{Instance}: TRUSTWORTHY on {TrustworthyDatabases}'],
                ['id' => 'Sql.RemoteAdminConnectionsDisabled', 'name' => 'Remote admin connections are off', 'reference' => 'CIS 2.7', 'severity' => 'medium',
                    'applies' => ['field' => 'Clustered', 'op' => 'false'], 'fail' => ['field' => 'RemoteAdminConnections', 'op' => 'ne', 'value' => 0]],
                ['id' => 'Sql.WindowsAuthOnly', 'name' => 'Windows authentication only', 'reference' => 'CIS 3.1', 'severity' => 'medium',
                    'warn' => ['field' => 'WindowsAuthOnly', 'op' => 'false']],
            ],
        ];
    }

    private function statuses(Device $device): array
    {
        return ComplianceResult::query()->where('device_id', $device->id)->pluck('status', 'check_id')->sortKeys()->all();
    }

    public function test_the_policy_language(): void
    {
        $this->assertSame([], CompliancePolicies::errors($this->sqlPolicy()));
        $this->assertNotEmpty(CompliancePolicies::errors([]));
        $broken = $this->sqlPolicy();
        $broken['checks'][] = $broken['checks'][0];
        $broken['checks'][1]['fail'] = ['field' => 'XpCmdshell', 'op' => 'nope'];
        unset($broken['checks'][2]['fail']);
        $broken['checks'][3]['source'] = 'events';
        $errors = implode("\n", CompliancePolicies::errors($broken));
        $this->assertStringContainsString('is there twice', $errors);
        $this->assertStringContainsString('Sql.TrustworthyOff.fail', $errors);
        $this->assertStringContainsString('"fail" or "warn" is needed', $errors);
        $this->assertStringContainsString('Sql.WindowsAuthOnly.source', $errors);

        $policy = $this->sqlPolicy();
        $check = fn (string $id) => collect($policy['checks'])->firstWhere('id', $id);
        $status = fn (string $id, array $items) => CompliancePolicies::evaluateCheck($policy, $check($id), $items)['status'];

        $this->assertSame('pass', $status('Sql.XpCmdshellDisabled', [$this->instance()]));
        $this->assertSame('fail', $status('Sql.XpCmdshellDisabled', [$this->instance(), $this->instance(['Instance' => 'B', 'XpCmdshell' => 1])]));
        // Not reported: it cannot be told, so it does not pass.
        $this->assertSame('manual', $status('Sql.XpCmdshellDisabled', [$this->instance(['XpCmdshell' => null])]));
        // The policy's "manual": an instance the agent could not connect to.
        $this->assertSame('manual', $status('Sql.XpCmdshellDisabled', [$this->instance(['Error' => 'Cannot connect', 'XpCmdshell' => 1])]));
        $this->assertSame('na', $status('Sql.RemoteAdminConnectionsDisabled', [$this->instance(['Clustered' => true, 'RemoteAdminConnections' => 1])]));
        $this->assertSame('warn', $status('Sql.WindowsAuthOnly', [$this->instance(['WindowsAuthOnly' => false])]));
        // No SQL Server on the device: nothing to check.
        $this->assertSame('na', $status('Sql.XpCmdshellDisabled', []));

        $result = CompliancePolicies::evaluateCheck($policy, $check('Sql.TrustworthyOff'), [$this->instance(['TrustworthyCount' => 2, 'TrustworthyDatabases' => 'erp, crm'])]);
        $this->assertSame('MSSQLSERVER: TRUSTWORTHY on erp, crm', $result['message']);
        $this->assertSame(['MSSQLSERVER'], $result['details']['names']);
        $this->assertSame(50, CompliancePolicies::score(['pass' => 1, 'fail' => 1, 'manual' => 5, 'na' => 3]));
        $this->assertNull(CompliancePolicies::score(['manual' => 1]));
    }

    public function test_devices_are_evaluated_with_every_inventory(): void
    {
        $device = $this->device();
        $linux = $this->device('b', 'linux');
        CompliancePolicy::apply([$this->sqlPolicy()], CompliancePolicy::CUSTOM);

        $this->agentSend('a', ['posture' => ['FirewallEnabled' => true], 'sqlserver' => [$this->instance(['XpCmdshell' => 1, 'WindowsAuthOnly' => false])]])->assertStatus(202);
        $this->agentSend('b', ['posture' => ['FirewallEnabled' => true]], [], null, [], false)->assertStatus(202);

        $this->assertSame([
            'Sql.RemoteAdminConnectionsDisabled' => 'pass', 'Sql.TrustworthyOff' => 'pass', 'Sql.WindowsAuthOnly' => 'warn', 'Sql.XpCmdshellDisabled' => 'fail',
        ], $this->statuses($device));
        // A Windows policy is not for Linux devices.
        $this->assertSame([], $this->statuses($linux));
        $failed = ComplianceResult::query()->where('device_id', $device->id)->where('check_id', 'Sql.XpCmdshellDisabled')->first();
        $this->assertSame('MSSQLSERVER: xp_cmdshell is 1', $failed->message);
        $this->assertSame('critical', $failed->severity);

        // Fixed: the next inventory passes, and since when it does is kept.
        $this->travel(2)->hours();
        $this->agentSend('a', ['posture' => ['FirewallEnabled' => true], 'sqlserver' => [$this->instance()]])->assertStatus(202);
        $this->assertSame('pass', $this->statuses($device)['Sql.XpCmdshellDisabled']);
        $this->assertTrue($failed->fresh()->changed_at->gt($failed->changed_at));

        // The SQL Server is gone: its checks do not apply.
        $this->agentSend('a', ['posture' => ['FirewallEnabled' => true], 'sqlserver' => []])->assertStatus(202);
        $this->assertSame(['na'], array_values(array_unique($this->statuses($device))));

        // Switched off or removed: the results go with it.
        CompliancePolicy::query()->update(['enabled' => false]);
        CompliancePolicy::touchVersion();
        \App\Support\ComplianceScanner::evaluateAll();
        $this->assertSame([], $this->statuses($device));
    }

    public function test_the_agent_is_told_which_inventory_sources_the_server_takes(): void
    {
        $this->device();
        $policy = $this->signedJson('GET', '/api/device/security/policy', [], 'a')->assertOk()->json();
        $this->assertContains('sqlserver', $policy['inventory']);
        $this->assertContains('posture', $policy['inventory']);
    }

    public function test_policies_come_with_the_rules_feed(): void
    {
        config(['mdm.security_feed_url' => 'https://github.com/example/security-rules', 'mdm.security_feed_ref' => 'main']);
        $raw = 'https://raw.githubusercontent.com/example/security-rules/main';
        $invalid = ['key' => 'broken', 'name' => 'Broken', 'checks' => []];
        $files = ['policies/windows/sql.json' => json_encode($this->sqlPolicy()), 'policies/broken.json' => json_encode($invalid)];
        $manifest = ['version' => '2026.10.3', 'files' => array_map(fn ($body) => hash('sha256', $body), $files)];
        Http::swap(new Factory);
        Http::fake([$raw.'/manifest.json' => Http::response(json_encode($manifest))] + collect($files)->mapWithKeys(fn ($body, $path) => ["$raw/$path" => Http::response($body)])->all());

        $state = SecurityFeed::sync(true);
        $this->assertTrue($state['ok'], (string) ($state['error'] ?? ''));
        $this->assertSame(1, $state['policies']['added']);
        $this->assertStringContainsString('broken', implode(' ', $state['skipped']));
        $policy = CompliancePolicy::query()->where('key', 'cis-mssql-test')->firstOrFail();
        $this->assertSame(CompliancePolicy::FEED, $policy->origin);
        $this->assertSame('2026.10.3', $policy->feed_version);

        // A policy of the portal's own with the same key is not replaced; one dropped from the feed is removed.
        $policy->update(['origin' => CompliancePolicy::CUSTOM, 'name' => 'Mine']);
        CompliancePolicy::apply([], CompliancePolicy::FEED, '2026.10.4');
        $this->assertSame('Mine', $policy->fresh()->name);
        $policy->update(['origin' => CompliancePolicy::FEED]);
        CompliancePolicy::apply([], CompliancePolicy::FEED, '2026.10.4');
        $this->assertFalse(CompliancePolicy::query()->exists());
    }

    public function test_the_pages_show_compliance_and_admins_manage_policies(): void
    {
        $this->withoutVite();
        $device = $this->device();
        CompliancePolicy::apply([$this->sqlPolicy()], CompliancePolicy::FEED, '1');
        $this->agentSend('a', ['sqlserver' => [$this->instance(['XpCmdshell' => 1])]])->assertStatus(202);
        $policy = CompliancePolicy::query()->firstOrFail();
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        $this->actingAs($admin);

        $this->get('/security?tab=compliance')->assertOk()->assertSee('CIS SQL Server (test)');
        Livewire::test(Page::class, ['tab' => 'compliance', 'policy' => $policy->id])
            ->assertSee('Sql.XpCmdshellDisabled')
            ->assertSee('MSSQLSERVER: xp_cmdshell is 1');
        Livewire::test(DeviceSecurity::class, ['deviceId' => $device->id])
            ->assertSee('CIS SQL Server (test)')
            ->assertSee('xp_cmdshell is disabled')
            ->assertSee('CIS 2.15');

        // A policy of the feed is shown, not changed; a copy is the portal's own.
        Livewire::test(PolicyForm::class, ['policyId' => $policy->id])->assertSet('readOnly', true);
        Livewire::test(PolicyForm::class, ['copyOf' => $policy->id])
            ->set('previewDeviceId', $device->id)
            ->assertSee('The policy is valid.')
            ->assertSee('MSSQLSERVER: xp_cmdshell is 1')
            ->call('save')
            ->assertDispatched('compliancePolicySaved');
        $copy = CompliancePolicy::query()->where('key', 'custom.cis-mssql-test-copy')->firstOrFail();
        $this->assertSame(CompliancePolicy::CUSTOM, $copy->origin);
        $this->assertSame(4, ComplianceResult::query()->where('compliance_policy_id', $copy->id)->count());
        Livewire::test(PolicyForm::class)->set('json', json_encode(['key' => 'cis-mssql-test'] + $this->sqlPolicy()))->assertSee('another policy has this key');

        Livewire::test(Page::class, ['tab' => 'compliance'])->call('togglePolicy', $policy->id);
        $this->assertSame(0, ComplianceResult::query()->where('compliance_policy_id', $policy->id)->count());

        // Users see compliance, but do not manage it.
        $this->actingAs(User::factory()->create());
        Livewire::test(Page::class, ['tab' => 'compliance'])->call('togglePolicy', $policy->id)->assertForbidden();
        Livewire::test(PolicyForm::class)->assertForbidden();
    }
}
