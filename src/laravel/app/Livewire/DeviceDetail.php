<?php

namespace App\Livewire;

use App\Models\Device;
use Livewire\Attributes\Url;
use Livewire\Component;

class DeviceDetail extends Component
{
    public $selectedDeviceId;

    /** Selected tab, kept in the URL; the view falls back to the first tab the device has. */
    #[Url(except: 'drives')]
    public string $tab = 'drives';

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
