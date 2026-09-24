<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class DeviceMetric extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** Days the samples are kept. */
    public const RETENTION_DAYS = 7;

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
