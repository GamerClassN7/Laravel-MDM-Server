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

    /** Open alerts of the user's rules (the badge in the menu), counted once per request. */
    public static function firingCountFor(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return once(fn () => static::query()->whereNull('resolved_at')
            ->whereIn('alert_rule_id', AlertRule::query()->where('user_id', $user->id)->select('id'))->count());
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
