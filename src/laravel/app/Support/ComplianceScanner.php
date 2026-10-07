<?php

namespace App\Support;

use App\Models\ComplianceResult;
use App\Models\CompliancePolicy;
use App\Models\Device;
use App\Models\SecurityInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Evaluates the enabled compliance policies (App\Support\CompliancePolicies) on a device's security
 * inventory and keeps one result per check: written when its status, message or severity changed,
 * removed with the policy or when the policy no longer applies to the device. Runs with every scan
 * of the inventory (SecurityScanner::scan), skipped when neither the policies nor the inventory
 * changed since the last evaluation.
 */
class ComplianceScanner
{
    /**
     * @return bool whether a result changed
     */
    public static function evaluate(Device $device, SecurityInventory $inventory, bool $force = false): bool
    {
        $token = substr(md5(CompliancePolicy::versionToken().'|'.json_encode($inventory->states ?? [])), 0, 16);
        $scanned = $inventory->scanned ?? [];
        if (! $force && ($scanned['compliance'] ?? null) === $token) {
            return false;
        }

        $changed = false;
        try {
            $changed = self::write($device, $inventory);
        } catch (Throwable $e) {
            Log::warning("Compliance evaluation failed on device {$device->id}: {$e->getMessage()}");

            return false;
        }
        $inventory->forceFill(['scanned' => ['compliance' => $token] + ($inventory->scanned ?? [])])->saveQuietly();

        return $changed;
    }

    /** The policies on every device with an inventory, after policies changed. */
    public static function evaluateAll(): void
    {
        foreach (SecurityInventory::query()->with('device')->get() as $inventory) {
            if ($inventory->device !== null && self::evaluate($inventory->device, $inventory, true)) {
                LiveUpdates::device($inventory->device_id, 'security');
            }
        }
    }

    /**
     * What every check of a policy gives on a device's last inventory, for the editor; null without
     * an inventory.
     *
     * @return array<string, array{status: string, severity: string, message: string, details: array}>|null
     */
    public static function preview(array $definition, Device $device): ?array
    {
        $inventory = SecurityInventory::query()->where('device_id', $device->id)->first();
        if ($inventory === null) {
            return null;
        }
        if (! CompliancePolicies::appliesToPlatform($definition, $device->platform)) {
            return [];
        }

        return CompliancePolicies::evaluate($definition, fn (string $source) => $inventory->items($source));
    }

    private static function write(Device $device, SecurityInventory $inventory): bool
    {
        $policies = CompliancePolicy::query()->enabled()->get()
            ->filter(fn (CompliancePolicy $policy) => CompliancePolicies::appliesToPlatform($policy->definition, $device->platform));
        $existing = ComplianceResult::query()->where('device_id', $device->id)->get()
            ->keyBy(fn (ComplianceResult $result) => $result->compliance_policy_id.'|'.$result->check_id);
        $keep = [];
        $changed = false;
        $now = now();

        DB::transaction(function () use ($device, $inventory, $policies, $existing, $now, &$keep, &$changed) {
            foreach ($policies as $policy) {
                foreach (CompliancePolicies::evaluate($policy->definition, fn (string $source) => $inventory->items($source)) as $checkId => $result) {
                    $key = $policy->id.'|'.$checkId;
                    $keep[$key] = true;
                    $result['message'] = mb_strimwidth($result['message'], 0, 1000, '…');
                    $row = $existing->get($key);
                    if ($row === null) {
                        ComplianceResult::query()->create($result + [
                            'device_id' => $device->id, 'compliance_policy_id' => $policy->id, 'check_id' => $checkId,
                            'changed_at' => $now, 'evaluated_at' => $now,
                        ]);
                        $changed = true;

                        continue;
                    }
                    $statusChanged = $row->status !== $result['status'];
                    if ($statusChanged || $row->message !== $result['message'] || $row->severity !== $result['severity'] || $row->details !== $result['details']) {
                        $row->update($result + ['evaluated_at' => $now] + ($statusChanged ? ['changed_at' => $now] : []));
                        $changed = true;
                    }
                }
            }
            // Checks and policies that are gone, switched off or not for this device anymore.
            $gone = $existing->reject(fn ($row, $key) => isset($keep[$key]));
            if ($gone->isNotEmpty()) {
                ComplianceResult::query()->whereKey($gone->modelKeys())->delete();
                $changed = true;
            }
        });

        return $changed;
    }

    /**
     * How many results of each status a policy has, per device or over all devices.
     *
     * @return array<string, int>
     */
    public static function counts(iterable $results): array
    {
        $counts = array_fill_keys(array_keys(CompliancePolicies::STATUSES), 0);
        foreach ($results as $result) {
            $status = is_array($result) ? $result['status'] : $result->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }
}
