@props(['command'])

<div class="position-relative" x-data="{
    copied: false,
    copy(text) {
        const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2000) };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }
        // Clipboard API needs HTTPS; fall back to a temporary textarea.
        const area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
        done();
    },
}">
    <pre class="bg-body-tertiary border rounded p-3 mb-0 small" style="white-space: pre-wrap; word-break: break-all; padding-right: 4rem !important;"><code x-ref="command">{{ $command }}</code></pre>
    <button class="btn btn-sm btn-light border position-absolute top-0 end-0 m-2" title="{{ __('Copy') }}" type="button" x-on:click="copy($refs.command.textContent)">
        <i class="far fa-copy" x-show="!copied"></i>
        <i class="fas fa-check text-success" x-show="copied" x-cloak style="display: none"></i>
    </button>
</div>
