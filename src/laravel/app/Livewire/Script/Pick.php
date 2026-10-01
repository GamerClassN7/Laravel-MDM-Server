<?php

namespace App\Livewire\Script;

use App\Models\Device;
use App\Models\Script;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** Bulk action of the device overview: runs one script on the selected devices. */
#[AllowInModal(ability: 'is-system-admin')]
class Pick extends Component
{
    /** @var array<int, int> */
    public array $deviceIds = [];

    public function mount(array $deviceIds = []): void
    {
        Gate::authorize('is-system-admin');
        $this->deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
    }

    public function run(int $scriptId): void
    {
        Gate::authorize('is-system-admin');
        $runs = Script::findOrFail($scriptId)->runOn($this->deviceIds, auth()->user());

        $this->dispatch('closeModal');
        alert()->success(trans_choice('Script queued on :count device|Script queued on :count devices', $runs->count(), ['count' => $runs->count()]))->now();
    }

    public function render()
    {
        $devices = Device::query()->whereIn('id', $this->deviceIds)->get();

        return view('livewire.script.pick', [
            'devices' => $devices,
            'scripts' => Script::query()->orderBy('name')->get()->map(fn (Script $script) => [
                'script' => $script,
                'runnable' => $devices->filter(fn (Device $device) => $script->unavailableReason($device) === null)->count(),
            ]),
        ]);
    }
}
