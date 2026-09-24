<#
.SYNOPSIS
    Laravel-MDM agent for Windows.

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

.PARAMETER NoRealtime
    Do not use the WebSocket, rely on HTTP only.

.EXAMPLE
    # Enrol the device and register the agent as a scheduled task running as SYSTEM
    .\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install

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
    $NoRealtime
)

$ErrorActionPreference = 'Stop'
$AllowedCommands = @('turnOff', 'restart', 'doUpdates')

function Get-MachineInfo {
    $DnsInfo = [System.Net.Dns]::GetHostByName($env:computerName)
    $OperatingSystem = Get-CimInstance -ClassName Win32_OperatingSystem -Property Caption, Version, LastBootUpTime
    [PSCustomObject] @{
        Hostname        = $DnsInfo.HostName
        User            = $env:USERNAME
        os              = "$($OperatingSystem.Caption) ($($OperatingSystem.Version))"
        uptime          = [int]((Get-Date) - $OperatingSystem.LastBootUpTime).TotalSeconds
        last_logon_user = (Get-CimInstance -ClassName Win32_ComputerSystem -Property UserName).UserName
        Processor       = (Get-ItemProperty -Path 'HKLM:\HARDWARE\DESCRIPTION\System\CentralProcessor\0' -Name ProcessorNameString -ErrorAction SilentlyContinue).ProcessorNameString
        Cores           = [Environment]::ProcessorCount
        Battery         = (Get-CimInstance -ClassName Win32_Battery -Property EstimatedChargeRemaining).EstimatedChargeRemaining
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
    $Session = New-Object -ComObject Microsoft.Update.Session
    $Updates = $Session.CreateUpdateSearcher().Search("IsInstalled=0 and Type='Software' and IsHidden=0").Updates
    if ($Updates.Count -eq 0) {
        return
    }

    $Downloader = $Session.CreateUpdateDownloader()
    $Downloader.Updates = $Updates
    $Downloader.Download() | Out-Null

    $Installer = $Session.CreateUpdateInstaller()
    $Installer.Updates = $Updates
    $Installer.Install() | Out-Null
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

#region Agent

function Write-AgentLog {
    param (
        [Parameter(Mandatory = $true)]
        [string]
        $Message
    )

    $LogPath = "$PSScriptRoot\agent.log"
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
    $AuthFilePath = "$PSScriptRoot\Token.xml"
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

function Register-AgentTask {
    # Starts the agent at boot; the repetition acts as a watchdog, a running instance is not started twice.
    $Trigger1 = New-ScheduledTaskTrigger -AtStartup
    $Trigger2 = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15)
    $Settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $arguments = '-WindowStyle Hidden -ExecutionPolicy Bypass -NoLogo -File "{0}\app.ps1" -ServerUrl "{1}" -ReportInterval {2} -HeartbeatInterval {3} -InventoryInterval {4}' -f $PSScriptRoot, $ServerUrl, $ReportInterval, $HeartbeatInterval, $InventoryInterval
    if ($ReverbHost) { $arguments += ' -ReverbHost "{0}"' -f $ReverbHost }
    if ($ReverbPort) { $arguments += ' -ReverbPort {0}' -f $ReverbPort }
    if ($ReverbScheme) { $arguments += ' -ReverbScheme {0}' -f $ReverbScheme }
    if ($ReverbKey) { $arguments += ' -ReverbKey "{0}"' -f $ReverbKey }
    if ($NoRealtime) { $arguments += ' -NoRealtime' }
    $Action = New-ScheduledTaskAction -Execute "PowerShell.exe" -Argument $arguments

    Register-ScheduledTask -TaskName "Laravel-MDM-Agent" -Trigger @($Trigger1, $Trigger2) -Settings $Settings -User "NT AUTHORITY\SYSTEM" -Action $Action -RunLevel Highest -Force | Out-Null
    Start-ScheduledTask -TaskName "Laravel-MDM-Agent"
}

function Start-InventoryCollection {
    # Windows Update search and winget are expensive, run them rarely in a separate idle-priority process.
    $init = [scriptblock]::Create(@"
    function Get-WingetSoftware {${function:Get-WingetSoftware}}
    function Get-WindowsUpdate {${function:Get-WindowsUpdate}}
"@)

    return Start-Job -Name 'inventory' -InitializationScript $init -ScriptBlock {
        [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::Idle
        $data = @{}
        try { $data['os_updates'] = @(Get-WindowsUpdate) } catch { }
        try { $data['packages_updates'] = @(Get-WingetSoftware -Updatable | Select-Object -Property Id, Version, Avaliable, Source) } catch { }
        return $data
    }
}

function Get-CachedInventory {
    $path = "$PSScriptRoot\inventory.json"
    if (-not (Test-Path -Path $path)) {
        return $null
    }

    try {
        $cache = Get-Content -Path $path -Raw -Encoding UTF8 | ConvertFrom-Json
        return @{ CollectedAt = [DateTime]$cache.collected_at; Data = $cache.data }
    }
    catch {
        return $null
    }
}

function Save-CachedInventory {
    param (
        [Parameter(Mandatory = $true)]
        $Data
    )

    @{ collected_at = (Get-Date).ToString('o'); data = $Data } | ConvertTo-Json -Depth 6 -Compress | Set-Content -Path "$PSScriptRoot\inventory.json" -Encoding UTF8
}

function Get-Report {
    param (
        $Inventory
    )

    $data = @{ machine = Get-MachineInfo }
    if ($Inventory) {
        $data['os_updates'] = $Inventory.os_updates
        $data['packages_updates'] = $Inventory.packages_updates
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
    switch ($Command) {
        'turnOff' { Stop-Computer -Force }
        'restart' { Restart-Computer -Force }
        'doUpdates' {
            $init = [scriptblock]::Create("function Install-WindowsUpdate {${function:Install-WindowsUpdate}}")
            Start-Job -Name 'updates' -InitializationScript $init -ScriptBlock {
                try { winget upgrade --all --silent --accept-source-agreements --accept-package-agreements | Out-Null } catch { }
                Install-WindowsUpdate
            } | Out-Null
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

function Get-SystemMetrics {
    # CPU usage is the average since the previous call (i.e. over the whole heartbeat interval), so nothing is sampled in between.
    try {
        Initialize-SystemMetrics

        $idle = $kernel = $user = [long]0
        [MdmAgent.SystemMetrics]::GetSystemTimes([ref]$idle, [ref]$kernel, [ref]$user) | Out-Null
        $memory = New-Object MdmAgent.SystemMetrics+MemoryStatusEx
        [MdmAgent.SystemMetrics]::GlobalMemoryStatusEx($memory) | Out-Null

        $previous = $script:CpuTimes
        $script:CpuTimes = @{ Idle = $idle; Total = $kernel + $user }
        if (-not $previous) {
            return $null
        }

        $total = $script:CpuTimes.Total - $previous.Total
        $cpu = if ($total -gt 0) { [math]::Round((1 - ($idle - $previous.Idle) / $total) * 100, 1) } else { 0 }

        return @{
            cpu          = [math]::Max(0, $cpu)
            memory_used  = $memory.ullTotalPhys - $memory.ullAvailPhys
            memory_total = $memory.ullTotalPhys
        }
    }
    catch {
        return $null
    }
}

function Send-Heartbeat {
    param (
        $Realtime,
        [Parameter(Mandatory = $true)]
        [string]
        $Token
    )

    $metrics = Get-SystemMetrics
    if ($Realtime -and $Realtime.Subscribed) {
        $data = if ($metrics) { $metrics } else { @{} }
        Send-WsMessage -Socket $Realtime.Socket -Message @{ event = 'client-heartbeat'; channel = $Realtime.Config.channel; data = $data }
    } else {
        try {
            Invoke-MdmApi -Method Post -Path 'device/heartbeat' -Token $Token -Body @{ metrics = $metrics } | Out-Null
        }
        catch {
            Write-AgentLog "Heartbeat failed: $($_.Exception.Message)"
        }
    }
}

function Start-Agent {
    # Keep the agent in the background, user applications take precedence.
    [System.Diagnostics.Process]::GetCurrentProcess().PriorityClass = [System.Diagnostics.ProcessPriorityClass]::BelowNormal

    $Token = Get-AgentToken
    $inventory = Get-CachedInventory
    # First inventory a few minutes after start, so it does not add to the load during boot.
    $nextInventory = if ($inventory) { $inventory.CollectedAt.AddSeconds($InventoryInterval) } else { (Get-Date).AddMinutes(5) }
    $inventoryJob = $null
    $lastReport = [DateTime]::MinValue
    $realtime = $null
    $nextConnect = Get-Date
    $lastActivity = Get-Date
    $lastHeartbeat = [DateTime]::MinValue

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
                    Save-CachedInventory -Data $data
                    $inventory = @{ CollectedAt = Get-Date; Data = $data }
                    $lastReport = [DateTime]::MinValue
                } else {
                    Write-AgentLog "Inventory collection failed: $($inventoryJob.ChildJobs[0].JobStateInfo.Reason)"
                }
                Remove-Job -Job $inventoryJob -Force
                $inventoryJob = $null
            }
            if (-not $inventoryJob -and (Get-Date) -ge $nextInventory) {
                $inventoryJob = Start-InventoryCollection
                $nextInventory = (Get-Date).AddSeconds($InventoryInterval)
            }

            if (((Get-Date) - $lastReport).TotalSeconds -ge $ReportInterval) {
                $lastReport = Get-Date
                try {
                    Send-Report -Data (Get-Report -Inventory $inventory.Data) -Token $Token
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
    Get-AgentToken | Out-Null
    Register-AgentTask
    return
}

Start-Agent
