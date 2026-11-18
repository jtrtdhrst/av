<?php
declare(strict_types=1);

require_once __DIR__ . '/mcp-test-environment.php';
$root = dirname(__DIR__);
mcpLoadIsolatedCiConfig($root);
[$config, $db] = require $root . '/app/bootstrap.php';

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$externalId = isset($argv[2]) ? trim((string) $argv[2]) : '';
if ($userId <= 0 || $externalId === '') {
    fwrite(STDERR, "Wymagane są userId i externalId fixture.\n");
    exit(1);
}

$prefix = str_starts_with($externalId, 'prefix:');
if ($prefix) {
    $row = $db->queryOne(
        'SELECT 1 AS found FROM exchange_imports WHERE user_id = ? AND external_id LIKE ? LIMIT 1',
        [$userId, substr($externalId, 7) . '%']
    );
} else {
    $row = $db->queryOne(
        'SELECT 1 AS found FROM exchange_imports WHERE user_id = ? AND external_id = ? LIMIT 1',
        [$userId, $externalId]
    );
}
echo json_encode(['exists' => $row !== null], JSON_UNESCAPED_SLASHES) . PHP_EOL;
