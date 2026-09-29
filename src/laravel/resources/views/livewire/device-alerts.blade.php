<div wire:poll>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <x-badge :color="$selectedDevice->connectedViaWebsocket ? 'success' : 'secondary'" icon="fas fa-bolt" title="{{ $selectedDevice->last_ws_at ? __('Last WebSocket heartbeat :time', ['time' => $selectedDevice->last_ws_at->diffForHumans()]) : __('Never connected over WebSocket') }}" variant="subtle">
            WebSocket
        </x-badge>
        <x-badge :color="$selectedDevice->connectedViaApi ? 'success' : 'secondary'" icon="fas fa-exchange-alt" title="{{ __('Last report :time', ['time' => ($selectedDevice->last_http_at ?? $selectedDevice->updated_at)->diffForHumans()]) }}" variant="subtle">
            REST API
        </x-badge>
        <x-badge :color="$selectedDevice->agentVersion === null ? 'secondary' : ($selectedDevice->agentOutdated ? 'warning' : 'success')" icon="fas fa-robot" title="{{ $selectedDevice->agentOutdated ? __('A newer agent is available') : __('The agent is up to date') }}" variant="subtle">
            {{ __('Agent') }} {{ $selectedDevice->agentVersion ?? __('unknown') }}
        </x-badge>
        @if ($virtualization = $selectedDevice->virtualization)
            <x-badge color="info" :icon="$virtualization['type'] === 'container' ? 'fas fa-box' : 'fas fa-clone'" title="{{ $virtualization['type'] === 'container' ? __('Runs in a container') : __('Runs in a virtual machine') }}" variant="subtle">
                {{ $virtualization['type'] === 'container' ? __('Container') : __('Virtual machine') }} · {{ $virtualization['label'] }}
            </x-badge>
        @endif
    </div>

    @if ($selectedDevice->offline)
        <div class="alert alert-secondary mt-3 mb-0" role="alert">
            <i class="fas fa-plug me-2"></i>{{ __('Device is offline!') }}
        </div>
    @elseif ($selectedDevice->restartPending)
        <div class="alert alert-warning mt-3 mb-0" role="alert">
            <i class="fas fa-redo me-2"></i>{{ __('Device is in restart pending state!') }}
        </div>
    @endif

    @if ($selectedDevice->agentOutdated)
        <div class="alert alert-warning mt-3 mb-0" role="alert">
            <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="me-auto">
                <i class="fas fa-arrow-circle-up me-2"></i>
                {{ __('A newer agent is available (:current → :latest).', ['current' => $selectedDevice->agentVersion ?? __('unknown'), 'latest' => $latestAgentVersion]) }}
                @unless ($selectedDevice->agentUpdatable)
                    {{ __('This agent cannot update itself, reinstall it with the command from Add device.') }}
                @endunless
            </span>
            @if ($selectedDevice->agentUpdatable && ! $remoteUpdate)
                <span class="small">{{ __('Remote update needs the portal on HTTPS.') }}</span>
            @elseif ($selectedDevice->agentUpdatable)
                <button class="btn btn-sm btn-warning" type="button" wire:click="updateAgent" @disabled($selectedDevice->offline || in_array('updateAgent', $selectedDevice->commands ?? []))>
                    @if (in_array('updateAgent', $selectedDevice->commands ?? []))
                        <span aria-hidden="true" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    @else
                        <i class="fas fa-download me-2"></i>
                    @endif
                    {{ __('Update agent') }}
                </button>
            @endif
            </div>

            @if ($updateCommand)
                <div class="mt-3">
                    <div class="small mb-1">
                        {{ __('Or run on the device (:how):', ['how' => $selectedDevice->platform === 'linux' ? 'sudo pwsh' : __('PowerShell as Administrator')]) }}
                    </div>
                    <x-copy-command :command="$updateCommand" />
                </div>
            @endif
        </div>
    @endif
</div>
