<?php

namespace App\Livewire\ScriptRun;

use App\Models\ScriptRun;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** The output (and error) of one script run, in a modal from the runs tables. */
#[AllowInModal]
class Output extends Component
{
    public int $runId;

    public function render()
    {
        return view('livewire.script-run.output', ['run' => ScriptRun::query()->with(['script', 'device'])->findOrFail($this->runId)]);
    }
}
