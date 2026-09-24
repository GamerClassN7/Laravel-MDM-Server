<?php

use App\Models\Device;
use App\Models\User;

return [

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'token',
            'provider' => 'devices',
            'storage_key' => 'token',
            'hash' => true,
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'devices' => [
            'driver' => 'eloquent',
            'model' => Device::class,
        ],
    ],

];
