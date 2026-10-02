# Laravel-MDM (Mobile Device Management)

## Description

A simple system for managing your Windows and Linux (Debian / Ubuntu) computers with a self-hosted
portal and a lightweight agent written in PowerShell.

## Why I wrote this

The answer is simple: over the years, the number of computers I take care of (my girlfriend's
laptop, my work PC, …) kept growing, and it was not always easy to keep track of free disk space
or whether OS updates were installed.

## Quick start

```yaml
# docker-compose.yml
services:
  mdm:
    image: ghcr.io/gamerclassn7/laravel-mdm-server:latest
    container_name: mdm
    ports:
      - "8000:8000" # web and agent WebSocket (/app)
    volumes:
      - storage:/var/www/storage
    restart: unless-stopped

volumes:
  storage:
```

```bash
docker compose up -d
```

Open `http://<server>:8000`, create the first account (it is the system admin) and click
**Add device**. No `.env` and no database server are needed: SQLite, the keys and the logs are
kept in the `storage` volume. For access from outside your network put it behind a TLS proxy
(see [Docker](#docker)).

## Screenshots

Devices: the list (search, tags, online state, what needs attention) beside the detail with its alerts, CPU/memory history (1h to 30d) and tabs:

![Device detail](docs/screenshots/device.png)

A ping-only device (a printer, a NAS without the agent): an agent in its network pings it every 30 s and wakes it with Wake-on-LAN:

![Ping-only device](docs/screenshots/ping.png)

Notifications: firing alerts, rules and channels (ntfy, Discord, Telegram, e-mail, …):

![Notifications](docs/screenshots/notifications.png)

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

Open the portal and create the first account on the setup page. Without `APP_SYSTEM_ADMINS` the
first user (ID 1) is the system admin; set `APP_SYSTEM_ADMINS` in `.env` to a comma-separated list of
user IDs to choose others. Users and new passwords can also be set from the command line:

```bash
php artisan mdm:user admin@example.com            # asks for the password
docker exec -it mdm php artisan mdm:user admin@example.com
```

Installations from before the setup page got a default account (`the-email@example.com` /
`the-password-of-choice`). Change its e-mail and password in the profile; the portal warns after
logging in with that password.

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

`docker-compose.yml` runs the image with SQLite and no settings (see [Quick start](#quick-start)).
Settings are passed as `environment:` of the service, e.g. `APP_SYSTEM_ADMINS: 1,2`. For MySQL:

```bash
cp src/laravel/.env.example src/laravel/.env   # set DB_*
docker compose -f docker-compose.mysql.yml --env-file src/laravel/.env up -d
```

Links are `https` behind a TLS proxy (any domain name). Opened directly by an IP address or
`localhost` over plain `http` (e.g. `http://192.168.1.10:8000`), they stay `http`.

Only port 8000 is exposed: nginx serves the web and proxies `/app` (agent WebSockets) to Reverb
inside the container. No Reverb settings are needed: the app publishes to Reverb directly inside the
container, and agents connect to the address they reach the server on (e.g. `wss://mdm.example.com`
behind a TLS proxy such as Nginx Proxy Manager with WebSocket support enabled). Set `REVERB_HOST`,
`REVERB_PORT` and `REVERB_SCHEME` only when the WebSocket is on a different public address.

On start the container waits for the database and runs the migrations. `APP_KEY`, `REVERB_APP_KEY`
and `REVERB_APP_SECRET` are generated on the first start when they are not set, and kept in
`storage/secrets.env` in the `storage` volume, so they stay the same across restarts and updates.
Values set in the environment always take precedence. Uploaded files and logs are stored in the
`storage` volume.

Without `DB_CONNECTION` (or with `DB_CONNECTION=sqlite`) and no `DB_DATABASE`, the database is SQLite
in the `storage` volume (`storage/database.sqlite`). A volume mounted over `/var/www/database` keeps working: its
`database.sqlite` is used, and on every start the migrations in it are replaced with the ones
from the image, so new migrations are applied after an update.

The application logs to daily files in the `storage` volume (`storage/logs`, shown in
**System → Logs**) and to `docker logs` at the same time. Another file channel in the environment or
in `.env` (e.g. `LOG_CHANNEL=single`) is used instead of the daily files; `LOG_CHANNEL=stderr` logs
to `docker logs` only. Database backups from the system pages are not supported in the image (no
`mysqldump`).

### Real-time commands (WebSocket)

Commands (turn off, restart, install updates) are pushed to agents instantly over a WebSocket
using [Laravel Reverb](https://laravel.com/docs/reverb). Without it, commands are delivered with
the agent's next periodic report.

Reverb is required for the portal: its pages never poll. When something about a device changes (a
report, a heartbeat, the progress of a command, a ping, a script result, an alert, a device going
offline) the server announces it on the private `devices` channel (only the device id), and the
components showing that device reload (`resources/js/live.js`, Laravel Echo). Open menus and
forms stay as they are. Browsers connect to the address of the portal under `/app` (the Docker
image proxies it), or to `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` when they name a public
address.

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

The portal can be installed as an app (PWA, `steelants/laravel-general`): open it in Chrome,
Edge or Safari over HTTPS and choose *Install* / *Add to Home Screen*. The service worker caches
nothing but an offline page. Change the icon with
`php artisan pwa:make-icons --icon=resources/images/icon.png` (the vector source is
`resources/images/icon.svg`, also served as `favicon.svg`).

## Agent

The agent (`src/powershell/app.ps1`) runs on Windows (Windows PowerShell 5.1 or PowerShell 7) and on
Debian / Ubuntu (PowerShell 7). It runs continuously as a scheduled task under `SYSTEM` on Windows and
as a systemd service (`laravel-mdm-agent`) running as root on Linux. It keeps a WebSocket connection
open for commands, sends a heartbeat with CPU and RAM usage every 30 seconds (over the WebSocket, or
over HTTPS when the WebSocket is unavailable) and sends a device report every 5 minutes over HTTPS.

The heartbeat also carries the fast-changing state: restart pending and the states of services and
Docker containers, only when something changed, so the portal shows a failed service or a stopped
container within 30 seconds. Everything large (updates, drives, networks, disk health, service and
container details) goes with the report over HTTPS; a state too large for a WebSocket message
(10 kB) is sent over HTTPS too. The live state is stored apart from the report in a single update,
so neither overwrites the other and the newer one is shown. Queued commands are handed to the agent
exactly once, also when a report and the WebSocket take them at the same time.

Every command has a state in the portal: waiting for the device, taken, running (agents 1.8.0+
report the progress in percent where it is known, e.g. apt, Windows Update, the steps of Install
updates), then done or failed with the reason. A restart and an agent update are done when the
agent is back. Commands without news are given up after a timeout. A command that is already on its
way is never queued again: double clicks, two users or an action for all devices do not run it twice.

**Smart alerts** show what needs attention on a device (restart required, a newer agent, updates,
low disk space, disk health, failed services and remediations, failed commands, errors from the
agent's log) with the action that fixes it. An alert can be dismissed: it stays hidden until it says something else (e.g. more
updates) or goes away and comes back. The **Smart alerts** dashboard widget lists them for all
devices, with one button per alert for all devices that can take the action now.
A device without a heartbeat for 90 seconds is shown as offline.

**Agent errors:** agents 1.13.2+ send the errors they write to their log (a failed inventory,
report or command status, a crash of the agent) to the server once it answers, each message once
with how often and when it happened; they are kept over a restart until the server took them. Not
reaching the server (network down, the WebSocket reconnecting) is not one. The device shows them as
an alert (the newest ones of the last 7 days) until **Clear**.

When the agent cannot reach the server (network outage, server down), it keeps the CPU and memory
samples, and the results of the ping-only devices it pings, in `backlog-metrics.json` /
`backlog-pings.json` (a day of samples, 10 000 pings at most, also over a restart). Once the server
answers again it sends them with the time each was taken, so the charts have no gap (agents 1.11.0+).

The agent is designed to stay out of the way:

- it runs with below-normal priority (`Nice=10` and idle I/O priority on Linux),
- CPU and RAM usage come from plain Win32 calls (`GetSystemTimes`, `GlobalMemoryStatusEx`) on Windows
  and from `/proc/stat` and `/proc/meminfo` on Linux; CPU usage is the average over the heartbeat
  interval, so nothing is sampled in between,
- the expensive update checks (Windows Update, winget and PowerShell Gallery modules, or the local apt
  cache and modules on Linux) run every 6 hours in an idle-priority process; the result is cached in `inventory.json` and reused in reports,
- disk health (S.M.A.R.T.) is read once an hour in an idle-priority process and cached in `health.json`;
  on Linux sleeping disks are skipped (`smartctl -n standby`), so the agent never spins them up.

### Feature matrix

✅ supported · ⚠️ partly (see the note) · ❌ not supported

| Feature | Windows | Debian / Ubuntu |
|---|:---:|:---:|
| **Agent** | | |
| PowerShell | ✅ 5.1 or 7 | ✅ 7 |
| Runs as | scheduled task, `SYSTEM` | systemd service, root |
| Enrolment with a code, install commands | ✅ | ✅ |
| Remote agent update | ✅ | ✅ |
| WebSocket: instant commands, heartbeat | ✅ | ✅ |
| HTTPS fallback (heartbeat, commands) | ✅ | ✅ |
| **Monitoring** | | |
| CPU and RAM (charts) | ✅ | ✅ |
| OS, uptime, user, CPU, drives, networks | ✅ | ✅ |
| Restart pending | ✅ | ✅ |
| Battery and charging | ✅ | ✅ |
| Virtual machine / container badge | ✅ | ✅ |
| Services and their state | ✅ automatic services | ✅ systemd units |
| Docker containers | ✅ when installed | ✅ when installed |
| Disk health (S.M.A.R.T.) | ✅ | ⚠️ needs smartmontools |
| **Updates** | | |
| OS updates | ✅ Windows Update | ✅ apt (phased and held back marked) |
| Applications | ✅ winget | ✅ flatpak, snap |
| PowerShell modules (PowerShell Gallery) | ✅ Windows PowerShell and 7 | ✅ PowerShell 7 |
| Users' own PowerShell modules | ⚠️ listed only | ✅ updated as the user |
| Newer PowerShell 7 release | ✅ installed from GitHub (MSI, SHA-256 checked) | ✅ installed from GitHub (.deb or tar.gz, SHA-256 checked) |
| **Commands** | | |
| Install updates | ✅ Windows Update, winget, modules | ✅ apt, flatpak, snap, modules |
| Install a single update (agent 1.8.0+, PowerShell 7 from GitHub 1.8.2+) | ✅ Windows Update, winget, modules, PowerShell 7 | ✅ apt, flatpak, snap, modules, PowerShell 7 |
| Progress and result of commands (agent 1.8.0+) | ✅ | ✅ |
| Restart / Turn off | ✅ | ✅ |
| Wake-on-LAN through another agent in the network (agent 1.9.0+) | ✅ sends and is woken | ✅ sends and is woken |
| Pings ping-only devices of its network (agent 1.10.0+, not on a battery) | ✅ | ✅ |

Notes:

- Disk health is not collected on virtual machines and in containers. On Linux it needs
  `smartctl` (`apt install smartmontools`).
- On Windows the agent runs as `SYSTEM`, which cannot act as a logged-on user. Modules installed in
  a user's own profile are only listed.

Where the data comes from:

| | Windows | Debian / Ubuntu |
|---|---|---|
| Report | OS, uptime, user, CPU, battery, drives, networks, pending reboot | the same, from `/etc/os-release`, `/proc`, `df`, `ip` and `/var/run/reboot-required` |
| Updates | Windows Update, winget (any system language) | `apt list --upgradable` (phased / held back marked), flatpak (system and users), snap |
| PowerShell | outdated PowerShell Gallery modules in Windows PowerShell and PowerShell 7 (users' own ones are listed, not updated: SYSTEM cannot act as the user), a newer PowerShell 7 release | the same in PowerShell 7; users' own modules are updated as that user |
| Services | running and stopped automatic services (`Get-Service`) | running and failed units (`systemctl`) |
| Docker (when installed) | containers and their state (`docker ps --all`) | the same |
| Virtualization | manufacturer and model (Hyper-V, VMware, KVM / QEMU, VirtualBox, Xen …) | `systemd-detect-virt`, DMI (VMs and containers) |
| Power | battery level and mains power (`GetSystemPowerStatus`) | `/sys/class/power_supply` |
| Disk health | `Get-PhysicalDisk`, `Get-StorageReliabilityCounter` | `smartctl` ([smartmontools](https://www.smartmontools.org/), `apt install smartmontools`) |
| Turn off / Restart | `Stop-Computer` / `Restart-Computer` | `systemctl poweroff` / `systemctl reboot` |
| Install updates | winget (also PowerShell 7 itself), PowerShell modules, Windows Update | `apt-get upgrade --with-new-pkgs`, flatpak, snap, PowerShell modules |

The device detail has tabs for drives, updates, networks, **services** (with search, failed ones
first), **Docker** containers (only where the Docker engine is installed, not just the CLI) and
**disk health** (temperature, power-on hours, SSD wear, reallocated / pending sectors and media
errors). Virtual machines and containers get a badge with the hypervisor; disk health is not collected
or shown for them. Services, Docker and Updates have a search field. The battery shows a
charging indicator while the device is on mains power.

**Install updates** runs in the background: on Linux `apt-get upgrade --with-new-pkgs` (waits for a
running apt, installs new dependencies, removes nothing), on Windows winget, Windows Update, and on
both PowerShell module updates. On Windows winget updates one package at a time (`winget upgrade --id <id>
--exact --silent`), each with a 15 minute limit: an installer that waits for a window (`SYSTEM` has
no desktop) or for an application to close is ended with its process tree, reported as failed and
the next package goes on (agents 1.10.1+; before, one such installer stopped `winget upgrade --all`
for good). App Installer, which is winget itself, is left out. Every step with its result is written to `agent.log`, the full
output of apt / winget to `updates.log`, and the update list is collected again right after.

On Windows (agents 1.14.0+):

- **The same list as the user's `winget upgrade`:** `SYSTEM` does not see applications installed
  only for a user (into their profile). The agent therefore also asks winget in the session of the
  logged-on user (a hidden one-off task) and adds what only they see, marked **user**. Those are
  updated in that session; nobody logged on means they wait.
- **What winget is doing** is shown in the progress: the download (MB or percent), that the
  installer runs, or that it runs in the user's session, with the time so far. For example
  `winget upgrade ONLYOFFICE.DesktopEditors (5/5): running the installer (3:12)`.
- **Windows Update** installs the updates it marks as "may ask for user input" too: drivers and
  vendor packages (NVIDIA, Intel, firmware) carry that flag but install quietly (`ForceQuiet`), as
  Windows Update installs them itself. One that really needs a person fails with its result.
On Linux the update list tells what apt would install now (a simulated `apt-get upgrade`, no
network): updates deferred by phasing and held back ones (pinned, held, or needing other packages
to change) are shown with an icon and do not count as available updates. The list is also
collected again a few minutes after packages are installed outside the agent (apt,
unattended-upgrades).

### Signed communication

Agents 1.7.0 and newer sign everything they send and accept only what the server signed. The
signatures are RSA-3072 (PKCS#1 v1.5, SHA-256) and are checked with plain .NET, which works in
Windows PowerShell 5.1 and PowerShell 7 without extra modules. HTTPS still keeps the content
private; the signatures make sure nothing was forged or changed on the way, in the database or on
the device's disk.

- **Server key:** `storage/mdm-signing.key`, created on the first start (`php artisan mdm:signing-key`
  shows its fingerprint) and never stored in the database. Keep it in the backup of the storage
  volume: without it agents accept no more updates or commands and have to be reinstalled.
  `/agent/app.ps1` is served with the public key filled in, so a new or updated agent pins it on
  its first start. The install commands also pass `-ServerKeyFingerprint`, and **Add device** shows
  the fingerprint.
- **Device key:** created on the device and never sent anywhere; the server stores only the public
  key. On Windows it is a non-exportable CNG machine key (with a DPAPI-protected file as a fallback),
  on Linux a root-only file.
- **Requests:** every request of the agent is signed with the device key over the method, path,
  time, a one-time nonce and the body hash. The server rejects unsigned, changed, replayed and stale
  requests (more than 5 minutes off; the agent corrects its clock from signed server responses).
- **Responses:** every response is signed with the server key for exactly that request (its nonce),
  error responses included. The agent ignores anything else.
- **WebSocket:** a command event is only a trigger signed by the server. The agent then takes the
  commands over the signed API, so nothing can be injected or replayed over the WebSocket.
  Heartbeats sent over the WebSocket are signed with the device key.
- **Agent updates:** the agent downloads the new version into memory, checks its signature
  (`/agent/app.ps1.sig`) and only then writes it to disk. The previous version is kept: when the
  new one has not reported to the server within 5 minutes, it is restored (agents 1.10.2+).

The agent keeps the pinned key and its settings in `config.json`, which only `SYSTEM` / root and
administrators can read. The server has no way to change this file. Agents older than 1.7.0 keep
working, but they only get **Update agent**. After updating, the agent pins the key embedded in
the new version and registers its device key once. The device detail shows **Signed** or
**Unsigned agent**. System admins can reset a device key, for example after the agent was
reinstalled with a new key. `MDM_REQUIRE_SIGNED_AGENTS=true` rejects unsigned agents entirely;
they then have to be reinstalled.

### Remediation scripts

**Scripts** in the main menu (system admins only) lists the scripts in a data table (search, sorting, **Add** in a modal). Each script has a detail page with its code, fingerprint and all runs. The scripts are PowerShell, in the style of Intune remediations:

- **Detection script** (required): exit 0 means compliant, exit 1 means the remediation should run.
- **Remediation script** (optional): runs after a detection that exited with 1, then the detection
  runs again. Without a remediation, exit 1 means failed.
- Each script targets **All**, **Windows** or **Linux** and has a timeout (up to 1 hour).
- **Remediate manually** (a switch in the script): runs and schedules only detect. The device gets
  the run without the remediation script (its manifest and fingerprint leave it out, so no agent
  can remediate with it). Exit 1 is **Needs remediation**: the device shows an alert with
  **Remediate**, and its latest run has a **Remediate** button in the runs table (output column).
  Remediate starts a full run on that device (detection, remediation, detection again). The script
  page counts the devices that need it.

Before saving, both scripts are checked: up to 200 kB each, valid PowerShell (with the PowerShell
parser when `pwsh` is on the server, `MDM_PWSH` or `PATH`; otherwise unterminated strings,
here-strings and comments and unbalanced brackets are found), and the detection has an `exit 0` and
an `exit 1` outside comments and strings.

The code is entered as text and stored byte for byte. Its **fingerprint** (SHA-256 over the
platform, the timeout and the hashes of both scripts) changes with every code change, and a new
version is created. **Run** opens a list of the devices with checkboxes. Devices on another
platform, agents that do not sign and devices with scripts disabled cannot be selected. Opened
again, the list has the devices of the last run selected. Offline devices run the script when they
come back within 24 hours. The results are shown under the script and in the **Scripts** tab of
the device: status, exit codes and up to 16 kB of output.

**Schedule** on the script page runs it again and again, like an Intune remediation: a cron expression
(`minute hour day month weekday`, e.g. `0 3 * * *` every day at 3:00, presets in the picker, the next
runs are shown) in the time zone of the server (`APP_TIMEZONE`, UTC by default), and the devices:
all of them (also the ones enrolled later), the ones with any of the chosen [tags](#tags), and picked
ones. Tags are resolved at every run. The scheduler (`schedule:run`, in the Docker image
`schedule:work`) starts each due minute once; a waiting run of the same script on an offline
device is replaced by the new one. Changing the schedule is not a new version of the script.

Security:

- **Only a trigger:** `runScripts` carries no data. The agent takes its runs over the signed API.
- **Signed manifest per run:** every run has a manifest signed with the server key, for this
  device and this run, valid for 24 hours. The agent checks the signature, the device, the expiry,
  that the run id was not executed before, the platform and the SHA-256 of both scripts, and only
  then runs anything. A rejected run is reported back with the reason.
- **Memory only:** the code is never written to disk and never on a command line. A separate
  low-priority process reads it from stdin and checks its hash again. Scripts run one at a time,
  are killed at the timeout, and run as `SYSTEM` / root.
- **No network access:**
  - On Linux the script runs in an empty network namespace (`unshare --net`, also for every
    process it starts). Without `unshare` it does not run.
  - On Windows it runs from a copy of `powershell.exe` (`%ProgramData%\Laravel-MDM\sandbox`)
    that Windows Firewall rules block in both directions. It does not run when the rules are
    missing or changed, or the firewall is off for a profile.
  - This is defense in depth, not a hard boundary: a script running as `SYSTEM` / root that sets
    out to reach the network can get around it. For example, on Linux it can enter the network
    namespace of PID 1; on Windows it can start another program from System32. DNS lookups on
    Windows go through the DNS Client service. The signature is what keeps foreign code out.
    The isolation keeps scripts from downloading or sending anything.
- **Local switch:** `-DisableScripts` (`-EnableScripts` to allow them again, or `scripts_enabled`
  in `config.json`) turns scripts off on the device. The server cannot change it.
- **Audit:** creating, changing, removing and running a script (with its fingerprint and devices)
  is written to the audit log.

### Tags

Devices get tags (**Edit tags** in the device menu, comma-separated, e.g. `servers, family`). The
device list shows them and filters by a tag with one click. Scheduled scripts and alerts target
tags, so a newly tagged device is included without changing them.

### Notifications and alerts

Notifications link to the device. The scheduler that sends them has no request to take the address
from, so it uses `APP_URL` when that is set to a real address, otherwise the address system admins
open the portal with (remembered in `storage/app/portal-url`; not localhost, and not any request's
`Host` header).

**Notifications** in the main menu, per user and in the style of
[Beszel](https://beszel.dev/guide/notifications/):

- **Where to send:** e-mail addresses (the server's `MAIL_*` settings) and push / webhook URLs in
  the [Shoutrrr](https://containrrr.dev/shoutrrr/) format, each with a **Test** button:

  | Service | URL |
  |---|---|
  | ntfy | `ntfy://ntfy.sh/topic`, `ntfy://user:password@ntfy.example.com/topic`, `ntfy://:token@host/topic` (`?priority=high&tags=warning`) |
  | Discord | `discord://token@webhookid` (from `https://discord.com/api/webhooks/webhookid/token`) |
  | Telegram | `telegram://bottoken@telegram?chats=@channel,123456789` |
  | Gotify | `gotify://gotify.example.com/AppToken` |
  | Slack | `slack://hook:T000-B000-XXXX@webhook` |
  | Pushover | `pushover://shoutrrr:apiToken@userKey` |
  | Webhook | `generic://example.com/hook` (JSON POST `{"title", "message"}`), `generic+http://` without TLS |

  `?disabletls=yes` sends over plain http (self-hosted ntfy, Gotify or webhooks in your network).

- **Alerts:** a rule is a condition on devices (all, [tags](#tags) or picked ones):

  | Alert | When |
  |---|---|
  | Status | the device is offline for at least *n* minutes |
  | CPU usage | the average over the last *n* minutes is above the threshold (%) |
  | Memory usage | the average over the last *n* minutes is above the threshold (%), or the free memory is below a size (GB) |
  | Disk usage | a drive is fuller than the threshold (%), or has less free space than a size (GB) |
  | Disk health | a disk reports a S.M.A.R.T. warning or failure |
  | Services | a service failed, or a container is unhealthy, dead or restarting |
  | Remediations | the latest run of a remediation script failed |
  | New device | a device is enrolled with the agent or added as ping-only (once each, nothing to resolve; sent with its name, system and agent version after its first report, at the latest 10 minutes after enrolment) |

  Disk and memory switch between **%** and **GB**: a percentage suits drives of the same size, a
  size suits the big ones (10 % of 4 TB are still 400 GB) and memory of different machines. GB are
  1024 based, as shown in the portal.

  The bell on a device switches the alerts for just that device, with a slider for the threshold
  and the minutes (like the bell of a system in Beszel).

The scheduler checks the rules every minute. When a rule starts to hold on a device, the user gets
one notification (🔴) and the alert is shown under **Recent alerts**; when it stops holding, a second
one (✅, with how long it lasted). Nothing is sent again in between. An offline device keeps its CPU
and memory alerts as they are until it reports again.

### Wake-on-LAN

A magic packet is a broadcast in the local network, so the server (usually somewhere else) cannot
send it. **Wake** on an offline device asks another agent in the same network to send it, e.g. the
NAS or Raspberry Pi that is always on:

1. The agents (1.9.0+) report the prefix length of their addresses, so the server knows their
   networks. The sleeping device's MAC addresses (wired first, then Wi-Fi) are kept from its last
   report.
2. The relay is an online, signing agent 1.9.0+ with an interface in the same IPv4 network. When
   both reported through the same public address, it has to be the same one (the address the
   server sees, the first `X-Forwarded-For` hop behind a proxy).
3. The relay gets a `wake` command with the MAC addresses and the broadcast addresses (the network's
   own one and `255.255.255.255`) in its signed command response. It checks them again and sends the
   packet to UDP ports 9 and 7, from the interface with an address in that network (agents 1.13.3+:
   bound to its address, on Linux also to the interface), not the one of the default route (Docker,
   VPN, a second card); the command says which interface it used. The command is the relay's (its history shows it); the woken device
   shows it like its other commands, with a progress bar.
4. The command runs until the woken device reports (agents 1.13.2+; older ones are done once the
   packet is out). When it does not come online within 10 minutes, the wake fails and the woken
   device shows an alert with **Try again**.

Devices on a battery (laptops) move between networks: their last report may show a network they
have left, and the same private address (`192.168.1.0/24`) can be another network elsewhere. They
only send a magic packet when no stationary agent can and they reach the server through the same
public address as the sleeping device. Only addresses of the same family count (a network can reach
the server over IPv6 from one device and over IPv4 from another). Every relay needs a report from the last 11 minutes, so its
networks are current.

**Wake-on-LAN settings** in the device menu set the MAC address and the IPv4 address with its
prefix by hand, for a card the agent does not report well (a docking station, another card than the
one that wakes); empty fields use what the agent reports. **Wake** is in the device menu of every
physical device (not of virtual machines) and says why it cannot wake it now.

The button says why it cannot wake a device (no known network card, no relay in the network). The
device has to allow it: Wake-on-LAN enabled in the BIOS / UEFI and for the network card (on Windows
in the adapter's *Power Management* and *Advanced* settings), usually only over a cable. On
Windows, *Fast Startup* often prevents waking after **Turn off**. It does not cross VLANs or
routers.

### Ping-only devices

A printer, a NAS or a PC without the agent: **Add device** › **Ping only** with a name, the IPv4
address, the prefix of its network (`/24`) and optionally the MAC address for Wake-on-LAN.

- An online agent 1.10.0+ in the same network (an interface in that IPv4 network, a report from
  the last 11 minutes) pings it with every heartbeat (30 s, 1 s timeout). Agents on a battery never
  ping: in another network the same address may answer for another machine. Agents 1.13.4+ ping
  from the interface in the device's network (the system `ping` with `-I` on Linux, `-S` with its
  address on Windows), not over the default route (Docker, VPN, a second card); **Sync** says which
  interface answered.
- The ping-only devices of a network are spread over all agents that can ping them: each goes to
  the one with the fewest so far, and stays with its agent while that is as good. When an agent goes
  offline, the others take its devices with their next ping (32 per agent at most).
- The agent gets the addresses with its report response and sends the results to
  `/api/device/pings` (signed like every request). The server takes results only for the devices
  it gave that agent. An answer is the heartbeat of the ping-only device: online, the round trip
  time and the agent are shown; no answer for 90 seconds means offline.
- **Wake** works as for agents: an agent of its network sends the magic packet to its MAC.
- **Sync** in its menu pings it now: the agent that pings it (1.12.0+) gets a `pingNow` command,
  pings the address it was given for it (2 s timeout) and reports the answer, e.g. `192.168.1.50
  answered in 2 ms`, shown under the device card.
- The detail has a monitor in the style of [Uptime Kuma](https://github.com/louislam/uptime-kuma):
  the last 50 pings as a bar (green answered, red not), the response now and on average, the
  uptime of the last 24 hours and 30 days, and the response time over 1 h, 24 h, 7 d or 30 d with
  the unanswered periods marked. Every ping is kept for 30 days (`ping_results`, pruned daily).
- In the device list it has a network icon; alerts offer the status only (offline for *n* minutes). Its address, prefix and MAC are changed in
  **Ping settings** in the device menu.

### Dashboard

`/dashboard` (menu **Dashboard**) is a configurable dashboard from
[steelants/laravel-boilerplate.dashboard](https://packistry.sa-dev.cz/public): every user can create
their own dashboards, share them, and add, resize and move widgets in the editor. A shared
**Overview** dashboard is created by a migration with the **Devices** widget (online and offline
devices right now, the offline ones listed, refreshed every 30 seconds). Owners edit their
dashboards; system admins (`APP_SYSTEM_ADMINS`) can edit every dashboard
(`App\Policies\DashboardPolicy`).

New widgets are Blade components in `app/View/Components/Widgets` and show up in the editor
automatically. The package comes from the `packistry` Composer repository configured in
`composer.json`.

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

Running the command on a device where the agent is already installed only updates it: the existing
token is kept, the device is not enrolled again and no enrolment code is needed.

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
| `-HealthInterval` | `3600` | Seconds between disk health (S.M.A.R.T.) checks |
| `-ReverbScheme` | scheme of `-ServerUrl` | `https` (wss) or `http` (ws) |
| `-ReverbHost`, `-ReverbPort`, `-ReverbKey` | from server | Override the WebSocket address announced by the server |
| `-NoRealtime` | | Use HTTPS only, without the WebSocket |

The device detail shows whether the agent is connected over the **WebSocket** (heartbeat in the last
90 s), the **REST API** (report in the last 11 min) or both, the agent version, and the device type
(server, laptop or desktop, detected by the agent).

When the server serves a newer agent than the device runs, the detail shows an **Update agent** button
and the update command to copy. The button only asks the agent to update itself; the message carries
no command or data. The agent then:

- downloads `/agent/app.ps1` from the `-ServerUrl` it was installed with (never from an address in the
  message), and only over HTTPS (plain HTTP is accepted for localhost only),
- rejects a script that does not parse or is not newer than the running version (no downgrades),
- replaces itself and restarts.

Agents older than 1.1.0 cannot update themselves and have to be reinstalled via **Add device**.

The token is stored next to the script (`Token.xml` on Windows, `token` readable by root only on
Linux) and logs are written to `agent.log`. The agent only executes the commands `turnOff`, `restart`,
`doUpdates`, `installUpdate`, `updateAgent`, `runScripts` and `wake`.

### Checking the agent

Windows: the agent is the scheduled task **Laravel-MDM-Agent** in the root of the Task Scheduler
Library. It runs as `SYSTEM`, so Task Scheduler only lists it when started as Administrator. The
installer only saves the enrolment code: the task enrols the device itself on its first start, as
`SYSTEM`, which then holds the token and the device key. The installer waits for that start and
shows whether it worked.

```powershell
Get-ScheduledTask -TaskName 'Laravel-MDM-Agent' | Select-Object TaskName, State   # Running
Get-Content "$env:ProgramData\Laravel-MDM\agent.log" -Tail 30 -Wait                # as Administrator
```

Linux: `systemctl status laravel-mdm-agent` and `sudo tail -f /opt/laravel-mdm/agent.log`.

If the agent cannot start (not enrolled, a token or key it cannot read), `agent.log` ends with
`Agent stopped: <reason>`. On Windows, agents before 1.7.0 enrolled as the installing
administrator, so the `SYSTEM` task could not read the token. Running the install command again
as Administrator hands the token over.

### Uninstalling

Windows (PowerShell as Administrator):

```powershell
Stop-ScheduledTask -TaskName 'Laravel-MDM-Agent'
Unregister-ScheduledTask -TaskName 'Laravel-MDM-Agent' -Confirm:$false
Remove-Item -Recurse -Force "$env:ProgramData\Laravel-MDM"
# The device key (agents 1.7.0+) is a CNG machine key outside that folder.
$provider = [Security.Cryptography.CngProvider]::MicrosoftSoftwareKeyStorageProvider
if ([Security.Cryptography.CngKey]::Exists('Laravel-MDM-Agent', $provider, 'MachineKey')) {
    [Security.Cryptography.CngKey]::Open('Laravel-MDM-Agent', $provider, 'MachineKey').Delete()
}
```

Linux:

```bash
sudo systemctl disable --now laravel-mdm-agent
sudo rm -f /etc/systemd/system/laravel-mdm-agent.service && sudo systemctl daemon-reload
sudo rm -rf /opt/laravel-mdm
```

Agents older than 1.1.0 ran from the folder they were started in, not from the install directory.
The scheduled task shows where: `(Get-ScheduledTask Laravel-MDM-Agent).Actions.Arguments`; delete
that folder (`app.ps1`, `Token.xml`, `agent.log`, `inventory.json`) instead. Then delete the device
in the portal.
