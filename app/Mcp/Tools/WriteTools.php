<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;
use AlphaVault\Mcp\Support\DayPlanRecords;
use AlphaVault\Mcp\Support\RecordDiff;
use AlphaVault\Mcp\Write\WriteEngine;
use AlphaVault\Mcp\Write\WriteException;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * Narzędzia zapisu MCP.
 *
 * Wszystkie operacje przechodzą przez `WriteEngine`, więc walidacja, formuły,
 * audyt i format danych są wspólne z zatwierdzaniem propozycji. Narzędzia
 * wszystkie 30 operacji może zapisywać bezpośrednio, a trzy narzędzia
 * opcjonalnie tworzą propozycję `write_op` wymagającą jawnej decyzji:
 *
 * Propozycje nie są obowiązkową bramką dla żadnej operacji zapisu. Zatwierdzenie
 * propozycji stosuje jej zamrożony snapshot po zgodności rewizji i diffHash.
 */
final class WriteTools
{
    private bool $writesEnabled;
    /** @var list<string> */
    private array $pilotIds;
    private WriteEngine $engine;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly ProposalService $proposals,
    ) {
        $this->writesEnabled = (bool) $config->mcp['writesEnabled'];
        $this->pilotIds = array_values(array_map('strval', (array) ($config->mcp['pilotDiscordIds'] ?? [])));
        $this->engine = new WriteEngine($db, $config);
    }

    // ------------------------------------------------------------------
    // Transakcje
    // ------------------------------------------------------------------

    public function createTrade(
        RequestContext $context,
        string $pair,
        string $dir,
        float $entry,
        float $size,
        string $idempotencyKey,
        ?float $sl = null,
        ?float $tp = null,
        ?float $fee = null,
        ?string $setup = null,
        ?string $emotion = null,
        ?string $grade = null,
        ?int $checklist = null,
        ?int $checkTotal = null,
        ?array $errors = null,
        ?string $note = null,
        ?string $session = null,
        ?string $date = null,
        ?string $account = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'trade.create', $this->compact([
            'pair' => $pair,
            'dir' => $dir,
            'entry' => $entry,
            'size' => $size,
            'sl' => $sl,
            'tp' => $tp,
            'fee' => $fee,
            'setup' => $setup,
            'emotion' => $emotion,
            'grade' => $grade,
            'checklist' => $checklist,
            'checkTotal' => $checkTotal,
            'errors' => $errors,
            'note' => $note,
            'session' => $session,
            'date' => $date,
            'account' => $account,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updateTrade(
        RequestContext $context,
        string $tradeId,
        array $changes,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'trade.update', $this->compact([
            'tradeId' => $tradeId,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function closeTrade(
        RequestContext $context,
        string $tradeId,
        string $idempotencyKey,
        ?float $exitPrice = null,
        ?float $result = null,
        ?string $comment = null,
        mixed $mfe = false,
        ?int $expectedRevision = null
    ): array {
        $args = [
            'tradeId' => $tradeId,
            'exitPrice' => $exitPrice,
            'result' => $result,
            'comment' => $comment,
        ];
        if ($mfe !== false) {
            $args['mfe'] = $mfe;
        }

        return $this->write($context, 'trade.close', $this->compact($args, ['mfe']), $expectedRevision, $idempotencyKey);
    }

    public function deleteTrade(
        RequestContext $context,
        string $tradeId,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'trade.delete', ['tradeId' => $tradeId], $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Recenzje transakcji
    // ------------------------------------------------------------------

    public function createTradeReview(
        RequestContext $context,
        string $tradeId,
        string $idempotencyKey,
        ?string $grade = null,
        ?string $setup = null,
        ?string $emotion = null,
        ?array $errors = null,
        ?int $checklist = null,
        ?string $note = null,
        ?array $recommendations = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'review.create', $this->compact([
            'tradeId' => $tradeId,
            'grade' => $grade,
            'setup' => $setup,
            'emotion' => $emotion,
            'errors' => $errors,
            'checklist' => $checklist,
            'note' => $note,
            'recommendations' => $recommendations,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updateTradeReview(
        RequestContext $context,
        string $tradeId,
        array $changes,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'review.update', $this->compact([
            'tradeId' => $tradeId,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function deleteTradeReview(
        RequestContext $context,
        string $tradeId,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'review.delete', ['tradeId' => $tradeId], $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Setupy
    // ------------------------------------------------------------------

    public function createSetup(
        RequestContext $context,
        string $name,
        array $rules,
        string $idempotencyKey,
        ?string $tf = null,
        ?string $grade = null,
        ?bool $bad = null,
        ?string $description = null,
        ?string $confirmation = null,
        ?string $invalidation = null,
        ?string $stopLoss = null,
        ?string $minRR = null,
        ?array $exitRules = null,
        ?array $examples = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'setup.create', $this->compact([
            'name' => $name,
            'rules' => $rules,
            'tf' => $tf,
            'grade' => $grade,
            'bad' => $bad,
            'description' => $description,
            'confirmation' => $confirmation,
            'invalidation' => $invalidation,
            'stopLoss' => $stopLoss,
            'minRR' => $minRR,
            'exitRules' => $exitRules,
            'examples' => $examples,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updateSetup(
        RequestContext $context,
        string $setupId,
        array $changes,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'setup.update', $this->compact([
            'setupId' => $setupId,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function deleteSetup(
        RequestContext $context,
        string $idempotencyKey,
        ?string $setupId = null,
        ?string $name = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'setup.delete', $this->compact([
            'setupId' => $setupId,
            'name' => $name,
        ]), $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Plan dnia
    // ------------------------------------------------------------------

    public function createDayPlan(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?string $market = null,
        ?array $markets = null,
        ?string $bias = null,
        ?string $why = null,
        ?array $levels = null,
        ?string $ifMain = null,
        ?string $thenMain = null,
        ?string $ifAlt = null,
        ?string $thenAlt = null,
        ?string $risk = null,
        ?int $maxTrades = null,
        ?string $until = null,
        ?string $notDo = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'plan.create', $this->compact([
            'date' => $date,
            'market' => $market,
            'markets' => $markets,
            'bias' => $bias,
            'why' => $why,
            'levels' => $levels,
            'ifMain' => $ifMain,
            'thenMain' => $thenMain,
            'ifAlt' => $ifAlt,
            'thenAlt' => $thenAlt,
            'risk' => $risk,
            'maxTrades' => $maxTrades,
            'until' => $until,
            'notDo' => $notDo,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updateDayPlan(
        RequestContext $context,
        array $changes,
        string $idempotencyKey,
        ?string $date = null,
        ?int $expectedRevision = null,
        ?string $planId = null
    ): array {
        return $this->write($context, 'plan.update', $this->compact([
            'date' => $date,
            'planId' => $planId,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function approveDayPlan(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?int $expectedRevision = null,
        ?string $planId = null
    ): array {
        return $this->write($context, 'plan.approve', $this->compact(['date' => $date, 'planId' => $planId]), $expectedRevision, $idempotencyKey);
    }

    public function deleteDayPlan(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?int $expectedRevision = null,
        ?string $planId = null
    ): array {
        return $this->write($context, 'plan.delete', $this->compact(['date' => $date, 'planId' => $planId]), $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Check-in
    // ------------------------------------------------------------------

    public function createCheckin(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?string $mood = null,
        ?int $energy = null,
        ?string $note = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'checkin.create', $this->compact([
            'date' => $date,
            'mood' => $mood,
            'energy' => $energy,
            'note' => $note,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updateCheckin(
        RequestContext $context,
        array $changes,
        string $idempotencyKey,
        ?string $date = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'checkin.update', $this->compact([
            'date' => $date,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function deleteCheckin(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'checkin.delete', $this->compact(['date' => $date]), $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Konta
    // ------------------------------------------------------------------

    public function createAccount(
        RequestContext $context,
        string $name,
        string $idempotencyKey,
        ?string $currency = null,
        ?string $exchange = null,
        ?string $type = null,
        ?float $start = null,
        ?float $balance = null,
        ?string $date = null,
        ?string $lev = null,
        ?string $goal = null,
        ?bool $include = null,
        ?bool $primary = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'account.create', $this->compact([
            'name' => $name,
            'currency' => $currency,
            'exchange' => $exchange,
            'type' => $type,
            'start' => $start,
            'balance' => $balance,
            'date' => $date,
            'lev' => $lev,
            'goal' => $goal,
            'include' => $include,
            'primary' => $primary,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updateAccount(
        RequestContext $context,
        string $accountId,
        array $changes,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'account.update', $this->compact([
            'accountId' => $accountId,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function deleteAccount(
        RequestContext $context,
        string $accountId,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'account.delete', ['accountId' => $accountId], $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Wpłaty i wypłaty
    // ------------------------------------------------------------------

    public function createAccountTransaction(
        RequestContext $context,
        float $amount,
        string $idempotencyKey,
        ?string $type = null,
        ?string $account = null,
        ?string $currency = null,
        ?string $date = null,
        ?float $fiat = null,
        ?float $fx = null,
        ?string $op = null,
        ?string $tax = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'flow.create', $this->compact([
            'amount' => $amount,
            'type' => $type,
            'account' => $account,
            'currency' => $currency,
            'date' => $date,
            'fiat' => $fiat,
            'fx' => $fx,
            'op' => $op,
            'tax' => $tax,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updateAccountTransaction(
        RequestContext $context,
        string $flowId,
        array $changes,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'flow.update', $this->compact([
            'flowId' => $flowId,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function deleteAccountTransaction(
        RequestContext $context,
        string $flowId,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'flow.delete', ['flowId' => $flowId], $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Reguły ryzyka
    // ------------------------------------------------------------------

    public function updateRiskRules(
        RequestContext $context,
        array $changes,
        string $reason,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'risk.update', [
            'changes' => $changes,
            'reason' => $reason,
        ], $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Prop challenges
    // ------------------------------------------------------------------

    public function createPropChallenge(
        RequestContext $context,
        string $idempotencyKey,
        ?string $name = null,
        ?string $firm = null,
        ?string $templateId = null,
        ?string $phase = null,
        ?string $account = null,
        ?string $currency = null,
        ?float $accountSize = null,
        ?float $startBalance = null,
        ?float $targetPct = null,
        ?float $dailyLossPct = null,
        ?float $maxLossPct = null,
        ?string $drawdownType = null,
        ?string $dailyBasis = null,
        ?int $minDays = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $notes = null,
        ?bool $archived = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'prop.create', $this->compact([
            'name' => $name,
            'firm' => $firm,
            'templateId' => $templateId,
            'phase' => $phase,
            'account' => $account,
            'currency' => $currency,
            'accountSize' => $accountSize,
            'startBalance' => $startBalance,
            'targetPct' => $targetPct,
            'dailyLossPct' => $dailyLossPct,
            'maxLossPct' => $maxLossPct,
            'drawdownType' => $drawdownType,
            'dailyBasis' => $dailyBasis,
            'minDays' => $minDays,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'notes' => $notes,
            'archived' => $archived,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function updatePropChallenge(
        RequestContext $context,
        string $challengeId,
        array $changes,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'prop.update', $this->compact([
            'challengeId' => $challengeId,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function deletePropChallenge(
        RequestContext $context,
        string $challengeId,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'prop.delete', ['challengeId' => $challengeId], $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Screeny transakcji
    // ------------------------------------------------------------------

    public function addTradeScreenshot(
        RequestContext $context,
        string $tradeId,
        string $dataUrl,
        string $idempotencyKey,
        ?string $position = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'screenshot.add', $this->compact([
            'tradeId' => $tradeId,
            'dataUrl' => $dataUrl,
            'position' => $position,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function deleteTradeScreenshot(
        RequestContext $context,
        string $tradeId,
        string $position,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'screenshot.delete', [
            'tradeId' => $tradeId,
            'position' => $position,
        ], $expectedRevision, $idempotencyKey);
    }

    // ------------------------------------------------------------------
    // Kosz i propozycje
    // ------------------------------------------------------------------

    public function restoreDeletedRecord(
        RequestContext $context,
        string $trashId,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'restore.record', ['trashId' => $trashId], $expectedRevision, $idempotencyKey);
    }

    public function proposeChange(
        RequestContext $context,
        string $operation,
        array $args,
        string $idempotencyKey,
        ?int $expectedRevision = null
    ): array {
        if (!in_array($operation, WriteEngine::OPERATIONS, true)) {
            throw new ToolCallException('unknown_operation: dozwolone operacje to ' . implode(', ', WriteEngine::OPERATIONS) . '.');
        }

        return $this->proposal($context, $operation, $args, $expectedRevision, $idempotencyKey);
    }

    public function approveProposal(
        RequestContext $context,
        string $proposalId,
        string $expectedDiffHash,
        bool $confirmed,
        string $idempotencyKey,
        ?string $confirmationCode = null
    ): array {
        [$userId, $client] = $this->guard($context);
        if (!$confirmed) {
            throw new ToolCallException('[confirmation_required] Zatwierdzenie wymaga confirmed=true po wyraźnym „tak” użytkownika w rozmowie.');
        }
        try {
            return $this->proposals->approveWithCode(
                $userId,
                $proposalId,
                $expectedDiffHash,
                $confirmationCode,
                $idempotencyKey,
                $client,
                $confirmed
            );
        } catch (ProposalException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
        } catch (\Throwable $err) {
            error_log('[mcp-write] approve proposal ' . $err::class . ': ' . $err->getMessage());
            throw new ToolCallException('[internal] Propozycja nie została zatwierdzona z powodu błędu serwera.');
        }
    }

    public function rejectProposal(RequestContext $context, string $proposalId, string $idempotencyKey): array
    {
        [$userId, $client] = $this->guard($context);
        try {
            return $this->proposals->rejectWithKey($userId, $proposalId, $idempotencyKey, $client);
        } catch (ProposalException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Wewnętrzne
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function write(RequestContext $context, string $op, array $args, ?int $expectedRevision, string $idempotencyKey): array
    {
        if (WriteEngine::requiresApproval($op)) {
            return $this->proposal($context, $op, $args, $expectedRevision, $idempotencyKey);
        }

        [$userId] = $this->guard($context);
        try {
            return $this->engine->execute($userId, $op, $args, $expectedRevision, $idempotencyKey);
        } catch (WriteException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
        } catch (\Throwable $err) {
            error_log('[mcp-write] ' . $op . ' ' . $err::class . ': ' . $err->getMessage() . ' @ ' . $err->getFile() . ':' . $err->getLine());

            throw new ToolCallException('[internal] Zapis nie został wykonany z powodu błędu serwera.');
        }
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function proposal(RequestContext $context, string $op, array $args, ?int $expectedRevision, string $idempotencyKey): array
    {
        [$userId, $client] = $this->guard($context);
        try {
            $existing = $this->db->queryOne(
                'SELECT kind, target_ref, after_json
                   FROM mcp_proposals WHERE user_id = ? AND idempotency_key = ?',
                [$userId, trim($idempotencyKey)]
            );
            $existingAfter = $existing !== null ? json_decode((string) ($existing['after_json'] ?? ''), true) : null;
            if ($existing !== null
                && (string) ($existing['kind'] ?? '') === 'write_op'
                && is_array($existingAfter)
                && (string) ($existingAfter['op'] ?? '') === $op) {
                $proposal = $this->proposals->create(
                    $userId,
                    $client,
                    'write_op',
                    (string) ($existing['target_ref'] ?? ''),
                    null,
                    ['op' => $op, 'args' => $args],
                    $idempotencyKey,
                    ['op' => $op, 'args' => $args],
                    null,
                    null,
                    null,
                    $expectedRevision
                );
                $previewAfter = $proposal['_previewAfter'] ?? null;
                unset($proposal['_previewAfter']);
                $alreadyApproved = (string) ($proposal['status'] ?? '') === 'approved';

                return array_merge($proposal, [
                    'ok' => true,
                    'applied' => 'proposal',
                    'operation' => $op,
                    'preview' => RecordDiff::redactValue($previewAfter),
                    'note' => $alreadyApproved
                        ? 'Ta propozycja została już zatwierdzona. Nie wykonano ponownego zapisu.'
                        : 'Powtórka klucza idempotencji zwróciła istniejącą propozycję; nie utworzono nowej zmiany.',
                ]);
            }

            $row = $this->db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ?', [$userId]);
            $state = $row !== null ? json_decode((string) ($row['state'] ?? ''), true) : [];
            if (!is_array($state)) {
                $state = [];
            }
            $revision = (int) ($row['revision'] ?? 0);
            if ($expectedRevision !== null && $expectedRevision !== $revision) {
                throw new ToolCallException(
                    '[revision_conflict] Dane zmieniły się od czasu odczytu (oczekiwano wersji ' . $expectedRevision . ', jest ' . $revision . '). Odczytaj rekord ponownie.'
                );
            }
            $basePreview = $this->engine->preview($userId, $state, $op, $args);
            $preparedTarget = $this->stableProposalTarget($userId, $state, $op, $basePreview, $idempotencyKey);
            $preview = $preparedTarget !== null && $preparedTarget !== ''
                ? $this->engine->preview($userId, $state, $op, $args, $preparedTarget)
                : $basePreview;
            $proposal = $this->proposals->create(
                $userId,
                $client,
                'write_op',
                $preview['target'],
                $preview['before'],
                [
                    'op' => $op,
                    'args' => $args,
                    'plannedState' => $preview['state'],
                    'plannedPreview' => [
                        'before' => $preview['before'],
                        'after' => $preview['after'],
                        'target' => $preview['target'],
                    ],
                ],
                $idempotencyKey,
                ['op' => $op, 'args' => $args],
                $preview['after'],
                $revision,
                $preview['stateChanges'],
                $expectedRevision
            );
            $previewAfter = $proposal['_previewAfter'] ?? $preview['after'];
            unset($proposal['_previewAfter']);

            return array_merge($proposal, [
                'ok' => true,
                'applied' => 'proposal',
                'operation' => $op,
                'preview' => RecordDiff::redactValue($previewAfter),
                'note' => 'Nic nie zostało zapisane. Pokaż użytkownikowi diff i diffHash. Po wyraźnym potwierdzeniu użytkownika wywołaj approve_proposal z confirmed=true; kod jednorazowy z panelu jest opcjonalny.',
            ]);
        } catch (ToolCallException $err) {
            throw $err;
        } catch (WriteException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
        } catch (ProposalException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
        } catch (\Throwable $err) {
            error_log('[mcp-write] ' . $op . ' proposal ' . $err::class . ': ' . $err->getMessage() . ' @ ' . $err->getFile() . ':' . $err->getLine());

            throw new ToolCallException('[internal] Propozycja nie została utworzona z powodu błędu serwera.');
        }
    }

    /**
     * Proposal IDs are derived from the idempotency key, so a retry produces
     * the same preview target. Existing proposal rows reuse their stored target.
     *
     * @param array<string,mixed> $state
     * @param array{target:string} $preview
     */
    private function stableProposalTarget(int $userId, array $state, string $op, array $preview, string $key): ?string
    {
        $resource = '';
        $prefix = '';
        $usedIds = [];
        switch ($op) {
            case 'plan.create':
                $resource = 'plan';
                $prefix = 'pl_';
                foreach (DayPlanRecords::all($state) as $record) {
                    $usedIds[] = $record['id'];
                }
                break;
            case 'account.create':
                $resource = 'account';
                $prefix = 'acc';
                $usedIds = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), (array) ($state['accounts'] ?? []));
                break;
            case 'flow.create':
                $resource = 'flow';
                $prefix = 'f';
                $usedIds = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), (array) ($state['flows'] ?? []));
                break;
            case 'prop.create':
                $resource = 'prop';
                $prefix = 'prop_';
                $usedIds = array_map(
                    static fn (array $row): string => (string) ($row['id'] ?? ''),
                    (array) ($state['prop']['challenges'] ?? [])
                );
                break;
            default:
                return null;
        }

        $date = '';
        if ($op === 'plan.create') {
            if (preg_match('/^plan:(\d{4}-\d{2}-\d{2})#/', (string) ($preview['target'] ?? ''), $match) !== 1) {
                throw new ToolCallException('[invalid_proposal_target] Nie można ustalić daty planu z podglądu.');
            }
            $date = $match[1];
        }
        for ($attempt = 0; $attempt < 1024; $attempt++) {
            $suffix = substr(hash('sha256', $userId . "\0" . trim($key) . "\0" . $op . "\0" . $attempt), 0, 12);
            $id = $prefix . $suffix;
            if (!in_array($id, $usedIds, true)) {
                return $resource === 'plan' ? 'plan:' . $date . '#' . $id : $resource . ':' . $id;
            }
        }

        throw new ToolCallException('[id_conflict] Nie można wygenerować unikalnego celu propozycji.');
    }

    /**
     * @return array{0:int,1:string}
     */
    private function guard(RequestContext $context): array
    {
        if (!$this->writesEnabled) {
            throw new ToolCallException('[writes_disabled] Zapis MCP jest wyłączony (MCP_WRITES_ENABLED=false).');
        }
        $token = $context->getAccessToken();
        if ($token === null) {
            throw new ToolCallException('[auth_required] Brak tokenu OAuth. Połącz klienta ponownie.');
        }
        $subject = $token->getSubject();
        if ($subject === null || !ctype_digit($subject)) {
            throw new ToolCallException('[auth_required] Brak zalogowanego użytkownika AlphaVault.');
        }
        $row = $this->db->queryOne('SELECT id, discord_id FROM users WHERE id = ? LIMIT 1', [(int) $subject]);
        if ($row === null) {
            throw new ToolCallException('[auth_required] Konto AlphaVault nie istnieje.');
        }
        if ($this->pilotIds !== [] && !in_array((string) ($row['discord_id'] ?? ''), $this->pilotIds, true)) {
            throw new ToolCallException('[pilot_only] Zapis MCP jest włączony tylko dla kont pilotażowych.');
        }

        return [(int) $row['id'], $token->getClientId() ?? 'Agent MCP'];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function compact(array $args, array $preserveNull = []): array
    {
        return array_filter(
            $args,
            static fn (mixed $value, int|string $key): bool => $value !== null || in_array($key, $preserveNull, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }
}
