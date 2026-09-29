<?php

namespace App\Livewire;

use App\Models\Device;
use App\Support\AgentScript;
use App\Support\InstallCommands;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class DeviceAlerts extends Component
{
    public $selectedDeviceId;

    public function updateAgent()
    {
        Device::find($this->selectedDeviceId)?->queueCommand('updateAgent');
    }

    /** The agent registers a key again with its next request (trust on first use). */
    public function resetDeviceKey()
    {
        Gate::authorize('is-system-admin');

        Device::query()->whereKey($this->selectedDeviceId)->update(['public_key' => null, 'key_registered_at' => null]);
    }

    public function render()
    {
        $device = Device::find($this->selectedDeviceId);

        return view('livewire.device-alerts', [
            'selectedDevice' => $device,
            'latestAgentVersion' => AgentScript::version(),
            'updateCommand' => $device->agentOutdated && $device->agentUpdatable ? InstallCommands::update($device) : null,
            // Agents refuse remote updates over plain HTTP (except localhost).
            'remoteUpdate' => str_starts_with(url('/'), 'https://') || in_array(parse_url(url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true),
        ]);
    }
}
