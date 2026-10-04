<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a user is alerted about, as in Beszel: a condition on the devices of the target (all,
 * tags, picked devices). Status: offline for minutes; CPU / memory: the average over minutes
 * above the threshold (memory also: free GB below limit_gb); disk: a drive fuller than the
 * threshold, or with less than limit_gb free; disk health, services and
 * scripts: a problem reported by the device. New device: one is enrolled or added;
 * unknown device: an agent sees one the portal does not know in its network; address changed: a
 * ping-only device with a MAC address moved to another IP address (events).
 */
class AlertRule extends Model
{
    public const TYPES = [
        'status' => ['label' => 'Status', 'icon' => 'fas fa-plug', 'threshold' => null, 'minutes' => 5, 'description' => 'The device is offline'],
        'cpu' => ['label' => 'CPU usage', 'icon' => 'fas fa-microchip', 'threshold' => 80, 'minutes' => 10, 'description' => 'Average CPU usage above the threshold'],
        'memory' => ['label' => 'Memory usage', 'icon' => 'fas fa-memory', 'threshold' => 80, 'minutes' => 10, 'description' => 'Average usage above a percentage, or free memory below a size'],
        'disk' => ['label' => 'Disk usage', 'icon' => 'fas fa-hdd', 'threshold' => 90, 'minutes' => null, 'description' => 'A drive fuller than a percentage, or with less free space than a size'],
        'disk_health' => ['label' => 'Disk health', 'icon' => 'fas fa-heartbeat', 'threshold' => null, 'minutes' => null, 'description' => 'A disk reports a S.M.A.R.T. warning or failure'],
        'services' => ['label' => 'Services', 'icon' => 'fas fa-cogs', 'threshold' => null, 'minutes' => null, 'description' => 'A service failed or a container is unhealthy'],
        'scripts' => ['label' => 'Remediations', 'icon' => 'fas fa-scroll', 'threshold' => null, 'minutes' => null, 'description' => 'The latest run of a remediation script failed'],
        // An event, not a state: sent once per device, nothing is resolved.
        'new_device' => ['label' => 'New device', 'icon' => 'fas fa-plus-circle', 'threshold' => null, 'minutes' => null, 'description' => 'A device is enrolled or added', 'event' => true],
        'unknown_device' => ['label' => 'Unknown device', 'icon' => 'fas fa-question-circle', 'threshold' => null, 'minutes' => null, 'description' => 'An agent sees a device the portal does not know in its network', 'event' => true],
        'address_changed' => ['label' => 'Address changed', 'icon' => 'fas fa-random', 'threshold' => null, 'minutes' => null, 'description' => 'A ping-only device with a MAC address got another IP address (dynamic address)', 'event' => true],
    ];

    /** Types about the fleet, not about one device's state (no per-device switch, no target). */
    public static function isEvent(string $type): bool
    {
        return (bool) (self::TYPES[$type]['event'] ?? false);
    }

    public const MAX_MINUTES = 1440;

    /** Disk and memory alerts: used above a percentage, or free space / memory below a size. */
    public const UNITS = ['percent' => '%', 'gb' => 'GB'];

    /** Default limit (GB free) when switching to GB. */
    public const DEFAULT_LIMIT_GB = ['disk' => 10, 'memory' => 1];

    public const MAX_LIMIT_GB = 100000;

    protected $fillable = ['user_id', 'type', 'threshold', 'unit', 'limit_gb', 'minutes', 'target', 'channels', 'enabled'];

    protected $casts = [
        'target' => 'array',
        'channels' => 'array',
        'enabled' => 'boolean',
        'threshold' => 'integer',
        'minutes' => 'integer',
        'limit_gb' => 'float',
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

    /** Disk and memory can alert on free GB instead of the used percentage. */
    public static function usesUnit(string $type): bool
    {
        return in_array($type, ['disk', 'memory'], true);
    }

    /** Whether the rule compares free GB (otherwise the used percentage). */
    public function getInGbAttribute(): bool
    {
        return self::usesUnit($this->type) && $this->unit === 'gb';
    }

    /** "12.5" for 12.5, "10" for 10.0 */
    public static function formatGb(?float $gb): string
    {
        return rtrim(rtrim(number_format((float) $gb, 1, '.', ''), '0'), '.');
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
            'memory' => $this->inGb
                ? __('Average free memory below :limit GB for :minutes min', ['limit' => self::formatGb($this->limit_gb), 'minutes' => $this->minutes])
                : __('Average above :threshold % for :minutes min', ['threshold' => $this->threshold, 'minutes' => $this->minutes]),
            'cpu' => __('Average above :threshold % for :minutes min', ['threshold' => $this->threshold, 'minutes' => $this->minutes]),
            'disk' => $this->inGb
                ? __('A drive with less than :limit GB free', ['limit' => self::formatGb($this->limit_gb)])
                : __('A drive above :threshold %', ['threshold' => $this->threshold]),
            default => __(self::TYPES[$this->type]['description'] ?? ''),
        };
    }

    public function getTargetDescriptionAttribute(): string
    {
        return Device::describeTarget($this->target ?? []);
    }
}
