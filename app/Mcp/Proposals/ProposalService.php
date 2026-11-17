<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Proposals;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\DayPlanRecords;
use AlphaVault\Mcp\Support\PropChallengeService;
use AlphaVault\Mcp\Write\WriteEngine;

/**
 * Model propozycji zmian MCP.
 *
 * Agent nigdy nie zapisuje stanu bezpośrednio. Tworzy wiersz pending, a zapis
 * następuje wyłącznie po decyzji użytkownika w panelu AlphaVault.
 */
final class ProposalService
{
    public const TTL_MINUTES = 30;
    public const STATUSES = ['pending', 'approved', 'rejected', 'expired'];
    public const KINDS = ['manual_trade', 'close_trade', 'trade_review', 'trade_update', 'day_plan', 'checkin', 'setup', 'prop_challenge', 'account', 'capital_flow', 'risk_rules', 'write_op'];
    public const APPROVAL_CODE_TTL_MINUTES = 10;
    public const APPROVAL_CODE_ATTEMPTS = 5;
    private const APPROVAL_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const EXCHANGE_FIELDS = [
        'entry', 'exit', 'exitPrice', 'price', 'fill', 'fillPrice', 'result', 'pnl',
        'size', 'qty', 'quantity', 'sl', 'tp', 'leverage', 'fee', 'fees',
        'balance', 'equity', 'source', 'exchangeId', 'externalId',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config
    ) {
    }

    public function create(int $userId, string $clientName, string $kind, string $targetRef, ?array $before, array $after, string $idempotencyKey, ?string $fingerprint = null, ?array $diffAfter = null): array
    {
        $key = trim($idempotencyKey);
        if (mb_strlen($key) < 8 || mb_strlen($key) > 120) {
            throw new ProposalException('bad_request', 'idempotencyKey musi mieć 8-120 znaków.', 400);
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new ProposalException('bad_request', 'Nieznany typ propozycji.', 400);
        }
        $diffTarget = $diffAfter ?? $after;
        $payloadHash = hash('sha256', $this->canonicalJson([
            'kind' => $kind,
            'target' => $targetRef,
            'fingerprint' => $fingerprint ?? ['after' => $this->payloadForHash($after)],
        ]));

        $this->db->execute(
            "UPDATE mcp_proposals
                SET status = 'expired'
              WHERE user_id = ? AND idempotency_key = ? AND status = 'pending'
                AND expires_at < UTC_TIMESTAMP()",
            [$userId, $key]
        );
        $existing = $this->db->queryOne(
            'SELECT id, kind, target_ref, status, expires_at, payload_hash, base_revision, diff_hash, diff_json, after_json FROM mcp_proposals WHERE user_id = ? AND idempotency_key = ?',
            [$userId, $key]
        );
        if ($existing !== null) {
            return $this->duplicateOrConflict($existing, $after, $fingerprint);
        }

        $revisionRow = $this->db->queryOne('SELECT revision FROM user_states WHERE user_id = ?', [$userId]);
        $revision = (int) ($revisionRow['revision'] ?? 0);
        $id = $this->uuid();
        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . self::TTL_MINUTES . ' minutes');
        $diff = $this->diff($before, $diffTarget);
        $diffHash = $this->diffHash($kind, $targetRef, $diff);

        try {
            $this->db->execute(
                'INSERT INTO mcp_proposals (id, user_id, client_name, kind, target_ref, base_revision, payload_hash, diff_hash, before_json, after_json, diff_json, idempotency_key, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $id,
                    $userId,
                    mb_substr($clientName !== '' ? $clientName : 'Agent MCP', 0, 100),
                    $kind,
                    $targetRef,
                    $revision,
                    $payloadHash,
                    $diffHash,
                    $before !== null ? $this->encode($before) : null,
                    $this->encode($after),
                    $this->encode($diff),
                    $key,
                    $expires->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $err) {
            if ((string) $err->getCode() === '23000') {
                $row = $this->db->queryOne(
                    'SELECT id, kind, target_ref, status, expires_at, payload_hash, base_revision, diff_hash, diff_json, after_json FROM mcp_proposals WHERE user_id = ? AND idempotency_key = ?',
                    [$userId, $key]
                );
                if ($row !== null) {
                    return $this->duplicateOrConflict($row, $after, $fingerprint);
                }
            }
            throw $err;
        }

        $this->audit($userId, $id, 'agent', 'mcp.proposal.create', [
            'kind' => $kind,
            'target' => $targetRef,
            'fields' => array_keys($diffTarget),
        ]);

        return [
            'proposalId' => $id,
            'status' => 'pending',
            'kind' => $kind,
            'target' => $targetRef,
            'baseRevision' => $revision,
            'diffHash' => $diffHash,
            'changes' => array_map(static fn (array $change): string => (string) $change['field'], $diff),
            'diff' => $diff,
            'expiresAt' => $expires->format('Y-m-d\TH:i:s\Z'),
            'note' => 'Propozycja czeka na decyzję. Nic nie zostało zmienione. Zatwierdź ją w panelu AlphaVault albo użyj wygenerowanego tam jednorazowego kodu w rozmowie z MCP.',
        ];
    }

    public function list(int $userId, string $status = 'pending'): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new ProposalException('bad_request', 'Nieznany status propozycji.', 400);
        }
        if ($status === 'pending') {
            $this->db->execute(
                "UPDATE mcp_proposals SET status = 'expired' WHERE user_id = ? AND status = 'pending' AND expires_at < UTC_TIMESTAMP()",
                [$userId]
            );
        }
        $rows = $this->db->query(
            'SELECT id, kind, target_ref, base_revision, diff_hash, before_json, after_json, diff_json, status, created_at, expires_at, decided_at, client_name,
                    approval_code_hash, approval_code_expires_at, approval_code_used_at
             FROM mcp_proposals WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 50',
            [$userId, $status]
        );

        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    public function status(int $userId, string $proposalId): array
    {
        $this->expireStale($userId);
        $row = $this->db->queryOne(
            'SELECT id, kind, target_ref, base_revision, diff_hash, before_json, after_json, diff_json, status, created_at, expires_at, decided_at, client_name,
                    approval_code_hash, approval_code_expires_at, approval_code_used_at
             FROM mcp_proposals WHERE id = ? AND user_id = ?',
            [$proposalId, $userId]
        );
        if ($row === null) {
            throw new ProposalException('not_found', 'Propozycja nie istnieje.', 404);
        }

        return $this->present($row);
    }

    /**
     * Jednorazowy kod potwierdzenia. Kod jest generowany wyłącznie w sesji
     * panelu AlphaVault, przechowywany jako hash i nigdy nie wraca przez MCP.
     */
    public function issueApprovalCode(int $userId, string $proposalId): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $proposal = $this->db->queryOne(
                'SELECT id, status, expires_at FROM mcp_proposals WHERE id = ? AND user_id = ? FOR UPDATE',
                [$proposalId, $userId]
            );
            if ($proposal === null) {
                throw new ProposalException('not_found', 'Propozycja nie istnieje.', 404);
            }
            if ((string) $proposal['status'] !== 'pending') {
                throw new ProposalException('proposal_closed', 'Propozycja nie jest już oczekująca.', 409);
            }
            $proposalExpiry = strtotime((string) $proposal['expires_at'] . ' UTC');
            if ($proposalExpiry === false || $proposalExpiry < time()) {
                $this->db->execute("UPDATE mcp_proposals SET status = 'expired' WHERE id = ?", [$proposalId]);
                $this->audit($userId, $proposalId, 'system', 'mcp.proposal.expire', []);
                $pdo->commit();
                throw new ProposalException('proposal_expired', 'Propozycja wygasła. Poproś agenta o nową.', 409);
            }

            $expiresTs = min(time() + self::APPROVAL_CODE_TTL_MINUTES * 60, $proposalExpiry);
            $code = $this->generateApprovalCode();
            $this->db->execute(
                'UPDATE mcp_proposals SET approval_code_hash = ?, approval_code_expires_at = ?, approval_code_attempts = 0, approval_code_used_at = NULL WHERE id = ?',
                [
                    $this->hashApprovalCode($code),
                    gmdate('Y-m-d H:i:s', $expiresTs),
                    $proposalId,
                ]
            );
            $this->audit($userId, $proposalId, 'user', 'mcp.proposal.code', [
                'channel' => 'panel',
                'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expiresTs),
            ]);
            $pdo->commit();

            return [
                'proposalId' => $proposalId,
                'code' => $code,
                'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expiresTs),
                'note' => 'Wpisz ten kod w rozmowie z agentem. Kod jest jednorazowy i wygasa po 10 minutach.',
            ];
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $err;
        }
    }

    /**
     * Zatwierdzenie propozycji przez MCP. Wymaga zgodnego hasha diffu i klucza
     * idempotencji decyzji. Kod z panelu jest opcjonalny: gdy go brak, wystarcza
     * potwierdzenie `confirmed: true` z rozmowy (sprawdzane w WriteTools).
     */
    public function approveWithCode(
        int $userId,
        string $proposalId,
        string $expectedDiffHash,
        ?string $confirmationCode,
        string $idempotencyKey,
        string $clientName,
        ?bool $confirmed = null
    ): array
    {
        $key = $this->decisionKey($idempotencyKey);
        $decisionHash = $this->decisionFingerprint('approve', $proposalId, $expectedDiffHash, $confirmationCode);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $proposal = $this->db->queryOne(
                'SELECT * FROM mcp_proposals WHERE id = ? AND user_id = ? FOR UPDATE',
                [$proposalId, $userId]
            );
            if ($proposal === null) {
                throw new ProposalException('not_found', 'Propozycja nie istnieje.', 404);
            }
            $replay = $this->decisionReplay(
                $userId,
                $key,
                $proposalId,
                'approved',
                $decisionHash,
                $expectedDiffHash,
                $confirmationCode
            );
            if ($replay !== null) {
                $pdo->commit();
                return $replay;
            }
            if ((string) $proposal['status'] !== 'pending') {
                throw new ProposalException('proposal_closed', 'Propozycja nie jest już oczekująca.', 409);
            }
            if (strtotime((string) $proposal['expires_at'] . ' UTC') < time()) {
                $this->db->execute("UPDATE mcp_proposals SET status = 'expired' WHERE id = ?", [$proposalId]);
                $this->audit($userId, $proposalId, 'system', 'mcp.proposal.expire', []);
                $pdo->commit();
                throw new ProposalException('proposal_expired', 'Propozycja wygasła. Poproś agenta o nową.', 409);
            }

            $storedHash = $this->diffHashFor($proposal);
            $expected = strtolower(trim($expectedDiffHash));
            if ($expected === '' || !hash_equals($storedHash, $expected)) {
                throw new ProposalException('diff_mismatch', 'Hash diffu nie zgadza się z propozycją. Odczytaj propozycję ponownie przez get_proposal_status.', 409);
            }

            if ($confirmationCode !== null) {
                $codeHash = (string) ($proposal['approval_code_hash'] ?? '');
                $codeExpiry = $proposal['approval_code_expires_at'] !== null
                    ? strtotime((string) $proposal['approval_code_expires_at'] . ' UTC')
                    : false;
                if ($codeHash === '' || $proposal['approval_code_used_at'] !== null || $codeExpiry === false || $codeExpiry < time()) {
                    throw new ProposalException('confirmation_required', 'Brak aktywnego kodu potwierdzenia. Zatwierdź w rozmowie z confirmed=true albo wygeneruj kod w panelu AlphaVault.', 403);
                }
                if (!$this->verifyApprovalCode($confirmationCode, $codeHash)) {
                    $attempts = (int) ($proposal['approval_code_attempts'] ?? 0) + 1;
                    $invalidate = $attempts >= self::APPROVAL_CODE_ATTEMPTS ? 1 : 0;
                    $this->db->execute(
                        'UPDATE mcp_proposals
                            SET approval_code_attempts = ?,
                                approval_code_hash = IF(? = 1, NULL, approval_code_hash),
                                approval_code_expires_at = IF(? = 1, NULL, approval_code_expires_at)
                          WHERE id = ?',
                        [$attempts, $invalidate, $invalidate, $proposalId]
                    );
                    $this->audit($userId, $proposalId, 'user', 'mcp.proposal.code_failed', [
                        'channel' => 'mcp',
                        'attempts' => $attempts,
                        'invalidated' => $invalidate === 1,
                    ]);
                    $pdo->commit();
                    throw new ProposalException('invalid_confirmation', 'Kod potwierdzenia jest nieprawidłowy.', 403);
                }
            }

            $row = $this->db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ? FOR UPDATE', [$userId]);
            $state = $row !== null ? json_decode((string) $row['state'], true) : [];
            if (!is_array($state)) {
                $state = [];
            }
            $revision = (int) ($row['revision'] ?? 0);
            $expectedRevision = $proposal['base_revision'] !== null ? (int) $proposal['base_revision'] : 0;
            if ($expectedRevision !== $revision) {
                throw new ProposalException(
                    'revision_conflict',
                    'Dane zmieniły się od chwili przygotowania propozycji. Poproś agenta o nową propozycję.',
                    409
                );
            }

            $after = json_decode((string) $proposal['after_json'], true);
            if (!is_array($after)) {
                throw new ProposalException('bad_proposal', 'Propozycja jest uszkodzona.', 400);
            }
            $state = $this->apply($userId, $state, (string) $proposal['kind'], (string) ($proposal['target_ref'] ?? ''), $after);
            $json = $this->encode($state);
            $bytes = strlen($json);
            if ($bytes > $this->config->maxStateBytes) {
                throw new ProposalException('state_too_large', 'Stan przekracza dopuszczalny rozmiar.', 413);
            }

            if ($row === null) {
                $this->db->execute(
                    'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)',
                    [$userId, $json, $bytes]
                );
                $revision = 1;
            } else {
                $this->db->execute(
                    'UPDATE user_states SET state = ?, revision = ?, bytes = ? WHERE user_id = ?',
                    [$json, $revision + 1, $bytes, $userId]
                );
                $revision++;
            }

            $result = [
                'ok' => true,
                'status' => 'approved',
                'proposalId' => $proposalId,
                'revision' => $revision,
                'kind' => (string) $proposal['kind'],
                'target' => (string) ($proposal['target_ref'] ?? ''),
                'diffHash' => $storedHash,
            ];
            $this->db->execute(
                "UPDATE mcp_proposals
                    SET status = 'approved', decided_at = UTC_TIMESTAMP(), decided_by = ?,
                        approval_code_used_at = IF(? IS NULL, approval_code_used_at, UTC_TIMESTAMP()),
                        decision_key = ?, decision_hash = ?, decision_result_json = ?
                  WHERE id = ?",
                [$userId, $confirmationCode, $key, $decisionHash, $this->encode($result), $proposalId]
            );
            $this->audit($userId, $proposalId, 'user', 'mcp.proposal.approve', [
                'kind' => (string) $proposal['kind'],
                'revision' => $revision,
                'decidedBy' => $userId,
                'channel' => 'mcp',
                'confirmed' => $confirmed ?? ($confirmationCode === null),
                'client' => mb_substr($clientName !== '' ? $clientName : 'Agent MCP', 0, 100),
                'diffHash' => $storedHash,
            ]);
            $pdo->commit();

            return $result;
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($err instanceof \PDOException && (string) $err->getCode() === '23000') {
                throw new ProposalException('idempotency_conflict', 'Ten sam idempotencyKey użyto dla innej decyzji.', 409);
            }
            throw $err;
        }
    }

    public function rejectWithKey(int $userId, string $proposalId, string $idempotencyKey, string $clientName): array
    {
        $key = $this->decisionKey($idempotencyKey);
        $decisionHash = $this->decisionFingerprint('reject', $proposalId, null, null);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $proposal = $this->db->queryOne(
                'SELECT id, status, expires_at FROM mcp_proposals WHERE id = ? AND user_id = ? FOR UPDATE',
                [$proposalId, $userId]
            );
            if ($proposal === null) {
                throw new ProposalException('not_found', 'Propozycja nie istnieje.', 404);
            }
            $replay = $this->decisionReplay($userId, $key, $proposalId, 'rejected', $decisionHash);
            if ($replay !== null) {
                $pdo->commit();
                return $replay;
            }
            if ((string) $proposal['status'] !== 'pending') {
                throw new ProposalException('proposal_closed', 'Propozycja nie jest już oczekująca.', 409);
            }
            if (strtotime((string) $proposal['expires_at'] . ' UTC') < time()) {
                $this->db->execute("UPDATE mcp_proposals SET status = 'expired' WHERE id = ?", [$proposalId]);
                $this->audit($userId, $proposalId, 'system', 'mcp.proposal.expire', []);
                $pdo->commit();
                throw new ProposalException('proposal_expired', 'Propozycja wygasła. Poproś agenta o nową.', 409);
            }
            $result = [
                'ok' => true,
                'status' => 'rejected',
                'proposalId' => $proposalId,
            ];
            $this->db->execute(
                "UPDATE mcp_proposals
                    SET status = 'rejected', decided_at = UTC_TIMESTAMP(), decided_by = ?,
                        decision_key = ?, decision_hash = ?, decision_result_json = ?
                  WHERE id = ?",
                [$userId, $key, $decisionHash, $this->encode($result), $proposalId]
            );
            $this->audit($userId, $proposalId, 'user', 'mcp.proposal.reject', [
                'decidedBy' => $userId,
                'channel' => 'mcp',
                'client' => mb_substr($clientName !== '' ? $clientName : 'Agent MCP', 0, 100),
            ]);
            $pdo->commit();

            return $result;
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($err instanceof \PDOException && (string) $err->getCode() === '23000') {
                throw new ProposalException('idempotency_conflict', 'Ten sam idempotencyKey użyto dla innej decyzji.', 409);
            }
            throw $err;
        }
    }

    public function approve(int $userId, string $proposalId, int $decidedBy): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $proposal = $this->db->queryOne(
                'SELECT * FROM mcp_proposals WHERE id = ? AND user_id = ? FOR UPDATE',
                [$proposalId, $userId]
            );
            if ($proposal === null) {
                throw new ProposalException('not_found', 'Propozycja nie istnieje.', 404);
            }
            if ((string) $proposal['status'] !== 'pending') {
                throw new ProposalException('proposal_closed', 'Propozycja nie jest już oczekująca.', 409);
            }
            if (strtotime((string) $proposal['expires_at'] . ' UTC') < time()) {
                $this->db->execute("UPDATE mcp_proposals SET status = 'expired' WHERE id = ?", [$proposalId]);
                $this->audit($userId, $proposalId, 'system', 'mcp.proposal.expire', []);
                $pdo->commit();
                throw new ProposalException('proposal_expired', 'Propozycja wygasła. Poproś agenta o nową.', 409);
            }

            $row = $this->db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ? FOR UPDATE', [$userId]);
            $state = $row !== null ? json_decode((string) $row['state'], true) : [];
            if (!is_array($state)) {
                $state = [];
            }
            $revision = (int) ($row['revision'] ?? 0);
            $expected = $proposal['base_revision'] !== null ? (int) $proposal['base_revision'] : 0;
            if ($expected !== $revision) {
                throw new ProposalException(
                    'revision_conflict',
                    'Dane zmieniły się od chwili przygotowania propozycji. Poproś agenta o nową propozycję.',
                    409
                );
            }

            $after = json_decode((string) $proposal['after_json'], true);
            if (!is_array($after)) {
                throw new ProposalException('bad_proposal', 'Propozycja jest uszkodzona.', 400);
            }

            $state = $this->apply($userId, $state, (string) $proposal['kind'], (string) ($proposal['target_ref'] ?? ''), $after);
            $json = $this->encode($state);
            $bytes = strlen($json);
            if ($bytes > $this->config->maxStateBytes) {
                throw new ProposalException('state_too_large', 'Stan przekracza dopuszczalny rozmiar.', 413);
            }

            if ($row === null) {
                $this->db->execute(
                    'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)',
                    [$userId, $json, $bytes]
                );
                $revision = 1;
            } else {
                $this->db->execute(
                    'UPDATE user_states SET state = ?, revision = ?, bytes = ? WHERE user_id = ?',
                    [$json, $revision + 1, $bytes, $userId]
                );
                $revision++;
            }

            $this->db->execute(
                "UPDATE mcp_proposals SET status = 'approved', decided_at = UTC_TIMESTAMP(), decided_by = ? WHERE id = ?",
                [$decidedBy, $proposalId]
            );
            $this->audit($userId, $proposalId, 'user', 'mcp.proposal.approve', [
                'kind' => (string) $proposal['kind'],
                'revision' => $revision,
                'decidedBy' => $decidedBy,
            ]);
            $pdo->commit();

            return ['ok' => true, 'status' => 'approved', 'revision' => $revision, 'kind' => (string) $proposal['kind']];
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $err;
        }
    }

    public function reject(int $userId, string $proposalId, int $decidedBy): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $proposal = $this->db->queryOne(
                'SELECT id, status FROM mcp_proposals WHERE id = ? AND user_id = ? FOR UPDATE',
                [$proposalId, $userId]
            );
            if ($proposal === null || (string) $proposal['status'] !== 'pending') {
                throw new ProposalException('not_found', 'Propozycja nie istnieje lub została już rozstrzygnięta.', 404);
            }
            $this->db->execute(
                "UPDATE mcp_proposals SET status = 'rejected', decided_at = UTC_TIMESTAMP(), decided_by = ? WHERE id = ?",
                [$decidedBy, $proposalId]
            );
            $this->audit($userId, $proposalId, 'user', 'mcp.proposal.reject', ['decidedBy' => $decidedBy]);
            $pdo->commit();

            return ['ok' => true, 'status' => 'rejected'];
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $err;
        }
    }

    public function auditLog(int $userId, int $limit = 50): array
    {
        return $this->db->query(
            'SELECT proposal_id, actor, action, detail_json, created_at FROM mcp_audit WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$userId]
        );
    }

    public function expireStale(int $userId): int
    {
        return $this->db->execute(
            "UPDATE mcp_proposals SET status = 'expired' WHERE user_id = ? AND status = 'pending' AND expires_at < UTC_TIMESTAMP()",
            [$userId]
        );
    }

    private function present(array $row): array
    {
        $diff = json_decode((string) ($row['diff_json'] ?? '[]'), true);
        $before = $row['before_json'] !== null ? json_decode((string) $row['before_json'], true) : null;
        $after = $row['after_json'] !== null ? json_decode((string) $row['after_json'], true) : null;
        $status = (string) $row['status'];
        $expiresTs = isset($row['expires_at']) ? strtotime((string) $row['expires_at'] . ' UTC') : false;
        $expired = $status === 'pending' && $expiresTs !== false && $expiresTs < time();
        $codeExpiry = isset($row['approval_code_expires_at']) && $row['approval_code_expires_at'] !== null
            ? strtotime((string) $row['approval_code_expires_at'] . ' UTC')
            : false;
        $codeActive = $status === 'pending'
            && !$expired
            && (string) ($row['approval_code_hash'] ?? '') !== ''
            && ($row['approval_code_used_at'] ?? null) === null
            && $codeExpiry !== false
            && $codeExpiry >= time();

        return [
            'id' => (string) $row['id'],
            'proposalId' => (string) $row['id'],
            'kind' => (string) $row['kind'],
            'target' => $row['target_ref'],
            'status' => $status,
            'baseRevision' => $row['base_revision'] !== null ? (int) $row['base_revision'] : null,
            'diffHash' => $this->diffHashFor($row),
            'changes' => is_array($diff) ? array_values(array_map(static fn (array $change): string => (string) ($change['field'] ?? ''), $diff)) : [],
            'before' => $before,
            'after' => $after,
            'diff' => is_array($diff) ? $diff : [],
            'createdAt' => $row['created_at'] ?? null,
            'expiresAt' => $row['expires_at'] ?? null,
            'expiresAtIso' => isset($row['expires_at']) ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $row['expires_at'] . ' UTC')) : null,
            'decidedAt' => $row['decided_at'] ?? null,
            'clientName' => $row['client_name'] ?? null,
            'canApprove' => $status === 'pending' && !$expired,
            'approvalCodeActive' => $codeActive,
            'approvalCodeExpiresAt' => $codeActive ? gmdate('Y-m-d\TH:i:s\Z', (int) $codeExpiry) : null,
        ];
    }

    private function decisionKey(string $idempotencyKey): string
    {
        $key = trim($idempotencyKey);
        if (mb_strlen($key) < 8 || mb_strlen($key) > 120) {
            throw new ProposalException('bad_request', 'idempotencyKey musi mieć 8–120 znaków.', 400);
        }

        return $key;
    }

    private function decisionFingerprint(string $action, string $proposalId, ?string $expectedDiffHash, ?string $confirmationCode): string
    {
        return hash('sha256', $this->canonicalJson([
            'action' => $action,
            'proposalId' => $proposalId,
            'expectedDiffHash' => $expectedDiffHash !== null ? strtolower(trim($expectedDiffHash)) : null,
            'confirmationCodeHash' => $confirmationCode !== null ? $this->hashApprovalCode($confirmationCode) : null,
        ]));
    }

    private function decisionReplay(
        int $userId,
        string $key,
        string $proposalId,
        string $expectedStatus,
        string $decisionHash,
        ?string $expectedDiffHash = null,
        ?string $confirmationCode = null,
    ): ?array
    {
        $row = $this->db->queryOne(
            'SELECT id, status, diff_hash, approval_code_hash, approval_code_used_at,
                    decision_hash, decision_result_json
               FROM mcp_proposals
              WHERE user_id = ? AND decision_key = ?
              LIMIT 1',
            [$userId, $key]
        );
        if ($row === null) {
            return null;
        }
        if ((string) $row['id'] !== $proposalId) {
            throw new ProposalException('idempotency_conflict', 'Ten sam idempotencyKey użyto dla innej propozycji.', 409);
        }
        $storedHash = (string) ($row['decision_hash'] ?? '');
        if ($storedHash === '') {
            // Older decisions predate decision_hash. Reconstruct only when the
            // persisted status and approval evidence prove the exact request.
            $legacyMatches = (string) ($row['status'] ?? '') === $expectedStatus;
            if ($legacyMatches && $expectedStatus === 'approved') {
                $storedDiffHash = strtolower((string) ($row['diff_hash'] ?? ''));
                $requestedDiffHash = strtolower(trim((string) $expectedDiffHash));
                $legacyMatches = $requestedDiffHash !== ''
                    && $storedDiffHash !== ''
                    && hash_equals($storedDiffHash, $requestedDiffHash);
                $codeWasUsed = ($row['approval_code_used_at'] ?? null) !== null;
                if ($legacyMatches && $codeWasUsed) {
                    $codeHash = (string) ($row['approval_code_hash'] ?? '');
                    $legacyMatches = $confirmationCode !== null
                        && $codeHash !== ''
                        && $this->verifyApprovalCode($confirmationCode, $codeHash);
                } elseif ($legacyMatches) {
                    $legacyMatches = $confirmationCode === null;
                }
            }
            if (!$legacyMatches) {
                throw new ProposalException('idempotency_conflict', 'Ten sam idempotencyKey użyto z inną treścią decyzji.', 409);
            }
            $this->db->execute(
                'UPDATE mcp_proposals SET decision_hash = ? WHERE id = ? AND decision_hash IS NULL',
                [$decisionHash, $proposalId]
            );
            $storedHash = $decisionHash;
        }
        if (!hash_equals($storedHash, $decisionHash)) {
            throw new ProposalException('idempotency_conflict', 'Ten sam idempotencyKey użyto z inną treścią decyzji.', 409);
        }
        $result = json_decode((string) ($row['decision_result_json'] ?? ''), true);
        if (!is_array($result)) {
            $result = ['ok' => true, 'status' => $expectedStatus, 'proposalId' => $proposalId];
        }
        if ((string) ($result['status'] ?? '') !== $expectedStatus) {
            throw new ProposalException('idempotency_conflict', 'Ten sam idempotencyKey użyto dla innej decyzji.', 409);
        }
        $result['duplicate'] = true;

        return $result;
    }

    private function generateApprovalCode(): string
    {
        $alphabet = self::APPROVAL_CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }

    private function normalizeApprovalCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code)));
    }

    private function hashApprovalCode(string $code): string
    {
        return hash('sha256', 'alphavault-approval:' . $this->normalizeApprovalCode($code));
    }

    private function verifyApprovalCode(string $code, string $storedHash): bool
    {
        $normalized = $this->normalizeApprovalCode($code);
        if ($normalized === '' || strlen($normalized) !== 8) {
            return false;
        }

        return hash_equals($storedHash, $this->hashApprovalCode($normalized));
    }

    private function diffHash(string $kind, string $targetRef, array $diff): string
    {
        return hash('sha256', $this->canonicalJson([
            'kind' => $kind,
            'target' => $targetRef,
            'diff' => $diff,
        ]));
    }

    /**
     * Hash diffu jest liczony raz przy utworzeniu propozycji. Starsze wiersze
     * bez zapisanego hasha dostają go przy pierwszym odczycie.
     */
    private function diffHashFor(array $row): string
    {
        $stored = trim((string) ($row['diff_hash'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $diff = json_decode((string) ($row['diff_json'] ?? '[]'), true);
        $hash = $this->diffHash(
            (string) ($row['kind'] ?? ''),
            (string) ($row['target_ref'] ?? ''),
            is_array($diff) ? $diff : []
        );
        if (($row['id'] ?? '') !== '') {
            try {
                $this->db->execute(
                    'UPDATE mcp_proposals SET diff_hash = ? WHERE id = ? AND (diff_hash IS NULL OR diff_hash = ?)',
                    [$hash, (string) $row['id'], '']
                );
            } catch (\Throwable) {
                // Brak kolumny nie może blokować odczytu propozycji.
            }
        }

        return $hash;
    }

    private function apply(int $userId, array $state, string $kind, string $targetRef, array $after): array
    {
        switch ($kind) {
            case 'manual_trade':
                $this->assertManualTrade($after);
                $trades = is_array($state['trades'] ?? null) ? $state['trades'] : [];
                foreach ($trades as $trade) {
                    if ((string) ($trade['id'] ?? '') === (string) $after['id']) {
                        throw new ProposalException('proposal_closed', 'Transakcja o tym identyfikatorze już istnieje.', 409);
                    }
                }
                $trades[] = $after;
                $state['trades'] = $trades;
                break;

            case 'close_trade':
                $tradeId = str_starts_with($targetRef, 'trade:') ? substr($targetRef, 6) : '';
                $index = null;
                foreach ((array) ($state['trades'] ?? []) as $i => $trade) {
                    if ((string) ($trade['id'] ?? '') === $tradeId) {
                        $index = $i;
                        break;
                    }
                }
                if ($index === null) {
                    throw new ProposalException('trade_missing', 'Transakcja nie istnieje już w stanie konta.', 409);
                }
                $trade = $state['trades'][$index];
                if (($trade['result'] ?? null) !== null) {
                    throw new ProposalException('already_closed', 'Transakcja jest już zamknięta.', 409);
                }
                if ($this->isExchangeTrade($userId, $trade)) {
                    throw new ProposalException('exchange_data_readonly', 'Transakcja importowana z giełdy jest tylko do odczytu.', 403);
                }
                $this->assertClosePayload($trade, $after);
                foreach (['exit', 'result', 'r', 'mfe', 'durationMin', 'mentor'] as $field) {
                    if (array_key_exists($field, $after)) {
                        $state['trades'][$index][$field] = $after[$field];
                    }
                }
                break;

            case 'trade_review':
                $tradeId = str_starts_with($targetRef, 'trade:') ? substr($targetRef, 6) : '';
                $exists = false;
                foreach ((array) ($state['trades'] ?? []) as $trade) {
                    if ((string) ($trade['id'] ?? '') === $tradeId) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    throw new ProposalException('trade_missing', 'Transakcja nie istnieje już w stanie konta.', 409);
                }
                $this->assertReviewPayload($after);
                $reviews = is_array($state['tradeReviews'] ?? null) ? $state['tradeReviews'] : [];
                $reviews[$tradeId] = $after;
                $state['tradeReviews'] = $reviews;
                break;

            case 'trade_update':
                $tradeId = str_starts_with($targetRef, 'trade:') ? substr($targetRef, 6) : '';
                $index = null;
                foreach ((array) ($state['trades'] ?? []) as $i => $trade) {
                    if ((string) ($trade['id'] ?? '') === $tradeId) {
                        $index = $i;
                        break;
                    }
                }
                if ($index === null) {
                    throw new ProposalException('trade_missing', 'Transakcja nie istnieje już w stanie konta.', 409);
                }
                $this->assertTradeUpdate($userId, $state['trades'][$index], $after);
                $state['trades'][$index] = $after;
                break;

            case 'risk_rules':
                $this->assertRiskRules($after);
                $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
                $patch = $after;
                unset($patch['reason']);
                $state['settings'] = array_merge($settings, $patch);
                break;

            case 'day_plan':
                $target = str_starts_with($targetRef, 'plan:') ? substr($targetRef, 5) : '';
                [$date, $targetPlanId] = array_pad(explode('#', $target, 2), 2, '');
                if (!Dates::isValidKey($date)) {
                    throw new ProposalException('bad_proposal', 'Nieprawidłowa data planu.', 400);
                }
                $this->assertDayPlan($after);
                $plans = is_array($state['plans'] ?? null) ? $state['plans'] : [];
                $records = DayPlanRecords::forDate($state, $date);
                $hasTargetPlanId = trim($targetPlanId) !== '';
                $planId = $hasTargetPlanId ? trim($targetPlanId) : '';
                $matches = $hasTargetPlanId
                    ? array_values(array_filter($records, static fn (array $record): bool => $record['id'] === $planId))
                    : $records;
                if (!$hasTargetPlanId && count($matches) > 1) {
                    throw new ProposalException('plan_id_required', 'Na ' . $date . ' jest wiele planów. Propozycja wymaga planId.', 409);
                }
                if (count($matches) > 1) {
                    throw new ProposalException('plan_id_ambiguous', 'planId nie identyfikuje jednoznacznie planu na ' . $date . '.', 409);
                }
                $existing = $matches[0] ?? null;
                if (!$hasTargetPlanId) {
                    if ($existing !== null) {
                        $planId = $existing['id'];
                    } else {
                        $planId = trim((string) ($after['id'] ?? ''));
                        if ($planId === '') {
                            $planId = DayPlanRecords::uniqueId($state);
                        }
                    }
                }
                if ($existing === null) {
                    foreach (DayPlanRecords::all($state) as $record) {
                        if ($record['id'] === $planId) {
                            throw new ProposalException('plan_id_conflict', 'ID planu jest już używane przez inny dzień.', 409);
                        }
                    }
                }
                $after['id'] = $planId;
                $after['date'] = $date;
                $key = $existing['key'] ?? DayPlanRecords::insertKey($state, $date, $planId);
                $plans[$key] = $after;
                $state['plans'] = $plans;
                break;

            case 'checkin':
                $this->assertCheckin($after);
                $date = str_starts_with($targetRef, 'checkin:') ? substr($targetRef, 8) : (string) ($after['date'] ?? '');
                if (!Dates::isValidKey($date)) {
                    throw new ProposalException('bad_proposal', 'Nieprawidłowa data check-inu.', 400);
                }
                $checkins = is_array($state['checkins'] ?? null) ? $state['checkins'] : [];
                $checkins = array_values(array_filter($checkins, static fn (array $row): bool => (string) ($row['date'] ?? '') !== $date));
                $checkins[] = $after;
                $state['checkins'] = $checkins;
                break;

            case 'setup':
                $this->assertSetup($after);
                $setups = is_array($state['setups'] ?? null) ? $state['setups'] : [];
                $setupId = str_starts_with($targetRef, 'setup:') ? substr($targetRef, 6) : '';
                $found = false;
                foreach ($setups as $i => $setup) {
                    if ((string) ($setup['id'] ?? '') === $setupId) {
                        $setups[$i] = $after;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    if ($targetRef !== 'setup:new') {
                        throw new ProposalException('target_missing', 'Setup nie istnieje już w Playbooku. Poproś agenta o nową propozycję.', 409);
                    }
                    $setups[] = $after;
                }
                $state['setups'] = array_values($setups);
                break;

            case 'prop_challenge':
                $this->assertPropChallenge($after);
                $prop = is_array($state['prop'] ?? null) ? $state['prop'] : ['challenges' => [], 'selectedId' => null];
                $challenges = is_array($prop['challenges'] ?? null) ? $prop['challenges'] : [];
                $challengeId = str_starts_with($targetRef, 'prop:') ? substr($targetRef, 5) : '';
                $found = false;
                foreach ($challenges as $i => $challenge) {
                    if ((string) ($challenge['id'] ?? '') === $challengeId) {
                        $challenges[$i] = $after;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    if ($targetRef !== 'prop:new') {
                        throw new ProposalException('target_missing', 'Challenge nie istnieje już w koncie. Poproś agenta o nową propozycję.', 409);
                    }
                    $challenges[] = $after;
                    if (empty($prop['selectedId'])) {
                        $prop['selectedId'] = (string) ($after['id'] ?? '');
                    }
                }
                $prop['challenges'] = array_values($challenges);
                $state['prop'] = $prop;
                break;

            case 'account':
                $this->assertAccount($after, $targetRef === 'account:new');
                $accounts = is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
                $accountId = str_starts_with($targetRef, 'account:') ? substr($targetRef, 8) : '';
                $found = false;
                foreach ($accounts as $i => $account) {
                    if (is_array($account) && (string) ($account['id'] ?? '') === $accountId) {
                        $accounts[$i] = $after;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    if ($targetRef !== 'account:new') {
                        throw new ProposalException('target_missing', 'Konto nie istnieje już w ustawieniach. Poproś agenta o nową propozycję.', 409);
                    }
                    $accounts[] = $after;
                }
                if (!empty($after['primary'])) {
                    foreach ($accounts as $i => $account) {
                        if (is_array($account) && (string) ($account['id'] ?? '') !== (string) ($after['id'] ?? '')) {
                            $accounts[$i]['primary'] = false;
                        }
                    }
                }
                $state['accounts'] = array_values($accounts);
                break;

            case 'capital_flow':
                $this->assertCapitalFlow($after);
                $flows = is_array($state['flows'] ?? null) ? $state['flows'] : [];
                $flowId = str_starts_with($targetRef, 'flow:') ? substr($targetRef, 5) : '';
                $found = false;
                foreach ($flows as $i => $flow) {
                    if (is_array($flow) && (string) ($flow['id'] ?? '') === $flowId) {
                        $flows[$i] = $after;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    if ($targetRef !== 'flow:new') {
                        throw new ProposalException('target_missing', 'Wpis kapitałowy nie istnieje już w historii. Poproś agenta o nową propozycję.', 409);
                    }
                    $flows[] = $after;
                }
                $state['flows'] = array_values($flows);
                break;

            case 'write_op':
                $op = (string) ($after['op'] ?? '');
                $opArgs = is_array($after['args'] ?? null) ? $after['args'] : [];
                if ($op === '' || !in_array($op, WriteEngine::OPERATIONS, true)) {
                    throw new ProposalException('bad_proposal', 'Propozycja nie zawiera prawidłowej operacji zapisu.', 400);
                }
                $engine = new WriteEngine($this->db, $this->config);
                $applied = $engine->apply($userId, $state, $op, $opArgs, $targetRef);
                $state = $applied['state'];
                break;

            default:
                throw new ProposalException('bad_proposal', 'Nieznany typ propozycji.', 400);
        }

        return $state;
    }

    private function assertManualTrade(array $trade): void
    {
        foreach (['id', 'pair', 'dir', 'entry', 'size', 'date', 'sl', 'tp', 'source', 'account'] as $field) {
            if (!array_key_exists($field, $trade)) {
                throw new ProposalException('bad_proposal', 'Propozycja transakcji nie ma pola ' . $field . '.', 400);
            }
        }
        if (!in_array((string) $trade['dir'], ['l', 's'], true)) {
            throw new ProposalException('bad_proposal', 'Kierunek transakcji jest nieprawidłowy.', 400);
        }
        if (in_array(strtolower((string) $trade['source']), ['manual', 'panel'], true) === false && (string) $trade['source'] !== 'Ręcznie') {
            throw new ProposalException('exchange_data_readonly', 'Nowa transakcja MCP musi być ręczna.', 403);
        }
        $entry = (float) ($trade['entry'] ?? 0);
        $stopLoss = (float) $trade['sl'];
        if ($trade['sl'] === null || trim((string) $trade['sl']) === '' || !is_numeric($trade['sl'])
            || !is_finite($stopLoss) || $stopLoss <= 0
            || ((string) $trade['dir'] === 'l' ? $stopLoss >= $entry : $stopLoss <= $entry)) {
            throw new ProposalException('stop_loss_required', 'Stop loss jest wymagany przy transakcji.', 400);
        }
        $this->assertOptionalMetric($trade, 'mae');
        $this->assertOptionalMetric($trade, 'mfe');
        foreach (['exchangeId', 'externalId', 'fill', 'fillPrice', 'price', 'qty', 'quantity', 'leverage', 'balance', 'equity'] as $field) {
            if (array_key_exists($field, $trade)) {
                throw new ProposalException('exchange_data_readonly', 'Pole ' . $field . ' należy do danych giełdowych.', 403);
            }
        }
    }

    private function assertClosePayload(array $trade, array $after): void
    {
        $allowed = ['id', 'exit', 'result', 'r', 'mfe', 'durationMin', 'mentor'];
        foreach ($after as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('exchange_data_readonly', 'Pole ' . $field . ' nie może być zmienione przez MCP.', 403);
            }
        }
        $this->assertOptionalMetric($after, 'mfe');
        $entry = (float) ($trade['entry'] ?? 0);
        $size = (float) ($trade['size'] ?? 0);
        $exit = (float) ($after['exit'] ?? 0);
        $direction = (string) ($trade['dir'] ?? 'l') === 's' ? -1 : 1;
        $expected = (int) round(((($exit - $entry) * $size * $direction) + PHP_FLOAT_EPSILON) * 100) / 100;
        if (abs($expected - (float) ($after['result'] ?? 0)) > 0.02) {
            throw new ProposalException('bad_proposal', 'Wynik propozycji nie zgadza się z ceną wyjścia.', 400);
        }
    }

    private function assertReviewPayload(array $after): void
    {
        foreach (self::EXCHANGE_FIELDS as $field) {
            if (array_key_exists($field, $after)) {
                throw new ProposalException('exchange_data_readonly', 'Pole ' . $field . ' należy do danych giełdowych.', 403);
            }
        }
        foreach ($after as $field => $_) {
            if (!in_array($field, ['tradeId', 'note', 'grade', 'setup', 'emotion', 'checklist', 'errors', 'exchangeOwned'], true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do recenzji.', 400);
            }
        }
    }

    private function assertDayPlan(array $after): void
    {
        foreach ($after as $field => $_) {
            if (!in_array($field, ['id', 'date', 'market', 'markets', 'bias', 'why', 'levels', 'ifMain', 'thenMain', 'ifAlt', 'thenAlt', 'risk', 'maxTrades', 'until', 'notDo', 'approvedAt'], true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do planu dnia.', 400);
            }
        }
    }

    /**
     * Zmiana transakcji: dane finansowe transakcji importowanej są nietykalne,
     * a wynik musi zgadzać się z ceną wyjścia i wielkością pozycji.
     */
    private function assertTradeUpdate(int $userId, array $before, array $after): void
    {
        $allowed = ['id', 'date', 'pair', 'dir', 'entry', 'exit', 'sl', 'tp', 'size', 'result', 'r', 'grade', 'checklist', 'checkTotal', 'errors', 'emotion', 'note', 'mentor', 'setup', 'session', 'mae', 'mfe', 'durationMin', 'fee', 'plan', 'source', 'account', 'screens'];
        foreach ($after as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do transakcji.', 400);
            }
        }
        if ((string) ($before['id'] ?? '') !== (string) ($after['id'] ?? '') || (string) ($before['id'] ?? '') === '') {
            throw new ProposalException('bad_proposal', 'Identyfikator transakcji nie może się zmienić.', 400);
        }
        $exchange = $this->isExchangeTrade($userId, $before);
        if ($exchange) {
            foreach (['entry', 'exit', 'size', 'sl', 'tp', 'result', 'r', 'fee', 'source', 'date'] as $field) {
                if ($this->canonicalField($before[$field] ?? null) !== $this->canonicalField($after[$field] ?? null)) {
                    throw new ProposalException('exchange_data_readonly', 'Pole ' . $field . ' należy do danych giełdowych.', 403);
                }
            }
        }
        $beforeSl = (string) ($before['sl'] ?? 'brak');
        $afterSl = (string) ($after['sl'] ?? 'brak');
        if ($beforeSl !== '' && $beforeSl !== 'brak' && ($afterSl === '' || $afterSl === 'brak' || $afterSl === '—')) {
            throw new ProposalException('stop_loss_required', 'Nie można usunąć wymaganego stop loss.', 400);
        }
        $this->assertOptionalMetric($after, 'mae');
        $this->assertOptionalMetric($after, 'mfe');
        $entry = (float) ($after['entry'] ?? 0);
        $size = (float) ($after['size'] ?? 0);
        $direction = (string) ($after['dir'] ?? 'l') === 's' ? -1 : 1;
        $exitRaw = $after['exit'] ?? null;
        if ($exitRaw !== null && $exitRaw !== '') {
            $exit = (float) $exitRaw;
            $expected = (int) round(((($exit - $entry) * $size * $direction) + PHP_FLOAT_EPSILON) * 100) / 100;
            if (abs($expected - (float) ($after['result'] ?? 0)) > 0.02) {
                throw new ProposalException('bad_proposal', 'Wynik transakcji nie zgadza się z ceną wyjścia.', 400);
            }
        }
        $slRaw = (string) ($after['sl'] ?? 'brak');
        if ($slRaw !== '' && $slRaw !== 'brak' && $slRaw !== '—') {
            $sl = (float) $slRaw;
            if (($direction === 1 && $sl >= $entry) || ($direction === -1 && $sl <= $entry)) {
                throw new ProposalException('bad_proposal', 'Stop loss jest po złej stronie wejścia.', 400);
            }
            $risk = abs($entry - $sl) * $size;
            $result = $after['result'] ?? null;
            if ($result !== null && $risk > 0 && array_key_exists('r', $after) && $after['r'] !== null) {
                if (abs((float) $after['r'] - (float) $result / $risk) > 0.05) {
                    throw new ProposalException('bad_proposal', 'R nie zgadza się z wynikiem i ryzykiem.', 400);
                }
            }
        }
    }

    private function assertOptionalMetric(array $record, string $field): void
    {
        if (!array_key_exists($field, $record) || $record[$field] === null) {
            return;
        }
        $value = $record[$field];
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || (is_string($value) && trim($value) === '')
            || !is_numeric($value)
            || !is_finite((float) $value)
            || (float) $value < 0) {
            throw new ProposalException('bad_proposal', 'Pole ' . $field . ' musi być nieujemną liczbą albo null.', 400);
        }
    }

    private function assertRiskRules(array $after): void
    {
        $allowed = ['maxRisk', 'maxLosses', 'maxDailyLoss', 'block', 'requireReason', 'safetyEnabled', 'pushLimit', 'rules', 'checklist', 'reason'];
        foreach ($after as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do zasad ryzyka.', 400);
            }
        }
        if (array_key_exists('safetyEnabled', $after) && !((bool) $after['safetyEnabled'])) {
            throw new ProposalException('forbidden', 'Propozycja nie może wyłączyć wyłącznika bezpieczeństwa.', 403);
        }
        if (array_key_exists('requireReason', $after) && !((bool) $after['requireReason'])) {
            throw new ProposalException('forbidden', 'Propozycja nie może wyłączyć wymogu powodu obejścia.', 403);
        }
        if (array_key_exists('pushLimit', $after) && !((bool) $after['pushLimit'])) {
            throw new ProposalException('forbidden', 'Propozycja nie może wyłączyć powiadomienia o limicie.', 403);
        }
        foreach (['rules' => 10, 'checklist' => 20] as $field => $max) {
            if (!array_key_exists($field, $after)) {
                continue;
            }
            if (!is_array($after[$field]) || count($after[$field]) > $max) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' musi być tablicą maks. ' . $max . ' pozycji.', 400);
            }
        }
    }

    private function canonicalField(mixed $value): string
    {
        return $this->canonicalJson($value === null ? null : $value);
    }

    private function assertCheckin(array $after): void
    {
        foreach ($after as $field => $_) {
            if (!in_array($field, ['date', 'mood', 'energy', 'note', 'score'], true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do check-inu.', 400);
            }
        }
    }

    private function assertSetup(array $after): void
    {
        foreach ($after as $field => $_) {
            if (!in_array($field, ['id', 'name', 'tf', 'grade', 'rules', 'bad', 'seed', 'description', 'confirmation', 'invalidation', 'stopLoss', 'minRR', 'exitRules', 'examples', 'createdAt', 'updatedAt'], true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do setupu.', 400);
            }
        }
        if (!is_array($after['rules'] ?? null) || count($after['rules']) < 2) {
            throw new ProposalException('bad_proposal', 'Setup musi mieć co najmniej 2 zasady.', 400);
        }
    }

    private function assertPropChallenge(array $after): void
    {
        $allowed = ['id', 'name', 'firm', 'templateId', 'phase', 'account', 'currency', 'accountSize', 'startBalance', 'targetPct', 'dailyLossPct', 'maxLossPct', 'drawdownType', 'dailyBasis', 'minDays', 'startDate', 'endDate', 'notes', 'archived', 'createdAt'];
        foreach ($after as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do konfiguracji challenge\'u.', 400);
            }
        }
    }

    private function assertAccount(array $after, bool $isNew): void
    {
        $allowed = ['id', 'name', 'exchange', 'type', 'currency', 'include', 'primary', 'lev', 'goal', 'date', 'start', 'balance'];
        foreach ($after as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do konfiguracji konta.', 400);
            }
        }
        foreach (['id', 'name', 'currency'] as $field) {
            if (!array_key_exists($field, $after)) {
                throw new ProposalException('bad_proposal', 'Propozycja konta nie ma pola ' . $field . '.', 400);
            }
        }
        if (trim((string) $after['name']) === '') {
            throw new ProposalException('bad_proposal', 'Nazwa konta nie może być pusta.', 400);
        }
        if (!in_array((string) $after['currency'], PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_proposal', 'Nieprawidłowa waluta konta.', 400);
        }
        // Saldo pochodzi z giełdy albo z ręcznego salda panelu. MCP go nie zmienia.
        if ($isNew && ((float) ($after['start'] ?? 0) !== 0.0 || (float) ($after['balance'] ?? 0) !== 0.0)) {
            throw new ProposalException('exchange_data_readonly', 'Nowe konto MCP musi startować z saldem 0. Saldo ustawia panel albo synchronizacja giełdy.', 403);
        }
    }

    private function assertCapitalFlow(array $after): void
    {
        $allowed = ['id', 'date', 'account', 'type', 'amount', 'currency', 'fiat', 'fx', 'op', 'tax'];
        foreach ($after as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('bad_proposal', 'Pole ' . $field . ' nie należy do wpisu kapitałowego.', 400);
            }
        }
        foreach (['id', 'date', 'account', 'type', 'amount', 'currency'] as $field) {
            if (!array_key_exists($field, $after)) {
                throw new ProposalException('bad_proposal', 'Propozycja wpisu nie ma pola ' . $field . '.', 400);
            }
        }
        if (!in_array((string) $after['type'], ['Wpłata', 'Wypłata'], true)) {
            throw new ProposalException('bad_proposal', 'Typ wpisu musi być Wpłata albo Wypłata.', 400);
        }
        if ((float) $after['amount'] <= 0) {
            throw new ProposalException('bad_proposal', 'Kwota wpisu musi być większa od 0.', 400);
        }
        if (!in_array((string) $after['currency'], PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_proposal', 'Nieprawidłowa waluta wpisu.', 400);
        }
    }

    public function isExchangeTrade(int $userId, array $trade): bool
    {
        $id = (string) ($trade['id'] ?? '');
        if ($id !== '') {
            try {
                $row = $this->db->queryOne(
                    'SELECT 1 AS ok FROM exchange_imports WHERE user_id = ? AND trade_id = ? LIMIT 1',
                    [$userId, $id]
                );
                if ($row !== null) {
                    return true;
                }
            } catch (\Throwable) {
                // Brak tabeli importów nie może blokować odczytu.
            }
        }
        $source = strtolower(trim((string) ($trade['source'] ?? '')));

        return $source !== '' && !in_array($source, ['manual', 'panel', 'ręcznie', 'recznie'], true);
    }

    private function duplicateOrConflict(array $row, array $after, ?string $fingerprint = null): array
    {
        $storedHash = $row['payload_hash'] !== null ? (string) $row['payload_hash'] : '';
        if ($storedHash !== '') {
            $incomingHash = hash('sha256', $this->canonicalJson([
                'kind' => (string) ($row['kind'] ?? ''),
                'target' => (string) ($row['target_ref'] ?? ''),
                'fingerprint' => $fingerprint ?? ['after' => $this->payloadForHash($after)],
            ]));
            $matches = hash_equals($storedHash, $incomingHash);
        } else {
            // Wiersze sprzed migracji nie mają payload_hash. Porównujemy treść.
            $storedAfter = json_decode((string) ($row['after_json'] ?? ''), true);
            $matches = $this->canonicalJson(is_array($storedAfter) ? $this->payloadForHash($storedAfter) : null)
                === $this->canonicalJson($this->payloadForHash($after));
        }
        if (!$matches) {
            throw new ProposalException(
                'idempotency_conflict',
                'Ten sam idempotencyKey użyto z inną treścią. Użyj nowego klucza.',
                409
            );
        }

        $diff = json_decode((string) ($row['diff_json'] ?? '[]'), true);

        return [
            'proposalId' => (string) $row['id'],
            'status' => (string) $row['status'],
            'kind' => (string) ($row['kind'] ?? ''),
            'target' => $row['target_ref'] ?? null,
            'baseRevision' => $row['base_revision'] !== null ? (int) $row['base_revision'] : null,
            'diffHash' => $this->diffHashFor($row),
            'changes' => is_array($diff) ? array_values(array_map(static fn (array $change): string => (string) ($change['field'] ?? ''), $diff)) : [],
            'expiresAt' => $row['expires_at'],
            'duplicate' => true,
        ];
    }

    /**
     * Pola generowane po stronie serwera albo zależne od czasu nie mogą
     * wpływać na idempotencję. Ten sam wniosek agenta ma zwracać tę samą
     * propozycję, nawet jeśli losowy identyfikator jest inny.
     */
    private function payloadForHash(array $after): array
    {
        foreach (['id', 'createdAt', 'seed', 'durationMin', 'mfe', 'mentor', 'screens'] as $volatile) {
            unset($after[$volatile]);
        }

        return $after;
    }

    private function canonicalJson(mixed $value): string
    {        $normalized = $this->sortKeys($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ProposalException('bad_request', 'Nie można zakodować treści propozycji.', 400);
        }

        return $json;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeys($item);
        }

        return $value;
    }

    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ProposalException('bad_request', 'Nie można zakodować propozycji.', 400);
        }

        return $json;
    }

    private function diff(?array $before, array $after): array
    {
        $diff = [];
        $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after)));
        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old !== $new) {
                $diff[] = ['field' => (string) $key, 'before' => $old, 'after' => $new];
            }
        }

        return $diff;
    }

    private function audit(int $userId, ?string $proposalId, string $actor, string $action, array $detail = []): void
    {
        $this->db->execute(
            'INSERT INTO mcp_audit (proposal_id, user_id, actor, action, detail_json) VALUES (?, ?, ?, ?, ?)',
            [
                $proposalId,
                $userId,
                $actor,
                $action,
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
