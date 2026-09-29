<div>
    <div class="container-xl">
        <div class="page-header">
            <h1>{{ $script->name }}</h1>
            <a class="btn btn-secondary" href="{{ route('script.index') }}">
                <i class="me-2 fas fa-arrow-left"></i><span>{{ __('Back') }}</span>
            </a>
            <button class="btn btn-light" type="button" wire:click="edit">
                <i class="me-2 fas fa-pen"></i><span>{{ __('Edit') }}</span>
            </button>
            <button class="btn btn-primary" type="button" wire:click="run">
                <i class="me-2 fas fa-play"></i><span>{{ __('Run') }}</span>
            </button>
        </div>
        <x-boilerplate::alerts />

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        @if ($script->description)
                            <p>{{ $script->description }}</p>
                        @endif
                        <dl class="row mb-0">
                            <dt class="col-5 fw-normal text-muted">{{ __('Platform') }}</dt>
                            <dd class="col-7">{{ __(\App\Models\Script::PLATFORMS[$script->platform]) }}</dd>
                            <dt class="col-5 fw-normal text-muted">{{ __('Version') }}</dt>
                            <dd class="col-7">v{{ $script->version }}</dd>
                            <dt class="col-5 fw-normal text-muted">{{ __('Timeout') }}</dt>
                            <dd class="col-7">{{ $script->timeout }} s</dd>
                            <dt class="col-5 fw-normal text-muted">{{ __('Changed') }}</dt>
                            <dd class="col-7" title="{{ $script->updated_at }}">{{ $script->updated_at->diffForHumans() }}</dd>
                            <dt class="col-12 fw-normal text-muted">{{ __('Fingerprint') }}</dt>
                            <dd class="col-12 mb-0"><code class="text-break">{{ $script->fingerprint }}</code></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-8">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">{{ __('Detection script') }}</h6>
                        <pre class="small bg-body-tertiary p-2 rounded" style="max-height: 16rem; white-space: pre-wrap;">{{ $script->detection }}</pre>
                        <h6 class="card-title">{{ __('Remediation script') }}</h6>
                        @if ($script->remediation !== null)
                            <pre class="small bg-body-tertiary p-2 rounded mb-0" style="max-height: 16rem; white-space: pre-wrap;">{{ $script->remediation }}</pre>
                        @else
                            <p class="text-muted mb-0">{{ __('No remediation script.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <h4 class="mt-4">{{ __('Runs') }}</h4>
        <div wire:poll.10s>
            @livewire('script-run.data-table', ['scriptId' => $script->id], key('script-runs-'.$script->id))
        </div>
    </div>
</div>
