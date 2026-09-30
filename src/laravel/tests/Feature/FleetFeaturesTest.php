<?php

namespace Tests\Feature;

use App\Livewire\DeviceCommands;
use App\Livewire\DeviceDetail;
use App\Livewire\Notifications\DeviceRules;
use App\Livewire\Notifications\Page;
use App\Livewire\Notifications\RuleForm;
use App\Livewire\Script\Schedule;
use App\Livewire\ShowDevices;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\NotificationSetting;
use App\Models\Script;
use App\Models\User;
use App\Support\AlertEvaluator;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** Tags, notifications with alert rules, scheduled remediations and Wake-on-LAN. */
class FleetFeaturesTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private function device(string $token, array $machine = [], bool $online = true, string $version = '1.9.0'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->name = "pc-$token";
        $device->data = json_encode(['machine' => $machine + ['Hostname' => "pc-$token", 'AgentVersion' => $version, 'Platform' => 'linux', 'ScriptsEnabled' => true, 'Drives' => []]]);
        $device->save();
        $this->registerDeviceKey($device);
        if ($online) {
            Device::recordHeartbeat($device->id);
        } else {
            $device->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        }

        return $device->fresh();
    }

    private function network(string $ip, int $prefix = 24, string $mac = 'AA-BB-CC-DD-EE-01', string $type = 'lan'): array
    {
        return ['Name' => 'eth0', 'Type' => $type, 'Connected' => true, 'Status' => 'Up', 'Mac' => $mac, 'IPAddresses' => [$ip], 'Addresses' => [['Address' => $ip, 'PrefixLength' => $prefix]]];
    }

    // Tags

    public function test_tags_are_normalized_and_filter_the_device_list(): void
    {
        $this->assertSame(['Servers', 'home lab'], Device::normalizeTags(' Servers, servers ,home   lab, <b>, '));

        $user = User::factory()->create();
        $server = $this->device('a');
        $laptop = $this->device('b');
        $this->actingAs($user);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $server->id])
            ->call('startEditTags')
            ->set('tagsText', 'servers, Rack 1')
            ->call('saveTags')
            ->assertDispatched('device-tags-changed');
        $this->assertSame(['servers', 'Rack 1'], $server->fresh()->tags);
        $this->assertSame(['Rack 1', 'servers'], Device::allTags());

        Livewire::test(ShowDevices::class)
            ->call('filterTag', 'servers')
            ->assertViewHas('visibleDevices', fn ($devices) => $devices->pluck('id')->all() === [$server->id])
            ->assertSet('selectedDeviceId', $server->id);

        $this->assertTrue($server->fresh()->matchesTarget(['tags' => ['SERVERS']]));
        $this->assertFalse($laptop->matchesTarget(['tags' => ['servers']]));
        $this->assertTrue($laptop->matchesTarget(['devices' => [$laptop->id]]));
        $this->assertSame([$server->id, $laptop->id], Device::targeted(['all' => true])->pluck('id')->all());
    }

    // Scheduled remediations

    public function test_scheduled_script_runs_once_per_due_minute_on_its_target(): void
    {
        $tagged = $this->device('a');
        $tagged->forceFill(['tags' => ['servers']])->save();
        $other = $this->device('b');
        $script = Script::create(['name' => 'Check', 'platform' => 'all', 'detection' => 'exit 0', 'timeout' => 60, 'schedule' => '0 3 * * *', 'schedule_target' => ['tags' => ['servers']]]);
        $version = $script->version;

        $this->assertSame(0, Script::runScheduled(Carbon::parse('2026-10-05 02:59:00')));
        $this->assertSame(1, Script::runScheduled(Carbon::parse('2026-10-05 03:00:00')));
        // The same minute again (a second scheduler) does nothing.
        $this->assertSame(0, Script::runScheduled(Carbon::parse('2026-10-05 03:00:30')));
        $this->assertSame([$tagged->id], $script->runs()->pluck('device_id')->all());
        $this->assertSame($version, $script->fresh()->version, 'The schedule is not a new version');
        $this->assertSame(1, $script->fresh()->runs()->count());
        $this->assertSame(0, $other->scriptRuns()->count());

        $this->assertSame('2026-10-06 03:00', $script->fresh()->nextScheduledRun(Carbon::parse('2026-10-05 03:00:00'))->format('Y-m-d H:i'));

        // In the configured time zone: 3:00 in Prague (CEST) is 1:00 UTC.
        config(['mdm.timezone' => 'Europe/Prague']);
        $this->assertSame(0, Script::runScheduled(Carbon::parse('2026-10-06 03:00:00', 'UTC')));
        $this->assertSame(1, Script::runScheduled(Carbon::parse('2026-10-06 01:00:00', 'UTC')));
        $this->assertSame('2026-10-07 03:00 Europe/Prague', $script->fresh()->nextScheduledRun(Carbon::parse('2026-10-06 01:00:00', 'UTC'))->format('Y-m-d H:i e'));
    }

    public function test_schedule_modal_validates_and_saves(): void
    {
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        $script = Script::create(['name' => 'Check', 'platform' => 'all', 'detection' => 'exit 0', 'timeout' => 60]);
        $this->actingAs($admin);

        Livewire::test(Schedule::class, ['scriptId' => $script->id])
            ->set('enabled', true)
            ->set('schedule', 'every day')
            ->call('save')
            ->assertHasErrors('schedule')
            ->set('schedule', '*/15  * * * *')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('closeModal');
        $this->assertSame('*/15 * * * *', $script->fresh()->schedule);
        $this->assertTrue($script->fresh()->schedule_target['all']);

        Livewire::test(Schedule::class, ['scriptId' => $script->id])->set('enabled', false)->call('save');
        $this->assertNull($script->fresh()->schedule);

        $this->actingAs(User::factory()->create());
        config(['boilerplate.system_admins' => [(string) $admin->id]]);
        Livewire::test(Schedule::class, ['scriptId' => $script->id])->assertForbidden();
    }

    // Notifications

    public function test_notification_urls_are_turned_into_requests(): void
    {
        Http::fake();

        Notifier::send('ntfy://user:pass@ntfy.example.com/alerts?priority=high', 'Title ✅', 'Body');
        Notifier::send('discord://tok@123', 'T', 'M');
        Notifier::send('telegram://123:ABC@telegram?chats=@one,2', 'T', 'M');
        Notifier::send('gotify://gotify.example.com/sub/AppToken?disabletls=yes', 'T', 'M');
        Notifier::send('generic+http://hooks.local:8080/in?x=1', 'T', 'M');
        Notifier::send('slack://hook:T0-B0-XX@webhook', 'T', 'M');
        Notifier::send('pushover://shoutrrr:apptoken@userkey', 'T', 'M');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $urls = $sent->map(fn (Request $request) => $request->url())->all();
        $this->assertSame([
            'https://ntfy.example.com/alerts',
            'https://discord.com/api/webhooks/123/tok',
            'https://api.telegram.org/bot123:ABC/sendMessage',
            'https://api.telegram.org/bot123:ABC/sendMessage',
            'http://gotify.example.com/sub/message?token=AppToken',
            'http://hooks.local:8080/in?x=1',
            'https://hooks.slack.com/services/T0/B0/XX',
            'https://api.pushover.net/1/messages.json',
        ], $urls);
        $ntfy = $sent->first();
        $this->assertSame('Body', $ntfy->body());
        $this->assertSame('high', $ntfy->header('Priority')[0]);
        $this->assertStringStartsWith('=?UTF-8?B?', $ntfy->header('Title')[0]);
        $this->assertSame(['2', '@one'], $sent->slice(2, 2)->map(fn ($request) => $request['chat_id'])->sort()->values()->all());
        $this->assertSame(['title' => 'T', 'message' => 'M'], $sent[5]->data());

        $this->assertFalse(Notifier::validUrl('https://example.com'));
        $this->assertFalse(Notifier::validUrl('telegram://token@telegram'));
        $this->assertSame('discord://…@123', Notifier::redact('discord://tok@123'));
    }

    public function test_status_alert_triggers_once_and_resolves(): void
    {
        Http::fake();
        $user = User::factory()->create();
        NotificationSetting::create(['user_id' => $user->id, 'urls' => ['ntfy://ntfy.sh/topic']]);
        $device = $this->device('a', online: false);
        AlertRule::create(['user_id' => $user->id, 'type' => 'status', 'minutes' => 5, 'target' => ['all' => true]]);

        $this->assertSame(['triggered' => 1, 'resolved' => 0], AlertEvaluator::run());
        $this->assertSame(['triggered' => 0, 'resolved' => 0], AlertEvaluator::run());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->header('Title')[0] !== '' && str_contains($request->body(), 'is offline'));

        Device::recordHeartbeat($device->id);
        $this->assertSame(['triggered' => 0, 'resolved' => 1], AlertEvaluator::run());
        Http::assertSentCount(2);
        $this->assertNotNull($device->fresh()->id);
        $this->assertSame(0, AlertRule::first()->events()->whereNull('resolved_at')->count());
    }

    public function test_metric_disk_and_scope_alerts(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $busy = $this->device('a', ['Drives' => [['FriendlyName' => 'System', 'DriveLetter' => 'C', 'Size' => 100, 'SizeRemaining' => 5]]]);
        $idle = $this->device('b');
        $idle->forceFill(['tags' => ['lab']])->save();
        foreach (range(1, 20) as $i) {
            DeviceMetric::create(['device_id' => $busy->id, 'cpu' => 95, 'memory_used' => 1, 'memory_total' => 10]);
            DeviceMetric::create(['device_id' => $idle->id, 'cpu' => 5, 'memory_used' => 1, 'memory_total' => 10]);
        }
        $cpu = AlertRule::create(['user_id' => $user->id, 'type' => 'cpu', 'threshold' => 80, 'minutes' => 10, 'target' => ['all' => true]]);
        $disk = AlertRule::create(['user_id' => $user->id, 'type' => 'disk', 'threshold' => 90, 'target' => ['devices' => [$busy->id]]]);
        $memory = AlertRule::create(['user_id' => $user->id, 'type' => 'memory', 'threshold' => 5, 'minutes' => 10, 'target' => ['tags' => ['lab']]]);

        AlertEvaluator::run();
        $this->assertSame([$busy->id], $cpu->events()->pluck('device_id')->all());
        $this->assertEqualsWithDelta(95.0, $cpu->events()->first()->value, 0.01);
        $this->assertStringContainsString('System (C) 95 %', $disk->events()->first()->message);
        $this->assertSame([$idle->id], $memory->events()->pluck('device_id')->all());

        // Out of the target: closed without a message.
        $memory->update(['target' => ['tags' => ['other']]]);
        AlertEvaluator::run();
        $this->assertSame(0, $memory->events()->whereNull('resolved_at')->count());
        // No channels saved: recorded, nothing sent.
        Http::assertNothingSent();
    }

    public function test_notification_page_saves_channels_and_rules(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $device = $this->device('a');
        $this->actingAs($user);

        Livewire::test(Page::class)
            ->set('emails', "me@example.com\nnot-an-email")
            ->set('urls', ['ntfy://ntfy.sh/x', 'https://nope'])
            ->call('save')
            ->assertHasErrors(['emails', 'urls.1'])
            ->set('emails', 'me@example.com')
            ->set('urls', ['ntfy://ntfy.sh/x', ''])
            ->call('save')
            ->assertHasNoErrors()
            ->call('testUrl', 0)
            ->assertSet('tests.0.ok', true);
        $this->assertSame(['ntfy://ntfy.sh/x'], NotificationSetting::for($user)->urlList);
        $this->assertSame(['me@example.com'], NotificationSetting::for($user)->emailList);

        Livewire::test(RuleForm::class)
            ->set('type', 'cpu')
            ->assertSet('threshold', 80)
            ->set('targetAll', false)
            ->call('save')
            ->assertHasErrors('target')
            ->set('targetDevices', [(string) $device->id])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(['cpu', 80, 10, [$device->id]], [AlertRule::first()->type, AlertRule::first()->threshold, AlertRule::first()->minutes, AlertRule::first()->target['devices']]);

        // The bell of the device: one switch per type.
        Livewire::test(DeviceRules::class, ['deviceId' => $device->id])
            ->assertSet('settings.cpu.enabled', true)
            ->set('settings.status.enabled', true)
            ->set('settings.cpu.threshold', 150)
            ->assertSet('settings.cpu.threshold', 99);
        $this->assertSame(99, AlertRule::where('type', 'cpu')->first()->threshold);
        $this->assertSame(2, AlertRule::count());
        Livewire::test(DeviceRules::class, ['deviceId' => $device->id])->set('settings.status.enabled', false);
        $this->assertSame(['cpu'], AlertRule::pluck('type')->all());

        // Other users do not see or change the rules.
        $this->actingAs(User::factory()->create());
        Livewire::test(Page::class)->assertViewHas('rules', fn ($rules) => $rules->isEmpty());
        Livewire::test(Page::class)->call('deleteRule', AlertRule::first()->id);
        $this->assertSame(1, AlertRule::count());
    }

    // Wake-on-LAN

    public function test_wake_goes_through_an_online_agent_in_the_same_network(): void
    {
        $sleeping = $this->device('sleep', ['Networks' => [$this->network('192.168.1.20', 24, 'AA-BB-CC-DD-EE-01'), $this->network('10.8.0.2', 24, '00-00-00-00-00-00', 'vpn')]], online: false);
        $elsewhere = $this->device('far', ['Networks' => [$this->network('192.168.2.5')]]);
        $old = $this->device('old', ['Networks' => [$this->network('192.168.1.6')]], version: '1.8.1');
        $this->assertSame(__('No online agent 1.9.0+ in the same network'), $sleeping->wakeRefusal());

        $relay = $this->device('relay', ['Networks' => [$this->network('192.168.1.5', 24, 'AA-BB-CC-DD-EE-02')]]);
        $this->assertNull($sleeping->wakeRefusal());
        [$found, $broadcasts] = $sleeping->wakeRelay();
        $this->assertSame($relay->id, $found->id);
        $this->assertSame(['192.168.1.255', '255.255.255.255'], $broadcasts);

        // A relay behind another public address is not in the same network.
        Device::query()->whereKey($sleeping->id)->update(['public_ip' => '1.1.1.1']);
        Device::query()->whereKey($relay->id)->update(['public_ip' => '2.2.2.2']);
        $this->assertNull($sleeping->fresh()->wakeRelay());
        Device::query()->whereKey($relay->id)->update(['public_ip' => '1.1.1.1']);

        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $sleeping->id])->call('wake')->assertHasNoErrors();
        $command = $relay->commands()->sole();
        $this->assertSame(['macs' => ['AA:BB:CC:DD:EE:01'], 'broadcasts' => ['192.168.1.255', '255.255.255.255'], 'device' => $sleeping->id, 'title' => 'pc-sleep'], $command->params);
        $this->assertSame('device:'.$sleeping->id, $command->target);
        // No second wake while one is on its way.
        $this->assertNull($sleeping->fresh()->wake());

        $this->signedJson('POST', '/api/device/commands/take', [], 'relay')
            ->assertJsonPath('tasks.0.command', 'wake')
            ->assertJsonPath('tasks.0.params.macs', ['AA:BB:CC:DD:EE:01'])
            ->assertJsonMissingPath('tasks.0.params.title');
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'succeeded', 'message' => 'Magic packet sent to AA:BB:CC:DD:EE:01'], 'relay')->assertOk();
        $this->assertSame('succeeded', $sleeping->fresh()->recentWake()->status);
        $this->assertSame(0, $elsewhere->commands()->count() + $old->commands()->count());
    }

    public function test_wake_parameters_are_checked(): void
    {
        $this->assertNull(DeviceCommand::sanitizeParams('wake', ['macs' => ['nope'], 'broadcasts' => ['192.168.1.255'], 'device' => 1]));
        $this->assertNull(DeviceCommand::sanitizeParams('wake', ['macs' => ['AA:BB:CC:DD:EE:FF'], 'broadcasts' => ['fe80::1'], 'device' => 1]));
        $this->assertSame(
            ['macs' => ['AA:BB:CC:DD:EE:FF'], 'broadcasts' => ['192.168.1.255'], 'device' => 3],
            DeviceCommand::sanitizeParams('wake', ['macs' => ['aa-bb-cc-dd-ee-ff', 'AA:BB:CC:DD:EE:FF'], 'broadcasts' => ['192.168.1.255'], 'device' => 3, 'evil' => 'x']),
        );
    }

    public function test_report_records_the_public_address(): void
    {
        $device = $this->device('a');
        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'pc', 'Drives' => [], 'AgentVersion' => '1.9.0']], 'a', ['server' => ['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1']])->assertOk();
        $this->assertSame('203.0.113.7', $device->fresh()->public_ip);
    }
}
