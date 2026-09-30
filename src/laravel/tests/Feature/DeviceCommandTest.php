<?php

namespace Tests\Feature;

use App\Livewire\DeviceAlerts;
use App\Livewire\DeviceCommands;
use App\Livewire\DeviceDetail;
use App\Livewire\SmartAlerts;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Support\AgentScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

class DeviceCommandTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    /** An online, signing device with the given agent version and report data. */
    private function device(string $version = '1.8.0', array $data = [], string $token = 'secret-token'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', $token);
        $device->data = json_encode(['machine' => ['Hostname' => 'pc', 'AgentVersion' => $version, 'Platform' => 'windows', 'RestartRequired' => false, 'Drives' => []]] + $data);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    public function test_tracking_agent_reports_progress_and_result(): void
    {
        $device = $this->device();
        $command = $device->issueCommand('doUpdates');
        $this->assertSame('queued', $command->status);

        $this->signedJson('POST', '/api/device/commands/take', [], 'secret-token')
            ->assertExactJson(['commands' => ['doUpdates'], 'tasks' => [['id' => $command->id, 'command' => 'doUpdates', 'params' => []]]]);
        $this->assertSame('sent', $command->fresh()->status);

        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'running', 'progress' => 40, 'message' => 'Windows Update: installing KB1'], 'secret-token')
            ->assertOk();
        $this->assertSame(['running', 40, 'Windows Update: installing KB1'], [$command->fresh()->status, $command->fresh()->progress, $command->fresh()->message]);
        // 100 % only once it is done.
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'running', 'progress' => 100], 'secret-token')->assertOk();
        $this->assertSame(99, $command->fresh()->progress);
        $this->assertNotNull($command->fresh()->started_at);

        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'succeeded'], 'secret-token')->assertOk();
        $this->assertSame(['succeeded', 100], [$command->fresh()->status, $command->fresh()->progress]);
        $this->assertNotNull($command->fresh()->finished_at);

        // A finished command is not changed anymore, and other devices cannot report it.
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'failed'], 'secret-token')
            ->assertStatus(409)->assertJson(['error' => 'not_running']);
        $this->app['auth']->forgetGuards();
        $this->device('1.8.0', [], 'other-token');
        $this->signedJson('POST', "/api/device/commands/{$command->id}", ['status' => 'failed'], 'other-token')->assertNotFound();
        $this->assertSame('succeeded', $command->fresh()->status);
    }

    public function test_older_agents_get_names_and_their_commands_end_as_delivered(): void
    {
        $device = $this->device('1.7.3');
        $command = $device->issueCommand('restart');

        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'pc', 'Drives' => [], 'AgentVersion' => '1.7.3']], 'secret-token')
            ->assertJson(['commands' => ['restart']]);
        $this->assertSame('delivered', $command->fresh()->status);
        $this->assertFalse($command->fresh()->active);
    }

    public function test_duplicates_are_not_queued(): void
    {
        $device = $this->device();

        $this->assertNotNull($device->issueCommand('restart'));
        $this->assertNull($device->issueCommand('restart'));
        // A restart and a shutdown exclude each other.
        $this->assertNull($device->issueCommand('turnOff'));

        $git = ['kind' => 'winget', 'id' => 'Git.Git'];
        $this->assertNotNull($device->issueCommand('installUpdate', $git));
        $this->assertNull($device->issueCommand('installUpdate', $git + ['title' => 'Git']));
        $this->assertNotNull($device->issueCommand('installUpdate', ['kind' => 'winget', 'id' => '7zip.7zip']));
        // All updates after single ones is fine, single ones while all are installed are not.
        $this->assertNotNull($device->issueCommand('doUpdates'));
        $this->assertNull($device->issueCommand('installUpdate', ['kind' => 'winget', 'id' => 'Mozilla.Firefox']));

        $this->assertSame(4, $device->commands()->count());
    }

    public function test_single_updates_need_a_tracking_agent_and_valid_parameters(): void
    {
        $old = $this->device('1.7.3', [], 'old-token');
        $this->assertNull($old->issueCommand('installUpdate', ['kind' => 'winget', 'id' => 'Git.Git']));

        $device = $this->device();
        foreach ([
            ['kind' => 'winget', 'id' => 'Git.Git; Remove-Item C:\\'],
            ['kind' => 'apt', 'id' => '-o APT::Foo'],
            ['kind' => 'shell', 'id' => 'x'],
            ['kind' => 'module', 'id' => 'Az', 'edition' => 'PowerShell 7'],
            ['kind' => 'snap', 'id' => 'firefox', 'user' => 'bob'],
        ] as $params) {
            $this->assertNull($device->issueCommand('installUpdate', $params), json_encode($params));
        }
        $this->assertNull($device->issueCommand('restart', ['force' => true]));
        $this->assertSame(0, $device->commands()->count());

        $command = $device->issueCommand('installUpdate', ['kind' => 'module', 'id' => 'Az.Accounts', 'edition' => 'PowerShell 7', 'version' => '3.0.1', 'title' => 'Az.Accounts']);
        $this->assertSame('module:PowerShell 7:Az.Accounts:', $command->target);
        // The title is only shown in the portal; commands with parameters are not sent by name.
        $this->signedJson('POST', '/api/device/commands/take', [], 'secret-token')
            ->assertExactJson(['commands' => [], 'tasks' => [['id' => $command->id, 'command' => 'installUpdate', 'params' => ['kind' => 'module', 'id' => 'Az.Accounts', 'edition' => 'PowerShell 7', 'version' => '3.0.1']]]]);
    }

    public function test_stale_commands_expire(): void
    {
        $device = $this->device();
        $queued = $device->issueCommand('doUpdates');
        $restart = $device->issueCommand('restart');
        $restart->forceFill(['status' => 'running'])->save();

        $this->travel(DeviceCommand::QUEUE_TTL + 60)->seconds();
        Device::recordHeartbeat($device->id);
        DeviceCommand::expireStale();

        $this->assertSame('expired', $queued->fresh()->status);
        $this->assertSame('expired', $restart->fresh()->status);
        // The restart can be sent again.
        $this->assertNotNull($device->fresh()->issueCommand('restart'));
    }

    public function test_queued_commands_can_be_cancelled_and_the_toolbar_shows_progress(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device();

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->call('sendCommandToDevice', 'doUpdates')
            ->call('sendCommandToDevice', 'doUpdates')
            ->assertHasErrors('command')
            ->assertSee('Waiting for the device');
        $command = $device->commands()->sole();
        $command->forceFill(['status' => 'running', 'progress' => 35, 'message' => 'winget upgrade --all'])->save();

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->assertSee('35 %')
            ->assertSee('winget upgrade --all')
            ->call('cancel', $command->id);
        // Taken by the agent: not cancelled anymore.
        $this->assertSame('running', $command->fresh()->status);

        $command->forceFill(['status' => 'queued'])->save();
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])->call('cancel', $command->id);
        $this->assertSame('cancelled', $command->fresh()->status);
    }

    public function test_one_update_of_the_list_is_installed(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device('1.8.0', [
            'os_updates' => [['Id' => '6f0a4b2e-1c3d-4e5f-8a9b-0c1d2e3f4a5b', 'Title' => 'KB5031455']],
            'packages_updates' => [
                ['Id' => 'Git.Git', 'Version' => '2.40', 'Avaliable' => '2.44', 'Source' => 'winget'],
                ['Id' => 'PowerShell', 'Version' => '7.6.1', 'Avaliable' => '7.6.6', 'Source' => 'github.com/PowerShell'],
            ],
            'module_updates' => [['Name' => 'Pester', 'Version' => '4.10.1', 'Available' => '5.6.1', 'Edition' => 'Windows PowerShell', 'User' => 'alice']],
        ]);

        // PowerShell installed from GitHub is updated from the GitHub release, by agents 1.8.2+.
        $this->assertNull($device->updateTarget('app', $device->apps_packages_updates[1]));
        $this->assertNull($device->issueCommand('installUpdate', ['kind' => 'pwsh', 'id' => '7.6.6']));
        $newer = $this->device('1.8.2', ['packages_updates' => [['Id' => 'PowerShell', 'Version' => '7.6.1', 'Avaliable' => '7.6.6', 'Source' => 'github.com/PowerShell']]], 'newer-token');
        $this->assertSame(['kind' => 'pwsh', 'id' => '7.6.6', 'title' => 'PowerShell 7.6.6'], $newer->updateTarget('app', $newer->apps_packages_updates[0]));
        $this->assertNotNull($newer->issueCommand('installUpdate', ['kind' => 'pwsh', 'id' => '7.6.6']));
        // Windows users' own modules cannot be updated by SYSTEM.
        $this->assertNull($device->updateTarget('module', $device->moduleUpdates[0]));

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id, 'tab' => 'updates'])
            ->assertSeeHtml('Install only this update')
            ->call('installUpdate', 'app', 0, 'Mozilla.Firefox')
            ->assertHasErrors('update')
            ->call('installUpdate', 'app', 0, 'Git.Git')
            ->call('installUpdate', 'os', 0, '6f0a4b2e-1c3d-4e5f-8a9b-0c1d2e3f4a5b')
            ->assertSee('Update Git.Git');

        $this->assertSame(['winget:Git.Git', 'windows:6f0a4b2e-1c3d-4e5f-8a9b-0c1d2e3f4a5b'], $device->commands()->orderBy('id')->pluck('target')->map(fn ($target) => str_replace(':', '', substr($target, 0, strpos($target, ':'))).':'.explode(':', $target)[2])->all());
    }

    public function test_alert_actions_run_once(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device(AgentScript::version());
        Device::recordHeartbeat($device->id, null, 'ws', ['restart_required' => true]);

        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Restart required')
            ->call('runAlert', 'restart')
            ->assertHasNoErrors()
            ->call('runAlert', 'restart')
            ->assertHasErrors('alert.restart')
            ->assertSeeHtml('title="Waiting for the device"')
            ->assertSeeHtml('spinner-border');
        $this->assertSame(['restart'], $device->fresh()->queuedCommands);

        // A failed command can be tried again from its alert.
        $device->commands()->update(['status' => 'failed', 'message' => 'Access denied']);
        Device::recordHeartbeat($device->id, null, 'ws', ['restart_required' => false]);
        $failed = $device->commands()->sole();
        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Restart failed')
            ->assertSee('Access denied')
            ->call('runAlert', 'failed:'.$failed->id);
        $this->assertSame(['restart'], $device->fresh()->queuedCommands);
    }

    public function test_widget_lists_alerts_of_all_devices_and_acts_on_all_once(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->device('1.7.0', [], 'token-1');
        $second = $this->device('1.7.0', [], 'token-2');
        $current = $this->device(AgentScript::version(), [], 'token-3');

        Livewire::test(SmartAlerts::class)
            ->assertSee('A newer agent is available')
            ->assertSee($first->displayName)
            ->assertSee('Update agent (2 devices)')
            ->call('runForAll', 'agent')
            ->call('runForAll', 'agent')
            ->call('runAlert', $first->id, 'agent')
            ->assertHasErrors('alert.'.$first->id.'.agent');

        $this->assertSame(1, $first->commands()->count());
        $this->assertSame(1, $second->commands()->count());
        $this->assertSame(0, $current->commands()->count());

        $html = Blade::render('<x-widgets.SmartAlerts :config="$config" />', ['config' => ['severity' => 'danger']]);
        $this->assertStringContainsString('Smart alerts', $html);
    }

    public function test_dismissed_alerts_stay_hidden_until_they_change(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device(AgentScript::version(), ['packages_updates' => [['Id' => 'Git.Git', 'Version' => '1', 'Avaliable' => '2', 'Source' => 'winget']]]);

        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('1 update available')
            ->call('dismiss', 'updates')
            ->assertDontSee('1 update available');
        // The widget hides it too, and its action cannot run.
        Livewire::test(SmartAlerts::class, ['minSeverity' => 'info'])->assertDontSee('1 update available');
        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])->call('runAlert', 'updates')->assertHasErrors('alert.updates');

        // Another update: the alert says something new and shows again.
        $data = json_decode($device->getRawOriginal('data'), true);
        $data['packages_updates'][] = ['Id' => '7zip.7zip', 'Version' => '1', 'Avaliable' => '2', 'Source' => 'winget'];
        $device->forceFill(['data' => json_encode($data)])->saveQuietly();
        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])->assertSee('2 updates available');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('device_alert_dismissals')->count());
    }

    public function test_device_menu_renames_and_has_no_agent_update(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->device('1.7.3');

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Rename')
            ->assertDontSee('Update agent');
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->dispatch('rename-device')
            ->assertSet('editMode', true);
    }

    public function test_history_shows_what_the_status_does_not_say(): void
    {
        $device = $this->device();
        $make = fn (array $values) => $device->commands()->create(['command' => 'doUpdates'])->forceFill($values);

        $done = $make(['status' => 'succeeded', 'message' => 'Done', 'started_at' => now()->subMinutes(23), 'finished_at' => now()]);
        $this->assertNull($done->resultNote);
        $this->assertSame('23m', $done->duration);
        $this->assertSame('Restart required', $make(['status' => 'succeeded', 'message' => 'Done, restart required'])->resultNote);
        $this->assertSame('winget upgrade: exit 1', $make(['status' => 'failed', 'message' => 'winget upgrade: exit 1'])->resultNote);

        $delivered = $make(['status' => 'delivered']);
        $this->assertSame(['Sent, no result', 'secondary'], [$delivered->statusLabel, $delivered->statusColor]);
        $this->assertNotNull($delivered->statusHint);
        $this->assertNull($delivered->duration);
    }
}
