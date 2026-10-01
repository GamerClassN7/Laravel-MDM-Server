<?php

use App\Events\DevicesChanged;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('device.{deviceId}', function (Device $device, int $deviceId) {
    return $device->id === $deviceId;
}, ['guards' => ['api']]);

// Live updates of the portal: every signed-in user sees all devices.
Broadcast::channel(DevicesChanged::CHANNEL, function (User $user) {
    return true;
}, ['guards' => ['web']]);
