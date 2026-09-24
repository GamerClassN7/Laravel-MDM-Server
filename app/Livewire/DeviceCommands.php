<?php

namespace App\Livewire;

use App\Events\DeviceCommandIssued;
use App\Models\Device;
use Livewire\Component;

class DeviceCommands extends Component
{
    public $selectedDeviceId;

    public function sendCommandToDevice($command)
    {
        $device = Device::find($this->selectedDeviceId);

        if (! in_array($command, Device::COMMANDS, true) || in_array($command, $device->commands) || $device->offline) {
            return;
        }

        $device->commands = array_merge($device->commands, (array) $command);
        $device->save();

        // Instant delivery over WebSocket; the command stays queued for the HTTP report as a fallback.
        rescue(fn () => DeviceCommandIssued::dispatch($device, $command));
    }

    public function deleteDevice()
    {
        Device::find($this->selectedDeviceId)?->delete();

        $this->dispatch('device-deleted');
    }

    public function render()
    {
        return view('livewire.device-commands', [
            'selectedDevice' => Device::find($this->selectedDeviceId),
        ]);
    }
}
