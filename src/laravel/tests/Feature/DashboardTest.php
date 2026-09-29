<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Types\PermissionType;
use App\View\Components\Widgets\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(bool $online): Device
    {
        $device = new Device();
        $device->token = hash('sha256', uniqid('', true));
        $device->commands = [];
        $device->save();
        if ($online) {
            Device::recordHeartbeat($device->id);
        } else {
            $device->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();
        }

        return $device->fresh();
    }

    public function test_migration_creates_a_shared_default_dashboard(): void
    {
        $dashboard = DB::table('dashboards')->first();

        $this->assertSame('Overview', $dashboard->name);
        $this->assertEquals(1, $dashboard->is_shared);
        $widget = DB::table('dashboard_widgets')->where('dashboard_id', $dashboard->id)->first();
        $this->assertSame('DeviceStatus', $widget->type);
        $this->assertSame([['type' => 'row', 'items' => [['id' => $widget->id, 'width' => 4, 'height' => 1]]]], json_decode($dashboard->body, true));
    }

    public function test_dashboard_page_renders_for_any_user(): void
    {
        // Not the owner of the shared default dashboard and not a system admin.
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertOk()->assertSee('Overview');
        $this->get('/dashboard/editor')->assertOk();
    }

    public function test_dashboard_requires_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_device_status_widget_counts_online_and_offline_devices(): void
    {
        $this->createDevice(online: true);
        $this->createDevice(online: true);
        $offline = $this->createDevice(online: false);

        $html = Blade::render('<x-widgets.DeviceStatus :config="$config" />', ['config' => ['list' => '5']]);

        $this->assertMatchesRegularExpression('/>\s*2\s*<\/div>\s*<small[^>]*>Online/', $html);
        $this->assertMatchesRegularExpression('/>\s*1\s*<\/div>\s*<small[^>]*>Offline/', $html);
        $this->assertStringContainsString('selectedDeviceId='.$offline->id, $html);
        $this->assertStringContainsString('wire:poll.30s', $html);
    }

    public function test_widget_config_keeps_only_known_keys(): void
    {
        $widget = new DeviceStatus(['name' => 'Fleet', 'bogus' => 'x']);

        $this->assertSame('Fleet', $widget->config['name']);
        $this->assertArrayNotHasKey('bogus', $widget->config);
    }

    public function test_system_admins_get_the_admin_permission(): void
    {
        $user = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $user->id]]);

        $this->assertSame(PermissionType::ADMIN, $user->fresh()->permission);
        $this->assertSame(PermissionType::USER, User::factory()->create()->permission);
    }
}
