<?php

namespace Tests\Feature;

use App\Events\DeviceCommandIssued;
use App\Livewire\DeviceAlerts;
use App\Models\Device;
use App\Models\User;
use App\Support\AgentScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceAgentStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(array $machine = [], string $token = 'secret-token'): Device
    {
        $device = new Device();
        $device->token = hash('sha256', $token);
        $device->commands = [];
        $device->data = json_encode(['machine' => $machine + ['RestartRequired' => 'false']]);
        $device->save();

        return $device;
    }

    public function test_served_agent_version_is_read_from_the_script(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AgentScript::version());
    }

    public function test_device_type_icon(): void
    {
        $this->assertSame('fas fa-server', $this->createDevice(['Type' => 'server'])->typeIcon);
        $this->assertSame('fas fa-laptop', $this->createDevice(['Type' => 'laptop'])->typeIcon);
        $this->assertSame('fas fa-desktop', $this->createDevice(['Type' => 'desktop'])->typeIcon);
        // Older agents do not report a type.
        $this->assertSame('fas fa-desktop', $this->createDevice()->typeIcon);
    }

    public function test_agent_version_status(): void
    {
        $current = $this->createDevice(['AgentVersion' => AgentScript::version()]);
        $old = $this->createDevice(['AgentVersion' => '0.9.0']);
        $legacy = $this->createDevice();

        $this->assertFalse($current->agentOutdated);
        $this->assertTrue($old->agentOutdated);
        $this->assertTrue($old->agentUpdatable);
        $this->assertTrue($legacy->agentOutdated);
        $this->assertFalse($legacy->agentUpdatable);
    }

    public function test_connection_channels_are_tracked(): void
    {
        $device = $this->createDevice();
        $device->forceFill(['last_http_at' => null])->saveQuietly();
        $this->travel(20)->minutes();

        $device->refresh();
        $this->assertFalse($device->connectedViaWebsocket);
        $this->assertFalse($device->connectedViaApi);

        Device::recordHeartbeat($device->id, null, 'ws');
        $this->assertTrue($device->fresh()->connectedViaWebsocket);
        $this->assertFalse($device->fresh()->connectedViaApi);

        $this->withToken('secret-token')->postJson('/api/device/heartbeat')->assertNoContent();
        $this->assertTrue($device->fresh()->connectedViaApi);

        $this->travel(Device::HEARTBEAT_TIMEOUT + 10)->seconds();
        $this->assertFalse($device->fresh()->connectedViaWebsocket);
        $this->assertTrue($device->fresh()->connectedViaApi);
    }

    public function test_report_marks_rest_api_contact(): void
    {
        $device = $this->createDevice();

        $this->withToken('secret-token')->postJson('/api/device', ['machine' => ['Hostname' => 'pc', 'Drives' => []]])->assertOk();

        $this->assertNotNull($device->fresh()->last_http_at);
    }

    public function test_outdated_agent_can_be_updated_from_the_ui(): void
    {
        Event::fake([DeviceCommandIssued::class]);
        $this->actingAs(User::factory()->create());
        $device = $this->createDevice(['AgentVersion' => '0.9.0']);
        Device::recordHeartbeat($device->id);

        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('A newer agent is available (0.9.0 → '.AgentScript::version().').')
            ->assertSee('Update agent')
            ->assertSee('WebSocket')
            ->assertSee('REST API')
            ->call('updateAgent');

        $this->assertSame(['updateAgent'], $device->fresh()->commands);
        Event::assertDispatched(DeviceCommandIssued::class, fn ($event) => $event->command === 'updateAgent');
    }

    public function test_legacy_agent_is_asked_to_reinstall(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->createDevice();

        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Agent unknown')
            ->assertSee('reinstall it with the command from Add device')
            ->assertDontSee('Update agent');
    }

    public function test_update_command_matches_the_platform_and_has_no_enrolment_code(): void
    {
        $linux = $this->createDevice(['AgentVersion' => '0.9.0', 'Platform' => 'linux']);
        $windows = $this->createDevice(['AgentVersion' => '0.9.0', 'Platform' => 'windows']);

        $this->assertSame(\App\Support\InstallCommands::for()['pwsh'], \App\Support\InstallCommands::update($linux));
        $this->assertSame(\App\Support\InstallCommands::for()['windows'], \App\Support\InstallCommands::update($windows));
        $this->assertStringNotContainsString('-EnrolmentCode', \App\Support\InstallCommands::update($linux));
        $this->assertStringContainsString("-ServerUrl '".url('/')."' -Install }", \App\Support\InstallCommands::update($linux));
    }

    public function test_platform_is_guessed_for_older_agents(): void
    {
        $device = $this->createDevice();
        $device->os = 'Ubuntu 22.04.4 LTS';
        $this->assertSame('linux', $device->platform);

        $device->os = 'Microsoft Windows 11 Pro (10.0.22631)';
        $this->assertSame('windows', $device->platform);
    }

    public function test_update_notice_offers_the_update_command(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->createDevice(['AgentVersion' => '0.9.0', 'Platform' => 'linux']);

        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Or run on the device (sudo pwsh):')
            ->assertSee(\App\Support\InstallCommands::update($device));
    }
}
