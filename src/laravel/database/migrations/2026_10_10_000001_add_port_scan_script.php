<?php

use App\Models\Script;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'Allow port scans';

    /** port_scan "on" in the agent's config.json (MDM_AGENT_DIR, agents 1.18.0+). */
    private const DETECTION = <<<'PS'
# Port scanning: scans of the open ports of an address in the device's network (Networks page) are
# allowed when port_scan is "on" in the agent's config.json. Exit 0 = allowed, exit 1 = not yet.
$dir = if ($env:MDM_AGENT_DIR) { $env:MDM_AGENT_DIR } elseif ($IsLinux) { '/opt/laravel-mdm' } else { Join-Path $env:ProgramData 'Laravel-MDM' }
$path = Join-Path $dir 'config.json'
if (-not (Test-Path -Path $path)) {
    Write-Output "No agent configuration at $path"
    exit 1
}
$level = (Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json).port_scan
if ($level -eq 'on') {
    Write-Output 'Port scans are allowed'
    exit 0
}
Write-Output "port_scan is '$(if ($level) { $level } else { 'off (default)' })'"
exit 1
PS;

    /** Sets port_scan to "on" (a backup next to it); the agent takes it with its next report. */
    private const REMEDIATION = <<<'PS'
# Allows port scans: port_scan "on" in the agent's config.json. The previous file is kept as
# config.json.bak; the agent reads the setting with its next report.
$dir = if ($env:MDM_AGENT_DIR) { $env:MDM_AGENT_DIR } elseif ($IsLinux) { '/opt/laravel-mdm' } else { Join-Path $env:ProgramData 'Laravel-MDM' }
$path = Join-Path $dir 'config.json'
if (-not (Test-Path -Path $path)) {
    Write-Output "No agent configuration at $path"
    exit 1
}
$raw = Get-Content -Path $path -Raw -Encoding UTF8
$config = $raw | ConvertFrom-Json
$config | Add-Member -NotePropertyName port_scan -NotePropertyValue 'on' -Force
$json = $config | ConvertTo-Json -Depth 10
# Written only when it reads back as the same settings with port scans allowed.
if (($json | ConvertFrom-Json).port_scan -ne 'on') {
    Write-Output 'The new configuration did not read back, nothing changed'
    exit 1
}
Set-Content -Path "$path.bak" -Value $raw -Encoding UTF8
Set-Content -Path $path -Value $json -Encoding UTF8
Write-Output "port_scan set to 'on' in $path"
exit 0
PS;

    /**
     * A remediation script that allows port scans on the devices it runs on. Manual remediation: a
     * run only detects, the remediation runs when an admin starts it (Remediate) on a device;
     * nothing runs until someone runs the script.
     */
    public function up(): void
    {
        if (DB::table('scripts')->where('name', self::NAME)->exists()) {
            return;
        }
        $now = now();
        DB::table('scripts')->insert([
            'name' => self::NAME,
            'description' => 'Sets port_scan to "on" in the agent\'s config.json, so the Networks page can scan the open ports of an address in the device\'s network (agents 1.18.0+). Run it on the devices that should scan; it only detects, Remediate allows the scans.',
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
