// The network map of the Networks page (App\Support\NetworkMap): HTML nodes laid out top down with
// dagre (internet, public addresses, gateways, networks, devices), SVG links between them, panned
// by dragging and zoomed with the wheel, two fingers or the buttons. update() takes new data from a
// live update and keeps the view where it is.
import dagre from '@dagrejs/dagre';

// How many ranks a link spans: networks line up whether or not a gateway is known, VPNs (over the
// internet) at the level of the networks.
const SPAN = { tunnel: 3 };

const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

function nodeHtml(node) {
    const badges = (node.badges || []).map((badge) => `<span class="nm-badge${badge === 'isolated' ? ' is-warning' : ''}">${esc(badge)}</span>`).join('');
    const dot = node.kind === 'device' || (node.kind === 'unknown' && !node.more) ? `<span class="nm-dot is-${esc(node.state)}"></span>` : '';
    return `<span class="nm-icon"><i class="${esc(node.icon)}"></i></span>`
        + `<span class="nm-text"><span class="nm-label">${esc(node.label)}${badges}</span>`
        + (node.sub ? `<span class="nm-sub">${dot}${esc(node.sub)}</span>` : '')
        + '</span>';
}

function rankSpan(edge, nodes) {
    if (SPAN[edge.type]) {
        return SPAN[edge.type];
    }
    const from = nodes.get(edge.from)?.kind;
    const to = nodes.get(edge.to)?.kind;
    // A site without a known gateway: its networks still at the level of the networks.
    if (from === 'site' && to === 'network') {
        return 2;
    }
    // A device or this server without a network of its own: at the level of the devices.
    if (from === 'site' && (to === 'device' || to === 'server')) {
        return 3;
    }
    return 1;
}

export function createNetworkMap(root, data) {
    root.innerHTML = '<div class="nm-viewport"><div class="nm-stage"><svg class="nm-links"></svg><div class="nm-nodes"></div></div></div>'
        + '<div class="nm-tools">'
        + '<button class="btn btn-light btn-sm" type="button" data-zoom="in" title="Zoom in"><i class="fas fa-plus"></i></button>'
        + '<button class="btn btn-light btn-sm" type="button" data-zoom="out" title="Zoom out"><i class="fas fa-minus"></i></button>'
        + '<button class="btn btn-light btn-sm" type="button" data-zoom="fit" title="Fit"><i class="fas fa-expand"></i></button>'
        + '</div>';
    const viewport = root.querySelector('.nm-viewport');
    const stage = root.querySelector('.nm-stage');
    const svg = root.querySelector('.nm-links');
    const layer = root.querySelector('.nm-nodes');
    const view = { x: 0, y: 0, scale: 1 };
    let size = { width: 0, height: 0 };
    let fitted = false;

    const apply = () => {
        stage.style.transform = `translate(${view.x}px, ${view.y}px) scale(${view.scale})`;
    };
    // On load readable (at least 75 %, on phones 45 %); the button shows all of it.
    const fit = (all = false) => {
        const box = viewport.getBoundingClientRect();
        if (!size.width || !box.width) {
            return;
        }
        const whole = Math.min(1, (box.width - 32) / size.width, (box.height - 32) / size.height);
        view.scale = all ? Math.max(0.2, whole) : Math.max(box.width < 700 ? 0.45 : 0.75, whole);
        view.x = (box.width - size.width * view.scale) / 2;
        view.y = Math.max(16, (box.height - size.height * view.scale) / 2);
        apply();
    };
    const zoomAt = (factor, cx, cy) => {
        const scale = Math.min(2.5, Math.max(0.2, view.scale * factor));
        view.x = cx - ((cx - view.x) * scale) / view.scale;
        view.y = cy - ((cy - view.y) * scale) / view.scale;
        view.scale = scale;
        apply();
    };

    const render = (map) => {
        const nodes = new Map(map.nodes.map((node) => [node.id, node]));
        layer.innerHTML = '';
        const elements = new Map();
        for (const node of map.nodes) {
            const el = document.createElement(node.url ? 'a' : 'div');
            el.className = `nm-node is-${node.kind} tone-${node.tone} state-${node.state}${node.isolated ? ' is-isolated' : ''}`;
            if (node.url) {
                el.href = node.url;
            }
            el.innerHTML = nodeHtml(node);
            if (node.title) {
                el.title = node.title;
            }
            layer.appendChild(el);
            elements.set(node.id, el);
        }

        // Laid out with the sizes the nodes render at.
        // The devices of a network (and its unknown devices) sit in a grid in a frame under it, one
        // block for dagre. A device of several networks is in the grid of its main one (a wired LAN
        // before Wi-Fi before a VPN), in the first row; its other networks link to the device itself
        // (not to the frame: not all devices of a LAN are in the VPN).
        const parents = new Map();
        for (const edge of map.edges) {
            if (nodes.get(edge.from)?.kind === 'network') {
                parents.set(edge.to, [...(parents.get(edge.to) || []), edge.from]);
            }
        }
        const priority = { lan: 0, mixed: 0, cellular: 1, wifi: 2, vpn: 3 };
        const mainOf = (owners) => [...owners].sort((a, b) => (priority[nodes.get(a).network] ?? 1) - (priority[nodes.get(b).network] ?? 1) || (nodes.get(b).devices || 0) - (nodes.get(a).devices || 0))[0];
        const members = new Map();
        const main = new Map();
        for (const node of map.nodes) {
            const owners = parents.get(node.id) || [];
            if (owners.length > 0 && ['device', 'unknown', 'server'].includes(node.kind)) {
                main.set(node.id, mainOf(owners));
                members.set(main.get(node.id), [...(members.get(main.get(node.id)) || []), node.id]);
            }
        }
        const grids = new Map();
        const inGrid = new Set();
        const sideLinks = new Map();
        const GAP = { x: 14, y: 12, pad: 12 };
        const shared = new Set(map.edges.filter((edge) => main.has(edge.to) && edge.from !== main.get(edge.to)).map((edge) => edge.to));
        for (const [network, all] of members) {
            if (all.length < 4) {
                continue;
            }
            const ids = [...all.filter((id) => shared.has(id)), ...all.filter((id) => !shared.has(id))];
            const cols = Math.min(ids.length, 6, Math.max(3, Math.round(Math.sqrt(ids.length * 1.8))));
            const rows = Math.ceil(ids.length / cols);
            const colWidth = Array.from({ length: cols }, (_, c) => Math.max(...ids.filter((_, k) => k % cols === c).map((id) => elements.get(id).offsetWidth)));
            const rowHeight = Array.from({ length: rows }, (_, r) => Math.max(...ids.slice(r * cols, (r + 1) * cols).map((id) => elements.get(id).offsetHeight)));
            const width = colWidth.reduce((a, b) => a + b, 0) + GAP.x * (cols - 1) + GAP.pad * 2;
            const height = rowHeight.reduce((a, b) => a + b, 0) + GAP.y * (rows - 1) + GAP.pad * 2;
            grids.set(network, { id: `grid:${network}`, ids, cols, colWidth, rowHeight, width, height });
            ids.forEach((id) => inGrid.add(id));
        }
        // The other networks of the devices in a grid: a link to each of those devices.
        for (const edge of map.edges) {
            if (inGrid.has(edge.to) && edge.from !== main.get(edge.to)) {
                sideLinks.set(edge.id, { from: edge.from, to: edge.to, type: edge.type, up: edge.up });
            }
        }

        const graph = new dagre.graphlib.Graph({ compound: true });
        graph.setGraph({ rankdir: 'TB', nodesep: 28, ranksep: 64, edgesep: 12, marginx: 24, marginy: 24 });
        graph.setDefaultEdgeLabel(() => ({}));
        const clusterOf = (node) => {
            if (node.group && node.kind !== 'internet') {
                const cluster = `cluster:${node.group}`;
                if (!graph.hasNode(cluster)) {
                    graph.setNode(cluster, {});
                }
                graph.setParent(node.id, cluster);
            }
        };
        for (const [id, el] of elements) {
            if (!inGrid.has(id)) {
                graph.setNode(id, { width: el.offsetWidth, height: el.offsetHeight });
            }
        }
        for (const node of map.nodes) {
            if (!inGrid.has(node.id)) {
                clusterOf(node);
            }
        }
        for (const [network, grid] of grids) {
            // A narrow stand-in for the layout, widened below (centered under its network).
            graph.setNode(grid.id, { width: Math.min(grid.width, 150), height: grid.height });
            clusterOf({ id: grid.id, group: nodes.get(network).group, kind: 'grid' });
            graph.setEdge(network, grid.id, { minlen: 1, weight: 1 });
        }

        for (const edge of map.edges) {
            if (nodes.has(edge.from) && nodes.has(edge.to) && !inGrid.has(edge.to)) {
                graph.setEdge(edge.from, edge.to, { minlen: rankSpan(edge, nodes), weight: edge.type === 'tunnel' ? 0 : 1 });
            }
        }
        dagre.layout(graph);

        // The grids at their real width, centered under their network; what stands beside them in
        // the same row moves aside (left to right, then the row back to where it was on average).
        if (grids.size > 0) {
            const rows = new Map();
            for (const id of graph.nodes()) {
                const n = graph.node(id);
                if (!id.startsWith('cluster:') && n.x !== undefined) {
                    const key = Math.round(n.y);
                    rows.set(key, [...(rows.get(key) || []), { id, n }]);
                }
            }
            const gridOf = new Map([...grids.values()].map((grid) => [grid.id, grid]));
            for (const row of rows.values()) {
                if (!row.some((item) => gridOf.has(item.id))) {
                    continue;
                }
                const GAP_X = 28;
                const items = row.map((item) => {
                    const grid = gridOf.get(item.id);
                    const want = grid ? graph.node([...grids].find(([, g]) => g === grid)[0]).x : item.n.x;
                    return { ...item, grid: !!grid, want, x: want, width: grid ? grid.width : item.n.width };
                }).sort((a, b) => a.want - b.want);
                // The grids stay under their network (apart from each other when they would overlap).
                const fixed = items.filter((item) => item.grid);
                for (let k = 1; k < fixed.length; k++) {
                    fixed[k].x = Math.max(fixed[k].x, fixed[k - 1].x + (fixed[k - 1].width + fixed[k].width) / 2 + GAP_X);
                }
                // Each of the others goes to the side of the nearest grid it was on, outward from it.
                const sides = new Map(fixed.map((item) => [item, { left: [], right: [] }]));
                for (const item of items.filter((candidate) => !candidate.grid)) {
                    const nearest = fixed.reduce((best, candidate) => (Math.abs(candidate.want - item.want) < Math.abs(best.want - item.want) ? candidate : best));
                    sides.get(nearest)[item.want < nearest.want ? 'left' : 'right'].push(item);
                }
                for (const [grid, { left, right }] of sides) {
                    let edge = grid.x - grid.width / 2 - GAP_X;
                    for (const item of left.sort((a, b) => b.want - a.want)) {
                        item.x = Math.min(item.want, edge - item.width / 2);
                        edge = item.x - item.width / 2 - GAP_X;
                    }
                    edge = grid.x + grid.width / 2 + GAP_X;
                    for (const item of right.sort((a, b) => a.want - b.want)) {
                        item.x = Math.max(item.want, edge + item.width / 2);
                        edge = item.x + item.width / 2 + GAP_X;
                    }
                }
                for (const item of items) {
                    item.n.x = item.x;
                    item.n.width = item.width;
                }
            }
        }
        // The view as big as what is drawn.
        let minX = Infinity;
        let maxX = -Infinity;
        for (const id of graph.nodes()) {
            const n = graph.node(id);
            if (!id.startsWith('cluster:') && n.x !== undefined) {
                minX = Math.min(minX, n.x - n.width / 2);
                maxX = Math.max(maxX, n.x + n.width / 2);
            }
        }
        const margin = 24;
        for (const id of graph.nodes()) {
            const n = graph.node(id);
            if (n.x !== undefined) {
                n.x += margin - minX;
            }
        }
        size = { width: maxX - minX + margin * 2, height: graph.graph().height };
        stage.style.width = `${size.width}px`;
        stage.style.height = `${size.height}px`;
        svg.setAttribute('width', size.width);
        svg.setAttribute('height', size.height);

        const box = new Map();
        for (const [id, el] of elements) {
            if (inGrid.has(id)) {
                continue;
            }
            const { x, y, width, height } = graph.node(id);
            if (x === undefined) {
                continue;
            }
            el.style.left = `${x - width / 2}px`;
            el.style.top = `${y - height / 2}px`;
            box.set(id, { x, top: y - height / 2, bottom: y + height / 2 });
        }
        // The grids: a frame behind, the devices in rows and columns of their own width and height.
        const frames = [];
        for (const [network, grid] of grids) {
            const { x, y } = graph.node(grid.id);
            const left = x - grid.width / 2;
            const top = y - grid.height / 2;
            const kind = nodes.get(network).network || 'lan';
            frames.push(`<div class="nm-group is-${esc(kind)}" style="left:${left}px;top:${top}px;width:${grid.width}px;height:${grid.height}px"></div>`);
            let rowTop = top + GAP.pad;
            for (let r = 0; r * grid.cols < grid.ids.length; r++) {
                let colLeft = left + GAP.pad;
                for (let c = 0; c < grid.cols && r * grid.cols + c < grid.ids.length; c++) {
                    const el = elements.get(grid.ids[r * grid.cols + c]);
                    el.style.left = `${colLeft}px`;
                    const elTop = rowTop + (grid.rowHeight[r] - el.offsetHeight) / 2;
                    el.style.top = `${elTop}px`;
                    const id = grid.ids[r * grid.cols + c];
                    box.set(id, { x: colLeft + el.offsetWidth / 2, top: elTop, bottom: elTop + el.offsetHeight });
                    colLeft += grid.colWidth[c] + GAP.x;
                }
                rowTop += grid.rowHeight[r] + GAP.y;
            }
            box.set(grid.id, { x, top, bottom: top + grid.height });
        }
        layer.insertAdjacentHTML('afterbegin', frames.join(''));

        // Links from the bottom of the upper node to the top of the lower one; down ones faint.
        const gridLinks = [...sideLinks.values(), ...[...grids].map(([network, grid]) => ({ from: network, to: grid.id, type: ['wifi', 'cellular', 'vpn'].includes(nodes.get(network).network) ? nodes.get(network).network : 'lan', up: nodes.get(network).state === 'up' }))];
        svg.innerHTML = [...map.edges.filter((edge) => !inGrid.has(edge.to)), ...gridLinks].filter((edge) => box.has(edge.from) && box.has(edge.to)).map((edge) => {
            const a = box.get(edge.from);
            const b = box.get(edge.to);
            const mid = (a.bottom + b.top) / 2;
            const title = edge.label ? `<title>${esc(edge.label)}</title>` : '';
            return `<path class="nm-link is-${esc(edge.type)}${edge.up ? '' : ' is-down'}" d="M${a.x} ${a.bottom} C ${a.x} ${mid}, ${b.x} ${mid}, ${b.x} ${b.top}">${title}</path>`;
        }).join('');

        if (!fitted) {
            fit();
            fitted = true;
        }
    };

    // Dragging pans, two fingers pinch; a click on a node opens it (not after a drag).
    const pointers = new Map();
    let moved = false;
    let start = null;
    let pinch = null;
    viewport.addEventListener('pointerdown', (event) => {
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pointers.size === 1) {
            moved = false;
            start = { x: event.clientX, y: event.clientY };
        }
        if (pointers.size === 2) {
            const [p, q] = [...pointers.values()];
            pinch = { distance: Math.hypot(p.x - q.x, p.y - q.y) };
        }
    });
    viewport.addEventListener('pointermove', (event) => {
        const last = pointers.get(event.pointerId);
        if (!last) {
            return;
        }
        const current = { x: event.clientX, y: event.clientY };
        pointers.set(event.pointerId, current);
        if (pointers.size === 2 && pinch) {
            const [p, q] = [...pointers.values()];
            const distance = Math.hypot(p.x - q.x, p.y - q.y);
            const rect = viewport.getBoundingClientRect();
            zoomAt(distance / pinch.distance, (p.x + q.x) / 2 - rect.left, (p.y + q.y) / 2 - rect.top);
            pinch.distance = distance;
            moved = true;
            return;
        }
        // A few pixels are still a click.
        if (!moved && Math.hypot(current.x - start.x, current.y - start.y) < 4) {
            return;
        }
        moved = true;
        view.x += current.x - last.x;
        view.y += current.y - last.y;
        apply();
    });
    const release = (event) => {
        pointers.delete(event.pointerId);
        if (pointers.size < 2) {
            pinch = null;
        }
    };
    viewport.addEventListener('pointerup', release);
    viewport.addEventListener('pointercancel', release);
    viewport.addEventListener('click', (event) => {
        if (moved) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
    viewport.addEventListener('wheel', (event) => {
        event.preventDefault();
        const rect = viewport.getBoundingClientRect();
        zoomAt(event.deltaY < 0 ? 1.12 : 1 / 1.12, event.clientX - rect.left, event.clientY - rect.top);
    }, { passive: false });
    root.querySelector('.nm-tools').addEventListener('click', (event) => {
        const action = event.target.closest('[data-zoom]')?.dataset.zoom;
        const rect = viewport.getBoundingClientRect();
        if (action === 'in') zoomAt(1.25, rect.width / 2, rect.height / 2);
        if (action === 'out') zoomAt(0.8, rect.width / 2, rect.height / 2);
        if (action === 'fit') fit(true);
    });

    render(data);

    return { update: render, fit };
}
