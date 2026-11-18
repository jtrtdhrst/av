<?php
declare(strict_types=1);

function mcpAssertIsolatedCiDatabase(\AlphaVault\Config $config): void
{
    $appUrl = parse_url($config->appUrl);
    $appHost = strtolower((string) ($appUrl['host'] ?? ''));
    $dbHost = strtolower((string) $config->db['host']);
    $root = realpath(dirname(__DIR__));
    $workspace = realpath((string) getenv('GITHUB_WORKSPACE'));
    $safe = getenv('CI') === 'true'
        && getenv('GITHUB_ACTIONS') === 'true'
        && preg_match('/^[1-9][0-9]*$/', (string) getenv('GITHUB_RUN_ID')) === 1
        && in_array(getenv('GITHUB_WORKFLOW'), ['CI', 'MCP tests'], true)
        && getenv('GITHUB_JOB') === 'test'
        && $root !== false
        && $workspace !== false
        && $workspace === $root
        && !$config->isProd
        && ($appUrl['scheme'] ?? '') === 'http'
        && in_array($appHost, ['127.0.0.1', 'localhost', '::1'], true)
        && in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)
        && (int) $config->db['port'] === 3306
        && (string) $config->db['user'] === 'alpha_vault'
        && (string) $config->db['database'] === 'alpha_vault';

    if (!$safe) {
        throw new RuntimeException('MCP E2E blocked: configuration is not the isolated GitHub Actions CI database.');
    }
}

function mcpLoadIsolatedCiConfig(string $root): \AlphaVault\Config
{
    require_once $root . '/app/Config.php';
    $config = \AlphaVault\Config::load($root);
    mcpAssertIsolatedCiDatabase($config);

    return $config;
}
