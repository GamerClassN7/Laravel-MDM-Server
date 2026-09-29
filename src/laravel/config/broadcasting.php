<?php

// Only the default differs from the framework: agents receive commands over Reverb (the rest of the
// framework's broadcasting config, including the reverb connection, still applies). Without Reverb
// credentials (e.g. while building the Docker image) the reverb broadcaster cannot be created.
$reverbConfigured = env('REVERB_APP_ID') && env('REVERB_APP_KEY') && env('REVERB_APP_SECRET');

return [

    'default' => env('BROADCAST_CONNECTION', $reverbConfigured ? 'reverb' : 'null'),

];
