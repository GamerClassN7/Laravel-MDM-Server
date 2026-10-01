<?php

namespace App\Livewire;

use App\Models\Device;
use App\Support\SmartAlerts;
use Livewire\Attributes\On;
use Livewire\Component;

/** What needs attention on the device, each alert with the action that fixes it. */
class DeviceAlerts extends Component
{
    public $selectedDeviceId;

    public function runAlert(string $key): void
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device && ($reason = SmartAlerts::run($device, $key, auth()->user()))) {
            $this->addError('alert.'.$key, $reason);
        }
    }

    public function dismiss(string $key): void
    {
        if ($device = Device::find($this->selectedDeviceId)) {
            SmartAlerts::dismiss($device, $key, auth()->user());
        }
    }

    /** Kept for the agent update button of older views and tests. */
    public function updateAgent(): void
    {
        $this->runAlert('agent');
    }

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{selectedDeviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::find($this->selectedDeviceId);
        $alerts = $device ? SmartAlerts::for($device) : [];

        return view('livewire.device-alerts', [
            'selectedDevice' => $device,
            'alerts' => $alerts,
            'busy' => collect($alerts)->contains(fn ($alert) => $alert['active'] !== null),
        ]);
    }
}
