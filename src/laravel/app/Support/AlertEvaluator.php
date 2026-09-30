<?php

namespace App\Support;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\ScriptRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks the alert rules every minute. A rule that starts to hold on a device opens an alert and
 * notifies its user; when it stops holding, the alert is resolved and the user is told so. Nothing
 * is sent again while an alert stays open.
 */
class AlertEvaluator
{
    /** Sizes are 1024 based, as shown in the portal. */
    private const GB = 1073741824;

    /** @var Collection<int, Device> loaded once per run */
    private Collection $devices;

    /** @return array{triggered: int, resolved: int} */
    public static function run(): array
    {
        return (new self)->evaluate();
    }

    public function evaluate(): array
    {
        $this->devices = Device::query()->orderBy('id')->get();
        $counts = ['triggered' => 0, 'resolved' => 0];

        foreach (AlertRule::query()->with('user')->get() as $rule) {
            try {
                $open = $rule->events()->whereNull('resolved_at')->get()->keyBy('device_id');
                $targeted = $rule->enabled ? $this->devices->filter->matchesTarget($rule->target ?? []) : collect();

                // Devices no longer in the target (or a disabled rule): closed without a message.
                foreach ($open as $deviceId => $event) {
                    if (! $targeted->contains('id', $deviceId)) {
                        $event->update(['resolved_at' => now()]);
                    }
                }

                foreach ($targeted as $device) {
                    $result = $this->check($rule, $device);
                    if ($result === null) {
                        continue;
                    }
                    $event = $open->get($device->id);
                    if ($result['active'] && $event === null) {
                        $this->trigger($rule, $device, $result);
                        $counts['triggered']++;
                    } elseif (! $result['active'] && $event !== null) {
                        $this->resolve($rule, $device, $event);
                        $counts['resolved']++;
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Alert rule {$rule->id} failed: {$e->getMessage()}");
            }
        }

        return $counts;
    }

    /**
     * Whether the rule holds on the device: ['active' => bool, 'value' => ?float, 'message' => string],
     * or null when it cannot be told now (no data yet, the device is offline for a metric).
     */
    public function check(AlertRule $rule, Device $device): ?array
    {
        if (empty($device->data) && $rule->type !== 'status') {
            return null;
        }

        return match ($rule->type) {
            'status' => $this->checkStatus($rule, $device),
            'cpu', 'memory' => $this->checkMetric($rule, $device),
            'disk' => $this->checkDisk($rule, $device),
            'disk_health' => $device->virtualization !== null || $device->diskHealth === null ? null : [
                'active' => $device->diskHealthProblem,
                'value' => null,
                'message' => __('A disk of :device reports a S.M.A.R.T. problem.', ['device' => $device->displayName]),
            ],
            'services' => $this->checkServices($device),
            'scripts' => $this->checkScripts($device),
            default => null,
        };
    }

    private function checkStatus(AlertRule $rule, Device $device): array
    {
        $lastSeen = $device->last_seen_at ?? $device->updated_at;
        $minutes = max(1, (int) $rule->minutes);
        $down = $device->offline && $lastSeen !== null && $lastSeen->lt(now()->subMinutes($minutes));

        return [
            'active' => $down,
            'value' => null,
            'message' => __(':device is offline, last seen :time.', ['device' => $device->displayName, 'time' => $lastSeen?->diffForHumans()]),
        ];
    }

    private function checkMetric(AlertRule $rule, Device $device): ?array
    {
        if ($device->offline) {
            return null;
        }
        $minutes = max(1, (int) $rule->minutes);
        $column = match (true) {
            $rule->type === 'cpu' => 'cpu',
            $rule->inGb => DB::raw('memory_total - memory_used'),
            default => DB::raw('100.0 * memory_used / memory_total'),
        };
        $samples = $device->metrics()->where('created_at', '>=', now()->subMinutes($minutes));
        // Heartbeats come every 30 s: at least half of the window must be covered.
        if ((clone $samples)->count() < max(1, $minutes)) {
            return null;
        }
        if ($rule->inGb) {
            $free = round((float) (clone $samples)->avg($column) / self::GB, 1);

            return [
                'active' => $free < $rule->limit_gb,
                'value' => $free,
                'message' => __('Free memory of :device averaged :value GB in the last :minutes min (limit :limit GB).', [
                    'device' => $device->displayName, 'value' => AlertRule::formatGb($free), 'minutes' => $minutes, 'limit' => AlertRule::formatGb($rule->limit_gb),
                ]),
            ];
        }
        $average = round((float) (clone $samples)->avg($column), 1);
        $label = $rule->type === 'cpu' ? __('CPU') : __('Memory');

        return [
            'active' => $average > $rule->threshold,
            'value' => $average,
            'message' => __(':label usage of :device averaged :value % in the last :minutes min (threshold :threshold %).', [
                'label' => $label, 'device' => $device->displayName, 'value' => $average, 'minutes' => $minutes, 'threshold' => $rule->threshold,
            ]),
        ];
    }

    private function checkDisk(AlertRule $rule, Device $device): ?array
    {
        $drives = collect($device->drives)->filter(fn ($drive) => isset($drive['PercentUsed']));
        if ($drives->isEmpty()) {
            return null;
        }
        $name = fn ($drive) => trim(($drive['FriendlyName'] ?? '').' ('.($drive['DriveLetter'] ?? '?').')');
        if ($rule->inGb) {
            $free = fn ($drive) => round((float) ($drive['SizeRemaining'] ?? 0) / self::GB, 1);
            $low = $drives->filter(fn ($drive) => $free($drive) < $rule->limit_gb);

            return [
                'active' => $low->isNotEmpty(),
                'value' => $drives->map($free)->min(),
                'message' => __('Drives of :device with less than :limit GB free: :drives.', [
                    'device' => $device->displayName,
                    'limit' => AlertRule::formatGb($rule->limit_gb),
                    'drives' => $low->map(fn ($drive) => $name($drive).' '.AlertRule::formatGb($free($drive)).' GB')->implode(', '),
                ]),
            ];
        }
        $full = $drives->filter(fn ($drive) => $drive['PercentUsed'] > $rule->threshold);
        $top = $drives->sortByDesc('PercentUsed')->first();

        return [
            'active' => $full->isNotEmpty(),
            'value' => (float) $top['PercentUsed'],
            'message' => __('Drives of :device above :threshold %: :drives.', [
                'device' => $device->displayName,
                'threshold' => $rule->threshold,
                'drives' => $full->map(fn ($drive) => $name($drive).' '.$drive['PercentUsed'].' %')->implode(', '),
            ]),
        ];
    }

    private function checkServices(Device $device): array
    {
        $failed = collect($device->services)->where('State', 'failed')->pluck('Name');
        $containers = collect($device->docker['containers'] ?? [])
            ->filter(fn ($container) => ($container['Health'] ?? null) === 'unhealthy' || in_array($container['State'] ?? null, ['dead', 'restarting'], true))
            ->pluck('Name');
        $names = $failed->merge($containers)->filter()->values();

        return [
            'active' => $names->isNotEmpty(),
            'value' => (float) $names->count(),
            'message' => __('Problems on :device: :names.', ['device' => $device->displayName, 'names' => $names->implode(', ')]),
        ];
    }

    private function checkScripts(Device $device): array
    {
        $failed = ScriptRun::query()->with('script')->where('device_id', $device->id)
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('device_id', $device->id)->groupBy('script_id'))
            ->whereIn('status', ['failed', 'error', 'rejected'])->get();

        return [
            'active' => $failed->isNotEmpty(),
            'value' => (float) $failed->count(),
            'message' => __('Remediations failed on :device: :scripts.', ['device' => $device->displayName, 'scripts' => $failed->map(fn ($run) => $run->script?->name)->filter()->implode(', ')]),
        ];
    }

    private function trigger(AlertRule $rule, Device $device, array $result): void
    {
        $rule->events()->create([
            'device_id' => $device->id,
            'message' => mb_strimwidth($result['message'], 0, 1000),
            'value' => $result['value'],
            'triggered_at' => now(),
        ]);

        $title = $rule->type === 'status'
            ? "🔴 {$device->displayName} ".__('is down')
            : "🔴 {$device->displayName}: {$rule->label}";
        Notifier::notify($rule->user, $title, $result['message']."\n".url('/devices?selectedDeviceId='.$device->id));
    }

    private function resolve(AlertRule $rule, Device $device, AlertEvent $event): void
    {
        $event->update(['resolved_at' => now()]);

        $title = $rule->type === 'status'
            ? "✅ {$device->displayName} ".__('is up')
            : "✅ {$device->displayName}: {$rule->label} ".__('resolved');
        $duration = $event->triggered_at->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);
        Notifier::notify($rule->user, $title, __('Resolved after :duration.', ['duration' => $duration])."\n".url('/devices?selectedDeviceId='.$device->id));
    }
}
