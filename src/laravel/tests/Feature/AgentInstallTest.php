<?php

namespace Tests\Feature;

use App\Livewire\ShowDevices;
use App\Models\User;
use App\Support\Bytes;
use App\Support\InstallCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_script_is_served_without_login(): void
    {
        $response = $this->get('/agent/app.ps1')->assertOk();

        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('function Start-Agent', $response->streamedContent());
    }

    public function test_install_commands_download_from_this_server(): void
    {
        $commands = InstallCommands::for(1234);

        $this->assertSame(['windows', 'pwsh'], array_keys($commands));
        foreach ($commands as $command) {
            $this->assertStringContainsString("'".url('agent/app.ps1')."'", $command);
            $this->assertStringContainsString("-ServerUrl '".url('/')."' -EnrolmentCode 1234 -Install", $command);
        }
        $this->assertStringStartsWith('iwr -useb ', $commands['windows']);
        $this->assertStringContainsString('& powershell -ExecutionPolicy Bypass -File "$env:TEMP\\mdm-agent.ps1"', $commands['windows']);
        $this->assertStringContainsString('& pwsh -ExecutionPolicy Bypass -File $f', $commands['pwsh']);
    }

    public function test_agent_download_url_can_be_overridden(): void
    {
        config(['mdm.agent_download_url' => 'https://github.com/example/releases/download/v1/app.ps1']);

        foreach (InstallCommands::for(1234) as $command) {
            $this->assertStringContainsString("'https://github.com/example/releases/download/v1/app.ps1'", $command);
        }
    }

    public function test_enrolment_shows_install_commands(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(ShowDevices::class)->set('addDevice', true);
        $code = $component->get('enrollmentCode');

        $component->assertSee('Windows PowerShell')
            ->assertSee('PowerShell 7 (Windows, Linux)')
            ->assertSee("-EnrolmentCode {$code} -Install");
    }

    public function test_sizes_are_human_readable(): void
    {
        $this->assertSame('512 B', Bytes::format(512));
        $this->assertSame('23.3 MB', Bytes::format(24481792));
        $this->assertSame('252 GB', Bytes::format(270553174016));
        $this->assertSame('1.8 TB', Bytes::format(2e12));
    }
}
