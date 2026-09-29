<?php

// Only the default differs from the framework: agents receive commands over Reverb (the rest of
// the framework's broadcasting config, including the reverb connection, still applies).
return [

    'default' => env('BROADCAST_CONNECTION', 'reverb'),

];
