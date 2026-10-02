<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remediation started by hand: the runs of such a script only detect (mode "detect", the device
 * gets no remediation script), a device that needs it shows an alert with Remediate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->boolean('manual_remediation')->default(false);
        });
        Schema::table('script_runs', function (Blueprint $table) {
            $table->string('mode', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropColumn('manual_remediation');
        });
        Schema::table('script_runs', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
