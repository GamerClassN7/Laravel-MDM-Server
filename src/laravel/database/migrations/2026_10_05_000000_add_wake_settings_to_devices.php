<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wake-on-LAN of a device with the agent set by hand (device menu): the MAC address and the IPv4
 * address with its prefix, instead of the ones the agent reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('wake_mac', 17)->nullable();
            $table->string('wake_address', 15)->nullable();
            $table->unsignedTinyInteger('wake_prefix')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['wake_mac', 'wake_address', 'wake_prefix']);
        });
    }
};
