<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a user gets notified: e-mail addresses and push / webhook URLs (Shoutrrr style).
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('emails')->nullable();
            $table->json('urls')->nullable();
            $table->timestamps();
        });

        // What a user wants to be alerted about: a condition (type, threshold, minutes) on devices.
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedTinyInteger('threshold')->nullable();
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->json('target');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // An alert of a rule on a device: open until the condition is gone (resolved_at).
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('message', 1000)->nullable();
            $table->float('value')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['alert_rule_id', 'device_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('notification_settings');
    }
};
