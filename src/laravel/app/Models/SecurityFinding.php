<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rule of the scanner that holds on a device. Findings of the inventory are resolved when the
 * next inventory does not match anymore; findings of events (they happened, nothing makes them
 * go away) are resolved by acknowledging them. An acknowledged finding of the inventory stays
 * open, quiet (no alert), until it is gone.
 */
class SecurityFinding extends Model
{
    protected $fillable = [
        'device_id', 'security_rule_id', 'fingerprint', 'severity', 'message', 'details', 'occurrences',
        'first_seen_at', 'last_seen_at', 'resolved_at', 'acknowledged_at', 'acknowledged_by',
    ];

    protected $casts = [
        'details' => 'array',
        'occurrences' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /** Open high and critical findings nobody acknowledged (the badge in the menu), counted once per request. */
    public static function severeCount(): int
    {
        return once(fn () => static::query()->active()->atLeast('high')->count());
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SecurityRule::class, 'security_rule_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** Open and not acknowledged: what needs attention. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->whereNull('acknowledged_at');
    }

    /** Ordered most severe first, then newest. */
    public function scopeBySeverity(Builder $query): Builder
    {
        $cases = collect(SecurityRules::SEVERITIES)->map(fn ($rank, $severity) => "when '$severity' then $rank")->implode(' ');

        return $query->orderByRaw("case severity $cases else 9 end")->latest('last_seen_at')->orderByDesc('id');
    }

    /** At least this severity (critical is the most severe). */
    public function scopeAtLeast(Builder $query, string $severity): Builder
    {
        $rank = SecurityRules::SEVERITIES[$severity] ?? 4;

        return $query->whereIn('severity', array_keys(array_filter(SecurityRules::SEVERITIES, fn ($value) => $value <= $rank)));
    }

    public function getSeverityColorAttribute(): string
    {
        return SecurityRules::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }

    public function getStatusAttribute(): string
    {
        return match (true) {
            $this->resolved_at !== null && $this->acknowledged_at !== null => 'acknowledged',
            $this->resolved_at !== null => 'resolved',
            $this->acknowledged_at !== null => 'acknowledged',
            default => 'open',
        };
    }

    /** Acknowledged: an event is done with it, a finding of the inventory goes quiet until it is gone. */
    public function acknowledge(?User $user): void
    {
        $this->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id,
            'resolved_at' => $this->rule?->isEvent ? now() : $this->resolved_at,
        ]);
    }

    /** Open again (a finding of the inventory that is still there, or an event to look at again). */
    public function reopen(): void
    {
        $this->update(['acknowledged_at' => null, 'acknowledged_by' => null, 'resolved_at' => $this->rule?->isEvent ? null : $this->resolved_at]);
    }
}
