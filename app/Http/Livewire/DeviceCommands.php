<?php

namespace App\Http\Livewire;

use App\Models\Device;
use Livewire\Component;

class DeviceCommands extends Component
{
    public $selectedDeviceId;

    public function sendCommandToDevice($command){
        $device = Device::find($this->selectedDeviceId);
        
        if (in_array($command,$device->commands))
            return;

        $device->commands = array_merge($device->commands, (array) $command);
        $device->save();
    }

    public function deleteDevice(){
        $device = Device::find($this->selectedDeviceId);
        $device->delete();
    }

    public function render()
    {
        return view('livewire.device-commands', [
            'selectedDevice' => Device::find($this->selectedDeviceId),
        ]);
    }
}
