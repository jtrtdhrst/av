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
        $trades = self::collapseMetricEvents($trades);
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
            foreach (self::groupItems($trade) as $item) {
                $value = $key($item);
                $label = $value === null || $value === '' ? null : $value;
                $id = $label ?? '__none__';
                if (!isset($buckets[$id])) {
                    $buckets[$id] = ['items' => [], 'label' => $label];
                }
                $buckets[$id]['items'][] = $item;
            }
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
            $dates = is_array($trade['__metricDates'] ?? null)
                ? $trade['__metricDates']
                : [(string) ($trade['date'] ?? '')];
            foreach ($dates as $date) {
                $key = Dates::dateKey((string) $date);
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
        return self::projectRange($trades, $from, $to, $fromField, $toField, true, false);
    }

    /**
     * Realized PnL in Europe/Warsaw date range, kept to one sample per trade.
     * Open positions with dated partial closes contribute only those realized legs.
     */
    private static function projectRange(
        array $trades,
        ?string $from,
        ?string $to,
        string $fromField,
        string $toField,
        bool $preserveUnrealized,
        bool $aggregateEvents
    ): array {
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
            $toTs = Dates::startOfNextLocalDay($to)->getTimestamp();
        }
        if ($fromTs !== null && $toTs !== null && $fromTs >= $toTs) {
            throw new \InvalidArgumentException('Pole from nie może być późniejsze niż to.');
        }

        $window = [];
        foreach ($trades as $index => $trade) {
            if (!is_array($trade)) {
                continue;
            }

            $events = self::realizedEvents($trade);
            $matched = [];
            foreach ($events as $event) {
                if (self::timestampInRange($event['date'], $fromTs, $toTs)) {
                    $matched[] = $event;
                }
            }
            if ($matched !== []) {
                usort($matched, static fn (array $a, array $b): int =>
                    (Dates::timestamp($a['date']) ?? 0) <=> (Dates::timestamp($b['date']) ?? 0)
                );
                $tradeKey = (string) $index . '#' . (string) ($trade['id'] ?? '');
                if (!$aggregateEvents) {
                    foreach ($matched as $event) {
                        $projected = $trade;
                        $projected['result'] = $event['result'];
                        $projected['date'] = $event['date'];
                        $projected['__metricTradeKey'] = $tradeKey;
                        $projected['__metricEvent'] = true;
                        $projected['__metricRealized'] = true;
                        $window[] = $projected;
                    }
                    continue;
                }

                $projected = $trade;
                $projected['result'] = array_sum(array_column($matched, 'result'));
                // Keep entry-time semantics for trade attributes; date-range
                // metadata and PnL grouping use the attached realization dates.
                $projected['date'] = (string) ($trade['date'] ?? $matched[0]['date']);
                $projected['__metricTradeKey'] = $tradeKey;
                $projected['__metricDates'] = array_values(array_map(
                    static fn (array $event): string => $event['date'],
                    $matched
                ));
                $projected['__metricEvents'] = array_values(array_map(
                    static fn (array $event): array => [
                        'date' => $event['date'],
                        'result' => $event['result'],
                        'kind' => $event['kind'],
                    ],
                    $matched
                ));
                $projected['__metricRealized'] = true;
                $window[] = $projected;
                continue;
            }

            // Keep the generic range helper's legacy behavior for entries with no
            // realized result. Analytics windows opt out of these unrealized rows.
            if ($preserveUnrealized && $events === [] && self::timestampInRange(
                (string) ($trade['date'] ?? ''),
                $fromTs,
                $toTs
            )) {
                $window[] = $trade;
            }
        }

        return array_values($window);
    }

    /**
     * Per-realization amounts. trade.result is cumulative, so subtract only the
     * individually dated partial legs before assigning its remainder to closedAt.
     *
     * @return list<array{date:string,result:float,kind:string}>
     */
    public static function realizedEvents(array $trade): array
    {
        $events = [];
        $datedPartialTotal = 0.0;
        $partials = $trade['partials'] ?? [];
        if (is_array($partials) && array_is_list($partials)) {
            foreach ($partials as $partial) {
                if (!is_array($partial)) {
                    continue;
                }
                $date = self::validTimestamp((string) ($partial['at'] ?? ''));
                $result = self::finiteNumber($partial['result'] ?? null);
                if ($date === null || $result === null || !is_finite($datedPartialTotal + $result)) {
                    continue;
                }
                $datedPartialTotal += $result;
                $events[] = ['date' => $date, 'result' => $result, 'kind' => 'partial'];
            }
        }

        $totalResult = self::finiteNumber($trade['result'] ?? null);
        if ($totalResult !== null) {
            $closeAt = self::validTimestamp((string) ($trade['closedAt'] ?? ''))
                ?? self::validTimestamp((string) ($trade['date'] ?? ''));
            $remainingResult = $totalResult - $datedPartialTotal;
            if ($closeAt !== null && is_finite($remainingResult)) {
                $events[] = ['date' => $closeAt, 'result' => $remainingResult, 'kind' => 'close'];
            }
        }

        return $events;
    }

    private static function timestampInRange(string $raw, ?int $fromTs, ?int $toTs): bool
    {
        $ts = Dates::timestamp($raw);
        if ($ts === null) {
            return $fromTs === null && $toTs === null;
        }
        if ($fromTs !== null && $ts < $fromTs) {
            return false;
        }
        if ($toTs !== null && $ts >= $toTs) {
            return false;
        }

        return true;
    }

    private static function validTimestamp(string $raw): ?string
    {
        $raw = trim($raw);

        return $raw !== '' && Dates::timestamp($raw) !== null ? $raw : null;
    }

    private static function finiteNumber(mixed $value): ?float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || (is_string($value) && trim($value) === '')
            || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /**
     * @param list<array> $trades
     * @return list<array>
     */
    private static function collapseMetricEvents(array $trades): array
    {
        $collapsed = [];
        $eventIndexes = [];
        foreach ($trades as $trade) {
            if (empty($trade['__metricEvent']) || !isset($trade['__metricTradeKey'])) {
                $collapsed[] = $trade;
                continue;
            }

            $key = (string) $trade['__metricTradeKey'];
            if (!array_key_exists($key, $eventIndexes)) {
                $row = $trade;
                unset($row['__metricEvent'], $row['__metricTradeKey'], $row['__metricEvents'], $row['__metricDates'], $row['__metricRealized']);
                $eventIndexes[$key] = count($collapsed);
                $collapsed[] = $row;
                continue;
            }

            $index = $eventIndexes[$key];
            $collapsed[$index]['result'] = (float) ($collapsed[$index]['result'] ?? 0)
                + (float) ($trade['result'] ?? 0);
        }

        return $collapsed;
    }

    /**
     * One event per grouped item, while the summarized sample remains one trade.
     *
     * @return list<array>
     */
    private static function groupItems(array $trade): array
    {
        $events = $trade['__metricEvents'] ?? null;
        if (!is_array($events) || $events === []) {
            return [$trade];
        }

        $items = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $item = $trade;
            $item['date'] = (string) ($event['date'] ?? $trade['date'] ?? '');
            $item['result'] = (float) ($event['result'] ?? 0);
            $item['__metricEvent'] = true;
            unset($item['__metricEvents'], $item['__metricDates'], $item['__metricRealized']);
            $items[] = $item;
        }

        return $items !== [] ? $items : [$trade];
    }

    /**
     * Transakcje zamknięte w zakresie dat, z jawnym zakresem i filtrami w odpowiedzi.
     */
    public static function window(array $state, ?string $from, ?string $to, array $filters = []): array
    {
        $included = array_values(array_filter(
            (array) ($state['trades'] ?? []),
            static fn (mixed $trade): bool => is_array($trade)
                && self::accountIncluded($state, (string) ($trade['account'] ?? ''))
        ));
        $window = self::projectRange($included, $from, $to, 'from', 'to', false, true);
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
