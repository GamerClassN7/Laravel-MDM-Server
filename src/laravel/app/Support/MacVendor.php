<?php

namespace App\Support;

/**
 * The vendor of a network card from the first bytes of its MAC address (IEEE registries, in
 * resources/oui, one file per first byte). Offline: no address leaves the server.
 */
class MacVendor
{
    /** @var array<string, array<string, string>> the files read so far */
    private static array $files = [];

    /** The vendor name, or null when the prefix is unknown or the address is not a global one (random). */
    public static function lookup(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));
        if (strlen($hex) !== 12 || self::isLocal($hex)) {
            return null;
        }
        $rest = substr($hex, 2);
        $names = self::file(substr($hex, 0, 2));

        // The longest registered prefix wins (MA-S 7 characters, MA-M 5, MA-L 4 after the first byte).
        return $names[substr($rest, 0, 7)] ?? $names[substr($rest, 0, 5)] ?? $names[substr($rest, 0, 4)] ?? null;
    }

    /** A locally administered address (a phone's private Wi-Fi address, a VM): it names no vendor. */
    public static function isLocal(string $mac): bool
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $mac);

        return strlen($hex) >= 2 && (hexdec(substr($hex, 0, 2)) & 2) === 2;
    }

    private static function file(string $byte): array
    {
        if (! isset(self::$files[$byte])) {
            $path = dirname(__DIR__, 2).'/resources/oui/'.$byte.'.json';
            self::$files[$byte] = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }

        return self::$files[$byte];
    }
}
