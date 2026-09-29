<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remediation scripts (PowerShell): a detection script and an optional remediation script.
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('platform', 16)->default('all');
            // Stored exactly as entered, the fingerprint covers these bytes.
            $table->longText('detection');
            $table->longText('remediation')->nullable();
            $table->unsignedSmallInteger('timeout')->default(60);
            $table->unsignedInteger('version')->default(1);
            $table->char('fingerprint', 64);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        // One run of a script version on one device, with its result.
        Schema::create('script_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->char('fingerprint', 64);
            $table->string('status', 16)->default('pending');
            $table->smallInteger('detection_exit')->nullable();
            $table->smallInteger('remediation_exit')->nullable();
            $table->smallInteger('post_detection_exit')->nullable();
            $table->text('output')->nullable();
            $table->string('error', 1000)->nullable();
            $table->foreignId('issued_by')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_runs');
        Schema::dropIfExists('scripts');
    }
};
