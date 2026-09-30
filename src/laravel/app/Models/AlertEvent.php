<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An alert of a rule on a device, open until resolved_at. */
class AlertEvent extends Model
{
    protected $fillable = ['alert_rule_id', 'device_id', 'message', 'value', 'triggered_at', 'resolved_at'];

    protected $casts = [
        'value' => 'float',
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
