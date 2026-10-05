<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A rule of the security scanner (the language: App\Support\SecurityRules). Built-in rules come
 * from resources/security/rules.json and follow that file (they can be switched off, not
 * edited); the others are added in the portal by system admins.
 */
class SecurityRule extends Model
{
    public const BUILT_IN_PATH = 'security/rules.json';

    protected $fillable = ['key', 'name', 'severity', 'source', 'platform', 'definition', 'built_in', 'enabled'];

    protected $casts = [
        'definition' => 'array',
        'built_in' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function findings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** The columns of a rule from its definition (a valid one, see SecurityRules::errors). */
    public static function attributesFor(array $definition): array
    {
        return [
            'key' => $definition['key'],
            'name' => $definition['name'],
            'severity' => $definition['severity'],
            'source' => $definition['source'],
            'platform' => $definition['platform'] ?? 'any',
            'definition' => $definition,
        ];
    }

    /** Whether the rule looks at devices of this platform. */
    public function appliesTo(Device $device): bool
    {
        return $this->platform === 'any' || $this->platform === $device->platform;
    }

    public function getIsEventAttribute(): bool
    {
        return (bool) (SecurityRules::SOURCES[$this->source]['event'] ?? false);
    }

    public function getSeverityColorAttribute(): string
    {
        return SecurityRules::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }

    public function getSourceLabelAttribute(): string
    {
        return __(SecurityRules::SOURCES[$this->source]['label'] ?? $this->source);
    }

    /**
     * The built-in rules as they are in the file: new ones are added (switched on unless the
     * file says "enabled": false), changed ones updated (the switch stays), removed ones deleted
     * with their findings. Done when the file changed since the last time.
     */
    public static function syncBuiltIn(): void
    {
        $path = resource_path(self::BUILT_IN_PATH);
        $hash = is_file($path) ? md5_file($path) : null;
        if ($hash === null || Cache::get('mdm.security_rules') === $hash && static::query()->where('built_in', true)->exists()) {
            return;
        }

        $rules = json_decode((string) file_get_contents($path), true);
        if (! is_array($rules)) {
            report(new \RuntimeException('resources/'.self::BUILT_IN_PATH.' is not valid JSON.'));

            return;
        }
        DB::transaction(function () use ($rules) {
            $keys = [];
            foreach ($rules as $definition) {
                if (SecurityRules::errors($definition) !== []) {
                    report(new \RuntimeException('Built-in security rule '.json_encode($definition['key'] ?? null).': '.implode(' ', SecurityRules::errors($definition))));

                    continue;
                }
                $keys[] = $definition['key'];
                $rule = static::query()->firstOrNew(['key' => $definition['key']]);
                // A rule of the portal with the key of a new built-in one keeps its own definition.
                if ($rule->exists && ! $rule->built_in) {
                    continue;
                }
                $rule->fill(self::attributesFor($definition) + ['built_in' => true]);
                if (! $rule->exists) {
                    $rule->enabled = $definition['enabled'] ?? true;
                }
                $rule->save();
            }
            static::query()->where('built_in', true)->whereNotIn('key', $keys)->get()->each->delete();
        });
        Cache::forever('mdm.security_rules', $hash);
    }
}
