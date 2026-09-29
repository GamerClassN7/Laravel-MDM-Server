<?php

namespace App\Support;

class InstallCommands
{
    /**
     * PowerShell one-liners that download the agent, enrol the device and install it as a service.
     *
     * @return array<string, string>
     */
    public static function for(int|string $enrolmentCode): array
    {
        $serverUrl = rtrim(url('/'), '/');
        $scriptUrl = config('mdm.agent_download_url') ?: url('agent/app.ps1');
        $arguments = sprintf("-ServerUrl '%s' -EnrolmentCode %s -Install", $serverUrl, $enrolmentCode);

        return [
            // Windows PowerShell 5.1, run as Administrator.
            'windows' => implode('; ', [
                'Set-ExecutionPolicy -Scope Process Bypass -Force',
                '[Net.ServicePointManager]::SecurityProtocol = \'Tls12\'',
                '$d = "$env:ProgramData\Laravel-MDM"',
                'New-Item -ItemType Directory -Force $d | Out-Null',
                sprintf('Invoke-WebRequest -UseBasicParsing \'%s\' -OutFile "$d\app.ps1"', $scriptUrl),
                '& "$d\app.ps1" '.$arguments,
            ]),
            // PowerShell 7 on Windows (as Administrator) or Linux (sudo pwsh).
            'pwsh' => implode('; ', [
                '$d = if ($IsWindows) { "$env:ProgramData/Laravel-MDM" } else { \'/opt/laravel-mdm\' }',
                'New-Item -ItemType Directory -Force $d | Out-Null',
                sprintf('Invoke-WebRequest \'%s\' -OutFile "$d/app.ps1"', $scriptUrl),
                'if ($IsWindows) { Set-ExecutionPolicy -Scope Process Bypass -Force }',
                '& "$d/app.ps1" '.$arguments,
            ]),
        ];
    }
}
