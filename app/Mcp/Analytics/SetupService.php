<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;

/**
 * Playbook: szczegóły setupu i jego rzeczywista skuteczność.
 *
 * Nazwa setupu jest kluczem w transakcjach, więc statystyki liczone są po nazwie.
 * Metadane (opis, potwierdzenie, unieważnienie, minimalne R:R) są opcjonalne
 * i mogą nie istnieć w setupach utworzonych wcześniej.
 */
final class SetupService
{
    public function find(array $state, string $identifier): ?array
    {
        $needle = trim($identifier);
        if ($needle === '') {
            return null;
        }
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (!is_array($setup)) {
                continue;
            }
            if ((string) ($setup['id'] ?? '') === $needle) {
                return $setup;
            }
        }
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (!is_array($setup)) {
                continue;
            }
            if (mb_strtolower(trim((string) ($setup['name'] ?? ''))) === mb_strtolower($needle)) {
                return $setup;
            }
        }

        return null;
    }

    public function details(array $state, string $identifier): array
    {
        $setup = $this->find($state, $identifier);
        if ($setup === null) {
            return [
                'found' => false,
                'setup' => null,
                'performance' => null,
                'available' => $this->names($state),
            ];
        }
        $name = (string) ($setup['name'] ?? '');
        $trades = $this->tradesFor($state, $name);
        $metrics = Metrics::summarize($trades);

        return [
            'found' => true,
            'setup' => [
                'id' => (string) ($setup['id'] ?? ''),
                'name' => $name,
                'grade' => $setup['grade'] ?? null,
                'timeframe' => $setup['tf'] ?? null,
                'markedAvoid' => (bool) ($setup['bad'] ?? false),
                'rules' => array_values(array_map('strval', is_array($setup['rules'] ?? null) ? $setup['rules'] : [])),
                'description' => $setup['description'] ?? null,
                'confirmation' => $setup['confirmation'] ?? null,
                'invalidation' => $setup['invalidation'] ?? null,
                'stopLoss' => $setup['stopLoss'] ?? null,
                'minRR' => isset($setup['minRR']) && is_numeric($setup['minRR']) ? (float) $setup['minRR'] : null,
                'exitRules' => array_values(array_map('strval', is_array($setup['exitRules'] ?? null) ? $setup['exitRules'] : [])),
                'examples' => array_values(array_map('strval', is_array($setup['examples'] ?? null) ? $setup['examples'] : [])),
                'createdAt' => $setup['createdAt'] ?? null,
                'updatedAt' => $setup['updatedAt'] ?? null,
            ],
            'performance' => array_merge($metrics, [
                'range' => Metrics::dateRange($trades),
                'lowSample' => $metrics['sampleSize'] < 3,
            ]),
            'available' => $this->names($state),
        ];
    }

    public function performance(array $state, string $identifier, ?string $from, ?string $to, int $minSample): array
    {
        $setup = $this->find($state, $identifier);
        if ($setup === null) {
            return [
                'found' => false,
                'setup' => null,
                'hasData' => false,
                'sampleSize' => 0,
                'metrics' => Metrics::summarize([]),
                'available' => $this->names($state),
            ];
        }
        $name = (string) ($setup['name'] ?? '');
        $all = $this->tradesFor($state, $name);
        $window = Metrics::range($all, $from, $to);
        $metrics = Metrics::summarize($window);
        $grades = [];
        foreach (['A', 'B', 'C'] as $grade) {
            $items = array_values(array_filter($window, static fn (array $trade): bool => strtoupper((string) ($trade['grade'] ?? '')) === $grade));
            $grades[] = array_merge(['grade' => $grade], Metrics::summarize($items));
        }
        $errors = [];
        foreach ($window as $trade) {
            foreach (array_unique(array_map('strval', is_array($trade['errors'] ?? null) ? $trade['errors'] : [])) as $error) {
                if (trim($error) === '') {
                    continue;
                }
                $errors[$error][] = $trade;
            }
        }
        $errorRows = [];
        foreach ($errors as $error => $items) {
            $errorMetrics = Metrics::summarize($items);
            $errorRows[] = [
                'error' => $error,
                'sampleSize' => $errorMetrics['sampleSize'],
                'net' => $errorMetrics['net'],
                'winRate' => $errorMetrics['winRate'],
            ];
        }
        usort($errorRows, static fn (array $a, array $b): int => ($a['net'] ?? PHP_INT_MAX) <=> ($b['net'] ?? PHP_INT_MAX));
        $byPair = [];
        $bySession = [];
        foreach ($window as $trade) {
            $pair = strtoupper(trim((string) ($trade['pair'] ?? '')));
            $byPair[$pair === '' ? '__none__' : $pair][] = $trade;
            $session = trim((string) ($trade['session'] ?? ''));
            $bySession[$session === '' ? '__none__' : $session][] = $trade;
        }
        $pairRows = [];
        foreach ($byPair as $pair => $items) {
            $pairMetrics = Metrics::summarize($items);
            $pairRows[] = [
                'pair' => $pair === '__none__' ? null : $pair,
                'sampleSize' => $pairMetrics['sampleSize'],
                'net' => $pairMetrics['net'],
                'winRate' => $pairMetrics['winRate'],
                'expectancyR' => $pairMetrics['expectancyR'],
            ];
        }
        usort($pairRows, static fn (array $a, array $b): int => ($b['net'] ?? PHP_INT_MIN) <=> ($a['net'] ?? PHP_INT_MIN));
        foreach ($bySession as $session => $items) {
            $sessionMetrics = Metrics::summarize($items);
            $bySession[$session] = [
                'session' => $session === '__none__' ? null : $session,
                'sampleSize' => $sessionMetrics['sampleSize'],
                'net' => $sessionMetrics['net'],
                'winRate' => $sessionMetrics['winRate'],
                'expectancyR' => $sessionMetrics['expectancyR'],
            ];
        }

        return [
            'found' => true,
            'setup' => [
                'id' => (string) ($setup['id'] ?? ''),
                'name' => $name,
                'grade' => $setup['grade'] ?? null,
                'timeframe' => $setup['tf'] ?? null,
                'minRR' => isset($setup['minRR']) && is_numeric($setup['minRR']) ? (float) $setup['minRR'] : null,
            ],
            'hasData' => $metrics['hasData'],
            'sampleSize' => $metrics['sampleSize'],
            'metrics' => $metrics,
            'maxDrawdown' => $this->drawdown($window),
            'byGrade' => $grades,
            'bySession' => array_values($bySession),
            'byPair' => $pairRows,
            'topErrors' => array_slice($errorRows, 0, 10),
            'lowSample' => $metrics['sampleSize'] < max(3, $minSample),
            'range' => Metrics::dateRange($window),
            'filters' => ['from' => $from, 'to' => $to, 'minSample' => max(3, $minSample)],
            'currency' => Metrics::CURRENCY,
            'note' => 'Statystyki liczone wyłącznie z transakcji z nazwą tego setupu. drawdown to obsunięcie skumulowanego wyniku transakcji, nie kapitału konta.',
        ];
    }

    /**
     * @return list<string>
     */
    public function names(array $state): array
    {
        $names = [];
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (is_array($setup) && trim((string) ($setup['name'] ?? '')) !== '') {
                $names[] = (string) $setup['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<array>
     */
    public function tradesFor(array $state, string $name): array
    {
        $trades = [];
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade) || (string) ($trade['setup'] ?? '') !== $name) {
                continue;
            }
            if (($trade['result'] ?? null) === null) {
                continue;
            }
            if (!$this->accountIncluded($state, (string) ($trade['account'] ?? ''))) {
                continue;
            }
            $trades[] = $trade;
        }

        return $trades;
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

    private function drawdown(array $trades): ?float
    {
        if ($trades === []) {
            return null;
        }
        usort($trades, static fn (array $a, array $b): int => (strtotime((string) ($a['date'] ?? '')) ?: 0) <=> (strtotime((string) ($b['date'] ?? '')) ?: 0));
        $curve = [];
        foreach ($trades as $trade) {
            $curve[] = (float) ($trade['result'] ?? 0);
        }
        $equity = 0.0;
        $peak = 0.0;
        $drawdown = 0.0;
        foreach ($curve as $result) {
            $equity += $result;
            $peak = max($peak, $equity);
            $drawdown = min($drawdown, $equity - $peak);
        }

        return Numbers::round($drawdown);
    }
}
