<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\Enrolment;
use Carbon\CarbonImmutable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ShowDevices extends Component
{
    public $devices;

    #[Url]
    public $selectedDeviceId;

    /* Device Enrolment*/
    public $addDevice = false;
    public $enrollmentCode;
    public $enrollmentCodeExpiration;

    public function selectDevice($id)
    {
        $this->selectedDeviceId = $id;
        $this->addDevice = false;
    }

    public  function updatedAddDevice($value)
    {
        if ($value) {
            Enrolment::Where('expire_at', '<', CarbonImmutable::now())->delete();

            $this->enrollmentCode = mt_rand(1000, 9999);
            $this->enrollmentCodeExpiration = CarbonImmutable::now()->add(15, 'min');

            $enrolment = new Enrolment();
            $enrolment->code = $this->enrollmentCode;
            $enrolment->expire_at = $this->enrollmentCodeExpiration;
            $enrolment->save();
        }
    }

    #[On('device-deleted')]
    public function deviceDeleted()
    {
        $this->selectedDeviceId = null;
        $this->devices = Device::all();
    }

    public function mount()
    {
        $this->devices = Device::all();
    }

    public function render()
    {
        return view('livewire.show-devices', [
            'selectedDevice' => Device::find($this->selectedDeviceId),
        ]);
    }
}
