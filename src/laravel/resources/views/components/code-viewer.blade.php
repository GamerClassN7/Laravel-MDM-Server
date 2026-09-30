@props(['code', 'language' => 'powershell'])

{{-- Read-only code with syntax highlighting (Ace, as the editor in the forms). --}}
<div {{ $attributes->class(['code-viewer rounded overflow-hidden']) }} wire:ignore x-data="codeViewer(@js($language))">
    <template x-ref="source">{{ $code }}</template>
    <pre class="small bg-body-tertiary p-2 mb-0" style="max-height: 24rem; white-space: pre-wrap;" x-ref="fallback">{{ $code }}</pre>
    <div class="d-none" x-ref="viewer"></div>
</div>

@assets
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.39.1/ace.js" integrity="sha512-tGc7XQXpQYGpFGmdQCEaYhGdJ8B64vyI9c8zdEO4vjYaWRCKYnLy+HkudtawJS3ttk/Pd7xrkRjK8ijcMMyauw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
@endassets
