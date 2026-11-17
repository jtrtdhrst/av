<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;

/**
 * Zasady ryzyka, wyłącznik bezpieczeństwa i wykrywanie naruszeń.
 *
 * Tryb ochronny nie jest zapisanym stanem — wynika z ustawień użytkownika,
 * dzisiejszych transakcji i obejść, tak samo jak w panelu AlphaVault.
 */
final class RiskService
{
    private const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public function __construct(private readonly DayPlanService $plans)
    {
    }

    public function rules(array $state): array
    {
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $capital = $this->plans->capital($state);
        $maxRisk = Numbers::pnum($settings['maxRisk'] ?? 1);
        if ($maxRisk <= 0) {
            $maxRisk = 1.0;
        }
        $maxDailyLoss = Numbers::pnum($settings['maxDailyLoss'] ?? 3);
        if ($maxDailyLoss <= 0) {
            $maxDailyLoss = 3.0;
        }
        $maxLosses = (int) ($settings['maxLosses'] ?? 2);
        if ($maxLosses < 1) {
            $maxLosses = 2;
        }

        return [
            'hasData' => $settings !== [],
            'maxRiskPercentPerTrade' => Numbers::round($maxRisk, 2),
            'maxConsecutiveLossesPerDay' => $maxLosses,
            'maxDailyLossPercent' => Numbers::round($maxDailyLoss, 2),
            'dailyLossLimitAmount' => $capital > 0 ? Numbers::round($capital * $maxDailyLoss / 100) : null,
            'capital' => $capital > 0 ? Numbers::round($capital) : null,
            'currency' => Metrics::CURRENCY,
            'safetySwitch' => [
                'enabled' => ($settings['safetyEnabled'] ?? true) !== false,
                'blockDuration' => $this->blockDuration($settings),
                'requireReasonForOverride' => ($settings['requireReason'] ?? true) !== false,
                'notifyOnTrigger' => ($settings['pushLimit'] ?? true) !== false,
            ],
            'rules' => array_values(array_filter(array_map('strval', is_array($settings['rules'] ?? null) ? $settings['rules'] : []), static fn (string $rule): bool => trim($rule) !== '')),
            'checklist' => array_values(array_filter(array_map('strval', is_array($settings['checklist'] ?? null) ? $settings['checklist'] : []), static fn (string $item): bool => trim($item) !== '')),
            'note' => 'Zasady pochodzą z ustawień AlphaVault. MCP nie zmienia ich bez propozycji i zgody użytkownika.',
        ];
    }

    public function protection(array $state): array
    {
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $today = Dates::todayKey();
        $todayTrades = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            if (Dates::dateKey((string) ($trade['date'] ?? '')) !== $today) {
                continue;
            }
            if (!$this->accountIncluded($state, (string) ($trade['account'] ?? ''))) {
                continue;
            }
            $todayTrades[] = $trade;
        }
        $closed = Metrics::closed($todayTrades);
        $pnl = 0.0;
        $losses = 0;
        $lastLossAt = null;
        foreach ($closed as $trade) {
            $result = (float) $trade['result'];
            $pnl += $result;
            if ($result < 0) {
                $losses++;
                $lastLossAt = (string) ($trade['date'] ?? '');
            }
        }
        $capital = $this->plans->capital($state);
        $capitalForPct = $capital > 0 ? $capital : 1.0;
        $lossPct = max(0.0, -min(0.0, $pnl)) / $capitalForPct * 100;
        $limit = max(1, (int) ($settings['maxLosses'] ?? 2));
        $dailyLimit = max(0.1, Numbers::pnum($settings['maxDailyLoss'] ?? 3));
        $safetyEnabled = ($settings['safetyEnabled'] ?? true) !== false;
        $triggered = $safetyEnabled && ($losses >= $limit || $lossPct >= $dailyLimit);
        $until = null;
        $triggeredAt = null;
        $reason = null;
        if ($triggered) {
            $base = $lastLossAt !== null ? (strtotime($lastLossAt) ?: time()) : time();
            $block = $this->blockDuration($settings);
            if ($block === '1h') {
                $until = $base + 3600;
            } elseif ($block === '4h') {
                $until = $base + 4 * 3600;
            } else {
                $until = (new \DateTimeImmutable('tomorrow', Dates::tz()))->setTime(0, 0)->getTimestamp();
            }
            if ($until <= time()) {
                $until = (new \DateTimeImmutable('tomorrow', Dates::tz()))->setTime(0, 0)->getTimestamp();
            }
            $triggeredAt = $lastLossAt;
            $reason = $losses >= $limit ? 'limit strat' : 'limit straty dziennej';
        }
        $overrides = [];
        foreach ((array) ($state['overrides'] ?? []) as $override) {
            if (!is_array($override)) {
                continue;
            }
            if ((string) ($override['date'] ?? '') !== $today) {
                continue;
            }
            $overrides[] = [
                'at' => isset($override['at']) ? (int) $override['at'] : null,
                'atIso' => isset($override['at']) ? gmdate('Y-m-d\TH:i:s\Z', (int) floor(((int) $override['at']) / 1000)) : null,
                'reason' => trim((string) ($override['reason'] ?? '')),
            ];
        }
        usort($overrides, static fn (array $a, array $b): int => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
        $latest = $overrides[0] ?? null;
        $overridden = false;
        if ($latest !== null) {
            if (!$triggered) {
                $overridden = true;
            } elseif ($until !== null && $latest['at'] !== null && $latest['at'] >= ($until - 86400) * 1000) {
                $overridden = true;
            }
        }
        $active = $triggered && !$overridden;

        return [
            'active' => $active,
            'triggered' => $triggered,
            'overridden' => $overridden,
            'reason' => $reason,
            'triggeredAt' => $triggeredAt !== null && $triggeredAt !== '' ? $this->iso($triggeredAt) : null,
            'blockedUntil' => $until !== null ? gmdate('Y-m-d\TH:i:s\Z', $until) : null,
            'blockedUntilLocal' => $until !== null ? (new \DateTimeImmutable('@' . $until))->setTimezone(Dates::tz())->format('Y-m-d\TH:i:sP') : null,
            'blockDuration' => $this->blockDuration($settings),
            'losses' => $losses,
            'lossLimit' => $limit,
            'dailyPnl' => Numbers::round($pnl),
            'dailyLossPercent' => Numbers::round($lossPct, 2),
            'dailyLossLimitPercent' => Numbers::round($dailyLimit, 2),
            'capital' => $capital > 0 ? Numbers::round($capital) : null,
            'openPositions' => count($todayTrades) - count($closed),
            'overridesToday' => $overrides,
            'overrideReason' => $latest['reason'] ?? null,
            'date' => $today,
            'currency' => Metrics::CURRENCY,
            'note' => $active
                ? 'Dziennik jest w trybie ochronnym. MCP nie zdejmuje blokady; może tylko pokazać status i zasady.'
                : 'Tryb ochronny nie jest aktywny.',
        ];
    }

    public function violations(array $state, ?string $from, ?string $to, int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $rules = $this->rules($state);
        $maxRisk = (float) ($rules['maxRiskPercentPerTrade'] ?? 1.0);
        $dailyLimit = (float) ($rules['maxDailyLossPercent'] ?? 3.0);
        $lossLimit = (int) ($rules['maxConsecutiveLossesPerDay'] ?? 2);
        $capital = (float) ($rules['capital'] ?? 0.0);
        $window = Metrics::window($state, $from, $to, ['analysis' => 'risk-violations']);
        $items = [];

        $byDate = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            if (!$this->accountIncluded($state, (string) ($trade['account'] ?? ''))) {
                continue;
            }
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $byDate[$date][] = $trade;
        }
        $allowed = [];
        foreach ($window['trades'] as $trade) {
            $allowed[(string) ($trade['id'] ?? '')] = true;
        }
        ksort($byDate);
        foreach ($byDate as $date => $trades) {
            if ($window['range']['from'] !== null && $date < $window['range']['from']) {
                continue;
            }
            if ($window['range']['to'] !== null && $date > $window['range']['to']) {
                continue;
            }
            usort($trades, static fn (array $a, array $b): int => (strtotime((string) ($a['date'] ?? '')) ?: 0) <=> (strtotime((string) ($b['date'] ?? '')) ?: 0));
            $dayPnl = 0.0;
            $dayLosses = 0;
            $locked = false;
            $sequences = [];
            foreach ($trades as $trade) {
                $plan = $this->plans->planForTrade($state, $trade);
                $planId = $plan !== null ? (string) ($plan['id'] ?? '') : '';
                $sequences[$planId] = ($sequences[$planId] ?? 0) + 1;
                $id = (string) ($trade['id'] ?? '');
                if (!isset($allowed[$id])) {
                    continue;
                }
                $risk = $this->plans->riskAmount($trade);
                if ($risk !== null && $capital > 0) {
                    $pct = $risk / $capital * 100;
                    if ($pct > $maxRisk + 0.0001) {
                        $items[] = $this->violation(
                            $date,
                            $id,
                            'risk_per_trade',
                            'Ryzyko na transakcję',
                            $maxRisk . '%',
                            Numbers::round($pct, 2) . '%',
                            $pct >= $maxRisk * 2 ? 'high' : 'medium',
                            'Ryzyko ' . Numbers::round($pct, 2) . '% kapitału przy limicie ' . $maxRisk . '%.',
                            ['riskAmount' => $risk, 'capital' => $capital]
                        );
                    }
                }
                if ($risk === null) {
                    $items[] = $this->violation(
                        $date,
                        $id,
                        'missing_stop_loss',
                        'Stop loss',
                        'stop loss ustawiony',
                        'brak stop lossa',
                        'high',
                        'Transakcja bez stop lossa.',
                        []
                    );
                }
                $checkTotal = (int) ($trade['checkTotal'] ?? 0);
                $checklist = (int) ($trade['checklist'] ?? 0);
                if ($checkTotal > 0 && $checklist < $checkTotal) {
                    $items[] = $this->violation(
                        $date,
                        $id,
                        'checklist_incomplete',
                        'Checklista wejścia',
                        $checkTotal . '/' . $checkTotal,
                        $checklist . '/' . $checkTotal,
                        $checklist <= $checkTotal / 2 ? 'medium' : 'low',
                        'Checklista wypełniona w ' . $checklist . ' z ' . $checkTotal . ' punktów.',
                        []
                    );
                }
                $alignment = $this->plans->alignment($state, $trade, $plan, $sequences[$planId]);
                if ($alignment['determined'] && !$alignment['aligned']) {
                    foreach ($alignment['reasons'] as $reason) {
                        $items[] = $this->violation(
                            $date,
                            $id,
                            $reason,
                            'Zgodność z planem dnia',
                            'transakcja zgodna z planem',
                            $reason,
                            'medium',
                            'Transakcja poza regułami planu: ' . $reason . '.',
                            []
                        );
                    }
                }
                if ($locked) {
                    $items[] = $this->violation(
                        $date,
                        $id,
                        'traded_after_lock',
                        'Handel w trybie ochronnym',
                        'brak transakcji po blokadzie',
                        'transakcja po blokadzie',
                        'critical',
                        'Transakcja otwarta po osiągnięciu dziennego limitu strat.',
                        []
                    );
                }
                if (($trade['result'] ?? null) !== null) {
                    $result = (float) $trade['result'];
                    $dayPnl += $result;
                    if ($result < 0) {
                        $dayLosses++;
                    }
                    $lossPct = max(0.0, -min(0.0, $dayPnl)) / ($capital > 0 ? $capital : 1.0) * 100;
                    if (!$locked && ($dayLosses >= $lossLimit || $lossPct >= $dailyLimit)) {
                        $locked = true;
                    }
                }
            }
        }

        $severity = self::SEVERITY_ORDER;
        usort($items, static function (array $a, array $b) use ($severity): int {
            return ($severity[$a['severity']] ?? 9) <=> ($severity[$b['severity']] ?? 9)
                ?: strcmp($b['date'], $a['date']);
        });
        $counts = [];
        foreach ($items as $item) {
            $counts[$item['rule']] = ($counts[$item['rule']] ?? 0) + 1;
        }
        arsort($counts);
        $byRule = [];
        foreach ($counts as $rule => $count) {
            $byRule[] = ['rule' => $rule, 'count' => $count];
        }
        $total = count($items);
        $page = array_slice($items, $offset, $limit);

        return [
            'hasData' => $items !== [],
            'sampleSize' => count($window['trades']),
            'items' => $page,
            'total' => $total,
            'nextOffset' => ($offset + count($page)) < $total ? $offset + count($page) : null,
            'byRule' => $byRule,
            'severityCounts' => $this->severityCounts($items),
            'rules' => $rules,
            'range' => $window['range'],
            'filters' => $window['filters'],
            'currency' => Metrics::CURRENCY,
            'note' => 'Naruszenia liczone z reguł użytkownika i planu dnia. Limit dzienny jest liczony od aktualnego kapitału, więc dla historii jest przybliżeniem.',
        ];
    }

    private function violation(string $date, ?string $tradeId, string $rule, string $label, string $expected, string $actual, string $severity, string $message, array $evidence): array
    {
        return [
            'date' => $date,
            'tradeId' => $tradeId,
            'rule' => $rule,
            'label' => $label,
            'expected' => $expected,
            'actual' => $actual,
            'severity' => $severity,
            'message' => $message,
            'evidence' => $evidence,
        ];
    }

    private function severityCounts(array $items): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($items as $item) {
            $counts[$item['severity']] = ($counts[$item['severity']] ?? 0) + 1;
        }

        return $counts;
    }

    private function blockDuration(array $settings): string
    {
        $block = (string) ($settings['block'] ?? 'midnight');

        return in_array($block, ['1h', '4h'], true) ? $block : 'midnight';
    }

    private function accountIncluded(array $state, string $account): bool
    {
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

    private function iso(string $value): ?string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
