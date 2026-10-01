<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

class DeviceMetric extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** Days the samples are kept. */
    public const RETENTION_DAYS = 30;

    protected $fillable = ['device_id', 'cpu', 'memory_used', 'memory_total'];

    protected $casts = [
        'cpu' => 'float',
        'memory_used' => 'integer',
        'memory_total' => 'integer',
    ];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    public function getMemoryPercentAttribute(): float
    {
        return $this->memory_total > 0 ? round($this->memory_used / $this->memory_total * 100, 1) : 0;
    }

    /**
     * Validates metrics sent by the agent, returns null when they are missing or invalid.
     */
    /** Samples one backfill request may carry (agents send at most this many at once). */
    public const BACKFILL_MAX = 600;

    /**
     * Samples an agent collected while it could not reach the server (agents 1.11.0+), with the
     * time each was taken: [{at: unix time, cpu, memory_used, memory_total}]. Only from the last
     * RETENTION_DAYS and not from the future; a sample next to one already stored (a batch sent
     * again) is skipped. Returns how many were stored.
     */
    public static function backfill(int $deviceId, mixed $samples): int
    {
        if (! is_array($samples)) {
            return 0;
        }
        $oldest = now()->subDays(self::RETENTION_DAYS)->getTimestamp();
        $newest = now()->addMinute()->getTimestamp();
        $rows = [];
        foreach (array_slice($samples, 0, self::BACKFILL_MAX) as $sample) {
            $at = is_array($sample) && is_numeric($sample['at'] ?? null) ? (int) $sample['at'] : null;
            $metrics = self::sanitize($sample);
            if ($at === null || $metrics === null || $at < $oldest || $at > $newest) {
                continue;
            }
            $rows[$at] = $metrics + ['device_id' => $deviceId, 'created_at' => Carbon::createFromTimestamp($at, config('app.timezone'))];
        }
        if ($rows === []) {
            return 0;
        }
        ksort($rows);
        $taken = 0;
        foreach ($rows as $at => $row) {
            $near = static::query()->where('device_id', $deviceId)
                ->whereBetween('created_at', [Carbon::createFromTimestamp($at - 10, config('app.timezone')), Carbon::createFromTimestamp($at + 10, config('app.timezone'))])->exists();
            if (! $near) {
                static::query()->insert($row);
                $taken++;
            }
        }

        return $taken;
    }

    public static function sanitize(mixed $data): ?array
    {
        if (! is_array($data) || ! isset($data['cpu'], $data['memory_used'], $data['memory_total'])) {
            return null;
        }

        if (! is_numeric($data['cpu']) || ! is_numeric($data['memory_used']) || ! is_numeric($data['memory_total']) || $data['memory_total'] <= 0) {
            return null;
        }

        return [
            'cpu' => max(0, min(100, round((float) $data['cpu'], 2))),
            'memory_total' => (int) $data['memory_total'],
            'memory_used' => max(0, min((int) $data['memory_total'], (int) $data['memory_used'])),
        ];
    }
}
