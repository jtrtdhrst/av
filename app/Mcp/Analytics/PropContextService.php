<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Mcp\Support\Numbers;
use AlphaVault\Mcp\Support\PropChallengeService;

/**
 * Kontekst i budżet ryzyka challenge'u.
 *
 * Silnikiem jest PropChallengeService (ten sam, który liczy panel i parity test),
 * więc drawdown statyczny i trailing są liczone zgodnie z regułami firmy.
 */
final class PropContextService
{
    public function __construct(private readonly PropChallengeService $engine)
    {
    }

    public function context(array $state, ?string $challengeId): array
    {
        $selection = $this->select($state, $challengeId);
        if ($selection['challenge'] === null) {
            return [
                'found' => false,
                'challenge' => null,
                'metrics' => null,
                'available' => $selection['available'],
                'reason' => $selection['reason'],
            ];
        }
        $challenge = $selection['challenge'];
        $metrics = $selection['metrics'];
        $normalized = PropChallengeService::normalize($challenge);

        return [
            'found' => true,
            'challenge' => array_merge($normalized, [
                'firm' => (string) ($normalized['firm'] ?? ''),
                'phase' => (string) ($normalized['phase'] ?? ''),
                'templateId' => (string) ($normalized['templateId'] ?? ''),
            ]),
            'metrics' => $metrics,
            'context' => [
                'firm' => (string) ($normalized['firm'] ?? ''),
                'phase' => (string) ($normalized['phase'] ?? ''),
                'account' => (string) ($normalized['account'] ?? ''),
                'currency' => (string) ($normalized['currency'] ?? 'USD'),
                'startBalance' => $metrics['startBalance'],
                'currentBalance' => $metrics['balance'],
                'profitAmount' => $metrics['profitAmount'],
                'targetAmount' => $metrics['targetAmount'],
                'targetProgressPct' => $metrics['targetProgressPct'],
                'targetRemaining' => $metrics['targetRemaining'],
                'dailyLossLimit' => $metrics['dailyLimitAmount'],
                'dailyLossUsed' => $metrics['dailyLossAmount'],
                'maxLossLimit' => $metrics['maxLossAmount'],
                'maxDrawdownUsed' => $metrics['maxDrawdownAmount'],
                'daysLeft' => $metrics['daysLeft'],
                'daysTotal' => $metrics['daysTotal'],
                'tradingDays' => $metrics['tradingDays'],
                'minDays' => $metrics['minDays'],
                'status' => $metrics['status'],
                'riskLevel' => $metrics['riskLevel'],
                'drawdownType' => (string) ($normalized['drawdownType'] ?? 'static'),
                'dailyBasis' => (string) ($normalized['dailyBasis'] ?? 'initial'),
                'accountMissing' => $metrics['accountMissing'],
                'alerts' => $metrics['alerts'],
            ],
            'available' => $selection['available'],
            'selectedId' => $state['prop']['selectedId'] ?? null,
            'note' => 'Status i limity liczy silnik challenge\'u; drawdown trailing i static nie są wymienne.',
        ];
    }

    public function risk(array $state, ?string $challengeId): array
    {
        $selection = $this->select($state, $challengeId);
        if ($selection['challenge'] === null) {
            return [
                'found' => false,
                'challenge' => null,
                'hasData' => false,
                'available' => $selection['available'],
                'reason' => $selection['reason'],
            ];
        }
        $metrics = $selection['metrics'];
        $dailyBuffer = (float) $metrics['dailyBuffer'];
        $drawdownBuffer = (float) $metrics['drawdownBuffer'];
        $capacity = min($dailyBuffer, $drawdownBuffer);
        $usedPct = max((float) $metrics['dailyUsedPct'], (float) $metrics['drawdownUsedPct']);
        $balance = (float) $metrics['balance'];
        $violations = [];
        if ($metrics['dailyUsedPct'] >= 100) {
            $violations[] = ['rule' => 'daily_loss_limit', 'severity' => 'critical', 'message' => 'Dzienny limit straty wykorzystany.'];
        } elseif ($metrics['dailyUsedPct'] >= 75) {
            $violations[] = ['rule' => 'daily_loss_limit', 'severity' => 'high', 'message' => 'Wykorzystano co najmniej 75% dziennego limitu.'];
        }
        if ($metrics['drawdownUsedPct'] >= 100) {
            $violations[] = ['rule' => 'max_drawdown', 'severity' => 'critical', 'message' => 'Maksymalne obsunięcie przekroczone.'];
        } elseif ($metrics['drawdownUsedPct'] >= 75) {
            $violations[] = ['rule' => 'max_drawdown', 'severity' => 'high', 'message' => 'Wykorzystano co najmniej 75% limitu obsunięcia.'];
        }
        if ($metrics['expired'] && $metrics['status'] !== 'passed') {
            $violations[] = ['rule' => 'deadline', 'severity' => 'critical', 'message' => 'Termin challenge\'u upłynął.'];
        }
        if (!$metrics['minDaysMet'] && $metrics['daysLeft'] !== null && $metrics['status'] === 'active') {
            $missingDays = max(0, (int) $metrics['minDays'] - (int) $metrics['tradingDays']);
            if ($missingDays > (int) $metrics['daysLeft']) {
                $violations[] = ['rule' => 'min_days_deadline', 'severity' => 'high', 'message' => 'Brakuje ' . $missingDays . ' dni handlowych, a zostało ' . $metrics['daysLeft'] . ' dni.'];
            }
        }

        return [
            'found' => true,
            'challenge' => [
                'id' => (string) ($metrics['challenge']['id'] ?? ''),
                'name' => (string) ($metrics['challenge']['name'] ?? ''),
                'firm' => (string) ($metrics['challenge']['firm'] ?? ''),
                'phase' => (string) ($metrics['challenge']['phase'] ?? ''),
            ],
            'hasData' => true,
            'currency' => (string) $metrics['currency'],
            'budget' => [
                'remainingDaily' => Numbers::round($dailyBuffer),
                'remainingTotal' => Numbers::round($drawdownBuffer),
                'maxLossTodayWithoutBreach' => Numbers::round(max(0.0, $capacity)),
                'maxLossTodayPercentOfBalance' => $balance > 0 ? Numbers::round(max(0.0, $capacity) / $balance * 100, 2) : null,
                'riskUsedPct' => Numbers::round($usedPct),
                'safetyMarginPct' => Numbers::round(max(0.0, 100 - $usedPct)),
                'dailyLimitAmount' => $metrics['dailyLimitAmount'],
                'dailyLossAmount' => $metrics['dailyLossAmount'],
                'maxLossAmount' => $metrics['maxLossAmount'],
                'maxDrawdownAmount' => $metrics['maxDrawdownAmount'],
                'peak' => $metrics['peak'],
                'balance' => $metrics['balance'],
                'targetRemaining' => $metrics['targetRemaining'],
            ],
            'status' => $metrics['status'],
            'riskLevel' => $metrics['riskLevel'],
            'violations' => $violations,
            'alerts' => $metrics['alerts'],
            'note' => 'maxLossTodayWithoutBreach to mniejszy z dostępnego limitu dziennego i bufora obsunięcia. Nie jest rekomendacją wielkości pozycji.',
        ];
    }

    /**
     * @return array{challenge:?array,metrics:?array,available:list<array>,reason:string}
     */
    private function select(array $state, ?string $challengeId): array
    {
        $challenges = is_array($state['prop']['challenges'] ?? null) ? $state['prop']['challenges'] : [];
        $available = [];
        $normalized = [];
        foreach ($challenges as $challenge) {
            if (!is_array($challenge)) {
                continue;
            }
            $row = PropChallengeService::normalize($challenge);
            $normalized[] = $challenge;
            $available[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'firm' => (string) ($row['firm'] ?? ''),
                'phase' => (string) ($row['phase'] ?? ''),
                'account' => (string) ($row['account'] ?? ''),
                'status' => null,
            ];
        }
        if ($normalized === []) {
            return ['challenge' => null, 'metrics' => null, 'available' => [], 'reason' => 'no_challenges'];
        }
        $needle = $challengeId !== null ? trim($challengeId) : '';
        $selected = null;
        if ($needle !== '') {
            foreach ($normalized as $challenge) {
                $row = PropChallengeService::normalize($challenge);
                if ((string) ($row['id'] ?? '') === $needle) {
                    $selected = $challenge;
                    break;
                }
            }
            if ($selected === null) {
                return ['challenge' => null, 'metrics' => null, 'available' => $available, 'reason' => 'challenge_not_found'];
            }
        } else {
            $selectedId = (string) ($state['prop']['selectedId'] ?? '');
            foreach ($normalized as $challenge) {
                $row = PropChallengeService::normalize($challenge);
                if ($selectedId !== '' && (string) ($row['id'] ?? '') === $selectedId) {
                    $selected = $challenge;
                    break;
                }
            }
            $selected ??= $normalized[0];
        }
        $accountNames = [];
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (is_array($account) && (string) ($account['name'] ?? '') !== '') {
                $accountNames[] = (string) $account['name'];
            }
        }
        $metrics = $this->engine->compute($selected, (array) ($state['trades'] ?? []), null, $accountNames);
        $selectedNormalized = PropChallengeService::normalize($selected);
        foreach ($available as $index => $row) {
            $available[$index]['status'] = $row['id'] === (string) ($selectedNormalized['id'] ?? '') ? $metrics['status'] : null;
        }

        return ['challenge' => $selected, 'metrics' => $metrics, 'available' => $available, 'reason' => 'ok'];
    }
}
