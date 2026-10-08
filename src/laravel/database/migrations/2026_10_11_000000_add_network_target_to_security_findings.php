<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Findings may now belong to a scanned host that is not a managed device (an unknown neighbour
     * found by a port scan): device_id becomes nullable and the target address is kept alongside.
     */
    public function up(): void
    {
        Schema::table('security_findings', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->change();
            $table->string('target_ip', 15)->nullable()->after('device_id');
            $table->string('target_mac', 17)->nullable()->after('target_ip');
            $table->string('network', 18)->nullable()->after('target_mac');
            $table->string('site', 45)->nullable()->after('network');
            $table->index('target_ip');
        });
    }

    public function down(): void
    {
        Schema::table('security_findings', function (Blueprint $table) {
            $table->dropIndex(['target_ip']);
            $table->dropColumn(['target_ip', 'target_mac', 'network', 'site']);
            // device_id is left nullable; re-tightening it would fail if network findings exist.
        });
    }
};
