# Laravel-MDM (Mobile Device Management)

## Description

A simple system for managing your Windows computers with a self-hosted portal and a lightweight
agent written in PowerShell. Linux support is planned.

## Why I wrote this

The answer is simple: over the years, the number of computers I take care of (my girlfriend's
laptop, my work PC, …) kept growing, and it was not always easy to keep track of free disk space
or whether OS updates were installed.

## Screenshots

![image](https://user-images.githubusercontent.com/22167469/174658961-76a99fcb-f9e4-450c-85d4-2d8219257f03.png)
![image](https://user-images.githubusercontent.com/22167469/174658974-13293bce-5b29-47df-9b73-ef8a3f39a37e.png)
![image](https://user-images.githubusercontent.com/22167469/174659000-fefaded0-cb7a-441b-ab40-bebe8777b465.png)

## Repository structure

| Path | Content |
|------|---------|
| `src/laravel` | Server application (Laravel) |
| `src/powershell` | Windows agent |
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
| Reverb | WebSocket server on port 8080 (`REVERB_SERVER_PORT`) | `REVERB_ENABLED=false` |
| Scheduler | `php artisan schedule:work` | `SCHEDULER_ENABLED=false` |

```bash
cp src/laravel/.env.example src/laravel/.env   # set APP_KEY, DB_* and REVERB_* values
docker compose --env-file src/laravel/.env up -d
```

Generate `APP_KEY` with `php artisan key:generate --show`. Uploaded files and logs are stored in the
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

4. Behind a reverse proxy, forward the `/app` WebSocket path to Reverb, e.g. for nginx:

   ```nginx
   location /app {
       proxy_http_version 1.1;
       proxy_set_header Host $http_host;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_pass http://127.0.0.1:8080;
   }
   ```

## Windows agent

The agent (`src/powershell/app.ps1`) runs continuously as a scheduled task under the `SYSTEM`
account. It keeps a WebSocket connection open for commands, sends a heartbeat with CPU and RAM usage
every 30 seconds (over the WebSocket, or over HTTPS when the WebSocket is unavailable) and sends a
device report every 5 minutes over HTTPS. A device without a heartbeat for 90 seconds is shown as
offline.

The agent is designed to stay out of the way:

- it runs with below-normal priority,
- CPU and RAM usage come from plain Win32 calls (`GetSystemTimes`, `GlobalMemoryStatusEx`); CPU usage
  is the average over the heartbeat interval, so nothing is sampled in between,
- the expensive Windows Update and winget checks run every 6 hours in an idle-priority process; the
  result is cached in `inventory.json` and reused in reports.

### Installation

1. Add a device in the portal to get an enrolment code.
2. Copy `app.ps1` to the device and run it from an elevated PowerShell prompt:

   ```powershell
   .\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install
   ```

Optional parameters (stored in the scheduled task by `-Install`):

| Parameter | Default | Description |
|-----------|---------|-------------|
| `-ReportInterval` | `300` | Seconds between device reports |
| `-HeartbeatInterval` | `30` | Seconds between heartbeats (with CPU/RAM) |
| `-InventoryInterval` | `21600` | Seconds between Windows Update / winget checks |
| `-ReverbScheme` | scheme of `-ServerUrl` | `https` (wss) or `http` (ws) |
| `-ReverbHost`, `-ReverbPort`, `-ReverbKey` | from server | Override the WebSocket address announced by the server |
| `-NoRealtime` | | Use HTTPS only, without the WebSocket |

The token is stored next to the script in `Token.xml` and logs are written to `agent.log`.
The agent only executes the commands `turnOff`, `restart` and `doUpdates`.
