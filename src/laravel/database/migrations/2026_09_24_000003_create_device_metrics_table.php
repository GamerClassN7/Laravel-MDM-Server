<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('cpu', 5, 2);
            $table->unsignedBigInteger('memory_used');
            $table->unsignedBigInteger('memory_total');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_metrics');
    }
};
