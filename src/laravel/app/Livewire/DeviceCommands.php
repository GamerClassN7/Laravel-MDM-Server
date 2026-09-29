<?php

namespace App\Livewire;

use App\Models\Device;
use Livewire\Component;

class DeviceCommands extends Component
{
    public $selectedDeviceId;

    public function sendCommandToDevice($command)
    {
        Device::find($this->selectedDeviceId)?->queueCommand((string) $command);
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
