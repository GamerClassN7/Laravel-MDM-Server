// Read-only Ace editor for showing code with syntax highlighting (<x-code-viewer>). The code is
// read from a <template>, so it is shown as plain text until Ace is loaded.
window.codeViewer = function (language, theme = 'tomorrow_night') {
    return {
        init() {
            if (typeof ace === 'undefined') {
                return;
            }
            const editor = ace.edit(this.$refs.viewer, {
                mode: 'ace/mode/' + language,
                theme: 'ace/theme/' + theme,
                readOnly: true,
                minLines: 3,
                maxLines: 40,
                showPrintMargin: false,
                highlightActiveLine: false,
                highlightGutterLine: false,
            });
            editor.renderer.$cursorLayer.element.style.display = 'none';
            editor.session.setValue(this.$refs.source.content.textContent, -1);
            this.$refs.fallback.remove();
            this.$refs.viewer.classList.remove('d-none');
        },
    };
};
