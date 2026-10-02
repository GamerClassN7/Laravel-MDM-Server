<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wakes and pings of a device are looked up by their target (DeviceCommand::completeWakes on
 * every report, heartbeat and answered ping; the device's alerts and commands).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->index(['target', 'command']);
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dropIndex(['target', 'command']);
        });
    }
};
