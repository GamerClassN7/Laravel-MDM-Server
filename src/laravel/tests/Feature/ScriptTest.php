<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\Script\DataTable;
use App\Livewire\Script\Detail;
use App\Livewire\Script\Form;
use App\Livewire\Script\Run;
use App\Livewire\ScriptRun\DataTable as RunDataTable;
use App\Models\Device;
use App\Models\Script;
use App\Models\ScriptRun;
use App\Models\User;
use App\Support\Signing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use SteelAnts\LaravelBoilerplate\Models\Activity;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

class ScriptTest extends TestCase
{
    use RefreshDatabase, SignsDeviceRequests;

    private function device(string $token = 'secret-token', string $platform = 'linux', bool $signed = true, bool $scriptsEnabled = true): Device
    {
        $device = new Device();
        $device->token = hash('sha256', $token);
        $device->commands = [];
        $device->name = "srv-$token";
        $device->data = json_encode(['machine' => ['Hostname' => "srv-$token", 'Platform' => $platform, 'ScriptsEnabled' => $scriptsEnabled, 'Drives' => []]]);
        $device->save();
        Device::recordHeartbeat($device->id);

        return $signed ? $this->registerDeviceKey($device) : $device->fresh();
    }

    private function script(array $attributes = []): Script
    {
        return Script::create($attributes + ['name' => 'Check', 'platform' => 'all', 'detection' => "if (\$true) {\n\texit 1\n}\n", 'remediation' => "'fixed'\n", 'timeout' => 60]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        config(['boilerplate.system_admins' => [(string) $admin->id]]);

        return $admin;
    }

    public function test_fingerprint_and_version_follow_the_code(): void
    {
        $script = $this->script();
        $this->assertSame(1, $script->version);
        $this->assertSame(Script::fingerprintOf('all', 60, $script->detection, $script->remediation), $script->fingerprint);
        // Stored byte for byte, tabs and trailing new lines included.
        $this->assertSame("if (\$true) {\n\texit 1\n}\n", $script->fresh()->detection);

        $fingerprint = $script->fingerprint;
        $script->update(['name' => 'Renamed', 'description' => 'Only metadata']);
        $this->assertSame([1, $fingerprint], [$script->version, $script->fingerprint]);

        $script->update(['remediation' => "'fixed differently'\n"]);
        $this->assertSame(2, $script->version);
        $this->assertNotSame($fingerprint, $script->fingerprint);

        // An empty remediation means detection only.
        $script->update(['remediation' => "  \n"]);
        $this->assertNull($script->remediation);
    }

    public function test_run_is_queued_only_where_it_can_run(): void
    {
        $linux = $this->device('linux');
        $windows = $this->device('windows', 'windows');
        $legacy = $this->device('legacy', signed: false);
        $disabled = $this->device('disabled', scriptsEnabled: false);
        $script = $this->script(['platform' => 'linux']);

        $runs = $script->runOn([$linux->id, $windows->id, $legacy->id, $disabled->id], $this->admin());

        $this->assertSame([$linux->id], $runs->pluck('device_id')->all());
        $this->assertSame(['runScripts'], $linux->fresh()->commands);
        $this->assertSame('Other platform', $script->unavailableReason($windows));
        $this->assertSame('Agent does not sign (update it)', $script->unavailableReason($legacy));
        $this->assertSame('Scripts disabled on the device', $script->unavailableReason($disabled));
        $activity = Activity::query()->where('affected_type', Script::class)->latest('id')->first();
        $this->assertSame(['version' => 1, 'fingerprint' => $script->fingerprint, 'devices' => [$linux->id]], $activity->data);

        // Running again replaces the run still waiting.
        $script->runOn([$linux->id]);
        $this->assertSame(['superseded', 'pending'], $script->runs()->orderBy('id')->pluck('status')->all());
    }

    public function test_agent_takes_signed_runs_once(): void
    {
        $device = $this->device();
        $script = $this->script();
        $run = $script->runOn([$device->id])->first();

        $this->signedJson('POST', '/api/device', ['machine' => ['Hostname' => 'srv1', 'Platform' => 'linux', 'ScriptsEnabled' => true, 'Drives' => []]], 'secret-token')
            ->assertJson(['scripts_pending' => true]);

        $response = $this->assertSignedResponse($this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->assertOk(), $device->id);
        $payload = $response->json('runs.0');
        $this->assertTrue(Signing::verify(Signing::publicKey(), 'MDM1-SCRIPT', $payload['manifest'], $payload['signature']));
        $manifest = json_decode($payload['manifest'], true);
        $this->assertSame([$run->id, $device->id, $script->fingerprint, 'all', 60], [$manifest['run_id'], $manifest['device_id'], $manifest['fingerprint'], $manifest['platform'], $manifest['timeout']]);
        $this->assertSame(hash('sha256', base64_decode($payload['detection'])), $manifest['detection_sha256']);
        $this->assertSame(hash('sha256', base64_decode($payload['remediation'])), $manifest['remediation_sha256']);
        $this->assertSame('sent', $run->fresh()->status);

        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->assertExactJson(['runs' => []]);
    }

    public function test_expired_and_changed_runs_are_not_sent(): void
    {
        $device = $this->device();
        $expired = $this->script(['name' => 'Old'])->runOn([$device->id])->first();
        $expired->update(['expires_at' => now()->subMinute()]);
        $changed = $this->script(['name' => 'Changed']);
        $changedRun = $changed->runOn([$device->id])->first();
        $changed->update(['detection' => 'exit 0']);

        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token')->assertExactJson(['runs' => []]);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('superseded', $changedRun->fresh()->status);
    }

    public function test_scripts_need_a_signing_agent(): void
    {
        $this->device(signed: false);

        $this->withToken('secret-token')->getJson('/api/device/scripts')->assertForbidden();
    }

    public function test_agent_reports_the_result_of_its_own_run(): void
    {
        $device = $this->device();
        $other = $this->device('other-token');
        $script = $this->script();
        $run = $script->runOn([$device->id])->first();
        $otherRun = $script->runOn([$other->id])->first();

        // Not taken yet.
        $this->signedJson('POST', "/api/device/scripts/runs/{$run->id}", ['status' => 'compliant', 'fingerprint' => $script->fingerprint], 'secret-token')
            ->assertStatus(409);
        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token');

        $this->signedJson('POST', "/api/device/scripts/runs/{$otherRun->id}", ['status' => 'compliant'], 'secret-token')->assertNotFound();

        $this->signedJson('POST', "/api/device/scripts/runs/{$run->id}", [
            'status' => 'remediated',
            'fingerprint' => $script->fingerprint,
            'detection_exit' => 1,
            'remediation_exit' => 0,
            'post_detection_exit' => 0,
            'output' => str_repeat('x', 20000),
        ], 'secret-token')->assertOk()->assertExactJson(['status' => 'remediated']);

        $run->refresh();
        $this->assertSame(['remediated', 1, 0, 0], [$run->status, $run->detection_exit, $run->remediation_exit, $run->post_detection_exit]);
        $this->assertSame(ScriptRun::MAX_OUTPUT, strlen($run->output));
        $this->assertNotNull($run->finished_at);
    }

    public function test_result_of_another_fingerprint_is_an_error(): void
    {
        $device = $this->device();
        $run = $this->script()->runOn([$device->id])->first();
        $this->signedJson('GET', '/api/device/scripts', [], 'secret-token');

        $this->signedJson('POST', "/api/device/scripts/runs/{$run->id}", ['status' => 'compliant', 'fingerprint' => str_repeat('0', 64)], 'secret-token')
            ->assertExactJson(['status' => 'error']);
        $this->assertSame('The device ran a different fingerprint', $run->fresh()->error);
    }

    public function test_scripts_are_for_system_admins_only(): void
    {
        $this->withoutVite();
        $script = $this->script(['name' => 'Firewall on']);
        // APP_SYSTEM_ADMINS of the local .env would make the first users admins.
        config(['boilerplate.system_admins' => []]);
        $this->actingAs(User::factory()->create());
        $this->get('/scripts')->assertForbidden();
        $this->get('/scripts/'.$script->id)->assertForbidden();

        $this->actingAs($this->admin());
        $this->get('/scripts')->assertOk()->assertSee('Scripts')->assertSee('Firewall on');
        $this->get('/scripts/'.$script->id)->assertOk()->assertSee('Firewall on')->assertSee($script->fingerprint);
    }

    public function test_admin_creates_and_runs_a_script(): void
    {
        $this->actingAs($this->admin());
        $device = $this->device();
        $windows = $this->device('windows', 'windows');

        Livewire::test(Form::class)
            ->set('name', 'Disk space')
            ->set('platform', 'linux')
            ->set('detection', "\$free = 10\r\nif (\$free -lt 5) { exit 1 }\r\n")
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('scriptSaved');
        $script = Script::firstOrFail();
        $this->assertSame("\$free = 10\r\nif (\$free -lt 5) { exit 1 }\r\n", $script->detection);
        $this->assertNull($script->remediation);

        Livewire::test(Run::class, ['scriptId' => $script->id])
            ->assertSee('srv-secret-token')
            ->assertSee('Other platform')
            ->set('selected', [(string) $device->id, (string) $windows->id])
            ->call('start');
        $this->assertSame([$device->id], $script->runs()->pluck('device_id')->all());

        // Opened again, the devices of the last run are selected.
        Livewire::test(Run::class, ['scriptId' => $script->id])->assertSet('selected', [(string) $device->id]);

        Livewire::test(DataTable::class)->assertSee('Disk space')->assertSee(route('script.show', $script))->assertDontSee('Detection only');
        Livewire::test(Detail::class, ['script' => $script])->assertSee('Disk space')->assertSee('v1');
        Livewire::test(RunDataTable::class, ['scriptId' => $script->id])->assertSee('srv-secret-token')->assertSee('Pending');
        Livewire::test(DeviceDetail::class, ['selectedDeviceId' => $device->id])->assertSee('Scripts')->assertSee('Disk space');
    }

    public function test_script_code_is_required(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Form::class)->set('name', 'Empty')->call('save')->assertHasErrors(['detection']);
        Livewire::test(Form::class)->set('name', 'Slow')->set('detection', 'exit 0')->set('timeout', 7200)->call('save')->assertHasErrors(['timeout']);
    }
}
