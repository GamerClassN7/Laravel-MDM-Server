<?php

namespace App\Livewire\Networks;

use App\Models\Device;
use App\Models\NetworkNeighbour;
use App\Support\NetworkMap;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The networks of the fleet as a map (top down: internet, public addresses, gateways, networks,
 * devices) and a list, with the devices the agents see that the portal does not know (add as
 * ping-only, ignore) and scans of a network. Kept current over Reverb: a change of any device
 * redraws the map.
 */
class Page extends Component
{
    /** Live update (resources/js/live.js): devices changed; the map gets the new data. */
    #[On('devices-changed')]
    public function devicesChanged(): void
    {
        $this->dispatch('network-map-updated', map: NetworkMap::build());
    }

    /** Asks an agent in the network that allows scans to scan it (its result comes with its next report). */
    public function scan(string $network): void
    {
        $found = collect(NetworkMap::build()['networks'])->firstWhere('id', $network);
        $scan = $found['scan'] ?? null;
        $agent = $scan && $scan['agent'] ? Device::find($scan['agent']) : null;
        if ($agent === null) {
            $this->addError('scan.'.($found['anchor'] ?? ''), $scan['refusal'] ?? __('The network is not known.'));

            return;
        }
        if ($agent->issueCommand('scanNetwork', ['cidr' => $found['cidr']], auth()->user()) === null) {
            $this->addError('scan.'.$found['anchor'], $agent->commandRefusal('scanNetwork', ['cidr' => $found['cidr']]) ?? __('Already on its way.'));
        }
    }

    /** An unknown device as a ping-only device: pinged by an agent of its network, its MAC followed. */
    public function add(int $id)
    {
        $neighbour = NetworkNeighbour::query()->whereNull('ignored_at')->findOrFail($id);
        $device = new Device();
        $device->forceFill([
            'kind' => 'ping',
            'name' => $neighbour->hostname ? Str::before($neighbour->hostname, '.') : $neighbour->ip,
            'os' => '',
            'token' => hash('sha256', Str::random(60)),
            'ping_address' => $neighbour->ip,
            'ping_prefix' => (int) explode('/', $neighbour->network)[1],
            'ping_mac' => $neighbour->mac,
        ]);
        $device->save();

        return $this->redirect(route('devices', ['selectedDeviceId' => $device->id]));
    }

    /** Not shown as unknown anymore (and no alerts about it). */
    public function ignore(int $id): void
    {
        NetworkNeighbour::query()->whereKey($id)->update(['ignored_at' => now()]);
        $this->devicesChanged();
    }

    public function restore(int $id): void
    {
        NetworkNeighbour::query()->whereKey($id)->update(['ignored_at' => null]);
        $this->devicesChanged();
    }

    /** Network discovery of the whole portal: off, the agents' neighbours are not taken (system admins). */
    public function toggleDiscovery(): void
    {
        Gate::authorize('is-system-admin');
        NetworkNeighbour::setEnabled(! NetworkNeighbour::enabled());
    }

    public function render()
    {
        return view('livewire.networks.page', [
            'map' => NetworkMap::build(),
            'discovery' => NetworkNeighbour::enabled(),
            'ignored' => NetworkNeighbour::query()->whereNotNull('ignored_at')->latest('last_seen_at')->limit(50)->get(),
        ])->title(__('Networks'));
    }
}
