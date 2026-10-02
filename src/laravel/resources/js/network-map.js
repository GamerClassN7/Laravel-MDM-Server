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
        // Each site in a cluster: its gateway, networks and devices stay together (VPNs between them).
        const graph = new dagre.graphlib.Graph({ compound: true });
        graph.setGraph({ rankdir: 'TB', nodesep: 28, ranksep: 64, edgesep: 12, marginx: 24, marginy: 24 });
        graph.setDefaultEdgeLabel(() => ({}));
        for (const [id, el] of elements) {
            graph.setNode(id, { width: el.offsetWidth, height: el.offsetHeight });
        }
        for (const node of map.nodes) {
            if (node.group && node.kind !== 'internet') {
                const cluster = `cluster:${node.group}`;
                if (!graph.hasNode(cluster)) {
                    graph.setNode(cluster, {});
                }
                graph.setParent(node.id, cluster);
            }
        }
        // Devices in one network only go in up to three rows under it (a long row would be too
        // wide to read); devices of several networks stay in the first row, between them.
        const links = new Map();
        for (const edge of map.edges) {
            if (nodes.get(edge.from)?.kind === 'network') {
                links.set(edge.to, (links.get(edge.to) || 0) + 1);
            }
        }
        const rowOf = new Map();
        const perNetwork = new Map();
        for (const edge of map.edges) {
            if (nodes.get(edge.from)?.kind === 'network' && links.get(edge.to) === 1) {
                const index = perNetwork.get(edge.from) || 0;
                perNetwork.set(edge.from, index + 1);
                rowOf.set(edge.id, index);
            }
        }
        for (const edge of map.edges) {
            if (nodes.has(edge.from) && nodes.has(edge.to)) {
                const count = perNetwork.get(edge.from) || 0;
                const row = rowOf.has(edge.id) && count > 4 ? rowOf.get(edge.id) % 3 : 0;
                graph.setEdge(edge.from, edge.to, { minlen: rankSpan(edge, nodes) + row, weight: edge.type === 'tunnel' ? 0 : 1 });
            }
        }
        dagre.layout(graph);
        size = { width: graph.graph().width, height: graph.graph().height };
        stage.style.width = `${size.width}px`;
        stage.style.height = `${size.height}px`;
        svg.setAttribute('width', size.width);
        svg.setAttribute('height', size.height);

        const box = new Map();
        for (const [id, el] of elements) {
            const { x, y, width, height } = graph.node(id);
            if (x === undefined) {
                continue;
            }
            el.style.left = `${x - width / 2}px`;
            el.style.top = `${y - height / 2}px`;
            box.set(id, { x, top: y - height / 2, bottom: y + height / 2 });
        }

        // Links from the bottom of the upper node to the top of the lower one; down ones faint.
        svg.innerHTML = map.edges.filter((edge) => box.has(edge.from) && box.has(edge.to)).map((edge) => {
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
