<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

/**
 * Normalizes the legacy one-plan-per-date map and the current date#id keys.
 */
final class DayPlanRecords
{
    /**
     * @return list<array{key:string,date:string,id:string,plan:array}>
     */
    public static function all(array $state): array
    {
        $records = [];
        foreach ((array) ($state['plans'] ?? []) as $key => $value) {
            $key = (string) $key;
            $plan = is_array($value) ? $value : (is_scalar($value) ? ['legacyValue' => $value] : null);
            if ($plan === null) {
                continue;
            }

            $keyDate = null;
            $keyId = null;
            if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:#(.+))?$/', $key, $matches) === 1
                && Dates::isValidKey($matches[1])) {
                $keyDate = $matches[1];
                $keyId = isset($matches[2]) ? trim($matches[2]) : null;
            }
            $date = $keyDate ?? Dates::dateKey((string) ($plan['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $id = trim((string) ($plan['id'] ?? ''));
            if ($id === '') {
                $id = $keyId !== null && $keyId !== '' ? $keyId : $date;
            }
            $plan['id'] = $id;
            $plan['date'] = $date;
            $records[] = ['key' => $key, 'date' => $date, 'id' => $id, 'plan' => $plan];
        }

        usort($records, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']) ?: strcmp($a['key'], $b['key']));

        return $records;
    }

    /**
     * @return list<array{key:string,date:string,id:string,plan:array}>
     */
    public static function forDate(array $state, string $date): array
    {
        return array_values(array_filter(
            self::all($state),
            static fn (array $record): bool => $record['date'] === $date
        ));
    }

    public static function insertKey(array $state, string $date, string $id): string
    {
        $plans = is_array($state['plans'] ?? null) ? $state['plans'] : [];
        if (!array_key_exists($date, $plans)) {
            return $date;
        }

        return $date . '#' . $id;
    }

    public static function uniqueId(array $state): string
    {
        $ids = [];
        foreach (self::all($state) as $record) {
            $ids[$record['id']] = true;
        }
        do {
            $id = 'pl_' . bin2hex(random_bytes(6));
        } while (isset($ids[$id]));

        return $id;
    }
}
