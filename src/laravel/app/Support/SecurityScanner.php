<?php

namespace App\Support;

use App\Models\Device;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use App\Models\SecurityInventoryItem;
use App\Models\SecurityRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;
use Throwable;

/**
 * Runs the rules (App\Support\SecurityRules) on a device's security inventory: a finding opens
 * for each item a rule matches and is resolved when the next inventory does not have it
 * anymore. Events are matched once, when they come in.
 */
class SecurityScanner
{
    /** At most this many findings per rule and device (a rule that matches everything). */
    public const MAX_FINDINGS_PER_RULE = 50;

    /**
     * The collection policy for the agents: what to send of the logs. Nothing when the portal takes no logs.
     *
     * @return array{version: string, sources: array<string, array<int, string|int>|null>}
     */
    public static function policy(): array
    {
        SecurityRule::syncBuiltIn();
        $parsers = self::logsAllowed() ? SecurityRule::query()->enabled()->parsers()->get()->pluck('definition') : collect();

        return SecurityParsers::policy($parsers);
    }

    /** The portal setting that turns the logs of all agents off (system admins, Security page). */
    public const LOGS_SETTING = 'mdm.security_logs';

    /** Whether the portal takes the logs the agents send (on unless a system admin turned it off). */
    public static function logsAllowed(): bool
    {
        return (string) Setting::query()->whereNull('settable_id')->where('index', self::LOGS_SETTING)->value('value') !== '0';
    }

    public static function setLogsAllowed(bool $allowed): void
    {
        Setting::query()->whereNull('settable_id')->updateOrCreate(['index' => self::LOGS_SETTING], ['value' => $allowed ? '1' : '0', 'type' => SettingDataType::BOOL]);
    }

    /**
     * The sources of a collection as an agent sends them, cleaned: each {source, state} (unchanged),
     * {source, base, state, upsert: [[key, hash, item], ...], remove: [key, ...]} (a delta) or
     * {source, full: true, state, upsert: [...]} (everything). Null when something is not right.
     *
     * @return array<int, array>|null
     */
    public static function normalizeSources(mixed $sources): ?array
    {
        if (! is_array($sources) || ! array_is_list($sources) || count($sources) > count(SecurityInventory::SOURCES)) {
            return null;
        }
        $clean = [];
        $isKey = fn ($key) => is_string($key) && (preg_match('/^[0-9a-f]{16}$/', $key) || $key === SecurityInventory::POSTURE_KEY);
        foreach ($sources as $delta) {
            $source = is_array($delta) ? ($delta['source'] ?? null) : null;
            if (! in_array($source, SecurityInventory::SOURCES, true) || isset($clean[$source])
                || ! is_string($delta['state'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $delta['state'])
                || (isset($delta['base']) && (! is_string($delta['base']) || ! preg_match('/^[0-9a-f]{64}$/', $delta['base'])))) {
                return null;
            }
            $upsert = [];
            foreach ($delta['upsert'] ?? [] as $row) {
                if (! is_array($row) || count($row) !== 3 || ! $isKey($row[0]) || ! is_string($row[1]) || ! preg_match('/^[0-9a-f]{16}$/', $row[1]) || ! is_array($row[2])) {
                    return null;
                }
                $upsert[] = [$row[0], $row[1], SecurityInventory::sanitizeItem($source, $row[2])];
            }
            $remove = array_values(array_filter($delta['remove'] ?? [], $isKey));
            if (count($upsert) > SecurityInventory::LIMITS[$source] || count($remove) > SecurityInventory::LIMITS[$source] * 2) {
                return null;
            }
            $clean[$source] = [
                'source' => $source,
                'state' => $delta['state'],
                'base' => $delta['base'] ?? null,
                'full' => (bool) ($delta['full'] ?? false),
                'upsert' => $upsert,
                'remove' => $remove,
            ];
        }

        return array_values($clean);
    }

    /**
     * The sources whose delta does not follow what the server has stored (the agent sends them
     * whole next): checked when a collection comes in, before anything is stored.
     *
     * @param  array<int, array>  $sources  from normalizeSources
     * @return array<int, string>
     */
    public static function mismatches(Device $device, array $sources): array
    {
        $states = SecurityInventory::query()->where('device_id', $device->id)->value('states');
        $states = is_array($states) ? $states : (is_string($states) ? (json_decode($states, true) ?: []) : []);
        $wrong = [];
        foreach ($sources as $delta) {
            if (! self::follows($states[$delta['source']] ?? null, $delta)) {
                $wrong[] = $delta['source'];
            }
        }

        return $wrong;
    }

    /** Whether a delta applies to a stored state (everything does; an unchanged source needs the same state). */
    private static function follows(?string $stored, array $delta): bool
    {
        if ($delta['full']) {
            return true;
        }
        return self::unchanged($delta) ? $stored === $delta['state'] : $stored === $delta['base'];
    }

    /** A source the agent reports only by its state: nothing changed since the state the server has. */
    private static function unchanged(array $delta): bool
    {
        return ! $delta['full'] && $delta['base'] === null && $delta['upsert'] === [] && $delta['remove'] === [];
    }

    /**
     * Applies the deltas of a collection in one transaction: a source is only changed when its
     * stored state is the one the delta follows, and the result must have the state the agent
     * computed (otherwise nothing of the collection is stored).
     *
     * @param  array<int, array>  $sources  from normalizeSources
     * @throws SecurityStateMismatch
     */
    public static function applyDeltas(Device $device, array $sources): void
    {
        DB::transaction(function () use ($device, $sources) {
            $inventory = SecurityInventory::query()->where('device_id', $device->id)->lockForUpdate()->first()
                ?? new SecurityInventory(['device_id' => $device->id, 'states' => []]);
            $states = $inventory->states ?? [];

            foreach ($sources as $delta) {
                $source = $delta['source'];
                if (! self::follows($states[$source] ?? null, $delta)) {
                    throw new SecurityStateMismatch($source);
                }
                if (self::unchanged($delta)) {
                    // Unchanged: the stored state is the agent's state (checked above).
                    continue;
                }
                $items = SecurityInventoryItem::query()->where('device_id', $device->id)->where('source', $source);
                if ($delta['full']) {
                    (clone $items)->delete();
                }
                foreach (array_chunk($delta['remove'], 500) as $keys) {
                    (clone $items)->whereIn('item_key', $keys)->delete();
                }
                $rows = array_map(fn ($row) => [
                    'device_id' => $device->id, 'source' => $source, 'item_key' => $row[0], 'item_hash' => $row[1], 'data' => json_encode($row[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], $delta['upsert']);
                foreach (array_chunk($rows, 200) as $chunk) {
                    SecurityInventoryItem::query()->upsert($chunk, ['device_id', 'source', 'item_key'], ['item_hash', 'data']);
                }
                // What is stored must add up to the state the agent has.
                $hashes = (clone $items)->pluck('item_hash', 'item_key')->all();
                if (count($hashes) > SecurityInventory::LIMITS[$source] || SecurityInventory::stateOf($hashes) !== $delta['state']) {
                    throw new SecurityStateMismatch($source, 'does not add up to the state of the agent');
                }
                $states[$source] = $delta['state'];
            }

            $inventory->states = $states;
            $inventory->collected_at = now();
            $inventory->save();
        });
    }

    /**
     * A collection (SecurityInbox: the inventory deltas, the logs already parsed into events):
     * applied, the events recorded, then scanned.
     *
     * @param  array<int, array>  $sources  from normalizeSources
     * @param  array<int, array>  $events  from SecurityInventory::sanitizeEvents
     * @return array{opened: int, resolved: int}
     * @throws SecurityStateMismatch
     */
    public static function ingest(Device $device, array $sources, array $events = []): array
    {
        self::applyDeltas($device, $sources);
        SecurityEvent::record($device, $events);

        return self::scan($device, $events);
    }

    /**
     * The rules on the device's last inventory (and on the events that just came in).
     *
     * @param  array<int, array>  $events
     * @return array{opened: int, resolved: int}
     */
    public static function scan(Device $device, array $events = []): array
    {
        SecurityRule::syncBuiltIn();
        $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();
        $counts = ['opened' => 0, 'resolved' => 0];
        if ($inventory === null) {
            return $counts;
        }

        $rules = SecurityRule::query()->detection()->get();
        $open = SecurityFinding::query()->open()->where('device_id', $device->id)->get()->groupBy('security_rule_id');
        foreach ($rules as $rule) {
            $findings = $open->get($rule->id, collect());
            try {
                if (! $rule->enabled || ! $rule->appliesTo($device)) {
                    // Switched off: what it found is gone with it (events stay until acknowledged).
                    if (! $rule->isEvent) {
                        $counts['resolved'] += self::resolve($findings);
                    }

                    continue;
                }
                $result = $rule->isEvent
                    ? self::scanEvents($device, $rule, $findings, $events)
                    : self::scanInventory($device, $rule, $findings, $inventory->items($rule->source));
                $counts['opened'] += $result['opened'];
                $counts['resolved'] += $result['resolved'];
            } catch (Throwable $e) {
                Log::warning("Security rule {$rule->key} failed on device {$device->id}: {$e->getMessage()}");
            }
        }

        if ($counts['opened'] + $counts['resolved'] > 0) {
            LiveUpdates::device($device->id, 'security');
        }

        return $counts;
    }

    /**
     * The rules on every device with an inventory, after rules changed.
     *
     * @return array{opened: int, resolved: int}
     */
    public static function scanAll(): array
    {
        $counts = ['opened' => 0, 'resolved' => 0];
        $ids = SecurityInventory::query()->pluck('device_id');
        foreach (Device::query()->whereIn('id', $ids)->get() as $device) {
            $result = self::scan($device);
            $counts['opened'] += $result['opened'];
            $counts['resolved'] += $result['resolved'];
        }

        return $counts;
    }

    /**
     * What a rule (a definition, not saved yet) finds on a device's last inventory, for the editor.
     *
     * @return array<int, array{message: string, item: array}>|null null without an inventory
     */
    public static function preview(array $definition, Device $device): ?array
    {
        $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();
        if ($inventory === null) {
            return null;
        }
        $items = $definition['source'] === 'events'
            ? SecurityEvent::query()->where('device_id', $device->id)->where('occurred_at', '>=', now()->subDay())->get()
                ->map(fn (SecurityEvent $event) => ['Type' => $event->type, 'Count' => $event->count, 'User' => $event->user, 'Source' => $event->source, 'Message' => $event->message, 'Last' => (string) $event->occurred_at])->all()
            : $inventory->items($definition['source']);

        return array_map(fn ($match) => ['message' => $match['message'], 'item' => $match['item']], self::matches($definition, $items));
    }

    /**
     * The findings of a rule in a list: one per matching item, or one for the whole list with
     * "threshold". Each: fingerprint, message, item (the details).
     *
     * @return array<string, array{fingerprint: string, message: string, item: array}>
     */
    public static function matches(array $definition, array $items): array
    {
        $matching = SecurityRules::matching($definition, $items);
        $template = $definition['message'] ?? $definition['name'];
        $found = [];

        if (isset($definition['threshold'])) {
            if (count($matching) < $definition['threshold']) {
                return [];
            }
            $names = array_map(fn ($item) => self::itemName($definition['source'], $item), array_slice($matching, 0, 10));
            $summary = ['count' => count($matching), 'items' => implode(', ', array_filter($names)).(count($matching) > 10 ? ', …' : '')];
            $fingerprint = SecurityRules::fingerprint($definition, null);

            return [$fingerprint => [
                'fingerprint' => $fingerprint,
                'message' => SecurityRules::render($template, $summary + ($matching[0] ?? [])),
                'item' => $summary,
            ]];
        }

        foreach ($matching as $item) {
            $fingerprint = SecurityRules::fingerprint($definition, $item);
            if (isset($found[$fingerprint])) {
                continue;
            }
            $found[$fingerprint] = ['fingerprint' => $fingerprint, 'message' => SecurityRules::render($template, $item), 'item' => $item];
            if (count($found) >= self::MAX_FINDINGS_PER_RULE) {
                break;
            }
        }

        return $found;
    }

    /** @param Collection<int, SecurityFinding> $open */
    private static function scanInventory(Device $device, SecurityRule $rule, Collection $open, array $items): array
    {
        $found = self::matches($rule->definition, $items);
        $byFingerprint = $open->keyBy('fingerprint');
        $opened = 0;

        DB::transaction(function () use ($device, $rule, $found, $byFingerprint, &$opened) {
            foreach ($found as $fingerprint => $match) {
                $finding = $byFingerprint->get($fingerprint);
                if ($finding !== null) {
                    $finding->update(['message' => self::message($match), 'details' => $match['item'], 'severity' => $rule->severity, 'last_seen_at' => now()]);

                    continue;
                }
                self::open($device, $rule, $match);
                $opened++;
            }
        });

        return ['opened' => $opened, 'resolved' => self::resolve($open->reject(fn ($finding) => isset($found[$finding->fingerprint])))];
    }

    /**
     * Events add to an open finding of the same kind (user, source) or open a new one; nothing
     * resolves them but acknowledging.
     *
     * @param  Collection<int, SecurityFinding>  $open
     */
    private static function scanEvents(Device $device, SecurityRule $rule, Collection $open, array $events): array
    {
        if ($events === []) {
            return ['opened' => 0, 'resolved' => 0];
        }
        $byFingerprint = $open->whereNull('acknowledged_at')->keyBy('fingerprint');
        $opened = 0;
        foreach (self::matches($rule->definition, $events) as $fingerprint => $match) {
            $finding = $byFingerprint->get($fingerprint);
            if ($finding !== null) {
                $finding->update([
                    'message' => self::message($match),
                    'details' => $match['item'],
                    'severity' => $rule->severity,
                    'occurrences' => $finding->occurrences + 1,
                    'last_seen_at' => now(),
                ]);

                continue;
            }
            self::open($device, $rule, $match);
            $opened++;
        }

        return ['opened' => $opened, 'resolved' => 0];
    }

    private static function open(Device $device, SecurityRule $rule, array $match): SecurityFinding
    {
        return SecurityFinding::query()->create([
            'device_id' => $device->id,
            'security_rule_id' => $rule->id,
            'fingerprint' => $match['fingerprint'],
            'severity' => $rule->severity,
            'message' => self::message($match),
            'details' => $match['item'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** @param Collection<int, SecurityFinding> $findings */
    private static function resolve(Collection $findings): int
    {
        if ($findings->isEmpty()) {
            return 0;
        }
        SecurityFinding::query()->whereKey($findings->modelKeys())->update(['resolved_at' => now()]);

        return $findings->count();
    }

    private static function message(array $match): string
    {
        return mb_strimwidth($match['message'], 0, 1000, '…');
    }

    /** How an item of a list is named in a summary ("Administrator", "AnyDesk", "tcp 0.0.0.0:23"). */
    private static function itemName(string $source, array $item): string
    {
        return match ($source) {
            'listening' => trim(($item['Protocol'] ?? '').' '.($item['Address'] ?? '').':'.($item['Port'] ?? '')),
            'events' => (string) ($item['Type'] ?? ''),
            default => (string) ($item['Name'] ?? ''),
        };
    }
}
