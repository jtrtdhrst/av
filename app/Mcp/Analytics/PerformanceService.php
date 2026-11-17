<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;

/**
 * Raporty wydajności i wykrywanie wzorców.
 *
 * Wszystkie metryki pochodzą z Metrics, więc sumy per grupa zgadzają się
 * z get_stats. Brak próby daje null i sampleSize=0, nigdy fałszywe zero.
 */
final class PerformanceService
{
    private const MIN_SAMPLE = 3;
    private const MAX_BUCKETS = 40;

    /** Pary instrumentów klasyfikowane jako krypto, gdy brak konta. */
    private const CRYPTO_QUOTES = ['USDT', 'USDC', 'BUSD', 'USD', 'BTC', 'ETH'];
    private const INDEX_SYMBOLS = ['NAS100', 'US500', 'US30', 'GER40', 'UK100', 'JPN225', 'SPX', 'NDX', 'DAX', 'DJI', 'FTSE', 'NIKKEI'];
    private const COMMODITY_SYMBOLS = ['XAU', 'XAG', 'XPT', 'OIL', 'WTI', 'BRENT', 'NG', 'GOLD', 'SILVER'];
    private const FOREX_CODES = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'NZD', 'CAD'];

    public function __construct(private readonly DayPlanService $plans, private readonly RiskService $risk)
    {
    }

    public function bySetup(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['groupBy' => 'setup']);
        $playbook = [];
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (is_array($setup)) {
                $playbook[(string) ($setup['name'] ?? '')] = $setup;
            }
        }
        $rows = Metrics::group($window['trades'], static function (array $trade): ?string {
            $value = trim((string) ($trade['setup'] ?? ''));
            return $value === '' ? null : $value;
        }, 'setup');
        foreach ($rows as &$row) {
            $name = (string) ($row['setup'] ?? '');
            $row['inPlaybook'] = $name !== '' && isset($playbook[$name]);
            $row['playbookGrade'] = $row['inPlaybook'] ? ($playbook[$name]['grade'] ?? null) : null;
            $row['timeframe'] = $row['inPlaybook'] ? ($playbook[$name]['tf'] ?? null) : null;
            $row['markedAvoid'] = $row['inPlaybook'] ? (bool) ($playbook[$name]['bad'] ?? false) : false;
            $row['lowSample'] = $row['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
            $row['maxDrawdown'] = $this->drawdown(
                array_values(array_filter($window['trades'], static fn (array $t): bool => (string) ($t['setup'] ?? '') === $name))
            );
        }
        unset($row);

        return $this->report($rows, 'setup', $window, $minSample);
    }

    public function byEmotion(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['groupBy' => 'emotion']);
        $rows = Metrics::group($window['trades'], static function (array $trade): ?string {
            $value = trim((string) ($trade['emotion'] ?? ''));
            return $value === '' ? null : $value;
        }, 'emotion');
        foreach ($rows as &$row) {
            $row['lowSample'] = $row['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
        }
        unset($row);

        return $this->report($rows, 'emotion', $window, $minSample);
    }

    public function bySession(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['groupBy' => 'session']);
        $rows = Metrics::group($window['trades'], static function (array $trade): ?string {
            $value = trim((string) ($trade['session'] ?? ''));
            return $value === '' ? null : $value;
        }, 'session');
        $hours = Metrics::group($window['trades'], function (array $trade): ?string {
            $local = Dates::localParts((string) ($trade['date'] ?? ''));
            if ($local['time'] === null) {
                return null;
            }
            $hour = (int) substr($local['time'], 0, 2);

            return $this->hourBucket($hour);
        }, 'hourBucket');
        foreach ($rows as &$row) {
            $row['lowSample'] = $row['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
        }
        unset($row);
        foreach ($hours as &$row) {
            $row['lowSample'] = $row['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
        }
        unset($row);
        $report = $this->report($rows, 'session', $window, $minSample);
        $report['byHour'] = $hours;
        $report['timezone'] = Dates::TZ;

        return $report;
    }

    public function byError(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['groupBy' => 'error']);
        $overall = Metrics::summarize($window['trades']);
        $buckets = [];
        $noError = [];
        foreach ($window['trades'] as $trade) {
            $errors = array_values(array_filter(array_map('strval', is_array($trade['errors'] ?? null) ? $trade['errors'] : [])));
            if ($errors === []) {
                $noError[] = $trade;
                continue;
            }
            foreach (array_unique($errors) as $error) {
                $buckets[$error][] = $trade;
            }
        }
        $rows = [];
        $grossLoss = (float) ($overall['grossLoss'] ?? 0.0);
        $net = (float) ($overall['net'] ?? 0.0);
        foreach ($buckets as $error => $items) {
            $metrics = Metrics::summarize($items);
            $metrics['error'] = $error;
            $metrics['cost'] = $metrics['net'];
            $metrics['shareOfGrossLossPct'] = $grossLoss > 0 && $metrics['net'] !== null && $metrics['net'] < 0
                ? Numbers::round(abs($metrics['net']) / $grossLoss * 100)
                : null;
            $metrics['shareOfNetPct'] = $net !== 0.0 && $metrics['net'] !== null
                ? Numbers::round($metrics['net'] / abs($net) * 100)
                : null;
            $metrics['lowSample'] = $metrics['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
            $rows[] = $metrics;
        }
        usort($rows, static fn (array $a, array $b): int => ($a['net'] ?? PHP_INT_MAX) <=> ($b['net'] ?? PHP_INT_MAX));

        return [
            'hasData' => $rows !== [],
            'sampleSize' => count($window['trades']),
            'items' => array_slice($rows, 0, self::MAX_BUCKETS),
            'tradesWithoutError' => Metrics::summarize($noError),
            'overall' => $overall,
            'range' => $window['range'],
            'filters' => $window['filters'],
            'currency' => Metrics::CURRENCY,
            'note' => 'Jedna transakcja może mieć wiele błędów, więc sumy kubełków nie sumują się do wyniku całkowitego. Koszt błędu to wynik netto transakcji z tym błędem, nie utracony potencjalny zysk.',
        ];
    }

    public function byGrade(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['groupBy' => 'grade']);
        $rows = [];
        $weights = ['A' => 1.0, 'B' => 0.5, 'C' => 0.0];
        foreach (['A', 'B', 'C'] as $grade) {
            $items = array_values(array_filter($window['trades'], static fn (array $trade): bool => strtoupper((string) ($trade['grade'] ?? '')) === $grade));
            $metrics = Metrics::summarize($items);
            $metrics['grade'] = $grade;
            $metrics['lowSample'] = $metrics['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
            $rows[] = $metrics;
        }
        $ungraded = array_values(array_filter($window['trades'], static fn (array $trade): bool => !in_array(strtoupper((string) ($trade['grade'] ?? '')), ['A', 'B', 'C'], true)));
        $qualityIndex = null;
        $gradedCount = 0;
        $qualitySum = 0.0;
        foreach ($window['trades'] as $trade) {
            $grade = strtoupper((string) ($trade['grade'] ?? ''));
            if (!isset($weights[$grade])) {
                continue;
            }
            $gradedCount++;
            $qualitySum += $weights[$grade] * 100;
        }
        if ($gradedCount > 0) {
            $qualityIndex = Numbers::round($qualitySum / $gradedCount);
        }

        return [
            'hasData' => $gradedCount > 0,
            'sampleSize' => $gradedCount,
            'items' => $rows,
            'ungraded' => Metrics::summarize($ungraded),
            'gradeQualityIndex' => $qualityIndex,
            'gradeQualityIndexNote' => 'Indeks jakości ocen: A=100, B=50, C=0, średnia ważona liczbą transakcji. Nie jest miarą wyniku finansowego.',
            'range' => $window['range'],
            'filters' => $window['filters'],
            'currency' => Metrics::CURRENCY,
        ];
    }

    public function byMarket(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['groupBy' => 'market']);
        $classes = [];
        $pairs = [];
        foreach ($window['trades'] as $trade) {
            $class = $this->classify($trade, $state);
            $classes[$class][] = $trade;
            $pair = strtoupper(trim((string) ($trade['pair'] ?? '')));
            $pairs[$pair === '' ? '__none__' : $pair][] = $trade;
        }
        $classRows = [];
        foreach ($classes as $class => $items) {
            $metrics = Metrics::summarize($items);
            $metrics['marketClass'] = $class;
            $metrics['lowSample'] = $metrics['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
            $classRows[] = $metrics;
        }
        usort($classRows, static fn (array $a, array $b): int => ($b['net'] ?? PHP_INT_MIN) <=> ($a['net'] ?? PHP_INT_MIN));
        $pairRows = [];
        foreach ($pairs as $pair => $items) {
            $metrics = Metrics::summarize($items);
            $metrics['pair'] = $pair === '__none__' ? null : $pair;
            $metrics['marketClass'] = $this->classify($items[0], $state);
            $metrics['lowSample'] = $metrics['sampleSize'] < max(self::MIN_SAMPLE, $minSample);
            $pairRows[] = $metrics;
        }
        usort($pairRows, static fn (array $a, array $b): int => ($b['net'] ?? PHP_INT_MIN) <=> ($a['net'] ?? PHP_INT_MIN));

        return [
            'hasData' => $classRows !== [],
            'sampleSize' => count($window['trades']),
            'byClass' => $classRows,
            'byPair' => array_slice($pairRows, 0, self::MAX_BUCKETS),
            'range' => $window['range'],
            'filters' => $window['filters'],
            'currency' => Metrics::CURRENCY,
            'note' => 'Klasa rynku wynika z pary i konta (krypto, forex, indeksy, surowce, akcje, inne). Nieznane instrumenty trafiają do „other”.',
        ];
    }

    public function byPlanCompliance(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['groupBy' => 'dayPlanCompliance']);
        $aligned = [];
        $violating = [];
        $undetermined = [];
        $byDate = [];
        foreach ($window['trades'] as $trade) {
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null) {
                $undetermined[] = $trade;
                continue;
            }
            $byDate[$date][] = $trade;
        }
        $reasons = [];
        foreach ($byDate as $date => $dayTrades) {
            usort($dayTrades, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
            foreach ($dayTrades as $trade) {
                $plan = $this->plans->planForTrade($state, $trade);
                $sequence = $this->plans->tradeSequence($state, $trade, $plan, true);
                $alignment = $this->plans->alignment($state, $trade, $plan, $sequence);
                if (!$alignment['determined']) {
                    $undetermined[] = $trade;
                    continue;
                }
                if ($alignment['aligned']) {
                    $aligned[] = $trade;
                } else {
                    $violating[] = $trade;
                    foreach ($alignment['reasons'] as $reason) {
                        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($reasons);
        $reasonRows = [];
        foreach ($reasons as $rule => $count) {
            $reasonRows[] = ['rule' => $rule, 'count' => $count];
        }
        $determined = count($aligned) + count($violating);

        return [
            'hasData' => $determined > 0,
            'sampleSize' => $determined,
            'determinedTrades' => $determined,
            'undeterminedTrades' => count($undetermined),
            'compliancePct' => $determined > 0 ? Numbers::round(count($aligned) / $determined * 100) : null,
            'aligned' => Metrics::summarize($aligned),
            'violating' => Metrics::summarize($violating),
            'undetermined' => Metrics::summarize($undetermined),
            'rulesViolated' => $reasonRows,
            'lowSample' => $determined < max(self::MIN_SAMPLE, $minSample),
            'range' => $window['range'],
            'filters' => $window['filters'],
            'currency' => Metrics::CURRENCY,
            'note' => 'Uwzględniane są wyłącznie transakcje, dla których zgodność da się ustalić: plan zatwierdzony i kierunek określony. Brak planu to „nie da się ustalić”, nie niezgodność.',
        ];
    }

    /**
     * Powtarzające się wzorce. Każdy wpis ma dowody, próbę, zakres i pewność.
     */
    public function patterns(array $state, ?string $from, ?string $to, int $minSample): array
    {
        $window = Metrics::window($state, $from, $to, ['analysis' => 'patterns']);
        $trades = $window['trades'];
        $min = max(self::MIN_SAMPLE, $minSample);
        $items = [];

        $byError = [];
        foreach ($trades as $trade) {
            foreach (array_unique(array_map('strval', is_array($trade['errors'] ?? null) ? $trade['errors'] : [])) as $error) {
                $byError[$error][] = $trade;
            }
        }
        uasort($byError, static fn (array $a, array $b): int => count($b) <=> count($a));
        foreach ($byError as $error => $itemsWithError) {
            if (count($itemsWithError) < $min) {
                continue;
            }
            $metrics = Metrics::summarize($itemsWithError);
            $items[] = $this->pattern(
                'repeated_error',
                'Powtarzalny błąd: ' . $error,
                'Błąd „' . $error . '” wystąpił w ' . count($itemsWithError) . ' transakcjach, wynik netto ' . $metrics['net'] . ' ' . Metrics::CURRENCY . '.',
                ['error' => $error, 'trades' => count($itemsWithError), 'net' => $metrics['net'], 'winRate' => $metrics['winRate']],
                count($itemsWithError),
                $window['range'],
                $metrics
            );
        }

        $sorted = $trades;
        usort($sorted, static fn (array $a, array $b): int => (strtotime((string) ($a['date'] ?? '')) ?: 0) <=> (strtotime((string) ($b['date'] ?? '')) ?: 0));
        $afterLoss = [];
        $previous = null;
        foreach ($sorted as $trade) {
            if ($previous !== null && (float) ($previous['result'] ?? 0) < 0) {
                $gap = (strtotime((string) ($trade['date'] ?? '')) ?: 0) - (strtotime((string) ($previous['date'] ?? '')) ?: 0);
                if ($gap >= 0 && $gap <= 1800) {
                    $afterLoss[] = $trade;
                }
            }
            $previous = $trade;
        }
        if (count($afterLoss) >= $min) {
            $metrics = Metrics::summarize($afterLoss);
            $items[] = $this->pattern(
                'trade_after_loss',
                'Handel do 30 minut po stracie',
                count($afterLoss) . ' transakcji otwarto w ciągu 30 minut po transakcji stratnej. Wynik netto tej grupy: ' . $metrics['net'] . ' ' . Metrics::CURRENCY . '.',
                ['windowMinutes' => 30, 'trades' => count($afterLoss), 'net' => $metrics['net'], 'winRate' => $metrics['winRate']],
                count($afterLoss),
                $window['range'],
                $metrics
            );
        }

        $perPlan = [];
        $planById = [];
        foreach ($trades as $trade) {
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $plan = $this->plans->planForTrade($state, $trade);
            if ($plan === null) {
                continue;
            }
            $planId = (string) ($plan['id'] ?? $date);
            $key = $date . '#' . $planId;
            $perPlan[$key] = ($perPlan[$key] ?? 0) + 1;
            $planById[$key] = $plan;
        }
        $overPlan = [];
        foreach ($perPlan as $key => $count) {
            [$date, $planId] = explode('#', $key, 2);
            $plan = $planById[$key] ?? null;
            $maxTrades = $plan !== null ? $this->plans->planMaxTrades($plan) : null;
            if ($maxTrades !== null && $count > $maxTrades) {
                $overPlan[$key] = ['date' => $date, 'planId' => $planId, 'count' => $count, 'limit' => $maxTrades];
            }
        }
        $overPlanDates = array_values(array_unique(array_column(array_values($overPlan), 'date')));
        if (count($overPlanDates) >= 2) {
            $ids = [];
            foreach ($trades as $trade) {
                $date = Dates::dateKey((string) ($trade['date'] ?? ''));
                $plan = $this->plans->planForTrade($state, $trade);
                $key = $date !== null && $plan !== null ? $date . '#' . (string) ($plan['id'] ?? $date) : '';
                if ($key !== '' && isset($overPlan[$key])) {
                    $ids[] = $trade;
                }
            }
            $metrics = Metrics::summarize($ids);
            $items[] = $this->pattern(
                'over_trading',
                'Przekraczanie limitu transakcji z planu',
                'W ' . count($overPlanDates) . ' dniach liczba transakcji przekroczyła limit planu dla danego rynku. Wynik netto tych dni: ' . $metrics['net'] . ' ' . Metrics::CURRENCY . '.',
                ['days' => $overPlanDates, 'plans' => array_values($overPlan), 'trades' => count($ids), 'net' => $metrics['net']],
                count($ids),
                $window['range'],
                $metrics
            );
        }

        $bySetup = [];
        foreach ($trades as $trade) {
            $setup = trim((string) ($trade['setup'] ?? ''));
            if ($setup === '') {
                continue;
            }
            $bySetup[$setup][] = $trade;
        }
        foreach ($bySetup as $setup => $setupTrades) {
            if (count($setupTrades) < $min) {
                continue;
            }
            $metrics = Metrics::summarize($setupTrades);
            if ($metrics['net'] !== null && $metrics['net'] < 0) {
                $items[] = $this->pattern(
                    'losing_setup',
                    'Setup przynoszący straty: ' . $setup,
                    'Setup „' . $setup . '” dał ' . $metrics['net'] . ' ' . Metrics::CURRENCY . ' na ' . count($setupTrades) . ' transakcjach (win rate ' . $metrics['winRate'] . '%).',
                    ['setup' => $setup, 'trades' => count($setupTrades), 'net' => $metrics['net']],
                    count($setupTrades),
                    $window['range'],
                    $metrics
                );
            }
        }

        $overall = Metrics::summarize($trades);
        $byEmotion = [];
        foreach ($trades as $trade) {
            $emotion = trim((string) ($trade['emotion'] ?? ''));
            if ($emotion === '') {
                continue;
            }
            $byEmotion[$emotion][] = $trade;
        }
        foreach ($byEmotion as $emotion => $emotionTrades) {
            if (count($emotionTrades) < $min) {
                continue;
            }
            $metrics = Metrics::summarize($emotionTrades);
            $gap = ($overall['winRate'] !== null && $metrics['winRate'] !== null) ? $overall['winRate'] - $metrics['winRate'] : null;
            if (($gap !== null && $gap >= 15) || ($metrics['net'] !== null && $metrics['net'] < 0)) {
                $items[] = $this->pattern(
                    'emotion_dependency',
                    'Emocja związana z gorszym wynikiem: ' . $emotion,
                    'Przy emocji „' . $emotion . '” win rate to ' . $metrics['winRate'] . '% przy średniej ' . $overall['winRate'] . '%. Wynik netto: ' . $metrics['net'] . ' ' . Metrics::CURRENCY . '.',
                    ['emotion' => $emotion, 'trades' => count($emotionTrades), 'winRate' => $metrics['winRate'], 'overallWinRate' => $overall['winRate'], 'net' => $metrics['net']],
                    count($emotionTrades),
                    $window['range'],
                    $metrics
                );
            }
        }

        $byHour = [];
        foreach ($trades as $trade) {
            $local = Dates::localParts((string) ($trade['date'] ?? ''));
            if ($local['time'] === null) {
                continue;
            }
            $byHour[$this->hourBucket((int) substr($local['time'], 0, 2))][] = $trade;
        }
        foreach ($byHour as $bucket => $bucketTrades) {
            if (count($bucketTrades) < $min) {
                continue;
            }
            $metrics = Metrics::summarize($bucketTrades);
            if ($metrics['net'] !== null && $metrics['net'] < 0) {
                $items[] = $this->pattern(
                    'time_of_day',
                    'Najsłabsza pora dnia: ' . $bucket,
                    'W przedziale ' . $bucket . ' wynik netto to ' . $metrics['net'] . ' ' . Metrics::CURRENCY . ' na ' . count($bucketTrades) . ' transakcjach.',
                    ['hourBucket' => $bucket, 'trades' => count($bucketTrades), 'net' => $metrics['net']],
                    count($bucketTrades),
                    $window['range'],
                    $metrics
                );
            }
        }

        $violations = $this->risk->violations($state, $from, $to, 200, 0);
        $rules = [];
        foreach ($violations['items'] as $violation) {
            $rules[$violation['rule']] = ($rules[$violation['rule']] ?? 0) + 1;
        }
        foreach ($rules as $rule => $count) {
            if ($count < $min) {
                continue;
            }
            $items[] = $this->pattern(
                'rule_violation',
                'Powtarzalne naruszenie reguły: ' . $rule,
                'Reguła ' . $rule . ' została naruszona ' . $count . ' razy w analizowanym zakresie.',
                ['rule' => $rule, 'violations' => $count],
                $count,
                $window['range'],
                null
            );
        }

        $maxWins = 0;
        $maxLosses = 0;
        $currentWins = 0;
        $currentLosses = 0;
        foreach ($sorted as $trade) {
            $result = (float) ($trade['result'] ?? 0);
            if ($result > 0) {
                $currentWins++;
                $currentLosses = 0;
            } elseif ($result < 0) {
                $currentLosses++;
                $currentWins = 0;
            } else {
                $currentWins = 0;
                $currentLosses = 0;
            }
            $maxWins = max($maxWins, $currentWins);
            $maxLosses = max($maxLosses, $currentLosses);
        }
        if ($maxLosses >= 3) {
            $items[] = $this->pattern(
                'loss_streak',
                'Seria strat',
                'Najdłuższa seria strat w zakresie to ' . $maxLosses . ' transakcji z rzędu.',
                ['maxConsecutiveLosses' => $maxLosses, 'maxConsecutiveWins' => $maxWins],
                $maxLosses,
                $window['range'],
                null
            );
        }

        usort($items, static function (array $a, array $b): int {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($order[$a['confidence']] ?? 9) <=> ($order[$b['confidence']] ?? 9)
                ?: ($b['sampleSize'] <=> $a['sampleSize']);
        });

        return [
            'hasData' => $items !== [],
            'sampleSize' => count($trades),
            'items' => array_slice($items, 0, 25),
            'range' => $window['range'],
            'filters' => array_merge($window['filters'], ['minSample' => $min]),
            'currency' => Metrics::CURRENCY,
            'note' => 'Wzorce to korelacje w danych, nie dowód przyczynowości. Pewność rośnie z liczebnością próby; przy małej próbie wynik może być przypadkiem.',
        ];
    }

    private function pattern(string $id, string $title, string $finding, array $evidence, int $sampleSize, array $range, ?array $metrics): array
    {
        $confidence = 'low';
        if ($sampleSize >= 15) {
            $confidence = 'high';
        } elseif ($sampleSize >= 5) {
            $confidence = 'medium';
        }

        return [
            'id' => $id,
            'title' => $title,
            'finding' => $finding,
            'evidence' => $evidence,
            'sampleSize' => $sampleSize,
            'range' => $range,
            'confidence' => $confidence,
            'metrics' => $metrics,
            'causality' => 'association_only',
        ];
    }

    private function report(array $rows, string $field, array $window, int $minSample): array
    {
        $min = max(self::MIN_SAMPLE, $minSample);

        return [
            'hasData' => $rows !== [],
            'sampleSize' => count($window['trades']),
            'groupBy' => $field,
            'items' => array_slice($rows, 0, self::MAX_BUCKETS),
            'lowSampleGroups' => count(array_filter($rows, static fn (array $row): bool => ($row['sampleSize'] ?? 0) < $min)),
            'overall' => Metrics::summarize($window['trades']),
            'range' => $window['range'],
            'filters' => array_merge($window['filters'], ['minSample' => $min]),
            'currency' => Metrics::CURRENCY,
            'note' => 'Grupy z sampleSize poniżej progu są oznaczone lowSample; metryki nadal są liczone z dostępnych danych.',
        ];
    }

    private function drawdown(array $trades): ?float
    {
        if ($trades === []) {
            return null;
        }
        usort($trades, static fn (array $a, array $b): int => (strtotime((string) ($a['date'] ?? '')) ?: 0) <=> (strtotime((string) ($b['date'] ?? '')) ?: 0));
        $equity = 0.0;
        $peak = 0.0;
        $max = 0.0;
        foreach ($trades as $trade) {
            $equity += (float) ($trade['result'] ?? 0);
            $peak = max($peak, $equity);
            $max = min($max, $equity - $peak);
        }

        return Numbers::round($max);
    }

    /**
     * Klasyfikacja rynku: konto (MetaTrader) ma pierwszeństwo, potem symbol.
     */
    public function classify(array $trade, array $state): string
    {
        $account = null;
        $accountName = (string) ($trade['account'] ?? '');
        foreach ((array) ($state['accounts'] ?? []) as $row) {
            if (is_array($row) && ((string) ($row['name'] ?? '') === $accountName || (string) ($row['id'] ?? '') === $accountName)) {
                $account = $row;
                break;
            }
        }
        $pair = strtoupper(trim((string) ($trade['pair'] ?? '')));
        $symbol = preg_replace('/[^A-Z0-9]/', '', $pair) ?? $pair;
        foreach (self::COMMODITY_SYMBOLS as $needle) {
            if ($symbol !== '' && str_starts_with($symbol, $needle)) {
                return 'commodities';
            }
        }
        foreach (self::INDEX_SYMBOLS as $needle) {
            if ($symbol !== '' && str_starts_with($symbol, $needle)) {
                return 'indices';
            }
        }
        if (str_contains($pair, '/')) {
            [$base, $quote] = array_pad(explode('/', $pair, 2), 2, '');
            if (in_array($quote, self::CRYPTO_QUOTES, true) && preg_match('/^[A-Z0-9.]{2,12}$/', $base) === 1) {
                return 'crypto';
            }
        }
        $mt = false;
        foreach ([$trade['source'] ?? '', $accountName, $account['exchange'] ?? '', $account['type'] ?? '', $account['name'] ?? ''] as $value) {
            if (preg_match('/(^|[^a-z0-9])(mt4|mt5|metatrader)/i', (string) $value) === 1) {
                $mt = true;
                break;
            }
        }
        if ($mt) {
            return 'forex';
        }
        if (preg_match('/^[A-Z]{6}$/', $symbol) === 1) {
            $left = substr($symbol, 0, 3);
            $right = substr($symbol, 3, 3);
            if (in_array($left, self::FOREX_CODES, true) && in_array($right, self::FOREX_CODES, true)) {
                return 'forex';
            }
        }

        return 'other';
    }

    private function hourBucket(int $hour): string
    {
        if ($hour >= 21 || $hour < 5) {
            return '21:00-05:00';
        }
        if ($hour < 9) {
            return '05:00-09:00';
        }
        if ($hour < 13) {
            return '09:00-13:00';
        }
        if ($hour < 18) {
            return '13:00-18:00';
        }

        return '18:00-21:00';
    }
}
