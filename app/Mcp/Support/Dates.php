<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Support;

final class Dates
{
    public const TZ = 'Europe/Warsaw';

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(self::TZ);
    }

    public static function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function todayKey(): string
    {
        return (new \DateTimeImmutable('now', self::tz()))->format('Y-m-d');
    }

    public static function dateKey(?string $iso): ?string
    {
        $date = self::parseUtc($iso);
        if ($date === null) {
            return null;
        }

        return $date->setTimezone(self::tz())->format('Y-m-d');
    }

    public static function isValidKey(string $key): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $key, self::tz());

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $key;
    }

    public static function startOfLocalDay(string $key): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($key . ' 00:00:00', self::tz()))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function startOfNextLocalDay(string $key): \DateTimeImmutable
    {
        if (!self::isValidKey($key)) {
            throw new \InvalidArgumentException('Data musi mieć format YYYY-MM-DD.');
        }

        return (new \DateTimeImmutable($key . ' 00:00:00', self::tz()))
            ->modify('+1 day')
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function localParts(?string $iso): array
    {
        if ($iso === null || trim($iso) === '') {
            return ['date' => null, 'time' => null, 'offset' => null, 'weekday' => null];
        }
        $date = self::parseUtc($iso);
        if ($date === null) {
            return ['date' => null, 'time' => null, 'offset' => null, 'weekday' => null];
        }
        $local = $date->setTimezone(self::tz());

        return [
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i'),
            'offset' => $local->format('P'),
            'weekday' => $local->format('N'),
        ];
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function timestamp(?string $iso): ?int
    {
        return self::parseUtc($iso)?->getTimestamp();
    }

    public static function parseUtc(?string $value): ?\DateTimeImmutable
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1;
        $isoDateTime = preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:\d{2})?$/i',
            $text
        ) === 1;
        $databaseDateTime = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,9})?$/', $text) === 1;
        if (!$dateOnly && !$isoDateTime && !$databaseDateTime) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($text, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }

            return $date->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
