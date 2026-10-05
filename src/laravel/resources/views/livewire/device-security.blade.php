{{-- Findings of the scanner, then the inventory they come from (one list at a time). --}}
<div x-data="{ list: 'posture', search: '' }">
    @php
        $posture = array_filter($inventory?->data['posture'] ?? [], fn ($value) => $value !== null);
        $lists = [
            'posture' => [__('Settings'), count($posture)],
            'listening' => [__('Open ports'), count($inventory?->data['listening'] ?? [])],
            'admins' => [__('Administrators'), count($inventory?->data['admins'] ?? [])],
            'startup' => [__('Startup'), count($inventory?->data['startup'] ?? [])],
            'software' => [__('Software'), count($inventory?->data['software'] ?? [])],
            'processes' => [__('Processes'), count($inventory?->data['processes'] ?? [])],
            'events' => [__('Events'), $events->count()],
        ];
        $columns = [
            'listening' => ['Protocol', 'Address', 'Port', 'Process'],
            'admins' => ['Name', 'Source', 'Enabled'],
            'startup' => ['Name', 'Location', 'Command'],
            'software' => ['Name', 'Version', 'Publisher', 'Source'],
            'processes' => ['Name', 'User', 'Path', 'CommandLine'],
        ];
        $yesNo = fn ($value) => is_bool($value) ? ($value ? __('Yes') : __('No')) : $value;
    @endphp

    <div class="card overflow-hidden mb-3">
        <div class="list-group list-group-flush">
            @forelse ($findings as $finding)
                @include('livewire.security-scan.partials.finding', ['finding' => $finding, 'showDevice' => false])
            @empty
                <div class="list-group-item text-muted py-3"><i class="fas fa-shield-alt text-success me-2"></i>{{ __('No findings.') }}</div>
            @endforelse
        </div>
    </div>

    @if ($inventory === null)
        <div class="small text-muted">{{ __('No security inventory yet: it needs agent :version or newer.', ['version' => \App\Models\Device::SECURITY_VERSION]) }}</div>
    @else
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div class="d-flex flex-wrap gap-1">
                @foreach ($lists as $key => [$label, $count])
                    <button class="btn btn-sm rounded-pill" type="button" x-on:click="list = '{{ $key }}'; search = ''" :class="list === '{{ $key }}' ? 'btn-primary' : 'btn-outline-secondary'">
                        {{ $label }} <span class="opacity-75">{{ $count }}</span>
                    </button>
                @endforeach
            </div>
            <input class="form-control form-control-sm ms-auto" placeholder="{{ __('Search') }}" style="max-width: 16rem;" type="search" x-model="search" x-show="list !== 'posture'">
        </div>

        <div x-show="list === 'posture'">
            @if ($posture === [])
                <div class="small text-muted">{{ __('Not reported.') }}</div>
            @else
                <div class="row g-2">
                    @foreach ($posture as $field => $value)
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="d-flex justify-content-between gap-2 rounded bg-body-tertiary px-3 py-2 small">
                                <span class="text-body-secondary">{{ \Illuminate\Support\Str::headline($field) }}</span>
                                <span class="fw-medium text-end text-break">{{ $yesNo($value) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        @foreach ($columns as $key => $fields)
            <div x-show="list === '{{ $key }}'" x-cloak>
                <div class="table-responsive" style="max-height: 32rem">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="sticky-top">
                            <tr>
                                @foreach ($fields as $field)
                                    <th class="{{ $loop->index > 1 ? 'd-none d-md-table-cell' : '' }}">{{ __(\Illuminate\Support\Str::headline($field)) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($inventory->data[$key] ?? [] as $item)
                                @php($text = mb_strtolower(implode(' ', array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $item))))
                                <tr wire:key="security-{{ $key }}-{{ $loop->index }}" x-show="!search || @js($text).includes(search.toLowerCase())">
                                    @foreach ($fields as $field)
                                        <td class="text-break {{ $loop->index > 1 ? 'd-none d-md-table-cell text-muted' : '' }} {{ $loop->first ? 'fw-medium' : '' }}">{{ $yesNo($item[$field] ?? null) ?? '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <div x-show="list === 'events'" x-cloak>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <tbody>
                        @forelse ($events as $event)
                            <tr x-show="!search || @js(mb_strtolower($event->label.' '.$event->user.' '.$event->source.' '.$event->message)).includes(search.toLowerCase())">
                                <td class="text-nowrap text-body-secondary" title="{{ $event->occurred_at }}">{{ $event->occurred_at->diffForHumans() }}</td>
                                <td class="fw-medium text-nowrap">{{ $event->label }}</td>
                                <td class="text-end">{{ $event->count }}×</td>
                                <td class="text-break">{{ $event->user }}</td>
                                <td class="d-none d-md-table-cell text-break text-muted">{{ $event->source }}</td>
                                <td class="d-none d-lg-table-cell text-break text-muted">{{ $event->message }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-muted">{{ __('No events.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <a class="small d-inline-block mt-2" href="{{ route('security', ['tab' => 'events']) }}">{{ __('All events') }}</a>
        </div>

        <div class="small text-body-tertiary mt-3" title="{{ $inventory->collected_at }}">{{ __('Collected :time', ['time' => $inventory->collected_at->diffForHumans()]) }}</div>
    @endif
</div>
