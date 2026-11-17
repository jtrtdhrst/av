<?php
declare(strict_types=1);

require_once __DIR__ . '/mcp-test-environment.php';
$root = dirname(__DIR__);
mcpLoadIsolatedCiConfig($root);
[$config, $db] = require $root . '/app/bootstrap.php';

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$payload = json_decode((string) file_get_contents('php://stdin'), true);
$state = is_array($payload) ? ($payload['state'] ?? null) : null;
if ($userId <= 0 || !is_array($state)) {
    fwrite(STDERR, "Wymagane są userId i obiekt state fixture.\n");
    exit(1);
}

$json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || strlen($json) > $config->maxStateBytes) {
    fwrite(STDERR, "Nieprawidłowy lub zbyt duży stan fixture.\n");
    exit(1);
}

$db->withTransaction(static function (\AlphaVault\Db $db) use ($userId, $json): void {
    $user = $db->queryOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
    if ($user === null) {
        throw new RuntimeException('Nie znaleziono użytkownika fixture.');
    }
    $row = $db->queryOne('SELECT revision FROM user_states WHERE user_id = ? FOR UPDATE', [$userId]);
    if ($row === null) {
        throw new RuntimeException('Nie znaleziono stanu użytkownika fixture.');
    }
    $db->execute(
        'UPDATE user_states SET state = ?, revision = revision + 1, bytes = ? WHERE user_id = ?',
        [$json, strlen($json), $userId]
    );
});
