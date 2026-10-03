<?php

use App\Models\Device;
use App\Support\NetworkMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Neighbours were kept per public address of the agent that saw them, which differs between
     * devices of one network (one reaching the server over the internet, another from inside):
     * they now go by the network's gateway MAC. The known ones move there, merged per MAC
     * (first and last seen of both, ignored when either was).
     */
    public function up(): void
    {
        $devices = Device::query()->where('kind', 'agent')->get()->keyBy('id');
        $rows = DB::table('network_neighbours')->orderBy('id')->get();
        $kept = [];
        foreach ($rows as $row) {
            $segment = null;
            foreach (($devices[$row->seen_by] ?? null)?->networks ?? [] as $interface) {
                foreach ($interface['Addresses'] as $address) {
                    if (NetworkMap::cidr($address['Address'], $address['PrefixLength']) === $row->network && str_starts_with($candidate = NetworkMap::segmentOf($interface, $devices[$row->seen_by], null), 'gw:')) {
                        $segment = $candidate;
                    }
                }
            }
            if ($segment === null) {
                continue;
            }
            $other = $kept[$segment.'|'.$row->mac] ?? DB::table('network_neighbours')->where('site', $segment)->where('mac', $row->mac)->first();
            if ($other === null) {
                DB::table('network_neighbours')->where('id', $row->id)->update(['site' => $segment]);
                $kept[$segment.'|'.$row->mac] = DB::table('network_neighbours')->find($row->id);

                continue;
            }
            $newer = $row->last_seen_at > $other->last_seen_at ? $row : $other;
            DB::table('network_neighbours')->where('id', $other->id)->update([
                'ip' => $newer->ip,
                'network' => $newer->network,
                'hostname' => $newer->hostname ?? $other->hostname ?? $row->hostname,
                'seen_by' => $newer->seen_by,
                'first_seen_at' => min($row->first_seen_at, $other->first_seen_at),
                'last_seen_at' => max($row->last_seen_at, $other->last_seen_at),
                'ignored_at' => $other->ignored_at ?? $row->ignored_at,
            ]);
            DB::table('network_neighbours')->where('id', $row->id)->delete();
            $kept[$segment.'|'.$row->mac] = DB::table('network_neighbours')->find($other->id);
        }
    }

    public function down(): void
    {
        // The rows stay keyed by gateway; they are taken again per public address with the next reports.
    }
};
