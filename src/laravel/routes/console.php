<?php

use App\Models\DeviceMetric;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DeviceMetric::class]])->daily();

// Creates the server signing key when it does not exist yet and shows its fingerprint.
Artisan::command('mdm:signing-key', function () {
    $this->info('Signing key: '.App\Support\Signing::keyPath());
    $this->info('Fingerprint: '.App\Support\Signing::fingerprint());
})->purpose('Create the server signing key (if needed) and show its fingerprint');
