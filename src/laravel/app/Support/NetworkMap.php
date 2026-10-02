<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The map of the networks (the Networks page), built from what the agents report: top down the
 * internet, the public addresses (sites), their gateways, the networks (IPv4 subnets; VPNs span
 * sites) and the devices, each linked to every network it has an interface in. This server is
 * placed where its name resolves to.
 *
 * Nodes: id, kind (internet, site, gateway, network, device, server), label, sub, icon, tone,
 * state (up, down, offline, warning), url, badges. Edges: from, to, type (wan, uplink, lan, wifi,
 * cellular, vpn, tunnel), up, label.
 */
class NetworkMap
{
    /** Interfaces that stay inside the machine (containers, VMs) or are no network. */
    public const SKIPPED_TYPES = ['docker', 'virtual', 'bluetooth'];

    /** Seconds the addresses of this server's name are kept. */
    private const RESOLVE_TTL = 3600;

    private array $nodes = [];

    private array $edges = [];

    /** network key => [cidr, site, kind types, members [device id => interface]] */
    private array $networks = [];

    /** @param  Collection<int, Device>|null  $devices */
    public static function build(?Collection $devices = null): array
    {
        return (new self)->make($devices ?? Device::query()->orderBy('id')->get());
    }

    /** The public IPv4 addresses this server's name (APP_URL, else the remembered address) resolves to. */
    public static function serverAddresses(): array
    {
        // Set by hand (MDM_PUBLIC_ADDRESS): when the name resolves to something else (split DNS, a proxy).
        $set = (string) config('mdm.public_address');
        if ($set !== '' && Device::isPublicIp($set)) {
            return [$set];
        }
        $url = PortalUrl::configured() ? (string) config('app.url') : PortalUrl::remembered();
        $host = $url ? (string) parse_url($url, PHP_URL_HOST) : '';
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return Device::isPublicIp($host) ? [$host] : [];
        }

        return Cache::remember('network-map:resolve:'.$host, self::RESOLVE_TTL, function () use ($host) {
            try {
                $addresses = @gethostbynamel($host) ?: [];
            } catch (Throwable) {
                $addresses = [];
            }

            return array_values(array_filter($addresses, fn ($address) => Device::isPublicIp($address)));
        });
    }

    private function make(Collection $devices): array
    {
        $serverIps = self::serverAddresses();
        $serverSite = $serverIps[0] ?? null;
        $agents = $devices->reject->isPingOnly;
        $pings = $devices->filter->isPingOnly;

        $this->node('internet', 'internet', __('Internet'), null, 'fas fa-globe', 'secondary');

        // Sites: the public address an agent reaches the server from. One that reaches it over a
        // private address is in the network of this server (it connects from inside).
        $siteOf = [];
        foreach ($agents as $device) {
            $siteOf[$device->id] = match (true) {
                Device::isPublicIp($device->public_ip) => $device->public_ip,
                $device->public_ip !== null && $serverSite !== null => $serverSite,
                default => '?',
            };
        }

        // The networks of the agents' interfaces.
        foreach ($agents as $device) {
            foreach ($device->networks as $interface) {
                if (in_array($interface['Type'], self::SKIPPED_TYPES, true)) {
                    continue;
                }
                foreach ($interface['Addresses'] as $address) {
                    // VPN clients often have a /32 (WireGuard, Tailscale): grouped in their /24.
                    $prefix = $interface['Type'] === 'vpn' && $address['PrefixLength'] >= 31 ? 24 : $address['PrefixLength'];
                    $cidr = self::cidr($address['Address'], $prefix);
                    if ($cidr === null) {
                        continue;
                    }
                    $vpn = $interface['Type'] === 'vpn';
                    $key = $vpn ? 'net:vpn|'.$cidr : 'net:'.$siteOf[$device->id].'|'.$cidr;
                    $this->networks[$key] ??= ['cidr' => $cidr, 'site' => $vpn ? null : $siteOf[$device->id], 'types' => [], 'members' => [], 'gateways' => []];
                    $this->networks[$key]['types'][$interface['Type']] = true;
                    $this->networks[$key]['members'][$device->id] ??= [
                        'type' => $interface['Type'], 'connected' => $interface['Connected'], 'name' => $interface['Name'], 'address' => $address['Address'],
                    ];
                    if ($interface['Gateway'] ?? null) {
                        $this->networks[$key]['gateways'][] = ['ip' => $interface['Gateway'], 'mac' => $interface['GatewayMac'] ?? null];
                    }
                }
            }
        }

        // Ping-only devices: in the network of their address, at the site of the agent that pings them.
        foreach ($pings as $device) {
            if (! $device->ping_address) {
                continue;
            }
            $relaySite = $device->ping_relay_id ? ($siteOf[$device->ping_relay_id] ?? null) : null;
            $key = collect($this->networks)->filter(fn ($network) => $network['site'] !== null && ($relaySite === null || $network['site'] === $relaySite) && self::contains($network['cidr'], $device->ping_address))->keys()->first();
            if ($key === null) {
                $cidr = self::cidr($device->ping_address, (int) ($device->ping_prefix ?? 24));
                $site = $relaySite ?? '?';
                $key = 'net:'.$site.'|'.$cidr;
                $this->networks[$key] ??= ['cidr' => $cidr, 'site' => $site, 'types' => ['lan' => true], 'members' => [], 'gateways' => []];
            }
            $this->networks[$key]['members'][$device->id] = ['type' => 'lan', 'connected' => true, 'name' => 'ping', 'address' => $device->ping_address];
            $siteOf[$device->id] = $this->networks[$key]['site'];
        }

        // Sites, with this server's even without devices.
        $sites = collect($siteOf)->filter()->unique()->values()->all();
        if ($serverSite !== null && ! in_array($serverSite, $sites, true)) {
            $sites[] = $serverSite;
        }
        foreach ($sites as $site) {
            $count = collect($siteOf)->filter(fn ($s) => $s === $site)->count();
            $this->node('site:'.$site, 'site', $site === '?' ? __('Unknown address') : $site,
                $site === $serverSite ? __('public · this server') : __('public'),
                $site === $serverSite ? 'fas fa-home' : 'fas fa-building', $site === '?' ? 'secondary' : 'success',
                badges: [trans_choice(':count device|:count devices', $count)], extra: ['group' => $site]);
            $this->edge('internet', 'site:'.$site, 'wan', true);
        }

        $byId = $devices->keyBy('id');
        $relays = $pings->whereNotNull('ping_relay_id')->countBy('ping_relay_id');

        // Networks and their gateways (one node per gateway MAC, or IP: a router of several networks).
        foreach ($this->networks as $key => $network) {
            $members = collect($network['members']);
            $online = $members->filter(fn ($member, $id) => $member['connected'] && ! $byId[$id]->offline)->count();
            $types = array_keys($network['types']);
            $kind = match (true) {
                in_array('vpn', $types, true) => 'vpn',
                $types === ['wifi'] => 'wifi',
                in_array('wifi', $types, true) && in_array('lan', $types, true) => 'mixed',
                $types === ['cellular'] => 'cellular',
                default => 'lan',
            };
            $label = ['vpn' => __('VPN'), 'wifi' => __('Wi-Fi'), 'mixed' => __('LAN + Wi-Fi'), 'cellular' => __('Mobile'), 'lan' => __('LAN')][$kind];
            $this->node($key, 'network', $network['cidr'],
                $label.' · '.trans_choice(':count device|:count devices', $members->count()),
                ['vpn' => 'fas fa-shield-alt', 'wifi' => 'fas fa-wifi', 'cellular' => 'fas fa-signal'][$kind] ?? 'fas fa-ethernet',
                ['vpn' => 'info', 'wifi' => 'purple'][$kind] ?? 'primary',
                state: $online > 0 ? 'up' : 'down',
                extra: ['network' => $kind, 'online' => $online, 'devices' => $members->count(), 'group' => $network['site']]);
            $this->networks[$key]['kind'] = $kind;
            $this->networks[$key]['online'] = $online;

            if ($network['site'] === null) {
                // A VPN runs over the internet, between the sites.
                $this->edge('internet', $key, 'tunnel', $online > 0);

                continue;
            }
            $gateway = collect($network['gateways'])->groupBy(fn ($gateway) => $gateway['ip'])->sortByDesc(fn ($group) => $group->count())->keys()->first();
            if ($gateway === null) {
                $this->edge('site:'.$network['site'], $key, 'uplink', $online > 0);

                continue;
            }
            $mac = collect($network['gateways'])->where('ip', $gateway)->pluck('mac')->filter()->first();
            $id = 'gw:'.$network['site'].'|'.($mac ?? $gateway);
            $this->networks[$key]['gateway'] = $gateway;
            // A device of the portal with that address is the gateway (a router added as ping-only).
            $known = $byId->first(fn (Device $device) => isset($network['members'][$device->id]) && $network['members'][$device->id]['address'] === $gateway);
            if (isset($this->nodes[$id])) {
                $this->nodes[$id]['sub'] = $this->nodes[$id]['sub'].' · '.$gateway;
            } else {
                $this->node($id, 'gateway', $known?->displayName ?? __('Gateway'), $gateway, 'fas fa-route', 'success',
                    url: $known ? route('devices', ['selectedDeviceId' => $known->id]) : null, badges: [__('gateway')],
                    extra: ['title' => $mac ? __('MAC :mac', ['mac' => $mac]) : null, 'group' => $network['site']]);
                $this->edge('site:'.$network['site'], $id, 'uplink', true);
            }
            $this->edge($id, $key, 'uplink', $online > 0);
        }

        // Devices, linked to each of their networks.
        foreach ($devices as $device) {
            $memberOf = collect($this->networks)->filter(fn ($network) => isset($network['members'][$device->id]));
            if ($memberOf->isEmpty() && ! isset($siteOf[$device->id])) {
                continue;
            }
            $addresses = $memberOf->map(fn ($network) => $network['members'][$device->id]['address'])->unique()->take(3)->implode(' · ');
            // Isolated: no other agent in any of its own networks (VPNs aside): nothing next to it
            // can wake it, nor ping the devices next to it.
            $neighbours = $memberOf->filter(fn ($network) => $network['site'] !== null)
                ->flatMap(fn ($network) => array_keys($network['members']))
                ->filter(fn ($id) => $id !== $device->id && ! $byId[$id]->isPingOnly)->unique();
            $isolated = ! $device->isPingOnly && $neighbours->isEmpty();
            $badges = array_values(array_filter([
                ($relays[$device->id] ?? 0) > 0 ? trans_choice('pings :count|pings :count', $relays[$device->id]) : null,
                $isolated ? __('isolated') : null,
            ]));
            $state = match (true) {
                $device->isPingOnly && $device->offline => 'warning',
                $device->offline => 'offline',
                default => 'up',
            };
            $this->node('device:'.$device->id, 'device', $device->displayName,
                $addresses ?: ($device->offline ? __('offline') : null), $device->typeIcon,
                $device->isPingOnly ? 'success' : 'primary', state: $state,
                url: route('devices', ['selectedDeviceId' => $device->id]), badges: $badges,
                extra: ['isolated' => $isolated, 'ping' => $device->isPingOnly,
                    // Laid out with the site of its own network (not of a VPN).
                    'group' => $memberOf->pluck('site')->filter()->first() ?? ($siteOf[$device->id] ?? null)]);
            foreach ($memberOf as $key => $network) {
                $member = $network['members'][$device->id];
                $type = match ($member['type']) {
                    'wifi' => 'wifi', 'vpn' => 'vpn', 'cellular' => 'cellular', default => 'lan',
                };
                $this->edge($key, 'device:'.$device->id, $type, $member['connected'] && ! $device->offline, $member['address']);
            }
            if ($memberOf->isEmpty()) {
                $this->edge('site:'.$siteOf[$device->id], 'device:'.$device->id, 'uplink', ! $device->offline);
            }
        }

        // This server: in the network the agents reach it from over private addresses, else at its site.
        if ($serverSite !== null) {
            $local = $agents->filter(fn (Device $device) => $device->public_ip !== null && ! Device::isPublicIp($device->public_ip))->pluck('public_ip')->all();
            $network = collect($this->networks)->filter(fn ($network) => $network['site'] === $serverSite)
                ->sortByDesc(fn ($network) => collect($local)->filter(fn ($ip) => self::contains($network['cidr'], $ip))->count())
                ->filter(fn ($network) => collect($local)->contains(fn ($ip) => self::contains($network['cidr'], $ip)))->keys()->first();
            $host = (string) parse_url(url('/'), PHP_URL_HOST);
            $this->node('server', 'server', 'Laravel-MDM', in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) ? $serverSite : $host, 'fas fa-shield-virus', 'success', badges: [__('this server')], extra: ['group' => $serverSite]);
            $this->edge($network ?? 'site:'.$serverSite, 'server', $network ? 'lan' : 'uplink', true);
        }

        return [
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
            'networks' => $this->summary($byId),
        ];
    }

    /** The networks for the list under the diagram: busiest first, VPNs last. */
    private function summary(Collection $byId): array
    {
        return collect($this->networks)->map(fn ($network, $key) => [
            'id' => $key,
            'cidr' => $network['cidr'],
            'site' => $network['site'],
            'kind' => $network['kind'],
            'gateway' => $network['gateway'] ?? null,
            'online' => $network['online'],
            'devices' => collect($network['members'])->keys()->map(fn ($id) => ['id' => $id, 'name' => $byId[$id]->displayName, 'online' => ! $byId[$id]->offline, 'ping' => $byId[$id]->isPingOnly])->values()->all(),
            'relays' => collect($network['members'])->keys()->filter(fn ($id) => ! $byId[$id]->isPingOnly && ! $byId[$id]->offline && $byId[$id]->signsRequests)->map(fn ($id) => $byId[$id]->displayName)->values()->all(),
        ])->sortBy(fn ($network) => [$network['kind'] === 'vpn' ? 1 : 0, -count($network['devices'])])->values()->all();
    }

    private function node(string $id, string $kind, string $label, ?string $sub, string $icon, string $tone, string $state = 'up', ?string $url = null, array $badges = [], array $extra = []): void
    {
        $this->nodes[$id] = compact('id', 'kind', 'label', 'sub', 'icon', 'tone', 'state', 'url', 'badges') + $extra;
    }

    private function edge(string $from, string $to, string $type, bool $up, ?string $label = null): void
    {
        $this->edges[$from.'>'.$to] = ['id' => $from.'>'.$to, 'from' => $from, 'to' => $to, 'type' => $type, 'up' => $up, 'label' => $label];
    }

    /** "192.168.1.0/24" for 192.168.1.42/24; null for loopback, link-local and odd prefixes. */
    public static function cidr(string $address, int $prefix): ?string
    {
        $ip = ip2long($address);
        if ($ip === false || $prefix < 8 || $prefix > 32 || str_starts_with($address, '127.') || str_starts_with($address, '169.254.')) {
            return null;
        }
        $mask = $prefix === 32 ? 0xFFFFFFFF : ((-1 << (32 - $prefix)) & 0xFFFFFFFF);

        return long2ip($ip & $mask).'/'.$prefix;
    }

    public static function contains(string $cidr, string $address): bool
    {
        [$network, $prefix] = explode('/', $cidr);
        $ip = ip2long($address);
        $mask = (int) $prefix === 32 ? 0xFFFFFFFF : ((-1 << (32 - (int) $prefix)) & 0xFFFFFFFF);

        return $ip !== false && ($ip & $mask) === (ip2long($network) & $mask);
    }
}
