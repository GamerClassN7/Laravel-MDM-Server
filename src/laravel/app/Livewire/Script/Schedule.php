<?php

namespace App\Livewire\Script;

use App\Livewire\Concerns\PicksTarget;
use App\Models\Device;
use App\Models\Script;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

/** Recurring runs of a script: a cron expression and the devices (all, tags, picked ones). */
#[AllowInModal(ability: 'is-system-admin')]
class Schedule extends Component
{
    use PicksTarget;

    public int $scriptId;

    public bool $enabled = false;

    public string $schedule = '0 3 * * *';

    public function mount(int $scriptId): void
    {
        Gate::authorize('is-system-admin');
        $this->scriptId = $scriptId;
        $script = Script::findOrFail($scriptId);
        $this->enabled = $script->scheduled;
        $this->schedule = $script->schedule ?: $this->schedule;
        $this->fillTarget($script->schedule_target ?? ['all' => true]);
    }

    public function save(): void
    {
        Gate::authorize('is-system-admin');
        $script = Script::findOrFail($this->scriptId);
        $this->resetErrorBag();
        $this->schedule = trim(preg_replace('/\s+/', ' ', $this->schedule));

        if ($this->enabled) {
            if (! Script::validSchedule($this->schedule)) {
                $this->addError('schedule', __('Not a valid cron expression (minute hour day month weekday).'));

                return;
            }
            if ($this->targetIsEmpty()) {
                $this->addError('target', __('Choose the devices.'));

                return;
            }
        }

        $script->schedule = $this->enabled ? $this->schedule : null;
        $script->schedule_target = $this->target();
        $script->updated_by = auth()->id();
        $script->save();

        $this->dispatch('scriptSaved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        $script = Script::findOrFail($this->scriptId);
        $valid = Script::validSchedule($this->schedule);
        $preview = $valid ? (clone $script)->forceFill(['schedule' => $this->schedule]) : null;
        $nextRuns = [];
        if ($preview) {
            $next = now();
            for ($i = 0; $i < 3; $i++) {
                $next = $preview->nextScheduledRun($next);
                $nextRuns[] = $next;
            }
        }
        $devices = $this->targetIsEmpty() ? collect() : Device::targeted($this->target());

        return view('livewire.script.schedule', [
            'script' => $script,
            'valid' => $valid,
            'nextRuns' => $nextRuns,
            'targeted' => $devices->count(),
            'runnable' => $devices->filter(fn (Device $device) => $script->unavailableReason($device) === null)->count(),
        ]);
    }
}
