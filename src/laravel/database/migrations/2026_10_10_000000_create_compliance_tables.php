<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compliance policies (App\Support\CompliancePolicies): benchmarks and baselines whose checks
        // look at the security inventory. From the rules feed (policies/*.json) or added in the portal.
        Schema::create('compliance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('platform', 16)->default('any');
            $table->json('definition');
            // feed: the rules repository (SyncSecurityFeed), custom: added in the portal.
            $table->string('origin', 16)->default('custom');
            $table->string('feed_version', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // The status of each check of a policy on a device (pass, fail, warn, manual, na), kept
        // until the next evaluation changes it.
        Schema::create('compliance_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compliance_policy_id')->constrained()->cascadeOnDelete();
            $table->string('check_id', 64);
            $table->string('status', 8);
            $table->string('severity', 16);
            $table->string('message', 1000);
            $table->json('details')->nullable();
            // Since when the check has this status.
            $table->timestamp('changed_at');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['device_id', 'compliance_policy_id', 'check_id']);
            $table->index(['compliance_policy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_results');
        Schema::dropIfExists('compliance_policies');
    }
};
