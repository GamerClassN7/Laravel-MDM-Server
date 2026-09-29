<?php

namespace App\Livewire\Scripts;

use App\Models\Device;
use App\Models\Script;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** Picks the devices a script runs on; opened again, the devices of the last run are preselected. */
#[AllowInModal('is-system-admin')]
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
        $devices = Device::query()->get()->sortBy(fn (Device $device) => mb_strtolower($device->displayName))->values();

        return view('livewire.scripts.run', [
            'script' => $script,
            'devices' => $devices->map(fn (Device $device) => [
                'id' => $device->id,
                'name' => $device->displayName,
                'platform' => $device->platform,
                'offline' => $device->offline,
                'reason' => $script->unavailableReason($device),
            ]),
        ]);
    }
}
