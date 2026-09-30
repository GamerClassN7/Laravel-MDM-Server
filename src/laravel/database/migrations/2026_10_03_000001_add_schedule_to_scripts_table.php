<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            // Recurring runs: a cron expression (APP_TIMEZONE) and the devices it runs on.
            $table->string('schedule', 100)->nullable();
            $table->json('schedule_target')->nullable();
            $table->timestamp('last_scheduled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropColumn(['schedule', 'schedule_target', 'last_scheduled_at']);
        });
    }
};
