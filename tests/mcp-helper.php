<?php
declare(strict_types=1);

use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;

/**
 * Helper testowy MCP. Nie jest częścią produkcyjnego API.
 *
 * Użycie:
 *   php tests/mcp-helper.php state <userId>
 *   php tests/mcp-helper.php decide <proposalId> <userId> approve|reject
 *   php tests/mcp-helper.php code <proposalId> <userId>
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/mcp-test-environment.php';
$root = dirname(__DIR__);
$config = mcpLoadIsolatedCiConfig($root);
[, $db] = require $root . '/app/bootstrap.php';

$command = (string) ($argv[1] ?? '');
$service = new ProposalService($db, $config);

try {
    if ($command === 'assert-test-environment') {
        echo json_encode(['ok' => true]);
        exit(0);
    }
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
    if ($command === 'seed-wearables') {
        $userId = (int) ($argv[2] ?? 0);
        $date = (string) ($argv[3] ?? '');
        $sleepMinutes = (int) ($argv[4] ?? 0);
        $hrvMs = (float) ($argv[5] ?? 0);
        $restingHr = (int) ($argv[6] ?? 0);
        $providerScore = (int) ($argv[7] ?? 0);
        $previousDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $db->execute(
            "INSERT INTO wearable_connections (user_id, provider, status, device_name, consent_at)
             VALUES (?, 'oura', 'connected', 'MCP E2E Oura', UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status = 'connected', device_name = VALUES(device_name), consent_at = UTC_TIMESTAMP()",
            [$userId]
        );
        foreach ([
            [$previousDate, 480, 60.0, 60, null],
            [$date, $sleepMinutes, $hrvMs, $restingHr, $providerScore],
        ] as [$metricDate, $sleep, $hrv, $heartRate, $score]) {
            $db->execute(
                'INSERT INTO wearable_daily_metrics
                    (user_id, provider, metric_date, sleep_minutes, hrv_ms, resting_hr, provider_score, synced_at)
                 VALUES (?, \'oura\', ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE sleep_minutes = VALUES(sleep_minutes),
                    hrv_ms = VALUES(hrv_ms), resting_hr = VALUES(resting_hr),
                    provider_score = VALUES(provider_score), synced_at = UTC_TIMESTAMP()',
                [$userId, $metricDate, $sleep, $hrv, $heartRate, $score]
            );
        }
        echo json_encode(['ok' => true]);
        exit(0);
    }
    if ($command === 'seed-events') {
        $guildId = (string) ($config->discord['guildId'] ?? '');
        $ids = ['mcp-e2e-001', 'mcp-e2e-002', 'mcp-e2e-003'];
        foreach ($ids as $eventId) {
            $db->execute(
                'DELETE FROM discord_scheduled_events WHERE discord_event_id = ?',
                [$eventId]
            );
        }
        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->setTime(12, 0, 0);
        $end = $start->modify('+1 hour +45 seconds');
        $stamp = gmdate('Y-m-d H:i:s');
        $db->withTransaction(static function (\AlphaVault\Db $db) use ($ids, $guildId, $start, $end, $stamp): void {
            foreach ([
                ['mcp-e2e-001', 'live'],
                ['mcp-e2e-002', 'consultation'],
                ['mcp-e2e-003', 'live'],
            ] as [$eventId, $category]) {
                $db->execute(
                    'INSERT INTO discord_scheduled_events
                    (discord_event_id, guild_id, name, event_type, status, scheduled_start_time,
                     scheduled_start_iso, scheduled_end_time, scheduled_end_iso, raw_payload,
                     category, category_source, manual_category, first_seen_at, last_seen_at, updated_at, last_synced_at)
                 VALUES (?, ?, ?, 2, 1, ?, ?, ?, ?, ?, ?, \'manual\', ?, ?, ?, ?, ?)',
                    [
                        $eventId,
                        $guildId,
                        'MCP_E2E_TEST ' . $eventId,
                        $start->format('Y-m-d H:i:s'),
                        $start->format('Y-m-d\TH:i:s\Z'),
                        $end->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d\TH:i:s\Z'),
                    json_encode(['id' => $eventId], JSON_UNESCAPED_SLASHES),
                    $category,
                    $category,
                    $stamp,
                        $stamp,
                        $stamp,
                        $stamp,
                    ]
                );
            }
        });
        echo json_encode(['ok' => true, 'count' => count($ids)]);
        exit(0);
    }
    if ($command === 'seed-macro') {
        $date = (string) ($argv[2] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || (new DateTimeImmutable($date, new DateTimeZone('Europe/Warsaw')))->format('Y-m-d') !== $date) {
            throw new RuntimeException('seed-macro requires a valid YYYY-MM-DD date.');
        }
        $externalIds = [
            'mcp-e2e-date-boundary',
            'mcp-e2e-dst-autumn',
            'mcp-e2e-dst-spring-late',
            'mcp-e2e-dst-spring-next-day',
        ];
        $db->execute(
            'DELETE FROM macro_events WHERE external_id IN (?, ?, ?, ?)',
            $externalIds
        );
        $warsaw = new DateTimeZone('Europe/Warsaw');
        $stamp = gmdate('Y-m-d H:i:s');
        $fixtures = [
            ['mcp-e2e-date-boundary', 'MCP_E2E_TEST same-day macro', new DateTimeImmutable($date . ' 14:30:00', $warsaw)],
            ['mcp-e2e-dst-autumn', 'MCP_E2E_TEST autumn DST boundary', new DateTimeImmutable('2026-10-25 23:30:00', $warsaw)],
            ['mcp-e2e-dst-spring-late', 'MCP_E2E_TEST spring DST last hour', new DateTimeImmutable('2027-03-28 23:30:00', $warsaw)],
            ['mcp-e2e-dst-spring-next-day', 'MCP_E2E_TEST spring DST next day', new DateTimeImmutable('2027-03-29 00:30:00', $warsaw)],
        ];
        $db->withTransaction(static function (\AlphaVault\Db $db) use ($fixtures, $stamp): void {
            foreach ($fixtures as [$externalId, $name, $local]) {
                $utc = $local->setTimezone(new DateTimeZone('UTC'));
                $db->execute(
                    'INSERT INTO macro_events
                        (external_id, source, name, currency, impact, starts_at_utc, starts_at_iso,
                         first_seen_at, last_seen_at, updated_at)
                     VALUES (?, \'forexfactory\', ?, \'USD\', 2, ?, ?, ?, ?, ?)',
                    [
                        $externalId,
                        $name,
                        $utc->format('Y-m-d H:i:s'),
                        $local->format(DATE_ATOM),
                        $stamp,
                        $stamp,
                        $stamp,
                    ]
                );
            }
        });
        echo json_encode(['ok' => true, 'count' => count($fixtures)]);
        exit(0);
    }
    if ($command === 'cleanup-events') {
        $db->execute(
            'DELETE FROM discord_scheduled_events WHERE discord_event_id IN (?, ?, ?)',
            ['mcp-e2e-001', 'mcp-e2e-002', 'mcp-e2e-003']
        );
        $db->execute(
            'DELETE FROM macro_events WHERE external_id IN (?, ?, ?, ?)',
            ['mcp-e2e-date-boundary', 'mcp-e2e-dst-autumn', 'mcp-e2e-dst-spring-late', 'mcp-e2e-dst-spring-next-day']
        );
        echo json_encode(['ok' => true]);
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
