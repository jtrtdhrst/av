<?php
declare(strict_types=1);

use AlphaVault\AcademyMaterials;
use AlphaVault\HttpException;

$root = dirname(__DIR__);
require_once __DIR__ . '/mcp-test-environment.php';
mcpLoadIsolatedCiConfig($root);
[$config, $db] = require $root . '/app/bootstrap.php';

function academyCheck(bool $condition, string $label, mixed $diagnostic = null): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['academy_failed'] = true;
        if ($diagnostic !== null) {
            $encodedDiagnostic = json_encode(
                $diagnostic,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            echo '      DIAG ' . ($encodedDiagnostic === false ? '[unavailable]' : $encodedDiagnostic) . "\n";
        }
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
$recapChannel = '1499558305888997568';
$morningChannel = '1566742784596250624';
$pagedChannel = $recapChannel;
$emptyChannel = '1186973732925874217';
$contentHiddenChannel = '1186976565909794906';
$permissionChannel = '1186978545902309456';
$categoryFailureChannel = '1186979958187368468';
$sourceOneId = '600000000000000001';
$sourceTwoId = '600000000000000002';
$captionId = '600000000000000003';
$marcinId = '333333333333333333';
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
                        '2026-11-22T09:59:00Z',
                        'Opisuje strefę NYC i ryzyko przed sesją nowojorską, dołączone do późniejszego nagrania.'
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
            $isPageBoundaryVideo = $id === $pagedIds[0];
            $page[] = academyMessage(
                $id,
                $pagedChannel,
                $isPageBoundaryVideo ? '2026-11-22T10:00:00Z' : '2026-11-22T10:01:00Z',
                $isPageBoundaryVideo ? 'Nagranie sesji NYC' : 'Paged material fixture ' . $index,
                '333333333333333333',
                $isPageBoundaryVideo ? [
                    'attachments' => [[
                        'id' => '899999999999999999',
                        'filename' => 'nyc-session.mp4',
                        'content_type' => 'video/mp4',
                    ]],
                ] : []
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
$syncSelectedChannel = static function (string $channelId) use ($db, $materials): array {
    $db->execute(
        'UPDATE academy_material_channel_state
            SET work_order = CASE WHEN channel_id = ? THEN 0 ELSE 1000000 END',
        [$channelId]
    );
    return $materials->sync();
};

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
$cdnEmojiMessage = AcademyMaterials::normalizeMessage(
    academyMessage(
        '600000000000000034',
        $academyChannel,
        '2026-11-24T09:00:00Z',
        'Emoji https://cdn.discordapp.com/emojis/123456789012345678.png and preview https://images-ext-1.discordapp.net/external/1234567890/example.png?width=64'
    ),
    $academyChannel
);
academyCheck(
        is_array($normalizedSource)
            && $normalizedSource['material_type'] === 'video'
            && $normalizedSource['title'] === 'Lekcja o wybiciu'
            && is_array($normalizedCaption)
            && $normalizedCaption['title'] === 'Academyneedle — opis materiału i kontekst dla uczestników.'
            && $normalizedCaption['description'] === null
            && $normalizedCaption['is_caption_candidate'] === 1,
        'normalizacja rozdziela tytuł od opisu i zachowuje materiał bez surowego payloadu'
    );
academyCheck(
    !str_contains(json_encode([$normalizedSource, $normalizedCaption, $cdnEmojiMessage], JSON_THROW_ON_ERROR), 'discordapp.com')
        && !str_contains(json_encode([$normalizedSource, $normalizedCaption, $cdnEmojiMessage], JSON_THROW_ON_ERROR), 'discordapp.net')
        && !str_contains(json_encode([$normalizedSource, $normalizedCaption, $cdnEmojiMessage], JSON_THROW_ON_ERROR), 'private-fixture-marker'),
        'normalizacja nie zapisuje URL-i CDN ani załączników'
    );
    $educationOnly = AcademyMaterials::normalizeMessage(
        academyMessage('600000000000000010', $academyChannel, '2026-11-24T10:00:00Z', 'Treść edukacyjna bez mediów.'),
        $academyChannel
    );
    academyCheck(
        is_array($educationOnly)
            && $educationOnly['title'] === 'Treść edukacyjna bez mediów.'
            && $educationOnly['description'] === null,
        'krótki tekst edukacyjny bez mediów jest zachowany jako tytuł, bez duplikowania go w opisie'
    );
    $educationalLink = AcademyMaterials::normalizeMessage(
        academyMessage(
            '600000000000000012',
            $academyChannel,
            '2026-11-24T10:00:00Z',
            'Raport o zarządzaniu ryzykiem: https://example.org/academy/risk'
        ),
        $academyChannel
    );
    academyCheck(
        is_array($educationalLink)
            && str_contains((string) $educationalLink['content_text'], 'https://example.org/academy/risk')
            && str_contains((string) $educationalLink['title'], 'https://example.org/academy/risk'),
        'edukacyjne adresy URL pozostają zwykłym, przeszukiwalnym tekstem'
    );
    $bodyAndEmbed = AcademyMaterials::normalizeMessage(
        academyMessage(
            '600000000000000015',
            $academyChannel,
            '2026-11-24T10:00:00Z',
            "Pierwsza linia wiadomości\nDruga linia opisu.",
            '333333333333333333',
            ['embeds' => [[
                'title' => 'Tytuł karty',
                'description' => 'Opis karty.',
            ]]]
        ),
        $academyChannel
    );
    academyCheck(
        is_array($bodyAndEmbed)
            && $bodyAndEmbed['title'] === 'Pierwsza linia wiadomości'
            && str_contains((string) $bodyAndEmbed['description'], 'Druga linia opisu.')
            && str_contains((string) $bodyAndEmbed['description'], 'Tytuł karty')
            && str_contains((string) $bodyAndEmbed['description'], 'Opis karty.'),
        'pierwsza linia wiadomości pozostaje tytułem, a treść osadzenia zostaje w opisie'
    );
    $filenameFallback = AcademyMaterials::normalizeMessage(
        academyMessage(
            '600000000000000013',
            $academyChannel,
            '2026-11-24T10:00:00Z',
            '',
            '333333333333333333',
            ['attachments' => [[
                'id' => '800000000000000013',
                'filename' => '../../bootcamp-forex.mp4',
                'content_type' => 'video/mp4',
                'url' => 'https://cdn.discordapp.com/attachments/filename-fixture',
            ]]]
        ),
        $academyChannel
    );
    $neutralFallback = AcademyMaterials::normalizeMessage(
        academyMessage(
            '600000000000000014',
            $academyChannel,
            '2026-11-24T10:00:00Z',
            '',
            '333333333333333333',
            ['attachments' => [[
                'id' => '800000000000000014',
                'filename' => '',
                'content_type' => 'video/mp4',
                'url' => 'https://cdn.discordapp.com/attachments/neutral-fixture',
            ]]]
        ),
        $academyChannel
    );
    academyCheck(
        is_array($filenameFallback)
            && $filenameFallback['title'] === 'bootcamp-forex.mp4'
            && $filenameFallback['attachment_filename'] === 'bootcamp-forex.mp4'
            && $filenameFallback['material_type'] === 'video'
            && is_array($neutralFallback)
            && $neutralFallback['title'] === 'Nagranie wideo · 24.11.2026',
        'brak tekstu używa bezpiecznej nazwy pliku, a potem neutralnego typu i daty'
    );
    academyCheck(
        AcademyMaterials::normalizeMessage(
            academyMessage('600000000000000011', $academyChannel, '2026-11-24T10:00:00Z', 'system', '333333333333333333', ['type' => 6]),
            $academyChannel
        ) === null,
        'wiadomości systemowe są filtrowane'
    );
    academyCheck(
        AcademyMaterials::normalizeMessage(
            academyMessage('600000000000000015', $academyChannel, '2026-11-24T10:00:00Z', '<@&333333333333333333>'),
            $academyChannel
        ) === null
            && AcademyMaterials::normalizeMessage(
                academyMessage('600000000000000016', $academyChannel, '2026-11-24T10:00:00Z', 'Spotkanie odwołane.'),
                $academyChannel
            ) === null,
        'wiadomości zawierające wyłącznie wzmiankę lub administracyjny komunikat nie są materiałami'
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
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-24 09:00:00',
        'author_id' => '333333333333333333',
        'has_media' => 1,
        'material_type' => 'video',
    ];
    $fallbackCaption = [
        'message_id' => '600000000000000021',
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-24 09:29:00',
        'author_id' => '333333333333333333',
        'has_media' => 0,
        'is_caption_candidate' => 1,
        'is_recording_caption' => 1,
        'title' => 'Nagranie sesji NYC',
        'content_text' => 'Nagranie sesji NYC',
    ];
    $lateCaption = [...$fallbackCaption, 'message_id' => '600000000000000022', 'created_at_utc' => '2026-11-24 09:31:00'];
    $nextWarsawDayCaption = [...$fallbackCaption, 'message_id' => '600000000000000023', 'created_at_utc' => '2026-11-24 23:05:00'];
    academyCheck(
        count(AcademyMaterials::groupMessages([$fallbackMedia, $fallbackCaption], 30)) === 1
            && count(AcademyMaterials::groupMessages([$fallbackMedia, $lateCaption], 30)) === 2
            && count(AcademyMaterials::groupMessages([$fallbackMedia, $lateCaption], 240)) === 2
            && count(AcademyMaterials::groupMessages([
                [...$fallbackMedia, 'created_at_utc' => '2026-11-24 22:55:00'],
                $nextWarsawDayCaption,
            ], 30)) === 2,
        'fallback respektuje 30 minut, a para nigdy nie przekracza dnia Europe/Warsaw'
    );
    $differentAuthorInterruption = [
        ...$fallbackCaption,
        'message_id' => '600000000000000024',
        'created_at_utc' => '2026-11-24 09:01:00',
        'author_id' => '444444444444444444',
        'is_caption_candidate' => 0,
        'is_recording_caption' => 0,
        'title' => 'Dzięki za komentarz.',
        'content_text' => 'Dzięki za komentarz.',
    ];
    $afterInterruptionCaption = [
        ...$fallbackCaption,
        'message_id' => '600000000000000025',
        'created_at_utc' => '2026-11-24 09:02:00',
    ];
    $interruptedItems = AcademyMaterials::groupMessages([
        $fallbackMedia,
        $differentAuthorInterruption,
        $afterInterruptionCaption,
    ]);
    academyCheck(
        count($interruptedItems) === 3
            && count(array_filter(
                $interruptedItems,
                static fn (array $item): bool => $item['descriptionMessageId'] !== null
            )) === 0,
        'inna osoba w bezpośrednio poprzedzającej wiadomości blokuje niejednoznaczne grupowanie'
    );
    $otherChannelInterruption = [
        'message_id' => '600000000000000026',
        'channel_id' => $academyChannel,
        'created_at_utc' => '2026-11-24 09:01:00',
        'author_id' => '333333333333333333',
        'has_media' => 0,
        'title' => 'Osobny materiał innego kanału.',
        'content_text' => 'Osobny materiał innego kanału.',
    ];
    $crossChannelItems = AcademyMaterials::groupMessages([
        $fallbackMedia,
        $otherChannelInterruption,
        $fallbackCaption,
    ]);
    $crossChannelRoot = array_values(array_filter(
        $crossChannelItems,
        static fn (array $item): bool => $item['sourceMessageId'] === '600000000000000020'
    ))[0] ?? [];
    academyCheck(
        count($crossChannelItems) === 2
            && ($crossChannelRoot['descriptionMessageId'] ?? null) === '600000000000000021',
        'wiadomość z innego kanału nie przerywa bezpośredniej pary w źródle'
    );
    $documentMessage = AcademyMaterials::normalizeMessage(
        academyMessage(
            '600000000000000027',
            $recapChannel,
            '2026-11-24T09:01:00Z',
            '',
            '333333333333333333',
            ['attachments' => [[
                'id' => '800000000000000027',
                'filename' => 'slownik.pdf',
                'content_type' => 'application/pdf',
                'url' => 'https://cdn.discordapp.com/attachments/document-fixture',
            ]]]
        ),
        $recapChannel
    );
    $documentSequence = AcademyMaterials::groupMessages([
        $fallbackMedia,
        $documentMessage ?? [],
        $fallbackCaption,
    ]);
    academyCheck(
        count($documentSequence) === 3
            && ($documentMessage['material_type'] ?? null) === 'file'
            && ($documentMessage['title'] ?? null) === 'slownik.pdf',
        'dokument PDF pozostaje samodzielnym materiałem i nie przejmuje podpisu nagrania'
    );
    $chartDescription = [
        'message_id' => '600000000000000028',
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-24 10:00:00',
        'author_id' => $marcinId,
        'has_media' => 1,
        'material_type' => 'image',
        'title' => 'Strefa oporu BTC',
        'content_text' => 'Wykres pokazuje ważną strefę oporu przed otwarciem sesji nowojorskiej.',
    ];
    $chartVideo = [
        ...$fallbackCaption,
        'message_id' => '600000000000000029',
        'created_at_utc' => '2026-11-24 10:01:00',
        'has_media' => 1,
        'material_type' => 'video',
        'title' => 'Nagranie sesji BTC',
        'content_text' => 'Nagranie sesji BTC',
        'attachment_filename' => 'nyc-session.mp4',
    ];
    $chartItems = AcademyMaterials::groupMessages([$chartVideo, $chartDescription]);
    $weakChart = [...$chartDescription, 'message_id' => '600000000000000032', 'title' => 'NYC', 'content_text' => 'NYC'];
    $weakChartItems = AcademyMaterials::groupMessages([$chartVideo, $weakChart]);
    academyCheck(
        count($chartItems) === 1
            && ($chartItems[0]['sourceMessageId'] ?? null) === '600000000000000029'
            && ($chartItems[0]['descriptionMessageId'] ?? null) === '600000000000000028'
            && count($weakChartItems) === 2,
        'opisowy wykres może opisać nagranie, ale krótki komentarz do obrazu nie jest scalany'
    );
    $unrelatedProse = [
        'message_id' => '600000000000000033',
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-24 10:00:00',
        'author_id' => $marcinId,
        'has_media' => 0,
        'title' => 'Plan tygodnia',
        'content_text' => 'W przyszłym tygodniu odbędzie się spotkanie organizacyjne dla wszystkich członków zespołu; w notatce wspomniano btc.',
    ];
    academyCheck(
        count(AcademyMaterials::groupMessages([$unrelatedProse, $chartVideo])) === 2,
        'długi niezwiązany tekst nie jest łączony z podpisem nagrania'
    );
    $warsawRangeMethod = new ReflectionMethod(AcademyMaterials::class, 'localDayUtcRange');
    $warsawRange = $warsawRangeMethod->invoke(null, '2026-10-25 12:00:00');
    academyCheck(
        $warsawRange === ['2026-10-24 22:00:00', '2026-10-25 23:00:00'],
        'granice dnia Warsaw poprawnie obejmują 25-godzinny dzień zmiany DST'
    );
    $textRoot = [
        'message_id' => '600000000000000030',
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-24 09:00:00',
        'author_id' => '333333333333333333',
        'has_media' => 0,
    ];
    $referencedText = [...$fallbackCaption, 'message_id' => '600000000000000031', 'reference_message_id' => $textRoot['message_id']];
    academyCheck(
        count(AcademyMaterials::groupMessages([$textRoot, $referencedText])) === 2,
        'jawna referencja nie łączy dwóch wiadomości tekstowych bez źródła medialnego'
    );

    $marcinProfile = [
        'author' => [
            'id' => $marcinId,
            'username' => 'marcin-b',
            'global_name' => 'Marcin B',
            'bot' => false,
        ],
        'member' => ['nick' => 'Marcin B'],
    ];
    $recapDescriptionId = '1554908231938220102';
    $recapVideoId = '1554908231938220103';
    $recapDescription = AcademyMaterials::normalizeMessage(
        academyMessage(
            $recapDescriptionId,
            $recapChannel,
            '2026-09-30T17:29:00Z',
            "Dzis SL na NYC sesji.\n\nRyzykowne zagranie przed Open i szybko poszli po SL",
            $marcinId,
            $marcinProfile
        ),
        $recapChannel
    );
    $recapVideo = AcademyMaterials::normalizeMessage(
        academyMessage(
            $recapVideoId,
            $recapChannel,
            '2026-09-30T17:30:00Z',
            'Nagranie sesji NYC',
            $marcinId,
            [
                ...$marcinProfile,
                'attachments' => [[
                    'id' => '810000000000000001',
                    'filename' => 'sesja-nyc.mp4',
                    'content_type' => 'video/mp4',
                    'url' => 'https://cdn.discordapp.com/attachments/recap-fixture',
                ]],
            ]
        ),
        $recapChannel
    );
    $recapItems = AcademyMaterials::groupMessages([$recapVideo ?? [], $recapDescription ?? []]);
    $recapItem = $recapItems[0] ?? [];
    academyCheck(
        count($recapItems) === 1
            && ($recapItem['sourceMessageId'] ?? null) === $recapVideoId
            && ($recapItem['descriptionMessageId'] ?? null) === $recapDescriptionId
            && ($recapItem['category'] ?? null) === 'Trade Recaps'
            && ($recapItem['title'] ?? null) === 'Dzis SL na NYC sesji.'
            && ($recapItem['description'] ?? null) === 'Ryzykowne zagranie przed Open i szybko poszli po SL'
            && ($recapItem['authorName'] ?? null) === 'Marcin B'
            && ($recapItem['publishedAt'] ?? null) === '2026-09-30T17:30:00Z'
            && ($recapItem['materialType'] ?? null) === 'video'
            && ($recapItem['discordUrl'] ?? null)
                === 'https://discord.com/channels/1186734786388176916/' . $recapChannel . '/' . $recapVideoId,
        'recap 30.09 grupuje opis przed MP4, zachowuje autora i linkuje do źródłowego nagrania'
    );

    $recapWindowCases = [
        [
            'descriptionId' => '610000000000000028',
            'videoId' => '610000000000000128',
            'descriptionAt' => '2026-09-28T13:51:00Z',
            'videoAt' => '2026-09-28T14:00:00Z',
        ],
        [
            'descriptionId' => '610000000000000029',
            'videoId' => '610000000000000129',
            'descriptionAt' => '2026-09-29T13:38:00Z',
            'videoAt' => '2026-09-29T13:41:00Z',
        ],
    ];
    $recapWindowsPass = true;
    foreach ($recapWindowCases as $case) {
        $descriptionMessage = AcademyMaterials::normalizeMessage(
            academyMessage(
                $case['descriptionId'],
                $recapChannel,
                $case['descriptionAt'],
                "Dzis SL na NYC sesji.\n\nRyzykowne zagranie przed Open i szybko poszli po SL",
                $marcinId,
                $marcinProfile
            ),
            $recapChannel
        );
        $videoMessage = AcademyMaterials::normalizeMessage(
            academyMessage(
                $case['videoId'],
                $recapChannel,
                $case['videoAt'],
                'Nagranie sesji NYC',
                $marcinId,
                [
                    ...$marcinProfile,
                    'attachments' => [[
                        'id' => '810000000000000002',
                        'filename' => 'sesja.mp4',
                        'content_type' => 'video/mp4',
                        'url' => 'https://cdn.discordapp.com/attachments/window-fixture',
                    ]],
                ]
            ),
            $recapChannel
        );
        $windowItems = AcademyMaterials::groupMessages([$videoMessage ?? [], $descriptionMessage ?? []], 30);
        $recapWindowsPass = $recapWindowsPass
            && count($windowItems) === 1
            && ($windowItems[0]['sourceMessageId'] ?? null) === $case['videoId']
            && ($windowItems[0]['descriptionMessageId'] ?? null) === $case['descriptionId'];
    }
    academyCheck($recapWindowsPass, 'recapy 28.09 i 29.09 grupują opis z nagraniem w oknie 30 minut');

    $morning24Id = '620000000000000024';
    $morning24 = AcademyMaterials::normalizeMessage(
        academyMessage(
            $morning24Id,
            $morningChannel,
            '2026-11-24T07:55:00Z',
            "Poranna odprawa — 24.11.2026\n\nOmówienie setupów porannych.",
            $marcinId,
            [
                ...$marcinProfile,
                'attachments' => [[
                    'id' => '820000000000000024',
                    'filename' => 'odprawa-24.mp4',
                    'content_type' => 'video/mp4',
                    'url' => 'https://cdn.discordapp.com/attachments/morning-24-fixture',
                ]],
            ]
        ),
        $morningChannel
    );
    $morning26Id = '620000000000000026';
    $morning26 = AcademyMaterials::normalizeMessage(
        academyMessage(
            $morning26Id,
            $morningChannel,
            '2026-11-26T07:57:00Z',
            "Dzisiejsza poranna odprawa !\n\nOmówienie kluczowych setupów i stref",
            $marcinId,
            [
                ...$marcinProfile,
                'attachments' => [[
                    'id' => '820000000000000026',
                    'filename' => 'odprawa-26.mp4',
                    'content_type' => 'video/mp4',
                    'url' => 'https://cdn.discordapp.com/attachments/morning-26-fixture',
                ]],
            ]
        ),
        $morningChannel
    );
    $morningItems = [
        AcademyMaterials::groupMessages([$morning24 ?? []])[0] ?? [],
        AcademyMaterials::groupMessages([$morning26 ?? []])[0] ?? [],
    ];
    academyCheck(
        ($morningItems[0]['category'] ?? null) === 'Poranne odprawy'
            && ($morningItems[0]['publishedAt'] ?? null) === '2026-11-24T07:55:00Z'
            && ($morningItems[1]['title'] ?? null) === 'Dzisiejsza poranna odprawa !'
            && ($morningItems[1]['description'] ?? null) === 'Omówienie kluczowych setupów i stref'
            && ($morningItems[1]['authorName'] ?? null) === 'Marcin B'
            && ($morningItems[1]['publishedAt'] ?? null) === '2026-11-26T07:57:00Z'
            && ($morningItems[1]['materialType'] ?? null) === 'video'
            && ($morningItems[1]['descriptionMessageId'] ?? null) === null,
        'odprawy 24.11 i 26.11 zachowują treść, autora, czas i typ MP4'
    );

    $ambiguousSource = [
        'message_id' => '630000000000000001',
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-24 09:00:00',
        'author_id' => $marcinId,
        'has_media' => 1,
        'material_type' => 'video',
        'title' => 'Nagranie sesji NYC',
    ];
    $ambiguousCaptionA = [
        ...$fallbackCaption,
        'message_id' => '630000000000000002',
        'created_at_utc' => '2026-11-24 09:01:00',
        'reference_message_id' => $ambiguousSource['message_id'],
    ];
    $ambiguousCaptionB = [
        ...$fallbackCaption,
        'message_id' => '630000000000000003',
        'created_at_utc' => '2026-11-24 09:02:00',
        'reference_message_id' => $ambiguousSource['message_id'],
        'content_text' => 'Nagranie sesji London',
        'title' => 'Nagranie sesji London',
    ];
    $ambiguousItems = AcademyMaterials::groupMessages([
        $ambiguousSource,
        $ambiguousCaptionA,
        $ambiguousCaptionB,
    ]);
    academyCheck(
        count($ambiguousItems) === 3
            && count(array_filter(
                $ambiguousItems,
                static fn (array $item): bool => $item['descriptionMessageId'] !== null
            )) === 0,
        'dwie jawne referencje do jednego źródła pozostają rozdzielone przy niejednoznaczności'
    );

    $crossDayTargetId = '630000000000000004';
    $crossDayMediaId = '630000000000000005';
    $crossDayCaptionId = '630000000000000006';
    $crossDayTarget = [
        'message_id' => $crossDayTargetId,
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-23 09:00:00',
        'author_id' => $marcinId,
        'has_media' => 1,
        'material_type' => 'video',
        'title' => 'Nagranie NYC z poprzedniego dnia',
        'content_text' => 'Nagranie NYC z poprzedniego dnia',
    ];
    $crossDayMedia = [
        'message_id' => $crossDayMediaId,
        'channel_id' => $recapChannel,
        'created_at_utc' => '2026-11-24 09:00:00',
        'author_id' => $marcinId,
        'has_media' => 1,
        'material_type' => 'video',
        'title' => 'Sesja NYC',
        'content_text' => 'Sesja NYC',
    ];
    $crossDayCaption = [
        ...$fallbackCaption,
        'message_id' => $crossDayCaptionId,
        'created_at_utc' => '2026-11-24 09:01:00',
        'reference_message_id' => $crossDayTargetId,
    ];
    $crossDayItems = AcademyMaterials::groupMessages([
        $crossDayCaption,
        $crossDayTarget,
        $crossDayMedia,
    ]);
    $crossDaySources = array_column($crossDayItems, 'sourceMessageId');
    sort($crossDaySources, SORT_STRING);
    academyCheck(
        count($crossDayItems) === 3
            && $crossDaySources === [$crossDayTargetId, $crossDayMediaId, $crossDayCaptionId]
            && count(array_filter(
                $crossDayItems,
                static fn (array $item): bool => $item['descriptionMessageId'] !== null
            )) === 0,
        'jawna referencja do poprzedniego dnia nie uruchamia fallbacku do sąsiedniego nagrania',
        ['sources' => $crossDaySources, 'items' => $crossDayItems]
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
    $pageBoundaryItem = $db->queryOne(
        'SELECT source_message_id, description_message_id
           FROM academy_material_items
          WHERE channel_id = ? AND source_message_id = ? AND is_deleted = 0',
        [$pagedChannel, $pagedIds[0]]
    );
    $pageBoundaryDescription = $db->queryOne(
        'SELECT target_source_message_id
           FROM academy_material_messages
          WHERE channel_id = ? AND message_id = ? AND is_deleted = 0',
        [$pagedChannel, $pagedOldId]
    );
    $pageBoundaryItemCount = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$pagedChannel]
    )['c'] ?? 0);
    academyCheck(
        ($pageBoundaryItem['source_message_id'] ?? null) === $pagedIds[0]
            && ($pageBoundaryItem['description_message_id'] ?? null) === $pagedOldId
            && ($pageBoundaryDescription['target_source_message_id'] ?? null) === $pagedIds[0]
            && $pageBoundaryItemCount === 100,
        'opis ze starszej strony zostaje połączony z późniejszym nagraniem po odbudowie dnia'
    );
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
        'HTTP 200 z pustą historią nie przedstawia pozornego sukcesu ani ready',
        ['emptyState' => $emptyState, 'state' => $stateView]
    );
    academyCheck(
        ($blankState['content_access_status'] ?? null) === 'suspected_missing'
            && ($blankState['content_access_error_code'] ?? null) === 'message_content_access_missing',
        'pola treści puste przy wiadomościach użytkowników sygnalizują brak dostępu Message Content'
    );

    $db->execute(
        'UPDATE academy_material_channel_state
            SET grouping_version = 1, last_audit_at = UTC_TIMESTAMP(),
                audit_running = 0, audit_cleanup_pending = 0,
                audit_started_at = NULL, audit_before_message_id = NULL,
                audit_content_verified = 0
          WHERE backfill_complete = 1'
    );
    $staleGroupingPage = $materials->listForApi('all', '', 10);
    academyCheck(
        $staleGroupingPage['items'] === [],
        'API ukrywa dane z indeksu wymagającego pełnego ponownego grupowania'
    );
    $db->execute(
        "UPDATE academy_material_channel_state
            SET grouping_version = 2, last_audit_at = UTC_TIMESTAMP(),
                audit_running = 0, audit_cleanup_pending = 0,
                audit_started_at = NULL, audit_before_message_id = NULL,
                audit_content_verified = 0
          WHERE verified = 1 AND backfill_complete = 1
            AND message_access_status = 'verified'
            AND content_access_status = 'verified'"
    );
    $groupingState = $materials->syncState();
    $staleChannelState = array_values(array_filter(
        $groupingState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $emptyChannel
    ))[0] ?? [];
    academyCheck(
        ($groupingState['ready'] ?? true) === false
            && ($staleChannelState['groupingReady'] ?? true) === false,
        'content-unverified source blijft niet-ready ondanks zaktualizowane grupowanie pozostałych kanałów'
    );
    $targetGroupingState = array_values(array_filter(
        $groupingState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $pagedChannel
    ))[0] ?? [];
    $targetVisiblePage = $materials->listForApi('Trade Recaps', '', 50);
    academyCheck(
        ($targetGroupingState['groupingReady'] ?? false) === true
            && ($targetGroupingState['available'] ?? false) === true
            && ($targetGroupingState['importedCount'] ?? 0) === 100
            && count($targetVisiblePage['items']) === 50,
        'aktualny indeks grupowania udostępnia gotowy kanał Trade Recaps',
        ['channel' => $targetGroupingState, 'visibleCount' => count($targetVisiblePage['items'])]
    );

    $db->execute(
        'UPDATE academy_material_channel_state
            SET grouping_version = 1, last_audit_at = UTC_TIMESTAMP(),
                audit_running = 0, audit_cleanup_pending = 0,
                audit_started_at = NULL, audit_before_message_id = NULL,
                audit_content_verified = 0
          WHERE channel_id = ?',
        [$pagedChannel]
    );
    $staleTargetState = array_values(array_filter(
        $materials->syncState()['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $pagedChannel
    ))[0] ?? [];
    $hiddenTargetPage = $materials->listForApi('Trade Recaps', '', 50);
    academyCheck(
        ($staleTargetState['groupingReady'] ?? true) === false
            && ($staleTargetState['available'] ?? true) === false
            && ($staleTargetState['importedCount'] ?? -1) === 0
            && $hiddenTargetPage['items'] === [],
        'stara wersja grupowania ukrywa tylko kanał wymagający audytu'
    );

    $syncSelectedChannel($pagedChannel);
    $auditAfterFirstPage = $db->queryOne(
        'SELECT grouping_version, audit_running, audit_cleanup_pending
           FROM academy_material_channel_state WHERE channel_id = ?',
        [$pagedChannel]
    );
    academyCheck(
        (int) ($auditAfterFirstPage['grouping_version'] ?? 0) === 1
            && (int) ($auditAfterFirstPage['audit_running'] ?? 0) === 1
            && (int) ($auditAfterFirstPage['audit_cleanup_pending'] ?? 0) === 0
            && $materials->listForApi('Trade Recaps', '', 50)['items'] === [],
        'pierwsza strona pełnego audytu nie ujawnia starego indeksu'
    );

    $syncSelectedChannel($pagedChannel);
    $auditAfterHistory = $db->queryOne(
        'SELECT grouping_version, audit_running, audit_cleanup_pending
           FROM academy_material_channel_state WHERE channel_id = ?',
        [$pagedChannel]
    );
    academyCheck(
        (int) ($auditAfterHistory['grouping_version'] ?? 0) === 1
            && (int) ($auditAfterHistory['audit_cleanup_pending'] ?? 0) === 1
            && $materials->listForApi('Trade Recaps', '', 50)['items'] === [],
        'pełny wielostronicowy audyt czeka na cleanup, a materiały pozostają ukryte',
        [
            'auditState' => $auditAfterHistory,
            'visibleCount' => count($materials->listForApi('Trade Recaps', '', 50)['items']),
        ]
    );

    $syncSelectedChannel($pagedChannel);
    $auditAfterCleanup = $db->queryOne(
        'SELECT grouping_version, audit_running, audit_cleanup_pending
           FROM academy_material_channel_state WHERE channel_id = ?',
        [$pagedChannel]
    );
    $readyAfterAudit = array_values(array_filter(
        $materials->syncState()['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $pagedChannel
    ))[0] ?? [];
    $visibleAfterAudit = $materials->listForApi('Trade Recaps', '', 50);
    academyCheck(
        (int) ($auditAfterCleanup['grouping_version'] ?? 0) === 2
            && (int) ($auditAfterCleanup['audit_running'] ?? 1) === 0
            && (int) ($auditAfterCleanup['audit_cleanup_pending'] ?? 1) === 0
            && ($readyAfterAudit['groupingReady'] ?? false) === true
            && ($readyAfterAudit['available'] ?? false) === true
            && ($readyAfterAudit['importedCount'] ?? 0) === 100
            && count($visibleAfterAudit['items']) === 50,
        'audyt i cleanup automatycznie promują wersję grupowania i przywracają widoczność',
        [
            'auditState' => $auditAfterCleanup,
            'channel' => $readyAfterAudit,
            'visibleCount' => count($visibleAfterAudit['items']),
        ]
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
            && count($allItems) === 111
            && $cursor === null
            && $cursorPages > 1,
        'API odczyt używa stabilnego keyset pagination bez duplikatów',
        [
            'itemCount' => count($allItems),
            'uniqueKeyCount' => count(array_unique($itemKeys)),
            'cursorRemaining' => $cursor,
            'pages' => $cursorPages,
            'firstKeys' => array_slice($itemKeys, 0, 5),
        ]
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
    $syncSelectedChannel($academyChannel);
    $countAfterIdempotency = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$academyChannel]
    )['c'] ?? 0);
    academyCheck(
        $countBeforeIdempotency === 2 && $countAfterIdempotency === 2,
        'powtórny incremental sync jest idempotentny',
        ['before' => $countBeforeIdempotency, 'after' => $countAfterIdempotency]
    );

    $syncSelectedChannel($pagedChannel);
    $incrementalRow = $db->queryOne(
        'SELECT message_id FROM academy_material_messages WHERE channel_id = ? AND message_id = ? AND is_deleted = 0',
        [$pagedChannel, $pagedNewId]
    );
    $pagedCountAfterIncremental = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$pagedChannel]
    )['c'] ?? 0);
    academyCheck(
        ($incrementalRow['message_id'] ?? null) === $pagedNewId && $pagedCountAfterIncremental === 101,
        'incremental sync używa after snowflake i zapisuje nowe rekordy',
        ['row' => $incrementalRow, 'itemCount' => $pagedCountAfterIncremental]
    );
    $syncSelectedChannel($pagedChannel);
    $pagedCountAfterRepeat = (int) ($db->queryOne(
        'SELECT COUNT(*) AS c FROM academy_material_items WHERE channel_id = ? AND is_deleted = 0',
        [$pagedChannel]
    )['c'] ?? 0);
    academyCheck(
        $pagedCountAfterRepeat === 101,
        'powtórny incremental nie duplikuje nowego rekordu',
        ['itemCount' => $pagedCountAfterRepeat]
    );

    $editedCaption = [...$caption, 'content' => 'Academyneedle — edytowany opis przeniesiony do drugiego źródła.'];
    $editedCaption['message_reference']['message_id'] = $sourceTwoId;
    $editedCaption['edited_timestamp'] = '2026-11-26T09:07:00.000Z';
    $auditDatasetA = [$sourceOne, $sourceTwo, $editedCaption];
    academySetAudit($db, $academyChannel);
    $syncSelectedChannel($academyChannel);
    for ($iteration = 0; $iteration < 3; $iteration++) {
        $auditState = $db->queryOne(
            'SELECT audit_cleanup_pending FROM academy_material_channel_state WHERE channel_id = ?',
            [$academyChannel]
        );
        if ((int) ($auditState['audit_cleanup_pending'] ?? 0) === 0) {
            break;
        }
        $syncSelectedChannel($academyChannel);
    }
    $editedItems = $db->query(
        'SELECT source_message_id, description_message_id, title, description, is_deleted
           FROM academy_material_items WHERE channel_id = ? ORDER BY source_message_id',
        [$academyChannel]
    );
    $editedById = [];
    foreach ($editedItems as $item) {
        $editedById[(string) $item['source_message_id']] = $item;
    }
    academyCheck(
        ($editedById[$sourceTwoId]['title'] ?? null) === 'Academyneedle — edytowany opis przeniesiony do drugiego źródła.'
            && ($editedById[$sourceTwoId]['description'] ?? null) === null
            && ($editedById[$sourceTwoId]['description_message_id'] ?? null) === $captionId
            && ($editedById[$sourceOneId]['description'] ?? null) === null,
        'audit wykrywa edycję, deterministycznie reassocjuje referencję i nie duplikuje jednolinijkowego tekstu',
        ['items' => $editedById]
    );

    $auditDatasetA = [$sourceOne, $editedCaption];
    academySetAudit($db, $academyChannel);
    $syncSelectedChannel($academyChannel);
    for ($iteration = 0; $iteration < 4; $iteration++) {
        $auditState = $db->queryOne(
            'SELECT audit_cleanup_pending FROM academy_material_channel_state WHERE channel_id = ?',
            [$academyChannel]
        );
        if ((int) ($auditState['audit_cleanup_pending'] ?? 0) === 0) {
            break;
        }
        $syncSelectedChannel($academyChannel);
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
    $standaloneCaption = $db->queryOne(
        'SELECT title, description, description_message_id
           FROM academy_material_items WHERE channel_id = ? AND source_message_id = ? AND is_deleted = 0',
        [$academyChannel, $captionId]
    );
    academyCheck(
        (int) ($deletedSource['is_deleted'] ?? 0) === 1
            && (int) ($deletedItem['is_deleted'] ?? 0) === 1
            && ($reassociatedCaption['target_source_message_id'] ?? null) === null
            && ($restoredRoot['description_message_id'] ?? null) === null
            && ($standaloneCaption['title'] ?? null) === 'Academyneedle — edytowany opis przeniesiony do drugiego źródła.'
            && ($standaloneCaption['description'] ?? null) === null
            && ($standaloneCaption['description_message_id'] ?? null) === null,
        'audyt miękko usuwa brakujące źródło i nie przepina jawnej referencji do niezwiązanego materiału',
        [
            'deletedSource' => $deletedSource,
            'deletedItem' => $deletedItem,
            'caption' => $reassociatedCaption,
            'restoredRoot' => $restoredRoot,
            'standaloneCaption' => $standaloneCaption,
        ]
    );

    $failMessageChannel = $permissionChannel;
    $failMessageStatus = 503;
    $transientResult = $syncSelectedChannel($permissionChannel);
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
    $syncSelectedChannel($permissionChannel);
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
        'utrata Read Message History ukrywa dane i pokazuje jawny status zamiast pustego sukcesu',
        ['channel' => $deniedChannel]
    );

    $failMessageStatus = null;
    $syncSelectedChannel($permissionChannel);
    $recoveredState = $materials->syncState();
    $recoveredChannel = array_values(array_filter(
        $recoveredState['channels'],
        static fn (array $channel): bool => $channel['channelId'] === $permissionChannel
    ))[0] ?? [];
    academyCheck(($recoveredChannel['available'] ?? false) === true, 'udany GET po przywróceniu dostępu przywraca zweryfikowany kanał');

    $failMetadataChannel = $permissionChannel;
    $failMetadataStatus = 503;
    $metadataRequestOffset = count($requests);
    $metadataTransientResult = $syncSelectedChannel($permissionChannel);
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
    $syncSelectedChannel($categoryFailureChannel);
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
    $syncSelectedChannel($categoryFailureChannel);

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
