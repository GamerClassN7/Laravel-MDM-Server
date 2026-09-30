<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Labels for grouping devices ("servers", "family"): filters, script and alert targets.
            $table->json('tags')->nullable();
            // Address the last report came from: devices behind the same one share a network (Wake-on-LAN).
            $table->string('public_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['tags', 'public_ip']);
        });
    }
};
