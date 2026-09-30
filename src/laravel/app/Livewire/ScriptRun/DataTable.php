<?php

namespace App\Livewire\ScriptRun;

use App\Models\ScriptRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use SteelAnts\DataTable\Livewire\DataTableComponent;
use SteelAnts\DataTable\Traits\UseDatabaseEloquent;

/** Runs of one script (detail page), newest first. */
class DataTable extends DataTableComponent
{
    use UseDatabaseEloquent;

    public $listeners = ['scriptSaved' => '$refresh'];

    public int $scriptId;

    public bool $filterable = true;

    public array $sortableColumns = ['status', 'version', 'issued_at', 'finished_at'];

    public string $sortBy = 'issued_at';

    public string $sortDirection = 'desc';

    public int $itemsPerPage = 25;

    public function mount()
    {
        Gate::authorize('is-system-admin');
        parent::mount();
    }

    public function query(): Builder
    {
        return ScriptRun::query()->with('device')->where('script_id', $this->scriptId);
    }

    public function headers(): array
    {
        return [
            'device_id' => __('Device'),
            'version' => __('Version'),
            'status' => __('Status'),
            'issued_at' => __('Issued'),
            'finished_at' => __('Finished'),
            'output' => __('Output'),
        ];
    }

    public function headerFilters(): array
    {
        return [
            'status' => ['type' => 'select', 'values' => collect(ScriptRun::STATUSES)->mapWithKeys(fn ($status) => [$status => __(ucfirst($status))])->all()],
        ];
    }

    public function renderColumnDeviceId($value, $row): string
    {
        return '<a href="'.e(route('devices', ['selectedDeviceId' => $value, 'tab' => 'scripts'])).'">'.e($row->device?->displayName ?? "#$value").'</a>';
    }

    public function renderColumnVersion($value, $row): string
    {
        return '<span title="'.e($row->fingerprint).'">v'.e($value).' · <code>'.e(substr($row->fingerprint, 0, 8)).'</code></span>';
    }

    public function renderColumnStatus($value, $row): string
    {
        $html = '<span class="badge border border-'.$row->statusColor.'-subtle bg-'.$row->statusColor.'-subtle text-'.$row->statusColor.'-emphasis">'.e(__(ucfirst($value))).'</span>';
        if ($row->error) {
            $html .= '<div class="small text-danger">'.e($row->error).'</div>';
        }

        return $html;
    }

    public function renderColumnIssuedAt($value): string
    {
        return '<span title="'.e($value).'">'.e($value?->diffForHumans()).'</span>';
    }

    public function renderColumnFinishedAt($value): string
    {
        return $value ? '<span title="'.e($value).'">'.e($value->diffForHumans()).'</span>' : '';
    }

    public function renderColumnOutput($value): string
    {
        if (! $value) {
            return '';
        }

        return '<details><summary class="small text-muted">'.e(__('Show')).'</summary><pre class="small bg-body-tertiary p-2 rounded mb-0" style="max-height: 20rem; max-width: 40rem; white-space: pre-wrap;">'.e($value).'</pre></details>';
    }
}
