<?php
declare(strict_types=1);

/**
 * CI-only upgrade regression: migrates an existing MCP schema from pre-20261116
 * columns/collations and verifies the migration is safe to re-run.
 */

putenv('ALPHA_VAULT_CONFIG=' . __DIR__ . '/config.test.json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/mcp-test-environment.php';
mcpLoadIsolatedCiConfig(dirname(__DIR__));

[$config, $db] = require __DIR__ . '/../app/bootstrap.php';
$pdo = $db->pdo();

$pdo->exec(
    'ALTER TABLE mcp_idempotency
       DROP COLUMN payload_hash,
       MODIFY COLUMN idem_key VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'
);
$pdo->exec(
    'ALTER TABLE mcp_proposals
       DROP COLUMN decision_hash,
       MODIFY COLUMN idempotency_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
       MODIFY COLUMN decision_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'
);
$db->execute('DELETE FROM schema_migrations WHERE version = ?', ['20261116_mcp_idempotency_fingerprints.sql']);

$migration = __DIR__ . '/../db/migrations/20261116_mcp_idempotency_fingerprints.sql';
$schema = __DIR__ . '/../db/schema.sql';
$applied = $db->migrate($schema);
if (!in_array(basename($migration), $applied, true)) {
    throw new RuntimeException('Migracja 20261116 nie została ponownie zastosowana.');
}

$statement = $pdo->prepare(
    'SELECT table_name AS table_name,
            column_name AS column_name,
            collation_name AS collation_name,
            data_type AS data_type,
            is_nullable AS is_nullable,
            character_maximum_length AS character_maximum_length
       FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND (
          (table_name = ? AND column_name IN (?, ?, ?))
          OR (table_name = ? AND column_name IN (?, ?))
        )'
);
$statement->execute(['mcp_proposals', 'idempotency_key', 'decision_key', 'decision_hash', 'mcp_idempotency', 'idem_key', 'payload_hash']);
$columns = $statement->fetchAll(PDO::FETCH_ASSOC);
$found = [];
foreach ($columns as $column) {
    $found[(string) $column['table_name'] . '.' . (string) $column['column_name']] = $column;
}

$expected = [
    'mcp_proposals.idempotency_key' => ['collation' => 'utf8mb4_bin', 'type' => 'varchar', 'nullable' => 'NO', 'length' => 120],
    'mcp_proposals.decision_key' => ['collation' => 'utf8mb4_bin', 'type' => 'varchar', 'nullable' => 'YES', 'length' => 120],
    'mcp_proposals.decision_hash' => ['collation' => null, 'type' => 'char', 'nullable' => 'YES', 'length' => 64],
    'mcp_idempotency.idem_key' => ['collation' => 'utf8mb4_bin', 'type' => 'varchar', 'nullable' => 'NO', 'length' => 128],
    'mcp_idempotency.payload_hash' => ['collation' => null, 'type' => 'char', 'nullable' => 'YES', 'length' => 64],
];
foreach ($expected as $name => $definition) {
    if (!array_key_exists($name, $found)) {
        throw new RuntimeException('Brak kolumny po migracji: ' . $name);
    }
    $column = $found[$name];
    if ($definition['collation'] !== null && $column['collation_name'] !== $definition['collation']) {
        throw new RuntimeException('Nieprawidłowa collation dla ' . $name . ': ' . (string) $column['collation_name']);
    }
    if ((string) $column['data_type'] !== $definition['type']
        || (string) $column['is_nullable'] !== $definition['nullable']
        || (int) $column['character_maximum_length'] !== $definition['length']) {
        throw new RuntimeException('Nieprawidłowa definicja kolumny po migracji: ' . $name);
    }
}

$db->execute('DELETE FROM schema_migrations WHERE version = ?', [basename($migration)]);
$reapplied = $db->migrate($schema);
if (!in_array(basename($migration), $reapplied, true)) {
    throw new RuntimeException('Ponowne uruchomienie migracji nie zostało wykonane.');
}
$again = $db->migrate($schema);
if ($again !== []) {
    throw new RuntimeException('Migrator nie pomija już zastosowanej migracji: ' . implode(', ', $again));
}

echo "PASS  upgrade schematu MCP 20261116: kolumny, kolacje case-sensitive, bezpieczny replay migracji\n";
