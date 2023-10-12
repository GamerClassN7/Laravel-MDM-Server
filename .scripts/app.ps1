function Get-MachineInfo {
    $DnsInfo = [System.Net.Dns]::GetHostByName($env:computerName)
    [PSCustomObject]@{
        Hostname        = $DnsInfo.HostName
        User            = $env:USERNAME
        Battery         = (Get-WmiObject  win32_battery -Property EstimatedChargeRemaining).EstimatedChargeRemaining
        RestartRequired = Test-PendingReboot
        IPAddresses     = $DnsInfo.AddressList.IPAddressToString
        Drives          = Get-Volume | Where-Object -Property DriveLetter -Value '' -NotLike | ForEach-Object {
            [PSCustomObject]@{
                "DriveLetter"   = $_.DriveLetter
                "FriendlyName"  = $_.FileSystemLabel
                "SizeRemaining" = $_.SizeRemaining
                "Size"          = $_.Size
                "DriveType"     = $_.DriveType
            }
        }
    }
}

function Get-WingetSoftware {
    param (
        [switch]
        $Updatable
    )
    begin {
        [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
        $upgradeResult = winget list | Out-String
        if ($Updatable) {
            $upgradeResult = winget update | Out-String
        }

        $lines = $upgradeResult.Split([Environment]::NewLine)

        $fl = 0
        while ( -not $lines[$fl].StartsWith("Name")) {
            $fl++
        }

        $idStart = $lines[$fl].IndexOf("Id")
        $versionStart = $lines[$fl].IndexOf("Version")

        if ($Updatable) {
            $availableStart = $lines[$fl].IndexOf("Available")
        }

        $sourceStart = $lines[$fl].IndexOf("Source")
    }

    process {
        For ($i = $fl + 1; $i -le $lines.Length; $i++) {
            $line = $lines[$i]
            if ($lines[$fl].Length -ne $line.Length) {
                continue
            }
            if (-not [string]::IsNullOrEmpty($line) -and -not $line.StartsWith('-')) {
                $name = $line.Substring(0, $idStart).TrimEnd()
                $id = $line.Substring($idStart, ($versionStart - $idStart)).TrimEnd()

                if ($Updatable) {
                    $version = $line.Substring($versionStart, ($availableStart - $versionStart)).TrimEnd()
                    $available = $line.Substring($availableStart, ($sourceStart - $availableStart)).TrimEnd()
                }
                else {
                    $version = $line.Substring($versionStart, ($sourceStart - $versionStart)).TrimEnd()
                }
                $source = $line.Substring($sourceStart, ($line.Length - $sourceStart)).TrimEnd()

                $tempObjLine = [PSCustomObject]@{
                    Name    = $name
                    Id      = $id
                    Version = $version
                    Source  = $source
                }

                if ($Updatable) {
                    $tempObjLine | Add-Member -Name 'Avaliable' -Value $available -MemberType NoteProperty
                }

                $tempObjLine
            }
        }
    }
}

function Get-WindowsUpdate {
    param (
        [switch]
        $Install
    )

    $UpdateSession = New-Object -ComObject Microsoft.Update.Session
    $UpdateSearcher = $UpdateSession.CreateupdateSearcher()
    $Updates = $UpdateSearcher.Search("IsInstalled=0").Updates
    #$s.Search("IsInstalled=0 and Type='Software' and IsHidden=0 and IsHidden=0 and IsInstalled=0")
    $Updates | Select-Object -Property Title, IsDownloaded, RebootRequired | foreach-object {
        return [PSCustomObject]@{
            Title          = $_.Title
            IsDownloaded   = $_.IsDownloaded
            RebootRequired = $_.RebootRequired
        }
    }

    if ($Install) {
        $Session = New-Object -ComObject Microsoft.Update.Session
        $Downloader = $Session.CreateUpdateDownloader()
        $Downloader.Updates = @($Updates)
        $Downloader.Download()

        $Installer = New-Object -ComObject Microsoft.Update.Installer
        $Installer.Updates = $Updates
        $Result = $Installer.Install()
    }
}

function Test-PendingReboot {
    if (Get-ChildItem "HKLM:\Software\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending" -EA Ignore) {
        return $true
    }
    if (Get-Item "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired" -EA Ignore) {
        return $true
    }
    if (Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager" -Name PendingFileRenameOperations -EA Ignore) {
        return $true
    }
    try {
        $util = [wmiclass]"\\.\root\ccm\clientsdk:CCM_ClientUtilities"
        $status = $util.DetermineIfRebootPending()
        if (( $null -ne $status) -and $status.RebootPending) {
            return $true
        }
    }
    catch {
    }

    return $false
}

function Get-DockerContainers {
    begin {
        [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
        $upgradeResult = $(docker ps --no-trunc | Out-String)
        $lines = $upgradeResult.Split([Environment]::NewLine)

        $fl = 0
        while ( -not $lines[$fl].StartsWith("CONTAINER ID")) {
            $fl++
        }

        $ContainerIdStart = $lines[$fl].IndexOf("CONTAINER ID")
        $ImageStart = $lines[$fl].IndexOf("IMAGE")
        $CommandStart = $lines[$fl].IndexOf("COMMAND")
        $CreatedStart = $lines[$fl].IndexOf("CREATED")
        $StatusStart = $lines[$fl].IndexOf("STATUS")
        $PortsStart = $lines[$fl].IndexOf("PORTS")
        $NamesStart = $lines[$fl].IndexOf("NAMES")
    }

    process {
        For ($i = $fl + 1; $i -le $lines.Length; $i++) {
            $line = $lines[$i]
            if (-not [string]::IsNullOrEmpty($line)) {
                $ContainerId = $line.Substring(0, $ImageStart).TrimEnd()
                $Image = $line.Substring($ImageStart, ($CommandStart - $ImageStart)).TrimEnd()
                $Command = $line.Substring($CommandStart, ($CreatedStart - $CommandStart)).TrimEnd()
                $Created = $line.Substring($CreatedStart, ($StatusStart - $CreatedStart)).TrimEnd()
                $Status = $line.Substring($StatusStart, ($PortsStart - $StatusStart)).TrimEnd()
                $Ports = $line.Substring($PortsStart, ($NamesStart - $PortsStart)).TrimEnd()
                $Names = $line.Substring($NamesStart, ($line.Length - $NamesStart)).TrimEnd()

                [PSCustomObject]@{
                    ContainerId = $ContainerId
                    Image       = $Image
                    Command     = $Command
                    Created     = $Created
                    Status      = $Status
                    Ports       = ($Ports -split ",")
                    Names       = $Names
                }
            }
        }
    }
}

function New-JobRegistration {
    $Trigger1 = New-ScheduledTaskTrigger -Daily -DaysInterval 1 -At 0am
    $Trigger1.Repetition = $(New-ScheduledTaskTrigger -Once -At 0am -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration  (New-TimeSpan -Hours 23 -Minutes 59)).Repetition
    $Trigger2 = New-ScheduledTaskTrigger -AtLogOn
    $Trigger3 = New-ScheduledTaskTrigger -AtStartup

    #$User = "NT AUTHORITY\SYSTEM"
    $Action = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument ("-windowstyle hidden -executionpolicy bypass -NoLogo -file {0}/app.ps1" -f $PSScriptRoot)
    Register-ScheduledTask -AsJob -TaskName "Laravell-MDM-Agent" -Trigger @($Trigger1, $Trigger2, $Trigger3) <#-User $User#> -Action $Action -RunLevel Highest -Force
}

function Register-MDMDevice {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $EnrolmentCode,
        [Parameter(Mandatory = $true)]
        [string]
        $Url
    )

    $response = Invoke-RestMethod -Method Post -Uri $url -Body $(@{ 'enrolment_code' = $EnrolmentCode } | ConvertTo-Json)
    return $response
}

function Invoke-ApiRequest {
    param (
        [Parameter(Mandatory = $true)]
        [array]
        $data,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $url = ''
    $response = Invoke-RestMethod -Method Post -Uri $url -Body $($data | ConvertTo-Json -Depth 4) -Headers @{ "Authorization" = "Bearer $Token" }
    return $response
}

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$init = [scriptblock]::create(@"
    function Get-WingetSoftware {${function:Get-WingetSoftware}}
    function Get-WindowsUpdate {${function:Get-WindowsUpdate}}
    function Get-DockerContainers {${function:Get-DockerContainers}}
"@)

$jobs = @()
$jobs += Start-Job -ScriptBlock { Get-WindowsUpdate } -Name 'os_updates' -InitializationScript $init
$jobs += Start-Job -ScriptBlock { Get-WingetSoftware -Updatable | Select-Object -Property Id, Version, Avaliable, Source } -Name 'packages_updates' -InitializationScript $init
$jobs += Start-Job -ScriptBlock { Get-DockerContainers | Select-Object -Property Names, Status } -Name 'docker_containers' -InitializationScript $init
$jobs | Wait-Job >> $null

$data = @{}
$data['machine'] = Get-MachineInfo
$jobs | ForEach-Object { $data[$_.Name] = Receive-Job $_ | Select-Object -ExcludeProperty "PSComputerName", "RunspaceId", "PSShowComputerName" }
$data | ConvertTo-Json -Depth 3 > E:\_GIT\LAR_MDM\.scripts\payload.log

#New-JobRegistration
$AuthFilePath = "$PSScriptRoot\Token.xml"
if (-not (Test-Path -Path $AuthFilePath)) {
    @{
        "token" = ((Register-MDMDevice).token | ConvertTo-SecureString -AsPlainText -Force)
    } | Export-Clixml -Path $AuthFilePath
}
$Auth = Import-Clixml -Path $AuthFilePath
$(Invoke-ApiRequest -data $data -Token ($Auth.token | ConvertFrom-SecureString -AsPlainText) | ConvertTo-Json -Depth 4) > E:\_GIT\LAR_MDM\.scripts\request.log
