import './boilerplate/boilerplate.js';
import './code-viewer';
import './live';

// The network map (Networks page), loaded only there.
window.mdmNetworkMap = async (root, data) => (await import('./network-map')).createNetworkMap(root, data);
