<?php

namespace App\Livewire\Networks;

use App\Support\NetworkMap;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The networks of the fleet as a map (top down: internet, public addresses, gateways, networks,
 * devices) and a list. Kept current over Reverb: a change of any device redraws the map.
 */
class Page extends Component
{
    /** Live update (resources/js/live.js): devices changed; the map gets the new data. */
    #[On('devices-changed')]
    public function devicesChanged(): void
    {
        $this->dispatch('network-map-updated', map: NetworkMap::build());
    }

    public function render()
    {
        return view('livewire.networks.page', ['map' => NetworkMap::build()])->title(__('Networks'));
    }
}
