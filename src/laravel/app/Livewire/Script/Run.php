<?php

namespace App\Livewire\Script;

use App\Models\Device;
use App\Models\Script;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** Picks the devices a script runs on; opened again, the devices of the last run are preselected. */
#[AllowInModal(ability: 'is-system-admin')]
class Run extends Component
{
    public int $scriptId;

    /** @var array<int> */
    public array $selected = [];

    public ?string $message = null;

    public function mount(int $scriptId): void
    {
        Gate::authorize('is-system-admin');
        $this->scriptId = $scriptId;

        $script = Script::findOrFail($scriptId);
        $lastIssue = $script->runs()->max('issued_at');
        $this->selected = $lastIssue === null ? [] : $script->runs()->where('issued_at', $lastIssue)->pluck('device_id')->map(fn ($id) => (string) $id)->all();
    }

    public function start(): void
    {
        Gate::authorize('is-system-admin');
        $script = Script::findOrFail($this->scriptId);

        $runs = $script->runOn(array_map('intval', $this->selected), auth()->user());

        $this->dispatch('scriptSaved');
        $this->dispatch('closeModal');
        alert()->success(trans_choice('Script queued on :count device|Script queued on :count devices', $runs->count(), ['count' => $runs->count()]))->now();
    }

    public function render()
    {
        $script = Script::findOrFail($this->scriptId);
        // Devices the script can run on first, then by name.
        // Ping-only devices have no agent to run scripts.
        $devices = Device::query()->where('kind', 'agent')->get()
            ->map(fn (Device $device) => [
                'id' => $device->id,
                'name' => $device->displayName,
                'platform' => $device->platform,
                'offline' => $device->offline,
                'reason' => $script->unavailableReason($device),
            ])
            ->sortBy(fn (array $device) => [$device['reason'] === null ? 0 : 1, mb_strtolower($device['name'])])
            ->values();

        return view('livewire.script.run', [
            'script' => $script,
            'devices' => $devices,
        ]);
    }
}
