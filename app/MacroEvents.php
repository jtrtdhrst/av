<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Kalendarz makro: publiczne feedy -> MySQL -> /api/macro-events -> UI.
 *
 * Bieżący tydzień: oficjalny JSON ForexFactory (impact High/Medium).
 * Poprzedni pełny tydzień i kolejne trzy tygodnie: TradingView (importance 1/0),
 * bo ForexFactory publikuje JSON tylko dla bieżącego tygodnia.
 *
 * Zapisujemy wyłącznie publikacje ★★ i ★★★. Udany feed z jawnym oknem
 * (także pusty) aktualizuje wyłącznie ten zakres; błąd zachowuje ostatni
 * dobry zestaw. Pusta lista bez okna jest no-op.
 */
final class MacroEvents
{
    private const FF_THIS_WEEK_URL = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';
    private const TV_EVENTS_URL = 'https://economic-calendar.tradingview.com/events';
    private const IMPACT_MAP = ['high' => 3, 'medium' => 2];
    private const FF_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CNY', 'CAD', 'AUD', 'CHF', 'NZD'];
    private const TV_IMPORTANCE_MAP = [1 => 3, 0 => 2];
    private const TV_COUNTRIES = ['US', 'EU', 'GB', 'JP', 'CN', 'CA', 'AU', 'CH', 'NZ'];
    private const TV_CURRENCY_MAP = [
        'US' => 'USD', 'EU' => 'EUR', 'GB' => 'GBP', 'JP' => 'JPY', 'CN' => 'CNY',
        'CA' => 'CAD', 'AU' => 'AUD', 'CH' => 'CHF', 'NZ' => 'NZD',
    ];
    private const TV_NOISE = [
        'auction', 'bill', 'bond', 'note', 'speaks', 'holiday', 'reopening',
        'settlement', 'redemption', 'allotted', 'bid-to-cover', 'total accepted',
    ];
    private const TV_MEDIUM_KEYWORDS = [
        'inflation', 'cpi', 'ppi', 'pce', 'gdp', 'retail sales', 'unemployment',
        'employment', 'payroll', 'jobless', 'claimant', 'jolts', 'job openings',
        'interest rate', 'rate decision', 'fomc', 'fed funds', 'ecb', 'boe', 'boj',
        'pmi', 'confidence', 'sentiment', 'zew', 'ifo', 'industrial production',
        'manufacturing production', 'manufacturing index', 'trade balance',
        'building permits', 'housing starts', 'durable goods', 'personal spending',
        'personal income', 'earnings', 'current account', 'factory orders',
        'industrial orders', 'leading index',
    ];
    private const FETCH_WINDOW_DAYS_BACK = 14;
    private const FETCH_WINDOW_DAYS_FORWARD = 35;
    private const STALE_AFTER_SECONDS = 1800;
    private const LOCK_NAME = 'alphavault_macro_sync';
    private readonly ?\Closure $feedFetcher;

    /** @param callable(string, list<string>): mixed $feedFetcher Optional deterministic feed source for CI tests. */
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        ?callable $feedFetcher = null,
    ) {
        $this->feedFetcher = $feedFetcher === null ? null : \Closure::fromCallable($feedFetcher);
    }

    public function sync(): array
    {
        if (!$this->acquireLock()) {
            $this->log('sync skipped: another run holds the lock');
            return ['skipped' => true];
        }

        $started = microtime(true);
        try {
            $this->db->execute(
                'UPDATE macro_sync_state
                    SET last_attempt_at = UTC_TIMESTAMP(), last_status = ?, last_error = NULL,
                        updated_at = UTC_TIMESTAMP()
                  WHERE id = 1',
                ['running']
            );
        } catch (\Throwable $err) {
            $this->log('cannot persist sync start: ' . $err->getMessage());
        }

        try {
            $windowNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $ffWindow = self::forexFactoryWindowUtc($windowNow);
            $tvWindow = self::tradingViewWindowUtc($windowNow);

            $ffRows = $this->fetchForexFactory();
            $ffNormalized = self::normalizeForexFactory($ffRows);
            if (!self::hasForexFactoryFeedShape($ffRows)) {
                throw new \RuntimeException('Feed ForexFactory ma nieprawidłowy kształt.');
            }

            $tvRows = null;
            $tvNormalized = [];
            $tvError = null;
            try {
                $fetchedTvRows = $this->fetchTradingView($windowNow);
                $normalizedTvRows = self::normalizeTradingView($fetchedTvRows);
                if (!self::hasTradingViewFeedShape($fetchedTvRows)) {
                    throw new \RuntimeException('Feed TradingView ma nieprawidłowy kształt.');
                }
                $tvRows = $fetchedTvRows;
                $tvNormalized = $normalizedTvRows;
            } catch (\Throwable $err) {
                $tvError = substr($err->getMessage(), 0, 500);
                $this->log('TradingView future weeks unavailable: ' . $err->getMessage());
            }

            $result = $this->syncNormalizedFeeds(
                $ffNormalized,
                $tvRows === null ? null : $tvNormalized,
                $started,
                $ffWindow,
                $tvWindow
            );

            $status = $tvRows === null ? 'partial' : 'success';
            $this->db->execute(
                'UPDATE macro_sync_state
                    SET last_success_at = CASE WHEN ? = \'success\' THEN UTC_TIMESTAMP() ELSE last_success_at END,
                        last_status = ?, last_error = ?,
                        events_count = ?, updated_count = ?, duration_ms = ?, updated_at = UTC_TIMESTAMP()
                  WHERE id = 1',
                [$status, $status, $tvError, $result['events_count'], $result['updated_count'], $result['duration_ms']]
            );
            $this->log(sprintf(
                'sync %s: events=%d updated=%d duration=%dms',
                $status,
                $result['events_count'],
                $result['updated_count'],
                $result['duration_ms']
            ));
            return $result;
        } catch (\Throwable $err) {
            $message = substr($err->getMessage(), 0, 500);
            try {
                $this->db->execute(
                    'UPDATE macro_sync_state
                        SET last_status = ?, last_error = ?, duration_ms = ?, updated_at = UTC_TIMESTAMP()
                      WHERE id = 1',
                    ['error', $message, (int) round((microtime(true) - $started) * 1000)]
                );
            } catch (\Throwable $dbErr) {
                $this->log('cannot persist sync error: ' . $dbErr->getMessage());
            }
            $this->log('sync error: ' . $message);
            throw $err;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @param list<array<string,mixed>> $ffRows
     * @param list<array<string,mixed>>|null $tvRows null means fetch failed
     * @param array{0:string,1:string} $ffWindow
     * @param array{0:string,1:string} $tvWindow
     * @return array{events_count:int,updated_count:int,duration_ms:int}
     */
    private function syncNormalizedFeeds(
        array $ffRows,
        ?array $tvRows,
        float $started,
        array $ffWindow,
        array $tvWindow
    ): array {
        $result = [
            'events_count' => 0,
            'updated_count' => 0,
            'duration_ms' => 0,
        ];
        if ($tvRows !== null) {
            // A successful empty feed clears only TradingView's requested window.
            // On fetch failure this call is skipped, preserving its last good data.
            $tvResult = $this->syncPayload(
                $tvRows,
                $started,
                'tradingview',
                false,
                $tvWindow[0],
                $tvWindow[1]
            );
            $result['events_count'] += $tvResult['events_count'];
            $result['updated_count'] += $tvResult['updated_count'];
            $result['duration_ms'] = $tvResult['duration_ms'];
        }

        // Apply ForexFactory last: its Sunday–Sunday window takes precedence
        // over overlapping TradingView events and soft-deletes their duplicates.
        $ffResult = $this->syncPayload(
            $ffRows,
            $started,
            'forexfactory',
            true,
            $ffWindow[0],
            $ffWindow[1]
        );
        $result['events_count'] += $ffResult['events_count'];
        $result['updated_count'] += $ffResult['updated_count'];
        $result['duration_ms'] = $ffResult['duration_ms'];

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows znormalizowane rekordy
     */
    public function syncPayload(
        array $rows,
        ?float $started = null,
        string $source = 'forexfactory',
        bool $replaceAllSources = true,
        ?string $windowStart = null,
        ?string $windowEnd = null,
    ): array {
        $started ??= microtime(true);
        if ($rows === [] && ($windowStart === null || $windowEnd === null)) {
            return ['events_count' => 0, 'updated_count' => 0, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
        }

        if ($rows !== []) {
            $starts = array_map(static fn (array $event): string => $event['starts_at_utc'], $rows);
            $windowStart ??= min($starts);
        }
        if ($windowEnd === null && $rows !== []) {
            $starts = array_map(static fn (array $event): string => $event['starts_at_utc'], $rows);
            $max = new \DateTimeImmutable(max($starts), new \DateTimeZone('UTC'));
            $windowEnd = $max->modify('+1 second')->format('Y-m-d H:i:s');
        }
        if ($windowStart === null || $windowEnd === null || $windowEnd <= $windowStart) {
            throw new \InvalidArgumentException('Nieprawidłowe okno synchronizacji kalendarza makro.');
        }

        // Keep inserts and soft deletes inside exactly the same half-open UTC window.
        $rows = array_values(array_filter(
            $rows,
            static fn (array $event): bool => $event['starts_at_utc'] >= $windowStart
                && $event['starts_at_utc'] < $windowEnd
        ));
        $ids = array_map(static fn (array $event): string => $event['external_id'], $rows);

        $this->db->withTransaction(function (Db $db) use ($rows, $ids, $windowStart, $windowEnd, $source, $replaceAllSources): void {
            foreach ($rows as $event) {
                $db->execute(
                    'INSERT INTO macro_events
                        (external_id, source, name, description, currency, impact, starts_at_utc, starts_at_iso,
                         forecast, previous, first_seen_at, last_seen_at, updated_at, is_deleted, deleted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0, NULL)
                     ON DUPLICATE KEY UPDATE
                        source = VALUES(source),
                        name = VALUES(name),
                        description = VALUES(description),
                        currency = VALUES(currency),
                        impact = VALUES(impact),
                        starts_at_utc = VALUES(starts_at_utc),
                        starts_at_iso = VALUES(starts_at_iso),
                        forecast = VALUES(forecast),
                        previous = VALUES(previous),
                        last_seen_at = UTC_TIMESTAMP(),
                        updated_at = UTC_TIMESTAMP(),
                        is_deleted = 0,
                        deleted_at = NULL',
                    [
                        $event['external_id'],
                        $source,
                        $event['name'],
                        $event['description'],
                        $event['currency'],
                        $event['impact'],
                        $event['starts_at_utc'],
                        $event['starts_at_iso'],
                        $event['forecast'],
                        $event['previous'],
                    ]
                );
            }

            $notInClause = '';
            $params = [$windowStart, $windowEnd];
            if ($ids !== []) {
                $notInClause = ' AND external_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
            $sourceClause = $replaceAllSources ? '' : ' AND source = ?';
            if (!$replaceAllSources) {
                $params[] = $source;
            }
            $db->execute(
                'UPDATE macro_events
                    SET is_deleted = 1, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                  WHERE is_deleted = 0
                    AND starts_at_utc >= ? AND starts_at_utc < ?
                    ' . $notInClause . $sourceClause,
                $params
            );
        });

        return [
            'events_count' => count($rows),
            'updated_count' => count($rows),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $rows): array
    {
        return self::normalizeForexFactory($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeForexFactory(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['title'] ?? ''));
            $currency = strtoupper(trim((string) ($row['country'] ?? '')));
            $dateIso = trim((string) ($row['date'] ?? ''));
            $impactKey = strtolower(trim((string) ($row['impact'] ?? '')));
            if ($name === '' || $dateIso === '' || !isset(self::IMPACT_MAP[$impactKey])) {
                continue;
            }
            if (!in_array($currency, self::FF_CURRENCIES, true)) {
                continue;
            }

            try {
                $date = new \DateTimeImmutable($dateIso);
            } catch (\Throwable) {
                continue;
            }
            $startsAtUtc = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $forecast = trim((string) ($row['forecast'] ?? ''));
            $previous = trim((string) ($row['previous'] ?? ''));
            $byId[sha1(strtolower('forexfactory|' . $currency . '|' . $name . '|' . $dateIso))] = [
                'external_id' => sha1(strtolower('forexfactory|' . $currency . '|' . $name . '|' . $dateIso)),
                'name' => $name,
                'description' => self::description($forecast, $previous),
                'currency' => $currency,
                'impact' => self::IMPACT_MAP[$impactKey],
                'starts_at_utc' => $startsAtUtc,
                'starts_at_iso' => $dateIso,
                'forecast' => $forecast,
                'previous' => $previous,
            ];
        }

        return self::sorted(array_values($byId));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeTradingView(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['title'] ?? ''));
            $country = strtoupper(trim((string) ($row['country'] ?? '')));
            $dateIso = trim((string) ($row['date'] ?? ''));
            $importance = self::tradingViewImportance($row['importance'] ?? null);
            if ($name === '' || $dateIso === '' || $importance === null || $importance === -1) {
                continue;
            }
            $currency = self::TV_CURRENCY_MAP[$country] ?? '';
            if ($currency === '') {
                continue;
            }
            $lower = strtolower($name);
            foreach (self::TV_NOISE as $noise) {
                if (str_contains($lower, $noise)) {
                    continue 2;
                }
            }
            if ($importance === 0) {
                $matched = false;
                foreach (self::TV_MEDIUM_KEYWORDS as $keyword) {
                    if (str_contains($lower, $keyword)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }

            try {
                $date = new \DateTimeImmutable($dateIso);
            } catch (\Throwable) {
                continue;
            }
            $startsAtUtc = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            $forecast = self::scalar($row['forecast'] ?? '');
            $previous = self::scalar($row['previous'] ?? '');
            $id = sha1(strtolower('tradingview|' . $currency . '|' . $name . '|' . $dateIso));
            $byId[$id] = [
                'external_id' => $id,
                'name' => $name,
                'description' => self::description($forecast, $previous),
                'currency' => $currency,
                'impact' => self::TV_IMPORTANCE_MAP[$importance],
                'starts_at_utc' => $startsAtUtc,
                'starts_at_iso' => $dateIso,
                'forecast' => $forecast,
                'previous' => $previous,
            ];
        }

        return self::sorted(array_values($byId));
    }

    public function listForApi(): array
    {
        $rows = $this->db->query(
            'SELECT external_id, source, name, description, currency, impact, starts_at_utc, starts_at_iso, forecast, previous
               FROM macro_events
              WHERE is_deleted = 0
                AND starts_at_utc BETWEEN UTC_TIMESTAMP() - INTERVAL ' . self::FETCH_WINDOW_DAYS_BACK . ' DAY
                                      AND UTC_TIMESTAMP() + INTERVAL ' . self::FETCH_WINDOW_DAYS_FORWARD . ' DAY
              ORDER BY starts_at_utc ASC'
        );

        $events = array_map(static fn (array $row): array => [
            'id' => (string) $row['external_id'],
            'source' => (string) $row['source'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'currency' => (string) $row['currency'],
            'impact' => (int) $row['impact'],
            'startAt' => self::utcIso((string) $row['starts_at_utc']),
            'startIso' => (string) $row['starts_at_iso'],
            'forecast' => $row['forecast'] !== null ? (string) $row['forecast'] : '',
            'previous' => $row['previous'] !== null ? (string) $row['previous'] : '',
        ], $rows);

        return ['events' => $events, 'sync' => $this->syncState()];
    }

    public function syncState(): array
    {
        $row = $this->db->queryOne(
            'SELECT last_attempt_at, last_success_at, last_status, last_error, events_count, duration_ms
               FROM macro_sync_state WHERE id = 1'
        );
        if ($row === null) {
            return [
                'status' => 'never',
                'lastSuccessAt' => null,
                'lastError' => null,
                'eventsCount' => 0,
                'stale' => true,
            ];
        }

        $lastSuccess = $row['last_success_at'] !== null ? self::utcIso((string) $row['last_success_at']) : null;
        $stale = $row['last_success_at'] === null
            || strtotime((string) $row['last_success_at'] . ' UTC') < time() - self::STALE_AFTER_SECONDS;

        return [
            'status' => (string) ($row['last_status'] ?? 'never'),
            'lastSuccessAt' => $lastSuccess,
            'lastError' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'eventsCount' => (int) ($row['events_count'] ?? 0),
            'stale' => $stale,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fetchForexFactory(): array
    {
        return $this->fetchJson(self::FF_THIS_WEEK_URL, []);
    }

    /** @return list<array<string, mixed>> */
    private function fetchTradingView(\DateTimeImmutable $now): array
    {
        $utc = new \DateTimeZone('UTC');
        [$fromUtc, $toExclusiveUtc] = self::tradingViewWindowUtc($now);
        $from = new \DateTimeImmutable($fromUtc, $utc);
        // Ask through the exclusive boundary to avoid millisecond gaps in the final second.
        // syncPayload filters any event returned exactly at this boundary.
        $to = new \DateTimeImmutable($toExclusiveUtc, $utc);
        $url = self::TV_EVENTS_URL
            . '?from=' . rawurlencode($from->format('Y-m-d\TH:i:s.000\Z'))
            . '&to=' . rawurlencode($to->format('Y-m-d\TH:i:s.000\Z'))
            . '&countries=' . rawurlencode(implode(',', self::TV_COUNTRIES));

        $rows = $this->fetchJson($url, [
            'Origin: https://www.tradingview.com',
            'Referer: https://www.tradingview.com/',
        ]);
        $decoded = $rows['result'] ?? null;
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('TradingView zwrócił nieprawidłową odpowiedź kalendarza.');
        }
        return $decoded;
    }

    /** @return array<mixed> */
    private function fetchJson(string $url, array $extraHeaders): array
    {
        if ($this->feedFetcher !== null) {
            $decoded = ($this->feedFetcher)($url, $extraHeaders);
            if ($url === self::FF_THIS_WEEK_URL) {
                if (!is_array($decoded) || !array_is_list($decoded)) {
                    throw new \RuntimeException('ForexFactory zwrócił nieprawidłową listę wydarzeń.');
                }
                return self::feedRowsToArrays($decoded);
            }
            if ($decoded instanceof \stdClass) {
                $decoded = get_object_vars($decoded);
            }
            $tvRows = is_array($decoded) ? ($decoded['result'] ?? null) : null;
            if (!is_array($tvRows) || !array_is_list($tvRows)) {
                throw new \RuntimeException('TradingView zwrócił nieprawidłową listę wydarzeń.');
            }
            $decoded['result'] = self::feedRowsToArrays($tvRows);
            return $decoded;
        }

        $attempt = 0;
        $lastStatus = 0;
        $lastError = '';

        while ($attempt < 2) {
            $attempt++;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Nie można zainicjować cURL dla kalendarza makro.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: AlphaVault/3.4 (https://alphavault.pl)',
                    ...$extraHeaders,
                ],
            ]);
            $raw = curl_exec($ch);
            $lastStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $lastStatus !== 200) {
                if ($lastStatus === 429) {
                    break;
                }
                if ($attempt < 2) {
                    sleep(1);
                }
                continue;
            }

            return self::decodeJsonFeed((string) $raw, $url);
        }

        throw new \RuntimeException(sprintf(
            'Nie można pobrać kalendarza makro (HTTP %d%s).',
            $lastStatus,
            $lastError !== '' ? ': ' . $lastError : ''
        ));
    }

    /** @return array<mixed> */
    private static function decodeJsonFeed(string $raw, string $url): array
    {
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $err) {
            throw new \RuntimeException('Kalendarz makro zwrócił nieprawidłowy JSON.', 0, $err);
        }
        if ($url === self::FF_THIS_WEEK_URL) {
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new \RuntimeException('ForexFactory zwrócił nieprawidłową listę wydarzeń.');
            }
            return self::feedRowsToArrays($decoded);
        }
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('TradingView zwrócił nieprawidłowy obiekt kalendarza.');
        }
        $payload = get_object_vars($decoded);
        $tvRows = $payload['result'] ?? null;
        if (!is_array($tvRows) || !array_is_list($tvRows)) {
            throw new \RuntimeException('TradingView zwrócił nieprawidłową listę wydarzeń.');
        }
        $payload['result'] = self::feedRowsToArrays($tvRows);
        return $payload;
    }

    private function acquireLock(): bool
    {
        $row = $this->db->queryOne('SELECT GET_LOCK(?, 1) AS acquired', [self::LOCK_NAME]);
        return (int) ($row['acquired'] ?? 0) === 1;
    }

    private function releaseLock(): void
    {
        try {
            $this->db->queryOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        } catch (\Throwable) {
            // Zwolnienie blokady nie może wywrócić synchronizacji.
        }
    }

    private static function description(string $forecast, string $previous): string
    {
        $parts = [];
        if ($forecast !== '') {
            $parts[] = 'Prognoza ' . $forecast;
        }
        if ($previous !== '') {
            $parts[] = 'poprzednio ' . $previous;
        }
        return implode(' · ', $parts);
    }

    /** @param list<mixed> $rows */
    private static function hasForexFactoryFeedShape(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return false;
            }
            $titleValue = $row['title'] ?? null;
            $countryValue = $row['country'] ?? null;
            $impactValue = $row['impact'] ?? null;
            $dateValue = $row['date'] ?? null;
            if (!is_string($titleValue) || !is_string($countryValue) || !is_string($impactValue) || !is_string($dateValue)) {
                return false;
            }
            $title = trim($titleValue);
            $country = strtoupper(trim($countryValue));
            $impact = strtolower(trim($impactValue));
            $date = $dateValue;
            $currencyCountry = in_array($country, self::FF_CURRENCIES, true);
            $countryValid = $impact === 'holiday'
                ? ($currencyCountry || $country === 'ALL')
                : $currencyCountry;
            if ($title === '' || !$countryValid || !in_array($impact, ['high', 'medium', 'low', 'holiday'], true)
                || !self::isParseableDate($date)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<mixed> $rows */
    private static function hasTradingViewFeedShape(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return false;
            }
            $titleValue = $row['title'] ?? null;
            $countryValue = $row['country'] ?? null;
            $dateValue = $row['date'] ?? null;
            if (!is_string($titleValue) || !is_string($countryValue) || !is_string($dateValue)) {
                return false;
            }
            $title = trim($titleValue);
            $country = strtoupper(trim($countryValue));
            $importance = self::tradingViewImportance($row['importance'] ?? null);
            $date = $dateValue;
            if ($title === '' || !isset(self::TV_CURRENCY_MAP[$country]) || $importance === null
                || !self::isParseableDate($date)) {
                return false;
            }
        }
        return true;
    }

    private static function tradingViewImportance(mixed $value): ?int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
            || floor((float) $value) !== (float) $value) {
            return null;
        }
        $importance = (int) $value;
        return in_array($importance, [-1, 0, 1], true) ? $importance : null;
    }

    /** @param list<mixed> $rows @return list<mixed> */
    private static function feedRowsToArrays(array $rows): array
    {
        return array_map(
            static fn (mixed $row): mixed => $row instanceof \stdClass ? get_object_vars($row) : $row,
            $rows
        );
    }

    private static function isParseableDate(string $date): bool
    {
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z|z|[+-]\d{2}:\d{2})$/D',
            $date,
            $parts
        ) !== 1) {
            return false;
        }
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23
            || (int) $parts[5] > 59
            || (int) $parts[6] > 59) {
            return false;
        }
        if (strtoupper($parts[7]) === 'Z') {
            return true;
        }
        if (preg_match('/^[+-](\d{2}):(\d{2})$/', $parts[7], $offset) !== 1) {
            return false;
        }
        $offsetHours = (int) $offset[1];
        $offsetMinutes = (int) $offset[2];

        return $offsetMinutes <= 59
            && ($offsetHours < 14 || ($offsetHours === 14 && $offsetMinutes === 0));
    }

    private static function scalar(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim((string) $value, '0'), '.');
        }
        return trim((string) $value);
    }

    /** @param list<array<string, mixed>> $events */
    private static function sorted(array $events): array
    {
        usort($events, static fn (array $a, array $b): int => strcmp($a['starts_at_utc'], $b['starts_at_utc']));
        return $events;
    }

    /**
     * ForexFactory publishes its current week Sunday–Sunday (UTC).
     *
     * @return array{0:string,1:string} start and exclusive end in UTC
     */
    public static function forexFactoryWindowUtc(?\DateTimeImmutable $now = null): array
    {
        $utc = new \DateTimeZone('UTC');
        $now = ($now ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc);
        $daysSinceSunday = (int) $now->format('w');
        $start = $now->setTime(0, 0)->modify('-' . $daysSinceSunday . ' days');

        return [$start->format('Y-m-d H:i:s'), $start->modify('+7 days')->format('Y-m-d H:i:s')];
    }

    /**
     * Poniedziałek–poniedziałek UTC dla tygodnia względem bieżącego.
     *
     * @return array{0:string,1:string} start i koniec okna (koniec wyłączny)
     */
    public static function weekWindowUtc(int $weekOffset, ?\DateTimeImmutable $now = null): array
    {
        $utc = new \DateTimeZone('UTC');
        $now = ($now ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc);
        $daysSinceMonday = (int) $now->format('N') - 1;
        $start = $now->setTime(0, 0)->modify('-' . $daysSinceMonday . ' days');
        if ($weekOffset !== 0) {
            $start = $start->modify(sprintf('%+d weeks', $weekOffset));
        }
        return [$start->format('Y-m-d H:i:s'), $start->modify('+7 days')->format('Y-m-d H:i:s')];
    }

    /**
     * TradingView window: previous full week and full weeks +1 through +3.
     *
     * @return array{0:string,1:string} start and exclusive end in UTC
     */
    public static function tradingViewWindowUtc(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = self::weekWindowUtc(-1, $now)[0];
        $to = self::weekWindowUtc(4, $now)[0];
        return [$from, $to];
    }

    private static function utcIso(string $utcDateTime): string
    {
        try {
            $date = new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC'));
            return $date->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return '';
        }
    }

    private function log(string $message): void
    {
        $line = gmdate('c') . ' ' . $message . "\n";
        error_log('[macro-sync] ' . $message);
        try {
            $dir = $this->config->root . '/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents($dir . '/macro-sync.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logowanie nie może przerwać synchronizacji.
        }
    }
}
