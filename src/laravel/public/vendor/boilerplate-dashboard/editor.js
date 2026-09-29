// Alpine component for the dashboard editor (drag & drop + resize of widgets).
(() => {
    const register = () => Alpine.data('dashboardEditor', () => ({
        drag: null,
        over: null,
        rowDrag: null,
        rowOver: null,
        dragChip(e, text) {
            const chip = document.createElement('div');
            chip.className = 'dash-drag-chip';
            chip.textContent = text;
            document.body.appendChild(chip);
            e.dataTransfer.setDragImage(chip, 12, 12);
            setTimeout(() => chip.remove(), 0);
        },
        start(e, row, index) {
            this.drag = { row, index };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row + ':' + index);
            this.dragChip(e, e.target.closest('[data-widget]').querySelector('h5')?.textContent.trim() || 'Widget');
        },
        hover(e, row, index) {
            if (!this.drag) return;
            const r = e.currentTarget.getBoundingClientRect();
            const after = (e.clientX - r.left) > r.width / 2 || (e.clientY - r.top) > r.height * 0.75;
            this.over = { row, index: index + (after ? 1 : 0) };
        },
        drop() {
            if (!this.drag) return;
            if (this.drag && this.over) {
                let to = this.over.index;
                if (this.drag.row === this.over.row && this.drag.index < to) to--;
                if (!(this.drag.row === this.over.row && this.drag.index === to)) {
                    this.$wire.moveWidget(this.drag.row, this.drag.index, this.over.row, to);
                }
            }
            this.end();
        },
        showAt(row, index) {
            if (!this.drag || !this.over || this.over.row !== row || this.over.index !== index) return false;
            // dropping right where the widget already is would change nothing
            return !(this.drag.row === row && (index === this.drag.index || index === this.drag.index + 1));
        },
        targetRow(row) {
            return !!(this.drag && this.over && this.over.row === row && this.drag.row !== row);
        },
        end() { this.drag = null; this.over = null; this.rowDrag = null; this.rowOver = null; },
        startRow(e, index) {
            this.rowDrag = { index };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'row:' + index);
            this.dragChip(e, e.target.closest('[data-row]').querySelector('h5')?.textContent.trim() || 'Row');
        },
        hoverRow(e, index, count) {
            if (this.drag) {
                // widget dragged over the container itself (not over another widget): append to its end
                e.preventDefault();
                this.over = { row: index, index: count };
                return;
            }
            if (!this.rowDrag) return;
            e.preventDefault();
            const r = e.currentTarget.getBoundingClientRect();
            const after = (e.clientY - r.top) > r.height / 2;
            this.rowOver = { index: index + (after ? 1 : 0) };
        },
        dropRow() {
            if (this.drag) return this.drop();
            if (this.rowDrag && this.rowOver) {
                let to = this.rowOver.index;
                if (this.rowDrag.index < to) to--;
                if (to !== this.rowDrag.index) this.$wire.moveRow(this.rowDrag.index, to);
            }
            this.end();
        },
        resize(e, row, index, isGrid, axis) {
            const el = e.target.closest('[data-widget]');
            const box = el.parentElement;
            const card = el.querySelector(':scope > .card');
            const startX = e.clientX, startY = e.clientY;
            const startW = el.offsetWidth, startH = el.offsetHeight;
            const cs = getComputedStyle(box);
            const gapX = parseFloat(cs.columnGap) || 0, gapY = parseFloat(cs.rowGap) || 0;
            const colW = (box.clientWidth + gapX) / 12;
            const span = parseInt((el.style.gridRow || '').replace(/\D/g, '')) || 1;
            const rowH = (startH + gapY) / span;
            const handle = e.currentTarget;
        // natural (content) height: the widget must never be smaller than its content
        let minH = startH;
        if (axis === 'y') {
            const box2 = box.children;
            const saved = [...box2].map(c => [c.style.alignSelf, c.style.height]);
            [...box2].forEach(c => { c.style.alignSelf = 'start'; c.style.height = 'auto'; });
            minH = el.offsetHeight;
            [...box2].forEach((c, i) => { c.style.alignSelf = saved[i][0]; c.style.height = saved[i][1]; });
        }
            handle.setPointerCapture(e.pointerId);
            el.setAttribute('data-resizing', '');
            const siblings = [...box.children].filter(c => c !== el && c.hasAttribute('data-widget'));
            if (axis === 'y') siblings.forEach(c => { c.style.alignSelf = 'start'; c.style.height = c.offsetHeight + 'px'; });
            document.body.style.userSelect = 'none';
            let w = parseInt(el.dataset.width) || Math.round(startW / colW), h = span;
            card.dataset.live = axis === 'x' ? w + '/12' : h;
            const move = (ev) => {
                if (axis === 'x') {
                    w = Math.max(1, Math.min(12, Math.round((startW + ev.clientX - startX + gapX) / colW)));
                    card.dataset.live = w + '/12';
                    if (isGrid) el.style.gridColumn = 'span ' + w;
                    else { el.style.flex = '0 0 auto'; el.style.width = (w / 12 * 100) + '%'; }
                } else {
                    const px = Math.max(minH, Math.min(Math.max(minH, rowH * 4 - gapY), startH + ev.clientY - startY));
                    h = Math.max(1, Math.min(4, Math.round((px + gapY) / rowH)));
                    card.dataset.live = h;
                    el.style.height = px + 'px';
                }
            };
            const up = () => {
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', up);
                handle.removeEventListener('pointercancel', up);
                el.removeAttribute('data-resizing');
                card.removeAttribute('data-live');
                el.style.height = '';
                siblings.forEach(c => { c.style.alignSelf = ''; c.style.height = ''; });
                document.body.style.userSelect = '';
                this.$wire.resizeWidget(row, index, w, isGrid ? h : null);
            };
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', up);
        }
    }));

    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
})();
