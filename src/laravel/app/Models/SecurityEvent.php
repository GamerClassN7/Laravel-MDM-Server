<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an agent saw happen since its previous collection (failed sign-ins, accounts created,
 * logs cleared, ...), grouped by type, user and source with how often: the timeline of the
 * Security page. The rules of the "events" source turn them into findings.
 */
class SecurityEvent extends Model
{
    use MassPrunable;

    public const RETENTION_DAYS = 30;

    /** At most this many (grouped) events are taken from one collection. */
    public const MAX_PER_COLLECTION = 500;

    protected $fillable = ['device_id', 'type', 'count', 'user', 'source', 'message', 'occurred_at'];

    protected $casts = [
        'count' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function prunable(): Builder
    {
        return static::where('occurred_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function getLabelAttribute(): string
    {
        return __(SecurityRules::EVENT_TYPES[$this->type] ?? \Illuminate\Support\Str::headline($this->type));
    }

    /**
     * Stores the events of a collection (SecurityInventory::sanitize cleaned them).
     *
     * @param  array<int, array>  $events
     */
    public static function record(Device $device, array $events): void
    {
        $rows = [];
        foreach (array_slice($events, 0, self::MAX_PER_COLLECTION) as $event) {
            $rows[] = [
                'device_id' => $device->id,
                'type' => $event['Type'],
                'count' => $event['Count'],
                'user' => $event['User'],
                'source' => $event['Source'],
                'message' => $event['Message'],
                'occurred_at' => $event['Last'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            static::query()->insert($chunk);
        }
    }
}
