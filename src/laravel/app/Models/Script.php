<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SteelAnts\LaravelBoilerplate\Models\Activity;
use SteelAnts\LaravelBoilerplate\Traits\Auditable;

/**
 * A remediation script: a PowerShell detection script (exit 0 = compliant, 1 = needs remediation)
 * and an optional remediation script, run on the devices as SYSTEM / root without network access.
 */
class Script extends Model
{
    use Auditable;

    public const PLATFORMS = ['all' => 'All', 'windows' => 'Windows', 'linux' => 'Linux'];

    public const MAX_TIMEOUT = 3600;

    /** Runs not taken by the agent within this time expire (the signed manifest does too). */
    public const RUN_TTL = 86400;

    protected $fillable = ['name', 'description', 'platform', 'detection', 'remediation', 'timeout'];

    protected static function booted(): void
    {
        // A change of what runs is a new version with a new fingerprint; runs keep theirs.
        static::saving(function (Script $script) {
            $script->remediation = ($script->remediation === null || trim($script->remediation) === '') ? null : $script->remediation;
            if ($script->exists && $script->isDirty(['detection', 'remediation', 'platform', 'timeout'])) {
                $script->version = $script->getOriginal('version') + 1;
            }
            $script->fingerprint = self::fingerprintOf($script->platform, (int) $script->timeout, $script->detection, $script->remediation);
        });
    }

    /** SHA-256 over what the device runs: platform, timeout and the hashes of both scripts. */
    public static function fingerprintOf(string $platform, int $timeout, string $detection, ?string $remediation): string
    {
        return hash('sha256', json_encode([
            'detection_sha256' => hash('sha256', $detection),
            'platform' => $platform,
            'remediation_sha256' => $remediation === null ? null : hash('sha256', $remediation),
            'timeout' => $timeout,
        ]));
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScriptRun::class);
    }

    /** Whether this script is meant for the device's platform. */
    public function supports(Device $device): bool
    {
        return $this->platform === 'all' || $this->platform === $device->platform;
    }

    /**
     * Why the script cannot run on the device, or null when it can.
     */
    public function unavailableReason(Device $device): ?string
    {
        return match (true) {
            ! $this->supports($device) => __('Other platform'),
            ! $device->signsRequests => __('Agent does not sign (update it)'),
            ! $device->scriptsEnabled => __('Scripts disabled on the device'),
            default => null,
        };
    }

    /**
     * Queues a run on each device that can run it and triggers the agents. Returns the runs.
     *
     * @param  array<int>  $deviceIds
     * @return \Illuminate\Support\Collection<int, ScriptRun>
     */
    public function runOn(array $deviceIds, ?User $user = null)
    {
        $runs = Device::query()->whereIn('id', $deviceIds)->get()
            ->filter(fn (Device $device) => $this->unavailableReason($device) === null)
            ->map(function (Device $device) use ($user) {
                // A newer run replaces a waiting one of the same script.
                $this->runs()->where('device_id', $device->id)->where('status', 'pending')->update(['status' => 'superseded']);

                $run = $this->runs()->create([
                    'device_id' => $device->id,
                    'version' => $this->version,
                    'fingerprint' => $this->fingerprint,
                    'issued_by' => $user?->id,
                    'issued_at' => now(),
                    'expires_at' => now()->addSeconds(self::RUN_TTL),
                ]);
                // Offline devices take it with their next report.
                $device->queueCommand('runScripts');

                return $run;
            })
            ->values();

        $activity = new Activity;
        $activity->lang_text = __('Ran Script :name', ['name' => $this->name]);
        $activity->data = ['version' => $this->version, 'fingerprint' => $this->fingerprint, 'devices' => $runs->pluck('device_id')->all()];
        $activity->affected()->associate($this);
        $activity->save();

        return $runs;
    }
}
