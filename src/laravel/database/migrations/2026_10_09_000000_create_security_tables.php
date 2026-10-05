<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The security inventory of a device (agents 1.17.0+): software, processes, listening ports,
        // startup items, administrators and security settings. One row per device with the state
        // (hash) of each source that was applied, and one row per item: agents send only what
        // changed (App\Support\SecurityScanner::applyDeltas).
        Schema::create('security_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('states');
            $table->timestamp('collected_at');
            $table->timestamps();
        });

        Schema::create('security_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('item_key', 32);
            $table->string('item_hash', 32);
            $table->text('data');
            $table->unique(['device_id', 'source', 'item_key']);
        });

        // Rules of the scanner: detection rules (App\Support\SecurityRules) and parsers of raw
        // logs (App\Support\SecurityParsers); the built-in ones from resources/security/*.json
        // and the ones added in the portal.
        Schema::create('security_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->default('detection');
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('severity', 16)->nullable();
            $table->string('source', 16);
            $table->string('platform', 16)->default('any');
            $table->json('definition');
            // bundled: resources/security/*.json, feed: the rules repository (SyncSecurityFeed), custom: added in the portal.
            $table->string('origin', 16)->default('custom');
            $table->string('feed_version', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // A rule that holds on a device: open until it does not anymore (resolved_at), events
        // until someone acknowledges them.
        Schema::create('security_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('security_rule_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('severity', 16);
            $table->string('message', 1000);
            $table->json('details')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'resolved_at']);
            $table->index(['security_rule_id', 'device_id', 'fingerprint']);
        });

        // What the agents saw happen (failed sign-ins, cleared logs, ...), kept for the timeline.
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('count')->default(1);
            $table->string('user', 256)->nullable();
            $table->string('source', 256)->nullable();
            $table->string('message', 1000)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['device_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_rules');
        Schema::dropIfExists('security_inventory_items');
        Schema::dropIfExists('security_inventories');
    }
};
