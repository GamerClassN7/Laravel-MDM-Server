<?php

namespace App\Support;

class InstallCommands
{
    /**
     * One-liners that download the agent to a temporary file and run its installer
     * (it copies itself to the install directory, enrols the device and registers the service).
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
            'windows' => sprintf(
                'iwr -useb \'%s\' -OutFile "$env:TEMP\mdm-agent.ps1"; & powershell -ExecutionPolicy Bypass -File "$env:TEMP\mdm-agent.ps1" %s',
                $scriptUrl,
                $arguments,
            ),
            // PowerShell 7 on Windows (as Administrator) or Linux (sudo pwsh).
            'pwsh' => sprintf(
                'iwr -useb \'%s\' -OutFile ($f = Join-Path ([IO.Path]::GetTempPath()) \'mdm-agent.ps1\'); & pwsh -ExecutionPolicy Bypass -File $f %s',
                $scriptUrl,
                $arguments,
            ),
        ];
    }
}
