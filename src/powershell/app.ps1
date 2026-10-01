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

.PARAMETER ServerKeyFingerprint
    With -Install: the fingerprint of the server key (shown in Add device). The agent pins the key
    only when it matches.

.PARAMETER ResetServerKey
    With -Install: forget the pinned server key and pin the current one (after the server key was
    replaced on purpose).

.PARAMETER DisableScripts
    With -Install: never run remediation scripts on this device (stored in config.json, the server
    cannot change it). -EnableScripts allows them again.

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
    $InstallPath,
    [string]
    $ServerKeyFingerprint,
    [switch]
    $ResetServerKey,
    [switch]
    $DisableScripts,
    [switch]
    $EnableScripts
)

$ErrorActionPreference = 'Stop'
# Reported to the server, which offers an update when it serves a newer agent.
$AgentVersion = '1.10.2'
$AllowedCommands = @('turnOff', 'restart', 'doUpdates', 'installUpdate', 'updateAgent', 'runScripts', 'sync', 'wake')
# What installUpdate may install on its own, with the pattern its id must match (as on the server).
$UpdateKinds = @{
    windows = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
    apt     = '^[a-z0-9][a-z0-9+.\-]*(:[a-z0-9]+)?$'
    winget  = '^[A-Za-z0-9][A-Za-z0-9._+\-]*$'
    flatpak = '^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)+$'
    snap    = '^[a-z0-9][a-z0-9\-]*$'
    module  = '^[A-Za-z0-9][A-Za-z0-9._\-]*$'
    # A PowerShell 7 release from GitHub, by its version.
    pwsh    = '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,4}$'
}
# The server's public key ("n:e", base64), filled in by the server when it serves this script.
# The agent pins it on the first start and then trusts only what is signed with it.
$EmbeddedServerKey = ''
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
        # Every adapter that is not disabled, also disconnected ones (shown as such in the portal).
        Networks        = @(Get-NetAdapter | Where-Object { $_.Status -ne 'Disabled' -and -not $_.Hidden } | ForEach-Object {
                $address = @(Get-NetIPAddress -InterfaceIndex $_.InterfaceIndex -ErrorAction SilentlyContinue)
                $text = "$($_.Name) $($_.InterfaceDescription)"
                [PSCustomObject]@{
                    "Name"                 = $_.Name
                    "InterfaceDescription" = $_.InterfaceDescription
                    "Status"               = "$($_.Status)"
                    "Connected"            = "$($_.Status)" -eq 'Up'
                    "Type"                 = if ($_.PhysicalMediaType -match '802\.11|Wireless' -or $text -match 'Wi-?Fi|Wireless|WLAN') { 'wifi' }
                                             elseif ($text -match 'VPN|WireGuard|TAP-|OpenVPN|Tailscale|ZeroTier|Fortinet|AnyConnect|GlobalProtect|WAN Miniport') { 'vpn' }
                                             elseif ($text -match 'Bluetooth') { 'bluetooth' }
                                             elseif ($text -match 'Mobile Broadband|Cellular|WWAN|LTE') { 'cellular' }
                                             elseif ($text -match 'Hyper-V|vEthernet|VirtualBox|VMware|Loopback') { 'virtual' }
                                             else { 'lan' }
                    "Mac"                  = $_.MacAddress
                    "IPAddresses"          = @($address.IPAddress)
                    # With the prefix length, so the server knows which devices share a network (Wake-on-LAN).
                    "Addresses"            = @($address | ForEach-Object { @{ Address = "$($_.IPAddress)"; PrefixLength = [int]$_.PrefixLength } })
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
    # The same updates Install-WindowsUpdate installs: software and drivers, not hidden.
    $Updates = $UpdateSearcher.Search('IsInstalled=0 and IsHidden=0').Updates

    return $Updates | ForEach-Object {
        [PSCustomObject]@{
            # installUpdate installs a single update by this id.
            Id             = $_.Identity.UpdateID
            Title          = $_.Title
            IsDownloaded   = $_.IsDownloaded
            RebootRequired = $_.RebootRequired
        }
    }
}

function Install-WindowsUpdate {
    # Returns log lines. ResultCode: 2 succeeded, 3 succeeded with errors, 4 failed, 5 aborted.
    # -UpdateId installs only that update; -OnProgress gets the percent (0-100) and a message;
    # $State.Failures collects what failed.
    param (
        [string]
        $UpdateId,
        [scriptblock]
        $OnProgress = {},
        [hashtable]
        $State = @{ Failures = [System.Collections.ArrayList]@() }
    )

    $results = @{ 0 = 'not started'; 1 = 'in progress'; 2 = 'succeeded'; 3 = 'succeeded with errors'; 4 = 'failed'; 5 = 'aborted' }
    $Session = New-Object -ComObject Microsoft.Update.Session
    # Software and drivers (the list shows both: "Intel net Driver Update", "NVIDIA Display Driver
    # Update"), without the hidden ones.
    $found = $Session.CreateUpdateSearcher().Search('IsInstalled=0 and IsHidden=0').Updates
    $Updates = New-Object -ComObject Microsoft.Update.UpdateColl
    foreach ($update in $found) {
        if (-not $UpdateId -or $update.Identity.UpdateID -eq $UpdateId) {
            if (-not $update.EulaAccepted) { $update.AcceptEula() }
            $Updates.Add($update) | Out-Null
        }
    }
    if ($Updates.Count -eq 0) {
        if ($UpdateId) {
            'Windows Update: the update is not offered anymore (installed or replaced)'
            [void]$State.Failures.Add('The update is not offered by Windows Update anymore (installed or replaced), the list is collected again')
        } else {
            'Windows Update: nothing to install'
        }
        return
    }
    "Windows Update: $($Updates.Count) update(s): $(@($Updates | ForEach-Object { $_.Title }) -join '; ')"

    & $OnProgress 0 'Windows Update: downloading'
    $Downloader = $Session.CreateUpdateDownloader()
    $Downloader.Updates = $Updates
    $download = $Downloader.Download()
    "Windows Update: download $($results[[int]$download.ResultCode])"
    if ([int]$download.ResultCode -in 4, 5) {
        [void]$State.Failures.Add("Windows Update: download $($results[[int]$download.ResultCode])")
    }

    # One by one, so the progress moves with each installed update.
    $reboot = $false
    for ($i = 0; $i -lt $Updates.Count; $i++) {
        $update = $Updates.Item($i)
        & $OnProgress (30 + [int](70 * $i / $Updates.Count)) "Windows Update: installing $($update.Title)"
        $single = New-Object -ComObject Microsoft.Update.UpdateColl
        $single.Add($update) | Out-Null
        $Installer = $Session.CreateUpdateInstaller()
        $Installer.Updates = $single
        try {
            $install = $Installer.Install()
            $code = [int]$install.ResultCode
            if ($install.RebootRequired) { $reboot = $true }
        }
        catch {
            $code = 4
            "Windows Update: $($update.Title): $($_.Exception.Message)"
        }
        if ($code -ne 2) {
            "Windows Update: $($update.Title): $($results[$code])"
            if ($code -ne 3) { [void]$State.Failures.Add("$($update.Title): $($results[$code])") }
        }
    }
    & $OnProgress 100 'Windows Update: done'
    "Windows Update: install finished$(if ($reboot) { ', restart required' })"
}

function Install-PowerShellRelease {
    # Installs a PowerShell 7 release from GitHub (installations no package manager knows about).
    # The download is checked against the release's hashes.sha256. Linux: the .deb when the
    # powershell package is installed (amd64), otherwise the tar.gz replaces the directory of
    # the installed pwsh. Windows: the MSI. Returns log lines; failures go to $State.Failures.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Version,
        [bool]
        $OnLinux,
        [hashtable]
        $State = @{ Failures = [System.Collections.ArrayList]@() }
    )

    if ($Version -notmatch '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,4}$') {
        [void]$State.Failures.Add("PowerShell: invalid version '$Version'")
        return
    }
    $pwsh = @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { $_.Edition -eq 'PowerShell 7' }) | Select-Object -First 1
    if (-not $pwsh) {
        [void]$State.Failures.Add('PowerShell 7 is not installed')
        return
    }
    $base = "https://github.com/PowerShell/PowerShell/releases/download/v$Version"
    $machine = if ($OnLinux) { "$(uname -m)" } else { "$env:PROCESSOR_ARCHITECTURE" }
    $arch = switch -Regex ($machine) { '^(x86_64|AMD64)$' { 'x64' } '^(aarch64|arm64|ARM64)$' { 'arm64' } default { $null } }
    if (-not $arch) {
        [void]$State.Failures.Add("PowerShell: architecture '$machine' is not supported")
        return
    }
    $realPath = if ($OnLinux) { "$(readlink -f $pwsh.Path)" } else { $pwsh.Path }
    $dir = Split-Path -Path $realPath -Parent
    $asset = if ($OnLinux) {
        $deb = if ($arch -eq 'x64' -and (Get-Command -Name dpkg-query -ErrorAction SilentlyContinue)) { "$(dpkg-query -W -f='${Status}' powershell 2>$null)" -match 'install ok installed' }
        if ($deb) { "powershell_$Version-1.deb_amd64.deb" } else { "powershell-$Version-linux-$arch.tar.gz" }
    } else {
        "PowerShell-$Version-win-$arch.msi"
    }

    $temp = Join-Path ([System.IO.Path]::GetTempPath()) "mdm-pwsh-$([guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $temp -Force | Out-Null
    try {
        $file = Join-Path $temp $asset
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri "$base/$asset" -OutFile $file -UseBasicParsing -TimeoutSec 600
        # The list is UTF-16 in some releases (7.6), ASCII in others: read it by its byte order mark.
        $hashFile = Join-Path $temp 'hashes.sha256'
        Invoke-WebRequest -Uri "$base/hashes.sha256" -OutFile $hashFile -UseBasicParsing -TimeoutSec 60
        $reader = New-Object System.IO.StreamReader($hashFile, [System.Text.Encoding]::UTF8, $true)
        try { $hashes = $reader.ReadToEnd() } finally { $reader.Dispose() }
        $expected = foreach ($line in ($hashes -split "`r?`n")) { if ($line.Trim() -match "^([0-9a-fA-F]{64})\s+\*?$([regex]::Escape($asset))$") { $Matches[1] } }
        if (-not $expected) {
            [void]$State.Failures.Add("PowerShell ${Version}: $asset is not listed in the release hashes")
            return
        }
        $actual = (Get-FileHash -Path $file -Algorithm SHA256).Hash
        if ($actual -ne "$(@($expected)[0])".ToUpperInvariant()) {
            [void]$State.Failures.Add("PowerShell ${Version}: $asset does not match the release hashes (SHA-256 $actual)")
            return
        }
        "PowerShell ${Version}: $asset downloaded, SHA-256 verified"

        if ($asset -like '*.deb') {
            $env:DEBIAN_FRONTEND = 'noninteractive'
            $output = @(apt-get -o DPkg::Lock::Timeout=600 -y -q install $file 2>&1 | ForEach-Object { "$_" })
            if ($LASTEXITCODE) { [void]$State.Failures.Add("PowerShell ${Version}: apt-get install exit $LASTEXITCODE, $((@($output) | Select-Object -Last 2) -join ' | ')") ; return }
        } elseif ($asset -like '*.tar.gz') {
            # Unpacked next to the installation, then swapped in: running pwsh processes keep
            # their open files.
            $new = "$dir.mdm-new"
            Remove-Item -Path $new, "$dir.mdm-old" -Recurse -Force -ErrorAction SilentlyContinue
            New-Item -ItemType Directory -Path $new -Force | Out-Null
            tar -xzf $file -C $new
            if ($LASTEXITCODE -or -not (Test-Path -Path "$new/pwsh")) { [void]$State.Failures.Add("PowerShell ${Version}: unpacking failed"); return }
            chmod +x "$new/pwsh"
            Move-Item -Path $dir -Destination "$dir.mdm-old"
            Move-Item -Path $new -Destination $dir
            Remove-Item -Path "$dir.mdm-old" -Recurse -Force -ErrorAction SilentlyContinue
        } else {
            $process = Start-Process -FilePath msiexec.exe -ArgumentList @('/i', "`"$file`"", '/quiet', '/norestart') -Wait -PassThru
            # 3010: installed, a restart completes it.
            if ($process.ExitCode -notin 0, 3010) { [void]$State.Failures.Add("PowerShell ${Version}: msiexec exit $($process.ExitCode)"); return }
        }

        $installed = "$(& $pwsh.Path -NoProfile -NonInteractive -Command '$PSVersionTable.PSVersion.ToString()' 2>$null)".Trim()
        if ($installed -ne $Version) {
            [void]$State.Failures.Add("PowerShell: version $installed after installing $Version")
            return
        }
        "PowerShell: updated to $Version"
        $State.RestartAgent = $PSVersionTable.PSEdition -eq 'Core'
    }
    catch {
        [void]$State.Failures.Add("PowerShell ${Version}: $($_.Exception.Message)")
    }
    finally {
        Remove-Item -Path $temp -Recurse -Force -ErrorAction SilentlyContinue
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
        $Update,
        # Only these modules of this edition and owner (installUpdate), '' for the system-wide ones.
        [string[]]
        $Names,
        [string]
        $Edition,
        [string]
        $User,
        # With -Names: the version to install (known from the inventory), so the gallery is not
        # searched again for every installed module.
        [string]
        $Version,
        # Gets the percent (0-100) and a message while modules are updated.
        [scriptblock]
        $OnProgress
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
function Write-MdmProgress ([int]$Percent, [string]$Message) {
    # Read by the agent while the process runs (the output is only read at the end).
    if ($ProgressPath) { try { Set-Content -Path $ProgressPath -Value "$Percent|$Message" -Encoding UTF8 } catch { } }
}
$outdated = @()
$canCheck = (Get-Command -Name Get-InstalledModule -ErrorAction SilentlyContinue) -and (Get-Command -Name Find-Module -ErrorAction SilentlyContinue)
# PowerShellGet 1 (Windows PowerShell) would ask to install the NuGet provider first.
if ($canCheck -and $PSVersionTable.PSEdition -ne 'Core' -and -not (Get-PackageProvider -ListAvailable -Name NuGet -ErrorAction SilentlyContinue)) { $canCheck = $false }
if ($canCheck) {
    Write-MdmProgress 5 'Reading the installed modules'
    # Only the modules asked for (installUpdate): listing all of them (Microsoft.Graph alone has
    # dozens) and searching the gallery for each would take minutes.
    $found = if ($Names) { Get-InstalledModule -Name $Names -ErrorAction SilentlyContinue } else { Get-InstalledModule -ErrorAction SilentlyContinue }
    $installed = @($found | Where-Object { $_.Repository -eq 'PSGallery' } |
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
    if ($Names) { $userModules = @($userModules | Where-Object { $Names -contains $_.Directory.Parent.Name }) }
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
        if ($Names -and $TargetVersion) {
            # The version the portal offered, found by the last inventory.
            foreach ($name in $Names) { $latest[$name] = $TargetVersion }
        } else {
            Write-MdmProgress 10 'Searching the PowerShell Gallery'
            foreach ($module in @(Find-Module -Name @($installed.Name | Select-Object -Unique) -Repository PSGallery -ErrorAction SilentlyContinue)) { $latest[$module.Name] = "$($module.Version)" }
        }
        foreach ($module in $installed) {
            $available = $latest[$module.Name]
            if ($available -and (ConvertTo-ModuleVersion $available) -gt (ConvertTo-ModuleVersion $module.Version)) {
                $outdated += [pscustomobject]@{ Name = $module.Name; Version = $module.Version; Available = $available; User = $module.User }
            }
        }
    }
}
if ($Names) {
    $outdated = @($outdated | Where-Object { $Names -contains $_.Name -and "$($_.User)" -eq "$OnlyUser" })
}
if ($Update) {
    # Installed next to the current version, like Update-Module always does; failed ones stay listed
    # with the reason. Users' own modules are left to the caller (updated as that user).
    $remaining = @()
    $done = 0
    $count = @($outdated | Where-Object { -not $_.User }).Count
    foreach ($module in $outdated) {
        if ($module.User) { $remaining += $module; continue }
        Write-MdmProgress (15 + [int](80 * $done / [Math]::Max(1, $count))) "Update-Module $($module.Name) $($module.Version) -> $($module.Available)"
        $done++
        try {
            Update-Module -Name $module.Name -RequiredVersion $module.Available -Force -Confirm:$false -ErrorAction Stop
        }
        catch {
            # Update-Module reads the dates of the installation, which modules not installed with
            # Install-Module lack (Microsoft.WinGet.Client: "Cannot convert null to type
            # System.DateTime"). Installing the version next to the old one is the same result.
            $updateError = $_.Exception.Message
            try {
                $install = @{ Name = $module.Name; RequiredVersion = $module.Available; Repository = 'PSGallery'; Scope = 'AllUsers'; Force = $true; Confirm = $false; ErrorAction = 'Stop' }
                $parameters = (Get-Command -Name Install-Module).Parameters
                foreach ($switch in 'AllowClobber', 'SkipPublisherCheck', 'AcceptLicense') { if ($parameters.ContainsKey($switch)) { $install[$switch] = $true } }
                Install-Module @install
            }
            catch {
                $module | Add-Member -NotePropertyName Error -NotePropertyValue "$updateError; Install-Module: $($_.Exception.Message)"
                $remaining += $module
            }
        }
    }
    $outdated = $remaining
}
ConvertTo-Json -InputObject @($outdated) -Compress
'@

    # Runs a script in a separate process (inherits the idle priority, stopped when the gallery
    # hangs) and returns the objects of its JSON output.
    $invoke = {
        param ([string]$FilePath, [string[]]$Prefix, [string]$Script, [int]$TimeoutMinutes = 15)
        $output = [System.IO.Path]::GetTempFileName()
        $progress = [System.IO.Path]::GetTempFileName()
        $Script = "`$ProgressPath = '$($progress -replace "'", "''")'`n$Script"
        $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($Script))
        try {
            $process = Start-Process -FilePath $FilePath -ArgumentList (@($Prefix) + @('-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded)) -RedirectStandardOutput $output -NoNewWindow -PassThru
            $deadline = (Get-Date).AddMinutes($TimeoutMinutes)
            $last = $null
            while (-not $process.WaitForExit(2000)) {
                if ((Get-Date) -gt $deadline) {
                    try { $process.Kill() } catch { }
                    # Not an empty result: the caller must not take it for "nothing to update".
                    return [pscustomobject]@{ TimedOut = $true; Minutes = $TimeoutMinutes }
                }
                $mark = (Get-Content -Path $progress -Raw -ErrorAction SilentlyContinue) -as [string]
                if ($OnProgress -and $mark -and $mark -ne $last -and $mark -match '^(\d+)\|(.*)$') {
                    $last = $mark
                    & $OnProgress ([int]$Matches[1]) $Matches[2].Trim()
                }
            }
            $json = (Get-Content -Path $output -Raw) -as [string]
            # Windows PowerShell 5.1 outputs a JSON array as one object: enumerate it explicitly.
            if ($json) { foreach ($item in @($json.Trim() | ConvertFrom-Json | ForEach-Object { $_ })) { if ($item) { $item } } }
        }
        catch {
        }
        finally {
            Remove-Item -Path $output, $progress -Force -ErrorAction SilentlyContinue
        }
    }

    $result = @()
    $quote = { param ($Value) "'" + ("$Value" -replace "'", "''") + "'" }
    foreach ($powershell in @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { -not $Edition -or $_.Edition -eq $Edition })) {
        $prefix = if ($Update) { '$Update = $true' } else { '$Update = $false' }
        $prefix += "`n`$Names = @($(@($Names | ForEach-Object { & $quote $_ }) -join ', '))`n`$OnlyUser = $(& $quote $User)`n`$TargetVersion = $(& $quote $Version)"
        # Updates of big modules (Microsoft.Graph, Az) take long, listing does not.
        foreach ($module in @(& $invoke $powershell.Path @() "$prefix`n$moduleScript" $(if ($Update) { 45 } else { 15 }))) {
            if ($module.PSObject.Properties['TimedOut']) {
                $result += [PSCustomObject]@{ Name = $(if ($Names) { $Names -join ', ' } else { 'PowerShell modules' }); Version = $null; Available = $null; Edition = $powershell.Edition; User = $null; Error = "Timed out after $($module.Minutes) minutes" }
                continue
            }
            $result += [PSCustomObject]@{ Name = $module.Name; Version = $module.Version; Available = $module.Available; Edition = $powershell.Edition; User = $module.User; Error = $module.Error }
        }
    }

    if ($Update -and $OnLinux) {
        # Users' own modules (Install-Module defaults to CurrentUser in PowerShell 7) are updated as
        # that user, so they stay in the user's profile and owned by the user. Not possible on
        # Windows: SYSTEM cannot run as a user without the password, they stay listed.
        foreach ($group in @($result | Where-Object { $_.User -and (-not $User -or $_.User -eq $User) } | Group-Object -Property User, Edition)) {
            $first = $group.Group[0]
            $powershell = @(Get-PowerShellHosts -OnLinux $true | Where-Object { $_.Edition -eq $first.Edition }) | Select-Object -First 1
            if (-not $powershell -or $first.User -notmatch '^[a-z_][a-z0-9_.-]*$') { continue }
            $modules = ConvertTo-Json -InputObject @($group.Group | Select-Object -Property Name, Available) -Compress
            $userScript = @"
`$ProgressPreference = 'SilentlyContinue'
`$failed = @()
foreach (`$module in @(ConvertFrom-Json '$($modules -replace "'", "''")')) {
    try { Update-Module -Name `$module.Name -RequiredVersion `$module.Available -Force -Confirm:`$false -ErrorAction Stop }
    catch { `$failed += [pscustomobject]@{ Name = `$module.Name; Error = `$_.Exception.Message } }
}
ConvertTo-Json -InputObject @(`$failed) -Compress
"@
            $asUser = Get-UserCommand -User $first.User
            if (-not $asUser) { continue }
            $failed = @{}
            foreach ($item in @(& $invoke $asUser[0] (@($asUser[1..($asUser.Count - 1)]) + $powershell.Path) $userScript 45)) {
                if ($item.PSObject.Properties['TimedOut']) { foreach ($module in $group.Group) { $failed[$module.Name] = "Timed out after $($item.Minutes) minutes" } } else { $failed[$item.Name] = $item.Error }
            }
            foreach ($module in $group.Group) {
                if ($failed.ContainsKey($module.Name)) { $module.Error = $failed[$module.Name] } else { $result = @($result | Where-Object { $_ -ne $module }) }
            }
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
    # Every interface except loopback and container ends (veth), also the ones that are down.
    $interfaces = ip -j addr show 2>$null | ConvertFrom-Json
    foreach ($interface in $interfaces) {
        $name = "$($interface.ifname)"
        if ($name -eq 'lo' -or $name -like 'veth*') {
            continue
        }
        $addresses = @($interface.addr_info | ForEach-Object { $_.local })
        $prefixed = @($interface.addr_info | Where-Object { $_.local } | ForEach-Object { @{ Address = "$($_.local)"; PrefixLength = [int]$_.prefixlen } })
        $type = if ((Test-Path -Path "/sys/class/net/$name/wireless") -or $name -match "^wl") { 'wifi' }
            elseif ($name -match '^(docker|br-)') { 'docker' }
            elseif ($name -match '^(tun|tap|wg|tailscale|zt|ppp|vpn|ipsec|nordlynx)') { 'vpn' }
            elseif ($name -match '^(wwan|ww)') { 'cellular' }
            elseif ($name -match '^(virbr|vnet|lxc|lxd|incus|cni|flannel|cali|podman)') { 'virtual' }
            elseif ($name -match '^(br|bond)') { 'bridge' }
            else { 'lan' }
        [PSCustomObject]@{
            Name        = $name
            Status      = if ($interface.operstate -eq 'UP') { 'Up' } else { "$($interface.operstate)" }
            # Tunnels (WireGuard, tun) report UNKNOWN: connected when they have an address.
            Connected   = $interface.operstate -eq 'UP' -or ($interface.operstate -eq 'UNKNOWN' -and $addresses.Count -gt 0)
            Type        = $type
            Mac         = $interface.address
            IPAddresses = $addresses
            Addresses   = $prefixed
        }
    }
}

function Get-UserCommand {
    param (
        [string]
        $User
    )

    # runuser keeps root's environment (HOME=/root, PSModulePath ...): tools would work on root's
    # profile. The command runs with the user's own minimal environment instead.
    $entry = "$(getent passwd $User 2>$null)" -split ':'
    if ($entry.Count -lt 6 -or -not $entry[5]) {
        return $null
    }

    return @('runuser', '-u', $User, '--', 'env', '-i', "HOME=$($entry[5])", "USER=$User", "LOGNAME=$User", 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG=C.UTF-8')
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
        $asUser = if ($installation.User) { Get-UserCommand -User $installation.User }
        if ($installation.User -and -not $asUser) { continue }
        $run = {
            param ([string[]]$FlatpakArguments)
            if ($asUser) { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) flatpak @FlatpakArguments 2>$null } else { flatpak @FlatpakArguments 2>$null }
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

#region Security

# Everything the server sends is signed with its RSA key, which the agent pins on the first start
# (from $EmbeddedServerKey, filled in when the server serves this script). Everything the agent
# sends is signed with the device key, generated on the device and never sent anywhere. Each
# signature covers a context prefix (MDM1-REQ, MDM1-RESP, ...), so it is valid for one purpose only.
$script:ClockOffset = 0
$script:ServerKey = $null
$script:ServerRsa = $null
$script:DeviceKey = $null
$script:DeviceId = $null
$script:HttpClient = $null

function Get-UnixTime {
    # Server time: the offset comes from the timestamps of verified server responses.
    return [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() + $script:ClockOffset
}

function New-Nonce {
    $bytes = New-Object byte[] 16
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($bytes) } finally { $random.Dispose() }
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Get-Sha256Hex {
    param (
        [byte[]]
        $Bytes
    )

    if ($null -eq $Bytes) { $Bytes = [byte[]]@() }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return -join ($sha.ComputeHash($Bytes) | ForEach-Object { $_.ToString('x2') })
    }
    finally {
        $sha.Dispose()
    }
}

function Get-KeyFingerprint {
    param (
        [Parameter(Mandatory = $true)]
        $Key
    )

    return Get-Sha256Hex -Bytes ([System.Text.Encoding]::UTF8.GetBytes("rsa:$($Key.n):$($Key.e)"))
}

function Protect-AgentPath {
    # Only SYSTEM / root and administrators may read or change the agent's files.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path,
        [switch]
        $Directory
    )

    if ($OnLinux) {
        chmod $(if ($Directory) { '700' } else { '600' }) $Path
        return
    }

    # SIDs instead of names: independent of the system language.
    $grant = if ($Directory) { '(OI)(CI)F' } else { 'F' }
    icacls $Path /inheritance:r /grant:r "*S-1-5-18:$grant" "*S-1-5-32-544:$grant" | Out-Null
}

function ConvertTo-Hashtable {
    # ConvertFrom-Json -AsHashtable does not exist in Windows PowerShell 5.1.
    param (
        $Object
    )

    if ($Object -is [System.Management.Automation.PSCustomObject]) {
        $table = @{}
        foreach ($property in $Object.PSObject.Properties) {
            $table[$property.Name] = ConvertTo-Hashtable -Object $property.Value
        }
        return $table
    }
    return $Object
}

function Get-AgentConfig {
    # Local settings the server cannot change: the pinned server key, the device id and whether
    # remediation scripts may run.
    $path = Join-Path $AgentDir 'config.json'
    $config = @{}
    if (Test-Path -Path $path) {
        $config = ConvertTo-Hashtable -Object (Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json)
    }
    if ($null -eq $config['scripts_enabled']) {
        $config['scripts_enabled'] = $true
    }
    return $config
}

function Save-AgentConfig {
    param (
        [Parameter(Mandatory = $true)]
        [hashtable]
        $Config
    )

    $path = Join-Path $AgentDir 'config.json'
    $Config | ConvertTo-Json -Depth 4 | Set-Content -Path $path -Encoding UTF8
    Protect-AgentPath -Path $path
}

function Set-ServerKey {
    param (
        [Parameter(Mandatory = $true)]
        $Key
    )

    $parameters = New-Object System.Security.Cryptography.RSAParameters
    $parameters.Modulus = [Convert]::FromBase64String($Key.n)
    $parameters.Exponent = [Convert]::FromBase64String($Key.e)
    $rsa = [System.Security.Cryptography.RSA]::Create()
    $rsa.ImportParameters($parameters)
    $script:ServerKey = $Key
    $script:ServerRsa = $rsa
}

function Initialize-ServerKey {
    # Pins the server key once: the one filled into this script, otherwise the one the server
    # announces (trust on first use). -ServerKeyFingerprint must match when it is given.
    $config = Get-AgentConfig
    $embedded = $null
    if ($EmbeddedServerKey -match '^([A-Za-z0-9+/=]+):([A-Za-z0-9+/=]+)$') {
        $embedded = @{ n = $Matches[1]; e = $Matches[2] }
    }

    if ($ResetServerKey -and $config['server_key']) {
        Write-AgentLog "Pinned server key $($config['server_key'].fingerprint) removed (-ResetServerKey)"
        $config.Remove('server_key')
    }

    $key = $config['server_key']
    if (-not $key) {
        $key = $embedded
        if (-not $key) {
            $response = Invoke-WebRequest -UseBasicParsing -Uri "$($ServerUrl.TrimEnd('/'))/agent/signing-key"
            $announced = $response.Content | ConvertFrom-Json
            $key = @{ n = $announced.n; e = $announced.e }
        }
        $key['fingerprint'] = Get-KeyFingerprint -Key $key
        if ($ServerKeyFingerprint -and $key.fingerprint -ne $ServerKeyFingerprint.ToLowerInvariant()) {
            throw "The server key $($key.fingerprint) does not match -ServerKeyFingerprint $ServerKeyFingerprint, it is not trusted."
        }
        $config['server_key'] = $key
        Save-AgentConfig -Config $config
        Write-AgentLog "Pinned the server key $($key.fingerprint)"
    } elseif ($ServerKeyFingerprint -and $key.fingerprint -ne $ServerKeyFingerprint.ToLowerInvariant()) {
        throw "The pinned server key $($key.fingerprint) does not match -ServerKeyFingerprint $ServerKeyFingerprint. Reinstall with -ResetServerKey if the server key was replaced on purpose."
    } elseif ($embedded -and (Get-KeyFingerprint -Key $embedded) -ne $key.fingerprint) {
        Write-AgentLog "Warning: this agent carries server key $(Get-KeyFingerprint -Key $embedded), the pinned key $($key.fingerprint) stays trusted"
    }

    Set-ServerKey -Key $key
    $script:DeviceId = $config['device_id']
    return $config
}

function Test-ServerSignature {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Context,
        [AllowEmptyString()]
        [string]
        $Message,
        [string]
        $Signature
    )

    if (-not $script:ServerRsa -or -not $Signature) {
        return $false
    }
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes("$Context`n$Message")
        return $script:ServerRsa.VerifyData($bytes, [Convert]::FromBase64String($Signature), [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1)
    }
    catch {
        return $false
    }
}

function Get-DeviceKey {
    # The device's own RSA key. Windows: a non-exportable CNG machine key (not even SYSTEM can read
    # the private key out), or a DPAPI-protected file where CNG is not available. Linux: a root-only file.
    if ($script:DeviceKey) {
        return $script:DeviceKey
    }

    if ($OnLinux) {
        $path = Join-Path $AgentDir 'device.key'
        $rsa = [System.Security.Cryptography.RSA]::Create()
        if (Test-Path -Path $path) {
            $read = 0
            $rsa.ImportRSAPrivateKey([Convert]::FromBase64String((Get-Content -Path $path -Raw).Trim()), [ref]$read)
        } else {
            $rsa.KeySize = 3072
            Set-Content -Path $path -Value ([Convert]::ToBase64String($rsa.ExportRSAPrivateKey())) -NoNewline
            Protect-AgentPath -Path $path
            Write-AgentLog 'Device key created'
        }
        return $script:DeviceKey = $rsa
    }

    try {
        $name = 'Laravel-MDM-Agent'
        $provider = [System.Security.Cryptography.CngProvider]::MicrosoftSoftwareKeyStorageProvider
        $machine = [System.Security.Cryptography.CngKeyOpenOptions]::MachineKey
        if ([System.Security.Cryptography.CngKey]::Exists($name, $provider, $machine)) {
            $cng = [System.Security.Cryptography.CngKey]::Open($name, $provider, $machine)
        } else {
            $parameters = New-Object System.Security.Cryptography.CngKeyCreationParameters
            $parameters.Provider = $provider
            $parameters.KeyCreationOptions = [System.Security.Cryptography.CngKeyCreationOptions]::MachineKey
            $parameters.ExportPolicy = [System.Security.Cryptography.CngExportPolicies]::None
            $parameters.Parameters.Add((New-Object System.Security.Cryptography.CngProperty('Length', [BitConverter]::GetBytes(3072), [System.Security.Cryptography.CngPropertyOptions]::None)))
            $cng = [System.Security.Cryptography.CngKey]::Create([System.Security.Cryptography.CngAlgorithm]::Rsa, $name, $parameters)
            Write-AgentLog 'Device key created (CNG machine key, not exportable)'
        }
        return $script:DeviceKey = New-Object System.Security.Cryptography.RSACng($cng)
    }
    catch {
        Write-AgentLog "CNG key storage failed ($($_.Exception.Message)), using a DPAPI-protected key file"
    }

    $path = Join-Path $AgentDir 'DeviceKey.xml'
    $rsa = New-Object System.Security.Cryptography.RSACryptoServiceProvider(3072)
    if (Test-Path -Path $path) {
        $secure = (Import-Clixml -Path $path).key
        $rsa.FromXmlString([System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)))
    } else {
        @{ key = ($rsa.ToXmlString($true) | ConvertTo-SecureString -AsPlainText -Force) } | Export-Clixml -Path $path
        Protect-AgentPath -Path $path
        Write-AgentLog 'Device key created (DPAPI-protected file)'
    }
    return $script:DeviceKey = $rsa
}

function Get-DevicePublicKey {
    $parameters = (Get-DeviceKey).ExportParameters($false)
    return @{ n = [Convert]::ToBase64String($parameters.Modulus); e = [Convert]::ToBase64String($parameters.Exponent) }
}

function New-DeviceSignature {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Context,
        [AllowEmptyString()]
        [string]
        $Message
    )

    $bytes = [System.Text.Encoding]::UTF8.GetBytes("$Context`n$Message")
    return [Convert]::ToBase64String((Get-DeviceKey).SignData($bytes, [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1))
}

function Get-HttpClient {
    if (-not $script:HttpClient) {
        if (-not ('System.Net.Http.HttpClient' -as [type])) {
            Add-Type -AssemblyName System.Net.Http
        }
        $script:HttpClient = New-Object System.Net.Http.HttpClient
        $script:HttpClient.Timeout = [TimeSpan]::FromSeconds(100)
    }
    return $script:HttpClient
}

function Get-ResponseHeader {
    param (
        [Parameter(Mandatory = $true)]
        $Response,
        [Parameter(Mandatory = $true)]
        [string]
        $Name
    )

    $values = $null
    if ($Response.Headers.TryGetValues($Name, [ref]$values)) {
        return @($values)[0]
    }
    return $null
}

#endregion

#region Remediation scripts

# Runs are verified against the pinned server key (manifest signature, device, expiry, one-time
# run id, platform, hashes of the script bytes), kept in memory only and run one at a time in a
# separate low-priority process without network access, the code passed on stdin. Nothing of the
# script is written to disk and it never appears on a command line.
$script:ScriptQueue = New-Object System.Collections.Queue
$script:CurrentScript = $null
$script:ScriptsRequested = $false
$script:ExecutedRuns = $null

# Constant bootstrap of the script process: reads the script (base64) from stdin, checks its hash
# again and runs it. Exit 97 = hash mismatch, 98 = the script threw.
$ScriptBootstrap = @'
$ErrorActionPreference = 'Stop'
# Windows PowerShell writes progress ("Preparing modules for first use.") to a redirected stderr as CLIXML.
$ProgressPreference = 'SilentlyContinue'
$bytes = [Convert]::FromBase64String([Console]::In.ReadToEnd().Trim())
$sha = [Security.Cryptography.SHA256]::Create()
$hash = -join ($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') })
if ($hash -ne $env:MDM_SCRIPT_SHA256) { [Console]::Error.WriteLine('The script does not match its signed hash.'); exit 97 }
$code = [Text.Encoding]::UTF8.GetString($bytes)
Remove-Variable bytes, sha, hash
$global:LASTEXITCODE = 0
try { & ([scriptblock]::Create($code)); exit $LASTEXITCODE }
catch { [Console]::Error.WriteLine($_.ToString()); exit 98 }
'@

function Get-ExecutedRuns {
    if ($null -eq $script:ExecutedRuns) {
        $script:ExecutedRuns = New-Object System.Collections.Generic.List[long]
        $path = Join-Path $AgentDir 'state.json'
        if (Test-Path -Path $path) {
            try { foreach ($id in @((Get-Content -Path $path -Raw | ConvertFrom-Json).executed_runs)) { $script:ExecutedRuns.Add([long]$id) } } catch { }
        }
    }
    # The comma keeps an empty list from being unrolled to $null.
    return , $script:ExecutedRuns
}

function Add-ExecutedRun {
    param (
        [long]
        $RunId
    )

    $runs = Get-ExecutedRuns
    $runs.Add($RunId)
    while ($runs.Count -gt 500) { $runs.RemoveAt(0) }
    $path = Join-Path $AgentDir 'state.json'
    @{ executed_runs = @($runs) } | ConvertTo-Json -Compress | Set-Content -Path $path -Encoding UTF8
    Protect-AgentPath -Path $path
}

function Get-ScriptFingerprint {
    # The same as the server: SHA-256 of {detection_sha256, platform, remediation_sha256, timeout}.
    param (
        $Manifest
    )

    $remediation = if ($Manifest.remediation_sha256) { '"' + $Manifest.remediation_sha256 + '"' } else { 'null' }
    $json = '{"detection_sha256":"' + $Manifest.detection_sha256 + '","platform":"' + $Manifest.platform + '","remediation_sha256":' + $remediation + ',"timeout":' + [int]$Manifest.timeout + '}'
    return Get-Sha256Hex -Bytes ([System.Text.Encoding]::UTF8.GetBytes($json))
}

function Test-ScriptRun {
    # Returns the verified run, or throws why it is rejected.
    param (
        [Parameter(Mandatory = $true)]
        $Payload
    )

    if (-not (Test-ServerSignature -Context 'MDM1-SCRIPT' -Message "$($Payload.manifest)" -Signature "$($Payload.signature)")) {
        throw 'the manifest is not signed with the pinned server key'
    }
    $manifest = $Payload.manifest | ConvertFrom-Json
    $now = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() + $script:ClockOffset
    if ("$($manifest.device_id)" -ne "$($script:DeviceId)") { throw "the run is for device $($manifest.device_id)" }
    if ($now -gt [long]$manifest.expires_at) { throw 'the run has expired' }
    if ($now -lt [long]$manifest.issued_at - 300) { throw 'the run is issued in the future' }
    if ((Get-ExecutedRuns).Contains([long]$manifest.run_id)) { throw 'the run was already executed' }
    $platform = if ($OnLinux) { 'linux' } else { 'windows' }
    if ($manifest.platform -ne 'all' -and $manifest.platform -ne $platform) { throw "the script is for $($manifest.platform)" }
    if ([int]$manifest.timeout -lt 1 -or [int]$manifest.timeout -gt 3600) { throw 'invalid timeout' }

    $detection = [Convert]::FromBase64String("$($Payload.detection)")
    if ((Get-Sha256Hex -Bytes $detection) -ne $manifest.detection_sha256) { throw 'the detection script does not match its signed hash' }
    $remediation = $null
    if ($manifest.remediation_sha256) {
        $remediation = [Convert]::FromBase64String("$($Payload.remediation)")
        if ((Get-Sha256Hex -Bytes $remediation) -ne $manifest.remediation_sha256) { throw 'the remediation script does not match its signed hash' }
    } elseif ($Payload.remediation) {
        throw 'the remediation script is not in the signed manifest'
    }
    if ((Get-ScriptFingerprint -Manifest $manifest) -ne $manifest.fingerprint) { throw 'the fingerprint does not match the scripts' }

    return @{ Manifest = $manifest; Detection = $detection; Remediation = $remediation }
}

function Initialize-ScriptSandbox {
    # Windows: a copy of powershell.exe that the firewall blocks completely; the agent itself keeps
    # its network. Returns the path, or throws why scripts cannot run isolated.
    $source = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $dir = Join-Path $AgentDir 'sandbox'
    $target = Join-Path $dir 'powershell.exe'
    if (-not (Test-Path -Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
    # Copied again after a Windows update changed the original.
    if (-not (Test-Path -Path $target) -or (Get-FileHash -Path $target).Hash -ne (Get-FileHash -Path $source).Hash) {
        Copy-Item -Path $source -Destination $target -Force
    }

    foreach ($direction in 'Outbound', 'Inbound') {
        $name = "LaravelMDM-Scripts-$direction"
        $rule = Get-NetFirewallRule -Name $name -ErrorAction SilentlyContinue
        if (-not $rule) {
            New-NetFirewallRule -Name $name -DisplayName "Laravel-MDM remediation scripts ($direction, no network)" -Direction $direction -Action Block -Program $target -Profile Any -Enabled True | Out-Null
            $rule = Get-NetFirewallRule -Name $name
        }
        $program = ($rule | Get-NetFirewallApplicationFilter).Program
        if ("$($rule.Enabled)" -ne 'True' -or "$($rule.Action)" -ne 'Block' -or $program -ne $target) {
            throw "the firewall rule $name is changed or disabled"
        }
    }
    $disabled = @(Get-NetFirewallProfile | Where-Object { "$($_.Enabled)" -ne 'True' })
    if ($disabled) {
        throw "Windows Firewall is off for the $(($disabled.Name) -join ', ') profile, scripts cannot run without network access"
    }
    return $target
}

function Start-ScriptProcess {
    param (
        [Parameter(Mandatory = $true)]
        [byte[]]
        $Code
    )

    $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($ScriptBootstrap))
    $info = New-Object System.Diagnostics.ProcessStartInfo
    if ($OnLinux) {
        # An empty network namespace: no interfaces, no DNS, also for every child process.
        foreach ($tool in 'unshare', 'nice', 'ionice') {
            if (-not (Get-Command -Name $tool -CommandType Application -ErrorAction SilentlyContinue)) {
                throw "network isolation unavailable ($tool not found)"
            }
        }
        $info.FileName = (Get-Command -Name unshare -CommandType Application | Select-Object -First 1).Source
        $info.Arguments = "--net -- nice -n 10 ionice -c 3 `"$((Get-Process -Id $PID).Path)`" -NoLogo -NoProfile -NonInteractive -EncodedCommand $encoded"
    } else {
        $info.FileName = Initialize-ScriptSandbox
        $info.Arguments = "-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand $encoded"
    }
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.WorkingDirectory = [System.IO.Path]::GetTempPath()
    $info.EnvironmentVariables['MDM_SCRIPT_SHA256'] = Get-Sha256Hex -Bytes $Code

    $process = [System.Diagnostics.Process]::Start($info)
    if (-not $OnLinux) {
        try { $process.PriorityClass = [System.Diagnostics.ProcessPriorityClass]::BelowNormal } catch { }
    }
    $stdout = $process.StandardOutput.ReadToEndAsync()
    $stderr = $process.StandardError.ReadToEndAsync()
    $process.StandardInput.Write([Convert]::ToBase64String($Code))
    $process.StandardInput.Close()

    return @{ Process = $process; Stdout = $stdout; Stderr = $stderr; Started = Get-Date }
}

function ConvertFrom-CliXmlStream {
    # Windows PowerShell serializes errors, warnings and progress to a redirected stderr as
    # "#< CLIXML" followed by <Objs>. Keeps the text of errors, warnings, verbose and debug records
    # and drops progress; anything that is not CLIXML stays as it is.
    param (
        [AllowEmptyString()]
        [string]
        $Text
    )

    $marker = '#< CLIXML'
    $start = $Text.IndexOf($marker)
    if ($start -lt 0) {
        return $Text
    }

    $before = $Text.Substring(0, $start)
    $lines = New-Object System.Collections.Generic.List[string]
    try {
        $xml = New-Object System.Xml.XmlDocument
        $xml.XmlResolver = $null
        # Every write starts a new "#< CLIXML" block.
        foreach ($block in ($Text.Substring($start) -split [regex]::Escape($marker))) {
            if (-not $block.Trim()) { continue }
            $xml.LoadXml($block.Trim())
            foreach ($node in $xml.DocumentElement.ChildNodes) {
                if ($node.LocalName -ne 'S') { continue }
                $prefix = switch ($node.GetAttribute('S')) { 'warning' { 'WARNING: ' } 'verbose' { 'VERBOSE: ' } 'debug' { 'DEBUG: ' } default { '' } }
                $lines.Add($prefix + [System.Xml.XmlConvert]::DecodeName($node.InnerText).TrimEnd("`r", "`n"))
            }
        }
    }
    catch {
        return $Text
    }

    return ($before + ($lines -join [Environment]::NewLine)).TrimEnd()
}

function Stop-ScriptProcess {
    param (
        $Process
    )

    try {
        if ($OnLinux) { $Process.Kill($true) } else { taskkill /PID $Process.Id /T /F 2>&1 | Out-Null }
    }
    catch { }
}

function Request-ScriptRuns {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $script:ScriptsRequested = $false
    if (-not (Get-AgentConfig)['scripts_enabled']) {
        Write-AgentLog 'Remediation scripts are disabled in config.json, not taking any'
        return
    }

    $response = Invoke-MdmApi -Path 'device/scripts' -Token $Token
    foreach ($payload in @($response.runs)) {
        if (-not $payload) { continue }
        $runId = $null
        try {
            $runId = ($payload.manifest | ConvertFrom-Json).run_id
            $run = Test-ScriptRun -Payload $payload
            $script:ScriptQueue.Enqueue($run)
        }
        catch {
            Write-AgentLog "Script run $runId rejected: $($_.Exception.Message)"
            if ($runId) {
                try { Invoke-MdmApi -Method Post -Path "device/scripts/runs/$runId" -Token $Token -Body @{ status = 'rejected'; error = $_.Exception.Message } | Out-Null } catch { }
            }
        }
    }
}

function Update-ScriptRun {
    # Advances the current run (detection, remediation, detection again) without blocking the loop.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $current = $script:CurrentScript
    if (-not $current) {
        if ($script:ScriptQueue.Count -eq 0) { return }
        $run = $script:ScriptQueue.Dequeue()
        Add-ExecutedRun -RunId $run.Manifest.run_id
        $current = $script:CurrentScript = @{ Run = $run; Step = 'detection'; Output = New-Object System.Text.StringBuilder; Exit = @{}; Error = $null; Proc = $null }
        Write-AgentLog "Script '$($run.Manifest.name)' v$($run.Manifest.version) ($($run.Manifest.fingerprint)) started"
    }

    try {
        if (-not $current.Proc) {
            $code = if ($current.Step -eq 'remediation') { $current.Run.Remediation } else { $current.Run.Detection }
            $current.Proc = Start-ScriptProcess -Code $code
            return
        }

        $proc = $current.Proc
        if (-not $proc.Process.HasExited) {
            if (((Get-Date) - $proc.Started).TotalSeconds -lt [int]$current.Run.Manifest.timeout) { return }
            Stop-ScriptProcess -Process $proc.Process
            $proc.Process.WaitForExit(5000) | Out-Null
            $current.Error = "$($current.Step) timed out after $($current.Run.Manifest.timeout) s"
        }

        $exit = if ($current.Error) { $null } else { $proc.Process.ExitCode }
        [void]$current.Output.AppendLine("== $($current.Step) ($(if ($current.Error) { 'timed out' } else { "exit $exit" })) ==")
        foreach ($stream in $proc.Stdout, $proc.Stderr) {
            if ($stream.Wait(5000) -and $stream.Result) {
                $text = ConvertFrom-CliXmlStream -Text $stream.Result
                if ($text.Trim()) { [void]$current.Output.AppendLine($text.TrimEnd()) }
            }
        }
        $proc.Process.Dispose()
        $current.Proc = $null
        $current.Exit[$current.Step] = $exit

        $status = $null
        if ($current.Error) {
            $status = 'error'
        } elseif ($current.Step -eq 'detection') {
            if ($exit -eq 0) { $status = 'compliant' }
            elseif ($exit -eq 1 -and $current.Run.Remediation) { $current.Step = 'remediation'; return }
            elseif ($exit -eq 1) { $status = 'failed' }
            else { $status = 'error'; $current.Error = "detection exited with $exit" }
        } elseif ($current.Step -eq 'remediation') {
            $current.Step = 'post'
            return
        } else {
            $status = if ($exit -eq 0) { 'remediated' } elseif ($exit -eq 1) { 'failed' } else { 'error' }
            if ($status -eq 'error') { $current.Error = "detection after the remediation exited with $exit" }
        }
    }
    catch {
        $status = 'error'
        $current.Error = $_.Exception.Message
        if ($current.Proc) { Stop-ScriptProcess -Process $current.Proc.Process }
    }

    $script:CurrentScript = $null
    $manifest = $current.Run.Manifest
    $output = $current.Output.ToString()
    if ($output.Length -gt 16000) { $output = $output.Substring(0, 16000) }
    Write-AgentLog "Script '$($manifest.name)' ($($manifest.fingerprint)): $status$(if ($current.Error) { ", $($current.Error)" })"
    try {
        Invoke-MdmApi -Method Post -Path "device/scripts/runs/$($manifest.run_id)" -Token $Token -Body @{
        status              = $status
        fingerprint         = $manifest.fingerprint
        detection_exit      = $current.Exit['detection']
        remediation_exit    = $current.Exit['remediation']
        post_detection_exit = $current.Exit['post']
        output              = $output
        error               = $current.Error
        } | Out-Null
    }
    catch {
        Write-AgentLog "Script result not sent: $($_.Exception.Message)"
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
    # Signed request with the device key; the response must be signed with the pinned server key
    # for this very request (its nonce), otherwise it is rejected before anything reads it.
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

    $client = Get-HttpClient
    $method = $Method.ToUpperInvariant()
    $bytes = if ($null -ne $Body) { [System.Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 6 -Compress)) } else { [byte[]]@() }
    $bodyHash = Get-Sha256Hex -Bytes $bytes

    for ($attempt = 1; ; $attempt++) {
        $nonce = New-Nonce
        $timestamp = Get-UnixTime
        $request = New-Object System.Net.Http.HttpRequestMessage((New-Object System.Net.Http.HttpMethod($method)), "$($ServerUrl.TrimEnd('/'))/api/$Path")
        $request.Headers.Add('Accept', 'application/json')
        if ($Token) { $request.Headers.Add('Authorization', "Bearer $Token") }
        $request.Headers.Add('X-MDM-Timestamp', "$timestamp")
        $request.Headers.Add('X-MDM-Nonce', $nonce)
        $request.Headers.Add('X-MDM-Signature', (New-DeviceSignature -Context 'MDM1-REQ' -Message "$method`n/api/$Path`n$timestamp`n$nonce`n$bodyHash"))
        if ($bytes.Length -gt 0) {
            $request.Content = New-Object System.Net.Http.ByteArrayContent(, $bytes)
            $request.Content.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/json; charset=utf-8')
        }

        try {
            $response = $client.SendAsync($request).GetAwaiter().GetResult()
            $content = $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
        }
        finally {
            $request.Dispose()
        }

        $device = Get-ResponseHeader -Response $response -Name 'X-MDM-Device'
        $serverTime = Get-ResponseHeader -Response $response -Name 'X-MDM-Timestamp'
        $signed = Test-ServerSignature -Context 'MDM1-RESP' -Message "$device`n$nonce`n$serverTime`n$(Get-Sha256Hex -Bytes $content)" -Signature (Get-ResponseHeader -Response $response -Name 'X-MDM-Signature')
        if (-not $signed) {
            throw "The response of $Path (HTTP $([int]$response.StatusCode)) is not signed with the pinned server key, it is ignored"
        }
        if ($script:DeviceId -and "$device" -ne "$($script:DeviceId)" -and $response.IsSuccessStatusCode) {
            throw "The response of $Path is signed for device $device, not for this device ($($script:DeviceId))"
        }
        $script:ClockOffset = [long]$serverTime - [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()

        $text = [System.Text.Encoding]::UTF8.GetString($content)
        $data = if ($text.Trim()) { $text | ConvertFrom-Json } else { $null }
        if ($response.IsSuccessStatusCode) {
            return $data
        }

        $code = if ($data -and $data.error) { "$($data.error)" } elseif ($data -and $data.message) { "$($data.message)" } else { "$($response.ReasonPhrase)" }
        # The clock of the device is off: the signed server time corrected it, try once more.
        if ($code -eq 'clock_skew' -and $attempt -eq 1) {
            Write-AgentLog ('Clock differs from the server by {0} s, using the server time' -f $script:ClockOffset)
            continue
        }
        if ($code -eq 'key_not_registered') {
            $script:KeyRegistered = $false
        }
        $exception = New-Object System.Exception("$Path failed: HTTP $([int]$response.StatusCode) $code")
        $exception.Data['MdmError'] = $code
        throw $exception
    }
}

function Register-MDMDevice {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $EnrolmentCode
    )

    # The public key goes with the enrolment, the request is signed with it.
    $response = Invoke-MdmApi -Method Post -Path 'device/register' -Body @{ 'enrolment_code' = $EnrolmentCode; 'public_key' = Get-DevicePublicKey }
    if (-not $response.token) {
        throw "Enrolment failed: $response"
    }

    $config = Get-AgentConfig
    $config['device_id'] = [int]$response.device_id
    $config['key_registered'] = $true
    Save-AgentConfig -Config $config
    $script:DeviceId = $config['device_id']
    $script:KeyRegistered = $true

    return $response.token
}

function Register-DeviceKey {
    # Agents enrolled before 1.7.0 register their key once with the device token; afterwards the
    # server accepts only requests signed with it.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $config = Get-AgentConfig
    try {
        $response = Invoke-MdmApi -Method Post -Path 'device/key' -Token $Token -Body @{ public_key = Get-DevicePublicKey }
        $config['device_id'] = [int]$response.device_id
        Write-AgentLog "Device key registered (device $($response.device_id))"
    }
    catch {
        if ($_.Exception.Data['MdmError'] -ne 'key_already_registered') {
            throw
        }
        # Registered before (the configuration was lost). If it is another key, requests fail
        # with invalid_signature until an admin resets the device key in the portal.
        Write-AgentLog 'The server already has a key for this device'
    }
    $config['key_registered'] = $true
    Save-AgentConfig -Config $config
    $script:DeviceId = $config['device_id']
    $script:KeyRegistered = $true
}

function Protect-MachineSecret {
    # DPAPI bound to this computer (not to a user): for handing the token from the installing
    # administrator to the SYSTEM task. The file is readable by SYSTEM and administrators only.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Text
    )

    try { Add-Type -AssemblyName System.Security -ErrorAction Stop } catch { }
    $bytes = [System.Security.Cryptography.ProtectedData]::Protect([System.Text.Encoding]::UTF8.GetBytes($Text), $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    return [Convert]::ToBase64String($bytes)
}

function Unprotect-MachineSecret {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Protected
    )

    try { Add-Type -AssemblyName System.Security -ErrorAction Stop } catch { }
    $bytes = [System.Security.Cryptography.ProtectedData]::Unprotect([Convert]::FromBase64String($Protected.Trim()), $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    return [System.Text.Encoding]::UTF8.GetString($bytes)
}

function Read-UserToken {
    # Token.xml: a SecureString (DPAPI) readable only by the account that wrote it.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path
    )

    $auth = Import-Clixml -Path $Path
    return [System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($auth.token))
}

function Save-UserToken {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Path,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    @{ token = ($Token | ConvertTo-SecureString -AsPlainText -Force) } | Export-Clixml -Path $Path
    Protect-AgentPath -Path $Path
}

function Get-EnrolmentCode {
    # From -EnrolmentCode, or saved by -Install for the SYSTEM task (Windows); asked for only when
    # someone runs the agent interactively.
    $code = $EnrolmentCode
    if (-not $code) { $code = (Get-AgentConfig)['enrolment_code'] }
    if (-not $code -and [Environment]::UserInteractive -and $Host.Name -eq 'ConsoleHost') {
        $code = Read-Host -Prompt 'Enrolment code'
    }
    if (-not $code) {
        throw 'The device is not enrolled: run the install command from Add device again (with a new enrolment code).'
    }
    return "$code"
}

function Get-AgentToken {
    if ($OnLinux) {
        # SecureString export is Windows only (DPAPI); keep the token in a root-only file.
        $tokenPath = "$AgentDir/token"
        if (-not (Test-Path -Path $tokenPath)) {
            Set-Content -Path $tokenPath -Value (Register-MDMDevice -EnrolmentCode (Get-EnrolmentCode)) -NoNewline
            Protect-AgentPath -Path $tokenPath
        }
        return (Get-Content -Path $tokenPath -Raw).Trim()
    }

    # Windows: the token is enrolled and kept by the account the agent runs as (SYSTEM), in
    # Token.xml encrypted for that account.
    $userPath = Join-Path $AgentDir 'Token.xml'
    $machinePath = Join-Path $AgentDir 'token.dat'
    if (Test-Path -Path $userPath) {
        try {
            return Read-UserToken -Path $userPath
        }
        catch {
            throw "Token.xml cannot be decrypted by $([System.Security.Principal.WindowsIdentity]::GetCurrent().Name): it belongs to another account (agents before 1.7.0 enrolled as the installing administrator). Run the install command again as Administrator, it hands the token over."
        }
    }

    if (Test-Path -Path $machinePath) {
        # Handed over by -Install: from now on only this account can read it.
        $token = Unprotect-MachineSecret -Protected (Get-Content -Path $machinePath -Raw)
        Save-UserToken -Path $userPath -Token $token
        Remove-Item -Path $machinePath -Force
        Write-AgentLog 'Device token taken over from the installation'
        return $token
    }

    $token = Register-MDMDevice -EnrolmentCode (Get-EnrolmentCode)
    Save-UserToken -Path $userPath -Token $token
    $config = Get-AgentConfig
    if ($config.ContainsKey('enrolment_code')) {
        $config.Remove('enrolment_code')
        Save-AgentConfig -Config $config
    }
    Write-AgentLog "Device enrolled (device $($script:DeviceId))"
    return $token
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
    $Action = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument $arguments -WorkingDirectory $AgentDir

    # A running agent (update) would keep the old version; the task never starts a second instance.
    Stop-ScheduledTask -TaskName "Laravel-MDM-Agent" -ErrorAction SilentlyContinue
    Register-ScheduledTask -TaskName "Laravel-MDM-Agent" -Trigger @($Trigger1, $Trigger2) -Settings $Settings -User "NT AUTHORITY\SYSTEM" -Action $Action -RunLevel Highest -Force | Out-Null
    Start-ScheduledTask -TaskName "Laravel-MDM-Agent"
}

function Restart-AgentTask {
    # Starts the agent again once this instance has exited (the caller exits right after). With
    # -Watch (agent update) it also rolls the update back: when the new agent has not reported to
    # the server within 5 minutes (agent-update.json is still there), app.ps1.previous is restored.
    # On Windows the task ignores a start while it runs and a process started from it counts as part
    # of it, so a separate one-off task does this; otherwise only the 15 minute watchdog would start
    # the new agent. On Linux systemd restarts the agent, the watcher runs in its own unit.
    param ([switch]$Watch)

    $dir = $AgentDir.Replace("'", "''")
    $script = @"
`$ErrorActionPreference = 'SilentlyContinue'
`$dir = '$dir'
`$onLinux = `$$([bool]$OnLinux)
function Write-Log(`$message) { '{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), `$message | Add-Content -Path "`$dir/agent.log" -Encoding UTF8 }
function Start-AgentNow {
    if (`$onLinux) { systemctl start laravel-mdm-agent.service; return }
    for (`$i = 0; `$i -lt 60; `$i++) {
        if ((Get-ScheduledTask -TaskName 'Laravel-MDM-Agent').State -eq 'Running') { return }
        Start-ScheduledTask -TaskName 'Laravel-MDM-Agent'
        Start-Sleep -Seconds 2
    }
    Write-Log 'Restart: the agent task did not start'
}
Wait-Process -Id $PID -Timeout 120
Start-AgentNow
if (`$$([bool]$Watch) -and (Test-Path -Path "`$dir/app.ps1.previous")) {
    `$deadline = (Get-Date).AddMinutes(5)
    while ((Get-Date) -lt `$deadline -and (Test-Path -Path "`$dir/agent-update.json")) { Start-Sleep -Seconds 5 }
    if (Test-Path -Path "`$dir/agent-update.json") {
        Write-Log 'Agent update: the new version did not report to the server within 5 minutes, rolling back'
        if (`$onLinux) { systemctl stop laravel-mdm-agent.service } else { Stop-ScheduledTask -TaskName 'Laravel-MDM-Agent'; Start-Sleep -Seconds 3 }
        Copy-Item -Path "`$dir/app.ps1.previous" -Destination "`$dir/app.ps1" -Force
        `$pending = Get-Content -Path "`$dir/pending-command.json" -Raw -Encoding UTF8 | ConvertFrom-Json
        if (`$pending) {
            `$pending | Add-Member -NotePropertyName rolled_back -NotePropertyValue (Get-Content -Path "`$dir/agent-update.json" -Raw -Encoding UTF8 | ConvertFrom-Json).to -Force
            `$pending | ConvertTo-Json -Compress | Set-Content -Path "`$dir/pending-command.json" -Encoding UTF8
        }
        Remove-Item -Path "`$dir/agent-update.json" -Force
        Start-AgentNow
    }
}
if (-not `$onLinux) { Unregister-ScheduledTask -TaskName 'Laravel-MDM-Agent-Restart' -Confirm:`$false }
"@
    $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($script))
    if ($OnLinux) {
        if (-not $Watch) { return }
        $pwsh = (Get-Process -Id $PID).Path
        # Not in the agent's cgroup: systemd would stop it together with the agent.
        systemd-run --unit="laravel-mdm-agent-restart-$PID" --collect --quiet $pwsh -NoProfile -NonInteractive -EncodedCommand $encoded 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-AgentLog 'Agent update: systemd-run failed, no rollback watcher' }
        return
    }
    try {
        $action = New-ScheduledTaskAction -Execute 'PowerShell.exe' -Argument "-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -EncodedCommand $encoded"
        $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
        Register-ScheduledTask -TaskName 'Laravel-MDM-Agent-Restart' -Action $action -Settings $settings -User 'NT AUTHORITY\SYSTEM' -RunLevel Highest -Force -ErrorAction Stop | Out-Null
        Start-ScheduledTask -TaskName 'Laravel-MDM-Agent-Restart' -ErrorAction Stop
    }
    catch {
        Write-AgentLog "Restart task not available ($($_.Exception.Message)), restarting directly"
        Start-Process -FilePath powershell.exe -WindowStyle Hidden -ArgumentList "-NoProfile -ExecutionPolicy Bypass -EncodedCommand $encoded"
    }
}

function Start-AgentJob {
    # Start-Job with the given agent functions defined in the job. Windows PowerShell passes an
    # -InitializationScript on the command line of the job process (32767 characters at most), so
    # the definitions go with the arguments instead, through the pipe to the job.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Name,
        [Parameter(Mandatory = $true)]
        [string[]]
        $Functions,
        [Parameter(Mandatory = $true)]
        [scriptblock]
        $ScriptBlock,
        [object[]]
        $ArgumentList = @()
    )

    $definitions = ($Functions | ForEach-Object { "function $_ {$((Get-Item -Path "function:$_").ScriptBlock)}" }) -join "`n"
    return Start-Job -Name $Name -ArgumentList (@($definitions, "$ScriptBlock") + $ArgumentList) -ScriptBlock {
        param ($Definitions, $Body)
        . ([scriptblock]::Create($Definitions))
        & ([scriptblock]::Create($Body)) @args
    }
}

function Start-InventoryCollection {
    # Windows Update search and winget are expensive, run them rarely in a separate idle-priority process.
    return Start-AgentJob -Name 'inventory' -Functions 'Get-WingetSoftware', 'Get-WindowsUpdate', 'Get-AptUpdates', 'Get-UserCommand', 'Get-FlatpakUpdates', 'Get-SnapUpdates', 'Get-PowerShellReleaseUpdate', 'ConvertFrom-WingetTable', 'Get-WingetPath', 'Get-PowerShellHosts', 'Invoke-PowerShellModules' -ArgumentList $OnLinux -ScriptBlock {
        param ($OnLinux)
        try { [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle } catch { }
        $data = @{}
        try { $data['module_updates'] = @(Invoke-PowerShellModules -OnLinux $OnLinux | Where-Object { $_.Version }) } catch { }
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
    return Start-AgentJob -Name 'health' -Functions 'Get-DiskHealth', 'Get-LinuxDiskHealth' -ArgumentList $OnLinux, $Previous -ScriptBlock {
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
    $config = Get-AgentConfig
    $data.machine | Add-Member -NotePropertyName ScriptsEnabled -NotePropertyValue ([bool]$config['scripts_enabled']) -Force
    $data.machine | Add-Member -NotePropertyName ServerKeyFingerprint -NotePropertyValue $config['server_key'].fingerprint -Force
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
    # Commands returned here were not delivered over the WebSocket (the server hands them over once).
    Invoke-DeviceCommands -Response $response
    if ($response.scripts_pending) {
        $script:ScriptsRequested = $true
    }
    if ($response.PSObject.Properties['ping_targets']) {
        Set-PingTargets -Targets $response.ping_targets
    }
}

function Set-PingTargets {
    # Ping-only devices of this network the server asks this agent to ping: an id and an IPv4
    # address each, checked again here (at most 32).
    param ($Targets)

    $script:PingTargets = @(@($Targets) | Where-Object { $_ } | ForEach-Object {
            $address = $null
            if ("$($_.id)" -match '^[0-9]{1,10}$' -and [System.Net.IPAddress]::TryParse("$($_.address)", [ref]$address) -and $address.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork) {
                @{ Id = [long]$_.id; Address = $address }
            }
        } | Select-Object -First 32)
}

function Send-PingResults {
    # Pings the ping-only devices (all at once, 1 s timeout) and reports which answered: the
    # answer is their online status in the portal.
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    if (-not $script:PingTargets -or $script:PingTargets.Count -eq 0) {
        return
    }
    $pings = @($script:PingTargets | ForEach-Object {
            $ping = New-Object System.Net.NetworkInformation.Ping
            @{ Id = $_.Id; Ping = $ping; Task = $ping.SendPingAsync($_.Address, 1000) }
        })
    try { [void][System.Threading.Tasks.Task]::WaitAll([System.Threading.Tasks.Task[]]@($pings | ForEach-Object { $_.Task }), 3000) } catch { }
    $results = @($pings | ForEach-Object {
            $reply = if ($_.Task.Status -eq 'RanToCompletion') { $_.Task.Result } else { $null }
            $up = $reply -and $reply.Status -eq [System.Net.NetworkInformation.IPStatus]::Success
            $_.Ping.Dispose()
            @{ id = $_.Id; up = [bool]$up; rtt = $(if ($up) { [double]$reply.RoundtripTime } else { $null }) }
        })
    try {
        $response = Invoke-MdmApi -Method Post -Path 'device/pings' -Token $Token -Body @{ results = $results }
        if ($response.PSObject.Properties['ping_targets']) {
            Set-PingTargets -Targets $response.ping_targets
        }
    }
    catch {
        Write-AgentLog "Ping results not sent: $($_.Exception.Message)"
    }
}

function Update-Agent {
    # Replace the installed script with the version the server serves, then restart.
    # The command carries no data: the source is always the -ServerUrl set at install time.
    param (
        $CommandId
    )

    $server = [Uri]$ServerUrl
    if ($server.Scheme -ne 'https' -and -not $server.IsLoopback) {
        Write-AgentLog 'Agent update refused: the server is not reached over HTTPS'
        [void](Send-CommandStatus -Id $CommandId -Status failed -Message 'Refused: the server is not reached over HTTPS')
        return
    }
    [void](Send-CommandStatus -Id $CommandId -Status running -Progress 10 -Message 'Downloading the agent')

    # Downloaded into memory and checked there: the bytes that are verified are the ones written.
    try {
        $client = Get-HttpClient
        $base = "$($ServerUrl.TrimEnd('/'))/agent/app.ps1"
        $bytes = $client.GetByteArrayAsync($base).GetAwaiter().GetResult()
        $signature = $client.GetStringAsync("$base.sig").GetAwaiter().GetResult().Trim()
        if (-not (Test-ServerSignature -Context 'MDM1-AGENT' -Message (Get-Sha256Hex -Bytes $bytes) -Signature $signature)) {
            throw 'the downloaded agent is not signed with the pinned server key'
        }

        $text = [System.Text.Encoding]::UTF8.GetString($bytes)
        $errors = $null
        [System.Management.Automation.Language.Parser]::ParseInput($text, [ref]$null, [ref]$errors) | Out-Null
        if ($errors -or $text -notmatch '(?m)^function Start-Agent') {
            throw 'the downloaded agent is not valid'
        }

        # Only move forward, an older agent is never installed this way.
        $version = if ($text -match "(?m)^\`$AgentVersion = '([^']+)'") { $Matches[1] } else { $null }
        if (-not $version -or [version]$version -le [version]$AgentVersion) {
            throw "the server offers version '$version', not newer than $AgentVersion"
        }

        # Kept for the rollback (see Restart-AgentTask), confirmed by the new agent once it reached the server.
        Copy-Item -Path (Join-Path $AgentDir 'app.ps1') -Destination (Join-Path $AgentDir 'app.ps1.previous') -Force
        @{ from = $AgentVersion; to = $version; at = (Get-Date).ToString('o') } | ConvertTo-Json -Compress | Set-Content -Path "$AgentDir/agent-update.json" -Encoding UTF8
        [System.IO.File]::WriteAllBytes((Join-Path $AgentDir 'app.ps1'), $bytes)
    }
    catch {
        Write-AgentLog "Agent update failed: $($_.Exception.Message)"
        [void](Send-CommandStatus -Id $CommandId -Status failed -Message $_.Exception.Message)
        return
    }

    Write-AgentLog 'Agent updated, restarting'
    # The new agent reports the result when it runs (see Complete-PendingCommand).
    Save-PendingCommand -Id $CommandId -Command 'updateAgent'
    [void](Send-CommandStatus -Id $CommandId -Status running -Progress 80 -Message 'Restarting the agent')
    # systemd (Restart=always) starts the new version on Linux, the task is started again on Windows.
    Restart-AgentTask -Watch
    exit 0
}

function Send-CommandStatus {
    # Reports what a command does (agents 1.8.0+): running with an optional progress, then
    # succeeded / failed. Problems are only logged, the command itself goes on. Returns $false
    # when the server could not be reached (a finished or unknown command counts as reported).
    param (
        [Parameter(Mandatory = $true)]
        $Id,
        [Parameter(Mandatory = $true)]
        [ValidateSet('running', 'succeeded', 'failed')]
        [string]
        $Status,
        $Progress,
        [string]
        $Message
    )

    if (-not $Id -or -not $script:AgentToken) {
        return $true
    }
    $body = @{ status = $Status }
    if ($null -ne $Progress) { $body['progress'] = [int]$Progress }
    if ($Message) { $body['message'] = $Message.Substring(0, [Math]::Min(1000, $Message.Length)) }
    try {
        Invoke-MdmApi -Method Post -Path "device/commands/$Id" -Token $script:AgentToken -Body $body | Out-Null
        return $true
    }
    catch {
        Write-AgentLog "Command ${Id}: status '$Status' not reported: $($_.Exception.Message)"
        # The server gave the command up meanwhile (not_running) or does not know it.
        return $_.Exception.Data['MdmError'] -in 'not_running', 'Not Found'
    }
}

function Save-PendingCommand {
    # A command that finishes after the agent restarts (restart, agent update): reported by the
    # agent that starts next (see Complete-PendingCommand).
    param ($Id, [string]$Command)

    @{ id = $Id; command = $Command; agent_version = $AgentVersion; at = (Get-Date).ToString('o') } | ConvertTo-Json -Compress | Set-Content -Path "$AgentDir/pending-command.json" -Encoding UTF8
}

function Complete-PendingCommand {
    # Returns $true when there is nothing (left) to report.
    $path = "$AgentDir/pending-command.json"
    if (-not (Test-Path -Path $path)) {
        return $true
    }
    try {
        $pending = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
        $reported = switch ($pending.command) {
            'restart' { Send-CommandStatus -Id $pending.id -Status succeeded -Message 'Restarted' }
            'updateAgent' {
                if ([version]$AgentVersion -gt [version]$pending.agent_version) {
                    $sent = Send-CommandStatus -Id $pending.id -Status succeeded -Message "Updated to $AgentVersion"
                    # The server is reached: no rollback (see Restart-AgentTask).
                    if ($sent) { Remove-Item -Path "$AgentDir/agent-update.json" -Force -ErrorAction SilentlyContinue }
                    $sent
                } elseif ($pending.rolled_back) {
                    Send-CommandStatus -Id $pending.id -Status failed -Message "Rolled back to ${AgentVersion}: version $($pending.rolled_back) did not report to the server within 5 minutes"
                } else {
                    Send-CommandStatus -Id $pending.id -Status failed -Message "Still version $AgentVersion after the update"
                }
            }
            default { $true }
        }
        # Right after a restart the network may not be up yet: tried again later.
        if ($reported -ne $false) {
            Remove-Item -Path $path -Force
            return $true
        }
    }
    catch {
        Write-AgentLog "Pending command: $($_.Exception.Message)"
        Remove-Item -Path $path -Force -ErrorAction SilentlyContinue
        return $true
    }

    return $false
}

function Test-UpdateParams {
    # installUpdate parameters, checked again on the device: only known kinds, ids of the
    # expected form (they are passed as arguments, never as code).
    param ($Params)

    $kind = "$($Params.kind)"
    if (-not $UpdateKinds.ContainsKey($kind) -or "$($Params.id)" -notmatch $UpdateKinds[$kind] -or "$($Params.id)".Length -gt 200) {
        throw "invalid update '$kind' '$($Params.id)'"
    }
    if ($Params.user -and ($kind -notin 'flatpak', 'module' -or "$($Params.user)" -notmatch '^[a-z_][a-z0-9_.\-]{0,31}$')) {
        throw "invalid user '$($Params.user)'"
    }
    if ($kind -eq 'module' -and ("$($Params.edition)" -notin 'Windows PowerShell', 'PowerShell 7' -or "$($Params.version)" -notmatch '^[0-9][0-9A-Za-z.\-]{0,49}$')) {
        throw "invalid module edition or version"
    }
    if ((($kind -in 'windows', 'winget') -and $OnLinux) -or (($kind -in 'apt', 'flatpak', 'snap') -and -not $OnLinux)) {
        throw "'$kind' updates are not available on this platform"
    }

    return @{ kind = $kind; id = "$($Params.id)"; user = "$($Params.user)"; edition = "$($Params.edition)"; version = "$($Params.version)" }
}

function Test-WakeParams {
    # wake parameters, checked again on the device: MAC addresses and IPv4 broadcast addresses only.
    param ($Params)

    $macs = @($Params.macs | ForEach-Object { "$_" } | Where-Object { $_ -match '^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$' })
    $broadcasts = @($Params.broadcasts | ForEach-Object {
            $address = $null
            if ([System.Net.IPAddress]::TryParse("$_", [ref]$address) -and $address.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork) { $address }
        })
    if ($macs.Count -eq 0 -or $macs.Count -gt 8 -or $broadcasts.Count -eq 0 -or $broadcasts.Count -gt 4) {
        throw 'invalid MAC or broadcast addresses'
    }

    return @{ Macs = $macs; Broadcasts = $broadcasts }
}

function Send-MagicPacket {
    # Wake-on-LAN for a device in this network: 6 x 0xFF and 16 x its MAC, as UDP broadcast to
    # ports 9 and 7 of each broadcast address (the network's own one leaves on the right interface).
    param (
        [string[]]
        $Macs,
        [System.Net.IPAddress[]]
        $Broadcasts
    )

    $client = New-Object System.Net.Sockets.UdpClient
    try {
        $client.EnableBroadcast = $true
        foreach ($mac in $Macs) {
            $bytes = [byte[]]($mac -split '[:-]' | ForEach-Object { [Convert]::ToByte($_, 16) })
            $packet = [byte[]](@(0xFF) * 6 + ($bytes * 16))
            foreach ($broadcast in $Broadcasts) {
                foreach ($port in 9, 7) {
                    [void]$client.Send($packet, $packet.Length, (New-Object System.Net.IPEndPoint($broadcast, $port)))
                }
            }
        }
    }
    finally {
        $client.Close()
    }
}

function Save-CommandState {
    # The update being installed, the ones waiting and a waiting agent update, so they go on
    # after the agent restarts (it restarts itself when an update replaced its PowerShell).
    $state = @{
        running = if ($script:UpdateJob) { @{ id = $script:UpdateCommandId } } else { $null }
        queue = @($script:UpdateQueue.ToArray() | ForEach-Object { @{ id = $_.Id; params = $_.Params } })
        deferred_agent_update = $script:DeferredAgentUpdate
    }
    try {
        $state | ConvertTo-Json -Depth 5 -Compress | Set-Content -Path "$AgentDir/commands-state.json" -Encoding UTF8
    }
    catch {
        Write-AgentLog "Command state not saved: $($_.Exception.Message)"
    }
}

function Restore-CommandState {
    # After a restart: the result of the update that was running (written by its job) or that it
    # was interrupted, then the waiting ones are started again.
    $path = "$AgentDir/commands-state.json"
    if (-not (Test-Path -Path $path)) {
        return
    }
    try {
        $state = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        Remove-Item -Path $path -Force -ErrorAction SilentlyContinue
        return
    }
    $resultPath = "$AgentDir/update-result.json"
    if ($state.running -and $state.running.id) {
        $result = try { Get-Content -Path $resultPath -Raw -Encoding UTF8 | ConvertFrom-Json } catch { $null }
        if ($result -and "$($result.CommandId)" -eq "$($state.running.id)") {
            Send-UpdateResult -Id $state.running.id -Result $result
        } else {
            [void](Send-CommandStatus -Id $state.running.id -Status failed -Message 'Interrupted: the agent restarted before the update finished')
        }
    }
    Remove-Item -Path $resultPath -Force -ErrorAction SilentlyContinue
    foreach ($item in @($state.queue)) {
        if (-not $item) { continue }
        $params = $null
        if ($item.params) {
            try { $params = Test-UpdateParams -Params $item.params } catch { [void](Send-CommandStatus -Id $item.id -Status failed -Message "Refused by the agent: $($_.Exception.Message)"); continue }
        }
        $script:UpdateQueue.Enqueue(@{ Id = $item.id; Params = $params })
    }
    if ($state.deferred_agent_update) {
        $script:DeferredAgentUpdate = $state.deferred_agent_update
    }
    Remove-Item -Path $path -Force -ErrorAction SilentlyContinue
    if ($script:UpdateQueue.Count -gt 0 -or $script:DeferredAgentUpdate) {
        Write-AgentLog "Resuming $($script:UpdateQueue.Count) waiting update(s)$(if ($script:DeferredAgentUpdate) { ' and the agent update' })"
        Start-NextUpdate
    }
}

function Send-UpdateResult {
    param ($Id, $Result)

    $restart = if ($Result.Restart) { ', restart required' } else { '' }
    if (@($Result.Failures).Count -gt 0) {
        [void](Send-CommandStatus -Id $Id -Status failed -Message ((@($Result.Failures) -join '; ') + $restart))
    } else {
        [void](Send-CommandStatus -Id $Id -Status succeeded -Progress 100 -Message "Done$restart")
    }
}

function Start-UpdateJob {
    # Installs OS, package and module updates in the background, or a single one (-Params of
    # installUpdate). Every step is logged to agent.log when the job ends (see Complete-UpdateJob),
    # the full tool output to updates.log; the progress goes to update-progress.json.
    param (
        $CommandId,
        [hashtable]
        $Params
    )

    $script:UpdateJobStarted = Get-Date
    $script:UpdateCommandId = $CommandId
    $script:UpdateProgress = $null
    $progressFile = "$AgentDir/update-progress.json"
    Remove-Item -Path $progressFile -Force -ErrorAction SilentlyContinue
    [void](Send-CommandStatus -Id $CommandId -Status running -Progress 0 -Message 'Starting')
    $script:UpdateJob = Start-AgentJob -Name 'updates' -Functions 'Install-WindowsUpdate', 'Install-PowerShellRelease', 'Get-PowerShellReleaseUpdate', 'Get-WingetPath', 'Get-WingetSoftware', 'ConvertFrom-WingetTable', 'Get-PowerShellHosts', 'Invoke-PowerShellModules', 'Get-UserCommand' -ArgumentList $OnLinux, "$AgentDir/updates.log", $progressFile, $Params, $CommandId, "$AgentDir/update-result.json" -ScriptBlock {
        param ($OnLinux, $OutputLog, $ProgressFile, $Params, $CommandId, $ResultFile)

        # When an update replaces the PowerShell the agent runs in (apt, a GitHub release, snap,
        # winget), the agent has to start again in the new one: compared at the end.
        $runtime = @('pwsh.dll', 'System.Management.Automation.dll') | ForEach-Object { Join-Path $PSHOME $_ } | Where-Object { Test-Path -Path $_ } | Select-Object -First 1
        $runtimeBefore = if ($runtime) { $item = Get-Item -Path $runtime; "$($item.Length)|$($item.LastWriteTimeUtc.Ticks)" }

        if ((Test-Path -Path $OutputLog) -and (Get-Item -Path $OutputLog).Length -gt 2MB) {
            Move-Item -Path $OutputLog -Destination "$OutputLog.1" -Force
        }
        $state = @{ Failures = [System.Collections.ArrayList]@(); Base = 0; Span = 100; Last = $null }
        function Set-Progress ([int]$Percent, [string]$Message) {
            # Percent within the current step (Base .. Base + Span), written for the agent loop.
            $total = [Math]::Min(100, [Math]::Max(0, $state.Base + [int]($state.Span * $Percent / 100)))
            $key = "$total|$Message"
            if ($key -eq $state.Last) { return }
            $state.Last = $key
            $temp = "$ProgressFile.tmp"
            @{ progress = $total; message = $Message } | ConvertTo-Json -Compress | Set-Content -Path $temp -Encoding UTF8
            Move-Item -Path $temp -Destination $ProgressFile -Force
        }
        function Enter-Step ([int]$From, [int]$To, [string]$Message) {
            $state.Base = $From
            $state.Span = $To - $From
            Set-Progress 0 $Message
        }
        function Invoke-Logged ([string]$Title, [scriptblock]$Command, [scriptblock]$OnLine) {
            # Runs a native command, keeps its whole output in updates.log and returns it.
            # -OnLine sees each line as it comes (progress); it returns $true for lines to drop.
            "===== {0:yyyy-MM-dd HH:mm:ss} $Title" -f (Get-Date) | Add-Content -Path $OutputLog -Encoding UTF8
            $output = @(& $Command 2>&1 | ForEach-Object {
                $line = "$_"
                if (-not ($OnLine -and (& $OnLine $line))) { $line }
            })
            $output | Add-Content -Path $OutputLog -Encoding UTF8
            "exit code $LASTEXITCODE" | Add-Content -Path $OutputLog -Encoding UTF8
            return , $output
        }
        function Get-Tail ($Lines) { (@($Lines | Where-Object { $_.Trim() }) | Select-Object -Last 3) -join ' | ' }
        function Add-Result ([string]$Name, [int]$Code, $Output, [int[]]$Ok = @(0)) {
            if ($Ok -notcontains $Code) {
                [void]$state.Failures.Add("${Name}: exit $Code$(if ($Output) { ', ' + (Get-Tail $Output) })")
            }
        }
        # apt progress (APT::Status-Fd=1): "dlstatus:..:percent:text" while downloading (first
        # third of the step), "pmstatus:..:percent:text" while installing.
        $aptProgress = {
            param ($Line)
            if ($Line -match '^(dlstatus|pmstatus):[^:]*:([\d.]+):(.*)$') {
                $percent = [double]$Matches[2]
                $overall = if ($Matches[1] -eq 'dlstatus') { $percent / 3 } else { 33 + $percent * 2 / 3 }
                Set-Progress ([int]$overall) $Matches[3]
                return $true
            }
            return $false
        }
        $wingetOk = @(0, -1978335189) # APPINSTALLER_CLI_ERROR_UPDATE_NOT_APPLICABLE: nothing to update
        # One package, killed with its installer after the timeout: an installer that waits for a
        # window nobody sees (SYSTEM has no desktop) or for an application to close would
        # otherwise hang the whole update forever. Returns the output; $LASTEXITCODE is set.
        $wingetTimeout = 900
        function Invoke-WingetUpgrade ([string]$Winget, [string]$Id, [string]$Source) {
            "===== {0:yyyy-MM-dd HH:mm:ss} winget upgrade $Id" -f (Get-Date) | Add-Content -Path $OutputLog -Encoding UTF8
            $out = [System.IO.Path]::GetTempFileName()
            $err = [System.IO.Path]::GetTempFileName()
            $arguments = @('upgrade', '--id', $Id, '--exact', '--silent', '--accept-source-agreements', '--accept-package-agreements', '--disable-interactivity')
            if ($Source -in 'winget', 'msstore') { $arguments += @('--source', $Source) }
            try {
                $process = Start-Process -FilePath $Winget -ArgumentList $arguments -NoNewWindow -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
                $null = $process.Handle # without it ExitCode stays empty in Windows PowerShell
                if ($process.WaitForExit($wingetTimeout * 1000)) {
                    $code = $process.ExitCode
                } else {
                    # The installer is a child of winget: end the whole tree.
                    & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null
                    $code = -1
                }
                $output = @(Get-Content -Path $out, $err -Encoding UTF8 -ErrorAction SilentlyContinue | Where-Object { $_ -match '\S' -and $_ -notmatch '^[\s\-\\|/█▒]*$' })
                if ($code -eq -1) { $output += "Timed out after $([int]($wingetTimeout / 60)) min, the installer was ended" }
            }
            finally {
                Remove-Item -Path $out, $err -Force -ErrorAction SilentlyContinue
            }
            $output | Add-Content -Path $OutputLog -Encoding UTF8
            "exit code $code" | Add-Content -Path $OutputLog -Encoding UTF8
            $global:LASTEXITCODE = $code
            return , $output
        }
        $env:DEBIAN_FRONTEND = 'noninteractive'
        # Wait for a running apt / unattended-upgrades instead of failing on its lock.
        $lock = '-o', 'DPkg::Lock::Timeout=600'
        $aptOptions = @('-y', '-q', '-o', 'APT::Status-Fd=1', '-o', 'Dpkg::Options::=--force-confdef', '-o', 'Dpkg::Options::=--force-confold')

        if ($Params) {
            # One update (installUpdate); the parameters were checked by Test-UpdateParams.
            $id = $Params.id
            switch ($Params.kind) {
                'windows' {
                    try { Install-WindowsUpdate -UpdateId $id -OnProgress { param ($p, $m) Set-Progress $p $m } -State $state } catch { [void]$state.Failures.Add("Windows Update: $($_.Exception.Message)") }
                }
                'winget' {
                    $winget = Get-WingetPath
                    if (-not $winget) { [void]$state.Failures.Add('winget not found'); break }
                    Set-Progress 10 "winget upgrade $id"
                    $output = Invoke-WingetUpgrade -Winget $winget -Id $id
                    "winget upgrade ${id}: exit $LASTEXITCODE"
                    Add-Result "winget upgrade $id" $LASTEXITCODE $output $wingetOk
                }
                'apt' {
                    Enter-Step 0 10 'apt-get update'
                    $output = Invoke-Logged 'apt-get update' { apt-get @lock -q update }
                    "apt-get update: exit $LASTEXITCODE"
                    Enter-Step 10 100 "apt-get install $id"
                    $output = Invoke-Logged "apt-get install --only-upgrade $id" { apt-get @lock @aptOptions install --only-upgrade $id } $aptProgress
                    "apt-get install --only-upgrade ${id}: exit $LASTEXITCODE"
                    Add-Result "apt-get install $id" $LASTEXITCODE $output
                }
                'flatpak' {
                    Set-Progress 10 "flatpak update $id"
                    if ($Params.user) {
                        $asUser = Get-UserCommand -User $Params.user
                        if (-not $asUser) { [void]$state.Failures.Add("user $($Params.user) not found"); break }
                        $output = Invoke-Logged "flatpak update $id ($($Params.user))" { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) flatpak update --user -y --noninteractive $id }
                    } else {
                        $output = Invoke-Logged "flatpak update $id" { flatpak update --system -y --noninteractive $id }
                    }
                    "flatpak update ${id}: exit $LASTEXITCODE"
                    Add-Result "flatpak update $id" $LASTEXITCODE $output
                }
                'snap' {
                    Set-Progress 10 "snap refresh $id"
                    $output = Invoke-Logged "snap refresh $id" { snap refresh $id }
                    "snap refresh ${id}: exit $LASTEXITCODE"
                    Add-Result "snap refresh $id" $LASTEXITCODE $output
                }
                'pwsh' {
                    Set-Progress 10 "PowerShell $id"
                    Install-PowerShellRelease -Version $id -OnLinux $OnLinux -State $state
                }
                'module' {
                    Set-Progress 10 "Update-Module $id"
                    if (-not @(Get-PowerShellHosts -OnLinux $OnLinux | Where-Object { $_.Edition -eq $Params.edition })) { [void]$state.Failures.Add("$($Params.edition) is not installed"); break }
                    try {
                        $failed = @(Invoke-PowerShellModules -OnLinux $OnLinux -Update -Names @($id) -Edition $Params.edition -User $Params.user -Version $Params.version -OnProgress { param ($p, $m) Set-Progress $p $m })
                        foreach ($module in $failed) {
                            $reason = if ($module.Error) { $module.Error } else { 'not updated' }
                            [void]$state.Failures.Add("PowerShell module $($module.Name): $reason")
                        }
                        if (-not $failed) { "PowerShell module ${id}: updated" }
                    }
                    catch {
                        [void]$state.Failures.Add("PowerShell module ${id}: $($_.Exception.Message)")
                    }
                }
            }
        } elseif ($OnLinux) {
            Enter-Step 0 5 'apt-get update'
            $output = Invoke-Logged 'apt-get update' { apt-get @lock -q update }
            "apt-get update: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"

            # --with-new-pkgs installs new dependencies (plain upgrade keeps such packages back),
            # nothing is removed; existing configuration files are kept.
            Enter-Step 5 70 'apt-get upgrade'
            $output = Invoke-Logged 'apt-get upgrade' { apt-get @lock @aptOptions --with-new-pkgs upgrade } $aptProgress
            $code = $LASTEXITCODE
            $summary = $output | Where-Object { $_ -match '\d+ upgraded, \d+ newly installed' } | Select-Object -Last 1
            "apt-get upgrade: exit $code$(if ($summary) { ', ' + $summary.Trim() })$(if ($code) { ': ' + (Get-Tail $output) })"
            Add-Result 'apt-get upgrade' $code $output
            $keptBack = $false
            $held = foreach ($line in $output) {
                # apt 2: "... kept back:" / "... deferred due to phasing:", apt 3: "Not upgrading ...:"
                if ($line -match '(kept back|phasing|Not upgrading).*:\s*$') { $keptBack = $true; continue }
                if ($keptBack -and $line -match '^\s+\S') { $line.Trim() } elseif ($keptBack) { $keptBack = $false }
            }
            if ($held) { "apt-get upgrade: kept back (phased or held): $($held -join ' ')" }
            Enter-Step 70 80 'flatpak update'
            if (Get-Command -Name flatpak -CommandType Application -ErrorAction SilentlyContinue) {
                $output = Invoke-Logged 'flatpak update' { flatpak update --system -y --noninteractive }
                "flatpak update (system): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                Add-Result 'flatpak update' $LASTEXITCODE $output
                foreach ($userHome in @(Get-ChildItem -Path /home -Directory -ErrorAction SilentlyContinue | Where-Object { Test-Path -Path "$($_.FullName)/.local/share/flatpak" })) {
                    $user = $userHome.Name
                    $asUser = Get-UserCommand -User $user
                    if (-not $asUser) { continue }
                    $output = Invoke-Logged "flatpak update ($user)" { & $asUser[0] @($asUser[1..($asUser.Count - 1)]) flatpak update --user -y --noninteractive }
                    "flatpak update ($user): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                    Add-Result "flatpak update ($user)" $LASTEXITCODE $output
                }
            }
            Enter-Step 80 90 'snap refresh'
            if (Get-Command -Name snap -CommandType Application -ErrorAction SilentlyContinue) {
                $output = Invoke-Logged 'snap refresh' { snap refresh }
                "snap refresh: exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                Add-Result 'snap refresh' $LASTEXITCODE $output
            }
        } else {
            Enter-Step 0 40 'winget'
            $winget = Get-WingetPath
            if ($winget) {
                # One package at a time instead of "upgrade --all": each has a timeout, one that
                # hangs or fails does not stop the others. winget also updates PowerShell 7
                # (Microsoft.PowerShell). App Installer is winget itself: replacing it ends winget.
                $listed = try { @(Get-WingetSoftware -Updatable) } catch { [void]$state.Failures.Add("winget: $($_.Exception.Message)"); @() }
                $packages = @($listed | Where-Object { $_.Id -and $_.Id -ne 'Microsoft.AppInstaller' -and "$($_.Avaliable)" -ne '' })
                $done = 0
                foreach ($package in $packages) {
                    Set-Progress ([int](40 * $done / [Math]::Max(1, $packages.Count))) "winget upgrade $($package.Id) ($($done + 1)/$($packages.Count))"
                    $output = Invoke-WingetUpgrade -Winget $winget -Id $package.Id -Source $package.Source
                    "winget upgrade $($package.Id): exit $LASTEXITCODE$(if ($LASTEXITCODE) { ': ' + (Get-Tail $output) })"
                    Add-Result "winget upgrade $($package.Id)" $LASTEXITCODE $output $wingetOk
                    $done++
                }
                if ($packages.Count -eq 0) { 'winget: nothing to update' }
            } else {
                'winget: not found, application updates skipped'
            }
            Enter-Step 40 90 'Windows Update'
            try { Install-WindowsUpdate -OnProgress { param ($p, $m) Set-Progress $p $m } -State $state } catch { "Windows Update failed: $($_.Exception.Message)"; [void]$state.Failures.Add("Windows Update: $($_.Exception.Message)") }
        }

        if (-not $Params) {
            # A PowerShell 7 release no package manager updated (winget / apt do it themselves).
            Enter-Step 88 90 'PowerShell 7'
            try {
                $release = Get-PowerShellReleaseUpdate -OnLinux $OnLinux -Known @()
                # Package managers ran first: still outdated means none of them manages PowerShell.
                if ($release) {
                    Install-PowerShellRelease -Version $release.Avaliable -OnLinux $OnLinux -State $state
                }
            }
            catch {
                "PowerShell 7: $($_.Exception.Message)"
            }
            Enter-Step 90 100 'PowerShell modules'
            try {
                $failed = @(Invoke-PowerShellModules -OnLinux $OnLinux -Update -OnProgress { param ($p, $m) Set-Progress $p $m })
                if (-not $failed) { 'PowerShell modules: up to date' }
                foreach ($module in $failed) {
                    $owner = if ($module.User) { ", user $($module.User)" } else { '' }
                    $reason = if ($module.Error) { $module.Error } elseif ($module.User) { 'in the user profile, only the user can update it' } else { 'not updated' }
                    "PowerShell module $($module.Name) $($module.Version) -> $($module.Available) ($($module.Edition)$owner): $reason"
                    # Modules only their user can update are no failure of this run.
                    if ($module.Error) { [void]$state.Failures.Add("PowerShell module $($module.Name): $($module.Error)") }
                }
            }
            catch {
                "PowerShell modules failed: $($_.Exception.Message)"
            }
        }
        $restart = if ($OnLinux) { Test-Path -Path /var/run/reboot-required } else { $false }
        if ($restart) { 'Restart required' }

        $runtimeAfter = if ($runtime -and (Test-Path -Path $runtime)) { $item = Get-Item -Path $runtime; "$($item.Length)|$($item.LastWriteTimeUtc.Ticks)" }
        if ($runtime -and $runtimeAfter -ne $runtimeBefore) {
            'PowerShell of the agent was updated, the agent restarts'
            $state.RestartAgent = $true
        }

        # The result for the portal, after the log lines; also in a file, in case the agent is
        # gone (restarted) before it reads the job.
        $result = [pscustomobject]@{ UpdateResult = $true; CommandId = $CommandId; Failures = @($state.Failures); Restart = $restart; RestartAgent = [bool]$state.RestartAgent }
        try { $result | ConvertTo-Json -Depth 4 -Compress | Set-Content -Path $ResultFile -Encoding UTF8 } catch { }
        $result
    }
    Save-CommandState
    Write-AgentLog "Installing updates started$(if ($Params) { " ($($Params.kind) $($Params.id))" }) (details in updates.log)"
}

function Sync-UpdateProgress {
    # Sends the progress the update job wrote, at most every 3 seconds.
    $job = $script:UpdateJob
    if (-not $job -or -not $script:UpdateCommandId -or ((Get-Date) - $script:UpdateProgressSent).TotalSeconds -lt 3) {
        return
    }
    $path = "$AgentDir/update-progress.json"
    try {
        $text = if (Test-Path -Path $path) { Get-Content -Path $path -Raw -Encoding UTF8 } else { $null }
    }
    catch {
        return
    }
    if (-not $text -or $text -eq $script:UpdateProgress) {
        return
    }
    $script:UpdateProgress = $text
    $script:UpdateProgressSent = Get-Date
    $progress = $text | ConvertFrom-Json
    [void](Send-CommandStatus -Id $script:UpdateCommandId -Status running -Progress $progress.progress -Message $progress.message)
}

function Complete-UpdateJob {
    # Logs the finished update job and reports its result; returns $true when it finished, so
    # the inventory is refreshed. Starts the next queued update then.
    $job = $script:UpdateJob
    if (-not $job -or $job.State -eq 'Running') {
        return $false
    }

    $result = $null
    foreach ($line in @(Receive-Job -Job $job -ErrorAction SilentlyContinue 2>&1)) {
        if ($line.PSObject.Properties['UpdateResult']) { $result = $line; continue }
        Write-AgentLog "Updates: $line"
    }
    if ($job.State -ne 'Completed') {
        Write-AgentLog "Updates: job $($job.State): $($job.ChildJobs[0].JobStateInfo.Reason)"
    }
    Write-AgentLog ('Installing updates finished after {0:n0} min' -f ((Get-Date) - $script:UpdateJobStarted).TotalMinutes)
    Remove-Job -Job $job -Force
    Remove-Item -Path "$AgentDir/update-progress.json" -Force -ErrorAction SilentlyContinue
    $script:UpdateJob = $null

    $resultPath = "$AgentDir/update-result.json"
    if (-not $result -and (Test-Path -Path $resultPath)) {
        $saved = try { Get-Content -Path $resultPath -Raw -Encoding UTF8 | ConvertFrom-Json } catch { $null }
        if ($saved -and "$($saved.CommandId)" -eq "$($script:UpdateCommandId)") { $result = $saved }
    }
    Remove-Item -Path $resultPath -Force -ErrorAction SilentlyContinue
    if (-not $result) {
        [void](Send-CommandStatus -Id $script:UpdateCommandId -Status failed -Message "The update job ended unexpectedly ($($job.State))")
    } else {
        Send-UpdateResult -Id $script:UpdateCommandId -Result $result
    }
    $script:UpdateCommandId = $null
    Save-CommandState
    if ($result -and $result.RestartAgent) {
        # The agent runs in the PowerShell that was just replaced: start it again in the new one,
        # the waiting updates go on after the restart (commands-state.json).
        Write-AgentLog 'PowerShell updated, restarting the agent'
        if (-not $OnLinux) {
            Restart-AgentTask
        }
        exit 0
    }
    Start-NextUpdate

    return $true
}

function Start-NextUpdate {
    # Updates run one after another: the next queued one, then a waiting agent update.
    if ($script:UpdateJob) {
        return
    }
    if ($script:UpdateQueue.Count -gt 0) {
        $next = $script:UpdateQueue.Dequeue()
        Start-UpdateJob -CommandId $next.Id -Params $next.Params
        return
    }
    if ($script:DeferredAgentUpdate) {
        $id = $script:DeferredAgentUpdate
        $script:DeferredAgentUpdate = $null
        Save-CommandState
        Update-Agent -CommandId $id
    }
}

function Invoke-PowerAction {
    # Restarts or turns off the device, also while updates are being installed (their job and
    # installers are ended). Returns the reason when it did not work, $null when it is on its way.
    param ([switch]$Restart)

    if ($script:UpdateJob) {
        Write-AgentLog "$(if ($Restart) { 'Restart' } else { 'Turn off' }) while updates are being installed: they are interrupted"
        Save-CommandState
    }
    try {
        if ($OnLinux) {
            $output = if ($Restart) { systemctl reboot 2>&1 } else { systemctl poweroff 2>&1 }
            if ($LASTEXITCODE -ne 0) { throw "systemctl: exit $LASTEXITCODE $output" }
        } elseif ($Restart) {
            Restart-Computer -Force -ErrorAction Stop
        } else {
            Stop-Computer -Force -ErrorAction Stop
        }
        return $null
    }
    catch {
        $first = $_.Exception.Message
    }
    # Second try, forced: the cmdlets refuse while an installation holds the shutdown (Windows) or
    # a unit blocks it (Linux).
    if ($OnLinux) {
        $output = if ($Restart) { systemctl reboot --force 2>&1 } else { systemctl poweroff --force 2>&1 }
    } else {
        $output = & shutdown.exe $(if ($Restart) { '/r' } else { '/s' }) /f /t 0 /d p:4:1 2>&1
    }
    if ($LASTEXITCODE -eq 0) {
        return $null
    }

    return "$first; forced: exit $LASTEXITCODE $output".Trim()
}

function Invoke-DeviceCommand {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Command,
        # Given by servers that track commands: the id the progress and the result are reported with.
        $Id,
        $Params
    )

    if ($AllowedCommands -notcontains $Command) {
        Write-AgentLog "Ignoring unknown command '$Command'"
        [void](Send-CommandStatus -Id $Id -Status failed -Message "Unknown command '$Command'")
        return
    }

    Write-AgentLog "Executing command '$Command'$(if ($Id) { " ($Id)" })"
    switch ($Command) {
        'updateAgent' {
            # Replacing the agent ends the update job: the agent update waits for it.
            if ($script:UpdateJob) {
                $script:DeferredAgentUpdate = $Id
                Save-CommandState
                [void](Send-CommandStatus -Id $Id -Status running -Message 'Waiting for the updates to finish')
                return
            }
            Update-Agent -CommandId $Id
        }
        'runScripts' {
            # Only a trigger: the runs are taken, verified and run by the main loop.
            $script:ScriptsRequested = $true
        }
        'sync' {
            # Everything collected again now (inventory, disk health) and reported right away; the
            # main loop does it (it owns the collection jobs) and reports the result.
            $script:SyncRequest = @{ Id = $Id; Phase = 'requested' }
            [void](Send-CommandStatus -Id $Id -Status running -Progress 5 -Message 'Collecting the inventory')
        }
        { $_ -in 'doUpdates', 'installUpdate' } {
            $updateParams = $null
            if ($Command -eq 'installUpdate') {
                try {
                    $updateParams = Test-UpdateParams -Params $Params
                }
                catch {
                    Write-AgentLog "Update refused: $($_.Exception.Message)"
                    [void](Send-CommandStatus -Id $Id -Status failed -Message "Refused by the agent: $($_.Exception.Message)")
                    return
                }
            }
            if ($script:UpdateJob) {
                if (-not $Id -and -not $updateParams) {
                    Write-AgentLog 'Updates are already being installed, command ignored'
                    return
                }
                $script:UpdateQueue.Enqueue(@{ Id = $Id; Params = $updateParams })
                Save-CommandState
                [void](Send-CommandStatus -Id $Id -Status running -Progress 0 -Message 'Waiting for the updates that are being installed')
                return
            }
            Start-UpdateJob -CommandId $Id -Params $updateParams
        }
        'wake' {
            try {
                $wake = Test-WakeParams -Params $Params
                Send-MagicPacket -Macs $wake.Macs -Broadcasts $wake.Broadcasts
                Write-AgentLog "Magic packet sent to $($wake.Macs -join ', ') via $($wake.Broadcasts -join ', ')"
                [void](Send-CommandStatus -Id $Id -Status succeeded -Message "Magic packet sent to $($wake.Macs -join ', ')")
            }
            catch {
                Write-AgentLog "Wake-on-LAN failed: $($_.Exception.Message)"
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Wake-on-LAN failed: $($_.Exception.Message)")
            }
        }
        'turnOff' {
            [void](Send-CommandStatus -Id $Id -Status running -Message 'Shutting down')
            $failure = Invoke-PowerAction -Restart:$false
            if ($failure) {
                Write-AgentLog "Turn off failed: $failure"
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Turn off failed: $failure")
            } else {
                [void](Send-CommandStatus -Id $Id -Status succeeded -Message 'Shutting down')
            }
        }
        'restart' {
            # Done when the agent is back after the restart.
            Save-PendingCommand -Id $Id -Command 'restart'
            [void](Send-CommandStatus -Id $Id -Status running -Message 'Restarting')
            $failure = Invoke-PowerAction -Restart
            if ($failure) {
                # Not restarting after all: report it now, so it can be tried again.
                Remove-Item -Path "$AgentDir/pending-command.json" -Force -ErrorAction SilentlyContinue
                Write-AgentLog "Restart failed: $failure"
                [void](Send-CommandStatus -Id $Id -Status failed -Message "Restart failed: $failure")
            }
        }
    }
}

function Invoke-DeviceCommands {
    # The commands of a server response: "tasks" (with ids, servers that track commands) or the
    # names in "commands" (older servers).
    param (
        [Parameter(Mandatory = $true)]
        $Response
    )

    if ($Response.PSObject.Properties['tasks']) {
        foreach ($task in @($Response.tasks)) {
            if ($task -and $task.command) {
                Invoke-DeviceCommand -Command $task.command -Id $task.id -Params $task.params
            }
        }
        return
    }
    foreach ($command in @($Response.commands)) {
        if ($command) {
            Invoke-DeviceCommand -Command $command
        }
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
            if ($Message.channel -ne $Realtime.Config.channel) {
                return
            }
            # Only a trigger signed by the server for this device and not older than 5 minutes;
            # the commands themselves come from the signed API, so nothing can be injected or replayed.
            $payload = if ($data.p -is [string]) { $data.p | ConvertFrom-Json } else { $null }
            if (-not (Test-ServerSignature -Context 'MDM1-WS' -Message "$($data.p)" -Signature "$($data.sig)") -or "$($payload.device_id)" -ne "$($script:DeviceId)" -or [Math]::Abs((Get-UnixTime) - [long]$payload.ts) -gt 300) {
                Write-AgentLog 'Ignoring a command event that is not signed by the server for this device'
                return
            }
            $response = Invoke-MdmApi -Method Post -Path 'device/commands/take' -Token $Token -Body @{}
            Invoke-DeviceCommands -Response $response
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
    # Reverb limits messages to 10 kB (with the signature): a large state (many services) goes over HTTPS instead.
    $stateOverWs = $state -and $stateJson.Length -le 7000

    if ($Realtime -and $Realtime.Subscribed) {
        # Signed with the device key, for this device, once (nonce).
        $payload = [ordered]@{ device_id = $script:DeviceId; ts = Get-UnixTime; nonce = New-Nonce; metrics = $metrics; state = $(if ($stateOverWs) { $state } else { $null }) } | ConvertTo-Json -Depth 6 -Compress
        $data = @{ p = $payload; sig = New-DeviceSignature -Context 'MDM1-HB' -Message $payload }
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

    $config = Initialize-ServerKey
    Protect-AgentPath -Path $AgentDir -Directory
    $Token = Get-AgentToken
    $script:AgentToken = $Token
    $script:UpdateQueue = New-Object System.Collections.Queue
    $script:UpdateProgressSent = [DateTime]::MinValue
    $pendingChecked = $false
    $nextPendingCheck = Get-Date
    # Read again: an enrolment just now registered the key.
    $script:KeyRegistered = [bool](Get-AgentConfig)['key_registered']
    Write-AgentLog ('Agent {0} started (PowerShell {1}, {2}, server {3})' -f $AgentVersion, $PSVersionTable.PSVersion, $(if ($OnLinux) { 'Linux' } else { 'Windows' }), $ServerUrl)
    $inventory = Get-CachedInventory
    # First inventory a few minutes after start, so it does not add to the load during boot.
    $nextInventory = if ($inventory -and -not $inventory.Stale) { $inventory.CollectedAt.AddSeconds($InventoryInterval) } elseif ($inventory) { (Get-Date).AddMinutes(1) } else { (Get-Date).AddMinutes(5) }
    $inventoryJob = $null
    # Virtual disks have no S.M.A.R.T. values: no disk health on VMs and containers.
    $virtualization = try { if ($OnLinux) { Get-LinuxVirtualization } else { $system = Get-CimInstance -ClassName Win32_ComputerSystem -Property Manufacturer, Model; Get-Virtualization -Vendor $system.Manufacturer -Product $system.Model } } catch { $null }
    $health = if ($virtualization) { $null } else { Get-CachedInventory -Name 'health' }
    $nextHealth = if ($virtualization) { [DateTime]::MaxValue } elseif ($health -and -not $health.Stale) { $health.CollectedAt.AddSeconds($HealthInterval) } else { (Get-Date).AddMinutes(2) }
    if ($virtualization) {
        Write-AgentLog "Running in a $($virtualization.Type) ($($virtualization.Name)), disk health is not collected"
    }
    $healthJob = $null
    $lastReport = [DateTime]::MinValue
    $realtime = $null
    $nextConnect = Get-Date
    $lastActivity = Get-Date
    $lastHeartbeat = [DateTime]::MinValue
    $lastPackageCheck = [DateTime]::MinValue

    while ($true) {
        try {
            if (-not $script:KeyRegistered) {
                # Updated from an agent that did not sign, or the key was reset in the portal.
                Register-DeviceKey -Token $Token
            }
            if (-not $pendingChecked -and (Get-Date) -ge $nextPendingCheck) {
                # A restart or agent update of the last run is done now.
                $pendingChecked = Complete-PendingCommand
                if ($pendingChecked) { Restore-CommandState }
                $nextPendingCheck = (Get-Date).AddSeconds(30)
            }

            if (-not $Once -and ((Get-Date) - $lastHeartbeat).TotalSeconds -ge $HeartbeatInterval) {
                $lastHeartbeat = Get-Date
                Send-Heartbeat -Realtime $realtime -Token $Token
                Send-PingResults -Token $Token
                $lastActivity = Get-Date
            }

            if ($inventoryJob -and $inventoryJob.State -ne 'Running') {
                if ($inventoryJob.State -eq 'Completed') {
                    $data = Receive-Job -Job $inventoryJob
                    Write-AgentLog ('Inventory collected: {0} OS, {1} application, {2} module update(s)' -f @($data.os_updates).Count, @($data.packages_updates).Count, @($data.module_updates).Count)
                    Save-CachedInventory -Data $data
                    $inventory = @{ CollectedAt = Get-Date; Data = $data }
                    $lastReport = [DateTime]::MinValue
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'collecting') {
                        $script:SyncRequest.InventoryDone = $true
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status running -Progress 60 -Message 'Inventory collected')
                    }
                } else {
                    Write-AgentLog "Inventory collection failed: $($inventoryJob.ChildJobs[0].JobStateInfo.Reason)"
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'collecting') {
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status failed -Message "Inventory collection failed: $($inventoryJob.ChildJobs[0].JobStateInfo.Reason)")
                        $script:SyncRequest = $null
                    }
                }
                Remove-Job -Job $inventoryJob -Force
                $inventoryJob = $null
            }
            Sync-UpdateProgress
            if (Complete-UpdateJob) {
                # Show what is left right away instead of the pre-update list for 6 hours.
                $nextInventory = Get-Date
            }
            try {
                if ($script:ScriptsRequested -and -not $script:CurrentScript -and $script:ScriptQueue.Count -eq 0) {
                    Request-ScriptRuns -Token $Token
                }
                Update-ScriptRun -Token $Token
            }
            catch {
                # Script problems must not tear down the WebSocket connection.
                Write-AgentLog "Scripts: $($_.Exception.Message)"
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
            if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'requested') {
                # Sync: collect everything now. A collection already running is waited for.
                Write-AgentLog 'Sync requested: collecting the inventory and disk health now'
                $script:SyncRequest.Phase = 'collecting'
                $script:SyncRequest.InventoryDone = $false
                if (-not $inventoryJob) { $nextInventory = Get-Date }
                if (-not $virtualization -and -not $healthJob) { $nextHealth = Get-Date }
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

            if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'collecting' -and $script:SyncRequest.InventoryDone -and -not $healthJob) {
                # Collected: the full report goes now.
                $script:SyncRequest.Phase = 'report'
                [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status running -Progress 90 -Message 'Sending the report')
                $lastReport = [DateTime]::MinValue
            }

            if (((Get-Date) - $lastReport).TotalSeconds -ge $ReportInterval) {
                $lastReport = Get-Date
                try {
                    Send-Report -Data (Get-Report -Inventory $inventory.Data -Health $health.Data) -Token $Token
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'report') {
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status succeeded -Progress 100 -Message 'Synced')
                        $script:SyncRequest = $null
                    }
                }
                catch {
                    # HTTP problems must not tear down the WebSocket connection.
                    Write-AgentLog "Report failed: $($_.Exception.Message)"
                    if ($script:SyncRequest -and $script:SyncRequest.Phase -eq 'report') {
                        [void](Send-CommandStatus -Id $script:SyncRequest.Id -Status failed -Message "Report failed: $($_.Exception.Message)")
                        $script:SyncRequest = $null
                    }
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
    $existing = @($(if ($OnLinux) { 'token' } else { 'Token.xml', 'token.dat' }) | Where-Object { Test-Path -Path (Join-Path $AgentDir $_) }).Count -gt 0
    if ($existing) {
        Write-Host "Existing installation found in $AgentDir, updating the agent to $AgentVersion." -ForegroundColor Yellow
    }

    $target = Join-Path $AgentDir 'app.ps1'
    if ($PSCommandPath -ne $target) {
        Copy-Item -Path $PSCommandPath -Destination $target -Force
    }

    Protect-AgentPath -Path $AgentDir -Directory
    $config = Initialize-ServerKey
    if ($DisableScripts -or $EnableScripts) {
        $config['scripts_enabled'] = [bool]$EnableScripts
        Save-AgentConfig -Config $config
    }
    Write-Host "Server key: $($config['server_key'].fingerprint)" -ForegroundColor Yellow
    Write-Host "Remediation scripts: $(if ($config['scripts_enabled']) { 'enabled' } else { 'disabled' }) (config.json)" -ForegroundColor Yellow

    if ($OnLinux) {
        # The service runs as root like this installer: enrol right away.
        Get-AgentToken | Out-Null
        Register-AgentService
    } else {
        # The task runs as SYSTEM, which cannot read what this administrator encrypts: the agent
        # enrols (token, device key) itself on its first start, this only hands over the code or
        # the token of an older installation.
        $userPath = Join-Path $AgentDir 'Token.xml'
        if (Test-Path -Path $userPath) {
            try {
                Set-Content -Path (Join-Path $AgentDir 'token.dat') -Value (Protect-MachineSecret -Text (Read-UserToken -Path $userPath)) -NoNewline
                Protect-AgentPath -Path (Join-Path $AgentDir 'token.dat')
                Remove-Item -Path $userPath -Force
                Write-Host 'The device token of the older installation is handed over to the SYSTEM task.' -ForegroundColor Yellow
            }
            catch {
                # Already written by the SYSTEM task, nothing to hand over.
            }
        } elseif (-not $existing) {
            if (-not $EnrolmentCode) {
                Write-Host 'An enrolment code is needed (-EnrolmentCode, see Add device in the portal).' -ForegroundColor Red
                exit 1
            }
            $config['enrolment_code'] = "$EnrolmentCode"
            Save-AgentConfig -Config $config
        }

        $since = '{0:yyyy-MM-dd HH:mm:ss}' -f (Get-Date)
        Register-AgentTask

        # Wait for the first start of the task to report how it went (log lines of this run only).
        $log = Join-Path $AgentDir 'agent.log'
        $deadline = (Get-Date).AddSeconds(90)
        $started = $false
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Seconds 2
            $lines = @(if (Test-Path -Path $log) { Get-Content -Path $log -Tail 50 | Where-Object { $_.Length -ge 19 -and $_.Substring(0, 19) -ge $since } })
            if ($lines -match 'Agent stopped: ') {
                Write-Host "The agent failed to start: $(($lines -match 'Agent stopped: ') | Select-Object -Last 1)" -ForegroundColor Red
                Remove-TemporaryInstaller
                exit 1
            }
            if ($lines -match "Agent $([regex]::Escape($AgentVersion)) started") {
                $started = $true
                break
            }
        }
        if (-not $started) {
            Write-Host "The agent did not report its start within 90 s, see $log and the task Laravel-MDM-Agent in Task Scheduler." -ForegroundColor Yellow
        }
    }

    Remove-TemporaryInstaller

    $action = if ($existing) { 'updated' } else { 'installed' }
    Write-Host "Laravel-MDM agent $AgentVersion $action in $AgentDir and started." -ForegroundColor Green
    return
}

try {
    Start-Agent
}
catch {
    # Errors before the loop (token, keys, configuration) would end the task without a trace.
    try { Write-AgentLog "Agent stopped: $($_.Exception.Message)" } catch { }
    exit 1
}
