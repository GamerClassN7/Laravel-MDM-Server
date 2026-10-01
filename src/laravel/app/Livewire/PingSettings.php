<?php

namespace App\Livewire;

use App\Models\Device;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** The address, prefix and MAC of a ping-only device (from the device menu). */
#[AllowInModal]
class PingSettings extends Component
{
    public int $deviceId;

    public string $address = '';

    public $prefix = 24;

    public string $mac = '';

    public function mount(int $deviceId): void
    {
        $device = Device::query()->where('kind', 'ping')->findOrFail($deviceId);
        $this->deviceId = $device->id;
        $this->address = (string) $device->ping_address;
        $this->prefix = $device->ping_prefix ?? 24;
        $this->mac = (string) $device->ping_mac;
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $device = Device::query()->where('kind', 'ping')->findOrFail($this->deviceId);
        $settings = Device::sanitizePingSettings($this->address, $this->prefix, $this->mac);
        if ($settings === null) {
            $this->addError('address', __('Enter an IPv4 address, a prefix of 8–30 and a valid MAC address (or none).'));

            return;
        }
        // Another address: the old answers say nothing about it.
        if ($settings['ping_address'] !== $device->ping_address) {
            $settings += ['last_seen_at' => null, 'ping_rtt' => null];
        }
        $device->forceFill($settings)->save();

        $this->dispatch('ping-settings-saved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        return view('livewire.ping-settings');
    }
}
