<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once __DIR__ . '/mcp-test-environment.php';
mcpLoadIsolatedCiConfig($root);
[$config, $db] = require $root . '/app/bootstrap.php';
require_once $root . '/app/MacroEvents.php';

use AlphaVault\MacroEvents;

function check(bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['failed'] = true;
    }
}

$GLOBALS['failed'] = false;
$macro = new MacroEvents($config, $db);
$db->execute('DELETE FROM macro_events');
$db->execute(
    "UPDATE macro_sync_state SET last_status = 'never', last_success_at = NULL, last_error = NULL,
        events_count = 0, updated_count = 0, duration_ms = 0 WHERE id = 1"
);

$warsaw = new DateTimeZone('Europe/Warsaw');
$now = new DateTimeImmutable('now', $warsaw);
$weekday = (int) $now->format('N');
$monday = $now->modify('-' . ($weekday - 1) . ' days')->setTime(0, 0);
$nextMonday = $monday->modify('+7 days');

$fixedNow = new DateTimeImmutable('2026-11-18T23:30:00-05:00');
$ffWindow = MacroEvents::forexFactoryWindowUtc($fixedNow);
$weekPlus2 = MacroEvents::weekWindowUtc(2, $fixedNow);
$weekPlus3 = MacroEvents::weekWindowUtc(3, $fixedNow);
$tvWindow = MacroEvents::tradingViewWindowUtc($fixedNow);
check($ffWindow === ['2026-11-15 00:00:00', '2026-11-22 00:00:00'], 'ForexFactory: zachowuje bieżący tydzień niedziela–niedziela UTC');
check($weekPlus2 === ['2026-11-30 00:00:00', '2026-12-07 00:00:00'], 'weekWindowUtc: +2 od poniedziałku, granice UTC');
check($weekPlus3 === ['2026-12-07 00:00:00', '2026-12-14 00:00:00'], 'weekWindowUtc: pełny tydzień +3, koniec wyłączny');
check($tvWindow === ['2026-11-09 00:00:00', '2026-12-14 00:00:00'], 'tradingViewWindowUtc: poprzedni pełny tydzień oraz +1 do +3');

$decodeFeedJson = new ReflectionMethod(MacroEvents::class, 'decodeJsonFeed');
$parseFeedDate = new ReflectionMethod(MacroEvents::class, 'isParseableDate');
$ffUrl = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';
$tvUrl = 'https://economic-calendar.tradingview.com/events?from=fixture';
$emptyFfList = $decodeFeedJson->invoke(null, '[]', $ffUrl);
$emptyTvList = $decodeFeedJson->invoke(null, '{"result":[]}', $tvUrl);
$emptyObjectErrors = 0;
foreach ([['{}', $ffUrl], ['{"result":{}}', $tvUrl]] as [$json, $url]) {
    try {
        $decodeFeedJson->invoke(null, $json, $url);
    } catch (RuntimeException) {
        $emptyObjectErrors++;
    }
}
check(
    $emptyFfList === [] && $emptyTvList === ['result' => []] && $emptyObjectErrors === 2,
    'feed JSON: puste listy są sukcesem, puste obiekty są błędem'
);
$validFeedDate = $parseFeedDate->invoke(null, '2026-11-18T09:30:00.000Z');
$invalidFeedDates = array_map(
    static fn (string $date): bool => $parseFeedDate->invoke(null, $date),
    [
        'tomorrow',
        '2026-11-18T09:30:00',
        '2026-02-30T09:30:00Z',
        '2026-11-18T25:30:00Z',
        '2026-11-18T09:30:00+15:00',
    ]
);
check($validFeedDate === true && !in_array(true, $invalidFeedDates, true), 'feed date: strict ISO 8601 with valid offset and calendar date');

$utc = new DateTimeZone('UTC');
$utcNow = new DateTimeImmutable('now', $utc);
[$tvWindowStart, $tvWindowEnd] = MacroEvents::tradingViewWindowUtc($utcNow);
$weekPlus2Start = new DateTimeImmutable(MacroEvents::weekWindowUtc(2, $utcNow)[0], $utc);
$weekPlus3Start = new DateTimeImmutable(MacroEvents::weekWindowUtc(3, $utcNow)[0], $utc);
$tvWindowEndDate = new DateTimeImmutable($tvWindowEnd, $utc);

$fixture = [
    [
        'title' => 'Inflacja CPI w USA',
        'country' => 'USD',
        'date' => $monday->modify('+2 days')->setTime(14, 30)->format('Y-m-d\TH:i:sP'),
        'impact' => 'High',
        'forecast' => '0,3%',
        'previous' => '0,6%',
    ],
    [
        'title' => 'Zapasy ropy EIA',
        'country' => 'USD',
        'date' => $nextMonday->modify('+1 day')->setTime(16, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'Medium',
        'forecast' => '-0,6M',
        'previous' => '-0,9M',
    ],
    [
        'title' => 'Protokół FOMC',
        'country' => 'USD',
        'date' => $monday->modify('+4 days')->setTime(20, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'High',
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Dane pomijane',
        'country' => 'USD',
        'date' => $nextMonday->modify('+3 days')->setTime(10, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'Low',
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Holiday',
        'country' => 'All',
        'date' => $nextMonday->modify('+3 days')->setTime(10, 0)->format('Y-m-d\TH:i:sP'),
        'impact' => 'Holiday',
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Zły rekord',
        'country' => 'USD',
        'date' => 'to-nie-jest-data',
        'impact' => 'High',
        'forecast' => '',
        'previous' => '',
    ],
];

$normalized = MacroEvents::normalize($fixture);
check(count($normalized) === 3, 'normalize: tylko ★★ i ★★★, bez Low/Holiday/błędnych dat');
$impacts = array_column($normalized, 'impact');
check(in_array(3, $impacts, true) && in_array(2, $impacts, true), 'normalize: High=3, Medium=2');
check($normalized[0]['starts_at_utc'] === $monday->modify('+2 days')->setTime(13, 30)->format('Y-m-d H:i:s'), 'normalize: czas lokalny -> UTC');
check($normalized[0]['description'] === 'Prognoza 0,3% · poprzednio 0,6%', 'normalize: opis z prognozą i poprzednim odczytem');
check(strlen($normalized[0]['external_id']) === 40, 'normalize: stabilny identyfikator');

$first = $macro->syncPayload($normalized);
check($first['events_count'] === 3, 'syncPayload: pierwszy zapis 3 wydarzeń');
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0');
check((int) $count['c'] === 3, 'syncPayload: 3 aktywne rekordy w bazie');

$macro->syncPayload($normalized);
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0');
check((int) $count['c'] === 3, 'syncPayload: powtórka nie tworzy duplikatów');

$list = $macro->listForApi();
check(count($list['events']) === 3, 'listForApi: zwraca 3 wydarzenia z okna');
check(($list['events'][0]['startAt'] ?? '') !== '' && str_ends_with($list['events'][0]['startAt'], 'Z'), 'listForApi: startAt w UTC ISO');
check(($list['events'][0]['startIso'] ?? '') !== '', 'listForApi: oryginalne ISO zachowane');
check(($list['events'][0]['source'] ?? '') === 'forexfactory', 'listForApi: źródło zapisane');

$apiNowRow = $db->queryOne('SELECT UTC_TIMESTAMP() AS now_utc');
$apiNow = new DateTimeImmutable((string) $apiNowRow['now_utc'], $utc);
$previousWeekStart = new DateTimeImmutable(MacroEvents::weekWindowUtc(-1, $apiNow)[0], $utc);
$apiBoundaryRows = MacroEvents::normalizeTradingView([
    [
        'title' => 'US CPI previous complete week start',
        'country' => 'US',
        'importance' => 1,
        'date' => $previousWeekStart->modify('+1 hour')->format('Y-m-d\TH:i:s.000\Z'),
    ],
    [
        'title' => 'US CPI inside API lower bound',
        'country' => 'US',
        'importance' => 1,
        'date' => $apiNow->modify('-14 days')->modify('+1 hour')->format('Y-m-d\TH:i:s.000\Z'),
    ],
    [
        'title' => 'US CPI outside API lower bound',
        'country' => 'US',
        'importance' => 1,
        'date' => $apiNow->modify('-15 days')->format('Y-m-d\TH:i:s.000\Z'),
    ],
    [
        'title' => 'US CPI outside API upper bound',
        'country' => 'US',
        'importance' => 1,
        'date' => $apiNow->modify('+36 days')->format('Y-m-d\TH:i:s.000\Z'),
    ],
    [
        'title' => 'US CPI inside API upper bound',
        'country' => 'US',
        'importance' => 1,
        'date' => $apiNow->modify('+35 days')->modify('-1 hour')->format('Y-m-d\TH:i:s.000\Z'),
    ],
]);
$apiBoundaryStart = min(array_column($apiBoundaryRows, 'starts_at_utc'));
$apiBoundaryEnd = (new DateTimeImmutable(max(array_column($apiBoundaryRows, 'starts_at_utc')), $utc))
    ->modify('+1 second')->format('Y-m-d H:i:s');
$macro->syncPayload($apiBoundaryRows, null, 'tradingview', false, $apiBoundaryStart, $apiBoundaryEnd);
$apiBounded = $macro->listForApi();
$apiBoundedNames = array_column($apiBounded['events'], 'name');
check(
    in_array('US CPI inside API lower bound', $apiBoundedNames, true)
    && in_array('US CPI previous complete week start', $apiBoundedNames, true)
    && in_array('US CPI inside API upper bound', $apiBoundedNames, true)
    && !in_array('US CPI outside API lower bound', $apiBoundedNames, true)
    && !in_array('US CPI outside API upper bound', $apiBoundedNames, true),
    'listForApi: obejmuje poprzedni pełny tydzień oraz zakres −14/+35 dni'
);
$apiBoundaryIds = array_column($apiBoundaryRows, 'external_id');
$db->execute(
    'DELETE FROM macro_events WHERE external_id IN (' . implode(',', array_fill(0, count($apiBoundaryIds), '?')) . ')',
    $apiBoundaryIds
);

$withoutFomc = array_values(array_filter($normalized, static fn (array $e): bool => $e['name'] !== 'Protokół FOMC'));
$fomcId = array_values(array_filter($normalized, static fn (array $e): bool => $e['name'] === 'Protokół FOMC'))[0]['external_id'];
$macro->syncPayload($withoutFomc);
$row = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$fomcId]);
check((int) ($row['is_deleted'] ?? 0) === 1, 'syncPayload: brak w feedzie = soft delete');

$macro->syncPayload($normalized);
$row = $db->queryOne('SELECT is_deleted, deleted_at FROM macro_events WHERE external_id = ?', [$fomcId]);
check((int) ($row['is_deleted'] ?? 1) === 0 && $row['deleted_at'] === null, 'syncPayload: powrót wydarzenia przywraca rekord');

$macro->syncPayload([]);
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0');
check((int) $count['c'] === 3, 'syncPayload: pusta lista nie czyści widoku');

$tvUtc = $utc;
$tvRows = [
    [
        'title' => 'Retail Sales MoM',
        'country' => 'US',
        'importance' => 1,
        'date' => $nextMonday->modify('+1 day')->setTime(14, 30)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => 0.4,
        'previous' => 0.2,
    ],
    [
        'title' => 'NY Empire State Manufacturing Index',
        'country' => 'US',
        'importance' => 0,
        'date' => $nextMonday->modify('+1 day')->setTime(14, 30)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => 25.5,
    ],
    [
        'title' => '6-Month Bill Auction Bid-to-Cover',
        'country' => 'US',
        'importance' => 1,
        'date' => $nextMonday->modify('+1 day')->setTime(16, 30)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'Low impact',
        'country' => 'US',
        'importance' => -1,
        'date' => $nextMonday->modify('+2 days')->setTime(10, 0)->setTimezone($tvUtc)->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'US CPI week +2 boundary',
        'country' => 'US',
        'importance' => 1,
        'date' => $weekPlus2Start->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'US GDP week +3 boundary',
        'country' => 'US',
        'importance' => 1,
        'date' => $weekPlus3Start->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'US Jobless Claims final second of week +3',
        'country' => 'US',
        'importance' => 1,
        'date' => $tvWindowEndDate->modify('-1 second')->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
    [
        'title' => 'US GDP at exclusive end of week +3',
        'country' => 'US',
        'importance' => 1,
        'date' => $tvWindowEndDate->format('Y-m-d\TH:i:s.000\Z'),
        'forecast' => '',
        'previous' => '',
    ],
];
$tv = MacroEvents::normalizeTradingView($tvRows);
check(count($tv) === 6, 'normalizeTradingView: importance 1/0, bez aukcji i -1');
check($tv[0]['currency'] === 'USD' && $tv[0]['impact'] === 3, 'normalizeTradingView: kraj -> waluta i impact');
check($tv[1]['impact'] === 2 && $tv[1]['previous'] === '25.5', 'normalizeTradingView: medium + liczba jako tekst');
$invalidImportance = MacroEvents::normalizeTradingView([[
    'title' => 'US CPI malformed importance',
    'country' => 'US',
    'importance' => 'junk',
    'date' => $nextMonday->modify('+1 day')->setTime(15, 0)->format('Y-m-d\TH:i:s.000\Z'),
]]);
check($invalidImportance === [], 'normalizeTradingView: tekst importance nie jest rzutowany na medium');

$tvResult = $macro->syncPayload($tv, null, 'tradingview', false, $tvWindowStart, $tvWindowEnd);
$count = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0 AND source = ?', ['tradingview']);
check($tvResult['events_count'] === 5 && (int) $count['c'] === 5, 'syncPayload: zapis tygodni +1–+3, wyłączna granica +4');

$futureApi = $macro->listForApi();
$futureEndEvent = array_values(array_filter(
    $futureApi['events'],
    static fn (array $event): bool => $event['name'] === 'US Jobless Claims final second of week +3'
));
check(count($futureApi['events']) === 8 && count($futureEndEvent) === 1, 'listForApi: obejmuje końcówkę pełnego tygodnia +3');

$macro->syncPayload([], null, 'tradingview', false, $tvWindowStart, $tvWindowEnd);
$tvCount = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0 AND source = ?', ['tradingview']);
$ffCount = $db->queryOne('SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 0 AND source = ?', ['forexfactory']);
$tvDeleted = $db->queryOne(
    "SELECT COUNT(*) AS c FROM macro_events WHERE is_deleted = 1 AND source = 'tradingview' AND deleted_at IS NOT NULL"
);
check(
    (int) $tvCount['c'] === 0 && (int) $tvDeleted['c'] === 5 && (int) $ffCount['c'] === 3,
    'syncPayload: pusty sukces soft-deletuje rekordy tylko w zakresie TV'
);
$macro->syncPayload($tv, null, 'tradingview', false, $tvWindowStart, $tvWindowEnd);

// FF wins an actual Sunday–Sunday / +1…+3 overlap; more distant TV events remain active.
$overlapNow = new DateTimeImmutable('2026-11-22T12:00:00Z');
$ffOverlapWindow = MacroEvents::forexFactoryWindowUtc($overlapNow);
$tvOverlapWindow = MacroEvents::tradingViewWindowUtc($overlapNow);
$overlapAt = (new DateTimeImmutable($ffOverlapWindow[0], $utc))->modify('+1 day')->setTime(14, 30);
$futureTvAt = (new DateTimeImmutable($tvOverlapWindow[1], $utc))->modify('-1 second');
$tvOverlapRows = MacroEvents::normalizeTradingView([
    [
        'title' => 'Retail Sales m/m',
        'country' => 'US',
        'importance' => 1,
        'date' => $overlapAt->format('Y-m-d\TH:i:s.000\Z'),
    ],
    [
        'title' => 'US CPI week +3 end',
        'country' => 'US',
        'importance' => 1,
        'date' => $futureTvAt->format('Y-m-d\TH:i:s.000\Z'),
    ],
]);
$ffOverlapRows = MacroEvents::normalizeForexFactory([[
    'title' => 'Retail Sales m/m',
    'country' => 'USD',
    'date' => $overlapAt->format('Y-m-d\TH:i:sP'),
    'impact' => 'High',
    'forecast' => '0,4%',
    'previous' => '0,2%',
]]);
$db->execute('DELETE FROM macro_events');
$syncFeeds = new ReflectionMethod(MacroEvents::class, 'syncNormalizedFeeds');
$syncFeeds->setAccessible(true);
$syncFeeds->invoke($macro, $ffOverlapRows, $tvOverlapRows, microtime(true), $ffOverlapWindow, $tvOverlapWindow);
$overlapTv = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$tvOverlapRows[0]['external_id']]);
$overlapFf = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$ffOverlapRows[0]['external_id']]);
$futureTv = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$tvOverlapRows[1]['external_id']]);
check(
    (int) ($overlapTv['is_deleted'] ?? 0) === 1
    && (int) ($overlapFf['is_deleted'] ?? 1) === 0
    && (int) ($futureTv['is_deleted'] ?? 1) === 0,
    'syncPayload: FF ma priorytet w nakładającym się oknie, dalsze tygodnie TV pozostają aktywne'
);
$syncFeeds->invoke($macro, $ffOverlapRows, null, microtime(true), $ffOverlapWindow, $tvOverlapWindow);
$futureTvAfterFailure = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$tvOverlapRows[1]['external_id']]);
check(
    (int) ($futureTvAfterFailure['is_deleted'] ?? 1) === 0,
    'syncNormalizedFeeds: błąd TV pomija synchronizację TV i zachowuje ostatnie dobre dane'
);

$db->execute(
    "UPDATE macro_sync_state SET last_status = 'partial', last_error = 'TradingView test failure', last_success_at = UTC_TIMESTAMP(), events_count = 3 WHERE id = 1"
);
$state = $macro->syncState();
check($state['status'] === 'partial' && $state['lastError'] === 'TradingView test failure' && $state['stale'] === false && $state['eventsCount'] === 3, 'syncState: błąd częściowego feedu widoczny');
$db->execute(
    "UPDATE macro_sync_state SET last_status = 'success', last_error = NULL, last_success_at = UTC_TIMESTAMP(), events_count = 3 WHERE id = 1"
);
$state = $macro->syncState();
check($state['status'] === 'success' && $state['lastError'] === null && $state['stale'] === false && $state['eventsCount'] === 3, 'syncState: sukces i świeżość');

$integrationNow = new DateTimeImmutable('now', $utc);
[$integrationTvStart, $integrationTvEnd] = MacroEvents::tradingViewWindowUtc($integrationNow);
$integrationStart = new DateTimeImmutable($integrationTvStart, $utc);
$oldTvRows = MacroEvents::normalizeTradingView([[
    'title' => 'US CPI sync noise cleanup',
    'country' => 'US',
    'importance' => 1,
    'date' => $integrationStart->modify('+2 hours')->format('Y-m-d\TH:i:s.000\Z'),
]]);
$macro->syncPayload($oldTvRows, microtime(true), 'tradingview', false, $integrationTvStart, $integrationTvEnd);
$noiseRows = [[
    'title' => 'US Treasury Bill Auction',
    'country' => 'US',
    'importance' => 1,
    'date' => $integrationStart->modify('+3 hours')->format('Y-m-d\TH:i:s.000\Z'),
]];
$noiseSync = new MacroEvents($config, $db, static function (string $url, array $headers) use ($noiseRows): array {
    return str_contains($url, 'ff_calendar_thisweek.json') ? [] : ['result' => $noiseRows];
});
$noiseResult = $noiseSync->sync();
$noiseState = $noiseSync->syncState();
$noiseCleaned = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$oldTvRows[0]['external_id']]);
check(
    $noiseResult['events_count'] === 0 && $noiseState['status'] === 'success'
    && $noiseState['lastError'] === null && (int) ($noiseCleaned['is_deleted'] ?? 0) === 1,
    'sync: poprawny feed z samymi odfiltrowanymi rekordami czyści wyłącznie własne okno'
);

$ffIntegrationNow = new DateTimeImmutable('now', $utc);
[$ffIntegrationStart, $ffIntegrationEnd] = MacroEvents::forexFactoryWindowUtc($ffIntegrationNow);
$ffStart = new DateTimeImmutable($ffIntegrationStart, $utc);
$oldFfRows = MacroEvents::normalizeForexFactory([[
    'title' => 'US CPI sync noise cleanup',
    'country' => 'USD',
    'impact' => 'High',
    'date' => $ffStart->modify('+2 hours')->format('Y-m-d\TH:i:sP'),
]]);
$macro->syncPayload($oldFfRows, microtime(true), 'forexfactory', true, $ffIntegrationStart, $ffIntegrationEnd);
$ffNoiseRows = [[
    'title' => 'US Federal Holiday',
    'country' => 'USD',
    'impact' => 'Holiday',
    'date' => $ffStart->modify('+3 hours')->format('Y-m-d\TH:i:sP'),
]];
$ffNoiseSync = new MacroEvents($config, $db, static function (string $url, array $headers) use ($ffNoiseRows): array {
    return str_contains($url, 'ff_calendar_thisweek.json') ? $ffNoiseRows : ['result' => []];
});
$ffNoiseSync->sync();
$ffNoiseState = $ffNoiseSync->syncState();
$ffNoiseCleaned = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$oldFfRows[0]['external_id']]);
check(
    $ffNoiseState['status'] === 'success' && $ffNoiseState['lastError'] === null
    && (int) ($ffNoiseCleaned['is_deleted'] ?? 0) === 1,
    'sync: poprawny feed ForexFactory z samymi świętami czyści własne okno'
);

$macro->syncPayload($oldFfRows, microtime(true), 'forexfactory', true, $ffIntegrationStart, $ffIntegrationEnd);
$emptyObjectFfSync = new MacroEvents($config, $db, static function (string $url, array $headers) {
    return str_contains($url, 'ff_calendar_thisweek.json') ? new stdClass() : ['result' => []];
});
$emptyObjectFailed = false;
try {
    $emptyObjectFfSync->sync();
} catch (RuntimeException) {
    $emptyObjectFailed = true;
}
$emptyObjectState = $emptyObjectFfSync->syncState();
$ffAfterEmptyObject = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$oldFfRows[0]['external_id']]);
check(
    $emptyObjectFailed && $emptyObjectState['status'] === 'error'
    && (int) ($ffAfterEmptyObject['is_deleted'] ?? 1) === 0,
    'sync: pusty obiekt ForexFactory jest błędem, nie czyści poprawnych danych'
);

$invalidCountryFfSync = new MacroEvents($config, $db, static function (string $url, array $headers) use ($ffStart): array {
    $invalidRows = [[
        'title' => 'US CPI invalid country',
        'country' => 'All',
        'impact' => 'High',
        'date' => $ffStart->modify('+3 hours')->format('Y-m-d\TH:i:sP'),
    ]];
    return str_contains($url, 'ff_calendar_thisweek.json') ? $invalidRows : ['result' => []];
});
$invalidCountryFailed = false;
try {
    $invalidCountryFfSync->sync();
} catch (RuntimeException) {
    $invalidCountryFailed = true;
}
$invalidCountryState = $invalidCountryFfSync->syncState();
$ffAfterInvalidCountry = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$oldFfRows[0]['external_id']]);
check(
    $invalidCountryFailed && $invalidCountryState['status'] === 'error'
    && (int) ($ffAfterInvalidCountry['is_deleted'] ?? 1) === 0,
    'sync: nieprawidłowy kraj ForexFactory jest błędem i zachowuje ostatnie dane'
);

$retainedTvRows = MacroEvents::normalizeTradingView([[
    'title' => 'US CPI sync retained fixture',
    'country' => 'US',
    'importance' => 1,
    'date' => $integrationStart->modify('+4 hours')->format('Y-m-d\TH:i:s.000\Z'),
]]);
$macro->syncPayload($retainedTvRows, microtime(true), 'tradingview', false, $integrationTvStart, $integrationTvEnd);
$db->execute("UPDATE macro_sync_state SET last_success_at = '2020-01-02 03:04:05' WHERE id = 1");
$successBeforePartial = $db->queryOne('SELECT last_success_at FROM macro_sync_state WHERE id = 1')['last_success_at'];
$emptyObjectTvSync = new MacroEvents($config, $db, static function (string $url, array $headers): array {
    return str_contains($url, 'ff_calendar_thisweek.json') ? [] : ['result' => new stdClass()];
});
$emptyObjectTvSync->sync();
$emptyObjectTvState = $emptyObjectTvSync->syncState();
$retainedAfterEmptyObjectTv = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$retainedTvRows[0]['external_id']]);
check(
    $emptyObjectTvState['status'] === 'partial'
    && (int) ($retainedAfterEmptyObjectTv['is_deleted'] ?? 1) === 0,
    'sync: obiekt TradingView result jest błędem, nie pustym sukcesem'
);
$junkImportanceRows = [[
    'title' => 'US CPI malformed importance retained check',
    'country' => 'US',
    'importance' => 'junk',
    'date' => $integrationStart->modify('+5 hours')->format('Y-m-d\TH:i:s.000\Z'),
]];
$malformedTvSync = new MacroEvents($config, $db, static function (string $url, array $headers) use ($junkImportanceRows): array {
    return str_contains($url, 'ff_calendar_thisweek.json') ? [] : ['result' => $junkImportanceRows];
});
$malformedTvSync->sync();
$malformedTvState = $malformedTvSync->syncState();
$retainedAfterMalformedTv = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$retainedTvRows[0]['external_id']]);
check(
    $malformedTvState['status'] === 'partial'
    && str_contains((string) $malformedTvState['lastError'], 'nieprawidłowy kształt')
    && (int) ($retainedAfterMalformedTv['is_deleted'] ?? 1) === 0,
    'sync: nieprawidłowe importance TradingView daje partial i zachowuje ostatnie dobre dane'
);
$partialSync = new MacroEvents($config, $db, static function (string $url, array $headers): array {
    if (str_contains($url, 'ff_calendar_thisweek.json')) {
        return [];
    }
    throw new RuntimeException('Testowy błąd TradingView');
});
$partialSync->sync();
$partialState = $partialSync->syncState();
$retainedAfterPartial = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$retainedTvRows[0]['external_id']]);
$successAfterPartial = $db->queryOne('SELECT last_success_at FROM macro_sync_state WHERE id = 1')['last_success_at'];
check(
    $partialState['status'] === 'partial' && $partialState['lastError'] === 'Testowy błąd TradingView'
    && $successBeforePartial === $successAfterPartial
    && (int) ($retainedAfterPartial['is_deleted'] ?? 1) === 0,
    'sync: błąd TradingView oznacza partial, zachowuje lastSuccessAt i ostatnie dobre dane'
);

$errorSync = new MacroEvents($config, $db, static function (string $url, array $headers): array {
    throw new RuntimeException('Testowy błąd ForexFactory');
});
$errorThrown = false;
try {
    $errorSync->sync();
} catch (RuntimeException $err) {
    $errorThrown = $err->getMessage() === 'Testowy błąd ForexFactory';
}
$errorState = $errorSync->syncState();
$retainedAfterError = $db->queryOne('SELECT is_deleted FROM macro_events WHERE external_id = ?', [$retainedTvRows[0]['external_id']]);
$successAfterError = $db->queryOne('SELECT last_success_at FROM macro_sync_state WHERE id = 1')['last_success_at'];
check(
    $errorThrown && $errorState['status'] === 'error' && $errorState['lastError'] === 'Testowy błąd ForexFactory'
    && $successBeforePartial === $successAfterError
    && (int) ($retainedAfterError['is_deleted'] ?? 1) === 0,
    'sync: błąd ForexFactory oznacza error, zachowuje lastSuccessAt i ostatnie dobre dane'
);

$coldStartNow = new DateTimeImmutable('now', $utc);
$coldCurrentWeekAt = $coldStartNow;
$coldFfRows = [[
    'title' => 'US CPI current week cold start',
    'country' => 'USD',
    'impact' => 'High',
    'date' => $coldCurrentWeekAt->format('Y-m-d\TH:i:sP'),
]];
$db->execute('DELETE FROM macro_events');
$coldTvRequest = null;
$coldStartSync = new MacroEvents($config, $db, static function (string $url, array $headers) use (&$coldTvRequest, $coldFfRows): array {
    if (str_contains($url, 'ff_calendar_thisweek.json')) {
        return $coldFfRows;
    }
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $coldTvRequest = ['from' => $query['from'] ?? null, 'to' => $query['to'] ?? null];
    $tvStart = new DateTimeImmutable((string) ($query['from'] ?? ''), new DateTimeZone('UTC'));
    $tvEnd = new DateTimeImmutable((string) ($query['to'] ?? ''), new DateTimeZone('UTC'));
    return ['result' => [
        [
            'title' => 'US CPI previous week cold start',
            'country' => 'US',
            'importance' => 1,
            'date' => $tvStart->modify('+1 day')->format('Y-m-d\TH:i:s.000\Z'),
        ],
        [
            'title' => 'US CPI future week cold start',
            'country' => 'US',
            'importance' => 1,
            'date' => $tvEnd->modify('-1 second')->format('Y-m-d\TH:i:s.000\Z'),
        ],
    ]];
});
$coldStartSync->sync();
$coldStartNames = array_column($coldStartSync->listForApi()['events'], 'name');
$requestedFrom = new DateTimeImmutable((string) ($coldTvRequest['from'] ?? ''), $utc);
$requestedTo = new DateTimeImmutable((string) ($coldTvRequest['to'] ?? ''), $utc);
check(
    $coldTvRequest !== null
    && $requestedFrom->format('N H:i:s') === '1 00:00:00'
    && $requestedTo->getTimestamp() - $requestedFrom->getTimestamp() === 35 * 86400
    && in_array('US CPI previous week cold start', $coldStartNames, true)
    && in_array('US CPI current week cold start', $coldStartNames, true)
    && in_array('US CPI future week cold start', $coldStartNames, true),
    'sync: fetch żąda poprzedniego pełnego tygodnia…+3 i cold-start zwraca poprzedni tydzień, bieżący FF oraz +3 TV'
);

$db->execute('DELETE FROM macro_events');
$db->execute(
    "UPDATE macro_sync_state
        SET last_status = 'never', last_success_at = NULL, last_error = NULL,
            events_count = 0, updated_count = 0, duration_ms = 0, updated_at = UTC_TIMESTAMP()
      WHERE id = 1"
);

if ($GLOBALS['failed']) {
    exit(1);
}
echo "Macro events: wszystkie testy PASS\n";
