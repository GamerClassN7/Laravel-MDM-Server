<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Last contact per channel, to show how the agent is connected.
            $table->timestamp('last_ws_at')->nullable();
            $table->timestamp('last_http_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['last_ws_at', 'last_http_at']);
        });
    }
};
