<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // The device's RSA public key ({n, e}): once registered, every request of the agent
            // must be signed with it (agents 1.7.0+).
            $table->text('public_key')->nullable();
            $table->timestamp('key_registered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['public_key', 'key_registered_at']);
        });
    }
};
