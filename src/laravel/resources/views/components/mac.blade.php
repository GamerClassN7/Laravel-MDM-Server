@props(['mac'])
@php
    $vendor = \App\Support\MacVendor::lookup($mac);
    $random = \App\Support\MacVendor::isLocal((string) $mac);
@endphp
{{-- A MAC address with the vendor of its card (offline IEEE data) or a note that it is a made-up one. --}}
<span {{ $attributes }}><span class="font-monospace">{{ $mac }}</span>@if ($vendor) <span class="text-body-secondary">· {{ $vendor }}</span>@elseif ($random) <span class="text-body-secondary" title="{{ __('A private address the device made up for this network: it may change.') }}">· {{ __('random MAC') }}</span>@endif</span>
