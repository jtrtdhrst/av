<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Numbers;

/**
 * Kalkulator Alpha — te same formuły, które liczy panel (public/js/actions.js,
 * AV.calcResult): max strata = saldo * ryzyko, wartość pozycji = max strata / SL%,
 * depozyt = wartość / dźwignia, zysk = wartość * TP%, R:R = TP% / SL%.
 *
 * Dla forexu i indeksów liczba lotów = max strata / (SL w pipsach * wartość pipsa),
 * a wartość pipsa zależy od instrumentu (JPY, quote, GBP, indeksy, metale).
 */
final class CalculatorService
{
    /** [symbol, typ, pip, wartość pipsa]; zgodne z AV.INST w public/js/data.js. */
    private const INSTRUMENTS = [
        'EUR/USD' => ['type' => 'fx', 'pip' => 0.0001, 'pipValue' => 10],
        'GBP/USD' => ['type' => 'fx', 'pip' => 0.0001, 'pipValue' => 10],
        'USD/JPY' => ['type' => 'fx', 'pip' => 0.01, 'pipValue' => 'jpy'],
        'AUD/USD' => ['type' => 'fx', 'pip' => 0.0001, 'pipValue' => 10],
        'USD/CHF' => ['type' => 'fx', 'pip' => 0.0001, 'pipValue' => 'quote'],
        'USD/CAD' => ['type' => 'fx', 'pip' => 0.0001, 'pipValue' => 'quote'],
        'NZD/USD' => ['type' => 'fx', 'pip' => 0.0001, 'pipValue' => 10],
        'EUR/GBP' => ['type' => 'fx', 'pip' => 0.0001, 'pipValue' => 'gbp'],
        'NAS100' => ['type' => 'ind', 'pip' => 1, 'pipValue' => 1],
        'US500' => ['type' => 'ind', 'pip' => 1, 'pipValue' => 1],
        'US30' => ['type' => 'ind', 'pip' => 1, 'pipValue' => 1],
        'GER40' => ['type' => 'ind', 'pip' => 1, 'pipValue' => 1],
        'UK100' => ['type' => 'ind', 'pip' => 1, 'pipValue' => 1],
        'JPN225' => ['type' => 'ind', 'pip' => 1, 'pipValue' => 1],
        'XAUUSD' => ['type' => 'met', 'pip' => 0.01, 'pipValue' => 1],
        'XAGUSD' => ['type' => 'met', 'pip' => 0.001, 'pipValue' => 5],
        'OIL' => ['type' => 'met', 'pip' => 0.01, 'pipValue' => 10],
    ];

    private const CRYPTO = ['BTC/USDT', 'ETH/USDT', 'SOL/USDT', 'XRP/USDT', 'BNB/USDT', 'DOGE/USDT', 'LINK/USDT', 'AVAX/USDT'];

    public function calculate(array $input): array
    {
        $mode = (string) ($input['mode'] ?? 'crypto');
        if (!in_array($mode, ['crypto', 'fx'], true)) {
            throw new \InvalidArgumentException('mode musi być crypto albo fx.');
        }
        $direction = strtolower((string) ($input['direction'] ?? 'long'));
        $direction = in_array($direction, ['short', 's'], true) ? 'short' : 'long';
        $balance = (float) ($input['balance'] ?? 0);
        $riskPercent = (float) ($input['riskPercent'] ?? 0);
        $entry = (float) ($input['entry'] ?? 0);
        $stopLoss = (float) ($input['stopLoss'] ?? 0);
        $takeProfit = isset($input['takeProfit']) && $input['takeProfit'] !== null ? (float) $input['takeProfit'] : null;
        $leverage = (float) ($input['leverage'] ?? 1);
        if ($balance <= 0) {
            throw new \InvalidArgumentException('balance musi być większe od 0.');
        }
        if ($riskPercent <= 0 || $riskPercent > 100) {
            throw new \InvalidArgumentException('riskPercent musi być w zakresie 0.1-100.');
        }
        if ($entry <= 0 || $stopLoss <= 0) {
            throw new \InvalidArgumentException('entry i stopLoss muszą być większe od 0.');
        }
        if ($takeProfit !== null && $takeProfit <= 0) {
            throw new \InvalidArgumentException('takeProfit musi być większe od 0.');
        }
        if ($leverage < 1 || $leverage > 1000) {
            throw new \InvalidArgumentException('leverage musi być w zakresie 1-1000.');
        }
        if ($direction === 'long' && ($stopLoss >= $entry || ($takeProfit !== null && $takeProfit <= $entry))) {
            throw new \InvalidArgumentException('Przy long stop loss musi być poniżej wejścia, a take profit powyżej.');
        }
        if ($direction === 'short' && ($stopLoss <= $entry || ($takeProfit !== null && $takeProfit >= $entry))) {
            throw new \InvalidArgumentException('Przy short stop loss musi być powyżej wejścia, a take profit poniżej.');
        }
        $maxLoss = $balance * $riskPercent / 100;
        $slDistance = abs($entry - $stopLoss);
        $slPct = $slDistance / $entry;
        $tpPct = $takeProfit !== null ? abs($takeProfit - $entry) / $entry : 0.0;
        if ($slPct <= 0) {
            throw new \InvalidArgumentException('Stop loss nie może być równy cenie wejścia.');
        }
        $rr = $takeProfit !== null ? $tpPct / $slPct : null;
        $breakeven = $rr !== null && $rr > 0 ? (int) round(100 / (1 + $rr)) : null;
        $warnings = [];
        if ($riskPercent > 5) {
            $warnings[] = 'Ryzyko powyżej 5% salda jest wysokie.';
        }
        if ($rr !== null && $rr < 1) {
            $warnings[] = 'R:R poniżej 1:1 — cel jest bliżej niż stop loss.';
        }
        $instrument = trim((string) ($input['instrument'] ?? ''));
        $currency = strtoupper(trim((string) ($input['currency'] ?? ($mode === 'crypto' ? 'USDT' : 'USD'))));

        if ($mode === 'crypto') {
            $notional = $maxLoss / $slPct;
            $margin = $notional / $leverage;
            $profit = $takeProfit !== null ? $notional * $tpPct : null;
            $quantity = $notional / $entry;

            return $this->payload($mode, $direction, $instrument, $currency, $balance, $riskPercent, $leverage, $entry, $stopLoss, $takeProfit, $maxLoss, $slPct, $tpPct, $rr, $breakeven, $warnings, [
                'positionSize' => Numbers::round($quantity, 8),
                'positionSizeUnit' => 'units',
                'positionValue' => Numbers::round($notional),
                'marginRequired' => Numbers::round($margin),
                'potentialProfit' => $profit !== null ? Numbers::round($profit) : null,
                'instrumentKnown' => $instrument === '' ? null : in_array(strtoupper($instrument), self::CRYPTO, true),
            ]);
        }

        $key = strtoupper(str_replace(' ', '', $instrument));
        $spec = self::INSTRUMENTS[$key] ?? null;
        if ($spec === null) {
            foreach (self::INSTRUMENTS as $symbol => $row) {
                if (str_starts_with($key, $symbol)) {
                    $spec = $row;
                    $key = $symbol;
                    break;
                }
            }
        }
        if ($spec === null) {
            throw new \InvalidArgumentException('Nieznany instrument dla trybu fx. Dostępne: ' . implode(', ', array_keys(self::INSTRUMENTS)) . '.');
        }
        $pip = (float) $spec['pip'];
        $pipValue = $this->pipValue($spec['pipValue'], $entry);
        $stopPips = $slDistance / $pip;
        $tpPips = $takeProfit !== null ? abs($takeProfit - $entry) / $pip : 0.0;
        if ($stopPips <= 0) {
            throw new \InvalidArgumentException('Stop loss jest bliżej niż jeden pip.');
        }
        $lots = $maxLoss / ($stopPips * $pipValue);
        $profit = $takeProfit !== null ? $lots * $tpPips * $pipValue : null;
        $unit = $spec['type'] === 'ind' ? 'points' : 'pips';
        $notional = $lots * 100000 * $entry;

        return $this->payload($mode, $direction, $key, $currency, $balance, $riskPercent, $leverage, $entry, $stopLoss, $takeProfit, $maxLoss, $slPct, $tpPct, $rr, $breakeven, $warnings, [
            'positionSize' => Numbers::round($lots, 2),
            'positionSizeUnit' => 'lots',
            'positionValue' => Numbers::round($notional),
            'positionValueNote' => 'Wartość nominalna liczona jak 1 lot = 100 000 jednostek; sprawdź specyfikację u brokera.',
            'marginRequired' => Numbers::round($notional / $leverage),
            'potentialProfit' => $profit !== null ? Numbers::round($profit) : null,
            'pipSize' => $pip,
            'pipValue' => Numbers::round($pipValue, 4),
            'stopDistance' => Numbers::round($stopPips, 1),
            'takeProfitDistance' => $takeProfit !== null ? Numbers::round($tpPips, 1) : null,
            'distanceUnit' => $unit,
            'instrumentKnown' => true,
        ]);
    }

    /**
     * @return list<array{symbol:string,type:string}>
     */
    public function instruments(): array
    {
        $rows = [];
        foreach (self::INSTRUMENTS as $symbol => $spec) {
            $rows[] = ['symbol' => $symbol, 'type' => (string) $spec['type']];
        }

        return $rows;
    }

    private function payload(
        string $mode,
        string $direction,
        string $instrument,
        string $currency,
        float $balance,
        float $riskPercent,
        float $leverage,
        float $entry,
        float $stopLoss,
        ?float $takeProfit,
        float $maxLoss,
        float $slPct,
        float $tpPct,
        ?float $rr,
        ?int $breakeven,
        array $warnings,
        array $extra
    ): array {
        return array_merge([
            'hasData' => true,
            'mode' => $mode,
            'direction' => $direction,
            'instrument' => $instrument === '' ? null : $instrument,
            'currency' => $currency,
            'balance' => Numbers::round($balance),
            'riskPercent' => Numbers::round($riskPercent, 2),
            'leverage' => Numbers::round($leverage, 2),
            'entry' => Numbers::round($entry, 8),
            'stopLoss' => Numbers::round($stopLoss, 8),
            'takeProfit' => $takeProfit !== null ? Numbers::round($takeProfit, 8) : null,
            'maxLoss' => Numbers::round($maxLoss),
            'stopDistancePct' => Numbers::round($slPct * 100, 4),
            'takeProfitDistancePct' => $takeProfit !== null ? Numbers::round($tpPct * 100, 4) : null,
            'riskReward' => $rr !== null ? Numbers::round($rr, 2) : null,
            'breakevenWinRatePct' => $breakeven,
            'warnings' => $warnings,
            'note' => 'Wynik liczy się tymi samymi formułami co kalkulator w panelu; nie jest zleceniem ani rekomendacją.',
        ], $extra);
    }

    private function pipValue(string|int|float $rule, float $price): float
    {
        if ($rule === 'jpy') {
            return $price > 0 ? 1000 / $price : 0.0;
        }
        if ($rule === 'quote') {
            return $price > 0 ? 10 / $price : 0.0;
        }
        if ($rule === 'gbp') {
            return 12.955;
        }

        return (float) $rule;
    }
}
