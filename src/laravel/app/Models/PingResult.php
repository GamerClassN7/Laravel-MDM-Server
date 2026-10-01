<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One ping of a ping-only device by an agent of its network: answered or not, the round trip. */
class PingResult extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 30;

    protected $fillable = ['device_id', 'up', 'rtt', 'relay_id'];

    protected $casts = [
        'up' => 'boolean',
        'rtt' => 'float',
        'created_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** Share of answered pings since the time, in percent with two decimals; null without pings. */
    public static function uptime(int $deviceId, \DateTimeInterface $since): ?float
    {
        $row = static::query()->where('device_id', $deviceId)->where('created_at', '>=', $since)
            ->selectRaw('count(*) as total, sum(case when up then 1 else 0 end) as answered')->first();

        return (int) $row->total === 0 ? null : round((int) $row->answered / (int) $row->total * 100, 2);
    }
}
