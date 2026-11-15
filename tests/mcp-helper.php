<?php
declare(strict_types=1);

/**
 * Helper testowy MCP. Nie jest częścią produkcyjnego API.
 *
 * Użycie:
 *   php tests/mcp-helper.php state <userId>
 *   php tests/mcp-helper.php decide <proposalId> <userId> approve|reject
 *   php tests/mcp-helper.php code <proposalId> <userId>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;

[$config, $db] = require __DIR__ . '/../app/bootstrap.php';
$command = (string) ($argv[1] ?? '');
$service = new ProposalService($db, $config);

try {
    if ($command === 'state') {
        $userId = (int) ($argv[2] ?? 0);
        $row = $db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ?', [$userId]);
        echo json_encode([
            'state' => $row !== null ? json_decode((string) $row['state'], true) : null,
            'revision' => (int) ($row['revision'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }
    if ($command === 'code') {
        $proposalId = (string) ($argv[2] ?? '');
        $userId = (int) ($argv[3] ?? 0);
        echo json_encode($service->issueApprovalCode($userId, $proposalId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }
    if ($command === 'decide') {
        $proposalId = (string) ($argv[2] ?? '');
        $userId = (int) ($argv[3] ?? 0);
        $action = (string) ($argv[4] ?? '');
        $result = $action === 'approve'
            ? $service->approve($userId, $proposalId, $userId)
            : $service->reject($userId, $proposalId, $userId);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }
    if ($command === 'patch') {
        $userId = (int) ($argv[2] ?? 0);
        $jsonFile = (string) ($argv[3] ?? '');
        $patch = json_decode((string) file_get_contents($jsonFile), true);
        if (!is_array($patch)) {
            fwrite(STDERR, "Patch nie jest obiektem JSON\n");
            exit(2);
        }
        $row = $db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userId]);
        $state = $row !== null ? json_decode((string) $row['state'], true) : [];
        $state = is_array($state) ? $state : [];
        foreach ($patch as $key => $value) {
            $state[$key] = $value;
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->execute(
            'UPDATE user_states SET state = ?, revision = revision + 1, bytes = ? WHERE user_id = ?',
            [$json, strlen($json), $userId]
        );
        echo json_encode(['ok' => true, 'revision' => 1]);
        exit(0);
    }
    if ($command === 'expire') {
        $proposalId = (string) ($argv[2] ?? '');
        $db->execute(
            "UPDATE mcp_proposals SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ? AND status = 'pending'",
            [$proposalId]
        );
        echo json_encode(['ok' => true]);
        exit(0);
    }
    fwrite(STDERR, "Nieznana komenda\n");
    exit(2);
} catch (ProposalException $err) {
    echo json_encode(['error' => $err->errorCode, 'message' => $err->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}
