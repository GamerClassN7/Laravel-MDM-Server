<div>
    <div class="container-xl">
        <div class="page-header">
            <div class="me-auto min-w-0">
                <h1 class="mb-1">{{ __('Notifications') }}</h1>
                <div class="small text-muted">{{ __('Get told when a device goes down, runs out of disk space or a service fails.') }}</div>
            </div>
        </div>
        <x-boilerplate::alerts />

        <div class="row g-4">
            <div class="col-12 col-xl-5">
                <div class="card card-body">
                    <h5 class="card-title">{{ __('Where to send') }}</h5>

                    <form wire:submit="save">
                        <label class="form-label" for="notification-emails">{{ __('E-mail addresses') }}</label>
                        <div class="d-flex gap-2 align-items-start">
                            <textarea class="form-control @error('emails') is-invalid @enderror" id="notification-emails" placeholder="admin@example.com" rows="2" wire:model="emails"></textarea>
                            <button class="btn btn-light text-nowrap" type="button" wire:click="testEmail" wire:loading.attr="disabled" wire:target="testEmail" title="{{ __('Send a test e-mail') }}">
                                <i class="fas fa-paper-plane me-1"></i>{{ __('Test') }}
                            </button>
                        </div>
                        @error('emails') <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                        @isset($tests['email'])
                            <div class="small mt-1 {{ $tests['email']['ok'] ? 'text-success' : 'text-danger' }}">{{ $tests['email']['message'] }}</div>
                        @endisset
                        <div class="form-text">{{ __('One per line. Uses the mail settings of the server (MAIL_*).') }}</div>

                        <label class="form-label mt-3">{{ __('Push and webhook URLs') }}</label>
                        <div class="vstack gap-2">
                            @foreach ($urls as $index => $url)
                                <div wire:key="notification-url-{{ $index }}">
                                    <div class="d-flex gap-2">
                                        <input class="form-control font-monospace @error('urls.' . $index) is-invalid @enderror" placeholder="ntfy://ntfy.sh/my-topic" type="text" wire:model="urls.{{ $index }}" autocomplete="off" spellcheck="false">
                                        <button class="btn btn-light text-nowrap" type="button" wire:click="testUrl({{ $index }})" wire:loading.attr="disabled" wire:target="testUrl({{ $index }})" title="{{ __('Send a test notification') }}">
                                            <span class="spinner-border spinner-border-sm me-1" wire:loading wire:target="testUrl({{ $index }})"></span>
                                            <i class="fas fa-paper-plane me-1" wire:loading.remove wire:target="testUrl({{ $index }})"></i>{{ __('Test') }}
                                        </button>
                                        <button class="btn btn-light btn-sq" type="button" wire:click="removeUrl({{ $index }})" title="{{ __('Remove') }}">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    @error('urls.' . $index) <div class="small text-danger mt-1">{{ $message }}</div> @enderror
                                    @isset($tests[$index])
                                        <div class="small mt-1 text-break {{ $tests[$index]['ok'] ? 'text-success' : 'text-danger' }}">
                                            <i class="fas {{ $tests[$index]['ok'] ? 'fa-check' : 'fa-times' }} me-1"></i>{{ $tests[$index]['message'] }}
                                        </div>
                                    @endisset
                                </div>
                            @endforeach
                        </div>
                        <button class="btn btn-sm btn-link px-0 mt-1" type="button" wire:click="addUrl" @disabled(count($urls) >= \App\Models\NotificationSetting::MAX_CHANNELS)>
                            <i class="fas fa-plus me-1"></i>{{ __('Add URL') }}
                        </button>

                        <details class="small text-muted mt-2">
                            <summary>{{ __('URL formats (Shoutrrr, as in Beszel)') }}</summary>
                            <table class="table table-sm small mt-2 mb-0">
                                <tbody>
                                    @foreach (\App\Support\Notifier::EXAMPLES as $service => $example)
                                        <tr>
                                            <td class="text-nowrap">{{ $service }}</td>
                                            <td><code class="text-break">{{ $example }}</code></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <div class="mt-1">{{ __('?disabletls=yes sends over http (self-hosted ntfy, Gotify, webhooks in your network). A webhook gets a JSON POST with title and message.') }}</div>
                        </details>

                        <div class="d-flex justify-content-end mt-3">
                            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-12 col-xl-7">
                <div class="card card-body">
                    <div class="d-flex align-items-center mb-2">
                        <h5 class="card-title mb-0 me-auto">{{ __('Alerts') }}</h5>
                        <button class="btn btn-primary btn-sm" type="button" wire:click="addRule">
                            <i class="fas fa-plus me-1"></i>{{ __('Add alert') }}
                        </button>
                    </div>
                    @if (! $hasChannels && $rules->isNotEmpty())
                        <div class="alert alert-warning small py-2">{{ __('No e-mail address or URL saved yet: alerts are recorded, but not sent.') }}</div>
                    @endif

                    <div class="list-group list-group-flush">
                        @forelse ($rules as $rule)
                            <div class="list-group-item px-0 d-flex align-items-center gap-3" wire:key="alert-rule-{{ $rule->id }}">
                                <i class="{{ $rule->icon }} fa-fw text-body-secondary"></i>
                                <div class="flex-grow-1 min-w-0 {{ $rule->enabled ? '' : 'opacity-50' }}">
                                    <div class="fw-semibold">{{ $rule->label }}</div>
                                    <div class="small text-muted d-flex flex-wrap align-items-center gap-1">{{ $rule->condition }} · <x-target-summary :target="$rule->target ?? []" /></div>
                                </div>
                                <div class="form-check form-switch m-0" title="{{ $rule->enabled ? __('Enabled') : __('Disabled') }}">
                                    <input class="form-check-input" type="checkbox" wire:click="toggleRule({{ $rule->id }})" @checked($rule->enabled)>
                                </div>
                                <button class="btn btn-sm btn-light btn-sq" type="button" wire:click="editRule({{ $rule->id }})" title="{{ __('Edit') }}"><i class="fas fa-pen"></i></button>
                                <button class="btn btn-sm btn-light btn-sq" type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="{{ __('Delete this alert?') }}" title="{{ __('Delete') }}"><i class="fas fa-trash"></i></button>
                            </div>
                        @empty
                            <div class="text-muted small py-2">{{ __('No alerts yet. Add one, e.g. Status for all devices, or use the bell on a device.') }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="card card-body mt-4" wire:poll.30s>
                    <h5 class="card-title">{{ __('Recent alerts') }}</h5>
                    <div class="list-group list-group-flush">
                        @forelse ($events as $event)
                            <div class="list-group-item px-0 d-flex align-items-start gap-3" wire:key="alert-event-{{ $event->id }}">
                                <i class="fas fa-circle small mt-1 {{ $event->resolved_at ? 'text-success' : 'text-danger' }}"></i>
                                <div class="flex-grow-1 min-w-0">
                                    <div>
                                        <a href="{{ route('devices', ['selectedDeviceId' => $event->device_id]) }}">{{ $event->device?->displayName }}</a>
                                        · {{ $event->rule?->label }}
                                    </div>
                                    <div class="small text-muted text-break">{{ $event->message }}</div>
                                </div>
                                <div class="small text-muted text-end text-nowrap">
                                    <div title="{{ $event->triggered_at }}">{{ $event->triggered_at->diffForHumans() }}</div>
                                    @if ($event->resolved_at)
                                        <div title="{{ $event->resolved_at }}">{{ __('resolved') }} {{ $event->resolved_at->diffForHumans() }}</div>
                                    @else
                                        <x-badge color="danger" size="sm" variant="subtle">{{ __('Active') }}</x-badge>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-muted small py-2">{{ __('Nothing happened yet.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
