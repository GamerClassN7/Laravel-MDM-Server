<?php

namespace App\Livewire\Script;

use App\Models\Script;
use App\Models\ScriptRun;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/** Detail page of a script: its code, fingerprint and the runs. */
class Detail extends Component
{
    public Script $script;

    public function mount(Script $script): void
    {
        Gate::authorize('is-system-admin');
        $this->script = $script;
    }

    public function run(): void
    {
        $this->dispatch('openModal', 'script.run', __('Run :name', ['name' => $this->script->name]), ['scriptId' => $this->script->id], 'lg');
    }

    public function edit(): void
    {
        $this->dispatch('openModal', 'script.form', __('Edit script'), ['scriptId' => $this->script->id], 'xl');
    }

    #[On('scriptSaved')]
    public function refresh(): void
    {
        $this->script->refresh();
    }

    public function render()
    {
        // The state of each device: its latest run of this script.
        $latest = ScriptRun::query()->where('script_id', $this->script->id)
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('script_id', $this->script->id)->groupBy('device_id'))
            ->get(['id', 'status', 'version', 'finished_at']);
        $groups = [
            'compliant' => ['compliant'],
            'remediated' => ['remediated'],
            'failed' => ['failed', 'error', 'rejected'],
            'waiting' => ['pending', 'sent'],
        ];

        return view('livewire.script.detail', [
            'stats' => collect($groups)->map(fn ($statuses) => $latest->whereIn('status', $statuses)->count()),
            'devices' => $latest->count(),
            'outdated' => $latest->where('version', '<', $this->script->version)->count(),
            'lastRun' => $this->script->runs()->max('issued_at'),
        ])->title($this->script->name);
    }
}
