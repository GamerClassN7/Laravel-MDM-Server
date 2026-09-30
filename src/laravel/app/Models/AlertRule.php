<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a user is alerted about, as in Beszel: a condition on the devices of the target (all,
 * tags, picked devices). Status: offline for minutes; CPU / memory: the average over minutes
 * above the threshold; disk: a drive fuller than the threshold; disk health, services and
 * scripts: a problem reported by the device.
 */
class AlertRule extends Model
{
    public const TYPES = [
        'status' => ['label' => 'Status', 'icon' => 'fas fa-plug', 'threshold' => null, 'minutes' => 5, 'description' => 'The device is offline'],
        'cpu' => ['label' => 'CPU usage', 'icon' => 'fas fa-microchip', 'threshold' => 80, 'minutes' => 10, 'description' => 'Average CPU usage above the threshold'],
        'memory' => ['label' => 'Memory usage', 'icon' => 'fas fa-memory', 'threshold' => 80, 'minutes' => 10, 'description' => 'Average memory usage above the threshold'],
        'disk' => ['label' => 'Disk usage', 'icon' => 'fas fa-hdd', 'threshold' => 90, 'minutes' => null, 'description' => 'A drive fuller than the threshold'],
        'disk_health' => ['label' => 'Disk health', 'icon' => 'fas fa-heartbeat', 'threshold' => null, 'minutes' => null, 'description' => 'A disk reports a S.M.A.R.T. warning or failure'],
        'services' => ['label' => 'Services', 'icon' => 'fas fa-cogs', 'threshold' => null, 'minutes' => null, 'description' => 'A service failed or a container is unhealthy'],
        'scripts' => ['label' => 'Remediations', 'icon' => 'fas fa-scroll', 'threshold' => null, 'minutes' => null, 'description' => 'The latest run of a remediation script failed'],
    ];

    public const MAX_MINUTES = 1440;

    protected $fillable = ['user_id', 'type', 'threshold', 'minutes', 'target', 'enabled'];

    protected $casts = [
        'target' => 'array',
        'enabled' => 'boolean',
        'threshold' => 'integer',
        'minutes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public static function usesThreshold(string $type): bool
    {
        return (self::TYPES[$type]['threshold'] ?? null) !== null;
    }

    public static function usesMinutes(string $type): bool
    {
        return (self::TYPES[$type]['minutes'] ?? null) !== null;
    }

    public function getLabelAttribute(): string
    {
        return __(self::TYPES[$this->type]['label'] ?? $this->type);
    }

    public function getIconAttribute(): string
    {
        return self::TYPES[$this->type]['icon'] ?? 'fas fa-bell';
    }

    /** "Offline for 5 min", "Above 80 % for 10 min", "Above 90 %", "S.M.A.R.T. problem" */
    public function getConditionAttribute(): string
    {
        return match ($this->type) {
            'status' => __('Offline for :minutes min', ['minutes' => $this->minutes]),
            'cpu', 'memory' => __('Average above :threshold % for :minutes min', ['threshold' => $this->threshold, 'minutes' => $this->minutes]),
            'disk' => __('A drive above :threshold %', ['threshold' => $this->threshold]),
            default => __(self::TYPES[$this->type]['description'] ?? ''),
        };
    }

    public function getTargetDescriptionAttribute(): string
    {
        return Device::describeTarget($this->target ?? []);
    }
}
