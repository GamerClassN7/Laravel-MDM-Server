<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\DeviceCommand;
use Livewire\Component;

/** The device's actions (restart, turn off, install updates ...) and the commands on their way. */
class DeviceCommands extends Component
{
    public $selectedDeviceId;

    /** Commands the toolbar offers; the others come from the alerts and the update list. */
    public const TOOLBAR = ['restart', 'turnOff', 'doUpdates'];

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

    /** A command the agent has not taken yet can be cancelled. */
    public function cancel(int $commandId)
    {
        DeviceCommand::query()->whereKey($commandId)->where('device_id', $this->selectedDeviceId)->where('status', 'queued')
            ->update(['status' => 'cancelled', 'finished_at' => now(), 'message' => __('Cancelled by :user', ['user' => auth()->user()?->name ?? '?'])]);
    }

    public function deleteDevice()
    {
        Device::find($this->selectedDeviceId)?->delete();

        $this->dispatch('device-deleted');
    }

    public function render()
    {
        $device = Device::find($this->selectedDeviceId);
        if ($device === null) {
            // Deleted meanwhile, the page selects another device.
            return '<div></div>';
        }
        $active = $device->activeCommands();

        return view('livewire.device-commands', [
            'selectedDevice' => $device,
            'active' => $active,
            'pendingUpdates' => count($device->installableUpdates) + count($device->apps_packages_updates) + count($device->moduleUpdates),
        ]);
    }
}
