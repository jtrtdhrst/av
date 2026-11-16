<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class DashboardService
{
    public function __construct(
        private readonly TradeService $trades,
        private readonly StatsService $stats,
        private readonly EventsService $events,
        private readonly \AlphaVault\Mcp\Analytics\ReadinessService $readinessService,
    ) {
    }

    public function dashboard(array $state, string $healthAccess = 'scope_missing', ?array $wearables = null): array
    {
        $today = $this->trades->todayStats($state);
        $todayKey = Dates::todayKey();
        $plan = $this->planSummary($state['plans'][$todayKey] ?? null, $todayKey);
        $lock = $this->lockInfo($state, $today);
        $readiness = $this->readiness($state, $healthAccess, $wearables);
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

    public function readiness(array $state, string $healthAccess = 'scope_missing', ?array $wearables = null): array
    {
        $context = $this->readinessService->context(
            $state,
            Dates::todayKey(),
            $wearables,
            $healthAccess
        );
        $ready = $context['readiness'];
        $health = $context['health'];
        $checkin = $context['checkin'];
        $today = is_array($wearables['today'] ?? null) ? $wearables['today'] : [];
        $components = is_array($today['components'] ?? null) ? $today['components'] : [];
        $score = $ready['score'];
        $title = (string) ($ready['title'] ?? '') !== '' ? $ready['title'] : null;
        $class = $score === null ? null : ($score < 50 ? 'loss' : ($score < 65 ? 'warnc' : 'win'));

        $healthDescription = [];
        $sleepMinutes = $today['sleepMinutes'] ?? null;
        $hrv = $today['hrvMs'] ?? null;
        $restingHr = $today['restingHr'] ?? null;
        $bodyBattery = $today['bodyBattery'] ?? null;
        $providerScore = $today['providerScore'] ?? null;
        if (is_numeric($sleepMinutes)) {
            $sleepHours = (float) $sleepMinutes / 60;
            $hours = (int) floor($sleepHours);
            $minutes = (int) round(($sleepHours - $hours) * 60);
            if ($minutes === 60) {
                $hours++;
                $minutes = 0;
            }
            $healthDescription[] = 'Sen ' . $hours . ' h ' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . ' min';
        }
        if (is_numeric($hrv)) {
            $healthDescription[] = 'HRV ' . round((float) $hrv, 1) . ' ms';
        }
        if (is_numeric($restingHr)) {
            $healthDescription[] = 'tętno spoczynkowe ' . (int) $restingHr;
        }
        if (is_numeric($bodyBattery)) {
            $healthDescription[] = 'body battery ' . (int) $bodyBattery;
        }
        if (is_numeric($providerScore)) {
            $healthDescription[] = 'wynik urządzenia ' . (int) $providerScore;
        }
        $description = $healthDescription !== []
            ? implode(', ', $healthDescription) . '.'
            : ($checkin !== null ? 'Gotowość liczona tylko z check-inu.' : null);
        $hasDeviceData = false;
        foreach ([$sleepMinutes, $hrv, $restingHr, $bodyBattery, $providerScore] as $metric) {
            if (is_numeric($metric)) {
                $hasDeviceData = true;
                break;
            }
        }
        $providers = array_values(array_filter(
            (array) ($today['providers'] ?? []),
            static fn ($provider): bool => is_string($provider) && $provider !== ''
        ));
        $garmin = in_array('garmin', $providers, true);

        return [
            'score' => $score,
            'title' => $title,
            'class' => $class,
            'description' => $description,
            'healthAccess' => $healthAccess,
            'garmin' => $garmin,
            'hasCheckin' => $checkin !== null,
            'hasDeviceData' => $hasDeviceData,
            'checkScore' => $checkin['score'] ?? null,
            'sleepScore' => $components['sleep'] ?? null,
            'hrvScore' => $components['hrv'] ?? null,
            'heartRateScore' => $components['restingHr'] ?? null,
            'bodyBatteryScore' => $components['bodyBattery'] ?? null,
            'recoveryScore' => $components['recovery'] ?? null,
            'physiologyScore' => $health['physiologyScore'] ?? null,
            'providers' => $providers,
        ];
    }
}
