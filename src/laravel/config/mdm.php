<?php

return [

    // Where install commands download the agent from; defaults to this server (/agent/app.ps1),
    // so the agent always matches the server version.
    'agent_download_url' => env('AGENT_DOWNLOAD_URL'),

    // The server's private signing key (RSA-3072, generated on first use). Agents pin its public
    // key and accept only responses, commands and agent updates signed with it.
    'signing_key' => env('MDM_SIGNING_KEY'),

    // Reject agents that do not sign their requests yet (older than 1.7.0). They can no longer be
    // updated remotely then, only reinstalled.
    'require_signed_agents' => (bool) env('MDM_REQUIRE_SIGNED_AGENTS', false),

    // Time zone of the script schedules (cron expressions), e.g. Europe/Prague.
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // Seconds a signed message may differ from the server clock.
    'signature_max_skew' => 300,

];
