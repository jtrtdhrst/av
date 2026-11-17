<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class DayPlanLevels
{
    /**
     * Normalizes both UI levels (`price`) and older MCP levels (`value`).
     *
     * @return list<array{type:string,price:string,value:string,note:string}>
     */
    public static function normalizeList(
        mixed $levels,
        int $maxItems,
        bool $allowScalar = true,
        bool $strictType = true,
        int $maxPriceLength = 40,
        bool $requirePositiveNumber = false
    ): array {
        if (!is_array($levels) || count($levels) > $maxItems) {
            throw new \InvalidArgumentException('levels musi być tablicą maks. ' . $maxItems . ' poziomów.');
        }

        $out = [];
        foreach ($levels as $level) {
            if (!is_array($level)) {
                if (!$allowScalar || !is_scalar($level)) {
                    throw new \InvalidArgumentException('Każdy poziom musi być obiektem.');
                }
                $level = ['value' => (string) $level];
            }
            $type = self::type((string) ($level['type'] ?? ''), $strictType);
            $price = trim((string) ($level['price'] ?? ''));
            $value = trim((string) ($level['value'] ?? ''));
            if ($price === '') {
                $price = $value;
            }
            if (mb_strlen($price) > $maxPriceLength) {
                throw new \InvalidArgumentException('Cena poziomu przekracza limit znaków.');
            }
            if ($requirePositiveNumber && $price !== '') {
                $numeric = str_replace([' ', ','], ['', '.'], $price);
                if (!is_numeric($numeric) || (float) $numeric <= 0) {
                    throw new \InvalidArgumentException('Cena poziomu musi być liczbą większą od 0.');
                }
            }
            $note = mb_substr(trim((string) ($level['note'] ?? '')), 0, 200);
            if ($price === '' && $note === '') {
                continue;
            }
            $out[] = ['type' => $type, 'price' => $price, 'value' => $price, 'note' => $note];
        }

        return $out;
    }

    private static function type(string $value, bool $strict): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return 'Wsparcie';
        }
        $normalized = mb_strtolower($raw);
        $map = [
            'support' => 'Wsparcie',
            'wsparcie' => 'Wsparcie',
            'resistance' => 'Opór',
            'opór' => 'Opór',
            'opor' => 'Opór',
            'liquidity' => 'Liquidity',
            'płynność' => 'Liquidity',
            'poi' => 'POI',
        ];
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }
        if ($strict) {
            throw new \InvalidArgumentException('type poziomu musi być support/Wsparcie, resistance/Opór, liquidity/Liquidity/Płynność albo poi/POI.');
        }

        return $raw;
    }
}
