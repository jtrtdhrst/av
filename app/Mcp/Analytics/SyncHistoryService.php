<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Db;

/**
 * Historia synchronizacji integracji. Zwraca wyłącznie metadane i oczyszczone
 * błędy — nigdy kluczy API, tokenów ani haseł.
 */
final class SyncHistoryService
{
    public function __construct(private readonly Db $db)
    {
    }

    public function history(int $userId, array $filters = []): array
    {
        $provider = strtolower(trim((string) ($filters['provider'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        if ($provider !== '' && preg_match('/^[a-z0-9_-]{2,16}$/', $provider) !== 1) {
            throw new \InvalidArgumentException('provider musi być krótkim identyfikatorem integracji.');
        }
        if ($status !== '' && preg_match('/^[a-z0-9_-]{2,20}$/', $status) !== 1) {
            throw new \InvalidArgumentException('status musi być krótkim identyfikatorem statusu.');
        }

        $where = ['c.user_id = ?'];
        $params = [$userId];
        if ($provider !== '') {
            $where[] = 'c.provider = ?';
            $params[] = $provider;
        }
        if ($status !== '') {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $runs = [];
        $total = 0;
        try {
            $countRow = $this->db->queryOne(
                'SELECT COUNT(*) AS n FROM exchange_sync_runs r INNER JOIN exchange_connections c ON c.id = r.connection_id WHERE ' . $whereSql,
                $params
            );
            $total = (int) ($countRow['n'] ?? 0);
            $rows = $this->db->query(
                'SELECT r.id, r.started_at, r.finished_at, r.status, r.fetched, r.imported, r.error_message,
                        c.provider, c.label, c.account_ref, c.last_sync_at, c.last_sync_status, c.imported_count
                 FROM exchange_sync_runs r INNER JOIN exchange_connections c ON c.id = r.connection_id
                 WHERE ' . $whereSql . '
                 ORDER BY r.started_at DESC, r.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
                $params
            );
            foreach ($rows as $row) {
                $fetched = (int) $row['fetched'];
                $imported = (int) $row['imported'];
                $started = $row['started_at'] !== null ? strtotime((string) $row['started_at'] . ' UTC') : null;
                $finished = $row['finished_at'] !== null ? strtotime((string) $row['finished_at'] . ' UTC') : null;
                $runs[] = [
                    'runId' => (int) $row['id'],
                    'integration' => (string) $row['provider'],
                    'label' => (string) $row['label'],
                    'account' => $this->mask((string) ($row['account_ref'] ?? '')),
                    'startedAt' => $row['started_at'] !== null ? (string) $row['started_at'] . ' UTC' : null,
                    'finishedAt' => $row['finished_at'] !== null ? (string) $row['finished_at'] . ' UTC' : null,
                    'status' => (string) $row['status'],
                    'fetched' => $fetched,
                    'imported' => $imported,
                    'skipped' => max(0, $fetched - $imported),
                    'durationMs' => $started !== null && $finished !== null ? max(0, ($finished - $started) * 1000) : null,
                    'error' => $this->sanitizeError((string) ($row['error_message'] ?? '')),
                    'connection' => [
                        'lastSyncAt' => $row['last_sync_at'] !== null ? (string) $row['last_sync_at'] . ' UTC' : null,
                        'lastSyncStatus' => $row['last_sync_status'],
                        'importedCount' => (int) $row['imported_count'],
                    ],
                ];
            }
        } catch (\Throwable) {
            // Brak tabel integracji nie może wywalać narzędzia; zwracamy pustą historię.
            $runs = [];
            $total = 0;
        }

        $state = null;
        try {
            $row = $this->db->queryOne('SELECT last_attempt_at, last_success_at, last_status, last_error, connections_count, imported_count FROM exchange_sync_state WHERE id = 1');
            if ($row !== null) {
                $state = [
                    'status' => (string) $row['last_status'],
                    'lastAttemptAt' => $row['last_attempt_at'] !== null ? (string) $row['last_attempt_at'] . ' UTC' : null,
                    'lastSuccessAt' => $row['last_success_at'] !== null ? (string) $row['last_success_at'] . ' UTC' : null,
                    'connectionsCount' => (int) $row['connections_count'],
                    'importedCount' => (int) $row['imported_count'],
                    'lastError' => $this->sanitizeError((string) ($row['last_error'] ?? '')),
                ];
            }
        } catch (\Throwable) {
            $state = null;
        }

        return [
            'hasData' => $runs !== [],
            'items' => $runs,
            'total' => $total,
            'nextOffset' => ($offset + count($runs)) < $total ? $offset + count($runs) : null,
            'syncState' => $state,
            'filters' => ['provider' => $provider !== '' ? $provider : null, 'status' => $status !== '' ? $status : null],
            'note' => 'Poświadczenia, klucze API i tokeny nie są zwracane. Liczba pominiętych to różnica pobranych i zaimportowanych.',
        ];
    }

    private function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (mb_strlen($value) <= 4) {
            return str_repeat('•', mb_strlen($value));
        }

        return mb_substr($value, 0, 2) . str_repeat('•', max(4, mb_strlen($value) - 4)) . mb_substr($value, -2);
    }

    private function sanitizeError(string $message): ?string
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }
        $message = mb_substr($message, 0, 300);
        $message = preg_replace('/\b(sk|pk|api|key|token|secret|pass)[_-][A-Za-z0-9_-]{6,}\b/i', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\b[A-Fa-f0-9]{24,}\b/', '[redacted]', $message) ?? $message;

        return $message;
    }
}
