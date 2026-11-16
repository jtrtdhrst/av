<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;
use AlphaVault\Mcp\Write\WriteEngine;
use AlphaVault\Mcp\Write\WriteException;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/**
 * Narzędzia zapisu MCP.
 *
 * Wszystkie operacje przechodzą przez `WriteEngine`, więc walidacja, formuły,
 * audyt i format danych są wspólne z zatwierdzaniem propozycji. Narzędzia
 * dzielą się na dwie klasy:
 *
 * - bezpośrednie: notatki, transakcje, recenzje, setupy, plany (szkic),
 *   check-iny, screeny — zapis od razu, odpowiedź potwierdza revision,
 * - wymagające zatwierdzenia: usuwanie, finanse, reguły ryzyka, prop
 *   challenges, zatwierdzenie planu i przywracanie z kosza — narzędzie
 *   tworzy propozycję `write_op`, a zapis wykonuje się dopiero po decyzji
 *   człowieka w panelu AlphaVault.
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
        ?float $mfe = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'trade.close', $this->compact([
            'tradeId' => $tradeId,
            'exitPrice' => $exitPrice,
            'result' => $result,
            'comment' => $comment,
            'mfe' => $mfe,
        ]), $expectedRevision, $idempotencyKey);
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
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'plan.update', $this->compact([
            'date' => $date,
            'changes' => $changes,
        ]), $expectedRevision, $idempotencyKey);
    }

    public function approveDayPlan(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'plan.approve', $this->compact(['date' => $date]), $expectedRevision, $idempotencyKey);
    }

    public function deleteDayPlan(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?int $expectedRevision = null
    ): array {
        return $this->write($context, 'plan.delete', $this->compact(['date' => $date]), $expectedRevision, $idempotencyKey);
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
        string $confirmationCode,
        string $idempotencyKey
    ): array {
        [$userId, $client] = $this->guard($context);
        try {
            return $this->proposals->approveWithCode(
                $userId,
                $proposalId,
                $expectedDiffHash,
                $confirmationCode,
                $idempotencyKey,
                $client
            );
        } catch (ProposalException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
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
            $preview = $this->engine->preview($userId, $state, $op, $args);
            $proposal = $this->proposals->create(
                $userId,
                $client,
                'write_op',
                $preview['target'],
                $preview['before'],
                ['op' => $op, 'args' => $args],
                $idempotencyKey,
                ['op' => $op, 'args' => $this->canonical($args), 'revision' => $revision],
                $preview['after']
            );

            return array_merge($proposal, [
                'ok' => true,
                'applied' => 'proposal',
                'operation' => $op,
                'preview' => $preview['after'],
                'note' => 'Nic nie zostało zapisane. Pokaż użytkownikowi diff i diffHash. Zapis wykona się po zatwierdzeniu w panelu AlphaVault kodem jednorazowym.',
            ]);
        } catch (WriteException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
        } catch (ProposalException $err) {
            throw new ToolCallException('[' . $err->errorCode . '] ' . $err->getMessage());
        }
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
        if ($token === null || !$token->hasScope('alphavault.write')) {
            throw new ToolCallException('[scope_missing] Brak zakresu alphavault.write. Połącz klienta ponownie i zaakceptuj zapis.');
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
    private function compact(array $args): array
    {
        return array_filter($args, static fn (mixed $value): bool => $value !== null);
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
