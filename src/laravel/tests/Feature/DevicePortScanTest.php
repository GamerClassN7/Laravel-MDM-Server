<?php

namespace Tests\Feature;

use App\Livewire\DeviceCommands;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/** Scanning a host's own ports from the device menu, through an agent in its network. */
class DevicePortScanTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    private function agent(string $portScan = 'on', string $version = '1.19.0'): Device
    {
        $device = new Device;
        $device->token = hash('sha256', 'secret-token');
        $device->data = json_encode(['machine' => ['Hostname' => 'nas', 'AgentVersion' => $version, 'Platform' => 'linux', 'Drives' => [], 'PortScan' => $portScan, 'Networks' => [
            ['Name' => 'eth0', 'Type' => 'lan', 'Connected' => true, 'Status' => 'Up', 'Mac' => 'AA-00-00-00-00-01', 'IPAddresses' => ['192.168.1.5'],
                'Addresses' => [['Address' => '192.168.1.5', 'PrefixLength' => 24]], 'Gateway' => '192.168.1.1', 'GatewayMac' => '50-C7-BF-AA-BB-CC'],
        ]]]);
        $device->save();
        $this->registerDeviceKey($device);
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    private function pingDevice(string $ip = '192.168.1.217'): Device
    {
        $device = new Device;
        $device->forceFill(['kind' => 'ping', 'name' => 'ap', 'os' => '', 'token' => hash('sha256', 'p'),
            'ping_address' => $ip, 'ping_prefix' => 24, 'ping_mac' => '1C:0B:8B:BA:57:F6']);
        $device->save();
        Device::recordHeartbeat($device->id);

        return $device->fresh();
    }

    public function test_a_ping_only_host_is_scanned_by_an_agent_in_its_network(): void
    {
        $agent = $this->agent();
        $ping = $this->pingDevice();

        $this->assertSame('192.168.1.217', $ping->portScanAddress());
        $this->assertSame($agent->id, $ping->portScanner()?->id);
        $this->assertSame($agent->id, Device::portScannerFor('192.168.1.217')?->id);
        // An agent cannot scan its own address, so it offers no scan of itself.
        $this->assertNull($agent->portScanner());

        $this->actingAs(User::factory()->create());
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $ping->id])->call('scanPorts');
        $command = $agent->commands()->where('command', 'scanPorts')->firstOrFail();
        $this->assertSame(['scanPorts', ['ip' => '192.168.1.217'], 'ports:192.168.1.217'], [$command->command, $command->params, $command->target]);

        // The full-range variant carries the all flag.
        $command->forceFill(['status' => 'succeeded'])->save();
        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $ping->id])->call('scanPorts', true);
        $this->assertSame(['ip' => '192.168.1.217', 'all' => true], $agent->commands()->where('command', 'scanPorts')->latest('id')->first()->params);
    }

    public function test_no_scanner_when_the_agent_cannot_scan(): void
    {
        $this->pingDevice();
        // Only an agent with port_scan off: nothing can scan the host.
        $this->agent('off');
        $ping = Device::query()->where('kind', 'ping')->firstOrFail();
        $this->assertNull($ping->portScanner());
        $this->assertNull(Device::portScannerFor('192.168.1.217'));

        // An address outside any agent's network has no scanner either.
        $this->assertNull(Device::portScannerFor('10.0.0.9'));
    }
}
