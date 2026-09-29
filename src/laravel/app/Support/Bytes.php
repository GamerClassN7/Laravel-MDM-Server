<?php

namespace App\Support;

class Bytes
{
    /**
     * Human readable size (1024 based) without the intl extension that Number::fileSize needs.
     */
    public static function format(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return ($bytes >= 100 || $unit === 0 ? round($bytes) : round($bytes, 1)).' '.$units[$unit];
    }
}
