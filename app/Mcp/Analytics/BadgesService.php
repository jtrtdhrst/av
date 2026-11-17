<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;
use AlphaVault\Mcp\Support\PropChallengeService;

/**
 * Odznaki liczone z rzeczywistych danych użytkownika.
 *
 * Każda odznaka ma trzy progi (brąz/srebro/złoto). Postęp to liczba zdarzeń
 * w danych, a data zdobycia to data zdarzenia, które osiągnęło próg. Jeżeli
 * danych o dacie nie ma (np. setup bez createdAt), earnedAt jest null, a nie
 * zmyśloną datą.
 */
final class BadgesService
{
    private const LEVELS = [1 => 'brąz', 2 => 'srebro', 3 => 'złoto'];

    public function __construct(
        private readonly DayPlanService $plans,
        private readonly RiskService $risk,
        private readonly PropChallengeService $prop
    ) {
    }

    public function progress(array $state): array
    {
        $trades = $this->closedTrades($state);
        $dates = array_map(static fn (array $trade): ?string => Dates::dateKey((string) ($trade['date'] ?? '')), $trades);
        $items = [];

        $items[] = $this->badge('first_trade', 'Pierwszy wpis', 'Zamknięta transakcja w dzienniku', [1, 10, 50], $this->milestones($dates, [1, 10, 50]), 'transakcje');
        $items[] = $this->badge('journal', 'Dziennik', 'Zamknięte transakcje w dzienniku', [10, 50, 100], $this->milestones($dates, [10, 50, 100]), 'transakcje');
        $items[] = $this->badge('win_streak', 'Seria zwycięstw', 'Transakcje z rzędu z dodatnim wynikiem', [3, 5, 10], $this->streakMilestones($trades), 'transakcje z rzędu');
        $items[] = $this->badge('plan_streak', 'Plan dnia', 'Dni z rzędu z zatwierdzonym planem', [3, 5, 10], $this->planStreakMilestones($state), 'dni z rzędu');

        $aligned = $this->alignedTrades($state);
        $items[] = $this->badge('plan_adherence', 'Zgodność z planem', 'Transakcje zgodne z regułami planu dnia', [20, 50, 100], $this->milestones(array_map(static fn (array $trade): ?string => Dates::dateKey((string) ($trade['date'] ?? '')), $aligned), [20, 50, 100]), 'transakcje');

        $fullChecklist = array_values(array_filter($trades, static function (array $trade): bool {
            $total = (int) ($trade['checkTotal'] ?? 0);
            return $total > 0 && (int) ($trade['checklist'] ?? 0) >= $total;
        }));
        $items[] = $this->badge('checklist', 'Pełna checklista', 'Transakcje z checklistą wypełnioną w całości', [10, 25, 50], $this->milestones(array_map(static fn (array $trade): ?string => Dates::dateKey((string) ($trade['date'] ?? '')), $fullChecklist), [10, 25, 50]), 'transakcje');

        $gradeA = array_values(array_filter($trades, static fn (array $trade): bool => strtoupper((string) ($trade['grade'] ?? '')) === 'A'));
        $items[] = $this->badge('grade_a', 'Ocena A', 'Transakcje z oceną A', [10, 25, 50], $this->milestones(array_map(static fn (array $trade): ?string => Dates::dateKey((string) ($trade['date'] ?? '')), $gradeA), [10, 25, 50]), 'transakcje');

        $setups = [];
        $setupDates = [];
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (!is_array($setup) || trim((string) ($setup['name'] ?? '')) === '') {
                continue;
            }
            $setups[] = $setup;
            $setupDates[] = isset($setup['createdAt']) ? Dates::dateKey((string) $setup['createdAt']) : null;
        }
        $items[] = $this->badge('setups', 'Playbook', 'Setupy zapisane w Playbooku', [3, 5, 8], $this->milestones($setupDates, [3, 5, 8]), 'setupy');

        $items[] = $this->badge('discipline', 'Dyscyplina', 'Dni handlowe bez naruszenia limitu strat', [5, 15, 30], $this->milestones($this->cleanDays($state), [5, 15, 30]), 'dni');

        $passed = [];
        $challenges = is_array($state['prop']['challenges'] ?? null) ? $state['prop']['challenges'] : [];
        $accountNames = [];
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (is_array($account) && (string) ($account['name'] ?? '') !== '') {
                $accountNames[] = (string) $account['name'];
            }
        }
        foreach ($challenges as $challenge) {
            if (!is_array($challenge)) {
                continue;
            }
            $metrics = $this->prop->compute($challenge, (array) ($state['trades'] ?? []), null, $accountNames);
            if ($metrics['status'] === 'passed') {
                $passed[] = (string) ($metrics['endDate'] !== '' ? $metrics['endDate'] : $metrics['todayKey']);
            }
        }
        $items[] = $this->badge('prop_passed', 'Prop challenge', 'Zaliczone challenge\'e', [1, 2, 3], $this->milestones($passed, [1, 2, 3]), 'challenge');

        $levels = 0;
        $earned = 0;
        foreach ($items as $item) {
            $levels += $item['level'];
            if ($item['level'] > 0) {
                $earned++;
            }
        }
        $maxLevels = count($items) * 3;

        return [
            'hasData' => $trades !== [] || $setups !== [] || $challenges !== [],
            'sampleSize' => count($trades),
            'items' => $items,
            'summary' => [
                'badges' => count($items),
                'earned' => $earned,
                'levels' => $levels,
                'maxLevels' => $maxLevels,
                'completionPct' => $maxLevels > 0 ? Numbers::round($levels / $maxLevels * 100) : null,
            ],
            'currency' => Metrics::CURRENCY,
            'note' => 'Odznaki liczone są z danych w AlphaVault. Data zdobycia to data zdarzenia osiągającego próg; przy braku daty w danych earnedAt jest null.',
        ];
    }

    private function badge(string $id, string $name, string $description, array $goals, array $progress, string $unit): array
    {
        $value = (int) ($progress['value'] ?? 0);
        $dates = is_array($progress['milestones'] ?? null) ? $progress['milestones'] : [];
        $level = 0;
        $earnedAt = null;
        $tiers = [];
        foreach ($goals as $index => $goal) {
            $achieved = $value >= $goal;
            $tierLevel = $index + 1;
            $date = $achieved ? ($dates[$goal] ?? null) : null;
            if ($achieved) {
                $level = $tierLevel;
                $earnedAt = $date;
            }
            $tiers[] = [
                'level' => $tierLevel,
                'levelLabel' => self::LEVELS[$tierLevel],
                'goal' => $goal,
                'achieved' => $achieved,
                'earnedAt' => $date,
            ];
        }
        $next = $level < 3 ? $goals[$level] : null;
        $progressPct = $next !== null ? min(100, Numbers::round($value / $next * 100)) : 100.0;

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'condition' => 'Progi: ' . implode(' / ', $goals) . ' (' . $unit . ').',
            'value' => $value,
            'goal' => $next,
            'unit' => $unit,
            'progressPct' => $value === 0 ? 0.0 : $progressPct,
            'level' => $level,
            'levelLabel' => $level > 0 ? self::LEVELS[$level] : null,
            'status' => $level > 0 ? 'earned' : ($value > 0 ? 'in_progress' : 'not_started'),
            'earnedAt' => $earnedAt,
            'tiers' => $tiers,
        ];
    }

    /**
     * Licznik zdarzeń: wartość = liczba zdarzeń, data progu = data N-tego zdarzenia.
     */
    private function milestones(array $dates, array $goals): array
    {
        $clean = array_values(array_filter($dates, static fn ($date): bool => is_string($date) && $date !== ''));
        sort($clean);
        $milestones = [];
        foreach ($goals as $goal) {
            $milestones[$goal] = $clean[$goal - 1] ?? null;
        }

        return ['value' => count($clean), 'milestones' => $milestones];
    }

    private function streakMilestones(array $trades): array
    {
        usort($trades, static fn (array $a, array $b): int => (strtotime((string) ($a['date'] ?? '')) ?: 0) <=> (strtotime((string) ($b['date'] ?? '')) ?: 0));
        $milestones = [3 => null, 5 => null, 10 => null];
        $streak = 0;
        $max = 0;
        foreach ($trades as $trade) {
            $result = (float) ($trade['result'] ?? 0);
            if ($result > 0) {
                $streak++;
                $max = max($max, $streak);
                if (isset($milestones[$streak]) && $milestones[$streak] === null) {
                    $milestones[$streak] = Dates::dateKey((string) ($trade['date'] ?? ''));
                }
            } else {
                $streak = 0;
            }
        }

        return ['value' => $max, 'milestones' => $milestones];
    }

    private function planStreakMilestones(array $state): array
    {
        $approvedDates = [];
        foreach ($this->plans->plans($state) as $plan) {
            $date = (string) ($plan['date'] ?? '');
            if ($date !== '' && $this->plans->status($plan) === 'approved') {
                $approvedDates[$date] = true;
            }
        }
        $dates = array_keys($approvedDates);
        sort($dates);
        $milestones = [3 => null, 5 => null, 10 => null];
        $streak = 0;
        $max = 0;
        $previous = null;
        foreach ($dates as $date) {
            $current = new \DateTimeImmutable($date, Dates::tz());
            if ($previous instanceof \DateTimeImmutable) {
                $gap = (int) $previous->diff($current)->days;
                $streak = $gap === 1 ? $streak + 1 : 1;
            } else {
                $streak = 1;
            }
            $max = max($max, $streak);
            if (isset($milestones[$streak]) && $milestones[$streak] === null) {
                $milestones[$streak] = $date;
            }
            $previous = $current;
        }

        return ['value' => $max, 'milestones' => $milestones];
    }

    /**
     * @return list<array>
     */
    private function alignedTrades(array $state): array
    {
        $aligned = [];
        $byDate = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade) || ($trade['result'] ?? null) === null) {
                continue;
            }
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $byDate[$date][] = $trade;
        }
        ksort($byDate);
        foreach ($byDate as $date => $trades) {
            usort($trades, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
            foreach ($trades as $trade) {
                $plan = $this->plans->planForTrade($state, $trade);
                $sequence = $this->plans->tradeSequence($state, $trade, $plan);
                $alignment = $this->plans->alignment($state, $trade, $plan, $sequence);
                if ($alignment['determined'] && $alignment['aligned']) {
                    $aligned[] = $trade;
                }
            }
        }

        return $aligned;
    }

    /**
     * Dni handlowe bez naruszenia limitu strat i bez handlu po blokadzie.
     */
    private function cleanDays(array $state): array
    {
        $violations = $this->risk->violations($state, null, null, 200, 0);
        $dirty = [];
        foreach ($violations['items'] as $violation) {
            if (in_array($violation['rule'], ['traded_after_lock', 'daily_loss_limit'], true)) {
                $dirty[$violation['date']] = true;
            }
        }
        $days = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null || isset($dirty[$date])) {
                continue;
            }
            $days[$date] = true;
        }
        $clean = array_keys($days);
        sort($clean);

        return $clean;
    }

    private function closedTrades(array $state): array
    {
        $trades = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade) || ($trade['result'] ?? null) === null) {
                continue;
            }
            $account = (string) ($trade['account'] ?? '');
            if ($account !== '') {
                foreach ((array) ($state['accounts'] ?? []) as $row) {
                    if (is_array($row) && (string) ($row['name'] ?? '') === $account && empty($row['include'])) {
                        continue 2;
                    }
                }
            }
            $trades[] = $trade;
        }

        return $trades;
    }
}
