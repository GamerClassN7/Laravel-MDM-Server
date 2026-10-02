<?php

namespace App\Livewire\Script;

use App\Models\Script;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use SteelAnts\Modal\Livewire\Attributes\AllowInModal;

#[AllowInModal(ability: 'is-system-admin')]
class Form extends Component
{
    public ?int $scriptId = null;

    public string $name = '';

    public string $description = '';

    public string $platform = 'all';

    public int $timeout = 60;

    public string $detection = '';

    public string $remediation = '';

    /** Runs only detect; the remediation is started per device (an alert with Remediate). */
    public bool $manualRemediation = false;

    public function mount(?int $scriptId = null): void
    {
        Gate::authorize('is-system-admin');
        $this->scriptId = $scriptId;
        if ($script = Script::find($scriptId)) {
            $this->fill($script->only(['name', 'platform', 'timeout', 'detection']));
            $this->description = (string) $script->description;
            $this->remediation = (string) $script->remediation;
            $this->manualRemediation = (bool) $script->manual_remediation;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'platform' => ['required', Rule::in(array_keys(Script::PLATFORMS))],
            'timeout' => 'required|integer|min:5|max:'.Script::MAX_TIMEOUT,
            'detection' => 'required|string|max:200000',
            'remediation' => 'nullable|string|max:200000',
            'manualRemediation' => 'boolean',
        ];
    }

    public function save(): void
    {
        Gate::authorize('is-system-admin');
        $this->validate();

        $script = Script::findOrNew($this->scriptId);
        $script->fill([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'platform' => $this->platform,
            'timeout' => $this->timeout,
            // Byte for byte as entered: the fingerprint and the device check these bytes.
            'detection' => $this->detection,
            'remediation' => $this->remediation,
            'manual_remediation' => $this->manualRemediation,
        ]);
        $script->exists ? $script->updated_by = auth()->id() : $script->created_by = auth()->id();
        $script->save();

        $this->dispatch('scriptSaved');
        $this->dispatch('closeModal');
    }

    public function render()
    {
        return view('livewire.script.form', [
            'script' => Script::find($this->scriptId),
        ]);
    }
}
