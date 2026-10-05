<?php

namespace App\Support;

use App\Models\Device;
use App\Models\SecurityRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the agents send for the security scanner is large (thousands of log lines, the software
 * list), so taking it is only putting it into the cache (compressed): the request is answered
 * right away. Parsing the logs, storing and scanning come after: once the response is sent, and
 * every minute from the scheduler for what is left (a PHP worker that ended, a busy minute).
 */
class SecurityInbox
{
    private const INDEX = 'mdm.security_inbox';

    private const LOCK = 'mdm.security_inbox.lock';

    /** A collection not processed within this time is dropped (the next one comes in an hour). */
    public const TTL = 86400;

    /** At most this many collections wait; the oldest are dropped. */
    public const MAX_PENDING = 1000;

    /** A request body larger than this is refused (bytes). */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** Takes a collection of a device: into the cache, its key into the list of what waits. */
    public static function push(Device $device, string $body): string
    {
        $key = 'mdm.security_inbox.'.Str::uuid();
        Cache::put($key, ['device_id' => $device->id, 'received_at' => now()->toIso8601String(), 'body' => base64_encode(gzcompress($body, 6))], self::TTL);
        Cache::lock(self::LOCK, 10)->block(5, function () use ($key) {
            $keys = Cache::get(self::INDEX, []);
            $keys[] = $key;
            foreach (array_splice($keys, 0, max(0, count($keys) - self::MAX_PENDING)) as $dropped) {
                Cache::forget($dropped);
            }
            Cache::put(self::INDEX, $keys, self::TTL);
        });

        return $key;
    }

    /** How many collections wait. */
    public static function pending(): int
    {
        return count(Cache::get(self::INDEX, []));
    }

    /**
     * Processes what waits, oldest first, for at most $seconds. Each collection is taken out of
     * the list under the lock first, so two runs at the same time never process one twice.
     *
     * @return int processed collections
     */
    public static function drain(int $seconds = 50): int
    {
        $until = microtime(true) + $seconds;
        $done = 0;
        while (microtime(true) < $until && ($key = self::take()) !== null) {
            $item = Cache::pull($key);
            if (! is_array($item)) {
                continue;
            }
            try {
                self::process($item);
                $done++;
            } catch (Throwable $e) {
                Log::warning("Security collection of device {$item['device_id']} failed: {$e->getMessage()}");
            }
        }

        return $done;
    }

    private static function take(): ?string
    {
        return Cache::lock(self::LOCK, 10)->block(5, function () {
            $keys = Cache::get(self::INDEX, []);
            $key = array_shift($keys);
            if ($key !== null) {
                Cache::put(self::INDEX, $keys, self::TTL);
            }

            return $key;
        });
    }

    /** One collection: the logs parsed into events, then the inventory and the events scanned. */
    private static function process(array $item): void
    {
        $device = Device::find($item['device_id']);
        $body = @gzuncompress((string) base64_decode((string) $item['body'], true));
        if ($device === null || $body === false) {
            return;
        }
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return;
        }

        SecurityRule::syncBuiltIn();
        $parsers = SecurityRule::query()->enabled()->where('kind', 'parser')->get()->pluck('definition');
        $events = SecurityParsers::run($payload['logs'] ?? [], $parsers);
        // Events another agent parsed itself are taken as they are.
        if (is_array($payload['events'] ?? null)) {
            $events = array_merge($events, $payload['events']);
        }
        SecurityScanner::ingest($device, ['events' => $events] + ($payload['inventory'] ?? []));
    }
}
