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

    /**
     * The JSON of a request body: gunzipped when the agent compressed it (Content-Encoding: gzip, the
     * signature covers the compressed bytes, so this is only done after it was verified), null when it is
     * not a JSON object or inflates to more than MAX_BYTES (a zip bomb).
     */
    public static function decode(string $raw, bool $gzip): ?string
    {
        $json = $gzip ? @zlib_decode($raw, self::MAX_BYTES) : $raw;
        if (! is_string($json) || strlen($json) > self::MAX_BYTES || ! json_validate($json) || ! str_starts_with(ltrim($json), '{')) {
            return null;
        }

        return $json;
    }

    /**
     * Takes a collection of a device: into the cache, gzipped (what the agent sent is kept as it came) and
     * encrypted, and a job to process it.
     */
    public static function push(Device $device, string $body, bool $gzip = false): string
    {
        $key = 'mdm.security_inbox.'.Str::uuid();
        Cache::put($key, Crypt::encryptString(base64_encode($gzip ? $body : gzencode($body, 6))), self::TTL);

        if (config('queue.default') === 'sync') {
            ProcessSecurityCollection::dispatchAfterResponse($device->id, $key);
        } else {
            ProcessSecurityCollection::dispatch($device->id, $key);
        }

        return $key;
    }

    /** The JSON of the collection of a key, null when it is gone or unreadable. */
    public static function read(string $key): ?string
    {
        $stored = Cache::get($key);
        if (! is_string($stored)) {
            return null;
        }
        try {
            $body = @zlib_decode(base64_decode(Crypt::decryptString($stored), true) ?: '', self::MAX_BYTES);
        } catch (Throwable) {
            return null;
        }

        return is_string($body) ? $body : null;
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
