function Get-MachineInfo {
    $DnsInfo = [System.Net.Dns]::GetHostByName($env:computerName)
    [PSCustomObject]@{
        Hostname        = $DnsInfo.HostName
        User            = $env:USERNAME
        Battery         = (Get-WmiObject  win32_battery -Property EstimatedChargeRemaining).EstimatedChargeRemaining
        RestartRequired = Test-PendingReboot
        IPAddresses     = $DnsInfo.AddressList.IPAddressToString
        Disks           = Get-Volume | Where-Object -Property DriveLetter -Value '' -NotLike | ForEach-Object {
            [PSCustomObject]@{
                "DriveLetter"   = $_.DriveLetter
                "FriendlyName"  = $_.FileSystemLabel
                "SizeRemaining" = $_.SizeRemaining
                "Size"          = $_.Size
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
                $name = $line.Substring(0, $idStart).TrimEnd();
                $id = $line.Substring($idStart, ($versionStart - $idStart)).TrimEnd();

                if ($Updatable) {
                    $version = $line.Substring($versionStart, ($sourceStart - $availableStart)).TrimEnd();
                    $available = $line.Substring($availableStart, ($sourceStart - $availableStart)).TrimEnd();
                }
                else {
                    $version = $line.Substring($versionStart, ($sourceStart - $versionStart)).TrimEnd();
                }
                $source = $line.Substring($sourceStart, ($line.Length - $sourceStart)).TrimEnd();

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
    $u = New-Object -ComObject Microsoft.Update.Session
    $u.ClientApplicationID = 'MSDN Sample Script'
    $s = $u.CreateUpdateSearcher()
    #$r = $s.Search("IsInstalled=0 and Type='Software' and IsHidden=0")
    $r = $s.Search('IsInstalled=0')
    $r.updates | Select-Object -Property Title, IsDownloaded, RebootRequired

    if ($Install) {
        $downloader = $updateSession.CreateUpdateDownloader()
        $downloader.Updates = $updates
        $null = $downloader.Download()

        $installer = $updateSession.CreateUpdateInstaller()
        $installer.Updates = $updates
        $null = $installer.Install()
    }
}

function Test-PendingReboot {
    if (Get-ChildItem "HKLM:\Software\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending" -EA Ignore) { return $true }
    if (Get-Item "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired" -EA Ignore) { return $true }
    if (Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager" -Name PendingFileRenameOperations -EA Ignore) { return $true }
    try {
        $util = [wmiclass]"\\.\root\ccm\clientsdk:CCM_ClientUtilities"
        $status = $util.DetermineIfRebootPending()
        if (( $null -ne $status) -and $status.RebootPending) {
            return $true
        }
    }
    catch {}

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
                $ContainerId = $line.Substring(0, $ImageStart).TrimEnd();
                $Image = $line.Substring($ImageStart, ($CommandStart - $ImageStart)).TrimEnd();
                $Command = $line.Substring($CommandStart, ($CreatedStart - $CommandStart)).TrimEnd();
                $Created = $line.Substring($CreatedStart, ($StatusStart - $CreatedStart)).TrimEnd();
                $Status = $line.Substring($StatusStart, ($PortsStart - $StatusStart)).TrimEnd();
                $Ports = $line.Substring($PortsStart, ($NamesStart - $PortsStart)).TrimEnd();
                $Names = $line.Substring($NamesStart, ($line.Length - $NamesStart)).TrimEnd();

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

$data = @{}
$data['machine'] = Get-MachineInfo
$data['packages_updates'] = Get-WingetSoftware -Updatable | Select-Object -Property Id, Version, Avaliable, Source
$data['os_updates'] = Get-WindowsUpdate
$data['docker_containers'] = Get-DockerContainers | Select-Object -Property Names, Status

$data | ConvertTo-Json -Depth 3
