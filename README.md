# Laravel-MDM (Mobile Device Management)

## Description

A simple system for managing your Windows and Linux (Debian / Ubuntu) computers with a self-hosted
portal and a lightweight agent written in PowerShell.

## Why I wrote this

The answer is simple: over the years, the number of computers I take care of (my girlfriend's
laptop, my work PC, …) kept growing, and it was not always easy to keep track of free disk space
or whether OS updates were installed.

## Screenshots

Device detail with status, commands and CPU/memory history:

![Device detail](docs/screenshots/device.png)

| Pending updates | Enrolling a new device |
|---|---|
| ![Pending updates](docs/screenshots/device-updates.png) | ![Enrolling a new device](docs/screenshots/enrolment.png) |

| Login | Mobile |
|---|---|
| ![Login](docs/screenshots/login.png) | ![Mobile](docs/screenshots/mobile.png) |

## Repository structure

| Path | Content |
|------|---------|
| `src/laravel` | Server application (Laravel) |
| `src/powershell` | Agent (Windows, Debian / Ubuntu) |
| `Dockerfile`, `docker-compose.yml`, `docker/` | Container setup |

## Server setup

```bash
cd src/laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Set `APP_SYSTEM_ADMINS` in `.env` to the IDs of the users who can access the system pages.

Run the scheduler every minute (it removes CPU/RAM history older than 7 days and runs backups;
the Docker image runs it for you):

```cron
* * * * * cd /path/to/src/laravel && php artisan schedule:run >> /dev/null 2>&1
```

### Docker

A small Alpine-based image (running as a non-root user) is built by GitHub Actions and published to
`ghcr.io/gamerclassn7/laravel-mdm-server`. A single container runs everything under supervisord:

| Process | Description | Disable with |
|---------|-------------|--------------|
| nginx + PHP-FPM | Web application on port 8000, migrations run on start | `RUN_MIGRATIONS=false` skips migrations |
| Reverb | WebSocket server, served by nginx on the same port under `/app` | `REVERB_ENABLED=false` |
| Scheduler | `php artisan schedule:work` | `SCHEDULER_ENABLED=false` |

```bash
cp src/laravel/.env.example src/laravel/.env   # set DB_* and REVERB_HOST/PORT/SCHEME
docker compose --env-file src/laravel/.env up -d
```

Only port 8000 is exposed: nginx serves the web and proxies `/app` (agent WebSockets) and `/apps`
(Reverb API used by the application) to Reverb inside the container. Set `REVERB_HOST`, `REVERB_PORT`
and `REVERB_SCHEME` to the public address of the web, e.g. `mdm.example.com`, `443`, `https` behind
a TLS proxy, or `localhost`, `8000`, `http` locally.

On start the container waits for the database and runs the migrations. `APP_KEY`, `REVERB_APP_KEY`
and `REVERB_APP_SECRET` are generated on the first start when they are not set, and kept in
`storage/secrets.env` in the `storage` volume, so they stay the same across restarts and updates.
Values set in the environment always take precedence. Uploaded files and logs are stored in the
`storage` volume. Database backups from the system pages are not supported in the image (no
`mysqldump`).

### Real-time commands (WebSocket)

Commands (turn off, restart, install updates) are pushed to agents instantly over a WebSocket
using [Laravel Reverb](https://laravel.com/docs/reverb). Without it, commands are delivered with
the agent's next periodic report.

1. Set `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env` to random strings.
2. Point `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` to the public address the agents connect to.
3. Keep the Reverb server running, e.g. with Supervisor (the Docker image already does):

   ```bash
   php artisan reverb:start
   ```

4. Without Docker, forward the `/app` (WebSocket) and `/apps` paths to Reverb, e.g. for nginx:

   ```nginx
   location /app/ {
       proxy_http_version 1.1;
       proxy_set_header Host $http_host;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_read_timeout 1h;
       proxy_pass http://127.0.0.1:8080;
   }

   location /apps/ {
       proxy_set_header Host $http_host;
       proxy_pass http://127.0.0.1:8080;
   }
   ```

## Agent

The agent (`src/powershell/app.ps1`) runs on Windows (Windows PowerShell 5.1 or PowerShell 7) and on
Debian / Ubuntu (PowerShell 7). It runs continuously as a scheduled task under `SYSTEM` on Windows and
as a systemd service (`laravel-mdm-agent`) running as root on Linux. It keeps a WebSocket connection
open for commands, sends a heartbeat with CPU and RAM usage every 30 seconds (over the WebSocket, or
over HTTPS when the WebSocket is unavailable) and sends a device report every 5 minutes over HTTPS.
A device without a heartbeat for 90 seconds is shown as offline.

The agent is designed to stay out of the way:

- it runs with below-normal priority (`Nice=10` and idle I/O priority on Linux),
- CPU and RAM usage come from plain Win32 calls (`GetSystemTimes`, `GlobalMemoryStatusEx`) on Windows
  and from `/proc/stat` and `/proc/meminfo` on Linux; CPU usage is the average over the heartbeat
  interval, so nothing is sampled in between,
- the expensive update checks (Windows Update and winget, or the local apt cache on Linux) run every
  6 hours in an idle-priority process; the result is cached in `inventory.json` and reused in reports.

| | Windows | Debian / Ubuntu |
|---|---|---|
| Report | OS, uptime, user, CPU, battery, drives, networks, pending reboot | the same, from `/etc/os-release`, `/proc`, `df`, `ip` and `/var/run/reboot-required` |
| Updates | Windows Update, winget | `apt list --upgradable` |
| Turn off / Restart | `Stop-Computer` / `Restart-Computer` | `systemctl poweroff` / `systemctl reboot` |
| Install updates | winget + Windows Update | `apt-get update && apt-get upgrade` |

### Installation

Click **Add device** in the portal. It shows the enrolment code and ready-made install commands with
a copy button:

- **Windows PowerShell** – run in PowerShell as Administrator,
- **PowerShell 7** – run in pwsh as Administrator on Windows, or with `sudo pwsh` on Debian / Ubuntu
  ([install PowerShell](https://learn.microsoft.com/powershell/scripting/install/install-ubuntu)).

The command downloads the agent from the server (`/agent/app.ps1`, the version matching the server)
to a temporary file and runs its installer, which copies the agent to `%ProgramData%\Laravel-MDM`
on Windows or `/opt/laravel-mdm` on Linux (`-InstallPath` to change), enrols the device and installs
the service:

```powershell
iwr -useb 'https://mdm.example.com/agent/app.ps1' -OutFile ($f = "$env:TEMP\mdm-agent-$(Get-Random).ps1"); if ($?) { & powershell -ExecutionPolicy Bypass -File $f -ServerUrl 'https://mdm.example.com' -EnrolmentCode 1234 -Install }
```

The installer needs administrator rights (Administrator on Windows, root via `sudo` on Linux). It
checks them first and stops without using up the enrolment code when they are missing.
 Set `AGENT_DOWNLOAD_URL` to download it from elsewhere, e.g. a GitHub release.

Manually:

```powershell
.\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install
```

Optional parameters (stored in the scheduled task / service by `-Install`):

| Parameter | Default | Description |
|-----------|---------|-------------|
| `-ReportInterval` | `300` | Seconds between device reports |
| `-HeartbeatInterval` | `30` | Seconds between heartbeats (with CPU/RAM) |
| `-InventoryInterval` | `21600` | Seconds between update checks |
| `-ReverbScheme` | scheme of `-ServerUrl` | `https` (wss) or `http` (ws) |
| `-ReverbHost`, `-ReverbPort`, `-ReverbKey` | from server | Override the WebSocket address announced by the server |
| `-NoRealtime` | | Use HTTPS only, without the WebSocket |

The token is stored next to the script (`Token.xml` on Windows, `token` readable by root only on
Linux) and logs are written to `agent.log`. The agent only executes the commands `turnOff`, `restart`
and `doUpdates`.
