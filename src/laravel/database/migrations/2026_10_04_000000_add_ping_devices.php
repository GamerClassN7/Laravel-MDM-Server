<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // "agent" (the default) or "ping": a device without an agent, pinged by an agent in its
            // network (online status) and woken through it (Wake-on-LAN).
            $table->string('kind', 8)->default('agent');
            $table->string('ping_address', 45)->nullable();
            $table->unsignedTinyInteger('ping_prefix')->nullable();
            $table->string('ping_mac', 17)->nullable();
            // The last answer: round trip in ms and the agent that pinged.
            $table->float('ping_rtt')->nullable();
            $table->foreignId('ping_relay_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['kind', 'ping_address', 'ping_prefix', 'ping_mac', 'ping_rtt', 'ping_relay_id']);
        });
    }
};
