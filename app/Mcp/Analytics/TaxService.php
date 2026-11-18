<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;

/**
 * Przepływy kapitału i zestawienie PIT-38.
 *
 * Logika jest przeniesiona z panelu (public/js/state.js: AV.taxEvents i AV.taxData):
 * przychód = wpłaty/wypłaty zaklasyfikowane jako income, koszt = cost,
 * dochód = max(0, przychód - koszt - strata z lat ubiegłych), podatek = 19%.
 * To zestawienie pomocnicze, nie interpretacja prawa podatkowego.
 */
final class TaxService
{
    private const TAX_RATE = 0.19;

    public function transactions(array $state, array $filters = []): array
    {
        $account = trim((string) ($filters['account'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        if ($type !== '' && !in_array($type, ['deposit', 'withdrawal'], true)) {
            throw new \InvalidArgumentException('type musi być deposit albo withdrawal.');
        }
        $fromKey = $this->dateKey($from, 'from');
        $toKey = $this->dateKey($to, 'to');
        if ($fromKey !== null && $toKey !== null && $fromKey > $toKey) {
            throw new \InvalidArgumentException('Pole from nie może być późniejsze niż to.');
        }
        $items = [];
        $totals = ['deposits' => 0.0, 'withdrawals' => 0.0, 'depositsPln' => 0.0, 'withdrawalsPln' => 0.0];
        foreach ((array) ($state['flows'] ?? []) as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            $flowAccount = (string) ($flow['account'] ?? '');
            if ($account !== '' && $flowAccount !== $account) {
                continue;
            }
            $normalized = $this->normalizeFlow($flow);
            if ($type !== '' && $normalized['type'] !== $type) {
                continue;
            }
            $date = (string) ($flow['date'] ?? '');
            $key = $date !== '' ? Dates::dateKey($date) : null;
            if ($fromKey !== null && ($key === null || $key < $fromKey)) {
                continue;
            }
            if ($toKey !== null && ($key === null || $key > $toKey)) {
                continue;
            }
            if ($normalized['type'] === 'deposit') {
                $totals['deposits'] += $normalized['amount'];
                $totals['depositsPln'] += $normalized['valuePln'];
            } else {
                $totals['withdrawals'] += $normalized['amount'];
                $totals['withdrawalsPln'] += $normalized['valuePln'];
            }
            $items[] = $normalized + ['account' => $flowAccount];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
        $total = count($items);
        $page = array_slice($items, $offset, $limit);

        return [
            'hasData' => $items !== [],
            'items' => $page,
            'total' => $total,
            'nextOffset' => ($offset + count($page)) < $total ? $offset + count($page) : null,
            'totals' => [
                'deposits' => Numbers::round($totals['deposits']),
                'withdrawals' => Numbers::round($totals['withdrawals']),
                'depositsPln' => Numbers::round($totals['depositsPln']),
                'withdrawalsPln' => Numbers::round($totals['withdrawalsPln']),
            ],
            'range' => ['from' => $fromKey, 'to' => $toKey],
            'filters' => ['account' => $account !== '' ? $account : null, 'type' => $type !== '' ? $type : null],
            'note' => 'Wartość PLN liczona jest jako fiat * fx zapisany we wpisie. MCP nie pobiera kursów NBP.',
        ];
    }

    public function summary(array $state, ?int $year): array
    {
        $stateYear = (int) ($state['tax']['year'] ?? 0);
        $year = $year ?? ($stateYear >= 2000 ? $stateYear : (int) gmdate('Y'));
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('year musi być w zakresie 2000-2100.');
        }
        $carry = max(0.0, Numbers::round(Numbers::pnum($state['tax']['carryLoss'] ?? 0)));
        $events = [];
        $revenue = 0.0;
        $costs = 0.0;
        $skippedFlows = 0;
        $currencies = [];
        foreach ((array) ($state['flows'] ?? []) as $flow) {
            if (!is_array($flow)) {
                continue;
            }
            $date = (string) ($flow['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                $skippedFlows++;
                continue;
            }
            if (substr($date, 0, 4) !== (string) $year) {
                continue;
            }
            $currency = strtoupper((string) ($flow['currency'] ?? 'PLN'));
            $fiat = Numbers::pnum($flow['fiat'] ?? 0);
            $fx = $currency === 'PLN' ? 1.0 : Numbers::pnum($flow['fx'] ?? 0);
            if ($fiat <= 0 || $fx <= 0) {
                $skippedFlows++;
                continue;
            }
            $normalized = $this->normalizeFlow($flow);
            if (!in_array($normalized['taxKind'], ['income', 'cost'], true)) {
                continue;
            }
            if ($normalized['taxKind'] === 'income') {
                $revenue += $normalized['valuePln'];
            } else {
                $costs += $normalized['valuePln'];
            }
            $currency = $normalized['currency'];
            if (!isset($currencies[$currency])) {
                $currencies[$currency] = ['currency' => $currency, 'incomePln' => 0.0, 'costPln' => 0.0, 'count' => 0];
            }
            $currencies[$currency][$normalized['taxKind'] === 'income' ? 'incomePln' : 'costPln'] += $normalized['valuePln'];
            $currencies[$currency]['count']++;
            $events[] = $normalized;
        }
        $revenue = Numbers::round($revenue);
        $costs = Numbers::round($costs);
        $income = max(0.0, Numbers::round($revenue - $costs - $carry));
        $due = Numbers::round($income * self::TAX_RATE);
        $carryForward = max(0.0, Numbers::round($costs + $carry - $revenue));
        foreach ($currencies as &$row) {
            $row['incomePln'] = Numbers::round($row['incomePln']);
            $row['costPln'] = Numbers::round($row['costPln']);
        }
        unset($row);
        $trades = [];
        $forex = 0;
        $fees = 0.0;
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            if (substr((string) ($trade['date'] ?? ''), 0, 4) !== (string) $year) {
                continue;
            }
            $trades[] = $trade;
            $fees += (float) ($trade['fee'] ?? 0);
            if ($this->isForexTrade($trade, $state)) {
                $forex++;
            }
        }
        $closed = Metrics::closed($trades);
        $net = 0.0;
        foreach ($closed as $trade) {
            $net += (float) $trade['result'];
        }

        return [
            'hasData' => $events !== [] || $trades !== [],
            'year' => $year,
            'pit38' => [
                'revenue' => $revenue,
                'costs' => $costs,
                'carryLossFromPreviousYears' => Numbers::round($carry),
                'taxableIncome' => $income,
                'taxDue19Pct' => $due,
                'carryForward' => $carryForward,
                'taxRate' => self::TAX_RATE,
            ],
            'flows' => [
                'count' => count($events),
                'skippedFlows' => $skippedFlows,
                'operations' => array_slice($events, 0, 100),
                'currencies' => array_values($currencies),
            ],
            'trades' => [
                'total' => count($trades),
                'closed' => count($closed),
                'forex' => $forex,
                'nonForex' => count($trades) - $forex,
                'net' => $closed !== [] ? Numbers::round($net) : null,
                'feesPaid' => count($trades) > 0 ? Numbers::round($fees) : null,
            ],
            'currency' => 'PLN',
            'disclaimer' => 'Zestawienie jest pomocnicze i powtarza logikę panelu AlphaVault. Nie jest interpretacją prawa podatkowego ani deklaracją PIT-38. Przed złożeniem zeznania zweryfikuj dane z księgowym lub w KIS.',
            'note' => 'Wpłaty i wypłaty klasyfikuje pole tax wpisu (income/cost), a przy jego braku typ wpisu. Kursy pochodzą z wpisów (fx), nie z NBP.',
        ];
    }

    private function normalizeFlow(array $flow): array
    {
        $fx = Numbers::pnum($flow['fx'] ?? 1);
        if ($fx <= 0) {
            $fx = 1.0;
        }
        $fiat = Numbers::pnum($flow['fiat'] ?? 0);
        $typeRaw = (string) ($flow['type'] ?? '');
        $type = in_array($typeRaw, ['Wpłata', 'deposit'], true) ? 'deposit' : 'withdrawal';
        $taxRaw = (string) ($flow['tax'] ?? '');
        $taxKind = in_array($taxRaw, ['income', 'cost'], true) ? $taxRaw : ($type === 'deposit' ? 'cost' : 'income');
        $currency = strtoupper((string) ($flow['currency'] ?? 'PLN'));

        return [
            'id' => (string) ($flow['id'] ?? ''),
            'date' => (string) ($flow['date'] ?? ''),
            'type' => $type,
            'operation' => (string) ($flow['op'] ?? ''),
            'amount' => Numbers::round(Numbers::pnum($flow['amount'] ?? 0), 8),
            'currency' => $currency,
            'fiat' => Numbers::round($fiat),
            'fx' => Numbers::round($fx, 6),
            'valuePln' => Numbers::round($fiat * $fx),
            'taxKind' => $taxKind,
        ];
    }

    private function isForexTrade(array $trade, array $state): bool
    {
        $accountName = (string) ($trade['account'] ?? '');
        $matched = [];
        foreach ((array) ($state['accounts'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            if ((string) ($row['id'] ?? '') === $accountName || $name === $accountName ||
                ($name !== '' && stripos($accountName, $name) !== false)) {
                $matched[] = $row;
            }
        }
        $keyParts = [(string) ($trade['source'] ?? ''), $accountName];
        foreach ($matched as $row) {
            $keyParts[] = (string) ($row['exchange'] ?? '');
            $keyParts[] = (string) ($row['type'] ?? '');
            $keyParts[] = (string) ($row['name'] ?? '');
        }
        $key = implode(' ', array_filter($keyParts, static fn (string $value): bool => $value !== ''));

        return preg_match('/(^|[^a-z0-9])(mt4|mt5|metatrader|forex|cfd)/i', $key) === 1;
    }

    private function dateKey(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $key = trim((string) $value);
        if (!Dates::isValidKey($key)) {
            throw new \InvalidArgumentException('Pole ' . $field . ' musi mieć format YYYY-MM-DD.');
        }

        return $key;
    }
}
