<?php

namespace App\Support;

/**
 * The language of compliance policies: a policy (a benchmark such as the CIS Microsoft SQL Server
 * Benchmark, or an internal baseline) is a list of checks, each of them looks at the items of a
 * source of the security inventory (App\Support\SecurityRules::SOURCES) and gives every device a
 * status: pass, fail, warn, manual (cannot be told from what the agent reported) or na (does not
 * apply). The conditions are the ones of the detection rules.
 *
 *   {
 *     "key": "cis-mssql-2022",
 *     "name": "CIS Microsoft SQL Server 2019 / 2022 Benchmark",
 *     "platform": "windows",
 *     "source": "sqlserver",
 *     "manual": {"field": "Error", "op": "exists"},
 *     "checks": [{
 *       "id": "Sql.XpCmdshellDisabled",
 *       "name": "xp_cmdshell is disabled",
 *       "reference": "CIS 2.15",
 *       "severity": "critical",
 *       "fail": {"field": "XpCmdshell", "op": "ne", "value": 0},
 *       "message": "{Instance}: xp_cmdshell is {XpCmdshell}",
 *       "remediation": "EXEC sp_configure 'xp_cmdshell', 0; RECONFIGURE;"
 *     }]
 *   }
 *
 * Each item of the source is judged on its own: not "applies" → na, "manual" → manual, a field
 * the check needs ("requires", by default every field of "fail" and "warn") not reported →
 * manual, "fail" → fail, "warn" → warn, otherwise pass. The check takes the worst status of the
 * items (fail, warn, manual, pass, na); without items it does not apply. "applies" and "manual"
 * of the policy hold for all its checks.
 */
class CompliancePolicies
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const WARN = 'warn';

    public const MANUAL = 'manual';

    public const NOT_APPLICABLE = 'na';

    /** The statuses, worst first (the status of a check is the worst one of its items). */
    public const STATUSES = [self::FAIL => 0, self::WARN => 1, self::MANUAL => 2, self::PASS => 3, self::NOT_APPLICABLE => 4];

    public const STATUS_LABELS = [self::FAIL => 'Fail', self::WARN => 'Warn', self::MANUAL => 'Manual', self::PASS => 'Pass', self::NOT_APPLICABLE => 'Not applicable'];

    /** Bootstrap colors of the statuses. */
    public const STATUS_COLORS = [self::FAIL => 'danger', self::WARN => 'warning', self::MANUAL => 'info', self::PASS => 'success', self::NOT_APPLICABLE => 'secondary'];

    public const MAX_CHECKS = 300;

    /** Items named in the message of a check at most. */
    private const MAX_NAMED = 3;

    /**
     * What is wrong with a policy, empty when it can be used.
     *
     * @return array<int, string>
     */
    public static function errors(mixed $policy): array
    {
        if (! is_array($policy) || array_is_list($policy)) {
            return [__('A policy is a JSON object.')];
        }
        $errors = [];
        if (! is_string($policy['key'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $policy['key'])) {
            $errors[] = __('key: 2 to 64 lower-case letters, digits, dots, dashes or underscores.');
        }
        if (! is_string($policy['name'] ?? null) || trim($policy['name']) === '' || mb_strlen($policy['name']) > 120) {
            $errors[] = __('name: up to 120 characters.');
        }
        foreach (['description', 'version'] as $field) {
            if (isset($policy[$field]) && (! is_string($policy[$field]) || mb_strlen($policy[$field]) > ($field === 'version' ? 32 : 1000))) {
                $errors[] = __(':field: text of up to :max characters.', ['field' => $field, 'max' => $field === 'version' ? 32 : 1000]);
            }
        }
        if (isset($policy['platform']) && ! in_array($policy['platform'], SecurityRules::PLATFORMS, true)) {
            $errors[] = __('platform: one of :values.', ['values' => implode(', ', SecurityRules::PLATFORMS)]);
        }
        if (isset($policy['enabled']) && ! is_bool($policy['enabled'])) {
            $errors[] = __('enabled: true or false.');
        }
        if (isset($policy['source']) && ! self::inventorySource($policy['source'])) {
            $errors[] = __('source: one of :values.', ['values' => implode(', ', self::sources())]);
        }
        foreach (['applies', 'manual'] as $field) {
            if (array_key_exists($field, $policy)) {
                $errors = [...$errors, ...self::conditionErrors($policy[$field], $field)];
            }
        }

        $checks = $policy['checks'] ?? null;
        if (! is_array($checks) || ! array_is_list($checks) || $checks === []) {
            $errors[] = __('checks: a list of at least one check.');

            return $errors;
        }
        if (count($checks) > self::MAX_CHECKS) {
            $errors[] = __('checks: at most :count.', ['count' => self::MAX_CHECKS]);

            return $errors;
        }
        $ids = [];
        foreach ($checks as $index => $check) {
            $path = "checks.$index";
            if (! is_array($check) || array_is_list($check)) {
                $errors[] = __(':path: a check is an object.', ['path' => $path]);

                continue;
            }
            $id = $check['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $id)) {
                $errors[] = __(':path.id: 2 to 64 letters, digits, dots, dashes or underscores.', ['path' => $path]);
            } elseif (isset($ids[strtolower($id)])) {
                $errors[] = __(':path.id: :id is there twice.', ['path' => $path, 'id' => $id]);
            } else {
                $ids[strtolower($id)] = true;
                $path = $id;
            }
            if (! is_string($check['name'] ?? null) || trim($check['name']) === '' || mb_strlen($check['name']) > 200) {
                $errors[] = __(':path.name: up to 200 characters.', ['path' => $path]);
            }
            if (! isset(SecurityRules::SEVERITIES[$check['severity'] ?? null])) {
                $errors[] = __(':path.severity: one of :values.', ['path' => $path, 'values' => implode(', ', array_keys(SecurityRules::SEVERITIES))]);
            }
            $source = $check['source'] ?? $policy['source'] ?? null;
            if (! self::inventorySource($source)) {
                $errors[] = __(':path.source: one of :values (or the source of the policy).', ['path' => $path, 'values' => implode(', ', self::sources())]);
            }
            if (isset($check['reference']) && (! is_string($check['reference']) || mb_strlen($check['reference']) > 120)) {
                $errors[] = __(':path.reference: text of up to 120 characters.', ['path' => $path]);
            }
            foreach (['description', 'message', 'remediation'] as $field) {
                if (isset($check[$field]) && (! is_string($check[$field]) || mb_strlen($check[$field]) > 1000)) {
                    $errors[] = __(':path.:field: text of up to 1000 characters.', ['path' => $path, 'field' => $field]);
                }
            }
            if (! array_key_exists('fail', $check) && ! array_key_exists('warn', $check)) {
                $errors[] = __(':path: "fail" or "warn" is needed.', ['path' => $path]);
            }
            foreach (['applies', 'manual', 'fail', 'warn'] as $field) {
                if (array_key_exists($field, $check)) {
                    $errors = [...$errors, ...self::conditionErrors($check[$field], "$path.$field")];
                }
            }
            if (array_key_exists('requires', $check)) {
                $requires = $check['requires'];
                if (! is_array($requires) || ! array_is_list($requires) || count(array_filter($requires, fn ($field) => ! is_string($field) || ! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $field))) > 0) {
                    $errors[] = __(':path.requires: a list of field names.', ['path' => $path]);
                }
            }
        }

        return $errors;
    }

    /** The errors of one condition, as the detection rules check it (prefixed with where it is). */
    private static function conditionErrors(mixed $condition, string $path): array
    {
        $rule = ['key' => 'xx', 'name' => 'x', 'severity' => 'info', 'source' => 'posture', 'when' => $condition];

        return array_map(fn ($error) => preg_replace('/^when\b/', $path, $error), SecurityRules::errors($rule));
    }

    /** The sources of the inventory a check can look at (not the events: they come and go). */
    public static function sources(): array
    {
        return array_keys(array_filter(SecurityRules::SOURCES, fn ($source) => ! ($source['event'] ?? false)));
    }

    private static function inventorySource(mixed $source): bool
    {
        return is_string($source) && in_array($source, self::sources(), true);
    }

    /** The source a check looks at. */
    public static function sourceOf(array $policy, array $check): string
    {
        return $check['source'] ?? $policy['source'];
    }

    /** Whether the policy is for devices of this platform. */
    public static function appliesToPlatform(array $policy, ?string $platform): bool
    {
        $wanted = $policy['platform'] ?? 'any';

        return $wanted === 'any' || $wanted === $platform;
    }

    /**
     * The status of every check of a (valid) policy on a device's inventory.
     *
     * @param  callable(string): array<int, array>  $items  the items of a source
     * @return array<string, array{status: string, severity: string, message: string, details: array}> by check id
     */
    public static function evaluate(array $policy, callable $items): array
    {
        $results = [];
        foreach ($policy['checks'] as $check) {
            $results[$check['id']] = self::evaluateCheck($policy, $check, $items(self::sourceOf($policy, $check)));
        }

        return $results;
    }

    /**
     * One check on the items of its source.
     *
     * @param  array<int, array>  $items
     * @return array{status: string, severity: string, message: string, details: array}
     */
    public static function evaluateCheck(array $policy, array $check, array $items): array
    {
        $items = array_values(array_filter($items, fn ($item) => is_array($item) && $item !== []));
        $status = self::NOT_APPLICABLE;
        $byStatus = [];
        foreach ($items as $item) {
            $itemStatus = self::itemStatus($policy, $check, $item);
            $byStatus[$itemStatus][] = $item;
            if (self::STATUSES[$itemStatus] < self::STATUSES[$status]) {
                $status = $itemStatus;
            }
        }

        $worst = $byStatus[$status] ?? [];
        $source = self::sourceOf($policy, $check);
        $names = array_values(array_filter(array_map(fn ($item) => self::itemName($source, $item), $worst)));
        $details = ['items' => count($items)];
        foreach ($byStatus as $itemStatus => $list) {
            $details[$itemStatus] = count($list);
        }
        if ($names !== []) {
            $details['names'] = array_slice($names, 0, 20);
        }

        return [
            'status' => $status,
            'severity' => $check['severity'],
            'message' => self::message($check, $source, $status, $worst, $items === []),
            'details' => $details,
        ];
    }

    /** The status of one item. */
    public static function itemStatus(array $policy, array $check, array $item): string
    {
        foreach ([$policy['applies'] ?? null, $check['applies'] ?? null] as $applies) {
            if ($applies !== null && ! SecurityRules::test($applies, $item)) {
                return self::NOT_APPLICABLE;
            }
        }
        foreach ([$policy['manual'] ?? null, $check['manual'] ?? null] as $manual) {
            if ($manual !== null && SecurityRules::test($manual, $item)) {
                return self::MANUAL;
            }
        }
        foreach (self::requiredFields($check) as $field) {
            $value = SecurityRules::field($item, $field);
            if ($value === null || $value === '') {
                return self::MANUAL;
            }
        }
        if (isset($check['fail']) && SecurityRules::test($check['fail'], $item)) {
            return self::FAIL;
        }
        if (isset($check['warn']) && SecurityRules::test($check['warn'], $item)) {
            return self::WARN;
        }

        return self::PASS;
    }

    /**
     * The fields a check cannot be told without: "requires", or every field of its "fail" and "warn".
     *
     * @return array<int, string>
     */
    public static function requiredFields(array $check): array
    {
        if (array_key_exists('requires', $check)) {
            return $check['requires'];
        }
        $fields = [];
        foreach (['fail', 'warn'] as $part) {
            if (isset($check[$part])) {
                self::collectFields($check[$part], $fields);
            }
        }

        return array_values(array_unique($fields));
    }

    private static function collectFields(array $condition, array &$fields): void
    {
        foreach (['all', 'any'] as $group) {
            if (isset($condition[$group])) {
                foreach ($condition[$group] as $child) {
                    self::collectFields($child, $fields);
                }

                return;
            }
        }
        if (array_key_exists('not', $condition)) {
            self::collectFields($condition['not'], $fields);

            return;
        }
        // "exists" and "empty" are about the field being there: they are answers, not unknowns.
        if (! in_array($condition['op'] ?? null, ['exists', 'empty'], true)) {
            $fields[] = $condition['field'];
        }
    }

    /** What the result says: the message of the check on the worst items, or a word on the status. */
    private static function message(array $check, string $source, string $status, array $worst, bool $noItems): string
    {
        if ($noItems) {
            return __('Nothing to check: the agent reports no :source.', ['source' => mb_strtolower(__(SecurityRules::SOURCES[$source]['label']))]);
        }
        if ($status === self::NOT_APPLICABLE) {
            return __('Does not apply.');
        }
        if ($status === self::MANUAL) {
            $errors = array_values(array_filter(array_map(fn ($item) => is_scalar($item['Error'] ?? null) ? (string) $item['Error'] : null, $worst)));

            return $errors !== []
                ? __('Check by hand: :error', ['error' => mb_strimwidth($errors[0], 0, 300, '…')])
                : __('Check by hand: the agent could not read what this check needs.');
        }
        if ($status === self::PASS) {
            return __('Passed.');
        }
        $template = $check['message'] ?? $check['name'];
        $messages = array_unique(array_map(fn ($item) => SecurityRules::render($template, $item), array_slice($worst, 0, self::MAX_NAMED)));
        $text = implode('; ', $messages);
        if (count($worst) > self::MAX_NAMED) {
            $text .= ' '.__('(and :count more)', ['count' => count($worst) - self::MAX_NAMED]);
        }

        return mb_strimwidth($text, 0, 1000, '…');
    }

    /** How an item is named in the details (the instance of a SQL Server, the name of a program, ...). */
    private static function itemName(string $source, array $item): string
    {
        return match ($source) {
            'posture' => '',
            'sqlserver' => (string) ($item['Instance'] ?? ''),
            'listening' => trim(($item['Protocol'] ?? '').' '.($item['Address'] ?? '').':'.($item['Port'] ?? '')),
            default => (string) ($item['Name'] ?? ''),
        };
    }

    /** The share of the checks that pass among the ones that can be told (fail, warn, pass), null when none. */
    public static function score(array $counts): ?int
    {
        $judged = ($counts[self::PASS] ?? 0) + ($counts[self::FAIL] ?? 0) + ($counts[self::WARN] ?? 0);

        return $judged === 0 ? null : (int) floor(100 * ($counts[self::PASS] ?? 0) / $judged);
    }
}
