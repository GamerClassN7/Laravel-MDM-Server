<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Fast-changing state (restart pending, service and container states) sent with the
            // heartbeat. Kept apart from the report data, so neither overwrites the other.
            $table->text('live_state')->nullable();
            $table->timestamp('live_state_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['live_state', 'live_state_at']);
        });
    }
};
