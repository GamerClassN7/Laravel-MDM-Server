<?php

namespace App\Models;

use App\Support\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's notification channels: e-mail addresses and push / webhook URLs. */
class NotificationSetting extends Model
{
    public const MAX_CHANNELS = 10;

    protected $fillable = ['user_id', 'emails', 'urls'];

    protected $casts = [
        'emails' => 'array',
        'urls' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function for(User $user): self
    {
        return static::query()->firstOrNew(['user_id' => $user->id]);
    }

    /** @return array<int, string> */
    public function getEmailListAttribute(): array
    {
        return array_values(array_filter((array) ($this->emails ?? []), fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)));
    }

    /** @return array<int, string> */
    public function getUrlListAttribute(): array
    {
        return array_values(array_filter((array) ($this->urls ?? []), fn ($url) => is_string($url) && Notifier::validUrl($url)));
    }

    public function getHasChannelsAttribute(): bool
    {
        return $this->emailList !== [] || $this->urlList !== [];
    }
}
