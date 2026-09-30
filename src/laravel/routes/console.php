<?php

use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DeviceMetric::class, DeviceCommand::class]])->daily();

// Commands the device never finished (it went offline, the agent was stopped) are given up.
Schedule::call(fn () => DeviceCommand::expireStale())->everyFiveMinutes()->name('mdm:expire-commands');

// Creates the server signing key when it does not exist yet and shows its fingerprint.
Artisan::command('mdm:signing-key', function () {
    $this->info('Signing key: '.App\Support\Signing::keyPath());
    $this->info('Fingerprint: '.App\Support\Signing::fingerprint());
})->purpose('Create the server signing key (if needed) and show its fingerprint');
