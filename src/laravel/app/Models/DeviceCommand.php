<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A command for one device and its state. queued: waits for the agent, sent: the agent took it,
 * running: the agent reports it is working on it (optionally with a progress in percent), then
 * succeeded / failed. Agents before 1.8.0 do not report back: their commands end as delivered.
 */
class DeviceCommand extends Model
{
    use MassPrunable;

    /** Finished commands are kept this many days (the device history). */
    public const KEEP_DAYS = 90;

    public const COMMANDS = ['turnOff', 'restart', 'doUpdates', 'installUpdate', 'updateAgent', 'runScripts', 'sync', 'wake', 'pingNow'];

    public const STATUSES = ['queued', 'sent', 'running', 'succeeded', 'failed', 'delivered', 'expired', 'cancelled'];

    public const ACTIVE = ['queued', 'sent', 'running'];

    /** What the agent may report. */
    public const RESULTS = ['running', 'succeeded', 'failed'];

    /** Only triggers, the agent does not report them (runScripts: the runs have their own results). */
    public const UNTRACKED = ['runScripts'];

    /** Commands that cannot wait for each other: a restart and a shutdown at the same time. */
    public const EXCLUSIVE = [['turnOff', 'restart']];

    /** Seconds a command not taken by the agent waits (the device is online when it is queued). */
    public const QUEUE_TTL = 3600;

    /** Seconds without news from the agent after which an active command is given up. */
    public const TIMEOUTS = [
        'turnOff' => 600,
        'restart' => 1800,
        'doUpdates' => 14400,
        'installUpdate' => 7200,
        'updateAgent' => 1800,
        'runScripts' => 600,
        'sync' => 1800,
        'wake' => 300,
        'pingNow' => 120,
    ];

    /** A MAC address as the agents report it (Windows AA-BB-..., Linux aa:bb:...). */
    public const MAC_PATTERN = '/^[0-9A-Fa-f]{2}([:-][0-9A-Fa-f]{2}){5}$/';

    /** Kinds of single updates the agent can install (installUpdate), with the pattern of their id. */
    public const UPDATE_KINDS = [
        'windows' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        'apt' => '/^[a-z0-9][a-z0-9+.\-]*(:[a-z0-9]+)?$/',
        'winget' => '/^[A-Za-z0-9][A-Za-z0-9._+\-]*$/',
        'flatpak' => '/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)+$/',
        'snap' => '/^[a-z0-9][a-z0-9\-]*$/',
        'module' => '/^[A-Za-z0-9][A-Za-z0-9._\-]*$/',
        // A PowerShell 7 release from GitHub (installations no package manager knows about).
        'pwsh' => '/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,4}$/',
    ];

    public const MODULE_EDITIONS = ['Windows PowerShell', 'PowerShell 7'];

    protected $fillable = ['device_id', 'command', 'params', 'target', 'status', 'progress', 'message', 'issued_by'];

    protected $casts = [
        'params' => 'array',
        'progress' => 'integer',
        'sent_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function prunable(): Builder
    {
        return static::query()->whereNotIn('status', self::ACTIVE)->where('updated_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    /**
     * Checks the parameters of installUpdate and returns them cleaned up (only the known keys), or
     * null when they are not valid. Other commands take no parameters.
     */
    public static function sanitizeParams(string $command, array $params): ?array
    {
        if ($command === 'wake') {
            return self::sanitizeWakeParams($params);
        }
        if ($command === 'pingNow') {
            // A ping-only device the agent pings: it pings the address it was given for it.
            $device = $params['device'] ?? null;
            if (! is_int($device) || $device < 1) {
                return null;
            }

            return ['device' => $device] + (isset($params['title']) && is_string($params['title']) ? ['title' => mb_substr($params['title'], 0, 200)] : []);
        }
        if ($command !== 'installUpdate') {
            return $params === [] ? [] : null;
        }

        $kind = $params['kind'] ?? null;
        $id = $params['id'] ?? null;
        if (! is_string($kind) || ! isset(self::UPDATE_KINDS[$kind]) || ! is_string($id) || strlen($id) > 200 || ! preg_match(self::UPDATE_KINDS[$kind], $id)) {
            return null;
        }

        $clean = ['kind' => $kind, 'id' => $id];
        $user = $params['user'] ?? null;
        if ($user !== null) {
            if (! in_array($kind, ['flatpak', 'module'], true) || ! is_string($user) || ! preg_match('/^[a-z_][a-z0-9_.\-]{0,31}$/', $user)) {
                return null;
            }
            $clean['user'] = $user;
        }
        if ($kind === 'module') {
            $edition = $params['edition'] ?? null;
            $version = $params['version'] ?? null;
            if (! in_array($edition, self::MODULE_EDITIONS, true) || ! is_string($version) || ! preg_match('/^[0-9][0-9A-Za-z.\-]{0,49}$/', $version)) {
                return null;
            }
            $clean['edition'] = $edition;
            $clean['version'] = $version;
        }
        // winget: the source of the package (without it winget also asks the Microsoft Store).
        if ($kind === 'winget' && in_array($params['source'] ?? null, ['winget', 'msstore'], true)) {
            $clean['source'] = $params['source'];
        }
        // Only shown in the portal, never used by the agent.
        if (isset($params['title']) && is_string($params['title'])) {
            $clean['title'] = mb_substr($params['title'], 0, 200);
        }

        return $clean;
    }

    /**
     * wake (sent to a relay agent in the network of the sleeping device): the MAC addresses to wake,
     * the broadcast addresses to send the magic packet to, the device it wakes and its name.
     */
    private static function sanitizeWakeParams(array $params): ?array
    {
        $macs = array_values(array_unique(array_map(
            fn ($mac) => strtoupper(str_replace('-', ':', (string) $mac)),
            array_filter((array) ($params['macs'] ?? []), fn ($mac) => is_string($mac) && preg_match(self::MAC_PATTERN, $mac)),
        )));
        $broadcasts = array_values(array_unique(array_filter((array) ($params['broadcasts'] ?? []), fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))));
        $device = $params['device'] ?? null;
        if ($macs === [] || count($macs) > 8 || $broadcasts === [] || count($broadcasts) > 4 || ! is_int($device) || $device < 1) {
            return null;
        }
        $clean = ['macs' => $macs, 'broadcasts' => $broadcasts, 'device' => $device];
        if (isset($params['title']) && is_string($params['title'])) {
            $clean['title'] = mb_substr($params['title'], 0, 200);
        }

        return $clean;
    }

    /** What the command works on, the same for duplicates: "winget:Git.Git", "module:PowerShell 7:Az:". */
    public static function targetOf(string $command, array $params): ?string
    {
        if ($command === 'wake') {
            return 'device:'.$params['device'];
        }
        if ($command === 'pingNow') {
            return 'ping:'.$params['device'];
        }
        if ($command !== 'installUpdate') {
            return null;
        }

        return implode(':', [$params['kind'], $params['edition'] ?? '', $params['id'], $params['user'] ?? '']);
    }

    /** Active commands without news for longer than their timeout are given up. */
    public static function expireStale(?int $deviceId = null): int
    {
        $expired = 0;
        foreach (self::TIMEOUTS as $command => $timeout) {
            $query = self::query()->where('command', $command)
                ->when($deviceId, fn ($query) => $query->where('device_id', $deviceId))
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('status', 'queued')->where('updated_at', '<', now()->subSeconds(self::QUEUE_TTL)))
                    ->orWhere(fn ($query) => $query->whereIn('status', ['sent', 'running'])->where('updated_at', '<', now()->subSeconds($timeout))));
            $expired += $query->toBase()->update([
                'status' => 'expired',
                'message' => __('No result from the device'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $expired;
    }

    public function getActiveAttribute(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function getLabelAttribute(): string
    {
        return match ($this->command) {
            'turnOff' => __('Turn off'),
            'restart' => __('Restart'),
            'doUpdates' => __('Install updates'),
            'installUpdate' => __('Update :name', ['name' => $this->params['title'] ?? $this->params['id'] ?? '?']),
            'updateAgent' => __('Update agent'),
            'runScripts' => __('Run scripts'),
            'sync' => __('Sync'),
            'wake' => __('Wake :name', ['name' => $this->params['title'] ?? '?']),
            'pingNow' => __('Ping :name', ['name' => $this->params['title'] ?? '?']),
            default => $this->command,
        };
    }

    public function getIconAttribute(): string
    {
        return match ($this->command) {
            'turnOff' => 'fas fa-power-off',
            'restart' => 'fas fa-redo',
            'doUpdates', 'installUpdate' => 'fas fa-sync',
            'updateAgent' => 'fas fa-robot',
            'runScripts' => 'fas fa-scroll',
            'sync' => 'fas fa-cloud-download-alt',
            'wake' => 'fas fa-sun',
            'pingNow' => 'fas fa-network-wired',
            default => 'fas fa-terminal',
        };
    }

    /** Human readable state: "Waiting for the device", "Running · 40 %" ... */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'queued' => __('Waiting for the device'),
            'sent' => __('Taken by the device'),
            'running' => __('Running'),
            'succeeded' => __('Done'),
            'failed' => __('Failed'),
            'delivered' => __('Sent, no result'),
            'expired' => __('Timed out'),
            'cancelled' => __('Cancelled'),
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'queued', 'sent', 'running' => 'info',
            'succeeded' => 'success',
            'delivered' => 'secondary',
            'failed', 'expired' => 'danger',
            default => 'secondary',
        };
    }

    /** What the status badge means, for its tooltip. */
    public function getStatusHintAttribute(): ?string
    {
        return match ($this->status) {
            'delivered' => __('Taken by an agent older than 1.8.0, it does not report the result.'),
            'expired' => __('The device did not report a result in time.'),
            default => null,
        };
    }

    /**
     * The agent's message when it adds something to the status: not the plain "Done" of a
     * finished update ("Done, restart required" becomes "Restart required").
     */
    public function getResultNoteAttribute(): ?string
    {
        $message = trim((string) $this->message);
        if ($this->status === 'succeeded' && preg_match('/^Done\b[,.\s]*/i', $message)) {
            $message = ucfirst(trim(preg_replace('/^Done\b[,.\s]*/i', '', $message)));
        }

        return $message === '' || strcasecmp($message, $this->statusLabel) === 0 ? null : $message;
    }

    /** How long the device worked on it ("5 min"), null when unknown. */
    public function getDurationAttribute(): ?string
    {
        if (! $this->finished_at || ! $this->started_at) {
            return null;
        }
        $seconds = max(0, $this->finished_at->getTimestamp() - $this->started_at->getTimestamp());

        return $seconds < 60 ? __(':count s', ['count' => $seconds]) : \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    /** What the agent gets: the id it reports back with, the command and its parameters. */
    public function toTask(): array
    {
        $params = $this->params ?? [];
        unset($params['title']);

        return ['id' => $this->id, 'command' => $this->command, 'params' => (object) $params];
    }
}
