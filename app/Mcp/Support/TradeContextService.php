<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

use AlphaVault\Mcp\Analytics\DayPlanService;

final class TradeContextService
{
    private DayPlanService $plans;

    public function __construct(private readonly TradeService $trades)
    {
        $this->plans = new DayPlanService();
    }

    public function context(array $state, string $tradeId): ?array
    {
        $trade = null;
        foreach ((array) ($state['trades'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $tradeId) {
                $trade = $candidate;
                break;
            }
        }
        if ($trade === null) {
            return null;
        }

        $setupName = (string) ($trade['setup'] ?? '');
        $setup = null;
        foreach ((array) ($state['setups'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['name'] ?? '') === $setupName) {
                $setup = [
                    'id' => (string) ($candidate['id'] ?? ''),
                    'name' => $setupName,
                    'timeframe' => $candidate['tf'] ?? null,
                    'grade' => $candidate['grade'] ?? null,
                    'rules' => array_values(array_map('strval', is_array($candidate['rules'] ?? null) ? $candidate['rules'] : [])),
                    'avoid' => (bool) ($candidate['bad'] ?? false),
                ];
                break;
            }
        }

        $dateKey = Dates::dateKey((string) ($trade['date'] ?? ''));
        $plan = $this->plans->planForTrade($state, $trade);
        $dayPlans = $dateKey !== null ? $this->plans->plansForDate($state, $dateKey) : [];
        $planApproved = $plan !== null && !empty($plan['approvedAt']);

        return [
            'found' => true,
            'trade' => $this->trades->sanitize($trade, true),
            'review' => $state['tradeReviews'][$tradeId] ?? null,
            'setup' => $setup,
            'setupStats' => $setupName !== '' ? $this->trades->setupStats($state, $setupName) : null,
            'plan' => $plan,
            'plans' => $dayPlans,
            'planMatch' => [
                'planId' => $plan !== null ? (string) ($plan['id'] ?? '') : null,
                'ambiguous' => $plan === null && count($dayPlans) > 1,
                'approved' => $planApproved,
                'setupInPlaybook' => $setup !== null,
                'tradeMarkedPlan' => (bool) ($trade['plan'] ?? false),
                'aligned' => $planApproved && $setup !== null && (bool) ($trade['plan'] ?? false),
            ],
            'date' => $dateKey,
        ];
    }
}
