<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tests/mcp-test-environment.php';
mcpLoadIsolatedCiConfig($root);
[, $db] = require $root . '/app/bootstrap.php';

$userId = (int) ($argv[1] ?? 0);
$payload = json_decode((string) file_get_contents('php://stdin'), true);
if ($userId <= 0 || !is_array($payload)) {
    fwrite(STDERR, "invalid_fixture_input\n");
    exit(2);
}
$action = (string) ($payload['action'] ?? '');

try {
    $result = $db->withTransaction(static function () use ($db, $userId, $action, $payload): ?array {
        $user = $db->queryOne(
            'SELECT is_demo, plan, access_until, role_verified FROM users WHERE id = ? FOR UPDATE',
            [$userId]
        );
        if (!is_array($user)) {
            throw new RuntimeException('fixture_user_not_found');
        }
        $backup = [
            'is_demo' => (int) $user['is_demo'],
            'plan' => (string) $user['plan'],
            'access_until' => $user['access_until'] === null ? null : (string) $user['access_until'],
            'role_verified' => (int) $user['role_verified'],
        ];

        if ($action === 'backup') {
            return $backup;
        }

        if ($action === 'set') {
            $scenario = (string) ($payload['scenario'] ?? '');
            $values = match ($scenario) {
                'solo' => [0, 'solo', '2099-12-31', 1],
                'expired' => [0, 'full', '2000-01-01', 1],
                'role_unverified' => [0, 'full', '2099-12-31', 0],
                default => throw new RuntimeException('invalid_fixture_scenario'),
            };
            $db->execute(
                'UPDATE users SET is_demo = ?, plan = ?, access_until = ?, role_verified = ? WHERE id = ?',
                [...$values, $userId]
            );
            return null;
        }

        if ($action === 'restore') {
            $values = $payload['backup'] ?? null;
            if (!is_array($values)
                || !in_array((int) ($values['is_demo'] ?? -1), [0, 1], true)
                || !in_array((int) ($values['role_verified'] ?? -1), [0, 1], true)
                || !in_array($values['plan'] ?? null, ['solo', 'full'], true)
                || (($values['access_until'] ?? null) !== null
                    && (!is_string($values['access_until'])
                        || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $values['access_until']) !== 1))) {
                throw new RuntimeException('invalid_fixture_backup');
            }
            $db->execute(
                'UPDATE users SET is_demo = ?, plan = ?, access_until = ?, role_verified = ? WHERE id = ?',
                [
                    (int) $values['is_demo'],
                    $values['plan'],
                    $values['access_until'],
                    (int) $values['role_verified'],
                    $userId,
                ]
            );
            return null;
        }

        throw new RuntimeException('invalid_fixture_action');
    });
    if (is_array($result)) {
        echo json_encode($result, JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
