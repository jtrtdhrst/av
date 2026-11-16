<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

/**
 * Wspólna matematyka metryk tradingowych.
 *
 * Jedno miejsce liczenia netto, win rate, profit factor, expectancy i średniego R.
 * Każde narzędzie analityczne korzysta z tej klasy, więc raporty nie mogą się
 * rozjechać z get_stats. Brak danych daje null, nie zero: zero jest realnym
 * wynikiem i nie może znaczyć „nie wiem”.
 */
final class Metrics
{
    public const CURRENCY = 'USDT';

    public static function closed(array $trades): array
    {
        return array_values(array_filter($trades, static function ($trade): bool {
            return is_array($trade) && array_key_exists('result', $trade) && $trade['result'] !== null;
        }));
    }

    public static function accountIncluded(array $state, string $account): bool
    {
        if ($account === '') {
            return true;
        }
        foreach ((array) ($state['accounts'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['name'] ?? '') === $account) {
                return !empty($row['include']);
            }
        }

        // Preserve analytics for legacy account labels not in the account list.
        return true;
    }

    /**
     * @param list<array> $trades zamknięte transakcje
     */
    public static function summarize(array $trades): array
    {
        $trades = self::closed($trades);
        $n = count($trades);
        $wins = 0;
        $losses = 0;
        $breakeven = 0;
        $net = 0.0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;
        $fees = 0.0;
        $rSum = 0.0;
        $rCount = 0;
        foreach ($trades as $trade) {
            $result = (float) $trade['result'];
            $net += $result;
            if ($result > 0) {
                $wins++;
                $grossProfit += $result;
            } elseif ($result < 0) {
                $losses++;
                $grossLoss += abs($result);
            } else {
                $breakeven++;
            }
            if (array_key_exists('r', $trade) && $trade['r'] !== null && is_finite((float) $trade['r'])) {
                $rSum += (float) $trade['r'];
                $rCount++;
            }
            $fees += (float) ($trade['fee'] ?? 0);
        }

        return [
            'hasData' => $n > 0,
            'sampleSize' => $n,
            'wins' => $wins,
            'losses' => $losses,
            'breakeven' => $breakeven,
            'winRate' => $n > 0 ? Numbers::round($wins / $n * 100) : null,
            'net' => $n > 0 ? Numbers::round($net) : null,
            'grossProfit' => $n > 0 ? Numbers::round($grossProfit) : null,
            'grossLoss' => $n > 0 ? Numbers::round($grossLoss) : null,
            'profitFactor' => $grossLoss > 0 ? Numbers::round($grossProfit / $grossLoss) : null,
            'expectancyCurrency' => $n > 0 ? Numbers::round($net / $n) : null,
            'expectancyR' => $rCount > 0 ? Numbers::round($rSum / $rCount) : null,
            'avgR' => $rCount > 0 ? Numbers::round($rSum / $rCount) : null,
            'avgWin' => $wins > 0 ? Numbers::round($grossProfit / $wins) : null,
            'avgLoss' => $losses > 0 ? Numbers::round(-$grossLoss / $losses) : null,
            'fees' => $n > 0 ? Numbers::round($fees) : null,
            'rSampleSize' => $rCount,
            'currency' => self::CURRENCY,
        ];
    }

    /**
     * Grupowanie transakcji po kluczu i metryki per grupa.
     *
     * @param list<array> $trades
     * @param callable(array):?string $key
     * @return list<array>
     */
    public static function group(array $trades, callable $key, string $field): array
    {
        $buckets = [];
        foreach (self::closed($trades) as $trade) {
            $value = $key($trade);
            $label = $value === null || $value === '' ? null : $value;
            $id = $label ?? '__none__';
            if (!isset($buckets[$id])) {
                $buckets[$id] = ['items' => [], 'label' => $label];
            }
            $buckets[$id]['items'][] = $trade;
        }

        $rows = [];
        foreach ($buckets as $bucket) {
            $rows[] = array_merge(
                [$field => $bucket['label']],
                self::summarize($bucket['items'])
            );
        }
        usort($rows, static fn (array $a, array $b): int => ($b['net'] ?? PHP_INT_MIN) <=> ($a['net'] ?? PHP_INT_MIN));

        return $rows;
    }

    /**
     * Zakres dat faktycznie użyty w raporcie (na podstawie transakcji).
     *
     * @param list<array> $trades
     */
    public static function dateRange(array $trades): array
    {
        $from = null;
        $to = null;
        foreach ($trades as $trade) {
            $key = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($key === null) {
                continue;
            }
            if ($from === null || $key < $from) {
                $from = $key;
            }
            if ($to === null || $key > $to) {
                $to = $key;
            }
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Filtr zakresu dat w Europe/Warsaw. Puste wartości = brak filtra.
     *
     * @param list<array> $trades
     */
    public static function range(array $trades, ?string $from, ?string $to, string $fromField = 'from', string $toField = 'to'): array
    {
        $fromTs = null;
        $toTs = null;
        if ($from !== null && trim($from) !== '') {
            $from = trim($from);
            if (!Dates::isValidKey($from)) {
                throw new \InvalidArgumentException('Pole ' . $fromField . ' musi mieć format YYYY-MM-DD.');
            }
            $fromTs = Dates::startOfLocalDay($from)->getTimestamp();
        }
        if ($to !== null && trim($to) !== '') {
            $to = trim($to);
            if (!Dates::isValidKey($to)) {
                throw new \InvalidArgumentException('Pole ' . $toField . ' musi mieć format YYYY-MM-DD.');
            }
            $toTs = Dates::startOfLocalDay($to)->modify('+1 day')->getTimestamp();
        }
        if ($fromTs !== null && $toTs !== null && $fromTs > $toTs) {
            throw new \InvalidArgumentException('Pole from nie może być późniejsze niż to.');
        }

        return array_values(array_filter($trades, static function (array $trade) use ($fromTs, $toTs): bool {
            $raw = (string) ($trade['date'] ?? '');
            $ts = strtotime($raw);
            if ($ts === false) {
                return $fromTs === null && $toTs === null;
            }
            if ($fromTs !== null && $ts < $fromTs) {
                return false;
            }
            if ($toTs !== null && $ts >= $toTs) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Transakcje zamknięte w zakresie dat, z jawnym zakresem i filtrami w odpowiedzi.
     */
    public static function window(array $state, ?string $from, ?string $to, array $filters = []): array
    {
        $closed = array_values(array_filter(
            self::closed((array) ($state['trades'] ?? [])),
            static fn (array $trade): bool => self::accountIncluded($state, (string) ($trade['account'] ?? ''))
        ));
        $window = self::range($closed, $from, $to);
        $range = self::dateRange($window);

        return [
            'trades' => $window,
            'range' => $range,
            'filters' => array_merge([
                'from' => $from !== null && trim($from) !== '' ? trim($from) : null,
                'to' => $to !== null && trim($to) !== '' ? trim($to) : null,
            ], $filters),
        ];
    }
}
