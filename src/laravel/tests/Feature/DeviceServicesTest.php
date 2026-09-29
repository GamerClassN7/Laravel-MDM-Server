<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceServicesTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $extra): Device
    {
        $device = new Device();
        $device->token = hash('sha256', 'secret-token');
        $device->commands = [];
        $device->save();

        $this->withToken('secret-token')->postJson('/api/device', [
            'machine' => [
                'Hostname' => 'srv1',
                'os' => 'Debian GNU/Linux 12',
                'RestartRequired' => false,
                'Drives' => [],
            ],
        ] + $extra)->assertOk();

        return $device->fresh();
    }

    public function test_services_are_sorted_failed_first(): void
    {
        $device = $this->report(['services' => [
            ['Name' => 'ssh', 'DisplayName' => 'OpenBSD Secure Shell server', 'State' => 'running'],
            ['Name' => 'nginx', 'DisplayName' => 'Web server', 'State' => 'failed'],
            ['Name' => 'Spooler', 'DisplayName' => 'Print Spooler', 'State' => 'stopped', 'StartType' => 'automatic'],
        ]]);

        $this->assertSame(['nginx', 'Spooler', 'ssh'], array_column($device->services, 'Name'));
    }

    public function test_docker_and_disk_health_are_optional(): void
    {
        $device = $this->report([]);

        $this->assertSame([], $device->services);
        $this->assertNull($device->docker);
        $this->assertNull($device->diskHealth);
        $this->assertFalse($device->diskHealthProblem);
    }

    public function test_single_items_serialized_as_objects_are_lists(): void
    {
        $device = $this->report([
            'services' => ['Name' => 'ssh', 'State' => 'running'],
            'docker' => ['containers' => ['Name' => 'web', 'State' => 'running']],
            'disk_health' => ['disks' => ['Device' => '/dev/sda', 'Health' => 'failed']],
        ]);

        $this->assertSame('ssh', $device->services[0]['Name']);
        $this->assertSame('web', $device->docker['containers'][0]['Name']);
        $this->assertTrue($device->diskHealthProblem);
    }

    public function test_detail_shows_services_containers_and_disk_health(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report([
            'services' => [
                ['Name' => 'nginx', 'DisplayName' => 'A high performance web server', 'State' => 'failed'],
                ['Name' => 'ssh', 'DisplayName' => 'OpenBSD Secure Shell server', 'State' => 'running'],
            ],
            'docker' => ['containers' => [
                ['Name' => 'mdm-web', 'Image' => 'alpine:3.22', 'State' => 'running', 'Status' => 'Up 2 hours', 'Ports' => '0.0.0.0:80->80/tcp'],
                ['Name' => 'backup', 'Image' => 'restic/restic', 'State' => 'exited', 'Status' => 'Exited (0) 3 hours ago', 'Ports' => ''],
            ]],
            'disk_health' => ['disks' => [
                ['Device' => '/dev/nvme0', 'Model' => 'WD Blue SN570 1TB', 'MediaType' => 'SSD', 'Health' => 'passed', 'Temperature' => 41, 'PowerOnHours' => 2210, 'WearPercent' => 3, 'MediaErrors' => 0, 'Standby' => false],
                ['Device' => '/dev/sdb', 'Model' => 'WD Red 4TB', 'MediaType' => 'HDD', 'Health' => 'passed', 'Standby' => true],
            ]],
        ]);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Services')
            ->assertSee('A high performance web server')
            ->assertSee('Docker')
            ->assertSee('mdm-web')
            ->assertSee('Exited (0) 3 hours ago')
            ->assertSee('Disk health')
            ->assertSee('WD Blue SN570 1TB')
            ->assertSee('41 °C')
            ->assertSee('Disk is asleep, showing the last known values.');
    }

    public function test_detail_shows_collection_errors(): void
    {
        $this->actingAs(User::factory()->create());
        $device = $this->report([
            'docker' => ['error' => 'Cannot connect to the Docker daemon'],
            'disk_health' => ['error' => 'smartctl not found, install smartmontools (apt install smartmontools).'],
        ]);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->assertSee('Cannot connect to the Docker daemon')
            ->assertSee('install smartmontools')
            ->assertDontSee('No containers.');
    }
}
