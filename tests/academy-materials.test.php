<?php
declare(strict_types=1);

use AlphaVault\AcademyMaterials;
use AlphaVault\HttpException;

$root = dirname(__DIR__);
require_once __DIR__ . '/mcp-test-environment.php';
mcpLoadIsolatedCiConfig($root);
[$config, $db] = require $root . '/app/bootstrap.php';

function academyCheck(bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['academy_failed'] = true;
    }
}

function academyMessage(
    string $id,
    string $channelId,
    string $timestamp,
    string $content,
    string $authorId = '333333333333333333',
    array $extra = [],
): array {
    return array_replace([
        'id' => $id,
        'channel_id' => $channelId,
        'type' => 0,
        'timestamp' => $timestamp,
        'edited_timestamp' => null,
        'content' => $content,
        'author' => [
            'id' => $authorId,
            'username' => 'Fixture user',
            'global_name' => 'Fixture user',
            'bot' => false,
        ],
        'member' => ['nick' => 'Fixture user'],
        'attachments' => [],
        'embeds' => [],
        'message_reference' => null,
        'mentions' => [],
    ], $extra);
}

function academyAllBackfillsComplete(\AlphaVault\Db $db, array $channelIds): bool
{
    $row = $db->queryOne(
        'SELECT COUNT(*) AS total, SUM(backfill_complete = 1) AS complete_count
           FROM academy_material_channel_state
          WHERE channel_id IN (' . implode(',', array_fill(0, count($channelIds), '?')) . ')',
        $channelIds
    );
    return (int) ($row['total'] ?? 0) === count($channelIds)
        && (int) ($row['complete_count'] ?? 0) === count($channelIds);
}

function academySetAudit(\AlphaVault\Db $db, string $channelId): void
{
    $db->execute(
        'UPDATE academy_material_channel_state
            SET audit_running = 1, audit_cleanup_pending = 0,
                audit_content_verified = 1,
                audit_before_message_id = NULL, audit_started_at = UTC_TIMESTAMP(),
                last_audit_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY), work_order = 0
          WHERE channel_id = ?',
        [$channelId]
    );
    $db->execute(
        'UPDATE academy_material_messages SET last_audit_seen_at = NULL WHERE channel_id = ?',
        [$channelId]
    );
}

$GLOBALS['academy_failed'] = false;
$db->execute('DELETE FROM academy_material_items');
$db->execute('DELETE FROM academy_material_messages');
$db->execute('DELETE FROM academy_material_channel_state');
$db->execute('DELETE FROM academy_material_sync_state');

$channels = AcademyMaterials::allowlist();
$channelIds = array_column($channels, 'channelId');
$channelMap = [];
foreach ($channels as $channel) {
    $channelMap[$channel['channelId']] = $channel;
}
$academyChannel = '1499560912611446784';
$pagedChannel = '1499560977849384991';
$emptyChannel = '1186973732925874217';
$contentHiddenChannel = '1186976565909794906';
$permissionChannel = '1186978545902309456';
$categoryFailureChannel = '1186979958187368468';
$sourceOneId = '600000000000000001';
$sourceTwoId = '600000000000000002';
$captionId = '600000000000000003';
$pagedBase = 700000000000000000;
$pagedIds = [];
for ($index = 0; $index < 100; $index++) {
    $pagedIds[] = (string) ($pagedBase + $index);
}
$pagedOldId = (string) ($pagedBase - 1);
$pagedNewId = (string) ($pagedBase + 120);

$sourceOne = academyMessage(
    $sourceOneId,
    $academyChannel,
    '2026-11-24T09:00:00Z',
    '',
    '333333333333333333',
    [
        'attachments' => [[
            'id' => '800000000000000001',
            'filename' => 'lekcja.mp4',
            'content_type' => 'video/mp4',
            'url' => 'https://cdn.discordapp.com/attachments/private-fixture-marker',
            'proxy_url' => 'https://media.discordapp.net/private-fixture-marker',
        ]],
        'embeds' => [['title' => 'Lekcja o wybiciu', 'description' => '']],
    ]
);
$sourceTwo = academyMessage(
    $sourceTwoId,
    $academyChannel,
    '2026-11-24T09:04:00Z',
    '',
    '333333333333333333',
    [
        'attachments' => [[
            'id' => '800000000000000002',
            'filename' => 'drugi.mp4',
            'content_type' => 'video/mp4',
            'url' => 'https://cdn.discordapp.com/attachments/private-fixture-marker-two',
        ]],
        'embeds' => [['title' => 'Drugi materiał', 'description' => '']],
    ]
);
$caption = academyMessage(
    $captionId,
    $academyChannel,
    '2026-11-24T09:06:00Z',
    'Academyneedle — opis materiału i kontekst dla uczestników.',
    '333333333333333333',
    [
        'type' => 19,
        'message_reference' => [
            'message_id' => $sourceOneId,
            'channel_id' => $academyChannel,
        ],
    ]
);
$datasetA = [$caption, $sourceTwo, $sourceOne];
$auditDatasetA = null;
$failMessageChannel = null;
$failMessageStatus = null;
$failMetadataChannel = null;
$failMetadataStatus = null;
$failMetadataChannels = [];
$failMetadataChannelsStatus = null;
$badMetadataChannel = null;
$rateLimitOnce = false;
$rateLimitUsed = false;
$sleeps = [];
$requests = [];
$genericMessages = [];
foreach ($channels as $index => $channel) {
    $id = $channel['channelId'];
    if (in_array($id, [$academyChannel, $pagedChannel, $emptyChannel, $contentHiddenChannel], true)) {
        continue;
    }
    $baseId = (string) (800000000000000100 + $index);
    if ($id === $categoryFailureChannel) {
        $baseId = '800000000000000199';
    }
    if ($id === $permissionChannel) {
        $baseId = '800000000000000188';
    }
    $genericMessages[$id] = [academyMessage(
        $baseId,
        $id,
        '2026-11-25T10:00:00Z',
        'Fixture material ' . $index . ' with marker ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT)
    )];
}
$genericMessages[$contentHiddenChannel] = [];
$contentHiddenMessages = [];
for ($index = 1; $index <= 5; $index++) {
    $contentHiddenMessages[] = academyMessage(
        (string) (900000000000000000 + $index),
        $contentHiddenChannel,
        '2026-11-25T11:00:00Z',
        ''
    );
}

$requester = static function (string $method, string $path, array $query) use (
    &$requests,
    &$datasetA,
    &$auditDatasetA,
    &$genericMessages,
    &$contentHiddenMessages,
    &$failMessageChannel,
    &$failMessageStatus,
    &$failMetadataChannel,
    &$failMetadataStatus,
    &$failMetadataChannels,
    &$failMetadataChannelsStatus,
    &$badMetadataChannel,
    &$rateLimitOnce,
    &$rateLimitUsed,
    $channelMap,
    $academyChannel,
    $pagedChannel,
    $pagedIds,
    $pagedOldId,
    $pagedNewId,
): array {
    $requests[] = ['method' => $method, 'path' => $path, 'query' => $query];
    if ($method !== 'GET') {
        throw new RuntimeException('fixture_non_get');
    }
    if (preg_match('#^/channels/([0-9]+)$#D', $path, $match) === 1) {
        $channelId = $match[1];
        if (!AcademyMaterials::isAllowedChannel($channelId) || !isset($channelMap[$channelId])) {
            throw new RuntimeException('fixture_unapproved_metadata');
        }
        if ($failMetadataChannel === $channelId && $failMetadataStatus !== null) {
            return ['status' => $failMetadataStatus, 'body' => ['message' => 'fixture metadata failure']];
        }
        if (in_array($channelId, $failMetadataChannels, true) && $failMetadataChannelsStatus !== null) {
            return ['status' => $failMetadataChannelsStatus, 'body' => ['message' => 'fixture metadata failure']];
        }
        if ($rateLimitOnce && !$rateLimitUsed) {
            $rateLimitUsed = true;
            return ['status' => 429, 'headers' => ['retry-after' => '0.5'], 'body' => ['retry_after' => 0.5]];
        }
        $channel = $channelMap[$channelId];
        return [
            'status' => 200,
            'body' => [
                'id' => $channelId,
                'guild_id' => '1186734786388176916',
                'parent_id' => $badMetadataChannel === $channelId
                    ? '1186777064494338069'
                    : $channel['parentCategoryId'],
                'type' => 0,
            ],
        ];
    }
    if (preg_match('#^/channels/([0-9]+)/messages$#D', $path, $match) !== 1) {
        throw new RuntimeException('fixture_unapproved_path');
    }
    $channelId = $match[1];
    if (!AcademyMaterials::isAllowedChannel($channelId) || !isset($channelMap[$channelId])) {
        throw new RuntimeException('fixture_unapproved_messages');
    }
    if ($failMessageChannel === $channelId && $failMessageStatus !== null) {
        return ['status' => $failMessageStatus, 'body' => ['message' => 'fixture failure']];
    }
    if ($channelId === $pagedChannel) {
        if (isset($query['before'])) {
            if ((string) $query['before'] === $pagedIds[0]) {
                return [
                    'status' => 200,
                    'body' => [academyMessage(
                        $pagedOldId,
                        $pagedChannel,
                        '2026-11-20T10:00:00Z',
                        'Oldest page checkpoint fixture.'
                    )],
                ];
            }
            return ['status' => 200, 'body' => []];
        }
        if (isset($query['after'])) {
            if ((string) $query['after'] === $pagedIds[99]) {
                return [
                    'status' => 200,
                    'body' => [academyMessage(
                        $pagedNewId,
                        $pagedChannel,
                        '2026-11-26T10:00:00Z',
                        'Incremental after-cursor fixture.'
                    )],
                ];
            }
            return ['status' => 200, 'body' => []];
        }
        $page = [];
        foreach (array_reverse($pagedIds) as $index => $id) {
            $page[] = academyMessage(
                $id,
                $pagedChannel,
                '2026-11-22T10:00:00Z',
                'Paged material fixture ' . $index
            );
        }
        return ['status' => 200, 'body' => $page];
    }
    if ($channelId === $academyChannel) {
        if (isset($query['after'])) {
            return ['status' => 200, 'body' => []];
        }
        $messages = $auditDatasetA ?? $datasetA;
        if (isset($query['before'])) {
            return ['status' => 200, 'body' => []];
        }
        return ['status' => 200, 'body' => $messages];
    }
    if ($channelId === '1186976565909794906' && $contentHiddenMessages !== []) {
        return ['status' => 200, 'body' => $contentHiddenMessages];
    }
    if (isset($genericMessages[$channelId])) {
        return ['status' => 200, 'body' => $genericMessages[$channelId]];
    }
    return ['status' => 200, 'body' => []];
};
$sleeper = static function (float $seconds) use (&$sleeps): void {
    $sleeps[] = $seconds;
};
$academyDiscord = $config->discord;
$academyDiscord['guildId'] = '1186734786388176916';
$academyConfig = new \AlphaVault\Config(
    root: $config->root,
    appUrl: $config->appUrl,
    origin: $config->origin,
    isProd: $config->isProd,
    secureCookies: $config->secureCookies,
    sessionSecret: $config->sessionSecret,
    sessionCookie: $config->sessionCookie,
    oauthCookie: $config->oauthCookie,
    demoCookie: $config->demoCookie,
    trustProxy: $config->trustProxy,
    sessionDays: $config->sessionDays,
    maxStateBytes: $config->maxStateBytes,
    allowDemoLogin: $config->allowDemoLogin,
    demoStateFile: $config->demoStateFile,
    db: $config->db,
    discord: $academyDiscord,
    discordConfigured: $config->discordConfigured,
    roleCheckConfigured: $config->roleCheckConfigured,
    roleRecheckConfigured: $config->roleRecheckConfigured,
    eventsConfigured: $config->eventsConfigured,
    adminDiscordIds: $config->adminDiscordIds,
    discordEventRules: $config->discordEventRules,
    wearables: $config->wearables,
    integrationsKey: $config->integrationsKey,
    integrationsConfigured: $config->integrationsConfigured,
    metaApiToken: $config->metaApiToken,
    integrationBases: $config->integrationBases,
    quotesBase: $config->quotesBase,
    quotesTtl: $config->quotesTtl,
    quotesCacheFile: $config->quotesCacheFile,
    academyRecapWindowMinutes: $config->academyRecapWindowMinutes,
    mcp: $config->mcp,
    version: $config->version,
);
$materials = new AcademyMaterials($academyConfig, $db, $requester, $sleeper, 30);

try {
    $expectedIds = [
        '1499560912611446784',
        '1499560977849384991',
        '1186973732925874217',
        '1186976565909794906',
        '1186978545902309456',
        '1186979958187368468',
        '1499561028646600878',
        '1500905675960750151',
        '1501626207207292968',
        '1542258755390152794',
        '1548026950961074216',
        '1499558305888997568',
        '1566742784596250624',
    ];
    academyCheck(count($channels) === 13 && $channelIds === $expectedIds, 'allowlista zawiera dokładnie 13 zatwierdzonych ID jako stringi');
    academyCheck(
        count(array_filter($channelIds, static fn (mixed $id): bool => !is_string($id))) === 0
            && AcademyMaterials::isAllowedChannel('1499560912611446784')
            && !AcademyMaterials::isAllowedChannel('1499560912611446785'),
        'ID kanałów nie są konwertowane na liczby i spoza allowlisty są odrzucane'
    );
    academyCheck(
        AcademyMaterials::approvedCategories() === [
            'Forex', 'Krypto', 'Nagrania', 'Narzędzia', 'Mental', 'Dodatkowe', 'Trade Recaps', 'Poranne odprawy',
        ],
        'kategorie pochodzą wyłącznie z allowlisty'
    );
    academyCheck(
        AcademyMaterials::supportsMethod('GET')
            && AcademyMaterials::supportsMethod('get')
            && !AcademyMaterials::supportsMethod('POST')
            && !AcademyMaterials::supportsMethod('PUT')
            && !AcademyMaterials::supportsMethod('DELETE'),
        'endpoint synchronizacji wspiera wyłącznie GET'
    );

    $fullUser = ['isDemo' => false, 'roleVerified' => true, 'plan' => 'full', 'accessUntil' => null];
    academyCheck(AcademyMaterials::hasAcademyEntitlement($fullUser, 1_800_000_000), 'aktywny plan full z potwierdzoną rolą ma entitlement');
    academyCheck(
        !AcademyMaterials::hasAcademyEntitlement([...$fullUser, 'plan' => 'solo'], 1_800_000_000)
            && !AcademyMaterials::hasAcademyEntitlement([...$fullUser, 'isDemo' => true], 1_800_000_000)
            && !AcademyMaterials::hasAcademyEntitlement([...$fullUser, 'roleVerified' => false], 1_800_000_000),
        'solo, demo i niezweryfikowana rola nie mają entitlementu'
    );
    academyCheck(
        AcademyMaterials::hasAcademyEntitlement([...$fullUser, 'accessUntil' => '2027-03-01'], 1_800_000_000)
            && !AcademyMaterials::hasAcademyEntitlement([...$fullUser, 'accessUntil' => '2020-01-01'], 1_800_000_000),
        'accessUntil jest sprawdzany względem czasu UTC'
    );

    $normalizedSource = AcademyMaterials::normalizeMessage($sourceOne, $academyChannel);
    $normalizedCaption = AcademyMaterials::normalizeMessage($caption, $academyChannel);
    academyCheck(
        is_array($normalizedSource)
            && $normalizedSource['material_type'] === 'video'
            && $normalizedSource['title'] === 'Lekcja o wybiciu'
            && is_array($normalizedCaption)
            && $normalizedCaption['description'] === 'Academyneedle — opis materiału i kontekst dla uczestników.'
            && $normalizedCaption['is_caption_candidate'] === 1,
        'normalizacja zachowuje materiał medialny i edukacyjny tekst bez surowego payloadu'
    );
    academyCheck(
        !str_contains(json_encode([$normalizedSource, $normalizedCaption], JSON_THROW_ON_ERROR), 'discordapp.com')
            && !str_contains(json_encode([$normalizedSource, $normalizedCaption], JSON_THROW_ON_ERROR), 'private-fixture-marker'),
        'normalizacja nie zapisuje URL-i CDN ani załączników'
    );
    academyCheck(
        AcademyMaterials::normalizeMessage(
            academyMessage('600000000000000010', $academyChannel, '2026-11-24T10:00:00Z', 'Treść edukacyjna bez mediów.'),
            $academyChannel
        )['description'] === 'Treść edukacyjna bez mediów.',
        'krótki tekst edukacyjny bez mediów nie jest odrzucany'
    );
    academyCheck(
        AcademyMaterials::normalizeMessage(
            academyMessage('600000000000000011', $academyChannel, '2026-11-24T10:00:00Z', 'system', '333333333333333333', ['type' => 6]),
            $academyChannel
        ) === null,
        'wiadomości systemowe są filtrowane'
    );
    $invalidSourceRejected = false;
    try {
        AcademyMaterials::normalizeMessage($sourceOne, '999999999999999999');
    } catch (InvalidArgumentException) {
        $invalidSourceRejected = true;
    }
    academyCheck($invalidSourceRejected, 'normalizator odrzuca kanał spoza allowlisty');

    $normalizedSourceTwo = AcademyMaterials::normalizeMessage($sourceTwo, $academyChannel);
    $grouped = AcademyMaterials::groupMessages([$normalizedCaption ?? [], $normalizedSourceTwo ?? [], $normalizedSource ?? []]);
    academyCheck(
        count($grouped) === 2
            && ($grouped[0]['sourceMessageId'] ?? null) === $sourceTwoId
            && ($grouped[1]['sourceMessageId'] ?? null) === $sourceOneId
            && ($grouped[1]['descriptionMessageId'] ?? null) === $captionId,
        'grupowanie referencji jest deterministyczne niezależnie od kolejności/page boundary'
    );
    $fallbackMedia = [
        'message_id' => '600000000000000020',
        'channel_id' => $academyChannel,
        'created_at_utc' => '2026-11-24 09:00:00',
        'author_id' => '333333333333333333',
        'has_media' => 1,
        'material_type' => 'video',
    ];
    $fallbackCaption = [
        'message_id' => '600000000000000021',
        'channel_id' => $academyChannel,
        'created_at_utc' => '2026-11-24 09:29:00',
        'author_id' => '333333333333333333',
        'has_media' => 0,
        'is_caption_candidate' => 1,
        'description' => 'Fallback caption',
    ];
    $lateCaption = [...$fallbackCaption, 'message_id' => '600000000000000022', 'created_at_utc' => '2026-11-24 09:31:00'];
    $nextWarsawDayCaption = [...$fallbackCaption, 'message_id' => '600000000000000023', 'created_at_utc' => '2026-11-24 23:05:00'];
    academyCheck(
        count(AcademyMaterials::groupMessages([$fallbackMedia, $fallbackCaption], 30)) === 1
            && count(AcademyMaterials::groupMessages([$fallbackMedia, $lateCaption], 30)) === 2
            && count(AcademyMaterials::groupMessages([
                [...$fallbackMedia, 'created_at_utc' => '2026-11-24 22:55:00'],
                $nextWarsawDayCaption,
            ], 30)) === 2,
        'fallback respektuje 30 minut, a para nigdy nie przekracza dnia Europe/Warsaw'
    );
    $warsawRangeMethod = new ReflectionMethod(AcademyMaterials::class, 'localDayUtcRange');
    $warsawRange = $warsawRangeMethod->invoke(null, '2026-10-25 12:00:00');
    academyCheck(
        $warsawRange === ['2026-10-24 22:00:00', '2026-10-25 23:00:00'],
        'granice dnia Warsaw poprawnie obejmują 25-godzinny dzień zmiany DST'
    );
    $textRoot = [
        'message_id' => '600000000000000030',
        'channel_id' => $academyChannel,
        'created_at_utc' => '2026-11-24 09:00:00',
        'author_id' => '333333333333333333',
        'has_media' => 0,
    ];
    $referencedText = [...$fallbackCaption, 'message_id' => '600000000000000031', 'reference_message_id' => $textRoot['message_id']];
    academyCheck(
        count(AcademyMaterials::groupMessages([$textRoot, $referencedText])) === 2,
        'jawna referencja nie łączy dwóch wiadomości tekstowych bez źródła medialnego'
    );

    $blankProbe = [];
    for ($index = 0; $index < 5; $index++) {
        $blankProbe[] = academyMessage((string) (500000000000000010 + $index), $emptyChannel, '2026-11-24T10:00:00Z', '');
    }
    academyCheck(
        AcademyMaterials::assessMessageContentAccess([], 'unverified') === 'unverified'
            && AcademyMaterials::assessMessageContentAccess([$blankProbe[0]], 'unverified') === 'unverified'
            && AcademyMaterials::assessMessageContentAccess($blankProbe, 'unverified') === 'suspected_missing'
            && AcademyMaterials::assessMessageContentAccess([$caption], 'unverified') === 'verified'
            && AcademyMaterials::assessMessageContentAccess([$blankProbe[0]], 'verified', 1) === 'suspected_missing',
        'pusta historia nie oznacza gotowości; brak uprzywilejowanej treści ma jawny status'
    );

    $rateLimitOnce = true;
    $firstSyncRequestOffset = count($requests);
    $syncResult = $materials->sync();
    $firstSyncRequests = array_slice($requests, $firstSyncRequestOffset);
    academyCheck(
        ($syncResult['status'] ?? '') === 'backfill',
        'częściowy, poprawny przebieg pełnego backfillu nie jest raportowany jako błąd dostępu'
    );
    academyCheck(
        $rateLimitUsed && count($sleeps) > 0 && abs($sleeps[0] - 0.5) < 0.001,
        '429 respektuje Retry-After przed ponowieniem GET'
    );
    $verifiedMetadata = [];
    $messagesFollowVerification = true;
    $onlyAllowlistedChannelPaths = true;
    foreach ($firstSyncRequests as $request) {
        if (preg_match('#^/channels/([0-9]+)$#D', $request['path'], $metadataMatch) === 1) {
            $verifiedMetadata[$metadataMatch[1]] = true;
            if (!AcademyMaterials::isAllowedChannel($metadataMatch[1])) {
                $onlyAllowlistedChannelPaths = false;
            }
            continue;
        }
        if (preg_match('#^/channels/([0-9]+)/messages$#D', $request['path'], $messagesMatch) === 1) {
            $channelId = $messagesMatch[1];
            if (!AcademyMaterials::isAllowedChannel($channelId)) {
                $onlyAllowlistedChannelPaths = false;
            }
            if (!isset($verifiedMetadata[$channelId])) {
                $messagesFollowVerification = false;
            }
            continue;
        }
        $onlyAllowlistedChannelPaths = false;
    }
    academyCheck(
        count(array_filter($requests, static fn (array $request): bool => $request['method'] !== 'GET')) === 0
            && $onlyAllowlistedChannelPaths
            && $messagesFollowVerification,
        'transport wykonuje wyłącznie GET, weryfikuje źródło przed historią i nie wychodzi poza allowlistę'
    );

    $checkpointObserved = false;
    $checkpointPersistedWithPage = false;
    for ($iteration = 0; $iteration < 20; $iteration++) {
        $pageState = $db->queryOne(
            'SELECT backfill_before_message_id, backfill_complete FROM academy_material_channel_state WHERE channel_id = ?',
            [$pagedChannel]
        );
        if (($pageState['backfill_before_message_id'] ?? null) === $pagedIds[0]
            && (int) $pageState['backfill_complete'] === 0) {
            $checkpointObserved = true;
            $stored = $db->queryOne(
                'SELECT COUNT(*) AS c FROM academy_material_messages WHERE channel_id = ? AND is_deleted = 0',
                [$pagedChannel]
            );
            $checkpointPersistedWithPage = (int) ($stored['c'] ?? 0) === 100;
        }
        if (academyAllBackfillsComplete($db, $channelIds)) {
            break;
        }
        $syncResult = $materials->sync();
    }
    $pagedState = $db->queryOne(
        'SELECT backfill_before_message_id, backfill_complete FROM academy_material_channel_state WHERE channel_id = ?',
        [$pagedChannel]
    );
    academyCheck($checkpointObserved && $checkpointPersistedWithPage, 'checkpoint backfillu przesuwa się dopiero razem z trwałym zapisem strony 100');
    academyCheck(
        (int) ($pagedState['backfill_complete'] ?? 0) === 1
            && ($pagedState['backfill_before_message_id'] ?? null) === null
            && count(array_filter($requests, static fn (array $request): bool =>
                $request['path'] === '/channels/' . $pagedChannel . '/messages'
                && (string) ($request['query']['before'] ?? '') === $pagedIds[0]
            )) >= 1,
        'granica strony 100 pobiera starszą stronę po snowflake checkpoint'
    );
    academyCheck(academyAllBackfillsComplete($db, $channelIds), 'backfill obejmuje wszystkie 13 źródeł');
    $additionalChannelsVerified = $db->query(
        'SELECT channel_id, verified FROM academy_material_channel_state WHERE channel_id IN (?, ?)',
        ['1499558305888997568', '1566742784596250624']
    );
    academyCheck(
        count($additionalChannelsVerified) === 2
            && array_reduce(
                $additionalChannelsVerified,
                static fn (bool $allVerified, array $row): bool => $allVerified && (int) $row['verified'] === 1,
                true
            ),
        'dwa zatwierdzone źródła dodatkowe nie wymagają parent category'
    );

    $failMetadataChannels = [
        '1186973732925874217',
        '1186976565909794906',
        '1186978545902309456',
        '1186979958187368468',
    ];
    $failMetadataChannelsStatus = 503;
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0');
    $starvationRequestOffset = count($requests);
    $materials->sync();
    $starvationRequests = array_slice($requests, $starvationRequestOffset);
    academyCheck(
        count(array_filter($starvationRequests, static fn (array $request): bool =>
            $request['path'] === '/channels/1499558305888997568/messages'
        )) === 1,
        'błędy metadanych w pierwszych pozycjach kolejki nie zagładzają historii pozostałych kanałów'
    );
    $failMetadataChannels = [];
    $failMetadataChannelsStatus = null;
    $materials->sync();

    $emptyState = $db->queryOne(
        'SELECT message_access_status, content_access_status, backfill_complete
           FROM academy_material_channel_state WHERE channel_id = ?',
        [$emptyChannel]
    );
    $blankState = $db->queryOne(
        'SELECT message_access_status, content_access_status, content_access_error_code, backfill_complete
           FROM academy_material_channel_state WHERE channel_id = ?',
        [$contentHiddenChannel]
    );
    $stateView = $materials->syncState();
    academyCheck(
        ($emptyState['message_access_status'] ?? null) === 'verified'
            && ($emptyState['content_access_status'] ?? null) === 'unverified'
            && (int) ($emptyState['backfill_complete'] ?? 0) === 1
            && empty($stateView['ready'])
            && ($stateView['status'] ?? '') === 'content_unverified',
        'HTTP 200 z pustą historią nie przedstawia pozornego sukcesu ani ready'
    );
    academyCheck(
        ($blankState['content_access_status'] ?? null) === 'suspected_missing'
            && ($blankState['content_access_error_code'] ?? null) === 'message_content_access_missing',
        'pola treści puste przy wiadomościach użytkowników sygnalizują brak dostępu Message Content'
    );

    $firstPage = $materials->listForApi('all', '', 25);
    $allItems = $firstPage['items'];
    $cursor = $firstPage['nextCursor'];
    $cursorPages = 1;
    while (is_string($cursor) && $cursor !== '' && $cursorPages < 12) {
        $nextPage = $materials->listForApi('all', '', 25, $cursor);
        $allItems = [...$allItems, ...$nextPage['items']];
        $cursor = $nextPage['nextCursor'];
        $cursorPages++;
    }
    $itemKeys = array_map(static fn (array $item): string => $item['channelId'] . ':' . $item['sourceMessageId'], $allItems);
    academyCheck(
        count($allItems) === count(array_unique($itemKeys))
            && count($allItems) === 113
            && $cursor === null
            && $cursorPages > 1,
        'API odczyt używa stabilnego keyset pagination bez duplikatów'
    );
    $needlePage = $materials->listForApi('Forex', 'Academyneedle', 10);
    academyCheck(
        count($needlePage['items']) === 1
            && $needlePage['items'][0]['sourceMessageId'] === $sourceOneId
            && $needlePage['items'][0]['descriptionMessageId'] === $captionId,
        'filtry kategorii i wyszukiwanie obejmują wyłącznie opis/tytuł'
    );
    $canonical = $needlePage['items'][0]['discordUrl'] ?? '';
    academyCheck(
        $canonical === 'https://discord.com/channels/1186734786388176916/' . $academyChannel . '/' . $sourceOneId
            && is_string($needlePage['items'][0]['guildId'] ?? null)
            && is_string($needlePage['items'][0]['channelId'] ?? null)
            && is_string($needlePage['items'][0]['sourceMessageId'] ?? null)
            && ($needlePage['items'][0]['publishedAt'] ?? null) === '2026-11-24T09:00:00Z',
        'odpowiedź zwraca string IDs, oryginalne ISO UTC i wyłącznie kanoniczny URL źródła'
    );
    $storedIso = $db->queryOne(
        'SELECT m.created_at_iso, i.published_at_iso
           FROM academy_material_messages m
           JOIN academy_material_items i
             ON i.channel_id = m.channel_id AND i.source_message_id = m.message_id
          WHERE m.channel_id = ? AND m.message_id = ?',
        [$academyChannel, $sourceOneId]
    );
    academyCheck(
        ($storedIso['created_at_iso'] ?? null) === '2026-11-24T09:00:00Z'
            && ($storedIso['published_at_iso'] ?? null) === '2026-11-24T09:00:00Z',
        'baza przechowuje UTC DATETIME oraz oryginalny zapis ISO 8601'
    );
    $serializedItems = json_encode($allItems, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    academyCheck(
        !str_contains($serializedItems, 'cdn.discordapp.com')
            && !str_contains($serializedItems, 'media.discordapp.net')
            && !str_contains($serializedItems, 'private-fixture-marker'),
        'odpowiedź API nie ujawnia URL-i CDN/załączników ani surowego payloadu'
    );
    $badCategory = false;
    try {
        $materials->listForApi('not-allowed', '', 10);
    } catch (HttpException $error) {
        $badCategory = $error->status === 400 && $error->errorCode === 'invalid_category';
    }
    $badCursor = false;
    try {
        $materials->listForApi('all', '', 25, (string) $firstPage['nextCursor'] . 'tamper');
    } catch (HttpException $error) {
        $badCursor = $error->status === 400 && $error->errorCode === 'invalid_cursor';
    }
    academyCheck($badCategory && $badCursor, 'niezatwierdzona kategoria i zmieniony cursor są odrzucane');

    $countBeforeIdempotency = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$academyChannel]
    )['c'] ?? 0);
    $db->execute(
        'UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?',
        [$academyChannel]
    );
    $materials->sync();
    $countAfterIdempotency = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$academyChannel]
    )['c'] ?? 0);
    academyCheck($countBeforeIdempotency === 2 && $countAfterIdempotency === 2, 'powtórny incremental sync jest idempotentny');

    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$pagedChannel]);
    $materials->sync();
    $incrementalRow = $db->queryOne(
        'SELECT message_id FROM academy_material_messages WHERE channel_id = ? AND message_id = ? AND is_deleted = 0',
        [$pagedChannel, $pagedNewId]
    );
    $pagedCountAfterIncremental = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$pagedChannel]
    )['c'] ?? 0);
    academyCheck(
        ($incrementalRow['message_id'] ?? null) === $pagedNewId && $pagedCountAfterIncremental === 102,
        'incremental sync używa after snowflake i zapisuje nowe rekordy'
    );
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$pagedChannel]);
    $materials->sync();
    $pagedCountAfterRepeat = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$pagedChannel]
    )['c'] ?? 0);
    academyCheck($pagedCountAfterRepeat === 102, 'powtórny incremental nie duplikuje nowego rekordu');

    $editedCaption = [...$caption, 'content' => 'Academyneedle — edytowany opis przeniesiony do drugiego źródła.'];
    $editedCaption['message_reference']['message_id'] = $sourceTwoId;
    $editedCaption['edited_timestamp'] = '2026-11-26T09:07:00.000Z';
    $auditDatasetA = [$sourceOne, $sourceTwo, $editedCaption];
    academySetAudit($db, $academyChannel);
    $materials->sync();
    for ($iteration = 0; $iteration < 3; $iteration++) {
        $auditState = $db->queryOne(
            'SELECT audit_cleanup_pending FROM academy_material_channel_state WHERE channel_id = ?',
            [$academyChannel]
        );
        if ((int) ($auditState['audit_cleanup_pending'] ?? 0) === 0) {
            break;
        }
        $materials->sync();
    }
    $editedItems = $db->query(
        'SELECT source_message_id, description_message_id, description, is_deleted
           FROM academy_material_items WHERE channel_id = ? ORDER BY source_message_id',
        [$academyChannel]
    );
    $editedById = [];
    foreach ($editedItems as $item) {
        $editedById[(string) $item['source_message_id']] = $item;
    }
    academyCheck(
        ($editedById[$sourceTwoId]['description'] ?? null) === 'Academyneedle — edytowany opis przeniesiony do drugiego źródła.'
            && ($editedById[$sourceTwoId]['description_message_id'] ?? null) === $captionId
            && ($editedById[$sourceOneId]['description'] ?? null) === null,
        'audit wykrywa edycję opisu i deterministycznie reassocjuje referencję'
    );

    $auditDatasetA = [$sourceOne, $editedCaption];
    academySetAudit($db, $academyChannel);
    $materials->sync();
    for ($iteration = 0; $iteration < 4; $iteration++) {
        $auditState = $db->queryOne(
            'SELECT audit_cleanup_pending FROM academy_material_channel_state WHERE channel_id = ?',
            [$academyChannel]
        );
        if ((int) ($auditState['audit_cleanup_pending'] ?? 0) === 0) {
            break;
        }
        $materials->sync();
    }
    $deletedSource = $db->queryOne(
        'SELECT is_deleted FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
        [$academyChannel, $sourceTwoId]
    );
    $deletedItem = $db->queryOne(
        'SELECT is_deleted FROM academy_material_items WHERE channel_id = ? AND source_message_id = ?',
        [$academyChannel, $sourceTwoId]
    );
    $reassociatedCaption = $db->queryOne(
        'SELECT target_source_message_id, is_deleted FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
        [$academyChannel, $captionId]
    );
    $restoredRoot = $db->queryOne(
        'SELECT description_message_id, description FROM academy_material_items WHERE channel_id = ? AND source_message_id = ?',
        [$academyChannel, $sourceOneId]
    );
    academyCheck(
        (int) ($deletedSource['is_deleted'] ?? 0) === 1
            && (int) ($deletedItem['is_deleted'] ?? 0) === 1
            && ($reassociatedCaption['target_source_message_id'] ?? null) === $sourceOneId
            && ($restoredRoot['description_message_id'] ?? null) === $captionId
            && str_contains((string) ($restoredRoot['description'] ?? ''), 'edytowany opis'),
        'audyt miękko usuwa brakujące źródło i przepina caption do pozostałego materiału'
    );

    $failMessageChannel = $permissionChannel;
    $failMessageStatus = 503;
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$permissionChannel]);
    $transientResult = $materials->sync();
    $transientState = $materials->syncState();
    $transientChannel = array_values(array_filter(
        $transientState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $permissionChannel
    ))[0] ?? [];
    $transientItem = $db->queryOne(
        'SELECT is_deleted FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0 LIMIT 1',
        [$permissionChannel]
    );
    academyCheck(
        ($transientResult['status'] ?? '') === 'partial'
            && ($transientChannel['available'] ?? false) === true
            && $transientItem !== null,
        'przejściowy błąd 5xx izoluje kanał, zachowując ostatni dobry zestaw'
    );

    $failMessageStatus = 403;
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$permissionChannel]);
    $materials->sync();
    $deniedState = $materials->syncState();
    $deniedChannel = array_values(array_filter(
        $deniedState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $permissionChannel
    ))[0] ?? [];
    academyCheck(
        ($deniedChannel['messageAccessStatus'] ?? '') === 'unavailable'
            && ($deniedChannel['messageAccessErrorCode'] ?? '') === 'http_403'
            && ($deniedChannel['available'] ?? true) === false
            && (int) ($deniedChannel['importedCount'] ?? -1) === 0,
        'utrata Read Message History ukrywa dane i pokazuje jawny status zamiast pustego sukcesu'
    );

    $failMessageStatus = null;
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$permissionChannel]);
    $materials->sync();
    $recoveredState = $materials->syncState();
    $recoveredChannel = array_values(array_filter(
        $recoveredState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $permissionChannel
    ))[0] ?? [];
    academyCheck(($recoveredChannel['available'] ?? false) === true, 'udany GET po przywróceniu dostępu przywraca zweryfikowany kanał');

    $failMetadataChannel = $permissionChannel;
    $failMetadataStatus = 503;
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$permissionChannel]);
    $metadataRequestOffset = count($requests);
    $metadataTransientResult = $materials->sync();
    $metadataTransientState = $materials->syncState();
    $metadataTransientChannel = array_values(array_filter(
        $metadataTransientState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $permissionChannel
    ))[0] ?? [];
    $metadataTransientRequests = array_slice($requests, $metadataRequestOffset);
    academyCheck(
        ($metadataTransientResult['status'] ?? '') === 'partial'
            && ($metadataTransientChannel['available'] ?? false) === true
            && (int) ($metadataTransientChannel['importedCount'] ?? 0) === 1
            && count(array_filter($metadataTransientRequests, static fn (array $request): bool =>
                $request['path'] === '/channels/' . $permissionChannel . '/messages'
            )) === 0,
        'przejściowy błąd metadanych zachowuje ostatni dobry zestaw i nie pobiera historii bez świeżej weryfikacji'
    );
    $failMetadataChannel = null;
    $failMetadataStatus = null;

    $badMetadataChannel = $categoryFailureChannel;
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$categoryFailureChannel]);
    $materials->sync();
    $mismatchState = $materials->syncState();
    $mismatchChannel = array_values(array_filter(
        $mismatchState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $categoryFailureChannel
    ))[0] ?? [];
    academyCheck(
        ($mismatchChannel['verified'] ?? true) === false
            && ($mismatchChannel['lastErrorCode'] ?? '') === 'category_mismatch'
            && ($mismatchChannel['available'] ?? true) === false,
        'zmiana parent category unieważnia konkretny kanał i jego widoczność'
    );
    $badMetadataChannel = null;
    $db->execute('UPDATE academy_material_channel_state SET work_order = 0 WHERE channel_id = ?', [$categoryFailureChannel]);
    $materials->sync();

    $db->execute('DELETE FROM academy_material_items');
    $db->execute('DELETE FROM academy_material_messages');
    academyCheck(
        (int) ($db->queryOne('SELECT COUNT(*) AS c FROM academy_material_items')['c'] ?? -1) === 0,
        'cleanup testów usuwa wyłącznie dane fixture Akademii'
    );
} catch (Throwable $error) {
    academyCheck(false, 'wykonanie fixture testu zakończyło się nieoczekiwanym wyjątkiem (' . get_class($error) . ')');
} finally {
    $db->execute('DELETE FROM academy_material_items');
    $db->execute('DELETE FROM academy_material_messages');
    $db->execute('DELETE FROM academy_material_channel_state');
    $db->execute('DELETE FROM academy_material_sync_state');
}

if ($GLOBALS['academy_failed']) {
    exit(1);
}
echo "Academy materials tests: PASS\n";
