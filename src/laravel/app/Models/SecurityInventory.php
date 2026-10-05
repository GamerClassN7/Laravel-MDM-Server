<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The last security inventory of a device (agents 1.17.0+): what the rules of the scanner look
 * at (App\Support\SecurityRules::SOURCES). Events are not kept here, they go to SecurityEvent.
 */
class SecurityInventory extends Model
{
    /** At most this many items per list are kept. */
    public const LIMITS = ['software' => 5000, 'processes' => 1000, 'listening' => 1000, 'startup' => 500, 'admins' => 200, 'events' => SecurityEvent::MAX_PER_COLLECTION];

    /** Texts are cut to this many characters (command lines are long). */
    private const MAX_TEXT = 1000;

    protected $fillable = ['device_id', 'data', 'collected_at'];

    protected $casts = [
        'data' => 'array',
        'collected_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Only the known lists and fields: scalars (texts cut), numbers of Count and Port as
     * integers, posture values as booleans, numbers or texts. Events also need a known type.
     *
     * @return array{software: array, processes: array, listening: array, startup: array, admins: array, posture: array, events: array}
     */
    public static function sanitize(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $clean = [];
        foreach (SecurityRules::SOURCES as $source => $definition) {
            if ($source === 'posture') {
                $clean['posture'] = self::posture($data['posture'] ?? null, $definition['fields']);

                continue;
            }
            $items = $data[$source] ?? [];
            // A single item serialized by PowerShell as an object instead of a list.
            if (is_array($items) && ! array_is_list($items)) {
                $items = [$items];
            }
            $clean[$source] = [];
            foreach (is_array($items) ? $items : [] as $item) {
                if (count($clean[$source]) >= self::LIMITS[$source]) {
                    break;
                }
                if (! is_array($item)) {
                    continue;
                }
                $row = [];
                foreach ($definition['fields'] as $field) {
                    $row[$field] = self::value($item[$field] ?? null, $field);
                }
                if ($source === 'events') {
                    // Types come from the parsers (built-in ones in SecurityRules::EVENT_TYPES).
                    if (! is_string($row['Type']) || ! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $row['Type'])) {
                        continue;
                    }
                    $row['Count'] = max(1, (int) $row['Count']);
                    $row['Last'] = self::time($row['Last']);
                }
                $clean[$source][] = $row;
            }
        }

        return $clean;
    }

    private static function posture(mixed $posture, array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $value = is_array($posture) ? ($posture[$field] ?? null) : null;
            $clean[$field] = is_bool($value) || is_int($value) || is_float($value) ? $value : self::value($value, $field);
        }

        return $clean;
    }

    private static function value(mixed $value, string $field): mixed
    {
        if (in_array($field, ['Count', 'Port', 'DaysSinceUpdate'], true)) {
            return is_numeric($value) ? (int) $value : null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_strimwidth($text, 0, self::MAX_TEXT, '…');
    }

    private static function time(mixed $value): string
    {
        try {
            $time = $value ? Carbon::parse((string) $value) : now();
        } catch (Throwable) {
            $time = now();
        }

        // Not from the future (a device clock that is off), not older than the retention.
        return $time->min(now())->max(now()->subDays(SecurityEvent::RETENTION_DAYS))->toDateTimeString();
    }

    /** One list of the inventory ([] for an unknown one). */
    public function items(string $source): array
    {
        return $source === 'posture' ? [$this->data['posture'] ?? []] : ($this->data[$source] ?? []);
    }
}
