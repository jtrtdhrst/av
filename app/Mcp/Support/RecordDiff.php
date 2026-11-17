<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\TradeRules;

/**
 * Semantyczne porównywanie zapisów i tworzenie diffów audytowych.
 *
 * JSON rozróżnia int i float składniowo, ale w polach liczbowych recordu
 * `10000` i `10000.0` oznaczają tę samą wartość. Listy nadal są porównywane
 * kolejnościowo, a pola obiektu niezależnie od kolejności kluczy.
 */
final class RecordDiff
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     * @param list<string> $ignoredFields
     * @return list<array{field:string,before:mixed,after:mixed}>
     */
    public static function between(?array $before, array $after, array $ignoredFields = []): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after)));
        foreach ($keys as $key) {
            if (in_array((string) $key, $ignoredFields, true)) {
                continue;
            }
            $hasBefore = $before !== null && array_key_exists($key, $before);
            $hasAfter = array_key_exists($key, $after);
            $old = $hasBefore ? $before[$key] : null;
            $new = $hasAfter ? $after[$key] : null;
            if ($hasBefore === $hasAfter && self::sameValue($old, $new)) {
                continue;
            }
            $changes[] = ['field' => (string) $key, 'before' => $old, 'after' => $new];
        }
        return $changes;
    }

    /**
     * Deep state-level diff for proposals. Lists of records are matched by ID;
     * screen payloads are represented by a digest and decoded byte count.
     *
     * @return list<array{field:string,before:mixed,after:mixed}>
     */
    public static function stateBetween(array $before, array $after): array
    {
        return self::deepBetween($before, $after, 'state', null);
    }

    public static function sameValue(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            if (!is_finite((float) $left) || !is_finite((float) $right)) {
                return false;
            }
            if (is_int($left) && is_float($right)) {
                return floor($right) === $right && sprintf('%.0f', $right) === (string) $left;
            }
            if (is_float($left) && is_int($right)) {
                return floor($left) === $left && sprintf('%.0f', $left) === (string) $right;
            }
            return $left === $right;
        }
        if (!is_array($left) || !is_array($right) || array_is_list($left) !== array_is_list($right)) {
            return false;
        }
        if (count($left) !== count($right)) {
            return false;
        }
        if (array_is_list($left)) {
            foreach ($left as $index => $value) {
                if (!self::sameValue($value, $right[$index])) {
                    return false;
                }
            }
            return true;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::sameValue($value, $right[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * State writes add empty legacy-compatible containers while applying an
     * operation. Treat only absent defaults as equivalent, so no-op writes do
     * not persist normalization-only changes or increment the revision.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function sameState(array $before, array $after): bool
    {
        return self::sameValue(self::withStateDefaults($before), self::withStateDefaults($after));
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function withStateDefaults(array $state): array
    {
        foreach (['trades', 'setups', 'checkins', 'accounts', 'flows', 'plans', 'tradeReviews', 'settings', 'trash'] as $key) {
            if (!isset($state[$key]) || !is_array($state[$key])) {
                $state[$key] = [];
            }
        }
        if (!isset($state['prop']) || !is_array($state['prop'])) {
            $state['prop'] = ['challenges' => [], 'selectedId' => null];
        } elseif (!isset($state['prop']['challenges']) || !is_array($state['prop']['challenges'])) {
            $state['prop']['challenges'] = [];
        }
        return TradeRules::normalizeTradeDirections($state);
    }

    /**
     * @return list<array{field:string,before:mixed,after:mixed}>
     */
    private static function deepBetween(mixed $before, mixed $after, string $path, ?string $key): array
    {
        if (self::sameValue($before, $after)) {
            return [];
        }
        if ($key === 'screens') {
            return [[
                'field' => $path,
                'before' => self::screenSummary($before),
                'after' => self::screenSummary($after),
            ]];
        }
        if (is_array($before) && is_array($after)) {
            if (!array_is_list($before) && !array_is_list($after)) {
                $changes = [];
                $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
                foreach ($keys as $childKey) {
                    $hasBefore = array_key_exists($childKey, $before);
                    $hasAfter = array_key_exists($childKey, $after);
                    $childPath = $path . '.' . (string) $childKey;
                    if (!$hasBefore || !$hasAfter) {
                        $changes[] = [
                            'field' => $childPath,
                            'before' => $hasBefore ? self::safeValue($before[$childKey], (string) $childKey) : null,
                            'after' => $hasAfter ? self::safeValue($after[$childKey], (string) $childKey) : null,
                        ];
                        continue;
                    }
                    array_push(
                        $changes,
                        ...self::deepBetween($before[$childKey], $after[$childKey], $childPath, (string) $childKey)
                    );
                }
                return $changes;
            }

            if (array_is_list($before) && array_is_list($after)
                && self::isIdentifiedRecordList($before) && self::isIdentifiedRecordList($after)) {
                $beforeById = [];
                $afterById = [];
                foreach ($before as $record) {
                    $beforeById[(string) $record['id']] = $record;
                }
                foreach ($after as $record) {
                    $afterById[(string) $record['id']] = $record;
                }
                $changes = [];
                foreach (array_unique(array_merge(array_keys($beforeById), array_keys($afterById))) as $id) {
                    $recordPath = $path . '[id:' . $id . ']';
                    if (!isset($beforeById[$id]) || !isset($afterById[$id])) {
                        $changes[] = [
                            'field' => $recordPath,
                            'before' => isset($beforeById[$id]) ? self::safeValue($beforeById[$id]) : null,
                            'after' => isset($afterById[$id]) ? self::safeValue($afterById[$id]) : null,
                        ];
                        continue;
                    }
                    array_push(
                        $changes,
                        ...self::deepBetween($beforeById[$id], $afterById[$id], $recordPath, null)
                    );
                }
                return $changes;
            }
        }

        return [[
            'field' => $path,
            'before' => self::safeValue($before, $key),
            'after' => self::safeValue($after, $key),
        ]];
    }

    /**
     * @param list<mixed> $records
     */
    private static function isIdentifiedRecordList(array $records): bool
    {
        foreach ($records as $record) {
            if (!is_array($record) || trim((string) ($record['id'] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    private static function safeValue(mixed $value, ?string $key = null): mixed
    {
        return self::redactValue($value, $key);
    }

    /**
     * Remove image payloads from every MCP-facing value while preserving
     * enough metadata to identify the changed screenshot.
     */
    public static function redactValue(mixed $value, ?string $key = null): mixed
    {
        $normalizedKey = strtolower((string) $key);
        if ($normalizedKey === 'screens' || $normalizedKey === 'dataurl' || $normalizedKey === 'data_url') {
            return self::screenSummary($value);
        }
        if (is_string($value) && preg_match('#^data:image/[^;]+;base64,#i', $value) === 1) {
            return self::screenSummary($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $safe = [];
        foreach ($value as $childKey => $child) {
            $safe[$childKey] = self::redactValue($child, (string) $childKey);
        }
        return $safe;
    }

    private static function screenSummary(mixed $value): array
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $json === false ? '' : $json;

        return [
            'sha256' => hash('sha256', $json),
            'bytes' => self::screenByteCount($value),
        ];
    }

    private static function screenByteCount(mixed $value): int
    {
        if (is_string($value)) {
            if (preg_match('#^data:[^;]+;base64,(.*)$#s', $value, $match) === 1) {
                $decoded = base64_decode($match[1], true);
                return $decoded === false ? strlen($match[1]) : strlen($decoded);
            }
            return strlen($value);
        }
        if (!is_array($value)) {
            return 0;
        }
        $bytes = 0;
        foreach ($value as $child) {
            $bytes += self::screenByteCount($child);
        }
        return $bytes;
    }
}
