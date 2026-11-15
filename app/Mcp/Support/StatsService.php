<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

/**
 * Statystyki tradingowe. Metryki bazowe pochodzą ze wspólnego Metrics, więc
 * raporty analityczne MCP nie mogą rozjechać się z get_stats. Brak transakcji
 * daje hasData=false i null, nie zera.
 */
final class StatsService
{
    public function __construct(private readonly TradeService $trades)
    {
    }

    public function compute(array $state, string $period): array
    {
        if (!in_array($period, ['7d', '30d', '90d', 'all'], true)) {
            throw new \InvalidArgumentException('Pole period musi mieć wartość 7d, 30d, 90d albo all.');
        }

        $closed = $this->trades->closedTrades($state);
        $fromKey = null;
        if ($period !== 'all') {
            $days = (int) substr($period, 0, -1);
            $fromKey = (new \DateTimeImmutable('now', Dates::tz()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
            $fromTs = Dates::startOfLocalDay($fromKey)->getTimestamp();
            $closed = array_values(array_filter(
                $closed,
                static fn (array $trade): bool => (strtotime((string) ($trade['date'] ?? '')) ?: 0) >= $fromTs
            ));
        }

        $metrics = Metrics::summarize($closed);
        $n = (int) $metrics['sampleSize'];
        $withSl = 0;
        $planned = 0;
        $full = [];
        $part = [];
        foreach ($closed as $trade) {
            $sl = (string) ($trade['sl'] ?? 'brak');
            if ($sl !== '' && $sl !== 'brak' && $sl !== '—') {
                $withSl++;
            }
            if (!empty($trade['plan'])) {
                $planned++;
            }
            $checklist = (int) ($trade['checklist'] ?? 0);
            $checkTotal = (int) ($trade['checkTotal'] ?? 7);
            if ($checkTotal > 0 && $checklist >= $checkTotal) {
                $full[] = $trade;
            } else {
                $part[] = $trade;
            }
        }

        $start = $this->startCapital($state);
        if ($start <= 0) {
            $start = 2000.0;
        }
        $sorted = $closed;
        usort($sorted, static fn (array $a, array $b): int => (strtotime((string) ($a['date'] ?? '')) ?: 0) <=> (strtotime((string) ($b['date'] ?? '')) ?: 0));
        $equity = $start;
        $peak = $start;
        $maxDd = 0.0;
        $maxDdPct = 0.0;
        foreach ($sorted as $trade) {
            $equity += (float) ($trade['result'] ?? 0);
            $peak = max($peak, $equity);
            $dd = $equity - $peak;
            if ($dd < $maxDd) {
                $maxDd = $dd;
            }
            if ($peak > 0) {
                $maxDdPct = min($maxDdPct, $dd / $peak * 100);
            }
        }

        $grades = [];
        foreach (['A', 'B', 'C'] as $grade) {
            $items = array_values(array_filter($closed, static fn (array $trade): bool => (string) ($trade['grade'] ?? '') === $grade));
            $grades[] = array_merge(['grade' => $grade], Metrics::summarize($items));
        }

        $setups = [];
        foreach ($closed as $trade) {
            $key = trim((string) ($trade['setup'] ?? ''));
            $setups[$key === '' ? '__none__' : $key][] = $trade;
        }
        $bySetup = [];
        foreach ($setups as $setup => $items) {
            $summary = Metrics::summarize($items);
            $bySetup[] = [
                'setup' => $setup === '__none__' ? null : $setup,
                'trades' => $summary['sampleSize'],
                'winRate' => $summary['winRate'],
                'net' => $summary['net'],
            ];
        }
        usort($bySetup, static fn (array $a, array $b): int => ($b['net'] ?? PHP_INT_MIN) <=> ($a['net'] ?? PHP_INT_MIN));

        return [
            'hasData' => $metrics['hasData'],
            'sampleSize' => $n,
            'period' => $period,
            'from' => $fromKey ?? ($sorted !== [] ? Dates::dateKey((string) ($sorted[0]['date'] ?? '')) : null),
            'to' => Dates::todayKey(),
            'trades' => $n,
            'wins' => $metrics['wins'],
            'losses' => $metrics['losses'],
            'winRate' => $metrics['winRate'],
            'net' => $metrics['net'],
            'grossProfit' => $metrics['grossProfit'],
            'grossLoss' => $metrics['grossLoss'],
            'profitFactor' => $metrics['profitFactor'],
            'expectancyR' => $metrics['expectancyR'],
            'expectancyCurrency' => $metrics['expectancyCurrency'],
            'avgWin' => $metrics['avgWin'],
            'avgLoss' => $metrics['avgLoss'],
            'maxDrawdown' => $n > 0 ? Numbers::round($maxDd) : null,
            'maxDrawdownPct' => $n > 0 ? Numbers::round($maxDdPct) : null,
            'fees' => $metrics['fees'],
            'withStopLoss' => $n > 0 ? Numbers::round($withSl / $n * 100) : null,
            'planAdherence' => $n > 0 ? Numbers::round($planned / $n * 100) : null,
            'byGrade' => $grades,
            'bySetup' => array_slice($bySetup, 0, 5),
            'fullChecklist' => Metrics::summarize($full),
            'partialChecklist' => Metrics::summarize($part),
            'currency' => Metrics::CURRENCY,
            'note' => 'Metryki bez danych zwracają null (nie zero). profitFactor jest null, gdy nie ma strat do porównania.',
        ];
    }

    public function startCapital(array $state): float
    {
        $sum = 0.0;
        foreach (($state['accounts'] ?? []) as $account) {
            if (empty($account['include'])) {
                continue;
            }
            $sum += (float) ($account['start'] ?? 0) * Numbers::fxToUsdt((string) ($account['currency'] ?? 'USDT'));
        }

        return $sum;
    }

    public function totalCapital(array $state): float
    {
        $sum = 0.0;
        foreach (($state['accounts'] ?? []) as $account) {
            if (empty($account['include'])) {
                continue;
            }
            $sum += (float) ($account['balance'] ?? 0) * Numbers::fxToUsdt((string) ($account['currency'] ?? 'USDT'));
        }

        return $sum;
    }
}
