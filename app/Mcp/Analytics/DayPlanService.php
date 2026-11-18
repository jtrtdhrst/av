<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Integrations\Normalizer;
use AlphaVault\TradeRules;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\DayPlanLevels;
use AlphaVault\Mcp\Support\DayPlanRecords;
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
        foreach (DayPlanRecords::all($state) as $record) {
            $plans[$record['key']] = $record['plan'];
        }
        uasort($plans, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']) ?: strcmp((string) $a['id'], (string) $b['id']));

        return $plans;
    }

    /**
     * @return list<array>
     */
    public function plansForDate(array $state, string $date): array
    {
        return array_map(function (array $record): array {
            $plan = $this->normalizePlanLevels($record['plan']);
            $plan['status'] = $this->status($plan);

            return $plan;
        }, DayPlanRecords::forDate($state, $date));
    }

    public function planFor(array $state, string $date, ?string $market = null, ?string $planId = null): ?array
    {
        $matches = DayPlanRecords::forDate($state, $date);
        $planId = trim((string) $planId);
        if ($planId !== '') {
            $matches = array_values(array_filter(
                $matches,
                static fn (array $record): bool => $record['id'] === $planId
            ));
        }
        $market = strtoupper(trim((string) $market));
        if ($market !== '') {
            $matches = array_values(array_filter(
                $matches,
                fn (array $record): bool => $this->marketSelectorMatches($market, $record['plan'])
            ));
        }
        if (count($matches) !== 1) {
            return null;
        }
        $plan = $this->normalizePlanLevels($matches[0]['plan']);
        $plan['status'] = $this->status($plan);

        return $plan;
    }

    public function planForTrade(array $state, array $trade): ?array
    {
        $date = Dates::dateKey((string) ($trade['date'] ?? ''));
        if ($date === null) {
            return null;
        }
        $plans = $this->plansForDate($state, $date);
        $plan = $this->planForTradeAmong($plans, $trade);
        if ($plan !== null) {
            $plan['status'] = $this->status($plan);
        }

        return $plan;
    }

    public function marketMatchesPlan(array $trade, array $plan): bool
    {
        $pair = Normalizer::pair((string) ($trade['pair'] ?? ''));
        if ($pair === '') {
            return false;
        }
        $pairBase = explode('/', $pair, 2)[0];
        foreach ($this->marketsOf($plan) as $market) {
            $normalizedMarket = Normalizer::pair($market);
            if ($normalizedMarket !== '' && ($normalizedMarket === $pair
                || (!str_contains($normalizedMarket, '/') && $normalizedMarket === $pairBase))) {
                return true;
            }
        }

        return false;
    }

    private function marketSelectorMatches(string $requested, array $plan): bool
    {
        $requested = strtoupper(trim($requested));
        $requestedCompact = preg_replace('/[^A-Z0-9]/', '', $requested) ?? $requested;
        foreach ($this->marketsOf($plan) as $market) {
            $marketCompact = preg_replace('/[^A-Z0-9]/', '', $market) ?? $market;
            if ($market === $requested || ($requestedCompact !== '' && $marketCompact === $requestedCompact)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array>
     */
    public function tradesForPlan(array $state, string $date, array $trades, array $plan): array
    {
        $plans = $this->plansForDate($state, $date);
        $selectedId = (string) ($plan['id'] ?? '');

        return array_values(array_filter($trades, function (array $trade) use ($plans, $selectedId): bool {
            $selected = $this->planForTradeAmong($plans, $trade);
            return $selected !== null && (string) ($selected['id'] ?? '') === $selectedId;
        }));
    }

    public function tradeSequence(array $state, array $trade, ?array $plan, bool $includedAccountsOnly = false): int
    {
        if ($plan === null) {
            return 1;
        }
        $date = Dates::dateKey((string) ($trade['date'] ?? ''));
        if ($date === null) {
            return 1;
        }
        $samePlan = [];
        foreach ((array) ($state['trades'] ?? []) as $candidate) {
            if (!is_array($candidate) || Dates::dateKey((string) ($candidate['date'] ?? '')) !== $date) {
                continue;
            }
            if ($includedAccountsOnly && !$this->includedForStatistics($state, $candidate)) {
                continue;
            }
            $selected = $this->planForTrade($state, $candidate);
            if ($selected !== null && (string) ($selected['id'] ?? '') === (string) ($plan['id'] ?? '')) {
                $samePlan[] = $candidate;
            }
        }
        usort($samePlan, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
        foreach ($samePlan as $index => $candidate) {
            if ((string) ($candidate['id'] ?? '') === (string) ($trade['id'] ?? '')) {
                return $index + 1;
            }
        }

        return 1;
    }

    /**
     * @param list<array> $plans
     */
    private function planForTradeAmong(array $plans, array $trade): ?array
    {
        if (count($plans) === 1) {
            return $plans[0];
        }
        if (count($plans) < 2) {
            return null;
        }
        $matches = array_values(array_filter($plans, fn (array $plan): bool => $this->marketMatchesPlan($trade, $plan)));

        return count($matches) === 1 ? $matches[0] : null;
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
        foreach ($this->plans($state) as $plan) {
            $date = (string) ($plan['date'] ?? '');
            if ($fromKey !== null && $date < $fromKey) {
                continue;
            }
            if ($toKey !== null && $date > $toKey) {
                continue;
            }
            $dayTrades = $this->tradesForPlan($state, $date, $tradesByDate[$date] ?? [], $plan);
            $compliance = $this->complianceForDay($plan, $date, $dayTrades, $state);
            $items[] = array_merge($this->presentPlan($plan, $date), [
                'planId' => (string) ($plan['id'] ?? $date),
                'trades' => count($dayTrades),
                'compliance' => [
                    'determined' => $compliance['determined'],
                    'alignedTrades' => $compliance['alignedTrades'],
                    'violatingTrades' => $compliance['violatingTrades'],
                    'compliancePct' => $compliance['compliancePct'],
                ],
            ]);
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']) ?: strcmp((string) ($a['planId'] ?? ''), (string) ($b['planId'] ?? '')));
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
    public function compliance(
        array $state,
        ?string $from,
        ?string $to,
        int $limit,
        int $offset,
        ?string $planId = null,
        ?string $market = null
    ): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        [$fromKey, $toKey] = $this->rangeKeys($from, $to);
        $planId = trim((string) $planId);
        $market = strtoupper(trim((string) $market));
        $plansByDate = [];
        foreach (DayPlanRecords::all($state) as $record) {
            if ($planId !== '' && $record['id'] !== $planId) {
                continue;
            }
            if ($market !== '' && !$this->marketSelectorMatches($market, $record['plan'])) {
                continue;
            }
            $plansByDate[$record['date']][] = $record['plan'];
        }
        $byDate = $this->tradesByDate($state);
        $hasPlanFilter = $planId !== '' || $market !== '';
        $dates = $hasPlanFilter
            ? array_keys($plansByDate)
            : array_values(array_unique(array_merge(array_keys($byDate), array_keys($plansByDate))));
        sort($dates);
        $days = [];
        foreach ($dates as $date) {
            if ($fromKey !== null && $date < $fromKey) {
                continue;
            }
            if ($toKey !== null && $date > $toKey) {
                continue;
            }
            $dayTrades = $byDate[$date] ?? [];
            $dayPlans = $plansByDate[$date] ?? [];
            if ($hasPlanFilter && $dayPlans === []) {
                continue;
            }
            if ($dayPlans === [] && $dayTrades === []) {
                continue;
            }
            if ($dayPlans === []) {
                $days[] = $this->complianceForDay(null, $date, $dayTrades, $state);
                continue;
            }

            $assignedIds = [];
            foreach ($dayPlans as $plan) {
                $assigned = $this->tradesForPlan($state, $date, $dayTrades, $plan);
                foreach ($assigned as $trade) {
                    $assignedIds[(string) ($trade['id'] ?? '')] = true;
                }
                $record = $this->complianceForDay($plan, $date, $assigned, $state);
                $record['planId'] = (string) ($plan['id'] ?? $date);
                $days[] = $record;
            }
            $unassigned = array_values(array_filter(
                $dayTrades,
                static fn (array $trade): bool => !isset($assignedIds[(string) ($trade['id'] ?? '')])
            ));
            if ($unassigned !== [] && !$hasPlanFilter) {
                $record = $this->complianceForDay(null, $date, $unassigned, $state);
                $record['planStatus'] = count($dayPlans) > 1 ? 'ambiguous' : 'none';
                $record['planIds'] = array_values(array_map(static fn (array $plan): string => (string) ($plan['id'] ?? $date), $dayPlans));
                $days[] = $record;
            }
        }
        usort($days, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']) ?: strcmp((string) ($a['planId'] ?? ''), (string) ($b['planId'] ?? '')));
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
            'filters' => [
                'from' => $fromKey,
                'to' => $toKey,
                'planId' => $planId !== '' ? $planId : null,
                'market' => $market !== '' ? $market : null,
            ],
        ];
    }

    /**
     * Pojedynczy dzień: plan, transakcje, limity, zgodność.
     */
    public function day(array $state, string $date, ?string $planId = null, ?string $market = null): array
    {
        if (!Dates::isValidKey($date)) {
            throw new \InvalidArgumentException('date musi mieć format YYYY-MM-DD.');
        }
        $allPlans = $this->plansForDate($state, $date);
        $requestedPlanId = trim((string) $planId);
        $requestedMarket = strtoupper(trim((string) $market));
        $hasPlanFilter = $requestedPlanId !== '' || $requestedMarket !== '';
        $matchingPlans = array_values(array_filter($allPlans, function (array $item) use ($requestedPlanId, $requestedMarket): bool {
            if ($requestedPlanId !== '' && (string) ($item['id'] ?? '') !== $requestedPlanId) {
                return false;
            }
            return $requestedMarket === '' || $this->marketSelectorMatches($requestedMarket, $item);
        }));
        $plan = $this->planFor($state, $date, $market, $planId);
        $trades = $this->tradesByDate($state)[$date] ?? [];
        if ($plan !== null) {
            $selectedTrades = $this->tradesForPlan($state, $date, $trades, $plan);
            $compliance = $this->complianceForDay($plan, $date, $selectedTrades, $state);
            $compliance['planId'] = (string) ($plan['id'] ?? $date);
            $limitTrades = $selectedTrades;
        } elseif ($hasPlanFilter && $matchingPlans === []) {
            $compliance = $this->complianceForDay(null, $date, [], $state);
            $limitTrades = [];
        } else {
            $plansForCompliance = $hasPlanFilter ? $matchingPlans : $allPlans;
            $tradesForCompliance = $trades;
            if ($hasPlanFilter) {
                $assigned = [];
                foreach ($plansForCompliance as $candidate) {
                    foreach ($this->tradesForPlan($state, $date, $trades, $candidate) as $selectedTrade) {
                        $assigned[(string) ($selectedTrade['id'] ?? '')] = $selectedTrade;
                    }
                }
                $tradesForCompliance = array_values($assigned);
            }
            $compliance = $this->complianceForPlans($plansForCompliance, $date, $tradesForCompliance, $state);
            $limitTrades = $tradesForCompliance;
        }

        return [
            'date' => $date,
            'plan' => $plan === null ? null : $this->presentPlan($plan, $date),
            'plans' => array_map(fn (array $item): array => $this->presentPlan($item, $date), $allPlans),
            'trades' => array_map(fn (array $trade): array => $this->presentTrade($trade), $trades),
            'limits' => $this->limits($plan, $state, $limitTrades),
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
        if (!$this->marketMatchesPlan($trade, $plan)) {
            $reasons[] = 'market_not_in_plan';
        }
        $bias = (string) ($plan['bias'] ?? 'none');
        $direction = TradeRules::normalizeDirection($trade['dir'] ?? 'l') === 's' ? 'short' : 'long';
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
        return $this->complianceForPlans($plan === null ? [] : [$plan], $date, $trades, $state);
    }

    /**
     * Calculates a date without assigning a trade to an arbitrary record:
     * multi-plan days require a unique market match.
     *
     * @param list<array> $plans
     */
    private function complianceForPlans(array $plans, string $date, array $trades, array $state): array
    {
        $aligned = [];
        $violating = [];
        $undetermined = 0;
        $rows = [];
        $ruleCounts = [];
        $ordered = $trades;
        usort($ordered, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
        $sequence = [];
        foreach ($ordered as $trade) {
            $plan = $this->planForTradeAmong($plans, $trade);
            $planId = $plan !== null ? (string) ($plan['id'] ?? '') : '';
            $sequence[$planId] = ($sequence[$planId] ?? 0) + 1;
            $alignment = $this->alignment($state, $trade, $plan, $sequence[$planId]);
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
                'planId' => $plan !== null ? $planId : null,
                'date' => (string) ($trade['date'] ?? ''),
                'pair' => (string) ($trade['pair'] ?? ''),
                'direction' => TradeRules::normalizeDirection($trade['dir'] ?? 'l') === 's' ? 'short' : 'long',
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
            'planStatus' => count($plans) > 1 ? 'multiple' : ($plans === [] ? 'none' : $this->status($plans[0])),
            'planIds' => array_values(array_map(static fn (array $plan): string => (string) ($plan['id'] ?? $date), $plans)),
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
        $plan = $this->normalizePlanLevels($plan);
        try {
            $levels = DayPlanLevels::normalizeList((array) ($plan['levels'] ?? []), 12, true, false);
        } catch (\InvalidArgumentException) {
            $levels = array_values(array_filter((array) ($plan['levels'] ?? []), 'is_array'));
        }

        return [
            'id' => (string) ($plan['id'] ?? $date),
            'date' => $date,
            'status' => $this->status($plan),
            'approved' => $this->status($plan) === 'approved',
            'approvedAt' => $plan['approvedAt'] ?? null,
            'markets' => $this->marketsOf($plan),
            'market' => (string) ($plan['market'] ?? ''),
            'bias' => (string) ($plan['bias'] ?? ''),
            'reason' => (string) ($plan['why'] ?? ''),
            'levels' => $levels,
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

    private function normalizePlanLevels(array $plan): array
    {
        try {
            $plan['levels'] = DayPlanLevels::normalizeList((array) ($plan['levels'] ?? []), 12, true, false);
        } catch (\InvalidArgumentException) {
            $plan['levels'] = array_values(array_filter((array) ($plan['levels'] ?? []), 'is_array'));
        }

        return $plan;
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
            'direction' => TradeRules::normalizeDirection($trade['dir'] ?? 'l') === 's' ? 'short' : 'long',
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
            if (!is_array($trade) || !$this->includedForStatistics($state, $trade)) {
                continue;
            }
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $map[$date][] = $trade;
        }

        return $map;
    }

    private function includedForStatistics(array $state, array $trade): bool
    {
        $account = (string) ($trade['account'] ?? '');
        if ($account === '') {
            return true;
        }
        foreach ((array) ($state['accounts'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['name'] ?? '') === $account) {
                return !empty($row['include']);
            }
        }

        return true;
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
