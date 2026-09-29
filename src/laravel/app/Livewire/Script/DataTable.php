<?php

namespace App\Livewire\Script;

use App\Models\Script;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use SteelAnts\DataTable\Livewire\DataTableComponent;
use SteelAnts\DataTable\Traits\UseDatabaseEloquent;

class DataTable extends DataTableComponent
{
    use UseDatabaseEloquent;

    public $listeners = ['scriptSaved' => '$refresh'];

    public bool $searchable = true;

    public array $searchableColumns = ['name', 'description'];

    public array $sortableColumns = ['name', 'platform', 'version', 'updated_at'];

    public string $sortBy = 'name';

    public function mount()
    {
        Gate::authorize('is-system-admin');
        parent::mount();
    }

    public function query(): Builder
    {
        return Script::query();
    }

    public function headers(): array
    {
        return [
            'name' => __('Name'),
            'platform' => __('Platform'),
            'version' => __('Version'),
            'fingerprint' => __('Fingerprint'),
            'updated_at' => __('Changed'),
        ];
    }

    public function renderColumnName($value, $row): string
    {
        $html = '<a class="fw-semibold" href="'.e(route('script.show', $row->id)).'">'.e($value).'</a>';
        if ($row->description) {
            $html .= '<div class="small text-muted">'.e($row->description).'</div>';
        }

        return $html;
    }

    public function renderColumnPlatform($value): string
    {
        return e(__(Script::PLATFORMS[$value] ?? $value));
    }

    public function renderColumnVersion($value): string
    {
        return 'v'.e($value);
    }

    public function renderColumnFingerprint($value): string
    {
        return '<code title="'.e($value).'">'.e(substr((string) $value, 0, 12)).'…</code>';
    }

    public function renderColumnUpdatedAt($value): string
    {
        return $value ? '<span title="'.e($value).'">'.e($value->diffForHumans()).'</span>' : '';
    }

    public function actions($item): array
    {
        return [
            [
                'type' => 'url',
                'url' => route('script.show', $item['id']),
                'text' => __('Detail'),
                'iconClass' => 'fas fa-eye',
            ],
            [
                'type' => 'livewire',
                'action' => 'run',
                'parameters' => $item['id'],
                'text' => __('Run'),
                'iconClass' => 'fas fa-play',
            ],
            [
                'type' => 'livewire',
                'action' => 'edit',
                'parameters' => $item['id'],
                'text' => __('Edit'),
                'iconClass' => 'fas fa-pen',
            ],
            [
                'type' => 'livewire',
                'action' => 'remove',
                'parameters' => $item['id'],
                'text' => __('Remove'),
                'actionClass' => 'text-danger',
                'iconClass' => 'fas fa-trash',
                'confirm' => __('Remove the script and its results?'),
            ],
        ];
    }

    public function run($id)
    {
        $this->dispatch('openModal', 'script.run', __('Run :name', ['name' => Script::find($id)?->name]), ['scriptId' => (int) $id], 'lg');
    }

    public function edit($id)
    {
        $this->dispatch('openModal', 'script.form', __('Edit script'), ['scriptId' => (int) $id], 'xl');
    }

    public function remove($id)
    {
        Gate::authorize('is-system-admin');
        Script::find($id)?->delete();
        alert()->success(__('Script removed'))->now();
    }
}
