<?php

namespace Tests\Feature;

use App\Livewire\DeviceCommands;
use App\Livewire\DeviceDetail;
use App\Livewire\ShowDevices;
use App\Models\Device;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'machine' => [
            'Hostname' => 'pc1',
            'os' => 'win',
            'uptime' => 3600,
            'RestartRequired' => 'false',
            'Drives' => [
                ['Size' => 100, 'SizeRemaining' => 40, 'DriveType' => 3, 'DriveLetter' => 'C'],
            ],
        ],
    ];

    public function test_user_can_log_in_and_see_devices(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['password' => 'secret']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret'])
            ->assertRedirect('/devices');

        $this->get('/devices')->assertOk();
    }

    public function test_device_enrolment_reporting_and_commands(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ShowDevices::class)->set('addDevice', true);
        $code = Enrolment::firstOrFail()->code;

        $token = $this->postJson('/api/device/register', ['enrolment_code' => $code])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->postJson('/api/device', $this->payload)
            ->assertOk()
            ->assertJson(['commands' => []]);

        $device = Device::firstOrFail();
        $this->assertSame('pc1', $device->name);

        $this->get('/devices?selectedDeviceId='.$device->id)
            ->assertOk()
            ->assertSee('pc1')
            ->assertSee('1 hour');

        Livewire::test(DeviceCommands::class, ['selectedDeviceId' => $device->id])
            ->call('sendCommandToDevice', 'restart');
        $this->assertSame(['restart'], $device->fresh()->commands);

        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])
            ->set('friendlyName', 'My PC')
            ->call('saveFriendlyName')
            ->assertSee('My PC');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/device', $this->payload)
            ->assertJson(['commands' => ['restart']]);
    }

    public function test_device_api_requires_token(): void
    {
        $this->postJson('/api/device', $this->payload)->assertUnauthorized();
    }
}
