<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Devices the agents see in their networks (ARP tables, scans) that the portal may not know. */
    public function up(): void
    {
        Schema::create('network_neighbours', function (Blueprint $table) {
            $table->id();
            // The public address of the network (NetworkMap's site) and its subnet.
            $table->string('site', 45);
            $table->string('network', 18);
            $table->string('mac', 17);
            $table->string('ip', 15);
            $table->string('hostname')->nullable();
            $table->foreignId('seen_by')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('ignored_at')->nullable();
            $table->timestamps();

            $table->unique(['site', 'mac']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_neighbours');
    }
};
