{{-- One device tag. As a link (href), a button or a label (as="label", for pickers); "active"
     highlights a selected one. --}}
@props(['tag', 'active' => false, 'href' => null, 'as' => null])
@php
    $element = $href ? 'a' : ($as ?? 'span');
@endphp
<{{ $element }} @if ($href) href="{{ $href }}" @endif @if ($element === 'button') type="button" @endif
    {{ $attributes->class(['mdm-tag', 'is-active' => $active]) }}>
    <i class="fas fa-tag"></i>{{ $tag }}{{ $slot }}
</{{ $element }}>
