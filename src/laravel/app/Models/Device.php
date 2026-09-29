<?php

namespace App\Models;

use App\Support\AgentScript;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Device extends Model
{
    use HasFactory;

    public const COMMANDS = ['turnOff', 'restart', 'doUpdates', 'updateAgent'];

    public const TYPE_ICONS = [
        'server' => 'fas fa-server',
        'laptop' => 'fas fa-laptop',
        'desktop' => 'fas fa-desktop',
    ];

    /** Seconds without a report after which the REST API connection is shown as inactive (reports come every 5 min). */
    public const REPORT_TIMEOUT = 660;

    /** Seconds without a heartbeat after which the device is considered offline. */
    public const HEARTBEAT_TIMEOUT = 90;

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_ws_at' => 'datetime',
        'last_http_at' => 'datetime',
        'live_state_at' => 'datetime',
    ];

    /** Live state values the agent may report (anything else is dropped). */
    private const SERVICE_STATES = ['running', 'stopped', 'failed'];

    private const CONTAINER_STATES = ['created', 'running', 'unhealthy', 'paused', 'restarting', 'removing', 'exited', 'dead'];

    /**
     * @param  'ws'|'http'  $channel
     */
    public static function recordHeartbeat(int $id, mixed $metrics = null, string $channel = 'ws', mixed $state = null): void
    {
        $values = [
            'last_seen_at' => now(),
            $channel === 'ws' ? 'last_ws_at' : 'last_http_at' => now(),
        ];
        if ($state = self::sanitizeLiveState($state)) {
            $values['live_state'] = json_encode($state);
            $values['live_state_at'] = now();
        }

        // One UPDATE (atomic, no read-modify-write) through the query builder, so the heartbeat
        // does not touch updated_at (time of the last report) or the report data.
        $updated = static::query()->whereKey($id)->toBase()->update($values);

        if ($updated && $metrics = DeviceMetric::sanitize($metrics)) {
            DeviceMetric::query()->create($metrics + ['device_id' => $id]);
        }
    }

    /**
     * Keeps only known fields and values: {restart_required: bool, services: {name: state},
     * containers: {name: state}}. Returns null when nothing usable is left.
     */
    public static function sanitizeLiveState(mixed $state): ?array
    {
        if (! is_array($state)) {
            return null;
        }

        $clean = [];
        if (array_key_exists('restart_required', $state)) {
            $clean['restart_required'] = filter_var($state['restart_required'], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['services' => self::SERVICE_STATES, 'containers' => self::CONTAINER_STATES] as $key => $allowed) {
            if (! isset($state[$key]) || ! is_array($state[$key])) {
                continue;
            }
            $clean[$key] = [];
            foreach (array_slice($state[$key], 0, 2000, true) as $name => $value) {
                if (is_string($name) && $name !== '' && strlen($name) <= 256 && in_array($value, $allowed, true)) {
                    $clean[$key][$name] = $value;
                }
            }
        }

        return $clean === [] ? null : $clean;
    }

    /** The live state when it is newer than the last report, otherwise null (the report wins). */
    public function getLiveStateAttribute(): ?array
    {
        $value = $this->attributes['live_state'] ?? null;
        if ($value === null || $this->live_state_at === null || ($this->updated_at !== null && $this->live_state_at->lt($this->updated_at))) {
            return null;
        }

        return json_decode($value, true) ?: null;
    }

    /**
     * Removes and returns the queued commands atomically: a command queued at the same time is
     * either returned now or stays queued for the next report, it is never lost.
     */
    public static function takeCommands(int $id): array
    {
        $taken = [];
        self::swapCommands($id, function (array $commands) use (&$taken) {
            $taken = $commands;

            return [];
        });

        return $taken;
    }

    /** Compare-and-swap on the commands column, retried when another request changed it meanwhile. */
    private static function swapCommands(int $id, callable $change): bool
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $current = static::query()->whereKey($id)->toBase()->value('commands');
            $next = json_encode(array_values($change((array) (json_decode((string) $current, true) ?? []))));
            if ($next === $current) {
                return true;
            }

            $query = static::query()->whereKey($id)->toBase();
            $current === null ? $query->whereNull('commands') : $query->where('commands', $current);
            if ($query->update(['commands' => $next]) === 1) {
                return true;
            }
        }

        return false;
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(DeviceMetric::class);
    }

    /**
     * Queues a command for the agent and pushes it over the WebSocket; returns false when it is not accepted.
     */
    public function queueCommand(string $command): bool
    {
        if (! in_array($command, self::COMMANDS, true) || $this->offline) {
            return false;
        }

        $queued = false;
        self::swapCommands($this->id, function (array $commands) use ($command, &$queued) {
            $queued = ! in_array($command, $commands, true);

            return $queued ? [...$commands, $command] : $commands;
        });
        if (! $queued) {
            return false;
        }
        $this->commands = self::query()->whereKey($this->id)->value('commands');
        $this->syncOriginalAttribute('commands');

        // Instant delivery over WebSocket; the command stays queued for the HTTP report as a fallback.
        rescue(fn () => \App\Events\DeviceCommandIssued::dispatch($this, $command));

        return true;
    }

    /** server, laptop or desktop, as detected by the agent. */
    public function getTypeAttribute(): string
    {
        $type = $this->data->machine->Type ?? null;

        return array_key_exists($type, self::TYPE_ICONS) ? $type : 'desktop';
    }

    /** windows or linux; older agents do not report it, guess from the OS name. */
    public function getPlatformAttribute(): string
    {
        $platform = $this->data->machine->Platform ?? null;
        if (in_array($platform, ['windows', 'linux'], true)) {
            return $platform;
        }

        return preg_match('/linux|ubuntu|debian/i', (string) $this->os) ? 'linux' : 'windows';
    }

    public function getTypeIconAttribute(): string
    {
        return self::TYPE_ICONS[$this->type];
    }

    /** Version reported by the agent; null for agents older than version reporting. */
    public function getAgentVersionAttribute(): ?string
    {
        return $this->data->machine->AgentVersion ?? null;
    }

    public function getAgentOutdatedAttribute(): bool
    {
        $latest = AgentScript::version();

        return $latest !== null && ($this->agent_version === null || version_compare($this->agent_version, $latest, '<'));
    }

    /** Old agents cannot update themselves, they have to be reinstalled. */
    public function getAgentUpdatableAttribute(): bool
    {
        return $this->agent_version !== null;
    }

    public function getConnectedViaWebsocketAttribute(): bool
    {
        return $this->last_ws_at !== null && $this->last_ws_at->diffInSeconds() <= self::HEARTBEAT_TIMEOUT;
    }

    public function getConnectedViaApiAttribute(): bool
    {
        $last = $this->last_http_at ?? $this->updated_at;

        return $last !== null && $last->diffInSeconds() <= self::REPORT_TIMEOUT;
    }

    public function getDataAttribute($value)
    {
        return (json_decode($value) ?? []);
    }

    public function getDrivesAttribute($value)
    {
        if ([] === $this->data) {
            return [];
        }

        $drives = json_decode(json_encode($this->data->machine), true)["Drives"];
        foreach ( $drives  as $key => $drive) {
            $drive = (array)$drive;
            if ($drive['Size'] <= 0) {
                continue;
            }

            $usedSpace = (int) $drive['Size'] - (int) $drive['SizeRemaining'];
            $drives[$key]['PercentUsed'] = round($usedSpace / ((int) $drive['Size'] / 100));
        }

        return $drives;
    }

    public function setDrivesAttribute($value)
    {
        $this->attributes['drives'] = json_encode($value);
    }

    public function getCommandsAttribute($value)
    {
        return json_decode($value);
    }

    public function setCommandsAttribute($value)
    {
        $this->attributes['commands'] = json_encode((array) $value);
    }

    public function getDisplayNameAttribute()
    {
        $name = $this->friendly_name;
        if (empty($name)) {
            $name = $this->name;
        }

        // Enrolled devices have no name until their first report.
        return $name ?: __('Device #:id', ['id' => $this->id]);
    }

    public function getNiceUptimeAttribute()
    {
        if (isset($this->data->machine->uptime)) {
            return CarbonInterval::seconds($this->data->machine->uptime)->cascade()->forHumans();
        }
        return false;
    }

    public function getOfflineAttribute()
    {
        if ($this->last_seen_at !== null) {
            return $this->last_seen_at->diffInSeconds() > self::HEARTBEAT_TIMEOUT;
        }

        // Agents without heartbeat support only send the periodic report.
        return $this->updated_at->diffInSeconds() > 900;
    }

    public function getRestartPendingAttribute()
    {
        if (isset($this->liveState['restart_required'])) {
            return $this->liveState['restart_required'];
        }

        if (null !== ($this->data->machine->RestartRequired ?? null)) {
            if (filter_var($this->data->machine->RestartRequired, FILTER_VALIDATE_BOOLEAN) === true) {
                return true;
            }
        }

        return false;
    }

    public function getLastLogonUserAttribute()
    {
        if (isset($this->data->machine->last_logon_user)) {
            return $this->data->machine->last_logon_user;
        }
        return false;
    }

    public function getAppsPackagesUpdatesAttribute()
    {
        if (isset($this->data->packages_updates)) {
            return (array) self::stdToArray($this->data->packages_updates);
        }
        return [];
    }

    public function getUpdatesAttribute()
    {
        if (isset($this->data->os_updates)) {
            return (array)  self::stdToArray($this->data->os_updates);
        }
        return [];
    }

    public function getNetworksAttribute()
    {
        if (isset($this->data->machine->Networks)) {
            return (array) $this->data->machine->Networks;
        }
        return [];
    }

    /** Running services and the ones that failed or should run but do not, failed first. */
    public function getServicesAttribute(): array
    {
        $services = self::withLiveStates(
            self::listOf(json_decode(json_encode($this->data->services ?? []), true)),
            $this->liveState['services'] ?? null,
        );
        $order = ['failed' => 0, 'stopped' => 1, 'running' => 2];
        usort($services, fn ($a, $b) => [$order[$a['State'] ?? ''] ?? 1, strtolower($a['Name'] ?? '')] <=> [$order[$b['State'] ?? ''] ?? 1, strtolower($b['Name'] ?? '')]);

        return $services;
    }

    /** Null when Docker is not installed on the device. */
    public function getDockerAttribute(): ?array
    {
        if (! isset($this->data->docker)) {
            return null;
        }

        $docker = json_decode(json_encode($this->data->docker), true);
        $live = $this->liveState['containers'] ?? null;

        $containers = array_map(function (array $container) {
            // "unhealthy" is a running container whose health check fails.
            if (($container['State'] ?? null) === 'unhealthy') {
                $container['State'] = 'running';
                $container['Health'] = 'unhealthy';
            }

            return $container;
        }, self::withLiveStates(array_map(function (array $container) {
            // Same naming as the live state, so an unchanged container keeps its status text.
            if (($container['State'] ?? null) === 'running' && str_contains($container['Status'] ?? '', '(unhealthy)')) {
                $container['State'] = 'unhealthy';
            }

            return $container;
        }, self::listOf($docker['containers'] ?? [])), $live));

        return [
            // A newer live state means the agent reads the containers again.
            'error' => $live !== null ? null : ($docker['error'] ?? null),
            'containers' => $containers,
        ];
    }

    /**
     * Applies the live states to the items of the last report. The live state lists every item
     * (running and failed / stopped ones): items it no longer has are gone, new ones are added
     * with their name only. A changed item loses its report-time status text.
     */
    private static function withLiveStates(array $items, ?array $states): array
    {
        if ($states === null) {
            return $items;
        }

        $byName = [];
        foreach ($items as $item) {
            $byName[$item['Name'] ?? ''] = $item;
        }

        $merged = [];
        foreach ($states as $name => $state) {
            $item = $byName[$name] ?? ['Name' => $name];
            if (($item['State'] ?? null) !== $state) {
                $item['State'] = $state;
                unset($item['Status'], $item['Health']);
            }
            $merged[] = $item;
        }

        return $merged;
    }

    /** Null until the agent reported disk health (agents before 1.2.0). */
    public function getDiskHealthAttribute(): ?array
    {
        if (! isset($this->data->disk_health)) {
            return null;
        }

        $health = json_decode(json_encode($this->data->disk_health), true);

        return [
            'error' => $health['error'] ?? null,
            'disks' => self::listOf($health['disks'] ?? []),
        ];
    }

    /** Any disk reporting a failed or warning SMART status. */
    public function getDiskHealthProblemAttribute(): bool
    {
        foreach ($this->diskHealth['disks'] ?? [] as $disk) {
            if (in_array($disk['Health'] ?? null, ['failed', 'warning'], true)) {
                return true;
            }
        }

        return false;
    }

    /** A single item serialized by PowerShell as an object instead of a list. */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
    }

    private static function  stdToArray($stdObject){
        if (is_object($stdObject)){
            return [json_decode(json_encode($stdObject), true)];
        }
        return json_decode(json_encode($stdObject), true);
    }
}
