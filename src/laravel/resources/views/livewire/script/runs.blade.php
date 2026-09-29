@if ($runs->isEmpty())
    <p class="text-muted mb-0">{{ __('Not run yet.') }}</p>
@else
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    @if ($showDevice)
                        <th>{{ __('Device') }}</th>
                    @else
                        <th>{{ __('Script') }}</th>
                    @endif
                    <th>{{ __('Version') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Exit codes') }}</th>
                    <th>{{ __('Issued') }}</th>
                    <th>{{ __('Finished') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td>
                            @if ($showDevice)
                                <a href="{{ route('devices', ['selectedDeviceId' => $run->device_id, 'tab' => 'scripts']) }}">{{ $run->device?->displayName }}</a>
                            @else
                                <a href="{{ route('script.show', $run->script_id) }}">{{ $run->script?->name }}</a>
                            @endif
                        </td>
                        <td><span title="{{ $run->fingerprint }}">v{{ $run->version }} · <code>{{ substr($run->fingerprint, 0, 8) }}</code></span></td>
                        <td><x-badge :color="$run->statusColor" size="sm" variant="subtle">{{ __(ucfirst($run->status)) }}</x-badge></td>
                        <td class="small text-muted">
                            {{ collect([__('detection') => $run->detection_exit, __('remediation') => $run->remediation_exit, __('after') => $run->post_detection_exit])->reject(fn ($code) => $code === null)->map(fn ($code, $step) => "$step $code")->implode(', ') }}
                        </td>
                        <td class="small" title="{{ $run->issued_at }}">{{ $run->issued_at->diffForHumans() }}</td>
                        <td class="small" title="{{ $run->finished_at }}">{{ $run->finished_at?->diffForHumans() }}</td>
                    </tr>
                    @if ($run->error || $run->output)
                        <tr>
                            <td class="border-top-0 pt-0" colspan="6">
                                @if ($run->error)
                                    <div class="small text-danger">{{ $run->error }}</div>
                                @endif
                                @if ($run->output)
                                    <details>
                                        <summary class="small text-muted">{{ __('Output') }}</summary>
                                        <pre class="small bg-body-tertiary p-2 rounded mb-0" style="max-height: 20rem; white-space: pre-wrap;">{{ $run->output }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
@endif
