#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);

try {
    [$config, $db] = require $root . '/app/bootstrap.php';
    $result = (new \AlphaVault\AcademyMaterials($config, $db))->sync();
    if (!empty($result['skipped'])) {
        fwrite(STDOUT, "AlphaVault Academy materials sync: skipped (lock held)\n");
        exit(0);
    }
    $line = sprintf(
        'AlphaVault Academy materials sync: status=%s pages=%d verified=%d ready=%s',
        (string) ($result['status'] ?? 'error'),
        (int) ($result['pages'] ?? 0),
        (int) ($result['verifiedChannels'] ?? 0),
        !empty($result['ready']) ? 'true' : 'false'
    );
    if (in_array(($result['status'] ?? 'error'), ['success', 'backfill', 'content_unverified'], true)) {
        fwrite(STDOUT, $line . "\n");
        exit(0);
    }
    // No message content, response body, token, or attachment URL is logged.
    fwrite(STDERR, $line . "\n");
    exit(1);
} catch (\Throwable) {
    fwrite(STDERR, "AlphaVault Academy materials sync: error\n");
    exit(1);
}
