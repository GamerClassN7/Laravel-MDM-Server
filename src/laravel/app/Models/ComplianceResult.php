<?php

namespace App\Models;

use App\Support\CompliancePolicies;
use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The status of a check of a compliance policy on a device (App\Support\ComplianceScanner). */
class ComplianceResult extends Model
{
    protected $fillable = ['device_id', 'compliance_policy_id', 'check_id', 'status', 'severity', 'message', 'details', 'changed_at', 'evaluated_at'];

    protected $casts = [
        'details' => 'array',
        'changed_at' => 'datetime',
        'evaluated_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CompliancePolicy::class, 'compliance_policy_id');
    }

    /** Ordered worst status first, then most severe. */
    public function scopeWorstFirst(Builder $query): Builder
    {
        $statuses = collect(CompliancePolicies::STATUSES)->map(fn ($rank, $status) => "when '$status' then $rank")->implode(' ');
        $severities = collect(SecurityRules::SEVERITIES)->map(fn ($rank, $severity) => "when '$severity' then $rank")->implode(' ');

        return $query->orderByRaw("case status $statuses else 9 end")->orderByRaw("case severity $severities else 9 end");
    }

    public function getStatusLabelAttribute(): string
    {
        return __(CompliancePolicies::STATUS_LABELS[$this->status] ?? $this->status);
    }

    public function getStatusColorAttribute(): string
    {
        return CompliancePolicies::STATUS_COLORS[$this->status] ?? 'secondary';
    }

    public function getSeverityColorAttribute(): string
    {
        return SecurityRules::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }
}
