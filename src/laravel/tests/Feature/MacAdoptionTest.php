<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\NetworkNeighbour;
use App\Models\User;
use App\Support\SmartAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Adopting the MAC that network discovery found at a ping-only device's address. */
class MacAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private function neighbour(string $ip = '192.168.1.217', string $mac = '1C:0B:8B:BA:57:F6'): NetworkNeighbour
    {
        $neighbour = new NetworkNeighbour;
        $neighbour->forceFill([
            'site' => 'gw:50:C7:BF:AA:BB:CC', 'network' => '192.168.1.0/24', 'mac' => $mac, 'ip' => $ip,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ])->save();

        return $neighbour;
    }

    private function pingDevice(string $ip = '192.168.1.217'): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'ap', 'os' => '', 'token' => hash('sha256', 'p'),
            'ping_address' => $ip, 'ping_prefix' => 24])->save();

        return $device->fresh();
    }

    public function test_discovered_mac_is_found_for_an_address(): void
    {
        $this->neighbour();
        $this->assertSame('1C:0B:8B:BA:57:F6', Device::discoveredMacFor('192.168.1.217')['mac']);
        // No neighbour at this address, or one not seen recently.
        $this->assertNull(Device::discoveredMacFor('192.168.1.99'));
        NetworkNeighbour::query()->update(['last_seen_at' => now()->subDays(2)]);
        $this->assertNull(Device::discoveredMacFor('192.168.1.217'));
    }

    public function test_a_smart_alert_offers_to_adopt_the_discovered_mac(): void
    {
        $this->neighbour();
        $device = $this->pingDevice();

        $alert = collect(SmartAlerts::for($device))->firstWhere('key', 'mac');
        $this->assertNotNull($alert);
        $this->assertSame('adoptMac', $alert['action']['command']);
        $this->assertNull($alert['refusal']);
        $this->assertStringContainsString('1C:0B:8B:BA:57:F6', $alert['message']);

        // Running it sets the MAC, and the alert is then gone.
        $this->assertNull(SmartAlerts::run($device, 'mac', User::factory()->create()));
        $this->assertSame('1C:0B:8B:BA:57:F6', $device->fresh()->ping_mac);
        $this->assertNull(collect(SmartAlerts::for($device->fresh()))->firstWhere('key', 'mac'));
    }

    public function test_no_alert_when_the_device_already_has_a_mac(): void
    {
        $this->neighbour();
        $device = $this->pingDevice();
        $device->forceFill(['ping_mac' => 'AA:BB:CC:DD:EE:FF'])->save();

        $this->assertNull(collect(SmartAlerts::for($device->fresh()))->firstWhere('key', 'mac'));
    }
}
