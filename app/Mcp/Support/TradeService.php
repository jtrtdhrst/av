<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\TradeRules;

final class TradeService
{
    public function includedAccountNames(array $state): array
    {
        $names = [];
        foreach (($state['accounts'] ?? []) as $account) {
            if (!empty($account['include']) && isset($account['name'])) {
                $names[] = (string) $account['name'];
            }
        }

        return $names;
    }

    public function includedTrades(array $state): array
    {
        $trades = [];
        foreach ($this->allTrades($state) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $account = isset($trade['account']) ? (string) $trade['account'] : '';
            if (Metrics::accountIncluded($state, $account)) {
                $trades[] = $trade;
            }
        }

        return $trades;
    }

    public function closedTrades(array $state): array
    {
        return array_values(array_filter(
            $this->includedTrades($state),
            fn (array $trade): bool => $this->hasKnownResult($trade)
        ));
    }

    public function openTrades(array $state): array
    {
        return array_values(array_filter(
            $this->includedTrades($state),
            static fn (array $trade): bool => !TradeRules::isClosed($trade)
        ));
    }

    public function todayTrades(array $state): array
    {
        $today = Dates::todayKey();

        return array_values(array_filter(
            $this->includedTrades($state),
            static fn (array $trade): bool => Dates::dateKey((string) ($trade['date'] ?? '')) === $today
        ));
    }

    public function todayStats(array $state): array
    {
        $arr = $this->todayTrades($state);
        $closed = array_values(array_filter(
            $arr,
            fn (array $trade): bool => $this->hasKnownResult($trade)
        ));
        $pnl = 0.0;
        $losses = 0;
        foreach ($closed as $trade) {
            $result = (float) $trade['result'];
            $pnl += $result;
            if ($result < 0) {
                $losses++;
            }
        }
        $risk = 0.0;
        foreach ($arr as $trade) {
            $result = $trade['result'] ?? null;
            $r = $trade['r'] ?? null;
            if ($this->hasKnownResult($trade) && is_numeric($r) && is_finite((float) $r) && (float) $r !== 0.0) {
                $risk += abs((float) $result / (float) $r);
                continue;
            }
            $slRaw = (string) ($trade['sl'] ?? 'brak');
            if ($slRaw !== '' && $slRaw !== 'brak' && $slRaw !== '—') {
                $entry = Numbers::pnum($trade['entry'] ?? 0);
                $sl = Numbers::pnum($slRaw);
                $size = Numbers::pnum($trade['size'] ?? 0);
                $risk += abs($entry - $sl) * $size;
            }
        }

        return [
            'trades' => $arr,
            'closed' => $closed,
            'pnl' => Numbers::round($pnl),
            'losses' => $losses,
            'count' => count($arr),
            'risk' => Numbers::round($risk),
        ];
    }

    public function list(array $state, array $filters): array
    {
        $status = (string) ($filters['status'] ?? 'all');
        $pair = strtoupper(trim((string) ($filters['pair'] ?? '')));
        $setup = trim((string) ($filters['setup'] ?? ''));
        $grade = strtoupper(trim((string) ($filters['grade'] ?? '')));
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $fromTs = null;
        $toTs = null;
        if ($from !== '') {
            if (!Dates::isValidKey($from)) {
                throw new \InvalidArgumentException('Pole from musi mieć format YYYY-MM-DD.');
            }
            $fromTs = Dates::startOfLocalDay($from)->getTimestamp();
        }
        if ($to !== '') {
            if (!Dates::isValidKey($to)) {
                throw new \InvalidArgumentException('Pole to musi mieć format YYYY-MM-DD.');
            }
            $toTs = Dates::startOfNextLocalDay($to)->getTimestamp();
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new \InvalidArgumentException('Pole from nie może być późniejsze niż to.');
        }

        $filtered = [];
        foreach ($this->allTrades($state) as $trade) {
            $result = $trade['result'] ?? null;
            $closed = TradeRules::isClosed($trade);
            if ($status === 'open' && $closed) {
                continue;
            }
            if ($status === 'closed' && !$closed) {
                continue;
            }
            if ($status === 'win' && (!$closed || (float) $result <= 0)) {
                continue;
            }
            if ($status === 'loss' && (!$closed || (float) $result >= 0)) {
                continue;
            }
            if ($pair !== '' && strtoupper((string) ($trade['pair'] ?? '')) !== $pair) {
                continue;
            }
            if ($setup !== '' && (string) ($trade['setup'] ?? '') !== $setup) {
                continue;
            }
            if ($grade !== '' && strtoupper((string) ($trade['grade'] ?? '')) !== $grade) {
                continue;
            }
            $ts = Dates::timestamp((string) ($trade['date'] ?? '')) ?? 0;
            if ($fromTs !== null && $ts < $fromTs) {
                continue;
            }
            if ($toTs !== null && $ts >= $toTs) {
                continue;
            }
            $filtered[] = $trade;
        }

        usort($filtered, static function (array $a, array $b): int {
            return (Dates::timestamp((string) ($b['date'] ?? '')) ?? 0) <=> (Dates::timestamp((string) ($a['date'] ?? '')) ?? 0);
        });

        $total = count($filtered);
        $items = array_slice($filtered, $offset, $limit);

        return [
            'items' => array_map(fn (array $trade): array => $this->sanitize($trade, false), $items),
            'total' => $total,
            'nextOffset' => ($offset + count($items)) < $total ? $offset + count($items) : null,
        ];
    }

    public function find(array $state, string $id): ?array
    {
        foreach ($this->allTrades($state) as $trade) {
            if ((string) ($trade['id'] ?? '') === $id) {
                return $this->sanitize($trade, true);
            }
        }

        return null;
    }

    /**
     * Account inclusion is an analytics filter, not a journal visibility rule.
     *
     * @return list<array<string,mixed>>
     */
    private function allTrades(array $state): array
    {
        return array_values(array_filter(
            (array) ($state['trades'] ?? []),
            static fn (mixed $trade): bool => is_array($trade)
        ));
    }

    public function setupStats(array $state, string $setupName): array
    {
        $trades = array_values(array_filter(
            $this->closedTrades($state),
            static fn (array $trade): bool => (string) ($trade['setup'] ?? '') === $setupName
        ));
        $metrics = Metrics::summarize($trades);

        return [
            'trades' => $metrics['sampleSize'],
            'wins' => $metrics['wins'],
            'breakeven' => $metrics['breakeven'],
            'winRate' => $metrics['winRate'],
            'net' => $metrics['net'],
            'avgR' => $metrics['avgR'],
        ];
    }

    public function sanitize(array $trade, bool $detail): array
    {
        $entry = Numbers::pnum($trade['entry'] ?? 0);
        $slRaw = (string) ($trade['sl'] ?? 'brak');
        $tpRaw = (string) ($trade['tp'] ?? '—');
        $screens = $trade['screens'] ?? [];
        $screensCount = is_array($screens) ? count(array_filter($screens)) : 0;
        $date = (string) ($trade['date'] ?? '');
        $local = Dates::localParts($date);

        $payload = [
            'id' => (string) ($trade['id'] ?? ''),
            'date' => $date,
            'localDate' => $local['date'],
            'localTime' => $local['time'],
            'pair' => (string) ($trade['pair'] ?? ''),
            'side' => TradeRules::normalizeDirection($trade['dir'] ?? 'l') === 's' ? 'short' : 'long',
            'entry' => (string) ($trade['entry'] ?? ''),
            'stopLoss' => $slRaw === 'brak' ? null : (string) $slRaw,
            'takeProfit' => $tpRaw === '—' ? null : (string) $tpRaw,
            'size' => (string) ($trade['size'] ?? ''),
            'entryPrice' => Numbers::round($entry, 8),
            'stopLossPrice' => $slRaw === 'brak' ? null : Numbers::round(Numbers::pnum($slRaw), 8),
            'takeProfitPrice' => $tpRaw === '—' ? null : Numbers::round(Numbers::pnum($tpRaw), 8),
            'sizeValue' => Numbers::round(Numbers::pnum($trade['size'] ?? 0), 8),
            'exit' => $trade['exit'] ?? null,
            'closedAt' => $trade['closedAt'] ?? null,
            'result' => $this->optionalNumber($trade['result'] ?? null),
            'r' => $this->optionalNumber($trade['r'] ?? null),
            'closed' => TradeRules::isClosed($trade),
            'closedAtStatus' => TradeRules::closedAtStatus($trade),
            'grade' => $trade['grade'] ?? null,
            'checklist' => isset($trade['checklist']) ? (int) $trade['checklist'] : null,
            'checkTotal' => isset($trade['checkTotal']) ? (int) $trade['checkTotal'] : null,
            'errors' => array_values(array_map('strval', is_array($trade['errors'] ?? null) ? $trade['errors'] : [])),
            'emotion' => $trade['emotion'] ?? null,
            'setup' => $trade['setup'] ?? null,
            'session' => $trade['session'] ?? null,
            'mae' => $this->optionalMetric($trade['mae'] ?? null),
            'mfe' => $this->optionalMetric($trade['mfe'] ?? null),
            'durationMin' => $this->optionalInteger($trade['durationMin'] ?? null),
            'fee' => $this->optionalNumber($trade['fee'] ?? null),
            'plan' => (bool) ($trade['plan'] ?? false),
            'source' => $trade['source'] ?? null,
            'account' => $trade['account'] ?? null,
            'screensCount' => $screensCount,
        ];

        if ($detail) {
            $payload['note'] = (string) ($trade['note'] ?? '');
            $payload['mentor'] = (string) ($trade['mentor'] ?? '');
        }

        return $payload;
    }

    private function optionalMetric(mixed $value): ?float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || (is_string($value) && trim($value) === '')
            || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 0 ? Numbers::round($number) : null;
    }

    private function optionalNumber(mixed $value): ?float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || (is_string($value) && trim($value) === '')
            || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? Numbers::round($number) : null;
    }

    private function optionalInteger(mixed $value): ?int
    {
        $number = $this->optionalNumber($value);

        return $number === null || $number < 0 ? null : (int) round($number);
    }

    private function hasKnownResult(array $trade): bool
    {
        $value = $trade['result'] ?? null;
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || (is_string($value) && trim($value) === '')
            || !is_numeric($value)) {
            return false;
        }

        return is_finite((float) $value);
    }
}
