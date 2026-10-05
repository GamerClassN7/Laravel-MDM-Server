<?php

namespace App\Support;

use App\Jobs\ProcessSecurityCollection;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the agents send for the security scanner is large (thousands of log lines, the software
 * list), so taking it is only putting it into the cache, compressed and encrypted (in Docker the
 * cache is a table of the database), and queueing a job: the request is answered right away.
 * The job (ProcessSecurityCollection, run by the queue worker) parses, stores and scans it.
 * Without a queue (QUEUE_CONNECTION=sync) it runs once the response has been sent.
 */
class SecurityInbox
{
    public const QUEUE = 'security';

    /** A collection not processed within this time is dropped (the next one comes in an hour). */
    public const TTL = 86400;

    /** A request body larger than this is refused (bytes). */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** Takes a collection of a device: into the cache, a job to process it. */
    public static function push(Device $device, string $body): string
    {
        $key = 'mdm.security_inbox.'.Str::uuid();
        Cache::put($key, Crypt::encryptString(base64_encode(gzcompress($body, 6))), self::TTL);

        if (config('queue.default') === 'sync') {
            ProcessSecurityCollection::dispatchAfterResponse($device->id, $key);
        } else {
            ProcessSecurityCollection::dispatch($device->id, $key);
        }

        return $key;
    }

    /** The collection of a key, null when it is gone or unreadable. */
    public static function read(string $key): ?string
    {
        $stored = Cache::get($key);
        if (! is_string($stored)) {
            return null;
        }
        try {
            $body = gzuncompress(base64_decode(Crypt::decryptString($stored), true) ?: '');
        } catch (Throwable) {
            return null;
        }

        return $body === false ? null : $body;
    }

    /** How many collections wait in the queue (0 without a queue worker to count). */
    public static function pending(): int
    {
        try {
            return config('queue.default') === 'sync' ? 0 : Queue::size(self::QUEUE);
        } catch (Throwable) {
            return 0;
        }
    }
}
