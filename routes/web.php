<?php

use App\Livewire\ShowDevices;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Auth::routes([
    'register' => false,
]);

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/devices');
    Route::get('/devices', ShowDevices::class)->name('devices');
});
