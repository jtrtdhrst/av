<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\Numbers;
use AlphaVault\Mcp\Support\TradeService;

/**
 * Dziennik: recenzja transakcji i pełny kontekst jednej transakcji.
 *
 * Recenzja jest osobnym dokumentem (tradeReviews) i nie zmienia pól finansowych.
 * Rozróżniamy „recenzja istnieje” od „recenzji jeszcze nie ma”.
 */
final class JournalService
{
    public function __construct(
        private readonly TradeService $trades,
        private readonly SetupService $setups,
        private readonly DayPlanService $plans,
        private readonly ScreensService $screens
    ) {
    }

    public function review(array $state, string $tradeId): array
    {
        $trade = $this->rawTrade($state, $tradeId);
        if ($trade === null) {
            return [
                'found' => false,
                'tradeId' => $tradeId,
                'reviewAvailable' => false,
                'review' => null,
                'reason' => 'trade_not_found',
            ];
        }
        $review = is_array($state['tradeReviews'] ?? null) ? ($state['tradeReviews'][$tradeId] ?? null) : null;
        if (!is_array($review)) {
            return [
                'found' => true,
                'tradeId' => $tradeId,
                'reviewAvailable' => false,
                'review' => null,
                'trade' => $this->trades->sanitize($trade, false),
                'reason' => 'review_not_created',
                'hint' => 'Recenzji nie ma w dzienniku. Na wyraźną prośbę użytkownika użyj create_trade_review. Podgląd zmian bez zapisu: propose_change(operation=review.create). Pola dziennika edytuje update_trade.',
            ];
        }
        $dateKey = Dates::dateKey((string) ($trade['date'] ?? ''));
        $plan = $dateKey !== null ? $this->plans->planFor($state, $dateKey) : null;
        $compliance = $this->plans->alignment($state, $trade, $plan);
        $reviewSetupName = trim((string) ($review['setup'] ?? ''));
        $tradeSetupName = trim((string) ($trade['setup'] ?? ''));
        // Pusta wartość w recenzji nie może ukryć setupu zapisanego przy transakcji.
        $setupName = $reviewSetupName !== '' ? $reviewSetupName : $tradeSetupName;
        $setup = $setupName !== '' ? $this->setups->find($state, $setupName) : null;

        return [
            'found' => true,
            'tradeId' => $tradeId,
            'reviewAvailable' => true,
            'review' => [
                'grade' => $review['grade'] ?? null,
                'setup' => $review['setup'] ?? null,
                'emotion' => $review['emotion'] ?? null,
                'errors' => array_values(array_map('strval', is_array($review['errors'] ?? null) ? $review['errors'] : [])),
                'note' => (string) ($review['note'] ?? ''),
                'checklist' => isset($review['checklist']) ? (int) $review['checklist'] : null,
                'savedAt' => $review['savedAt'] ?? null,
            ],
            'decisionQuality' => $this->decisionQuality($review),
            'planCompliance' => $compliance,
            'setupCompliance' => [
                'tradeSetup' => $trade['setup'] ?? null,
                'reviewSetup' => $review['setup'] ?? null,
                'effectiveSetup' => $setupName !== '' ? $setupName : null,
                'setupSource' => $reviewSetupName !== '' ? 'review' : ($tradeSetupName !== '' ? 'trade' : 'none'),
                'setupExists' => $setup !== null,
                // Brak setupu w recenzji to brak potwierdzenia, nie automatyczna zgodność.
                'matchesTrade' => $reviewSetupName !== '' && $tradeSetupName !== '' && $reviewSetupName === $tradeSetupName,
                'rules' => $setup !== null ? array_values(array_map('strval', is_array($setup['rules'] ?? null) ? $setup['rules'] : [])) : [],
            ],
            'recommendations' => $this->recommendationsFromReview($review),
            'trade' => $this->trades->sanitize($trade, false),
        ];
    }

    public function context(array $state, string $tradeId, bool $includeImages): array
    {
        $trade = $this->rawTrade($state, $tradeId);
        if ($trade === null) {
            return [
                'found' => false,
                'trade' => null,
                'review' => null,
                'setup' => null,
                'setupPerformance' => null,
                'plan' => null,
                'compliance' => null,
                'screenshots' => null,
                'account' => null,
                'risk' => null,
            ];
        }
        $date = Dates::dateKey((string) ($trade['date'] ?? ''));
        $plan = $date !== null ? $this->plans->planFor($state, $date) : null;
        $sequence = $this->sequence($state, $trade);
        $alignment = $this->plans->alignment($state, $trade, $plan, $sequence);
        $setupName = trim((string) ($trade['setup'] ?? ''));
        $setup = $setupName !== '' ? $this->setups->details($state, $setupName) : null;
        $review = $this->review($state, $tradeId);
        $account = $this->account($state, $trade);
        $riskAmount = $this->plans->riskAmount($trade);
        $capital = $this->plans->capital($state);
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $maxRisk = Numbers::pnum($settings['maxRisk'] ?? 1);
        $screens = $this->screens->forTrade($trade, $includeImages);

        return [
            'found' => true,
            'trade' => $this->trades->sanitize($trade, true),
            'review' => $review['review'],
            'reviewAvailable' => $review['reviewAvailable'],
            'setup' => $setup['setup'] ?? null,
            'setupPerformance' => $setup['performance'] ?? null,
            'plan' => $plan,
            'compliance' => $alignment + [
                'planStatus' => $plan === null ? 'none' : $this->plans->status($plan),
                'rulesNote' => 'Zgodność wynika z reguł planu, nie z wyniku transakcji.',
            ],
            'checklist' => [
                'completed' => isset($trade['checklist']) ? (int) $trade['checklist'] : null,
                'total' => isset($trade['checkTotal']) ? (int) $trade['checkTotal'] : null,
                'points' => array_values(array_map('strval', is_array($settings['checklist'] ?? null) ? $settings['checklist'] : [])),
                'note' => 'Zapisana jest liczba punktów, nie ich wybór; points to aktualna checklista użytkownika.',
            ],
            'emotions' => [
                'emotion' => $trade['emotion'] ?? null,
                'errors' => array_values(array_map('strval', is_array($trade['errors'] ?? null) ? $trade['errors'] : [])),
            ],
            'screenshots' => $screens['screenshots'],
            'account' => $account,
            'risk' => [
                'riskAmount' => $riskAmount,
                'capital' => $capital > 0 ? Numbers::round($capital) : null,
                'riskPercentOfCapital' => $riskAmount !== null && $capital > 0 ? Numbers::round($riskAmount / $capital * 100, 2) : null,
                'maxRiskPercentPerTrade' => $maxRisk > 0 ? Numbers::round($maxRisk, 2) : null,
                'plannedRiskPercent' => $plan !== null ? $this->plans->planRiskPercent($plan) : null,
                'currency' => Metrics::CURRENCY,
            ],
            'currency' => Metrics::CURRENCY,
            'timezone' => Dates::TZ,
        ];
    }

    private function rawTrade(array $state, string $tradeId): ?array
    {
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (is_array($trade) && (string) ($trade['id'] ?? '') === $tradeId) {
                return $trade;
            }
        }

        return null;
    }

    private function sequence(array $state, array $trade): int
    {
        $date = Dates::dateKey((string) ($trade['date'] ?? ''));
        if ($date === null) {
            return 1;
        }
        $sameDay = [];
        foreach ((array) ($state['trades'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (Dates::dateKey((string) ($row['date'] ?? '')) === $date) {
                $sameDay[] = $row;
            }
        }
        usort($sameDay, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
        foreach ($sameDay as $index => $row) {
            if ((string) ($row['id'] ?? '') === (string) ($trade['id'] ?? '')) {
                return $index + 1;
            }
        }

        return 1;
    }

    private function account(array $state, array $trade): ?array
    {
        $needle = (string) ($trade['account'] ?? '');
        if ($needle === '') {
            return null;
        }
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (!is_array($account)) {
                continue;
            }
            if ((string) ($account['name'] ?? '') === $needle || (string) ($account['id'] ?? '') === $needle) {
                $currency = strtoupper((string) ($account['currency'] ?? 'USDT'));

                return [
                    'id' => (string) ($account['id'] ?? ''),
                    'name' => (string) ($account['name'] ?? ''),
                    'currency' => $currency,
                    'balance' => Numbers::round((float) ($account['balance'] ?? 0)),
                    'start' => Numbers::round((float) ($account['start'] ?? 0)),
                    'include' => !empty($account['include']),
                    'leverage' => (string) ($account['lev'] ?? ''),
                    'exchange' => (string) ($account['exchange'] ?? ''),
                    'type' => (string) ($account['type'] ?? ''),
                ];
            }
        }

        return null;
    }

    private function decisionQuality(array $review): array
    {
        $grade = strtoupper((string) ($review['grade'] ?? ''));
        $checklist = isset($review['checklist']) ? (int) $review['checklist'] : null;
        $errors = array_values(array_map('strval', is_array($review['errors'] ?? null) ? $review['errors'] : []));

        return [
            'grade' => $grade !== '' ? $grade : null,
            'checklistCompleted' => $checklist,
            'errorsCount' => count($errors),
            'assessment' => match ($grade) {
                'A' => 'Ocena A w zapisanej recenzji; zgodność z planem wymaga osobnej weryfikacji w planCompliance.',
                'B' => 'Decyzja poprawna, ale z lukami w wykonaniu.',
                'C' => 'Decyzja poza regułami; wpis wymaga analizy.',
                default => null,
            },
            'note' => 'Ocena jakości decyzji pochodzi z zapisanej recenzji, nie z wyniku finansowego.',
        ];
    }

    /**
     * Wnioski zapisane w recenzji. Recenzja MCP nie ma osobnego pola zadań,
     * więc zwracamy tylko to, co faktycznie istnieje w danych.
     *
     * @return array{source:string,items:list<string>}
     */
    private function recommendationsFromReview(array $review): array
    {
        $items = [];
        foreach (['recommendations', 'tasks', 'lessons'] as $field) {
            foreach ((array) ($review[$field] ?? []) as $value) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }

        return [
            'source' => $items === [] ? 'none' : 'review',
            'items' => array_values(array_unique($items)),
        ];
    }
}
