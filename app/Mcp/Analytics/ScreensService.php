<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

/**
 * Screeny transakcji: metadane zawsze, obrazy tylko na żądanie i w twardych limitach.
 *
 * Screeny są zapisane jako data URL w stanie użytkownika (trade.screens.before/after).
 * Nie ma publicznego adresu obrazu i MCP go nie tworzy — dzięki temu prywatny
 * screen nie może wyciec przez zgadywalny URL.
 */
final class ScreensService
{
    public const MAX_IMAGE_BYTES = 200000;
    public const MAX_TOTAL_BYTES = 400000;
    public const MAX_IMAGES = 2;

    private const KINDS = ['before', 'after'];

    public function forTrade(array $trade, bool $includeImages = false, int $maxImages = 1): array
    {
        $screens = is_array($trade['screens'] ?? null) ? $trade['screens'] : [];
        $items = [];
        $images = [];
        $total = 0;
        $limit = max(1, min(self::MAX_IMAGES, $maxImages));
        foreach (self::KINDS as $kind) {
            $data = $screens[$kind] ?? null;
            if (!is_string($data) || trim($data) === '') {
                continue;
            }
            $parsed = $this->parse($data);
            if ($parsed === null) {
                $items[] = [
                    'kind' => $kind,
                    'available' => true,
                    'valid' => false,
                    'mimeType' => null,
                    'bytes' => null,
                    'sha256' => null,
                    'dimensions' => null,
                    'addedAt' => null,
                    'note' => 'Obraz nie jest poprawnym data URL, więc nie jest udostępniany.',
                ];
                continue;
            }
            $items[] = [
                'kind' => $kind,
                'available' => true,
                'valid' => true,
                'mimeType' => $parsed['mimeType'],
                'bytes' => $parsed['bytes'],
                'sha256' => $parsed['sha256'],
                'dimensions' => $parsed['dimensions'],
                'addedAt' => null,
                'note' => 'Czas dodania screenu nie jest zapisywany w stanie transakcji.',
            ];
            if (!$includeImages || count($images) >= $limit) {
                continue;
            }
            if ($parsed['bytes'] > self::MAX_IMAGE_BYTES || ($total + $parsed['bytes']) > self::MAX_TOTAL_BYTES) {
                $images[] = [
                    'kind' => $kind,
                    'mimeType' => $parsed['mimeType'],
                    'bytes' => $parsed['bytes'],
                    'omitted' => 'too_large',
                    'limitBytes' => self::MAX_IMAGE_BYTES,
                ];
                continue;
            }
            $total += $parsed['bytes'];
            $images[] = [
                'kind' => $kind,
                'mimeType' => $parsed['mimeType'],
                'bytes' => $parsed['bytes'],
                'encoding' => 'base64',
                'data' => $parsed['base64'],
            ];
        }

        return [
            'screenshots' => [
                'available' => $items !== [],
                'count' => count($items),
                'items' => $items,
                'images' => $images,
                'includeImages' => $includeImages,
                'limits' => [
                    'maxImages' => $limit,
                    'maxImageBytes' => self::MAX_IMAGE_BYTES,
                    'maxTotalBytes' => self::MAX_TOTAL_BYTES,
                ],
                'note' => 'Obrazy są zwracane tylko po includeImages=true i w limitach rozmiaru. Brak publicznego URL do screenu.',
            ],
            'tradeId' => (string) ($trade['id'] ?? ''),
        ];
    }

    /**
     * @return array{mimeType:string,bytes:int,base64:string,sha256:string,dimensions:?array}|null
     */
    private function parse(string $dataUrl): ?array
    {
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\s]+)$#i', trim($dataUrl), $matches) !== 1) {
            return null;
        }
        $mime = strtolower($matches[1]);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return null;
        }
        $base64 = preg_replace('/\s+/', '', $matches[2]) ?? '';
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        return [
            'mimeType' => $mime,
            'bytes' => strlen($binary),
            'base64' => $base64,
            'sha256' => hash('sha256', $binary),
            'dimensions' => $this->dimensions($binary, $mime),
        ];
    }

    private function dimensions(string $binary, string $mime): ?array
    {
        if ($mime === 'image/png' && strlen($binary) >= 24 && str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            $width = unpack('N', substr($binary, 16, 4));
            $height = unpack('N', substr($binary, 20, 4));

            return ['width' => $width[1] ?? null, 'height' => $height[1] ?? null];
        }
        if ($mime === 'image/gif' && strlen($binary) >= 10 && str_starts_with($binary, 'GIF')) {
            $width = unpack('v', substr($binary, 6, 2));
            $height = unpack('v', substr($binary, 8, 2));

            return ['width' => $width[1] ?? null, 'height' => $height[1] ?? null];
        }
        if ($mime === 'image/jpeg') {
            $length = strlen($binary);
            $offset = 2;
            while ($offset + 9 < $length) {
                if (ord($binary[$offset]) !== 0xFF) {
                    break;
                }
                $marker = ord($binary[$offset + 1]);
                $size = unpack('n', substr($binary, $offset + 2, 2));
                $size = $size[1] ?? 0;
                if ($size < 2) {
                    break;
                }
                if (($marker >= 0xC0 && $marker <= 0xC3) || ($marker >= 0xC5 && $marker <= 0xC7) || ($marker >= 0xC9 && $marker <= 0xCB) || ($marker >= 0xCD && $marker <= 0xCF)) {
                    $height = unpack('n', substr($binary, $offset + 5, 2));
                    $width = unpack('n', substr($binary, $offset + 7, 2));

                    return ['width' => $width[1] ?? null, 'height' => $height[1] ?? null];
                }
                $offset += 2 + $size;
            }
        }

        return null;
    }
}
