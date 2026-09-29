<div>
    <div class="container-xl">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h4 class="mb-1">{{ __('Remediation scripts') }}</h4>
                <p class="text-muted small mb-0">
                    {{ __('PowerShell detection (exit 0 = compliant, exit 1 = run the remediation) and remediation scripts. They run as SYSTEM / root without network access, only on agents that sign their communication and allow scripts.') }}
                </p>
            </div>
            <button class="btn btn-primary" type="button" wire:click="create">
                <i class="fas fa-plus me-2"></i>{{ __('New script') }}
            </button>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Platform') }}</th>
                            <th>{{ __('Version') }}</th>
                            <th>{{ __('Fingerprint') }}</th>
                            <th>{{ __('Results') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($scripts as $script)
                            @php($results = ($latest[$script->id] ?? collect())->countBy('status'))
                            <tr class="{{ $selected?->id === $script->id ? 'table-active' : '' }}" role="button" wire:click="select({{ $script->id }})">
                                <td>
                                    <div class="fw-semibold">{{ $script->name }}</div>
                                    @if ($script->description)
                                        <div class="small text-muted">{{ $script->description }}</div>
                                    @endif
                                    @if ($script->remediation === null)
                                        <x-badge color="secondary" size="sm" variant="subtle">{{ __('Detection only') }}</x-badge>
                                    @endif
                                </td>
                                <td>{{ __(\App\Models\Script::PLATFORMS[$script->platform]) }}</td>
                                <td>v{{ $script->version }}</td>
                                <td><code title="{{ $script->fingerprint }}">{{ substr($script->fingerprint, 0, 12) }}…</code></td>
                                <td>
                                    @foreach (['compliant' => 'success', 'remediated' => 'success', 'failed' => 'danger', 'error' => 'danger', 'rejected' => 'danger', 'pending' => 'info', 'sent' => 'info'] as $status => $color)
                                        @if ($results[$status] ?? 0)
                                            <x-badge class="me-1" :color="$color" size="sm" variant="subtle">{{ __(ucfirst($status)) }} {{ $results[$status] }}</x-badge>
                                        @endif
                                    @endforeach
                                </td>
                                <td class="text-end text-nowrap" x-on:click.stop>
                                    <button class="btn btn-sm btn-primary" type="button" wire:click="run({{ $script->id }})">
                                        <i class="fas fa-play me-1"></i>{{ __('Run') }}
                                    </button>
                                    <button class="btn btn-sm btn-light" type="button" title="{{ __('Edit') }}" wire:click="edit({{ $script->id }})"><i class="fas fa-pen"></i></button>
                                    <button class="btn btn-sm btn-light text-danger" type="button" title="{{ __('Remove') }}" wire:click="remove({{ $script->id }})" wire:confirm="{{ __('Remove the script and its results?') }}"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="text-muted text-center py-4" colspan="6">{{ __('No scripts yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($selected)
            <div class="card mt-4" wire:poll.10s>
                <div class="card-body">
                    <h5 class="card-title">{{ __('Runs of :name', ['name' => $selected->name]) }}</h5>
                    <p class="small text-muted">{{ __('Fingerprint') }} v{{ $selected->version }}: <code class="text-break">{{ $selected->fingerprint }}</code></p>
                    @include('livewire.scripts.runs', ['runs' => $runs, 'showDevice' => true])
                </div>
            </div>
        @endif

        <p class="small text-muted mt-3">
            <i class="fas fa-key me-1"></i>{{ __('Runs are signed with the server key') }} <code class="text-break">{{ $serverFingerprint }}</code>.
            {{ __('Agents can disable scripts locally with -DisableScripts, the server cannot change that.') }}
        </p>
    </div>
</div>
