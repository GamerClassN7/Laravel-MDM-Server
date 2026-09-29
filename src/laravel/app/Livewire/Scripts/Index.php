<?php

namespace App\Livewire\Scripts;

use App\Models\Script;
use App\Models\ScriptRun;
use App\Support\Signing;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Remediation scripts (system admins only): list, results of the selected script. */
class Index extends Component
{
    #[Url(as: 'script')]
    public ?int $selectedScriptId = null;

    public function mount(): void
    {
        Gate::authorize('is-system-admin');
    }

    public function create(): void
    {
        $this->dispatch('openModal', 'scripts.form', __('New script'), [], 'xl');
    }

    public function edit(int $id): void
    {
        $this->dispatch('openModal', 'scripts.form', __('Edit script'), ['scriptId' => $id], 'xl');
    }

    public function run(int $id): void
    {
        $this->dispatch('openModal', 'scripts.run', __('Run :name', ['name' => Script::find($id)?->name]), ['scriptId' => $id], 'lg');
    }

    public function select(int $id): void
    {
        $this->selectedScriptId = $this->selectedScriptId === $id ? null : $id;
    }

    public function remove(int $id): void
    {
        Gate::authorize('is-system-admin');
        Script::find($id)?->delete();
        if ($this->selectedScriptId === $id) {
            $this->selectedScriptId = null;
        }
    }

    #[On('scriptSaved')]
    public function refresh(): void {}

    public function render()
    {
        $scripts = Script::query()->orderBy('name')->get();
        // Result of the latest run per script and device.
        $latest = ScriptRun::query()
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->groupBy('script_id', 'device_id'))
            ->get()
            ->groupBy('script_id');

        $selected = $scripts->firstWhere('id', $this->selectedScriptId);

        return view('livewire.scripts.index', [
            'scripts' => $scripts,
            'latest' => $latest,
            'selected' => $selected,
            'runs' => $selected?->runs()->with('device')->latest('id')->limit(200)->get() ?? collect(),
            'serverFingerprint' => Signing::fingerprint(),
        ]);
    }
}
