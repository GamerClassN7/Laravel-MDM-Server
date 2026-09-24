<?php

namespace Tests\Feature;

use App\Listeners\RecordDeviceHeartbeat;
use App\Livewire\DeviceMetrics;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(string $token = 'secret-token'): Device
    {
        $device = new Device();
        $device->token = hash('sha256', $token);
        $device->commands = [];
        $device->save();

        return $device;
    }

    private function heartbeat(Device $device, bool $subscribed, array $data = []): void
    {
        $connection = new class extends Connection
        {
            public function __construct() {}

            public function app(): Application
            {
                return (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
            }

            public function identifier(): string
            {
                return '1';
            }

            public function id(): string
            {
                return '1234.5678';
            }

            public function send(string $message): void {}

            public function control(string $type = 'ping'): void {}

            public function terminate(): void {}
        };

        $channel = Mockery::mock(Channel::class);
        $channel->shouldReceive('find')->with($connection)->andReturn($subscribed ? new ChannelConnection($connection) : null);

        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldReceive('for')->andReturnSelf();
        $channels->shouldReceive('find')->with('private-device.'.$device->id)->andReturn($channel);

        (new RecordDeviceHeartbeat($channels))->handle(new MessageReceived($connection, json_encode([
            'event' => 'client-heartbeat',
            'channel' => 'private-device.'.$device->id,
            'data' => $data,
        ])));
    }

    public function test_websocket_heartbeat_marks_device_as_seen(): void
    {
        $device = $this->createDevice();
        $updatedAt = $device->updated_at;
        $this->travel(5)->minutes();

        $this->heartbeat($device, subscribed: true);

        $device->refresh();
        $this->assertNotNull($device->last_seen_at);
        $this->assertTrue($device->updated_at->equalTo($updatedAt));
        $this->assertFalse($device->offline);
    }

    public function test_heartbeat_from_connection_outside_the_channel_is_ignored(): void
    {
        $device = $this->createDevice();

        $this->heartbeat($device, subscribed: false);

        $this->assertNull($device->fresh()->last_seen_at);
    }

    public function test_http_heartbeat_marks_device_as_seen(): void
    {
        $device = $this->createDevice();

        $this->withToken('secret-token')->postJson('/api/device/heartbeat')->assertNoContent();

        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_device_goes_offline_without_heartbeat(): void
    {
        $device = $this->createDevice();
        Device::recordHeartbeat($device->id);

        $this->travel(Device::HEARTBEAT_TIMEOUT - 10)->seconds();
        $this->assertFalse($device->fresh()->offline);

        $this->travel(20)->seconds();
        $this->assertTrue($device->fresh()->offline);
    }

    public function test_agents_without_heartbeat_use_report_time(): void
    {
        $device = $this->createDevice();

        $this->travel(10)->minutes();
        $this->assertFalse($device->fresh()->offline);

        $this->travel(10)->minutes();
        $this->assertTrue($device->fresh()->offline);
    }

    public function test_websocket_heartbeat_records_metrics(): void
    {
        $device = $this->createDevice();

        $this->heartbeat($device, subscribed: true, data: ['cpu' => 12.5, 'memory_used' => 4 * 1024 ** 3, 'memory_total' => 16 * 1024 ** 3]);

        $metric = $device->metrics()->sole();
        $this->assertSame(12.5, $metric->cpu);
        $this->assertSame(25.0, $metric->memory_percent);
    }

    public function test_http_heartbeat_records_metrics(): void
    {
        $device = $this->createDevice();

        $this->withToken('secret-token')->postJson('/api/device/heartbeat', [
            'metrics' => ['cpu' => 150, 'memory_used' => 20, 'memory_total' => 10],
        ])->assertNoContent();

        $metric = $device->metrics()->sole();
        $this->assertSame(100.0, $metric->cpu);
        $this->assertSame(10, $metric->memory_used);
    }

    public function test_invalid_metrics_are_ignored(): void
    {
        $device = $this->createDevice();

        $this->heartbeat($device, subscribed: true, data: ['cpu' => 'abc', 'memory_used' => 1, 'memory_total' => 0]);

        $this->assertNotNull($device->fresh()->last_seen_at);
        $this->assertSame(0, $device->metrics()->count());
    }

    public function test_old_metrics_are_pruned(): void
    {
        $device = $this->createDevice();
        Device::recordHeartbeat($device->id, ['cpu' => 1, 'memory_used' => 1, 'memory_total' => 2]);
        $this->travel(DeviceMetric::RETENTION_DAYS + 1)->days();
        Device::recordHeartbeat($device->id, ['cpu' => 2, 'memory_used' => 1, 'memory_total' => 2]);

        $this->artisan('model:prune', ['--model' => [DeviceMetric::class]]);

        $this->assertSame([2.0], $device->metrics()->pluck('cpu')->all());
    }

    public function test_metrics_charts_are_rendered(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->createDevice();
        Device::recordHeartbeat($device->id, ['cpu' => 20, 'memory_used' => 4 * 1024 ** 3, 'memory_total' => 16 * 1024 ** 3]);
        $this->travel(2)->minutes();
        Device::recordHeartbeat($device->id, ['cpu' => 40, 'memory_used' => 8 * 1024 ** 3, 'memory_total' => 16 * 1024 ** 3]);

        Livewire::test(DeviceMetrics::class, ['selectedDeviceId' => $device->id])
            ->assertSee('40 %')
            ->assertSee('50 %')
            ->assertSee('8 of 16 GB')
            ->assertSeeHtml('<polyline', false)
            ->call('setRange', '7d')
            ->assertSet('range', '7d')
            ->call('setRange', 'invalid')
            ->assertSet('range', '7d');
    }
}
