<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What an agent found on the open ports of one address in its network (a port scan on request). */
    public function up(): void
    {
        Schema::create('port_scan_results', function (Blueprint $table) {
            $table->id();
            // The network segment (NetworkMap's site) and subnet the address is in, like a neighbour.
            $table->string('site', 45);
            $table->string('network', 18);
            $table->string('ip', 15);
            // The agent that scanned it (kept even when it is gone, for the history of the result).
            $table->foreignId('scanned_by')->nullable()->constrained('devices')->nullOnDelete();
            // [{port, service, banner}] of the open ports, and passive observations about them.
            $table->json('ports');
            $table->json('findings')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->unique(['site', 'ip']);
            $table->index('scanned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_scan_results');
    }
};
