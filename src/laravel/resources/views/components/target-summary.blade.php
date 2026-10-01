{{-- Where a schedule or an alert applies: all devices, the tags (x-tag) and the picked devices. --}}
@props(['target' => []])
@php
    $target = \App\Models\Device::normalizeTarget($target);
    $names = $target['devices'] === [] ? [] : \App\Models\Device::query()->whereIn('id', $target['devices'])->get()->map->displayName->all();
@endphp
<span {{ $attributes->class(['d-inline-flex flex-wrap align-items-center gap-1']) }}>
    @if ($target['all'])
        <span><i class="fas fa-desktop me-1"></i>{{ __('All devices') }}</span>
    @else
        @foreach ($target['tags'] as $tag)
            <x-tag :tag="$tag" />
        @endforeach
        @if ($names !== [])
            <span><i class="fas fa-desktop me-1"></i>{{ implode(', ', $names) }}</span>
        @endif
        @if ($target['tags'] === [] && $names === [])
            <span>{{ __('No devices') }}</span>
        @endif
    @endif
</span>
