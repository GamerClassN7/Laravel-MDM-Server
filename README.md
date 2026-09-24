Laravel-MDM (MObile Device Management)

## Description 
Simple system to manage your Windows and Linux ps with help of self-hosted portal and simple user agent written in PowerShell.

## why i wrote this ?
Answer is simple threw years computers i need to take care of (My girlfriend NTB, My Work PC) rised to higher numbers and some times is not simple to keep track ton free space on drives or if OS updates are installed.

## Few Screenshots
![image](https://user-images.githubusercontent.com/22167469/174658961-76a99fcb-f9e4-450c-85d4-2d8219257f03.png)
![image](https://user-images.githubusercontent.com/22167469/174658974-13293bce-5b29-47df-9b73-ef8a3f39a37e.png)
![image](https://user-images.githubusercontent.com/22167469/174659000-fefaded0-cb7a-441b-ab40-bebe8777b465.png)



## Server setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Set `APP_SYSTEM_ADMINS` in `.env` to the IDs of users with access to the system pages.

### Real-time commands (WebSocket)

Commands (turn off, restart, install updates) are pushed to agents instantly over WebSocket
using [Laravel Reverb](https://laravel.com/docs/reverb). Without it, commands are delivered with
the next periodic report of the agent.

1. Fill `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env` with random strings.
2. Point `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` to the public address agents connect to.
3. Keep the server running, e.g. with Supervisor or the `reverb` service in `docker-compose.yml`:

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

The agent (`.scripts/app.ps1`) runs continuously as a scheduled task under `SYSTEM`. It keeps a
WebSocket connection for commands and sends a device report every 5 minutes over HTTPS.

1. Add a device in the portal to get an enrolment code.
2. Copy `app.ps1` to the device and run it from an elevated PowerShell:

   ```powershell
   .\app.ps1 -ServerUrl https://mdm.example.com -EnrolmentCode 1234 -Install
   ```

The token is stored next to the script in `Token.xml`, logs are written to `agent.log`.
Only the commands `turnOff`, `restart` and `doUpdates` are executed.
