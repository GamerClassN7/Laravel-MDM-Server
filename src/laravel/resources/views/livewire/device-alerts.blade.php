{{-- Polls faster while an action is on its way, so its progress stays current. --}}
<div @if ($busy) wire:poll.3s @else wire:poll.15s @endif>
    @if ($alerts)
        <div class="card mt-3">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fas fa-bell text-body-secondary"></i>
                <span class="fw-semibold">{{ __('Needs attention') }}</span>
                <x-badge class="ms-auto" :color="$alerts[0]['severity']" size="sm" variant="subtle">{{ count($alerts) }}</x-badge>
            </div>
            {{-- The most important ones; the rest (less severe) behind "Show all". --}}
            <div class="list-group list-group-flush" x-data="{ all: false }">
                @foreach ($alerts as $alert)
                    @if ($loop->index < 4)
                        @include('partials.device.alert', ['alert' => $alert, 'device' => $selectedDevice])
                    @else
                        <div x-show="all" x-cloak style="display: none">@include('partials.device.alert', ['alert' => $alert, 'device' => $selectedDevice])</div>
                    @endif
                @endforeach
                @if (count($alerts) > 4)
                    <button class="list-group-item list-group-item-action small text-center text-body-secondary" type="button" x-on:click="all = !all">
                        <span x-show="!all">{{ __('Show all (:count)', ['count' => count($alerts)]) }} <i class="fas fa-chevron-down ms-1"></i></span>
                        <span x-show="all" x-cloak style="display: none">{{ __('Show less') }} <i class="fas fa-chevron-up ms-1"></i></span>
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
