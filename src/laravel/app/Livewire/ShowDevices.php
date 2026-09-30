<?php

namespace App\Livewire;

use App\Models\Device;
use App\Models\Enrolment;
use App\Support\InstallCommands;
use Carbon\CarbonImmutable;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ShowDevices extends Component
{
    public $devices;

    #[Url]
    public $selectedDeviceId;

    /** Shows only the devices with this tag. */
    #[Url(except: '')]
    public string $tag = '';

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
        $this->loadDevices();
        $this->selectFirstDevice();
    }

    #[On('device-tags-changed')]
    public function loadDevices(): void
    {
        $this->devices = Device::all();
    }

    public function filterTag(string $tag): void
    {
        $this->tag = $this->tag === $tag ? '' : $tag;
        $this->selectFirstDevice();
    }

    public function mount()
    {
        $this->loadDevices();
        $this->selectFirstDevice();
    }

    /** The devices in the list: all of them, or the ones with the selected tag. */
    private function visibleDevices()
    {
        return $this->tag === '' ? $this->devices : $this->devices->filter(fn (Device $device) => $device->hasTag($this->tag))->values();
    }

    /** Opens the first device when none (or a deleted one) is selected, saving a click. */
    private function selectFirstDevice(): void
    {
        $devices = $this->visibleDevices();
        if (! $devices->contains('id', (int) $this->selectedDeviceId)) {
            $this->selectedDeviceId = $devices->first()?->id;
        }
    }

    public function render()
    {
        return view('livewire.show-devices', [
            'visibleDevices' => $this->visibleDevices(),
            'tags' => Device::allTags(),
            'selectedDevice' => Device::find($this->selectedDeviceId),
            'installCommands' => $this->addDevice && $this->enrollmentCode ? InstallCommands::for($this->enrollmentCode) : [],
        ]);
    }
}
