<?php

use App\Models\Script;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'Allow security logs';

    /** security_logs "on" in the agent's config.json (MDM_AGENT_DIR, agents 1.17.0+). */
    private const DETECTION = <<<'PS'
# Security logs: the agent sends the records of its security logs (sign-ins, accounts, sudo,
# Defender, ...) when security_logs is "on" in its config.json. Exit 0 = on, exit 1 = not yet.
$dir = if ($env:MDM_AGENT_DIR) { $env:MDM_AGENT_DIR } elseif ($IsLinux) { '/opt/laravel-mdm' } else { Join-Path $env:ProgramData 'Laravel-MDM' }
$path = Join-Path $dir 'config.json'
if (-not (Test-Path -Path $path)) {
    Write-Output "No agent configuration at $path"
    exit 1
}
$level = (Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json).security_logs
if ($level -eq 'on') {
    Write-Output 'Security logs are sent'
    exit 0
}
Write-Output "security_logs is '$(if ($level) { $level } else { 'off (default)' })'"
exit 1
PS;

    /** Sets security_logs to "on" (a backup next to it); the agent takes it with its next collection. */
    private const REMEDIATION = <<<'PS'
# Turns the security logs on: security_logs "on" in the agent's config.json. The previous file is
# kept as config.json.bak; the agent reads the setting with its next collection (hourly).
$dir = if ($env:MDM_AGENT_DIR) { $env:MDM_AGENT_DIR } elseif ($IsLinux) { '/opt/laravel-mdm' } else { Join-Path $env:ProgramData 'Laravel-MDM' }
$path = Join-Path $dir 'config.json'
if (-not (Test-Path -Path $path)) {
    Write-Output "No agent configuration at $path"
    exit 1
}
$raw = Get-Content -Path $path -Raw -Encoding UTF8
$config = $raw | ConvertFrom-Json
$config | Add-Member -NotePropertyName security_logs -NotePropertyValue 'on' -Force
$json = $config | ConvertTo-Json -Depth 10
# Written only when it reads back as the same settings with the logs on.
if (($json | ConvertFrom-Json).security_logs -ne 'on') {
    Write-Output 'The new configuration did not read back, nothing changed'
    exit 1
}
Set-Content -Path "$path.bak" -Value $raw -Encoding UTF8
Set-Content -Path $path -Value $json -Encoding UTF8
Write-Output "security_logs set to 'on' in $path"
exit 0
PS;

    /**
     * A remediation script every installation starts with, like Allow network scans: it turns the
     * security logs on for the devices it runs on. Manual remediation: a run only detects, the
     * remediation runs when an admin starts it (Remediate) on a device; nothing runs, and nothing
     * is switched on, until someone runs the script.
     */
    public function up(): void
    {
        if (DB::table('scripts')->where('name', self::NAME)->exists()) {
            return;
        }
        $now = now();
        DB::table('scripts')->insert([
            'name' => self::NAME,
            'description' => 'Sets security_logs to "on" in the agent\'s config.json, so the device sends the records of its sign-in and system logs for the security checks (agents 1.17.0+). Run it on the devices that should send them; it only detects, Remediate turns the logs on.',
            'platform' => 'all',
            'detection' => self::DETECTION,
            'remediation' => self::REMEDIATION,
            'timeout' => 60,
            'version' => 1,
            'fingerprint' => Script::fingerprintOf('all', 60, self::DETECTION, self::REMEDIATION),
            'manual_remediation' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('scripts')->where('name', self::NAME)->where('version', 1)->delete();
    }
};
