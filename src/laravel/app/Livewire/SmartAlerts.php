<?php

namespace App\Livewire;

use App\Models\Device;
use App\Support\SmartAlerts as Alerts;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The smart alerts of all devices (dashboard widget), most severe first, with their actions and
 * one action for all devices with the same alert ("Restart 3 devices").
 */
class SmartAlerts extends Component
{
    /** Least severe alert shown: danger, warning, info or secondary (offline devices). */
    #[Locked]
    public string $minSeverity = 'warning';

    /** Alerts listed at most. */
    #[Locked]
    public int $limit = 10;

    public function runAlert(int $deviceId, string $key): void
    {
        $device = Device::find($deviceId);
        if ($device && ($reason = Alerts::run($device, $key, auth()->user()))) {
            $this->addError("alert.$deviceId.$key", $reason);
        }
    }

    public function dismiss(int $deviceId, string $key): void
    {
        if ($device = Device::find($deviceId)) {
            Alerts::dismiss($device, $key, auth()->user());
        }
    }

    /**
     * Runs the action of an alert on every device that has it now and can take it. Devices where
     * it is already on its way are skipped, so it never runs twice.
     */
    public function runForAll(string $key): void
    {
        $queued = 0;
        foreach ($this->alerts()->where('alert.key', $key) as $item) {
            if ($item['alert']['action'] && ! $item['alert']['active'] && ! $item['alert']['refusal']
                && Alerts::run($item['device'], $key, auth()->user()) === null) {
                $queued++;
            }
        }
        $this->dispatch('snackbar', ['message' => trans_choice('Sent to :count device|Sent to :count devices', $queued), 'type' => 'success']);
    }

    /** @return Collection<int, array{device: Device, alert: array}> */
    private function alerts(): Collection
    {
        $max = Alerts::SEVERITIES[$this->minSeverity] ?? 1;

        return Device::all()
            ->flatMap(fn (Device $device) => collect(Alerts::for($device))->map(fn ($alert) => ['device' => $device, 'alert' => $alert]))
            ->filter(fn ($item) => Alerts::SEVERITIES[$item['alert']['severity']] <= $max)
            ->sortBy(fn ($item) => [Alerts::SEVERITIES[$item['alert']['severity']], $item['device']->displayName])
            ->values();
    }

    public function render()
    {
        $alerts = $this->alerts();
        // One action for all devices: alerts with the same key and an action more than one device can take now.
        $bulk = $alerts->filter(fn ($item) => $item['alert']['action'] && ! $item['alert']['active'] && ! $item['alert']['refusal'] && ! str_starts_with($item['alert']['key'], 'failed:'))
            ->groupBy('alert.key')
            ->filter(fn ($items) => $items->count() > 1)
            ->map(fn ($items) => ['count' => $items->count(), 'action' => $items->first()['alert']['action'], 'title' => $items->first()['alert']['title']]);

        return view('livewire.smart-alerts', [
            'alerts' => $alerts,
            'listed' => $alerts->take(max(1, $this->limit)),
            'bulk' => $bulk,
            'counts' => $alerts->countBy('alert.severity'),
            'busy' => $alerts->contains(fn ($item) => $item['alert']['active'] !== null),
        ]);
    }
}
