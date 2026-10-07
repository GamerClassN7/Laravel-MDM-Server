<?php

namespace App\Models;

use App\Support\CompliancePolicies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A compliance policy (App\Support\CompliancePolicies): from the rules feed (policies/*.json) or
 * added in the portal by system admins. A policy of the feed is shown and switched on and off,
 * not edited (a copy can be); one of the portal's own is never replaced by the feed.
 */
class CompliancePolicy extends Model
{
    public const FEED = 'feed';

    public const CUSTOM = 'custom';

    private const RANK = [self::FEED => 2, self::CUSTOM => 3];

    protected $fillable = ['key', 'name', 'platform', 'definition', 'origin', 'feed_version', 'enabled'];

    protected $casts = [
        'definition' => 'array',
        'enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        $bump = fn () => static::touchVersion();
        static::saved($bump);
        static::deleted($bump);
    }

    /** Says that policies changed: every device is evaluated again on its next scan. */
    public static function touchVersion(): void
    {
        Cache::forever('mdm.compliance_version', (int) Cache::get('mdm.compliance_version', 0) + 1);
    }

    /** What an evaluation remembers of the policies: when it is the same, so are the results on the same inventory. */
    public static function versionToken(): string
    {
        return Cache::get('mdm.compliance_version', 0).'|'.static::query()->count().'|'.static::query()->max('updated_at');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ComplianceResult::class);
    }

    public function getBuiltInAttribute(): bool
    {
        return $this->origin !== self::CUSTOM;
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('origin', self::CUSTOM);
    }

    /** The checks of the policy by id. */
    public function checks(): array
    {
        $checks = [];
        foreach ($this->definition['checks'] ?? [] as $check) {
            $checks[$check['id']] = $check;
        }

        return $checks;
    }

    /** The columns of a policy from its definition (a valid one). */
    public static function attributesFor(array $definition): array
    {
        return [
            'key' => $definition['key'],
            'name' => $definition['name'],
            'platform' => $definition['platform'] ?? 'any',
            'definition' => $definition,
        ];
    }

    /**
     * Makes the policies of an origin the given definitions (as SecurityRule::apply): new ones are
     * added, changed ones updated (the switch stays), the ones not given anymore deleted with their
     * results; a policy of the portal's own keeps its definition. Invalid definitions are skipped.
     *
     * @param  iterable<int, mixed>  $definitions
     * @return array{added: int, updated: int, removed: int, skipped: array<int, string>}
     */
    public static function apply(iterable $definitions, string $origin, ?string $version = null): array
    {
        $result = ['added' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => []];
        DB::transaction(function () use ($definitions, $origin, $version, &$result) {
            $keys = [];
            foreach ($definitions as $definition) {
                if (($errors = CompliancePolicies::errors($definition)) !== []) {
                    $result['skipped'][] = (is_array($definition) && is_string($definition['key'] ?? null) ? $definition['key'] : '?').': '.implode(' ', array_slice($errors, 0, 5));

                    continue;
                }
                $keys[] = $definition['key'];
                $policy = static::query()->firstOrNew(['key' => $definition['key']]);
                if ($policy->exists && self::RANK[$policy->origin] > self::RANK[$origin]) {
                    continue;
                }
                $policy->fill(self::attributesFor($definition) + ['origin' => $origin, 'feed_version' => $origin === self::FEED ? $version : null]);
                if (! $policy->exists) {
                    $policy->enabled = $definition['enabled'] ?? true;
                    $result['added']++;
                } elseif ($policy->isDirty()) {
                    $result['updated']++;
                }
                $policy->save();
            }
            $gone = static::query()->where('origin', $origin)->whereNotIn('key', $keys)->get();
            $result['removed'] = $gone->count();
            $gone->each->delete();
        });

        return $result;
    }
}
