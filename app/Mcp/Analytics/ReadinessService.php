<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;

/**
 * Gotowość do handlu: check-in, opcjonalnie dane z urządzeń oraz korelacje
 * między check-inami a wynikami. Dane zdrowotne tylko przy zgodzie użytkownika
 * i zakresie alphavault.health.
 */
final class ReadinessService
{
    /** Progi zgodności z panelem (state.js readiness). */
    private const MOOD_MODIFIERS = [
        'Spokój' => 10,
        'Skupienie' => 7,
        'Pewność' => 5,
        'Nuda' => -3,
        'Zmęczenie' => -8,
        'Strach' => -10,
        'FOMO' => -12,
        'Złość' => -14,
        'Chciwość' => -6,
    ];

    /**
     * @param array{today:?array,baseline:?array,series:list<array>,readiness:?array,devices:list<array>}|null $wearables
     */
    public function context(array $state, ?string $date, ?array $wearables, string $healthAccess): array
    {
        $date = $date !== null && trim($date) !== '' ? trim($date) : Dates::todayKey();
        if (!Dates::isValidKey($date)) {
            throw new \InvalidArgumentException('date musi mieć format YYYY-MM-DD.');
        }
        $checkin = $this->checkinFor($state, $date);
        $checkinScore = $checkin !== null ? $this->score($checkin) : null;
        $health = null;
        if ($wearables !== null && $healthAccess === 'granted') {
            $today = is_array($wearables['today'] ?? null) ? $wearables['today'] : null;
            $baseline = is_array($wearables['baseline'] ?? null) ? $wearables['baseline'] : null;
            $readiness = is_array($wearables['readiness'] ?? null) ? $wearables['readiness'] : null;
            $series = array_slice((array) ($wearables['series'] ?? []), -14);
            $health = [
                'date' => $today['date'] ?? null,
                'sleepMinutes' => $today['sleepMinutes'] ?? null,
                'hrvMs' => $today['hrvMs'] ?? null,
                'restingHr' => $today['restingHr'] ?? null,
                'bodyBattery' => $today['bodyBattery'] ?? null,
                'providerScore' => $today['providerScore'] ?? null,
                'provider' => $today['provider'] ?? null,
                'baseline' => $baseline,
                'physiologyScore' => $readiness['components']['physiology'] ?? null,
                'source' => $readiness['source'] ?? 'none',
                'series' => array_map(static fn (array $day): array => [
                    'date' => $day['date'] ?? null,
                    'sleepMinutes' => $day['sleepMinutes'] ?? null,
                    'hrvMs' => $day['hrvMs'] ?? null,
                    'restingHr' => $day['restingHr'] ?? null,
                    'bodyBattery' => $day['bodyBattery'] ?? null,
                    'providerScore' => $day['providerScore'] ?? null,
                ], array_values(array_filter($series, 'is_array'))),
            ];
        }
        $readinessScore = null;
        $readinessSource = 'none';
        $title = '';
        if ($health !== null && $health['physiologyScore'] !== null && $checkinScore !== null) {
            $readinessScore = (int) round($health['physiologyScore'] * 0.7 + $checkinScore * 0.3);
            $readinessSource = 'devices+checkin';
        } elseif ($health !== null && $health['physiologyScore'] !== null) {
            $readinessScore = (int) $health['physiologyScore'];
            $readinessSource = 'devices';
        } elseif ($checkinScore !== null) {
            $readinessScore = $checkinScore;
            $readinessSource = 'checkin';
        }
        if ($readinessScore !== null) {
            $title = $readinessScore >= 80 ? 'Świetna forma' : ($readinessScore >= 65 ? 'Dobra forma' : ($readinessScore >= 50 ? 'Ostrożnie dziś' : 'Lepiej odpuścić'));
        }
        $todayNet = $this->dailyNet($state, $date);

        return [
            'hasData' => $checkin !== null || $health !== null,
            'date' => $date,
            'checkin' => $checkin === null ? null : [
                'date' => $date,
                'mood' => (string) ($checkin['mood'] ?? ''),
                'energy' => (int) ($checkin['energy'] ?? 0),
                'note' => (string) ($checkin['note'] ?? ''),
                'score' => $checkinScore,
            ],
            'readiness' => [
                'score' => $readinessScore,
                'title' => $title,
                'source' => $readinessSource,
            ],
            'health' => $health,
            'healthAccess' => $healthAccess,
            'healthNote' => $healthAccess === 'granted'
                ? 'Dane zdrowotne pochodzą z podłączonego urządzenia i są zwracane tylko przy zakresie alphavault.health oraz aktywnej zgodzie użytkownika.'
                : 'Dane zdrowotne wymagają zakresu alphavault.health i podłączonego urządzenia. MCP nie zwraca ich bez tej zgody.',
            'trading' => [
                'trades' => $todayNet['trades'],
                'closedTrades' => $todayNet['closed'],
                'net' => $todayNet['net'],
            ],
            'currency' => Metrics::CURRENCY,
            'timezone' => Dates::TZ,
        ];
    }

    public function correlation(array $state, ?string $from, ?string $to, int $minSample, string $healthAccess): array
    {
        $window = Metrics::window($state, $from, $to, ['analysis' => 'checkin-correlation']);
        $daily = [];
        foreach ($window['trades'] as $trade) {
            $date = Dates::dateKey((string) ($trade['date'] ?? ''));
            if ($date === null) {
                continue;
            }
            $daily[$date][] = $trade;
        }
        $rows = [];
        foreach ((array) ($state['checkins'] ?? []) as $checkin) {
            if (!is_array($checkin)) {
                continue;
            }
            $date = (string) ($checkin['date'] ?? '');
            if (!Dates::isValidKey($date)) {
                continue;
            }
            if ($window['range']['from'] !== null && $date < $window['range']['from']) {
                continue;
            }
            if ($window['range']['to'] !== null && $date > $window['range']['to']) {
                continue;
            }
            $trades = $daily[$date] ?? [];
            if ($trades === []) {
                continue;
            }
            $rows[] = [
                'date' => $date,
                'mood' => (string) ($checkin['mood'] ?? ''),
                'energy' => (int) ($checkin['energy'] ?? 0),
                'score' => $this->score($checkin),
                'trades' => $trades,
            ];
        }
        $min = max(3, $minSample);
        $byEnergy = [];
        $byMood = [];
        $byScoreBand = [];
        foreach ($rows as $row) {
            $byEnergy[(string) $row['energy']][] = $row['trades'];
            $mood = $row['mood'] !== '' ? $row['mood'] : 'brak';
            $byMood[$mood][] = $row['trades'];
            $band = $this->scoreBand($row['score']);
            $byScoreBand[$band][] = $row['trades'];
        }
        $flatten = static function (array $groups): array {
            $out = [];
            foreach ($groups as $items) {
                foreach ($items as $trade) {
                    $out[] = $trade;
                }
            }

            return $out;
        };
        $energyItems = [];
        foreach ($byEnergy as $energy => $groups) {
            $trades = $flatten($groups);
            $metrics = Metrics::summarize($trades);
            $metrics['energy'] = (int) $energy;
            $metrics['days'] = count($groups);
            $metrics['lowSample'] = $metrics['sampleSize'] < $min;
            $energyItems[] = $metrics;
        }
        usort($energyItems, static fn (array $a, array $b): int => $a['energy'] <=> $b['energy']);
        $moodItems = [];
        foreach ($byMood as $mood => $groups) {
            $trades = $flatten($groups);
            $metrics = Metrics::summarize($trades);
            $metrics['mood'] = $mood === 'brak' ? null : $mood;
            $metrics['days'] = count($groups);
            $metrics['lowSample'] = $metrics['sampleSize'] < $min;
            $moodItems[] = $metrics;
        }
        usort($moodItems, static fn (array $a, array $b): int => ($b['net'] ?? PHP_INT_MIN) <=> ($a['net'] ?? PHP_INT_MIN));
        foreach (['poniżej 50', '50-64', '65-79', '80+'] as $band) {
            $groups = $byScoreBand[$band] ?? [];
            $trades = $flatten($groups);
            $metrics = Metrics::summarize($trades);
            $metrics['scoreBand'] = $band;
            $metrics['days'] = count($groups);
            $metrics['lowSample'] = $metrics['sampleSize'] < $min;
            $byScoreBand[$band] = $metrics;
        }
        $energySeries = [];
        $scoreSeries = [];
        $netSeries = [];
        $winSeries = [];
        foreach ($rows as $row) {
            $trades = $row['trades'];
            $net = 0.0;
            $wins = 0;
            $count = 0;
            foreach ($trades as $trade) {
                if (($trade['result'] ?? null) === null) {
                    continue;
                }
                $result = (float) $trade['result'];
                $net += $result;
                $count++;
                if ($result > 0) {
                    $wins++;
                }
            }
            if ($count === 0) {
                continue;
            }
            $energySeries[] = (float) $row['energy'];
            $scoreSeries[] = (float) ($row['score'] ?? 0);
            $netSeries[] = $net;
            $winSeries[] = $wins / $count * 100;
        }
        $health = null;
        if ($healthAccess === 'granted') {
            $health = [
                'merged' => false,
                'note' => 'Korelacja ze snem i HRV wymaga połączenia dziennych metryk urządzenia z check-inami; w tym narzędziu zwracane są wyłącznie dane gotowości i check-inów. Metryki urządzenia udostępnia get_readiness_context.',
            ];
        }

        return [
            'hasData' => $rows !== [],
            'sampleSize' => count($rows),
            'days' => count($rows),
            'byEnergy' => $energyItems,
            'byMood' => $moodItems,
            'byReadiness' => array_values($byScoreBand),
            'correlations' => [
                'energyVsDailyNet' => $this->pearson($energySeries, $netSeries, 3),
                'energyVsWinRate' => $this->pearson($energySeries, $winSeries, 3),
                'readinessVsDailyNet' => $this->pearson($scoreSeries, $netSeries, 3),
                'readinessVsWinRate' => $this->pearson($scoreSeries, $winSeries, 3),
            ],
            'health' => $health,
            'healthAccess' => $healthAccess,
            'range' => $window['range'],
            'filters' => array_merge($window['filters'], ['minSample' => $min]),
            'currency' => Metrics::CURRENCY,
            'limitations' => [
                'Korelacja to nie przyczynowość: wynik może zależeć od rynku, setupu i liczby transakcji.',
                'Próba liczona jest w dniach z check-inem i co najmniej jedną zamkniętą transakcją.',
                'Współczynnik Pearsona liczony jest tylko przy co najmniej 3 dniach i pełnych danych.',
                'Brak danych zdrowotnych nie jest błędem; wymagana jest zgoda użytkownika i zakres alphavault.health.',
            ],
        ];
    }

    /**
     * Wynik gotowości z check-inu, zgodny z formułą panelu (state.js: AV.readiness).
     */
    public function score(array $checkin): ?int
    {
        if (isset($checkin['score']) && is_numeric($checkin['score'])) {
            $stored = (int) $checkin['score'];
            if ($stored > 0) {
                return $stored;
            }
        }
        if (!isset($checkin['energy']) || !is_numeric($checkin['energy'])) {
            return null;
        }
        $modifier = self::MOOD_MODIFIERS[(string) ($checkin['mood'] ?? '')] ?? 0;
        $score = 50 + (((int) $checkin['energy']) - 3) * 10 + $modifier;

        return max(0, min(100, (int) round($score)));
    }

    private function checkinFor(array $state, string $date): ?array
    {
        $best = null;
        foreach ((array) ($state['checkins'] ?? []) as $checkin) {
            if (!is_array($checkin) || (string) ($checkin['date'] ?? '') !== $date) {
                continue;
            }
            $score = (int) ($checkin['score'] ?? 0);
            if ($best === null || $score > (int) ($best['score'] ?? 0)) {
                $best = $checkin;
            }
        }

        return $best;
    }

    private function dailyNet(array $state, string $date): array
    {
        $trades = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            if (Dates::dateKey((string) ($trade['date'] ?? '')) !== $date) {
                continue;
            }
            if (!$this->accountIncluded($state, (string) ($trade['account'] ?? ''))) {
                continue;
            }
            $trades[] = $trade;
        }
        $closed = Metrics::closed($trades);
        $net = 0.0;
        foreach ($closed as $trade) {
            $net += (float) $trade['result'];
        }

        return [
            'trades' => count($trades),
            'closed' => count($closed),
            'net' => $closed !== [] ? Numbers::round($net) : null,
        ];
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

    private function scoreBand(?int $score): string
    {
        if ($score === null) {
            return 'brak';
        }
        if ($score < 50) {
            return 'poniżej 50';
        }
        if ($score < 65) {
            return '50-64';
        }
        if ($score < 80) {
            return '65-79';
        }

        return '80+';
    }

    private function pearson(array $x, array $y, int $min): ?array
    {
        $n = min(count($x), count($y));
        if ($n < $min) {
            return null;
        }
        $sumX = 0.0;
        $sumY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumX += $x[$i];
            $sumY += $y[$i];
        }
        $meanX = $sumX / $n;
        $meanY = $sumY / $n;
        $num = 0.0;
        $denX = 0.0;
        $denY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $num += $dx * $dy;
            $denX += $dx * $dx;
            $denY += $dy * $dy;
        }
        if ($denX <= 0.0 || $denY <= 0.0) {
            return ['r' => null, 'sampleSize' => $n, 'note' => 'Brak zmienności w danych.'];
        }

        return [
            'r' => Numbers::round($num / sqrt($denX * $denY), 3),
            'sampleSize' => $n,
            'note' => 'Współczynnik Pearsona; wartość opisuje współwystępowanie, nie przyczynowość.',
        ];
    }
}
