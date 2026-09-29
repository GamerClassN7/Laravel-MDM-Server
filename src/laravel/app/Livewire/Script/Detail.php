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
        $latest = ScriptRun::query()
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('script_id', $this->script->id)->groupBy('device_id'))
            ->get()
            ->countBy('status');

        return view('livewire.script.detail', ['latest' => $latest])
            ->title($this->script->name);
    }
}
