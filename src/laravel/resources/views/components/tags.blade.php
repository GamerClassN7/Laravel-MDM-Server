{{-- The tags of a device (or any list of tags); links filter the device list when "links" is set. --}}
@props(['tags' => [], 'links' => false])
@if (count($tags) > 0)
    <div {{ $attributes->class(['mdm-tags']) }}>
        @foreach ($tags as $tag)
            <x-tag :tag="$tag" :href="$links ? route('devices', ['tag' => $tag]) : null" />
        @endforeach
        {{ $slot }}
    </div>
@endif
