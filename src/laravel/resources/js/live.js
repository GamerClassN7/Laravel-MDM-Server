// Live updates of the portal over Reverb: the server announces that a device changed
// (App\Events\DevicesChanged on the private "devices" channel) and the Livewire components showing
// it reload themselves. The pages never poll.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const config = document.querySelector('meta[name="mdm-reverb-key"]');

if (config && config.content) {
    window.Pusher = Pusher;
    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
    // Reverb is served by the same host and port as the portal (nginx proxies /app to it), unless
    // REVERB_HOST names its own public address.
    const host = meta('mdm-reverb-host') || window.location.hostname;
    const secure = meta('mdm-reverb-host') ? meta('mdm-reverb-scheme') === 'https' : window.location.protocol === 'https:';
    const port = Number(meta('mdm-reverb-host') ? meta('mdm-reverb-port') : window.location.port) || (secure ? 443 : 80);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: config.content,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content } },
    });

    // The components of one device reload at once (device-changed.<id>); the lists and widgets of
    // all devices at most every few seconds (devices-changed), many devices send heartbeats.
    const pending = new Map();
    let listTimer = null;
    const announce = (deviceId, what) => {
        if (!window.Livewire) {
            return;
        }
        clearTimeout(pending.get(deviceId));
        pending.set(deviceId, setTimeout(() => {
            pending.delete(deviceId);
            window.Livewire.dispatch(`device-changed.${deviceId}`, { what });
        }, 300));
        if (!listTimer) {
            listTimer = setTimeout(() => {
                listTimer = null;
                window.Livewire.dispatch('devices-changed');
                // For Blade widgets of the dashboard (x-on:mdm-devices-changed.window).
                window.dispatchEvent(new CustomEvent('mdm-devices-changed'));
            }, 5000);
        }
    };

    window.Echo.private('devices').listen('.changed', (event) => announce(event.device_id, event.what));

    // After a lost connection everything may be stale: reload all of it once.
    let wasConnected = false;
    window.Echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            if (wasConnected && window.Livewire) {
                window.Livewire.dispatch('devices-changed');
                window.Livewire.dispatch('live-reconnected');
            }
            wasConnected = true;
        }
    });
}
