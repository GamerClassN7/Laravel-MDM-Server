<?php

use App\Models\Device;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('device.{deviceId}', function (Device $device, int $deviceId) {
    return $device->id === $deviceId;
}, ['guards' => ['api']]);
