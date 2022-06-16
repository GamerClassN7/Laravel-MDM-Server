<?php

namespace App\Http\Livewire;

use App\Models\Device;
use App\Models\Enrolment;
use Carbon\CarbonImmutable;
use Livewire\Component;

class ShowDevices extends Component
{
    public $devices;
    public $selectedDeviceId;

    /* Device Enrolment*/
    public $addDevice = false;
    public $enrollmentCode;
    public $enrollmentCodeExpiration;


    protected $queryString = [
        'selectedDeviceId'
    ];

    public function sendCommandToDevice($command){
        $device = Device::find($this->selectedDeviceId);
        
        if (in_array($command,$device->commands))
            return;

        $device->commands = array_merge($device->commands, (array) $command);
        $device->save();
    }
    
    public function selectDevice($id){
        $this->selectedDeviceId =$id;
        $this->addDevice = false;
    }

    public  function updatedAddDevice($value){
        if ($value){
            Enrolment::Where('expire_at', '<', CarbonImmutable::now())->delete();
            
            $this->enrollmentCode = mt_rand(1000,9999);
            $this->enrollmentCodeExpiration = CarbonImmutable::now()->add(15, 'min');
            
            $enrolment = new Enrolment();
            $enrolment->code = $this->enrollmentCode;
            $enrolment->expire_at = $this->enrollmentCodeExpiration;
            $enrolment->save();
        }
    }
    
    public function mount(){
        $this->devices = Device::all();
    }

    public function render()
    {
        return view('livewire.show-devices', [
            'selectedDevice' => Device::find($this->selectedDeviceId),
        ]);
    }
}
