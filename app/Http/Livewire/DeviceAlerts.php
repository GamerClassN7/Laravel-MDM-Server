<?php

namespace App\Http\Livewire;

use Livewire\Component;
use App\Models\Device;

class DeviceAlerts extends Component
{
    public $selectedDeviceId;

    public function render()
    {
        return view('livewire.device-alerts', [
            'selectedDevice' => Device::find($this->selectedDeviceId),
        ]);
    }
}
