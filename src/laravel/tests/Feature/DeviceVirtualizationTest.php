<?php

namespace Tests\Feature;

use App\Livewire\DeviceAlerts;
use App\Livewire\DeviceDetail;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceVirtualizationTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(?array $virtualization, array $disks): Device
    {
        $device = new Device();
        $device->token = hash('sha256', 'token');
        $device->commands = [];
        $device->data = json_encode([
            'machine' => ['Hostname' => 'vm1', 'RestartRequired' => false, 'Drives' => [], 'Virtualization' => $virtualization],
            'disk_health' => ['disks' => $disks],
        ]);
        $device->save();

        return $device;
    }

    public function test_virtual_machine_is_labelled(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->createDevice(['Type' => 'vm', 'Name' => 'microsoft'], []);

        $this->assertSame(['type' => 'vm', 'name' => 'microsoft', 'label' => 'Hyper-V'], $device->virtualization);
        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Virtual machine · Hyper-V');

        $container = $this->createDevice(['Type' => 'container', 'Name' => 'lxc'], []);
        Livewire::test(DeviceAlerts::class, ['selectedDeviceId' => $container->id])
            ->assertSee('Container · LXC');
    }

    public function test_disk_health_is_hidden_for_virtual_disks(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->createDevice(['Type' => 'vm', 'Name' => 'kvm'], [
            ['Device' => 'PhysicalDisk0', 'Model' => 'QEMU HARDDISK', 'Health' => 'passed'],
        ]);

        $this->assertFalse($device->showDiskHealth);
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertDontSee('Disk health');
    }

    public function test_disk_health_is_hidden_on_vms_even_with_smart_values(): void
    {
        $device = $this->createDevice(['Type' => 'vm', 'Name' => 'kvm'], [
            ['Device' => '/dev/sda', 'Model' => 'WD Red 4TB', 'Health' => 'passed', 'Temperature' => 33, 'PowerOnHours' => 1200],
        ]);

        $this->assertFalse($device->showDiskHealth);
    }

    public function test_physical_machines_keep_disk_health(): void
    {
        $device = $this->createDevice(null, [['Device' => 'PhysicalDisk0', 'Health' => 'passed']]);

        $this->assertNull($device->virtualization);
        $this->assertTrue($device->showDiskHealth);
    }
}
