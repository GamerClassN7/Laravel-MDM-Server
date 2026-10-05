<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\SecurityRule;
use App\Support\SecurityInbox;
use App\Support\SecurityParsers;
use App\Support\SecurityScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one collection of a device that SecurityInbox::push put into the cache: the logs are
 * parsed into events, then the inventory and the events are stored and scanned. The payload is
 * dropped from the cache once that worked, so a retry still has it.
 */
class ProcessSecurityCollection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $deviceId, public string $cacheKey)
    {
        $this->onQueue(SecurityInbox::QUEUE);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** Collections of one device one after another, of different devices side by side. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('security:'.$this->deviceId))->releaseAfter(30)->expireAfter(360)];
    }

    public function handle(): void
    {
        $body = SecurityInbox::read($this->cacheKey);
        $device = Device::find($this->deviceId);
        if ($body === null || $device === null) {
            // Expired, already processed, or the device is gone.
            Cache::forget($this->cacheKey);

            return;
        }
        $payload = json_decode($body, true);
        if (is_array($payload)) {
            SecurityRule::syncBuiltIn();
            $parsers = SecurityRule::query()->enabled()->parsers()->get()->pluck('definition');
            $events = SecurityParsers::run($payload['logs'] ?? [], $parsers);
            // Events another agent parsed itself are taken as they are.
            if (is_array($payload['events'] ?? null)) {
                $events = array_merge($events, $payload['events']);
            }
            SecurityScanner::ingest($device, ['events' => $events] + ($payload['inventory'] ?? []));
        }
        Cache::forget($this->cacheKey);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning("Security collection of device {$this->deviceId} failed: ".($exception?->getMessage() ?? 'unknown'));
        Cache::forget($this->cacheKey);
    }
}
