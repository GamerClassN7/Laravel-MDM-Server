<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Smart alerts someone dismissed. The signature covers what the alert said, so it comes
        // back when that changes (e.g. more updates); it is removed once the alert is gone.
        Schema::create('device_alert_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->char('signature', 64);
            $table->foreignId('dismissed_by')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_alert_dismissals');
    }
};
