<div>
    <div class="container-xl">
        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="list-group">
                    @forelse ($devices as $device)
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ isset($selectedDevice) && $device->id == $selectedDevice->id ? 'active' : '' }}" href="#" wire:click.prevent="selectDevice({{ $device->id }})">
                            <span>
                                <i class="{{ $device->typeIcon }} me-2" title="{{ __(ucfirst($device->type)) }}"></i>{{ $device->DisplayName }}
                            </span>
                            @if ($device->offline)
                                <x-badge color="secondary" size="sm" variant="subtle">{{ __('Offline') }}</x-badge>
                            @else
                                <x-badge color="success" size="sm" variant="subtle">{{ __('Online') }}</x-badge>
                            @endif
                        </a>
                    @empty
                        <div class="list-group-item text-muted">{{ __('No devices enrolled yet.') }}</div>
                    @endforelse
                </div>
                <div class="d-grid mt-2">
                    <button class="btn btn-primary" type="button" wire:click.prevent="$set('addDevice', true)">
                        <i class="fas fa-plus me-2"></i>{{ __('Add device') }}
                    </button>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                @if ($addDevice)
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">{{ __('Enrol new device') }}</h5>
                            <p class="text-muted mb-2">{{ __('Enter this code in the agent to register the device.') }}</p>
                            <h2 class="display-5 fw-semibold">{{ $enrollmentCode }}</h2>
                            <p class="text-muted">
                                <i class="far fa-clock me-1"></i>{{ __('Expires') }} {{ $enrollmentCodeExpiration->diffForHumans() }} ({{ $enrollmentCodeExpiration }})
                            </p>

                            <h6 class="mt-4">{{ __('Install the agent') }}</h6>
                            <div x-data="{ tab: 'windows' }">
                                <ul class="nav nav-tabs mb-2">
                                    <li class="nav-item">
                                        <button class="nav-link" type="button" x-bind:class="{ active: tab === 'windows' }" x-on:click="tab = 'windows'">
                                            <i class="fab fa-windows me-2"></i>Windows PowerShell
                                        </button>
                                    </li>
                                    <li class="nav-item">
                                        <button class="nav-link" type="button" x-bind:class="{ active: tab === 'pwsh' }" x-on:click="tab = 'pwsh'">
                                            <i class="fas fa-terminal me-2"></i>PowerShell 7 (Windows, Linux)
                                        </button>
                                    </li>
                                </ul>

                                @foreach (['windows' => __('Run in PowerShell as Administrator.'), 'pwsh' => __('Run in pwsh as Administrator on Windows, or with sudo pwsh on Debian / Ubuntu.')] as $variant => $hint)
                                    <div x-show="tab === '{{ $variant }}'" @if ($variant !== 'windows') x-cloak style="display: none" @endif>
                                        <x-copy-command :command="$installCommands[$variant]" />
                                        <p class="small text-muted mt-2 mb-0">{{ $hint }}</p>
                                    </div>
                                @endforeach
                            </div>

                            <p class="small text-muted mt-3 mb-0" title="{{ __('The agent accepts only commands, responses and updates signed with this key. It logs the fingerprint it pinned in agent.log.') }}">
                                <i class="fas fa-key me-1"></i>{{ __('Server key fingerprint') }}:
                                <code class="text-break">{{ \App\Support\Signing::fingerprint() }}</code>
                            </p>
                        </div>
                    </div>
                @elseif (isset($selectedDevice))
                    @livewire('device-detail', ['selectedDeviceId' => $selectedDevice->id], key($selectedDevice->id))
                @endif
            </div>
        </div>
    </div>
</div>
