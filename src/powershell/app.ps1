<#
.SYNOPSIS
    Laravel-MDM agent for Windows and Linux (Debian / Ubuntu with PowerShell 7).

.DESCRIPTION
    Runs continuously: keeps a WebSocket connection to the server (Laravel Reverb, Pusher protocol)
    to receive commands instantly and periodically sends a device report over HTTP. When the
    WebSocket is unavailable, commands are still delivered in the response to the HTTP report.

.PARAMETER ReverbHost
    Overrides the WebSocket host announced by the server (also -ReverbPort, -ReverbKey).

.PARAMETER ReverbScheme
    WebSocket scheme, defaults to the scheme of -ServerUrl (https => wss).

.PARAMETER InventoryInterval
    Seconds between the (expensive) Windows Update and winget checks, default 6 hours.

.PARAMETER HealthInterval
    Seconds between the disk health (S.M.A.R.T.) checks, default 1 hour.

.PARAMETER InstallPath
    Where -Install copies the agent to, default %ProgramData%\Laravel-MDM or /opt/laravel-mdm.

.PARAMETER NoRealtime
    Do not use the WebSocket, rely on HTTP only.

.EXAMPLE
    # Enrol the device and register the agent as a scheduled task running as SYSTEM
    .\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install

.EXAMPLE
    # Linux: enrol and register the agent as a systemd service
    sudo pwsh ./app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install

.EXAMPLE
    # Reverb reachable on a different address than the one configured on the server
    .\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -ReverbHost ws.example.com -ReverbPort 443 -ReverbScheme https -Install
#>
param (
    [string]
    $ServerUrl = 'https://sa-dev.cz/laravel-mdm/public/index.php',
    [string]
    $EnrolmentCode,
    [switch]
    $Install,
    [switch]
    $Once,
    [int]
    $ReportInterval = 300,
    [int]
    $HeartbeatInterval = 30,
    [int]
    $InventoryInterval = 21600,
    [int]
    $HealthInterval = 3600,
    [string]
    $ReverbHost,
    [int]
    $ReverbPort,
    [ValidateSet('http', 'https')]
    [string]
    $ReverbScheme,
    [string]
    $ReverbKey,
    [switch]
    $NoRealtime,
    [string]
    $InstallPath
)

$ErrorActionPreference = 'Stop'
# Reported to the server, which offers an update when it serves a newer agent.
$AgentVersion = '1.6.1'
$AllowedCommands = @('turnOff', 'restart', 'doUpdates', 'updateAgent')
# $IsLinux only exists in PowerShell 6+, Windows PowerShell 5.1 is always Windows.
$OnLinux = [bool](Get-Variable -Name IsLinux -ValueOnly -ErrorAction SilentlyContinue)
# Token, logs and cache live next to the script; -Install moves the agent to its install directory first.
$AgentDir = $PSScriptRoot

function Get-MachineInfo {
    $DnsInfo = [System.Net.Dns]::GetHostByName($env:computerName)
    $OperatingSystem = Get-CimInstance -ClassName Win32_OperatingSystem -Property Caption, Version, LastBootUpTime, ProductType
    $ComputerSystem = Get-CimInstance -ClassName Win32_ComputerSystem -Property UserName, PCSystemType, Manufacturer, Model
    $Battery = (Get-CimInstance -ClassName Win32_Battery -Property EstimatedChargeRemaining).EstimatedChargeRemaining
    # ProductType 1 = workstation (2, 3 = server editions), PCSystemType 2 = mobile.
    $Type = if ($OperatingSystem.ProductType -ne 1) { 'server' } elseif ($ComputerSystem.PCSystemType -eq 2 -or $null -ne $Battery) { 'laptop' } else { 'desktop' }
    [PSCustomObject] @{
        AgentVersion    = $AgentVersion
        Platform        = 'windows'
        Type            = $Type
        Virtualization  = Get-Virtualization -Vendor $ComputerSystem.Manufacturer -Product $ComputerSystem.Model
        Hostname        = $DnsInfo.HostName
        User            = $env:USERNAME
        os              = "$($OperatingSystem.Caption) ($($OperatingSystem.Version))"
        uptime          = [int]((Get-Date) - $OperatingSystem.LastBootUpTime).TotalSeconds
        last_logon_user = $ComputerSystem.UserName
        Processor       = (Get-ItemProperty -Path 'HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0' -Name ProcessorNameString -ErrorAction SilentlyContinue).ProcessorNameString
        Cores           = [Environment]::ProcessorCount
        Battery         = $Battery
        PluggedIn       = (Get-PowerStatus).plugged
        RestartRequired = Test-PendingReboot
        Drives          = Get-Volume | Where-Object -Property DriveLetter -Value '' -NotLike | ForEach-Object {
            [PSCustomObject]@{
                "DriveLetter"   = $_.DriveLetter
                "FriendlyName"  = $_.FileSystemLabel
                "SizeRemaining" = $_.SizeRemaining
                "Size"          = $_.Size
                "DriveType"     = $_.DriveType
            }
        }
        Networks        = @(Get-NetAdapter | Where-Object -Property Status -Value 'Disabled' -NotLike | Where-Object -Property Status -Value 'Disconnected' -NotLike | Where-Object -Property ConnectorPresent -Value 'False' -NotLike | ForEach-Object {
                $address = Get-NetIPAddress -InterfaceIndex $_.InterfaceIndex
                [PSCustomObject]@{
                    "Name"                 = $_.Name
                    "InterfaceDescription" = $_.InterfaceDescription
                    "Status"               = $_.Status
                    "IPAddresses"          = $address.IPAddress
                }
            })
    }
}

function Get-WingetPath {
    # SYSTEM (the agent's account) has no App Installer alias on the PATH: use the newest installed package.
    $command = Get-Command -Name winget.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($command) {
        return $command.Source
    }

    $candidates = Get-ChildItem -Path "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*__8wekyb3d8bbwe\winget.exe" -ErrorAction SilentlyContinue
    $newest = $candidates | Sort-Object -Property { try { [version](($_.Directory.Name -split '_')[1]) } catch { [version]'0.0' } } -Descending | Select-Object -First 1
    if ($newest) {
        return $newest.FullName
    }

    return $null
}

function ConvertFrom-WingetTable {
    param (
        [string[]]
        $Lines
    )

    # Locale independent (winget prints "Name" / "Název" ... in the system language, SYSTEM included):
    # a table is the header above a line of dashes. Columns start where a header word starts and
    # no row has text running across that position (so "K dispozici" stays one column).
    $Lines = @($Lines | ForEach-Object { ("$_" -split "`r")[-1].TrimEnd() })
    for ($i = 1; $i -lt $Lines.Count; $i++) {
        if ($Lines[$i] -notmatch '^-{10,}$') {
            continue
        }
        $header = $Lines[$i - 1]
        $rows = @()
        for ($j = $i + 1; $j -lt $Lines.Count -and $Lines[$j].Trim(); $j++) { $rows += $Lines[$j] }

        $starts = @(0)
        foreach ($match in [regex]::Matches($header, '(?<=\s)\S')) {
            $position = $match.Index
            $crossed = @($rows | Where-Object { $_.Length -gt $position -and $_[$position - 1] -ne ' ' -and $_[$position] -ne ' ' })
            # Rows shorter than the header (a footer like "3 upgrades available.") do not count.
            $crossed = @($crossed | Where-Object { $_.Length -ge $header.Length - 10 })
            if (-not $crossed) { $starts += $position }
        }
        if ($starts.Count -lt 4) {
            continue
        }

        foreach ($row in $rows) {
            $values = for ($c = 0; $c -lt $starts.Count; $c++) {
                $from = $starts[$c]
                $to = if ($c + 1 -lt $starts.Count) { $starts[$c + 1] } else { [int]::MaxValue }
                if ($row.Length -le $from) { '' } else { $row.Substring($from, [math]::Min($to, $row.Length) - $from).Trim() }
            }
            # Name, Id, Version, [Available,] Source; footers and notes have no id and version.
            if ($values.Count -ge 4 -and $values[1] -and $values[2] -and $values[1] -notmatch '\s') {
                ,$values
            }
        }
        $i = $j
    }
}

function Get-WingetSoftware {
    param (
        [switch]
        $Updatable
    )

    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
    $winget = Get-WingetPath
    if (-not $winget) {
        throw 'winget not found'
    }
    # Never prompt (the agent runs without a user): accept the source agreements up front.
    $command = if ($Updatable) { 'upgrade' } else { 'list' }
    $output = @(& $winget $command --accept-source-agreements --disable-interactivity 2>$null)

    foreach ($values in @(ConvertFrom-WingetTable -Lines $output)) {
        $hasAvailable = $values.Count -ge 5
        $item = [PSCustomObject]@{
            Name    = $values[0]
            Id      = $values[1]
            Version = $values[2]
            Source  = $values[$values.Count - 1]
        }
        if ($Updatable) {
            $item | Add-Member -Name 'Avaliable' -Value $(if ($hasAvailable) { $values[3] } else { '' }) -MemberType NoteProperty
        }
        $item
    }
}


function Get-WindowsUpdate {
    $UpdateSession = New-Object -ComObject Microsoft.Update.Session
    $UpdateSearcher = $UpdateSession.CreateUpdateSearcher()
    $Updates = $UpdateSearcher.Search("IsInstalled=0").Updates

    return $Updates | ForEach-Object {
        [PSCustomObject]@{
            Title          = $_.Title
            IsDownloaded   = $_.IsDownloaded
            RebootRequired = $_.RebootRequired
        }
    }
}

function Install-WindowsUpdate {
    # Returns log lines. ResultCode: 2 succeeded, 3 succeeded with errors, 4 failed, 5 aborted.
    $results = @{ 0 = 'not started'; 1 = 'in progress'; 2 = 'succeeded'; 3 = 'succeeded with errors'; 4 = 'failed'; 5 = 'aborted' }
    $Session = New-Object -ComObject Microsoft.Update.Session
    $Updates = $Session.CreateUpdateSearcher().Search("IsInstalled=0 and Type='Software' and IsHidden=0").Updates
    if ($Updates.Count -eq 0) {
        'Windows Update: nothing to install'
        return
    }
    "Windows Update: $($Updates.Count) update(s): $(@($Updates | ForEach-Object { $_.Title }) -join '; ')"

    $Downloader = $Session.CreateUpdateDownloader()
    $Downloader.Updates = $Updates
    $download = $Downloader.Download()
    "Windows Update: download $($results[[int]$download.ResultCode])"

    $Installer = $Session.CreateUpdateInstaller()
    $Installer.Updates = $Updates
    $install = $Installer.Install()
    "Windows Update: install $($results[[int]$install.ResultCode])$(if ($install.RebootRequired) { ', restart required' })"
    for ($i = 0; $i -lt $Updates.Count; $i++) {
        $code = [int]$install.GetUpdateResult($i).ResultCode
        if ($code -ne 2) { "Windows Update: $($Updates.Item($i).Title): $($results[$code])" }
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

function Get-AgentServices {
    # Running services and the ones that should run but do not (automatic but stopped, failed).
    if ($OnLinux) {
        # unit load active sub description
        systemctl list-units --type=service --all --plain --no-legend --no-pager 2>$null | ForEach-Object {
            $parts = $_.Trim() -split '\s+', 5
            if ($parts.Count -ge 4 -and $parts[1] -eq 'loaded' -and ($parts[3] -eq 'running' -or $parts[2] -eq 'failed')) {
                [PSCustomObject]@{
                    Name        = $parts[0] -replace '\.service$', ''
                    DisplayName = if ($parts.Count -eq 5) { $parts[4] } else { '' }
                    State       = if ($parts[2] -eq 'failed') { 'failed' } else { 'running' }
                    StartType   = $null
                }
            }
        }
        return
    }

    Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Status -eq 'Running' -or ($_.StartType -eq 'Automatic' -and $_.Status -eq 'Stopped') } | ForEach-Object {
        [PSCustomObject]@{
            Name        = $_.Name
            DisplayName = $_.DisplayName
            State       = if ($_.Status -eq 'Running') { 'running' } else { 'stopped' }
            StartType   = "$($_.StartType)".ToLowerInvariant()
        }
    }
}

function Test-DockerInstalled {
    # The CLI alone (docker-ce-cli, a Docker Desktop that is not running, a leftover) is not Docker:
    # the engine must be reachable at its default endpoint or one set in DOCKER_HOST.
    if (-not (Get-Command -Name docker -CommandType Application -ErrorAction SilentlyContinue)) {
        return $false
    }
    if ($env:DOCKER_HOST) {
        return $true
    }
    if ($OnLinux) {
        return (Test-Path -Path /var/run/docker.sock) -or (Test-Path -Path /run/docker.sock)
    }

    return Test-Path -Path '\\.\pipe\docker_engine'
}

function Get-DockerContainers {
    # Only when Docker is installed; one call to the local daemon, no per-container requests.
    if (-not (Test-DockerInstalled)) {
        return $null
    }

    $output = @(docker ps --all --no-trunc --format '{{json .}}' 2>&1)
    if ($LASTEXITCODE -ne 0) {
        return @{ error = (($output | Select-Object -First 1) -as [string]).Trim() }
    }

    return @{
        containers = @($output | Where-Object { $_ -is [string] -and $_.StartsWith('{') } | ForEach-Object {
                $container = $_ | ConvertFrom-Json
                [PSCustomObject]@{
                    Name    = $container.Names
                    Image   = $container.Image
                    State   = $container.State
                    Status  = $container.Status
                    Ports   = $container.Ports
                    Created = $container.CreatedAt
                }
            })
    }
}

function Get-DiskHealth {
    param (
        # The previous result, reused for disks that are asleep now.
        $Previous
    )

    if ($OnLinux) {
        return Get-LinuxDiskHealth -Previous $Previous
    }

    $disks = @(Get-PhysicalDisk -ErrorAction Stop | ForEach-Object {
            $counter = $_ | Get-StorageReliabilityCounter -ErrorAction SilentlyContinue
            $errors = [long]$counter.ReadErrorsUncorrected + [long]$counter.WriteErrorsUncorrected
            [PSCustomObject]@{
                Device       = "PhysicalDisk$($_.DeviceId)"
                Model        = $_.FriendlyName
                Serial       = "$($_.SerialNumber)".Trim()
                Protocol     = "$($_.BusType)"
                MediaType    = "$($_.MediaType)"
                Size         = [long]$_.Size
                Health       = switch ("$($_.HealthStatus)") { 'Healthy' { 'passed' } 'Unhealthy' { 'failed' } 'Warning' { 'warning' } default { 'unknown' } }
                Temperature  = if ($counter.Temperature) { [int]$counter.Temperature } else { $null }
                PowerOnHours = if ($null -ne $counter.PowerOnHours) { [long]$counter.PowerOnHours } else { $null }
                WearPercent  = if ($null -ne $counter.Wear) { [int]$counter.Wear } else { $null }
                Reallocated  = $null
                Pending      = $null
                MediaErrors  = if ($counter) { $errors } else { $null }
                Standby      = $false
            }
        })

    return @{ disks = $disks }
}

function Get-LinuxDiskHealth {
    param (
        $Previous
    )

    if (-not (Get-Command -Name smartctl -CommandType Application -ErrorAction SilentlyContinue)) {
        return @{ error = 'smartctl not found, install smartmontools (apt install smartmontools).' }
    }

    $scan = (smartctl --scan -j 2>$null | Out-String | ConvertFrom-Json).devices
    $disks = foreach ($device in @($scan)) {
        if (-not $device.name) {
            continue
        }

        # -n standby: do not spin up a sleeping disk just to read its values.
        $result = smartctl -j -n standby -H -A -i -d $device.type $device.name 2>$null | Out-String | ConvertFrom-Json -ErrorAction SilentlyContinue
        if (-not $result) {
            continue
        }

        if ([bool](@($result.smartctl.messages.string) -match 'STANDBY|SLEEP')) {
            $last = @($Previous.disks) | Where-Object { $_.Device -eq $device.name } | Select-Object -First 1
            if ($last) {
                $last.Standby = $true
                $last
            } else {
                [PSCustomObject]@{ Device = $device.name; Model = $null; Serial = $null; Protocol = $device.protocol; MediaType = $null; Size = $null; Health = 'unknown'; Temperature = $null; PowerOnHours = $null; WearPercent = $null; Reallocated = $null; Pending = $null; MediaErrors = $null; Standby = $true }
            }
            continue
        }

        if (-not $result.model_name -and -not $result.serial_number) {
            # No SMART on this device (virtual disks, some USB bridges).
            continue
        }

        $attributes = @{}
        foreach ($attribute in @($result.ata_smart_attributes.table)) {
            if ($attribute) { $attributes[[int]$attribute.id] = $attribute }
        }
        $nvme = $result.nvme_smart_health_information_log
        # SSD wear on ATA: normalized "life left" attributes (Samsung 177, Intel 233, others 231, 202).
        $wear = $null
        foreach ($id in 177, 231, 233, 202) {
            if ($attributes.ContainsKey($id)) { $wear = 100 - [int]$attributes[$id].value; break }
        }
        if ($nvme) { $wear = $nvme.percentage_used }

        [PSCustomObject]@{
            Device       = $device.name
            Model        = $result.model_name
            Serial       = $result.serial_number
            Protocol     = $result.device.protocol
            MediaType    = if ($nvme -or $result.rotation_rate -eq 0) { 'SSD' } elseif ($result.rotation_rate) { 'HDD' } else { $null }
            Size         = $result.user_capacity.bytes
            Health       = if ($null -eq $result.smart_status.passed) { 'unknown' } elseif ($result.smart_status.passed) { 'passed' } else { 'failed' }
            Temperature  = $result.temperature.current
            PowerOnHours = $result.power_on_time.hours
            WearPercent  = $wear
            Reallocated  = if ($attributes.ContainsKey(5)) { [long]$attributes[5].raw.value } else { $null }
            Pending      = if ($attributes.ContainsKey(197)) { [long]$attributes[197].raw.value } else { $null }
            MediaErrors  = if ($nvme) { [long]$nvme.media_errors } elseif ($attributes.ContainsKey(198)) { [long]$attributes[198].raw.value } else { $null }
            Standby      = $false
        }
    }

    return @{ disks = @($disks) }
}

function Get-PowerShellHosts {
    param (
        [bool]
        $OnLinux
    )

    # Windows PowerShell and PowerShell 7 keep their modules apart, both are checked.
    $hosts = @()
    if (-not $OnLinux) {
        $hosts += @{ Edition = 'Windows PowerShell'; Path = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" }
    }
    $pwsh = Get-Command -Name pwsh -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    $pwshPath = if ($pwsh) { $pwsh.Source } elseif (-not $OnLinux) { "$env:ProgramFiles\PowerShell\7\pwsh.exe" }
    if ($pwshPath) {
        $hosts += @{ Edition = 'PowerShell 7'; Path = $pwshPath }
    }

    return @($hosts | Where-Object { Test-Path -Path $_.Path })
}

function Invoke-PowerShellModules {
    param (
        [bool]
        $OnLinux,
        # Install the newer versions instead of only listing them.
        [switch]
        $Update
    )

    # Runs in each PowerShell edition. Only modules installed from the PowerShell Gallery with
    # Install-Module are considered; nothing ever prompts (-NonInteractive, no NuGet bootstrap).
    $moduleScript = @'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
function ConvertTo-ModuleVersion ($Value) {
    $base = ("$Value" -split '-')[0]
    if ($base -notmatch '\.') { $base += '.0' }
    try { [version]$base } catch { [version]'0.0' }
}
$outdated = @()
$canCheck = (Get-Command -Name Get-InstalledModule -ErrorAction SilentlyContinue) -and (Get-Command -Name Find-Module -ErrorAction SilentlyContinue)
# PowerShellGet 1 (Windows PowerShell) would ask to install the NuGet provider first.
if ($canCheck -and $PSVersionTable.PSEdition -ne 'Core' -and -not (Get-PackageProvider -ListAvailable -Name NuGet -ErrorAction SilentlyContinue)) { $canCheck = $false }
if ($canCheck) {
    $installed = @(Get-InstalledModule -ErrorAction SilentlyContinue | Where-Object { $_.Repository -eq 'PSGallery' } |
        ForEach-Object { [pscustomobject]@{ Name = $_.Name; Version = "$($_.Version)"; User = $null } })
    # Modules users installed for themselves (Install-Module -Scope CurrentUser) are not visible to
    # SYSTEM / root: read their PowerShellGet metadata. They are listed, not updated (only the
    # user can update their own profile).
    $userModules = if ($IsLinux) {
        @(Get-ChildItem -Path '/home/*/.local/share/powershell/Modules/*/*/PSGetModuleInfo.xml' -ErrorAction SilentlyContinue)
    } else {
        $folder = if ($PSVersionTable.PSEdition -eq 'Core') { 'PowerShell' } else { 'WindowsPowerShell' }
        @(Get-ChildItem -Path "$env:SystemDrive\Users\*\Documents\$folder\Modules\*\*\PSGetModuleInfo.xml", "$env:SystemDrive\Users\*\OneDrive*\Documents\$folder\Modules\*\*\PSGetModuleInfo.xml" -ErrorAction SilentlyContinue)
    }
    foreach ($file in $userModules) {
        try {
            $info = Import-Clixml -Path $file.FullName
            $user = ($file.FullName -replace '\\', '/' -split '/')[2]
            if ($info.Repository -eq 'PSGallery') { $installed += [pscustomobject]@{ Name = $info.Name; Version = "$($info.Version)"; User = $user } }
        }
        catch { }
    }
    # The newest version per module and owner (side-by-side versions).
    $installed = @($installed | Group-Object -Property Name, User | ForEach-Object { $_.Group | Sort-Object -Property { ConvertTo-ModuleVersion $_.Version } -Descending | Select-Object -First 1 })
    if ($installed) {
        $latest = @{}
        foreach ($module in @(Find-Module -Name @($installed.Name | Select-Object -Unique) -Repository PSGallery -ErrorAction SilentlyContinue)) { $latest[$module.Name] = "$($module.Version)" }
        foreach ($module in $installed) {
            $available = $latest[$module.Name]
            if ($available -and (ConvertTo-ModuleVersion $available) -gt (ConvertTo-ModuleVersion $module.Version)) {
                $outdated += [pscustomobject]@{ Name = $module.Name; Version = $module.Version; Available = $available; User = $module.User }
            }
        }
    }
}
if ($Update) {
    # Installed next to the current version, like Update-Module always does; failed ones stay listed.
    $outdated = @($outdated | Where-Object {
        if ($_.User) { return $true }
        try { Update-Module -Name $_.Name -RequiredVersion $_.Available -Force -Confirm:$false -ErrorAction Stop; $false } catch { $true }
    })
}
ConvertTo-Json -InputObject @($outdated) -Compress
'@

    $result = @()
    foreach ($powershell in @(Get-PowerShellHosts -OnLinux $OnLinux)) {
        $prefix = if ($Update) { '$Update = $true' } else { '$Update = $false' }
        $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes("$prefix`n$moduleScript"))
        $output = [System.IO.Path]::GetTempFileName()
        try {
            # A separate process per edition (inherits the idle priority), stopped when the gallery hangs.
            $process = Start-Process -FilePath $powershell.Path -ArgumentList '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded -RedirectStandardOutput $output -NoNewWindow -PassThru
            if (-not $process.WaitForExit(900000)) {
                try { $process.Kill() } catch { }
                continue
            }
            $json = (Get-Content -Path $output -Raw) -as [string]
            if ($json) {
                foreach ($module in @($json.Trim() | ConvertFrom-Json)) {
                    if ($module) { $result += [PSCustomObject]@{ Name = $module.Name; Version = $module.Version; Available = $module.Available; Edition = $powershell.Edition; User = $module.User } }
                }
            }
        }
        catch {
        }
        finally {
            Remove-Item -Path $output -Force -ErrorAction SilentlyContinue
        }
    }

    return $result
}

function Get-Virtualization {
    param (
        # System manufacturer and model (SMBIOS), e.g. "QEMU" / "Standard PC (Q35 + ICH9, 2009)".
        [string]
        $Vendor,
        [string]
        $Product
    )

    # Names follow systemd-detect-virt. HypervisorPresent is not used: it is also set on physical
    # machines running Hyper-V or virtualization-based security.
    $identity = "$Vendor $Product"
    $name = switch -Regex ($identity) {
        'VMware' { 'vmware'; break }
        'VirtualBox|innotek' { 'oracle'; break }
        'Parallels' { 'parallels'; break }
        'Xen|HVM domU' { 'xen'; break }
        'QEMU|KVM|Standard PC \(|Proxmox' { 'kvm'; break }
        'Amazon EC2' { 'amazon'; break }
        'Google Compute Engine' { 'google'; break }
        'bhyve' { 'bhyve'; break }
        '^Microsoft Corporation Virtual Machine' { 'microsoft'; break }
    }
    if ($name) {
        return @{ Type = 'vm'; Name = $name }
    }

    return $null
}

#region Linux

function Get-LinuxVirtualization {
    # systemd-detect-virt knows most hypervisors and containers; a VM wins over a container in it.
    if (Get-Command -Name systemd-detect-virt -CommandType Application -ErrorAction SilentlyContinue) {
        foreach ($kind in @('vm', 'container')) {
            $name = "$(systemd-detect-virt --$kind 2>$null)".Trim()
            if ($LASTEXITCODE -eq 0 -and $name -and $name -ne 'none') {
                return @{ Type = $kind; Name = $name }
            }
        }
        return $null
    }

    $vendor = Get-Content -Path /sys/class/dmi/id/sys_vendor -ErrorAction SilentlyContinue
    $product = Get-Content -Path /sys/class/dmi/id/product_name -ErrorAction SilentlyContinue
    $vm = Get-Virtualization -Vendor $vendor -Product $product
    if ($vm) {
        return $vm
    }
    if ((Test-Path -Path /.dockerenv) -or (Test-Path -Path /run/.containerenv)) {
        return @{ Type = 'container'; Name = if (Test-Path -Path /run/.containerenv) { 'podman' } else { 'docker' } }
    }

    return $null
}

function Get-LinuxMachineInfo {
    $osRelease = @{}
    Get-Content -Path /etc/os-release | ForEach-Object {
        if ($_ -match '^(\w+)=(.*)$') { $osRelease[$Matches[1]] = $Matches[2].Trim('"') }
    }
    $cpu = Select-String -Path /proc/cpuinfo -Pattern '^model name\s*:\s*(.+)$' | Select-Object -First 1
    $battery = Get-ChildItem -Path /sys/class/power_supply -Filter 'BAT*' -ErrorAction SilentlyContinue | Select-Object -First 1
    $loggedOn = @(who 2>$null) | Select-Object -First 1

    [PSCustomObject]@{
        AgentVersion    = $AgentVersion
        Platform        = 'linux'
        Type            = Get-LinuxDeviceType -HasBattery ([bool]$battery)
        Virtualization  = Get-LinuxVirtualization
        Hostname        = [System.Net.Dns]::GetHostName()
        User            = [Environment]::UserName
        os              = $osRelease['PRETTY_NAME']
        uptime          = [int][double]((Get-Content -Path /proc/uptime -Raw).Split(' ')[0])
        last_logon_user = if ($loggedOn) { ($loggedOn -split '\s+')[0] } else { $null }
        Processor       = if ($cpu) { $cpu.Matches[0].Groups[1].Value.Trim() } else { $null }
        Cores           = [Environment]::ProcessorCount
        Battery         = if ($battery) { [int](Get-Content -Path "$($battery.FullName)/capacity") } else { $null }
        PluggedIn       = (Get-PowerStatus).plugged
        RestartRequired = Test-Path -Path /var/run/reboot-required
        Drives          = @(Get-LinuxDrives)
        Networks        = @(Get-LinuxNetworks)
    }
}

function Get-LinuxDeviceType {
    param (
        [bool]
        $HasBattery
    )

    # SMBIOS chassis types: 8-10, 14, 30-32 portable; 17, 23, 28, 29 server / rack / blade.
    $chassis = [int](Get-Content -Path /sys/class/dmi/id/chassis_type -ErrorAction SilentlyContinue)
    if ($HasBattery -or $chassis -in 8, 9, 10, 14, 30, 31, 32) {
        return 'laptop'
    }
    if ($chassis -in 17, 23, 28, 29) {
        return 'server'
    }

    # No desktop environment installed: a server (also covers VMs, which report "Other").
    $sessions = @('/usr/share/xsessions', '/usr/share/wayland-sessions') | Where-Object { (Get-ChildItem -Path $_ -ErrorAction SilentlyContinue | Measure-Object).Count -gt 0 }
    if (-not $sessions) {
        return 'server'
    }

    return 'desktop'
}

function Get-LinuxDrives {
    # Real block devices only (no tmpfs, overlays, snaps ...).
    df -B1 --output=source,size,avail,target -x tmpfs -x devtmpfs -x squashfs -x overlay -x efivarfs 2>$null | Select-Object -Skip 1 | ForEach-Object {
        $parts = $_.Trim() -split '\s+', 4
        if ($parts.Count -eq 4 -and $parts[0].StartsWith('/dev/')) {
            [PSCustomObject]@{
                DriveLetter   = $parts[3]
                FriendlyName  = $parts[0].Substring(5)
                Size          = [long]$parts[1]
                SizeRemaining = [long]$parts[2]
                DriveType     = 3
            }
        }
    }
}

function Get-LinuxNetworks {
    $interfaces = ip -j addr show 2>$null | ConvertFrom-Json
    foreach ($interface in $interfaces) {
        if ($interface.ifname -eq 'lo' -or $interface.ifname -like 'veth*' -or $interface.operstate -eq 'DOWN') {
            continue
        }
        [PSCustomObject]@{
            Name        = $interface.ifname
            Status      = if ($interface.operstate -eq 'UP') { 'Up' } else { $interface.operstate }
            IPAddresses = @($interface.addr_info | ForEach-Object { $_.local })
        }
    }
}

function Get-FlatpakUpdates {
    # System installation plus every user's own (~/.local/share/flatpak), checked as that user.
    if (-not (Get-Command -Name flatpak -CommandType Application -ErrorAction SilentlyContinue)) {
        return
    }

    $installations = @(@{ User = $null; Arguments = @('--system') })
    foreach ($userHome in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue)) {
        if (Test-Path -Path "$($userHome.FullName)/.local/share/flatpak") {
            $installations += @{ User = $userHome.Name; Arguments = @('--user') }
        }
    }

    foreach ($installation in $installations) {
        $run = {
            param ([string[]]$FlatpakArguments)
            if ($installation.User) { runuser -u $installation.User -- flatpak @FlatpakArguments 2>$null } else { flatpak @FlatpakArguments 2>$null }
        }
        $installed = @{}
        foreach ($line in @(& $run (@('list', '--app') + $installation.Arguments + '--columns=application,version'))) {
            $parts = "$line" -split "`t"
            if ($parts[0]) { $installed[$parts[0]] = $parts[1] }
        }
        foreach ($line in @(& $run (@('remote-ls', '--updates', '--app') + $installation.Arguments + '--columns=application,version'))) {
            $parts = "$line" -split "`t"
            if (-not $parts[0] -or $parts[0] -eq 'Application ID') { continue }
            [PSCustomObject]@{
                Id        = $parts[0]
                Version   = $installed[$parts[0]]
                Avaliable = $parts[1]
                Source    = if ($installation.User) { "flatpak ($($installation.User))" } else { 'flatpak' }
            }
        }
    }
}

function Get-SnapUpdates {
    if (-not (Get-Command -Name snap -CommandType Application -ErrorAction SilentlyContinue)) {
        return
    }

    $installed = @{}
    foreach ($line in @(snap list 2>$null | Select-Object -Skip 1)) {
        $parts = "$line".Trim() -split '\s+'
        if ($parts.Count -ge 2) { $installed[$parts[0]] = $parts[1] }
    }
    # Name  Version  Rev  Size  Publisher  Notes
    foreach ($line in @(snap refresh --list 2>$null | Select-Object -Skip 1)) {
        $parts = "$line".Trim() -split '\s+'
        if ($parts.Count -ge 2) {
            [PSCustomObject]@{ Id = $parts[0]; Version = $installed[$parts[0]]; Avaliable = $parts[1]; Source = 'snap' }
        }
    }
}

function Get-PowerShellReleaseUpdate {
    param (
        [bool]
        $OnLinux,
        # Updates already listed by a package manager, PowerShell is not reported twice.
        $Known
    )

    $pwsh = @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { $_.Edition -eq 'PowerShell 7' }) | Select-Object -First 1
    if (-not $pwsh -or @($Known | Where-Object { "$($_.Id) $($_.Title)" -match '(^|\s)(powershell|Microsoft\.PowerShell)(\s|$)' })) {
        return
    }

    # The release pwsh itself checks for its update notification.
    $installed = (& $pwsh.Path -NoProfile -NonInteractive -Command '$PSVersionTable.PSVersion.ToString()' 2>$null | Select-Object -First 1)
    $latest = (Invoke-RestMethod -Uri 'https://aka.ms/pwsh-buildinfo-stable' -TimeoutSec 30).ReleaseTag -replace '^v', ''
    try {
        if ($installed -and $latest -and [version]($latest -split '-')[0] -gt [version](("$installed" -split '-')[0])) {
            [PSCustomObject]@{ Id = 'PowerShell'; Version = "$installed"; Avaliable = $latest; Source = 'github.com/PowerShell' }
        }
    }
    catch { }
}

function Get-AptUpdates {
    # Reads the local apt cache only, no network access. "apt list --upgradable" also lists
    # packages apt will not install now, so a simulated upgrade (as Install updates runs it)
    # tells which ones are installable, deferred by phasing or held back.
    $simulation = @(apt-get -s -q -o Debug::NoLocking=1 --with-new-pkgs upgrade 2>$null)
    $installable = @{}
    $phased = @{}
    $section = $null
    foreach ($line in $simulation) {
        if ($line -match '^Inst (\S+)') {
            $installable[$Matches[1]] = $true
        } elseif ($line -match '^\S.*:\s*$') {
            # A list header, e.g. "The following upgrades have been deferred due to phasing:" (apt 2)
            # or "Not upgrading yet due to phasing:" (apt 3).
            $section = if ($line -match 'phasing') { 'phased' } else { 'other' }
        } elseif ($section -eq 'phased' -and $line -match '^\s+\S') {
            foreach ($name in ($line.Trim() -split '\s+')) { $phased[$name] = $true }
        } elseif ($line -notmatch '^\s') {
            $section = $null
        }
    }

    apt list --upgradable 2>$null | Where-Object { $_ -match '^(\S+?)/\S+\s+(\S+)' } | ForEach-Object {
        $name = $Matches[1]
        [PSCustomObject]@{
            Title          = "$name $($Matches[2])"
            Status         = if ($installable[$name]) { 'installable' } elseif ($phased[$name]) { 'phased' } else { 'held' }
            IsDownloaded   = $false
            RebootRequired = $false
        }
    }
}

#endregion

#region Agent

function Write-AgentLog {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Message
    )

    $LogPath = "$AgentDir/agent.log"
    if ((Test-Path -Path $LogPath) -and (Get-Item -Path $LogPath).Length -gt 1MB) {
        Move-Item -Path $LogPath -Destination "$LogPath.1" -Force
    }
    "{0:yyyy-MM-dd HH:mm:ss} {1}" -f (Get-Date), $Message | Add-Content -Path $LogPath -Encoding UTF8
}

function Invoke-MdmApi {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path,
        [string]
        $Method = 'Get',
        $Body,
        [string]
        $Token
    )

    $params = @{
        Method = $Method
        Uri    = "$($ServerUrl.TrimEnd('/'))/api/$Path"
        Headers = @{ 'Accept' = 'application/json' }
    }
    if ($Token) {
        $params.Headers['Authorization'] = "Bearer $Token"
    }
    if ($null -ne $Body) {
        $params.Body = [System.Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 6 -Compress))
        $params.ContentType = 'application/json; charset=utf-8'
    }

    return Invoke-RestMethod @params
}

function Register-MDMDevice {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $EnrolmentCode
    )

    $response = Invoke-MdmApi -Method Post -Path 'device/register' -Body @{ 'enrolment_code' = $EnrolmentCode }
    if (-not $response.token) {
        throw "Enrolment failed: $response"
    }

    return $response.token
}

function Get-AgentToken {
    if ($OnLinux) {
        # SecureString export is Windows only (DPAPI); keep the token in a root-only file.
        $tokenPath = "$AgentDir/token"
        if (-not (Test-Path -Path $tokenPath)) {
            if (-not $EnrolmentCode) {
                $script:EnrolmentCode = Read-Host -Prompt 'Enrolment code'
            }
            Set-Content -Path $tokenPath -Value (Register-MDMDevice -EnrolmentCode $EnrolmentCode) -NoNewline
            chmod 600 $tokenPath
        }
        return (Get-Content -Path $tokenPath -Raw).Trim()
    }

    $AuthFilePath = "$AgentDir/Token.xml"
    if (-not (Test-Path -Path $AuthFilePath)) {
        if (-not $EnrolmentCode) {
            $script:EnrolmentCode = Read-Host -Prompt 'Enrolment code'
        }
        @{
            "token" = ((Register-MDMDevice -EnrolmentCode $EnrolmentCode) | ConvertTo-SecureString -AsPlainText -Force)
        } | Export-Clixml -Path $AuthFilePath
    }

    $Auth = Import-Clixml -Path $AuthFilePath
    return [System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($Auth.token))
}

function Get-AgentArguments {
    # Agent options persisted by -Install.
    $arguments = '-ServerUrl "{0}" -ReportInterval {1} -HeartbeatInterval {2} -InventoryInterval {3} -HealthInterval {4}' -f $ServerUrl, $ReportInterval, $HeartbeatInterval, $InventoryInterval, $HealthInterval
    if ($ReverbHost) { $arguments += ' -ReverbHost "{0}"' -f $ReverbHost }
    if ($ReverbPort) { $arguments += ' -ReverbPort {0}' -f $ReverbPort }
    if ($ReverbScheme) { $arguments += ' -ReverbScheme {0}' -f $ReverbScheme }
    if ($ReverbKey) { $arguments += ' -ReverbKey "{0}"' -f $ReverbKey }
    if ($NoRealtime) { $arguments += ' -NoRealtime' }
    return $arguments
}

function Remove-TemporaryInstaller {
    # The install command downloads the agent to the temp directory; the installed copy lives elsewhere.
    if ($PSCommandPath.StartsWith([System.IO.Path]::GetTempPath())) {
        Remove-Item -Path $PSCommandPath -Force -ErrorAction SilentlyContinue
    }
}

function Test-AgentAdmin {
    if ($OnLinux) {
        return (id -u) -eq '0'
    }

    $principal = New-Object System.Security.Principal.WindowsPrincipal([System.Security.Principal.WindowsIdentity]::GetCurrent())
    return $principal.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Register-AgentService {
    # Linux: systemd service running as root, restarted when it exits, low CPU and I/O priority.
    $pwsh = (Get-Process -Id $PID).Path
    $unit = @"
[Unit]
Description=Laravel-MDM agent
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=$pwsh -NoLogo -NoProfile -NonInteractive -File "$AgentDir/app.ps1" $(Get-AgentArguments)
WorkingDirectory=$AgentDir
Restart=always
RestartSec=10
Nice=10
IOSchedulingClass=idle

[Install]
WantedBy=multi-user.target
"@
    Set-Content -Path /etc/systemd/system/laravel-mdm-agent.service -Value $unit
    systemctl daemon-reload
    systemctl enable --now laravel-mdm-agent.service
    systemctl restart laravel-mdm-agent.service
}

function Register-AgentTask {
    # Starts the agent at boot; the repetition acts as a watchdog, a running instance is not started twice.
    $Trigger1 = New-ScheduledTaskTrigger -AtStartup
    $Trigger2 = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15)
    $Settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $arguments = '-WindowStyle Hidden -ExecutionPolicy Bypass -NoLogo -File "{0}\app.ps1" {1}' -f $AgentDir, (Get-AgentArguments)
    $Action = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument $arguments

    # A running agent (update) would keep the old version; the task never starts a second instance.
    Stop-ScheduledTask -TaskName "Laravel-MDM-Agent" -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName "Laravel-MDM-Agent" -Trigger @($Trigger1, $Trigger2) -Settings $Settings -User "NT AUTHORITY\SYSTEM" -Action $Action -RunLevel Highest -Force | Out-Null
    Start-ScheduledTask -TaskName "Laravel-MDM-Agent"
}

function Start-InventoryCollection {
    # Windows Update search and winget are expensive, run them rarely in a separate idle-priority process.
    $init = [scriptblock]::Create(@"
    function Get-WingetSoftware {${function:Get-WingetSoftware}}
    function Get-WindowsUpdate {${function:Get-WindowsUpdate}}
    function Get-AptUpdates {${function:Get-AptUpdates}}
    function Get-FlatpakUpdates {${function:Get-FlatpakUpdates}}
    function Get-SnapUpdates {${function:Get-SnapUpdates}}
    function Get-PowerShellReleaseUpdate {${function:Get-PowerShellReleaseUpdate}}
    function ConvertFrom-WingetTable {${function:ConvertFrom-WingetTable}}
    function Get-WingetPath {${function:Get-WingetPath}}
    function Get-PowerShellHosts {${function:Get-PowerShellHosts}}
    function Invoke-PowerShellModules {${function:Invoke-PowerShellModules}}
"@)

    return Start-Job -Name 'inventory' -InitializationScript $init -ArgumentList $OnLinux -ScriptBlock {
        param ($OnLinux)
        try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle } catch { }
        $data = @{}
        try { $data['module_updates'] = @(Invoke-PowerShellModules -OnLinux $OnLinux) } catch { }
        $packages = @()
        if ($OnLinux) {
            try { $data['os_updates'] = @(Get-AptUpdates) } catch { }
            try { $packages += @(Get-FlatpakUpdates) } catch { }
            try { $packages += @(Get-SnapUpdates) } catch { }
        } else {
            try { $data['os_updates'] = @(Get-WindowsUpdate) } catch { }
            try { $packages += @(Get-WingetSoftware -Updatable | Select-Object -Property Id, Version, Avaliable, Source) } catch { }
        }
        try { $packages += @(Get-PowerShellReleaseUpdate -OnLinux $OnLinux -Known (@($data['os_updates']) + $packages)) } catch { }
        $data['packages_updates'] = $packages
        return $data
    }
}

function Start-HealthCollection {
    param (
        $Previous
    )

    # smartctl / storage reliability counters talk to every disk, run them rarely and at idle priority.
    $init = [scriptblock]::Create(@"
    function Get-DiskHealth {${function:Get-DiskHealth}}
    function Get-LinuxDiskHealth {${function:Get-LinuxDiskHealth}}
"@)

    return Start-Job -Name 'health' -InitializationScript $init -ArgumentList $OnLinux, $Previous -ScriptBlock {
        param ($OnLinux, $Previous)
        try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle } catch { }
        try {
            return Get-DiskHealth -Previous $Previous
        }
        catch {
            return @{ error = $_.Exception.Message }
        }
    }
}

function Get-CachedInventory {
    param (
        [string]
        $Name = 'inventory'
    )

    $path = "$AgentDir/$Name.json"
    if (-not (Test-Path -Path $path)) {
        return $null
    }

    try {
        $cache = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
        # Collected by another agent version (update): still reported until the new one is ready (the
        # portal would lose its update list meanwhile), but collected again right away.
        return @{ CollectedAt = [DateTime]$cache.collected_at; Data = $cache.data; Stale = $cache.agent_version -ne $AgentVersion }
    }
    catch {
        return $null
    }
}

function Save-CachedInventory {
    param (
        [Parameter(Mandatory = $true)]
        $Data,
        [string]
        $Name = 'inventory'
    )

    @{ collected_at = (Get-Date).ToString('o'); agent_version = $AgentVersion; data = $Data } | ConvertTo-Json -Depth 6 -Compress | Set-Content -Path "$AgentDir/$Name.json" -Encoding UTF8
}

function Get-Report {
    param (
        $Inventory,
        $Health
    )

    $data = @{ machine = if ($OnLinux) { Get-LinuxMachineInfo } else { Get-MachineInfo } }
    if ($Inventory) {
        $data['os_updates'] = $Inventory.os_updates
        $data['packages_updates'] = $Inventory.packages_updates
        $data['module_updates'] = $Inventory.module_updates
    }
    if ($Health) {
        $data['disk_health'] = $Health
    }
    try { $data['services'] = @(Get-AgentServices) } catch { Write-AgentLog "Services failed: $($_.Exception.Message)" }
    try {
        $docker = Get-DockerContainers
        if ($docker) { $data['docker'] = $docker }
    }
    catch {
        Write-AgentLog "Docker failed: $($_.Exception.Message)"
    }

    return $data
}

function Send-Report {
    param (
        [Parameter(Mandatory = $true)]
        $Data,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $response = Invoke-MdmApi -Method Post -Path 'device' -Body $Data -Token $Token
    # Commands returned here were not delivered over the WebSocket (the server clears them now).
    foreach ($command in @($response.commands)) {
        if ($command) {
            Invoke-DeviceCommand -Command $command
        }
    }
}

function Update-Agent {
    # Replace the installed script with the version the server serves, then restart.
    # The command carries no data: the source is always the -ServerUrl set at install time.
    $server = [Uri]$ServerUrl
    if ($server.Scheme -ne 'https' -and -not $server.IsLoopback) {
        Write-AgentLog 'Agent update refused: the server is not reached over HTTPS'
        return
    }

    $download = Join-Path ([System.IO.Path]::GetTempPath()) "mdm-agent-update-$(Get-Random).ps1"
    try {
        Invoke-WebRequest -UseBasicParsing -Uri "$($ServerUrl.TrimEnd('/'))/agent/app.ps1" -OutFile $download

        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseFile($download, [ref]$null, [ref]$errors) | Out-Null
        if ($errors -or -not (Select-String -Path $download -Pattern 'function Start-Agent' -Quiet)) {
            throw 'the downloaded agent is not valid'
        }

        # Only move forward, an older agent is never installed this way.
        $version = (Select-String -Path $download -Pattern "^\`$AgentVersion = '([^']+)'" | Select-Object -First 1).Matches.Groups[1].Value
        if (-not $version -or [version]$version -le [version]$AgentVersion) {
            throw "the server offers version '$version', not newer than $AgentVersion"
        }

        Copy-Item -Path $download -Destination (Join-Path $AgentDir 'app.ps1') -Force
    }
    catch {
        Write-AgentLog "Agent update failed: $($_.Exception.Message)"
        return
    }
    finally {
        Remove-Item -Path $download -Force -ErrorAction SilentlyContinue
    }

    Write-AgentLog 'Agent updated, restarting'
    if (-not $OnLinux) {
        # The scheduled task does not start a second instance, start it again once this one has exited.
        Start-Process -FilePath powershell.exe -WindowStyle Hidden -ArgumentList '-NoProfile -Command "Start-Sleep -Seconds 5; Start-ScheduledTask -TaskName Laravel-MDM-Agent"'
    }
    # systemd (Restart=always) starts the new version on Linux.
    exit 0
}

function Start-UpdateJob {
    # Installs OS, package and module updates in the background. Every step is logged to
    # agent.log when the job ends (see Complete-UpdateJob), the full tool output to updates.log.
    if ($script:UpdateJob -and $script:UpdateJob.State -eq 'Running') {
        Write-AgentLog 'Updates are already being installed, command ignored'
        return
    }

    $init = [scriptblock]::Create(@"
    function Install-WindowsUpdate {${function:Install-WindowsUpdate}}
    function Get-WingetPath {${function:Get-WingetPath}}
    function Get-PowerShellHosts {${function:Get-PowerShellHosts}}
    function Invoke-PowerShellModules {${function:Invoke-PowerShellModules}}
"@)

    $script:UpdateJobStarted = Get-Date
    $script:UpdateJob = Start-Job -Name 'updates' -InitializationScript $init -ArgumentList $OnLinux, "$AgentDir/updates.log" -ScriptBlock {
        param ($OnLinux, $OutputLog)

        if ((Test-Path -Path $OutputLog) -and (Get-Item -Path $OutputLog).Length -gt 2MB) {
            Move-Item -Path $OutputLog -Destination "$OutputLog.1" -Force
        }
        function Invoke-Logged ([string]$Title, [scriptblock]$Command) {
            # Runs a native command, keeps its whole output in updates.log and returns it.
            "===== {0:yyyy-MM-dd HH:mm:ss} $Title" -f (Get-Date) | Add-Content -Path $OutputLog -Encoding UTF8
            $output = @(& $Command 2>&1 | ForEach-Object { "$_" })
            $output | Add-Content -Path $OutputLog -Encoding UTF8
            "exit code $LASTEXITCODE" | Add-Content -Path $OutputLog -Encoding UTF8
            return , $output
        }
        function Get-Tail ($Lines) { (@($Lines | Where-Object { $_.Trim() }) | Select-Object -Last 3) -join ' | ' }

        if ($OnLinux) {
            $env:DEBIAN_FRONTEND = 'noninteractive'
            # Wait for a running apt / unattended-upgrades instead of failing on its lock.
            $lock = '-o', 'DPkg::Lock::Timeout=600'
            $output = Invoke-Logged 'apt-get update' { apt-get @lock -q update }
            "apt-get update: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"

            # --with-new-pkgs installs new dependencies (plain upgrade keeps such packages back),
            # nothing is removed; existing configuration files are kept.
            $output = Invoke-Logged 'apt-get upgrade' { apt-get @lock -y -q --with-new-pkgs -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade }
            $code = $LASTEXITCODE
            $summary = $output | Where-Object { $_ -match '\d+ upgraded, \d+ newly installed' } | Select-Object -Last 1
            "apt-get upgrade: exit $code$(if ($summary) { ', ' + $summary.Trim() })$(if ($code) { ': ' + (Get-Tail $output) })"
            $keptBack = $false
            $held = foreach ($line in $output) {
                # apt 2: "... kept back:" / "... deferred due to phasing:", apt 3: "Not upgrading ...:"
                if ($line -match '(kept back|phasing|Not upgrading).*:\s*$') { $keptBack = $true; continue }
                if ($keptBack -and $line -match '^\s+\S') { $line.Trim() } elseif ($keptBack) { $keptBack = $false }
            }
            if ($held) { "apt-get upgrade: kept back (phased or held): $($held -join ' ')" }
            if (Get-Command -Name flatpak -CommandType Application -ErrorAction SilentlyContinue) {
                $output = Invoke-Logged 'flatpak update' { flatpak update --system -y --noninteractive }
                "flatpak update (system): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                foreach ($userHome in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue | Where-Object { Test-Path -Path "$($_.FullName)/.local/share/flatpak" })) {
                    $user = $userHome.Name
                    $output = Invoke-Logged "flatpak update ($user)" { runuser -u $user -- flatpak update --user -y --noninteractive }
                    "flatpak update ($user): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                }
            }
            if (Get-Command -Name snap -CommandType Application -ErrorAction SilentlyContinue) {
                $output = Invoke-Logged 'snap refresh' { snap refresh }
                "snap refresh: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
            }
            if (Test-Path -Path /var/run/reboot-required) { 'Restart required' }
        } else {
            $winget = Get-WingetPath
            if ($winget) {
                # winget also updates PowerShell 7 (Microsoft.PowerShell).
                $output = Invoke-Logged 'winget upgrade --all' { & $winget upgrade --all --silent --accept-source-agreements --accept-package-agreements --disable-interactivity }
                "winget upgrade: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
            } else {
                'winget: not found, application updates skipped'
            }
            try { Install-WindowsUpdate } catch { "Windows Update failed: $($_.Exception.Message)" }
        }

        try {
            $failed = @(Invoke-PowerShellModules -OnLinux $OnLinux -Update)
            if ($failed) { "PowerShell modules not updated: $(@($failed | ForEach-Object { "$($_.Name) ($($_.Edition))" }) -join ', ')" } else { 'PowerShell modules: up to date' }
        }
        catch {
            "PowerShell modules failed: $($_.Exception.Message)"
        }
    }
    Write-AgentLog 'Installing updates started (details in updates.log)'
}

function Complete-UpdateJob {
    # Logs the finished update job; returns $true when it finished, so the inventory is refreshed.
    $job = $script:UpdateJob
    if (-not $job -or $job.State -eq 'Running') {
        return $false
    }

    foreach ($line in @(Receive-Job -Job $job -ErrorAction SilentlyContinue 2>&1)) {
        Write-AgentLog "Updates: $line"
    }
    if ($job.State -ne 'Completed') {
        Write-AgentLog "Updates: job $($job.State): $($job.ChildJobs[0].JobStateInfo.Reason)"
    }
    Write-AgentLog ('Installing updates finished after {0:n0} min' -f ((Get-Date) - $script:UpdateJobStarted).TotalMinutes)
    Remove-Job -Job $job -Force
    $script:UpdateJob = $null

    return $true
}

function Invoke-DeviceCommand {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Command
    )

    if ($AllowedCommands -notcontains $Command) {
        Write-AgentLog "Ignoring unknown command '$Command'"
        return
    }

    Write-AgentLog "Executing command '$Command'"
    if ($Command -eq 'updateAgent') {
        Update-Agent
        return
    }

    if ($OnLinux) {
        switch ($Command) {
            'turnOff' { systemctl poweroff }
            'restart' { systemctl reboot }
            'doUpdates' { Start-UpdateJob }
        }
        return
    }

    switch ($Command) {
        'turnOff' { Stop-Computer -Force }
        'restart' { Restart-Computer -Force }
        'doUpdates' { Start-UpdateJob }
    }
}

function Send-WsMessage {
    param (
        [Parameter(Mandatory = $true)]
        [System.Net.WebSockets.ClientWebSocket]
        $Socket,
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Message
    )

    $bytes = [System.Text.Encoding]::UTF8.GetBytes(($Message | ConvertTo-Json -Depth 5 -Compress))
    $segment = New-Object 'System.ArraySegment[byte]' -ArgumentList (, $bytes)
    $Socket.SendAsync($segment, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, [System.Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
}

function Start-Realtime {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    if ($NoRealtime) {
        return $null
    }

    $config = Invoke-MdmApi -Path 'device/realtime' -Token $Token
    if (-not $config.enabled) {
        return $null
    }

    # Local overrides for installations where the WebSocket is reachable on a different address.
    if ($ReverbHost) { $config.host = $ReverbHost }
    if ($ReverbKey) { $config.key = $ReverbKey }

    # The scheme follows -ServerUrl unless given explicitly (an https portal means wss).
    $scheme = if ($ReverbScheme) { $ReverbScheme } else { ([Uri]$ServerUrl).Scheme }
    if ($ReverbPort) {
        $config.port = $ReverbPort
    } elseif ($scheme -ne $config.scheme) {
        $config.port = if ($scheme -eq 'https') { 443 } else { 80 }
    }
    $config.scheme = $scheme

    $scheme = if ($config.scheme -eq 'https') { 'wss' } else { 'ws' }
    $uri = "{0}://{1}:{2}{3}/app/{4}?protocol=7&client=laravel-mdm-agent&version=1.0&flash=false" -f $scheme, $config.host, $config.port, $config.path, $config.key

    $socket = New-Object System.Net.WebSockets.ClientWebSocket
    $socket.Options.KeepAliveInterval = [TimeSpan]::FromSeconds(30)
    $socket.ConnectAsync([Uri]$uri, [System.Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
    Write-AgentLog "WebSocket connected to $uri"

    return @{ Socket = $socket; Config = $config; Subscribed = $false }
}

function Receive-WsMessage {
    # Waits up to $TimeoutMs for a complete text message; returns $null on timeout.
    param (
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Realtime,
        [int]
        $TimeoutMs = 1000
    )

    if (-not $Realtime.Pending) {
        $Realtime.Buffer = New-Object byte[] 8192
        $Realtime.Pending = $Realtime.Socket.ReceiveAsync((New-Object 'System.ArraySegment[byte]' -ArgumentList (, $Realtime.Buffer)), [System.Threading.CancellationToken]::None)
    }

    if (-not $Realtime.Pending.Wait($TimeoutMs)) {
        return $null
    }

    $result = $Realtime.Pending.Result
    $Realtime.Pending = $null

    if ($result.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) {
        throw "WebSocket closed by server: $($result.CloseStatusDescription)"
    }

    if (-not $Realtime.Message) {
        $Realtime.Message = New-Object System.IO.MemoryStream
    }
    $Realtime.Message.Write($Realtime.Buffer, 0, $result.Count)

    if (-not $result.EndOfMessage) {
        return $null
    }

    $text = [System.Text.Encoding]::UTF8.GetString($Realtime.Message.ToArray())
    $Realtime.Message = $null

    return $text | ConvertFrom-Json
}

function Invoke-RealtimeMessage {
    param (
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Realtime,
        [Parameter(Mandatory = $true)]
        $Message,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $data = $null
    if ($Message.data -is [string] -and $Message.data.Length -gt 0) {
        $data = $Message.data | ConvertFrom-Json
    }

    switch ($Message.event) {
        'pusher:connection_established' {
            $auth = Invoke-MdmApi -Method Post -Path 'broadcasting/auth' -Token $Token -Body @{
                socket_id    = $data.socket_id
                channel_name = $Realtime.Config.channel
            }
            Send-WsMessage -Socket $Realtime.Socket -Message @{
                event = 'pusher:subscribe'
                data  = @{ auth = $auth.auth; channel = $Realtime.Config.channel }
            }
        }
        'pusher_internal:subscription_succeeded' {
            $Realtime.Subscribed = $true
            Write-AgentLog "Subscribed to $($Message.channel)"
        }
        'pusher:ping' {
            Send-WsMessage -Socket $Realtime.Socket -Message @{ event = 'pusher:pong'; data = @{} }
        }
        'pusher:error' {
            throw "WebSocket error: $($Message.data)"
        }
        'command' {
            if ($Message.channel -eq $Realtime.Config.channel -and $data.command) {
                # Acknowledge first, so the command is not delivered again with the next report.
                Invoke-MdmApi -Method Post -Path 'device/commands/ack' -Token $Token -Body @{ command = $data.command } | Out-Null
                Invoke-DeviceCommand -Command $data.command
            }
        }
    }
}

function Initialize-SystemMetrics {
    # Plain Win32 calls: negligible cost and independent of the system language (unlike performance counters).
    if (-not ('MdmAgent.SystemMetrics' -as [type])) {
        Add-Type -Namespace MdmAgent -Name SystemMetrics -MemberDefinition @'
[DllImport("kernel32.dll", SetLastError = true)]
public static extern bool GetSystemTimes(out long idleTime, out long kernelTime, out long userTime);

[StructLayout(LayoutKind.Sequential)]
public struct SystemPowerStatus {
    public byte ACLineStatus;
    public byte BatteryFlag;
    public byte BatteryLifePercent;
    public byte SystemStatusFlag;
    public int BatteryLifeTime;
    public int BatteryFullLifeTime;
}

[DllImport("kernel32.dll")]
public static extern bool GetSystemPowerStatus(out SystemPowerStatus status);

[StructLayout(LayoutKind.Sequential)]
public class MemoryStatusEx {
    public uint dwLength = (uint)Marshal.SizeOf(typeof(MemoryStatusEx));
    public uint dwMemoryLoad;
    public ulong ullTotalPhys;
    public ulong ullAvailPhys;
    public ulong ullTotalPageFile;
    public ulong ullAvailPageFile;
    public ulong ullTotalVirtual;
    public ulong ullAvailVirtual;
    public ulong ullAvailExtendedVirtual;
}

[DllImport("kernel32.dll", SetLastError = true)]
public static extern bool GlobalMemoryStatusEx([In, Out] MemoryStatusEx buffer);
'@
    }
}

function Get-CpuMemoryCounters {
    # Cumulative CPU times and current memory, read with negligible cost.
    if ($OnLinux) {
        # Direct file reads, a pipeline would cost ~10x more.
        $fields = [System.IO.File]::ReadLines('/proc/stat') | Select-Object -First 1
        $cpu = [long[]]($fields.Split(' ', [System.StringSplitOptions]::RemoveEmptyEntries)[1..8])
        $memory = @{}
        foreach ($line in [System.IO.File]::ReadAllLines('/proc/meminfo')[0..4]) {
            $parts = $line.Split(' ', [System.StringSplitOptions]::RemoveEmptyEntries)
            $memory[$parts[0].TrimEnd(':')] = [long]$parts[1] * 1024
        }
        # user nice system idle iowait irq softirq steal
        return @{
            Idle        = $cpu[3] + $cpu[4]
            Total       = $cpu[0] + $cpu[1] + $cpu[2] + $cpu[3] + $cpu[4] + $cpu[5] + $cpu[6] + $cpu[7]
            MemoryUsed  = $memory['MemTotal'] - $memory['MemAvailable']
            MemoryTotal = $memory['MemTotal']
        }
    }

    Initialize-SystemMetrics
    $idle = $kernel = $user = [long]0
    [MdmAgent.SystemMetrics]::GetSystemTimes([ref]$idle, [ref]$kernel, [ref]$user) | Out-Null
    $memory = New-Object MdmAgent.SystemMetrics+MemoryStatusEx
    [MdmAgent.SystemMetrics]::GlobalMemoryStatusEx($memory) | Out-Null

    # Kernel time includes idle time.
    return @{
        Idle        = $idle
        Total       = $kernel + $user
        MemoryUsed  = $memory.ullTotalPhys - $memory.ullAvailPhys
        MemoryTotal = $memory.ullTotalPhys
    }
}

function Get-SystemMetrics {
    # CPU usage is the average since the previous call (i.e. over the whole heartbeat interval), so nothing is sampled in between.
    try {
        $counters = Get-CpuMemoryCounters

        $previous = $script:CpuTimes
        $script:CpuTimes = @{ Idle = $counters.Idle; Total = $counters.Total }
        if (-not $previous) {
            return $null
        }

        $total = $counters.Total - $previous.Total
        $cpu = if ($total -gt 0) { [math]::Round((1 - ($counters.Idle - $previous.Idle) / $total) * 100, 1) } else { 0 }

        return @{
            cpu          = [math]::Max(0, $cpu)
            memory_used  = $counters.MemoryUsed
            memory_total = $counters.MemoryTotal
        }
    }
    catch {
        return $null
    }
}

function Get-PowerStatus {
    param (
        [string]
        $PowerSupplyPath = '/sys/class/power_supply'
    )

    # Battery level and whether the device runs on mains power; null without a battery.
    if ($OnLinux) {
        $battery = Get-ChildItem -Path $PowerSupplyPath -Filter 'BAT*' -ErrorAction SilentlyContinue | Select-Object -First 1
        if (-not $battery) {
            return $null
        }
        $mains = @(Get-ChildItem -Path $PowerSupplyPath -ErrorAction SilentlyContinue | Where-Object {
                (Get-Content -Path "$($_.FullName)/type" -ErrorAction SilentlyContinue) -eq 'Mains'
            })
        $plugged = if ($mains) {
            [bool]($mains | Where-Object { (Get-Content -Path "$($_.FullName)/online" -ErrorAction SilentlyContinue) -eq '1' })
        } else {
            (Get-Content -Path "$($battery.FullName)/status" -ErrorAction SilentlyContinue) -ne 'Discharging'
        }
        $capacity = Get-Content -Path "$($battery.FullName)/capacity" -ErrorAction SilentlyContinue
        return [ordered]@{ battery = if ($capacity -match '^\d+$') { [int]$capacity } else { $null }; plugged = $plugged }
    }

    Initialize-SystemMetrics
    $status = New-Object MdmAgent.SystemMetrics+SystemPowerStatus
    # BatteryFlag 128 = no system battery, 255 = unknown.
    if (-not [MdmAgent.SystemMetrics]::GetSystemPowerStatus([ref]$status) -or $status.BatteryFlag -eq 128 -or $status.BatteryFlag -eq 255) {
        return $null
    }

    return [ordered]@{
        battery = if ($status.BatteryLifePercent -le 100) { [int]$status.BatteryLifePercent } else { $null }
        plugged = $status.ACLineStatus -eq 1
    }
}

function Get-LiveState {
    # Small, fast-changing state sent with the heartbeat (the full details go with the report):
    # restart pending, service and container states. Kept cheap, it runs every heartbeat.
    $services = [ordered]@{}
    if ($OnLinux) {
        foreach ($service in @(Get-AgentServices | Sort-Object -Property Name)) { $services[$service.Name] = $service.State }
    } else {
        Add-Type -AssemblyName System.ServiceProcess -ErrorAction SilentlyContinue
        # Start types (one service manager query each) change rarely: cached, only needed for stopped services.
        if (-not $script:StartTypes -or $script:StartTypesAt -lt (Get-Date).AddHours(-1)) {
            $script:StartTypes = @{}
            $script:StartTypesAt = Get-Date
        }
        foreach ($service in @([System.ServiceProcess.ServiceController]::GetServices() | Sort-Object -Property ServiceName)) {
            if ($service.Status -eq 'Running') {
                $services[$service.ServiceName] = 'running'
            } else {
                if (-not $script:StartTypes.ContainsKey($service.ServiceName)) {
                    $script:StartTypes[$service.ServiceName] = "$($service.StartType)"
                }
                if ($script:StartTypes[$service.ServiceName] -eq 'Automatic') { $services[$service.ServiceName] = 'stopped' }
            }
            $service.Dispose()
        }
    }

    $state = [ordered]@{
        restart_required = if ($OnLinux) { Test-Path -Path /var/run/reboot-required } else { [bool](Test-PendingReboot) }
        services         = $services
    }
    $power = Get-PowerStatus
    if ($power) {
        $state['power'] = $power
    }

    if (Test-DockerInstalled) {
        $lines = @(docker ps --all --no-trunc --format '{{.Names}}|{{.State}}|{{.Status}}' 2>$null)
        if ($LASTEXITCODE -eq 0) {
            $containers = [ordered]@{}
            foreach ($line in ($lines | Sort-Object)) {
                $name, $containerState, $status = "$line" -split '\|', 3
                if (-not $name) { continue }
                $containers[$name] = if ($containerState -eq 'running' -and $status -like '*(unhealthy)*') { 'unhealthy' } else { $containerState }
            }
            $state['containers'] = $containers
        }
    }

    return $state
}

function Send-Heartbeat {
    param (
        $Realtime,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $metrics = Get-SystemMetrics

    # The live state is sent only when it changed; the server keeps the last one.
    $state = $null
    try {
        $current = Get-LiveState
        $stateJson = $current | ConvertTo-Json -Depth 4 -Compress
        if ($stateJson -ne $script:LastStateJson) { $state = $current }
    }
    catch {
        Write-AgentLog "Live state failed: $($_.Exception.Message)"
    }
    # Reverb limits messages to 10 kB: a large state (many services) goes over HTTPS instead.
    $stateOverWs = $state -and $stateJson.Length -le 8000

    if ($Realtime -and $Realtime.Subscribed) {
        $data = if ($metrics) { $metrics } else { @{} }
        if ($stateOverWs) { $data['state'] = $state }
        # A failed send throws: the connection is reset and the state is sent again later.
        Send-WsMessage -Socket $Realtime.Socket -Message @{ event = 'client-heartbeat'; channel = $Realtime.Config.channel; data = $data }
        if ($stateOverWs) {
            $script:LastStateJson = $stateJson
            return
        }
        $metrics = $null
    }
    if (-not $state -and $Realtime -and $Realtime.Subscribed) {
        return
    }

    try {
        Invoke-MdmApi -Method Post -Path 'device/heartbeat' -Token $Token -Body @{ metrics = $metrics; state = $state } | Out-Null
        if ($state) { $script:LastStateJson = $stateJson }
    }
    catch {
        Write-AgentLog "Heartbeat failed: $($_.Exception.Message)"
    }
}

function Start-Agent {
    # Keep the agent in the background, user applications take precedence.
    try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::BelowNormal } catch { }

    $Token = Get-AgentToken
    Write-AgentLog ('Agent {0} started (PowerShell {1}, {2}, server {3})' -f $AgentVersion, $PSVersionTable.PSVersion, $(if ($OnLinux) { 'Linux' } else { 'Windows' }), $ServerUrl)
    $inventory = Get-CachedInventory
    # First inventory a few minutes after start, so it does not add to the load during boot.
    $nextInventory = if ($inventory -and -not $inventory.Stale) { $inventory.CollectedAt.AddSeconds($InventoryInterval) } elseif ($inventory) { (Get-Date).AddMinutes(1) } else { (Get-Date).AddMinutes(5) }
    $inventoryJob = $null
    $health = Get-CachedInventory -Name 'health'
    $nextHealth = if ($health -and -not $health.Stale) { $health.CollectedAt.AddSeconds($HealthInterval) } else { (Get-Date).AddMinutes(2) }
    $healthJob = $null
    $lastReport = [DateTime]::MinValue
    $realtime = $null
    $nextConnect = Get-Date
    $lastActivity = Get-Date
    $lastHeartbeat = [DateTime]::MinValue
    $lastPackageCheck = [DateTime]::MinValue

    while ($true) {
        try {
            if (-not $Once -and ((Get-Date) - $lastHeartbeat).TotalSeconds -ge $HeartbeatInterval) {
                $lastHeartbeat = Get-Date
                Send-Heartbeat -Realtime $realtime -Token $Token
                $lastActivity = Get-Date
            }

            if ($inventoryJob -and $inventoryJob.State -ne 'Running') {
                if ($inventoryJob.State -eq 'Completed') {
                    $data = Receive-Job -Job $inventoryJob
                    Write-AgentLog ('Inventory collected: {0} OS, {1} application, {2} module update(s)' -f @($data.os_updates).Count, @($data.packages_updates).Count, @($data.module_updates).Count)
                    Save-CachedInventory -Data $data
                    $inventory = @{ CollectedAt = Get-Date; Data = $data }
                    $lastReport = [DateTime]::MinValue
                } else {
                    Write-AgentLog "Inventory collection failed: $($inventoryJob.ChildJobs[0].JobStateInfo.Reason)"
                }
                Remove-Job -Job $inventoryJob -Force
                $inventoryJob = $null
            }
            if (Complete-UpdateJob) {
                # Show what is left right away instead of the pre-update list for 6 hours.
                $nextInventory = Get-Date
            }
            if ($OnLinux -and $inventory -and -not $inventoryJob -and ((Get-Date) - $lastPackageCheck).TotalSeconds -ge 60) {
                # Packages installed outside the agent (apt, unattended-upgrades): collect again once
                # dpkg has been quiet for 2 minutes, so the portal does not list installed updates.
                $lastPackageCheck = Get-Date
                $dpkgChanged = (Get-Item -Path /var/lib/dpkg/status -ErrorAction SilentlyContinue).LastWriteTime
                if ($dpkgChanged -gt $inventory.CollectedAt -and $dpkgChanged -lt (Get-Date).AddMinutes(-2) -and $nextInventory -gt (Get-Date)) {
                    Write-AgentLog 'Packages changed, collecting the inventory again'
                    $nextInventory = Get-Date
                }
            }
            if (-not $inventoryJob -and (Get-Date) -ge $nextInventory) {
                Write-AgentLog 'Inventory collection started'
                $inventoryJob = Start-InventoryCollection
                $nextInventory = (Get-Date).AddSeconds($InventoryInterval)
            }

            if ($healthJob -and $healthJob.State -ne 'Running') {
                if ($healthJob.State -eq 'Completed') {
                    $data = Receive-Job -Job $healthJob | Select-Object -Last 1
                    Save-CachedInventory -Data $data -Name 'health'
                    $health = @{ CollectedAt = Get-Date; Data = $data }
                    $lastReport = [DateTime]::MinValue
                } else {
                    Write-AgentLog "Disk health collection failed: $($healthJob.ChildJobs[0].JobStateInfo.Reason)"
                }
                Remove-Job -Job $healthJob -Force
                $healthJob = $null
            }
            if (-not $healthJob -and (Get-Date) -ge $nextHealth) {
                $healthJob = Start-HealthCollection -Previous $health.Data
                $nextHealth = (Get-Date).AddSeconds($HealthInterval)
            }

            if (((Get-Date) - $lastReport).TotalSeconds -ge $ReportInterval) {
                $lastReport = Get-Date
                try {
                    Send-Report -Data (Get-Report -Inventory $inventory.Data -Health $health.Data) -Token $Token
                }
                catch {
                    # HTTP problems must not tear down the WebSocket connection.
                    Write-AgentLog "Report failed: $($_.Exception.Message)"
                }
                if ($Once) {
                    return
                }
            }

            if (-not $realtime -and (Get-Date) -ge $nextConnect) {
                $realtime = Start-Realtime -Token $Token
                $lastActivity = Get-Date
                if (-not $realtime) {
                    # WebSocket not enabled on the server, rely on HTTP reports only.
                    $nextConnect = (Get-Date).AddMinutes(15)
                }
            }

            if ($realtime) {
                $message = Receive-WsMessage -Realtime $realtime
                if ($message) {
                    $lastActivity = Get-Date
                    Invoke-RealtimeMessage -Realtime $realtime -Message $message -Token $Token
                } elseif (((Get-Date) - $lastActivity).TotalSeconds -ge 60) {
                    Send-WsMessage -Socket $realtime.Socket -Message @{ event = 'pusher:ping'; data = @{} }
                    $lastActivity = Get-Date
                }
            } else {
                Start-Sleep -Seconds 1
            }
        }
        catch {
            Write-AgentLog "Error: $($_.Exception.Message)"
            if ($realtime) {
                $realtime.Socket.Dispose()
                $realtime = $null
            }
            # Back off before reconnecting.
            $nextConnect = (Get-Date).AddSeconds(15)
            Start-Sleep -Seconds 5
        }
    }
}

#endregion

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if ($env:MDM_AGENT_NO_START) {
    return
}

if ($Install) {
    # Check privileges before anything else, so a failed install does not use up the enrolment code.
    if (-not (Test-AgentAdmin)) {
        $hint = if ($OnLinux) { 'run it with sudo (sudo pwsh ...)' } else { 'run PowerShell as Administrator' }
        Write-Host "The agent installs a system service and needs administrator rights: $hint." -ForegroundColor Red
        Remove-TemporaryInstaller
        exit 1
    }

    # The installer may run from a temporary download, install the agent to a permanent location.
    if (-not $InstallPath) {
        $InstallPath = if ($OnLinux) { '/opt/laravel-mdm' } else { Join-Path $env:ProgramData 'Laravel-MDM' }
    }
    New-Item -ItemType Directory -Force -Path $InstallPath | Out-Null
    $AgentDir = (Resolve-Path -Path $InstallPath).Path

    # An existing installation keeps its token: the run only updates the agent and the service,
    # the device is not enrolled again (an enrolment code is not needed and not used up).
    $existing = Test-Path -Path (Join-Path $AgentDir $(if ($OnLinux) { 'token' } else { 'Token.xml' }))
    if ($existing) {
        Write-Host "Existing installation found in $AgentDir, updating the agent to $AgentVersion." -ForegroundColor Yellow
    }

    $target = Join-Path $AgentDir 'app.ps1'
    if ($PSCommandPath -ne $target) {
        Copy-Item -Path $PSCommandPath -Destination $target -Force
    }

    Get-AgentToken | Out-Null
    if ($OnLinux) { Register-AgentService } else { Register-AgentTask }

    Remove-TemporaryInstaller

    $action = if ($existing) { 'updated' } else { 'installed' }
    Write-Host "Laravel-MDM agent $AgentVersion $action in $AgentDir and started." -ForegroundColor Green
    return
}

Start-Agent
