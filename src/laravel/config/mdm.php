<?php

return [

    // Where install commands download the agent from; defaults to this server (/agent/app.ps1),
    // so the agent always matches the server version.
    'agent_download_url' => env('AGENT_DOWNLOAD_URL'),

];
