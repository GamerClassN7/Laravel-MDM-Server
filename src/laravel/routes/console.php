<?php

use App\Models\DeviceMetric;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DeviceMetric::class]])->daily();
