<?php

namespace App\Http\Livewire;

use Livewire\Component;
use App\Models\Device;

class DeviceDetail extends Component
{
    public $selectedDeviceId;

    /* Edit Mode*/
    public $editMode = false;
    public $friendlyName = "";

    public function saveFriendlyName()
    {
        $device = Device::find($this->selectedDeviceId);
        $device->friendly_name = $this->friendlyName;
        $device->save();
        $this->editMode = false;
    }

    public function mount()
    {
        $this->friendlyName = Device::find($this->selectedDeviceId)->DisplayName;
    }

    public function render()
    {
        return view('livewire.device-detail', [
            'selectedDevice' => Device::find($this->selectedDeviceId),

        ]);
    }
}
