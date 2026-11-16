<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once __DIR__ . '/mcp-test-environment.php';
$config = mcpLoadIsolatedCiConfig($root);
[, $db] = require $root . '/app/bootstrap.php';

$db->execute(
    'DELETE FROM users WHERE discord_id IN (?, ?, ?, ?, ?, ?)',
    ['mcp-e2e-a', 'mcp-e2e-b', 'mcp-e2e-account', 'mcp-e2e-account-2', 'mcp-e2e-same-name-old', 'mcp-e2e-same-name-new']
);
$db->execute("DELETE FROM macro_events WHERE external_id = 'mcp-e2e-macro'");
$db->execute("DELETE FROM mcp_clients WHERE client_name IN ('MCP E2E Client', 'MCP E2E Confidential', 'MCP E2E Writer', 'MCP E2E Pilot B', 'MCP Tools E2E', 'MCP Tools E2E B')");
$file = $root . '/tests/mcp-test-sessions.json';
if (is_file($file)) {
    unlink($file);
}
fwrite(STDOUT, "MCP E2E cleanup done\n");
