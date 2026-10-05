<?php

namespace App\Models;

use App\Support\SecurityRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A rule of the security scanner: a detection rule (kind "detection", App\Support\SecurityRules)
 * or a parser of raw logs (kind "parser", App\Support\SecurityParsers). Built-in ones come from
 * resources/security/rules.json and parsers.json and follow those files (they can be switched
 * off, not edited); the others are added in the portal by system admins.
 */
class SecurityRule extends Model
{
    public const BUILT_IN_PATHS = ['detection' => 'security/rules.json', 'parser' => 'security/parsers.json'];

    protected $fillable = ['kind', 'key', 'name', 'severity', 'source', 'platform', 'definition', 'built_in', 'enabled'];

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

    public function scopeDetection(Builder $query): Builder
    {
        return $query->where('kind', 'detection');
    }

    public function scopeParsers(Builder $query): Builder
    {
        return $query->where('kind', 'parser');
    }

    /** What is wrong with a definition of either kind (empty when it can be used). */
    public static function errorsOf(mixed $definition): array
    {
        return is_array($definition) && ($definition['kind'] ?? null) === 'parser'
            ? \App\Support\SecurityParsers::errors($definition)
            : SecurityRules::errors($definition);
    }

    /** The columns of a rule from its definition (a valid one, see errorsOf). */
    public static function attributesFor(array $definition): array
    {
        $parser = ($definition['kind'] ?? null) === 'parser';

        return [
            'kind' => $parser ? 'parser' : 'detection',
            'key' => $definition['key'],
            'name' => $definition['name'],
            'severity' => $parser ? null : $definition['severity'],
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

    public function getIsParserAttribute(): bool
    {
        return $this->kind === 'parser';
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
        return __(SecurityRules::SOURCES[$this->source]['label'] ?? \App\Support\SecurityParsers::LOG_SOURCES[$this->source]['label'] ?? $this->source);
    }

    /**
     * The built-in rules and parsers as they are in their files: new ones are added (switched on
     * unless the file says "enabled": false), changed ones updated (the switch stays), removed
     * ones deleted with their findings. Done when a file changed since the last time.
     */
    public static function syncBuiltIn(): void
    {
        $hashes = [];
        foreach (self::BUILT_IN_PATHS as $kind => $path) {
            $hashes[$kind] = is_file(resource_path($path)) ? md5_file(resource_path($path)) : null;
        }
        $hash = md5(implode('|', $hashes));
        if (Cache::get('mdm.security_rules') === $hash && static::query()->where('built_in', true)->exists()) {
            return;
        }

        $files = [];
        foreach (self::BUILT_IN_PATHS as $kind => $path) {
            if ($hashes[$kind] === null) {
                continue;
            }
            $rules = json_decode((string) file_get_contents(resource_path($path)), true);
            if (! is_array($rules)) {
                report(new \RuntimeException("resources/$path is not valid JSON."));

                return;
            }
            $files[$kind] = $rules;
        }
        DB::transaction(function () use ($files) {
            $keys = [];
            foreach ($files as $kind => $rules) {
                foreach ($rules as $definition) {
                    if ($kind === 'parser' && is_array($definition)) {
                        $definition = ['kind' => 'parser'] + $definition;
                    }
                    if (($errors = self::errorsOf($definition)) !== []) {
                        report(new \RuntimeException('Built-in security rule '.json_encode($definition['key'] ?? null).': '.implode(' ', $errors)));

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
            }
            static::query()->where('built_in', true)->whereNotIn('key', $keys)->get()->each->delete();
        });
        Cache::forever('mdm.security_rules', $hash);
    }
}
