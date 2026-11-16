<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class DashboardService
{
    public function __construct(
        private readonly TradeService $trades,
        private readonly StatsService $stats,
        private readonly EventsService $events,
    ) {
    }

    public function dashboard(array $state, string $healthAccess = 'scope_missing'): array
    {
        $today = $this->trades->todayStats($state);
        $todayKey = Dates::todayKey();
        $plan = $this->planSummary($state['plans'][$todayKey] ?? null, $todayKey);
        $lock = $this->lockInfo($state, $today);
        $readiness = $this->readiness($state, $healthAccess);
        $events = $this->events->list(['limit' => 1]);

        return [
            'asOf' => gmdate('Y-m-d\TH:i:s\Z'),
            'date' => $todayKey,
            'today' => [
                'pnl' => $today['pnl'],
                'trades' => $today['count'],
                'closedTrades' => count($today['closed']),
                'openPositions' => count($this->trades->openTrades($state)),
                'losses' => $today['losses'],
                'riskUsed' => $today['risk'],
            ],
            'plan' => $plan,
            'readiness' => $readiness,
            'protection' => $lock,
            'capital' => [
                'balance' => Numbers::round($this->stats->totalCapital($state)),
                'start' => Numbers::round($this->stats->startCapital($state)),
                'currency' => 'USDT',
            ],
            'nextEvent' => $this->events->nextUpcoming(),
            'sync' => $events['sync'] ?? null,
        ];
    }

    public function planSummary(?array $plan, string $date): ?array
    {
        if (!is_array($plan)) {
            return null;
        }

        return [
            'date' => (string) ($plan['date'] ?? $date),
            'approved' => !empty($plan['approvedAt']),
            'approvedAt' => isset($plan['approvedAt']) && $plan['approvedAt'] !== null ? (int) $plan['approvedAt'] : null,
            'market' => $plan['market'] ?? null,
            'bias' => $plan['bias'] ?? null,
            'risk' => $plan['risk'] ?? null,
            'maxTrades' => $plan['maxTrades'] ?? null,
            'until' => $plan['until'] ?? null,
            'notDo' => $plan['notDo'] ?? null,
        ];
    }

    public function lockInfo(array $state, array $today): array
    {
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $limit = max(1, (int) ($settings['maxLosses'] ?? 2));
        $dailyLimit = max(0.1, (float) ($settings['maxDailyLoss'] ?? 3));
        $capital = $this->stats->totalCapital($state);
        if ($capital <= 0) {
            $capital = 1.0;
        }
        $lossPct = max(0.0, -min(0.0, (float) $today['pnl'])) / $capital * 100;
        $triggered = !empty($settings['safetyEnabled'])
            && ($today['losses'] >= $limit || $lossPct >= $dailyLimit);

        $until = null;
        if ($triggered) {
            $lastLoss = null;
            foreach ($today['closed'] as $trade) {
                if ((float) ($trade['result'] ?? 0) < 0) {
                    $ts = strtotime((string) ($trade['date'] ?? '')) ?: 0;
                    if ($lastLoss === null || $ts > $lastLoss) {
                        $lastLoss = $ts;
                    }
                }
            }
            $base = $lastLoss !== null ? (new \DateTimeImmutable('@' . $lastLoss))->setTimezone(new \DateTimeZone('UTC')) : Dates::nowUtc();
            $block = (string) ($settings['block'] ?? 'midnight');
            $until = match ($block) {
                '1h' => $base->modify('+1 hour'),
                '4h' => $base->modify('+4 hours'),
                default => Dates::startOfLocalDay((new \DateTimeImmutable('now', Dates::tz()))->modify('+1 day')->format('Y-m-d')),
            };
            if ($until <= Dates::nowUtc()) {
                $until = Dates::startOfLocalDay((new \DateTimeImmutable('now', Dates::tz()))->modify('+1 day')->format('Y-m-d'));
            }
        }

        $override = null;
        foreach (($state['overrides'] ?? []) as $candidate) {
            if (!is_array($candidate) || (string) ($candidate['date'] ?? '') !== Dates::todayKey()) {
                continue;
            }
            if ($override === null || (int) ($candidate['at'] ?? 0) > (int) ($override['at'] ?? 0)) {
                $override = $candidate;
            }
        }
        $overridden = $override !== null
            && (!$triggered || (int) ($override['at'] ?? 0) >= ($until?->getTimestamp() ?? 0) * 1000 - 86400000);

        return [
            'active' => $triggered && !$overridden,
            'triggered' => $triggered,
            'overridden' => $overridden,
            'until' => $until?->format('Y-m-d\TH:i:s\Z'),
            'untilLocal' => $until?->setTimezone(Dates::tz())->format('Y-m-d H:i'),
            'losses' => $today['losses'],
            'limit' => $limit,
            'lossPercent' => Numbers::round($lossPct),
            'dailyLimitPercent' => Numbers::round($dailyLimit),
            'overrideReason' => $override['reason'] ?? null,
            'reason' => $triggered
                ? ($today['losses'] >= $limit ? 'limit strat' : 'limit straty dziennej')
                : null,
        ];
    }

    public function readiness(array $state, string $healthAccess = 'scope_missing'): array
    {
        $healthGranted = $healthAccess === 'granted';
        $health = $healthGranted && is_array($state['health'] ?? null) ? $state['health'] : [];
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $check = null;
        foreach (($state['checkins'] ?? []) as $candidate) {
            if (!is_array($candidate) || (string) ($candidate['date'] ?? '') !== Dates::todayKey()) {
                continue;
            }
            if ($check === null || (int) ($candidate['score'] ?? 0) > (int) ($check['score'] ?? 0)) {
                $check = $candidate;
            }
        }

        $moodMod = [
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
        $checkScore = $check !== null
            ? $this->clamp(50 + (((int) ($check['energy'] ?? 3)) - 3) * 10 + ($moodMod[(string) ($check['mood'] ?? '')] ?? 0), 0, 100)
            : null;
        $garmin = $healthGranted && !empty($settings['devices']['garmin']);
        $sleepH = $garmin ? $this->metricValue($health, 'sleepH') : null;
        $hrv = $garmin ? $this->metricValue($health, 'hrv') : null;
        $hrvBase = $garmin ? $this->metricValue($health, 'hrvBase') : null;
        $hr = $garmin ? $this->metricValue($health, 'hr') : null;
        $hrBase = $garmin ? $this->metricValue($health, 'hrBase') : null;
        $sleepScore = $sleepH !== null ? $this->clamp($sleepH / 8 * 100, 0, 100) : null;
        $hrvScore = $hrv !== null && $hrvBase !== null && $hrvBase > 0
            ? $this->clamp($hrv / $hrvBase * 100, 0, 100)
            : null;
        $hrScore = $hr !== null && $hr > 0 && $hrBase !== null && $hrBase > 0
            ? $this->clamp($hrBase / $hr * 100, 0, 100)
            : null;
        $weightedParts = [];
        if ($sleepScore !== null) {
            $weightedParts[] = [$sleepScore, 0.35];
        }
        if ($hrvScore !== null) {
            $weightedParts[] = [$hrvScore, 0.2];
        }
        if ($hrScore !== null) {
            $weightedParts[] = [$hrScore, 0.1];
        }
        if ($checkScore !== null) {
            $weightedParts[] = [$checkScore, $garmin ? 0.35 : 1.0];
        }
        $weightTotal = array_sum(array_column($weightedParts, 1));
        $weightedScore = array_sum(array_map(
            static fn (array $part): float => $part[0] * $part[1],
            $weightedParts
        ));
        $score = $weightTotal > 0 ? (int) round($weightedScore / $weightTotal) : null;

        $title = null;
        $class = null;
        if ($score !== null) {
            $title = 'Świetna forma';
            $class = 'win';
            if ($score < 80) {
                $title = 'Dobra forma';
            }
            if ($score < 65) {
                $title = 'Ostrożnie dziś';
                $class = 'warnc';
            }
            if ($score < 50) {
                $title = 'Lepiej odpuścić';
                $class = 'loss';
            }
        }

        $healthDescription = [];
        if ($sleepH !== null) {
            $healthDescription[] = 'Sen ' . floor($sleepH) . ' h '
                . str_pad((string) round(($sleepH - floor($sleepH)) * 60), 2, '0', STR_PAD_LEFT) . ' min';
        }
        if ($hrv !== null) {
            $healthDescription[] = 'HRV ' . round($hrv) . ' ms';
        }
        if ($hr !== null) {
            $healthDescription[] = 'tętno ' . round($hr);
        }
        $description = $healthDescription !== []
            ? implode(', ', $healthDescription) . '.'
            : ($check !== null ? 'Gotowość liczona tylko z check-inu.' : null);

        return [
            'score' => $score,
            'title' => $title,
            'class' => $class,
            'description' => $description,
            'healthAccess' => $healthAccess,
            'garmin' => $garmin,
            'hasCheckin' => $check !== null,
            'hasDeviceData' => $garmin && ($sleepH !== null || $hrv !== null || $hr !== null),
            'checkScore' => $checkScore !== null ? Numbers::round($checkScore) : null,
            'sleepScore' => $sleepScore !== null ? Numbers::round($sleepScore) : null,
            'hrvScore' => $hrvScore !== null ? Numbers::round($hrvScore) : null,
            'heartRateScore' => $hrScore !== null ? Numbers::round($hrScore) : null,
        ];
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function metricValue(array $health, string $key): ?float
    {
        if (!array_key_exists($key, $health) || !is_numeric($health[$key])) {
            return null;
        }
        $value = (float) $health[$key];

        return is_finite($value) ? $value : null;
    }
}
