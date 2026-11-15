<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;

/**
 * Plan dnia: historia, zgodność transakcji z planem i pełny kontekst dnia.
 *
 * Zgodność jest liczona na regułach planu (rynek, kierunek, limit transakcji,
 * ryzyko, godzina, stop loss), nie na wyniku transakcji. Transakcja bez
 * zatwierdzonego planu jest oznaczana jako „nie da się ustalić”, a nie jako
 * niezgodna.
 */
final class DayPlanService
{
    private const MAX_RANGE_DAYS = 370;

    /**
     * @return array<string,array>
     */
    public function plans(array $state): array
    {
        $plans = [];
        foreach ((array) ($state['plans'] ?? []) as $key => $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $date = is_string($key) && Dates::isValidKey($key)
                ? $key
                : Dates::dateKey((string) ($plan['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $plans[$date] = $plan + ['date' => $date];
        }
        ksort($plans);

        return $plans;
    }

    public function planFor(array $state, string $date): ?array
    {
        $plans = $this->plans($state);
        $plan = $plans[$date] ?? null;
        if ($plan === null) {
            return null;
        }
        $plan['status'] = $this->status($plan);

        return $plan;
    }

    public function status(array $plan): string
    {
        $approvedAt = $plan['approvedAt'] ?? null;
        if ($approvedAt !== null && $approvedAt !== '' && $approvedAt !== 0 && $approvedAt !== false) {
            return 'approved';
        }

        return 'draft';
    }

    /**
     * @return list<string>
     */
    public function marketsOf(array $plan): array
    {
        $markets = [];
        foreach ((array) ($plan['markets'] ?? []) as $market) {
            $value = strtoupper(trim((string) $market));
            if ($value !== '') {
                $markets[] = $value;
            }
        }
        $primary = strtoupper(trim((string) ($plan['market'] ?? '')));
        if ($primary !== '' && !in_array($primary, $markets, true)) {
            array_unshift($markets, $primary);
        }

        return array_values(array_unique($markets));
    }

    public function planRiskPercent(array $plan): ?float
    {
        $raw = trim((string) ($plan['risk'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $value = Numbers::pnum($raw);

        return $value > 0 ? $value : null;
    }

    public function planMaxTrades(array $plan): ?int
    {
        $raw = trim((string) ($plan['maxTrades'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $value = (int) Numbers::pnum($raw);

        return $value > 0 ? $value : null;
    }

    public function history(array $state, ?string $from, ?string $to, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$fromKey, $toKey] = $this->rangeKeys($from, $to);
        $tradesByDate = $this->tradesByDate($state);
        $items = [];
        foreach ($this->plans($state) as $date => $plan) {
            if ($fromKey !== null && $date < $fromKey) {
                continue;
            }
            if ($toKey !== null && $date > $toKey) {
                continue;
            }
            $dayTrades = $tradesByDate[$date] ?? [];
            $compliance = $this->complianceForDay($plan, $date, $dayTrades, $state);
            $items[] = array_merge($this->presentPlan($plan, $date), [
                'trades' => count($dayTrades),
                'compliance' => [
                    'determined' => $compliance['determined'],
                    'alignedTrades' => $compliance['alignedTrades'],
                    'violatingTrades' => $compliance['violatingTrades'],
                    'compliancePct' => $compliance['compliancePct'],
                ],
            ]);
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        $total = count($items);
        $page = array_slice($items, $offset, $limit);

        return [
            'items' => $page,
            'total' => $total,
            'nextOffset' => ($offset + count($page)) < $total ? $offset + count($page) : null,
            'range' => $this->rangeMeta($fromKey, $toKey, $items),
            'filters' => ['from' => $fromKey, 'to' => $toKey],
        ];
    }

    /**
     * Zgodność transakcji z planem w zakresie dat.
     */
    public function compliance(array $state, ?string $from, ?string $to, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$fromKey, $toKey] = $this->rangeKeys($from, $to);
        $plans = $this->plans($state);
        $byDate = $this->tradesByDate($state);
        $dates = [];
        foreach ($byDate as $date => $trades) {
            if (!in_array($date, $dates, true)) {
                $dates[] = $date;
            }
        }
        foreach ($plans as $date => $plan) {
            if (!in_array($date, $dates, true)) {
                $dates[] = $date;
            }
        }
        sort($dates);
        $days = [];
        foreach ($dates as $date) {
            if ($fromKey !== null && $date < $fromKey) {
                continue;
            }
            if ($toKey !== null && $date > $toKey) {
                continue;
            }
            $plan = $plans[$date] ?? null;
            $dayTrades = $byDate[$date] ?? [];
            if ($plan === null && $dayTrades === []) {
                continue;
            }
            $days[] = $this->complianceForDay($plan, $date, $dayTrades, $state);
        }
        usort($days, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        $publicDays = array_map(fn (array $day): array => $this->publicCompliance($day), $days);
        $total = count($days);
        $page = array_slice($publicDays, $offset, $limit);

        $alignedTrades = [];
        $violatingTrades = [];
        $undetermined = 0;
        $rules = [];
        foreach ($days as $day) {
            $alignedTrades = array_merge($alignedTrades, $day['aligned']);
            $violatingTrades = array_merge($violatingTrades, $day['violating']);
            $undetermined += $day['undeterminedTrades'];
            foreach ($day['rulesViolated'] as $rule) {
                $rules[$rule['rule']] = ($rules[$rule['rule']] ?? 0) + $rule['count'];
            }
        }
        arsort($rules);
        $ruleRows = [];
        foreach ($rules as $rule => $count) {
            $ruleRows[] = ['rule' => $rule, 'count' => $count];
        }
        $determined = count($alignedTrades) + count($violatingTrades);

        return [
            'hasData' => $days !== [],
            'sampleSize' => $determined,
            'days' => $page,
            'totalDays' => $total,
            'nextOffset' => ($offset + count($page)) < $total ? $offset + count($page) : null,
            'summary' => [
                'tradesAnalyzed' => $determined,
                'alignedTrades' => count($alignedTrades),
                'violatingTrades' => count($violatingTrades),
                'undeterminedTrades' => $undetermined,
                'compliancePct' => $determined > 0 ? Numbers::round(count($alignedTrades) / $determined * 100) : null,
                'aligned' => Metrics::summarize($alignedTrades),
                'violating' => Metrics::summarize($violatingTrades),
                'rulesViolated' => $ruleRows,
            ],
            'range' => $this->rangeMeta($fromKey, $toKey, $days, 'date'),
            'filters' => ['from' => $fromKey, 'to' => $toKey],
        ];
    }

    /**
     * Pojedynczy dzień: plan, transakcje, limity, zgodność.
     */
    public function day(array $state, string $date): array
    {
        if (!Dates::isValidKey($date)) {
            throw new \InvalidArgumentException('date musi mieć format YYYY-MM-DD.');
        }
        $plan = $this->planFor($state, $date);
        $trades = $this->tradesByDate($state)[$date] ?? [];
        $compliance = $this->complianceForDay($plan, $date, $trades, $state);

        return [
            'date' => $date,
            'plan' => $plan === null ? null : $this->presentPlan($plan, $date),
            'trades' => array_map(fn (array $trade): array => $this->presentTrade($trade), $trades),
            'limits' => $this->limits($plan, $state, $trades),
            'compliance' => $this->publicCompliance($compliance),
        ];
    }

    /**
     * Wersja zgodności bez surowych transakcji (są już w polu trades).
     */
    public function publicCompliance(array $row): array
    {
        unset($row['aligned'], $row['violating']);

        return $row;
    }

    private function limits(?array $plan, array $state, array $trades): array
    {
        $riskPercent = $plan !== null ? $this->planRiskPercent($plan) : null;
        $maxTrades = $plan !== null ? $this->planMaxTrades($plan) : null;
        $until = $plan !== null ? (trim((string) ($plan['until'] ?? '')) ?: null) : null;
        $capital = $this->capital($state);
        $usedRisk = 0.0;
        $withStop = 0;
        foreach ($trades as $trade) {
            $risk = $this->riskAmount($trade);
            if ($risk !== null) {
                $usedRisk += $risk;
                $withStop++;
            }
        }

        return [
            'plannedRiskPercent' => $riskPercent,
            'maxTrades' => $maxTrades,
            'tradingUntil' => $until,
            'notDo' => $plan !== null ? (string) ($plan['notDo'] ?? '') : null,
            'tradesCount' => count($trades),
            'tradesWithStopLoss' => $withStop,
            'tradesOverLimit' => $maxTrades !== null ? max(0, count($trades) - $maxTrades) : null,
            'usedRisk' => $withStop > 0 ? Numbers::round($usedRisk) : null,
            'usedRiskPercent' => $withStop > 0 && $capital > 0 ? Numbers::round($usedRisk / $capital * 100) : null,
            'capital' => $capital > 0 ? Numbers::round($capital) : null,
            'currency' => Metrics::CURRENCY,
            'rulesNote' => 'Pole notDo jest tekstem użytkownika; MCP nie ocenia jego treści, więc nie wlicza go do zgodności.',
        ];
    }

    /**
     * Zgodność jednej transakcji z planem dnia.
     *
     * @return array{determined:bool,aligned:bool,reasons:list<string>}
     */
    public function alignment(array $state, array $trade, ?array $plan, int $sequence = 1): array
    {
        $reasons = [];
        $determined = true;
        if ($plan === null) {
            return ['determined' => false, 'aligned' => false, 'reasons' => ['no_plan']];
        }
        if ($this->status($plan) !== 'approved') {
            return ['determined' => false, 'aligned' => false, 'reasons' => ['plan_not_approved']];
        }
        $markets = $this->marketsOf($plan);
        $pair = strtoupper((string) ($trade['pair'] ?? ''));
        $base = trim((string) (explode('/', $pair)[0] ?? $pair));
        $marketOk = false;
        foreach ($markets as $market) {
            if ($market !== '' && ($market === $base || str_starts_with($pair, $market))) {
                $marketOk = true;
                break;
            }
        }
        if (!$marketOk) {
            $reasons[] = 'market_not_in_plan';
        }
        $bias = (string) ($plan['bias'] ?? 'none');
        $direction = (string) ($trade['dir'] ?? 'l') === 's' ? 'short' : 'long';
        if ($bias === 'long' || $bias === 'short') {
            if ($bias !== $direction) {
                $reasons[] = 'direction_against_bias';
            }
        } else {
            $determined = false;
            $reasons[] = 'plan_bias_neutral';
        }
        $maxTrades = $this->planMaxTrades($plan);
        if ($maxTrades !== null && $sequence > $maxTrades) {
            $reasons[] = 'max_trades_exceeded';
        }
        $planRisk = $this->planRiskPercent($plan);
        $capital = $this->capital($state);
        $risk = $this->riskAmount($trade);
        if ($risk === null) {
            $reasons[] = 'missing_stop_loss';
        } elseif ($planRisk !== null && $capital > 0 && ($risk / $capital * 100) > $planRisk + 0.0001) {
            $reasons[] = 'risk_above_plan';
        }
        $until = trim((string) ($plan['until'] ?? ''));
        if ($until !== '') {
            $local = Dates::localParts((string) ($trade['date'] ?? ''));
            if ($local['time'] !== null && $local['time'] > $until) {
                $reasons[] = 'after_plan_hours';
            }
        }

        return [
            'determined' => $determined,
            'aligned' => $determined && $reasons === [],
            'reasons' => $reasons,
        ];
    }

    private function complianceForDay(?array $plan, string $date, array $trades, array $state): array
    {
        $aligned = [];
        $violating = [];
        $undetermined = 0;
        $rows = [];
        $ruleCounts = [];
        $ordered = $trades;
        usort($ordered, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
        $sequence = 0;
        foreach ($ordered as $trade) {
            $sequence++;
            $alignment = $this->alignment($state, $trade, $plan, $sequence);
            $reasons = $alignment['reasons'];
            $determined = $alignment['determined'];
            $isAligned = $alignment['aligned'];
            if (!$determined) {
                $undetermined++;
            } elseif ($isAligned) {
                $aligned[] = $trade;
            } else {
                $violating[] = $trade;
            }
            foreach ($reasons as $reason) {
                $count = $ruleCounts[$reason] ?? ['rule' => $reason, 'count' => 0, 'tradeIds' => []];
                $count['count']++;
                $count['tradeIds'][] = (string) ($trade['id'] ?? '');
                $ruleCounts[$reason] = $count;
            }
            $rows[] = [
                'tradeId' => (string) ($trade['id'] ?? ''),
                'date' => (string) ($trade['date'] ?? ''),
                'pair' => (string) ($trade['pair'] ?? ''),
                'direction' => (string) ($trade['dir'] ?? 'l') === 's' ? 'short' : 'long',
                'result' => $trade['result'] !== null ? Numbers::round((float) $trade['result']) : null,
                'determined' => $determined,
                'aligned' => $isAligned,
                'reasons' => $reasons,
            ];
        }
        $rules = array_values($ruleCounts);
        usort($rules, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $determinedCount = count($aligned) + count($violating);

        return [
            'date' => $date,
            'planStatus' => $plan === null ? 'none' : $this->status($plan),
            'determined' => $determinedCount > 0,
            'trades' => count($trades),
            'alignedTrades' => count($aligned),
            'violatingTrades' => count($violating),
            'undeterminedTrades' => $undetermined,
            'compliancePct' => $determinedCount > 0 ? Numbers::round(count($aligned) / $determinedCount * 100) : null,
            'rulesViolated' => $rules,
            'attention' => array_values(array_filter($rows, static fn (array $row): bool => !$row['aligned'] && $row['reasons'] !== [])),
            'tradeRows' => $rows,
            'aligned' => $aligned,
            'violating' => $violating,
            'resultAligned' => Metrics::summarize($aligned),
            'resultViolating' => Metrics::summarize($violating),
            'currency' => Metrics::CURRENCY,
            'note' => 'Zgodność wynika z reguł planu (rynek, kierunek, limit transakcji, ryzyko, godzina, SL), nie z zysku lub straty.',
        ];
    }

    private function presentPlan(array $plan, string $date): array
    {
        return [
            'date' => $date,
            'status' => $this->status($plan),
            'approved' => $this->status($plan) === 'approved',
            'approvedAt' => $plan['approvedAt'] ?? null,
            'markets' => $this->marketsOf($plan),
            'market' => (string) ($plan['market'] ?? ''),
            'bias' => (string) ($plan['bias'] ?? ''),
            'reason' => (string) ($plan['why'] ?? ''),
            'levels' => array_values(array_filter((array) ($plan['levels'] ?? []), 'is_array')),
            'scenarioA' => [
                'condition' => (string) ($plan['ifMain'] ?? ''),
                'action' => (string) ($plan['thenMain'] ?? ''),
            ],
            'scenarioB' => [
                'condition' => (string) ($plan['ifAlt'] ?? ''),
                'action' => (string) ($plan['thenAlt'] ?? ''),
            ],
            'limits' => [
                'riskPercent' => $this->planRiskPercent($plan),
                'maxTrades' => $this->planMaxTrades($plan),
                'tradingUntil' => trim((string) ($plan['until'] ?? '')) ?: null,
            ],
            'rulesNotToDo' => (string) ($plan['notDo'] ?? ''),
        ];
    }

    private function presentTrade(array $trade): array
    {
        $local = Dates::localParts((string) ($trade['date'] ?? ''));

        return [
            'id' => (string) ($trade['id'] ?? ''),
            'date' => (string) ($trade['date'] ?? ''),
            'localDate' => $local['date'],
            'localTime' => $local['time'],
            'pair' => (string) ($trade['pair'] ?? ''),
            'direction' => (string) ($trade['dir'] ?? 'l') === 's' ? 'short' : 'long',
            'entry' => $trade['entry'] ?? null,
            'exit' => $trade['exit'] ?? null,
            'stopLoss' => $trade['sl'] ?? null,
            'takeProfit' => $trade['tp'] ?? null,
            'result' => $trade['result'] !== null ? Numbers::round((float) $trade['result']) : null,
            'r' => $trade['r'] !== null ? Numbers::round((float) $trade['r']) : null,
            'setup' => $trade['setup'] ?? null,
            'grade' => $trade['grade'] ?? null,
            'emotion' => $trade['emotion'] ?? null,
            'account' => $trade['account'] ?? null,
            'riskAmount' => $this->riskAmount($trade),
        ];
    }

    /**
     * Ryzyko transakcji w walucie wyniku: |entry - SL| * size.
     */
    public function riskAmount(array $trade): ?float
    {
        $slRaw = $trade['sl'] ?? null;
        if ($slRaw === null || $slRaw === '' || $slRaw === 'brak' || $slRaw === '—') {
            return null;
        }
        $entry = Numbers::pnum($trade['entry'] ?? 0);
        $sl = Numbers::pnum($slRaw);
        $size = Numbers::pnum($trade['size'] ?? 0);
        if ($entry <= 0 || $sl <= 0 || $size <= 0 || abs($entry - $sl) <= 0) {
            return null;
        }

        return Numbers::round(abs($entry - $sl) * $size);
    }

    public function capital(array $state): float
    {
        $sum = 0.0;
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (!is_array($account) || empty($account['include'])) {
                continue;
            }
            $sum += (float) ($account['balance'] ?? 0) * Numbers::fxToUsdt((string) ($account['currency'] ?? 'USDT'));
        }

        return $sum;
    }

    /**
     * @return array<string,list<array>>
     */
    private function tradesByDate(array $state): array
    {
        $map = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $account = (string) ($trade['account'] ?? '');
            if ($account !== '') {
                $included = true;
                $known = false;
                foreach ((array) ($state['accounts'] ?? []) as $row) {
                    if (is_array($row) && (string) ($row['name'] ?? '') === $account) {
                        $known = true;
                        $included = !empty($row['include']);
                        break;
                    }
                }
                if ($known && !$included) {
                    continue;
                }
            }
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $map[$date][] = $trade;
        }

        return $map;
    }

    private function rangeKeys(?string $from, ?string $to): array
    {
        $fromKey = null;
        $toKey = null;
        if ($from !== null && trim($from) !== '') {
            $from = trim($from);
            if (!Dates::isValidKey($from)) {
                throw new \InvalidArgumentException('Pole from musi mieć format YYYY-MM-DD.');
            }
            $fromKey = $from;
        }
        if ($to !== null && trim($to) !== '') {
            $to = trim($to);
            if (!Dates::isValidKey($to)) {
                throw new \InvalidArgumentException('Pole to musi mieć format YYYY-MM-DD.');
            }
            $toKey = $to;
        }
        if ($fromKey !== null && $toKey !== null) {
            if ($fromKey > $toKey) {
                throw new \InvalidArgumentException('Pole from nie może być późniejsze niż to.');
            }
            $days = (new \DateTimeImmutable($fromKey))->diff(new \DateTimeImmutable($toKey))->days;
            if ($days !== false && $days > self::MAX_RANGE_DAYS) {
                throw new \InvalidArgumentException('Zakres dat może obejmować maksymalnie ' . self::MAX_RANGE_DAYS . ' dni.');
            }
        }

        return [$fromKey, $toKey];
    }

    private function rangeMeta(?string $from, ?string $to, array $rows, string $field = 'date'): array
    {
        $first = null;
        $last = null;
        foreach ($rows as $row) {
            $key = (string) ($row[$field] ?? '');
            if ($key === '') {
                continue;
            }
            if ($first === null || $key < $first) {
                $first = $key;
            }
            if ($last === null || $key > $last) {
                $last = $key;
            }
        }

        return [
            'from' => $from ?? $first,
            'to' => $to ?? $last,
            'timezone' => Dates::TZ,
        ];
    }
}
