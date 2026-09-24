<?php

namespace Tests\Feature;

use App\Listeners\RecordDeviceHeartbeat;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Channels\ChannelConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
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

    private function heartbeat(Device $device, bool $subscribed): void
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
            'data' => [],
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
        Device::markSeen($device->id);

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
}
