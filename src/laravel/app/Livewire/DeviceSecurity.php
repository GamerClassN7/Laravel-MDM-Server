<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\SecurityFinding;
use App\Models\SecurityInventory;
use Livewire\Attributes\On;
use Livewire\Component;

/** The Security tab of a device: the scanner's findings and the security inventory they come from. */
class DeviceSecurity extends Component
{
    public int $deviceId;

    public function acknowledge(int $id): void
    {
        $this->finding($id)?->acknowledge(auth()->user());
    }

    public function reopen(int $id): void
    {
        $this->finding($id)?->reopen();
    }

    private function finding(int $id): ?SecurityFinding
    {
        return SecurityFinding::query()->with('rule')->where('device_id', $this->deviceId)->find($id);
    }

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{deviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::findOrFail($this->deviceId);
        $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();

        return view('livewire.device-security', [
            'device' => $device,
            'inventory' => $inventory,
            'findings' => $device->securityFindings()->with(['rule', 'acknowledger'])->open()->bySeverity()->get(),
            'events' => \App\Models\SecurityEvent::query()->where('device_id', $device->id)->latest('occurred_at')->limit(20)->get(),
        ]);
    }
}
