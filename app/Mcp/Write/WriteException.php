<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Write;

/**
 * Błąd operacji zapisu MCP. `errorCode` trafia do odpowiedzi narzędzia,
 * żeby model mógł rozpoznać sytuację bez parsowania komunikatów.
 */
final class WriteException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
