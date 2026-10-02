<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The address of the portal for links made outside a web request (notifications of the scheduler):
 * APP_URL when it is set to a real address, otherwise the one the system admins open the portal
 * with (a request's Host header could be anything, so only theirs is taken).
 */
class PortalUrl
{
    /** Where it is kept; tests set another file. */
    public static ?string $file = null;

    public static function path(): string
    {
        return self::$file ?? storage_path('app/portal-url');
    }

    /** APP_URL is set to a real address (not the http://localhost default). */
    public static function configured(): bool
    {
        return self::isReal((string) config('app.url'));
    }

    /** An http(s) root URL (also under a path, /mdm) whose host is not this machine's loopback. */
    private static function isReal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_match('#^https?://[^/\s?\#]+(/[^\s?\#]*)?$#i', $url) === 1
            && $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    public static function remembered(): ?string
    {
        try {
            $url = is_file(self::path()) ? trim((string) file_get_contents(self::path())) : '';
        } catch (Throwable) {
            return null;
        }

        return self::isReal($url) ? $url : null;
    }

    /**
     * Keeps the root URL of this web request (a signed-in user's page); not localhost (a tunnel
     * or the server itself), which would be no address for the others.
     */
    public static function remember(string $url): void
    {
        $url = rtrim($url, '/');
        if (! self::isReal($url) || self::remembered() === $url) {
            return;
        }
        try {
            file_put_contents(self::path(), $url, LOCK_EX);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Outside a web request: links go to the portal's real address. */
    public static function apply(): void
    {
        if (self::configured() || ($url = self::remembered()) === null) {
            return;
        }
        URL::forceRootUrl($url);
        URL::forceScheme((string) parse_url($url, PHP_URL_SCHEME));
    }
}
