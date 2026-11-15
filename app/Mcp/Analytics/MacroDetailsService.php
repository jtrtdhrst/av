<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Analytics;

use AlphaVault\Db;
use AlphaVault\Mcp\Support\Dates;

/**
 * Szczegóły wydarzenia makro. Brak kolumny z wynikiem rzeczywistym nie jest
 * uzupełniany zmyśloną wartością — pole actual pozostaje null z jawnym statusem.
 */
final class MacroDetailsService
{
    public function __construct(private readonly Db $db)
    {
    }

    public function details(string $eventId): array
    {
        $needle = trim($eventId);
        if ($needle === '') {
            throw new \InvalidArgumentException('eventId nie może być pusty.');
        }
        $row = null;
        if (ctype_digit($needle)) {
            $row = $this->db->queryOne(
                'SELECT id, external_id, source, name, description, currency, impact, starts_at_utc, starts_at_iso, forecast, previous, updated_at
                 FROM macro_events WHERE id = ? AND is_deleted = 0',
                [(int) $needle]
            );
        }
        if ($row === null) {
            $row = $this->db->queryOne(
                'SELECT id, external_id, source, name, description, currency, impact, starts_at_utc, starts_at_iso, forecast, previous, updated_at
                 FROM macro_events WHERE external_id = ? AND is_deleted = 0',
                [$needle]
            );
        }
        if ($row === null) {
            return ['found' => false, 'event' => null, 'eventId' => $needle];
        }
        $iso = (string) ($row['starts_at_iso'] !== '' ? $row['starts_at_iso'] : $row['starts_at_utc'] . 'Z');
        $local = Dates::localParts($iso);
        $startTs = strtotime($iso);
        $now = time();
        $status = 'scheduled';
        if ($startTs !== false && $startTs <= $now) {
            $status = 'past_without_result';
        }

        return [
            'found' => true,
            'event' => [
                'id' => (string) $row['id'],
                'externalId' => (string) $row['external_id'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? mb_substr((string) $row['description'], 0, 500) : null,
                'currency' => (string) $row['currency'],
                'impact' => (int) $row['impact'],
                'startAt' => $iso,
                'startUtc' => $row['starts_at_utc'],
                'startLocal' => $local['date'] !== null ? $local['date'] . 'T' . $local['time'] . ':00' . $local['offset'] : null,
                'forecast' => $row['forecast'],
                'previous' => $row['previous'],
                'actual' => null,
                'actualAvailable' => false,
                'source' => (string) $row['source'],
                'publicationStatus' => $status,
                'updatedAt' => $row['updated_at'] !== null ? (string) $row['updated_at'] . ' UTC' : null,
            ],
            'timezone' => Dates::TZ,
            'note' => $status === 'past_without_result'
                ? 'Wydarzenie już się odbyło, ale źródło nie dostarcza wyniku rzeczywistego do AlphaVault — actual pozostaje null.'
                : 'Wydarzenie zaplanowane; wynik rzeczywisty nie jest jeszcze dostępny.',
        ];
    }
}
