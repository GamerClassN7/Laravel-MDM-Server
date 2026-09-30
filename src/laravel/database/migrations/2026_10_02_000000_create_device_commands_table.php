<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One command for one device with its state: queued, taken by the agent, running (with an
        // optional progress in percent) and its result. Replaces the list in devices.commands.
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('command', 32);
            $table->json('params')->nullable();
            // What the command works on (e.g. "winget:Git.Git" for one update), for duplicates.
            $table->string('target', 300)->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('message', 1000)->nullable();
            $table->foreignId('issued_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
        });

        $now = now();
        foreach (DB::table('devices')->whereNotNull('commands')->get(['id', 'commands']) as $device) {
            foreach ((array) (json_decode((string) $device->commands, true) ?? []) as $command) {
                if (is_string($command)) {
                    DB::table('device_commands')->insert(['device_id' => $device->id, 'command' => $command, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('commands');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->json('commands')->nullable();
        });
        foreach (DB::table('device_commands')->where('status', 'queued')->get()->groupBy('device_id') as $deviceId => $commands) {
            DB::table('devices')->where('id', $deviceId)->update(['commands' => json_encode($commands->pluck('command')->unique()->values())]);
        }
        Schema::dropIfExists('device_commands');
    }
};
