<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\DeviceCommand;
use Livewire\Attributes\On;
use Livewire\Component;

/** The device's actions (restart, turn off, install updates ...) and the commands on their way. */
class DeviceCommands extends Component
{
    public $selectedDeviceId;

    /** Commands the toolbar offers; the others come from the alerts and the update list. */
    public const TOOLBAR = ['restart', 'turnOff', 'doUpdates', 'sync'];

    public function sendCommandToDevice($command)
    {
        $command = (string) $command;
        $device = Device::find($this->selectedDeviceId);
        if ($device === null || ! in_array($command, self::TOOLBAR, true)) {
            return;
        }
        if (! $device->issueCommand($command, [], auth()->user())) {
            $this->addError('command', $device->commandRefusal($command) ?? __('Already on its way.'));
        }
    }

    /** Sync of a ping-only device: the agent that pings it pings it now. */
    public function pingNow()
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device && ! $device->pingNow(auth()->user())) {
            $this->addError('command', $device->pingNowRefusal() ?? __('Already on its way.'));
        }
    }

    /** Wake-on-LAN: another agent in the same network sends the magic packet. */
    public function wake()
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device && ! $device->wake(auth()->user())) {
            $this->addError('command', $device->wakeRefusal() ?? __('Already on its way.'));
        }
    }

    /**
     * Cancels a command not taken yet, or gives up one the device took (a hanging update): it no
     * longer blocks a new one (Restart, Install updates again). The device may still be working
     * on it; what it reports later is ignored.
     */
    public function cancel(int $commandId)
    {
        // Its own commands, and the wakes / pings another agent does for it.
        $command = DeviceCommand::query()->whereKey($commandId)->active()
            ->where(fn ($query) => $query->where('device_id', $this->selectedDeviceId)
                ->orWhereIn('target', ['device:'.$this->selectedDeviceId, 'ping:'.$this->selectedDeviceId]))
            ->first();
        if ($command === null) {
            return;
        }
        $user = auth()->user()?->name ?? '?';
        DeviceCommand::query()->whereKey($command->id)->active()->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'message' => $command->status === 'queued' ? __('Cancelled by :user', ['user' => $user]) : __('Given up by :user', ['user' => $user]),
        ]);
        DeviceCommand::announce($command->device_id, $command->target);
    }

    public function deleteDevice()
    {
        Device::find($this->selectedDeviceId)?->delete();
        \App\Support\LiveUpdates::device((int) $this->selectedDeviceId, 'deleted');

        $this->dispatch('device-deleted');
    }

    /** Live update (resources/js/live.js): this device changed. */
    #[On('device-changed.{selectedDeviceId}')]
    public function deviceChanged(): void {}

    public function render()
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device === null) {
            // Deleted meanwhile, the page selects another device.
            return '<div></div>';
        }
        $active = $device->activeCommands();
        $recentWake = $device->offline ? $device->recentWake() : null;
        $recentPing = $device->isPingOnly ? $device->recentPingNow() : null;
        // With the device's own commands: a wake or ping another agent does for it, and a wake an
        // agent before 1.13.2 finished on its side while the device is still waited for.
        $onItsWay = $active->concat(array_filter([
            $recentWake && ($recentWake->active || $recentWake->status === 'succeeded') ? $recentWake : null,
            $recentPing?->active ? $recentPing : null,
        ]));

        return view('livewire.device-commands', [
            'selectedDevice' => $device,
            'active' => $active,
            'onItsWay' => $onItsWay,
            'wakeRefusal' => $device->wakeRefusal(),
            'wakeRelay' => $device->offline ? $device->wakeRelay()[0] ?? null : null,
            'recentWake' => $recentWake,
            'pingNowRefusal' => $device->isPingOnly ? $device->pingNowRefusal() : null,
            'recentPing' => $recentPing,
            'pendingUpdates' => count($device->installableUpdates) + count($device->apps_packages_updates) + count($device->moduleUpdates),
        ]);
    }
}
