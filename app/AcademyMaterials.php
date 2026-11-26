<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Read-only Discord Academy source, persisted and indexed for protected API reads.
 * Discord IDs are deliberately kept as strings throughout this class.
 */
final class AcademyMaterials
{
    private const GUILD_ID = '1186734786388176916';
    private const EXPECTED_CATEGORY_ID = '1186777064494338068';
    private const MESSAGE_PAGE_SIZE = 100;
    private const MESSAGE_PAGES_PER_RUN = 4;
    private const MIN_CONTENT_ACCESS_PROBE_MESSAGES = 5;
    private const RUN_BUDGET_SECONDS = 180;
    private const AUDIT_INTERVAL_SECONDS = 604800;
    private const AUDIT_CLEANUP_BATCH = 100;
    private const TZ = 'Europe/Warsaw';

    /** @var list<array{channelId:string,label:string,category:string,parentCategoryId:?string}> */
    private const CHANNELS = [
        ['channelId' => '1499560912611446784', 'label' => 'Materiały Forex', 'category' => 'Forex', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1499560977849384991', 'label' => 'Materiały Krypto', 'category' => 'Krypto', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1186973732925874217', 'label' => 'Nagrania', 'category' => 'Nagrania', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1186976565909794906', 'label' => 'Kalkulator', 'category' => 'Narzędzia', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1186978545902309456', 'label' => 'Przydatne strony', 'category' => 'Narzędzia', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1186979958187368468', 'label' => 'Dziennik tradingowy', 'category' => 'Narzędzia', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1499561028646600878', 'label' => 'Bootcamp Forex', 'category' => 'Forex', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1500905675960750151', 'label' => 'Bootcamp Krypto', 'category' => 'Krypto', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1501626207207292968', 'label' => 'Nagrania Mental', 'category' => 'Mental', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1542258755390152794', 'label' => 'Bonusowe wideo', 'category' => 'Dodatkowe', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1548026950961074216', 'label' => 'Nagrania Akademii', 'category' => 'Nagrania', 'parentCategoryId' => self::EXPECTED_CATEGORY_ID],
        ['channelId' => '1499558305888997568', 'label' => 'Trade Recaps', 'category' => 'Trade Recaps', 'parentCategoryId' => null],
        ['channelId' => '1566742784596250624', 'label' => 'Poranne odprawy', 'category' => 'Poranne odprawy', 'parentCategoryId' => null],
    ];

    private readonly ?\Closure $requester;
    private readonly \Closure $sleeper;
    private readonly int $recapWindowMinutes;

    /**
     * @param callable(string,string,array):array|null $requester Fixture transport: method, fixed path, query.
     * @param callable(float):void|null $sleeper Fixture delay hook, receives seconds.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        ?callable $requester = null,
        ?callable $sleeper = null,
        ?int $recapWindowMinutes = null,
    ) {
        $this->requester = $requester === null ? null : \Closure::fromCallable($requester);
        $this->sleeper = $sleeper === null
            ? static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            }
            : \Closure::fromCallable($sleeper);
        $configuredWindow = $recapWindowMinutes ?? $config->academyRecapWindowMinutes;
        $this->recapWindowMinutes = max(1, min(240, $configuredWindow));
    }

    /** @return list<array{channelId:string,label:string,category:string,parentCategoryId:?string}> */
    public static function allowlist(): array
    {
        return self::CHANNELS;
    }

    /** @return list<string> */
    public static function approvedCategories(): array
    {
        return array_values(array_unique(array_column(self::CHANNELS, 'category')));
    }

    public static function isAllowedChannel(string $channelId): bool
    {
        foreach (self::CHANNELS as $channel) {
            if ($channel['channelId'] === $channelId) {
                return true;
            }
        }
        return false;
    }

    public static function supportsMethod(string $method): bool
    {
        return strtoupper($method) === 'GET';
    }

    /**
     * Discord may return HTTP 200 with blank content/embeds/attachments when
     * Message Content access is unavailable. Only positive user-message content
     * is proof; an empty history remains unverified rather than ready.
     *
     * @param list<array<string,mixed>> $messages
     */
    public static function assessMessageContentAccess(
        array $messages,
        string $previousStatus = 'unverified',
        int $knownContentMessages = 0,
    ): string
    {
        $eligible = 0;
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $type = $message['type'] ?? 0;
            if ((!is_int($type) && !(is_string($type) && ctype_digit($type)))
                || !in_array((int) $type, [0, 19, 21], true)) {
                continue;
            }
            $author = is_array($message['author'] ?? null) ? $message['author'] : [];
            if (!empty($author['bot'])) {
                continue;
            }
            $eligible++;
            $content = is_string($message['content'] ?? null) ? trim($message['content']) : '';
            if ($content !== ''
                || (is_array($message['embeds'] ?? null) && $message['embeds'] !== [])
                || (is_array($message['attachments'] ?? null) && $message['attachments'] !== [])) {
                return 'verified';
            }
        }
        if ($eligible >= self::MIN_CONTENT_ACCESS_PROBE_MESSAGES
            || ($eligible > 0 && $knownContentMessages > 0 && $previousStatus === 'verified')) {
            return 'suspected_missing';
        }
        return in_array($previousStatus, ['verified', 'suspected_missing'], true)
            ? $previousStatus
            : 'unverified';
    }

    /**
     * Mirrors the Academy/full-plan gate used by #akFull. Session user data is
     * loaded from the current users row on every request by Auth::readSession().
     */
    public static function hasAcademyEntitlement(array $user, ?int $now = null): bool
    {
        if (!empty($user['isDemo']) || empty($user['roleVerified']) || ($user['plan'] ?? null) !== 'full') {
            return false;
        }
        $until = $user['accessUntil'] ?? null;
        if ($until === null || $until === '') {
            return true;
        }
        if (!is_string($until) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) !== 1) {
            return false;
        }
        try {
            $expiry = new \DateTimeImmutable($until . ' 23:59:59', new \DateTimeZone('UTC'));
            return $expiry->getTimestamp() >= ($now ?? time());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pure message normalization seam used by CI fixtures.
     *
     * @return array<string,mixed>|null Null means non-content/system noise.
     */
    public static function normalizeMessage(array $payload, string $channelId): ?array
    {
        if (!self::isAllowedChannel($channelId)) {
            throw new \InvalidArgumentException('unapproved_channel');
        }
        $messageId = self::snowflake($payload['id'] ?? null);
        if ($messageId === null) {
            throw new \RuntimeException('invalid_payload');
        }
        if (isset($payload['channel_id']) && (!is_string($payload['channel_id']) || $payload['channel_id'] !== $channelId)) {
            throw new \RuntimeException('invalid_payload');
        }
        $createdAt = self::utcDateTime($payload['timestamp'] ?? null);
        if ($createdAt === null) {
            throw new \RuntimeException('invalid_payload');
        }
        $type = $payload['type'] ?? 0;
        if (!is_int($type) && !(is_string($type) && ctype_digit($type))) {
            return null;
        }
        if (!in_array((int) $type, [0, 19, 21], true)) {
            return null;
        }

        $author = is_array($payload['author'] ?? null) ? $payload['author'] : [];
        $authorId = self::snowflake($author['id'] ?? null);
        $authorName = self::cleanText(
            (string) ($payload['member']['nick'] ?? $author['global_name'] ?? $author['username'] ?? '')
        );
        $authorName = $authorName === '' ? null : self::truncate($authorName, 100);
        $referenceId = null;
        if (is_array($payload['message_reference'] ?? null)) {
            $referenceId = self::snowflake($payload['message_reference']['message_id'] ?? null);
        }

        $mediaTypes = [];
        $attachmentDescriptions = [];
        foreach (is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [] as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $mime = strtolower((string) ($attachment['content_type'] ?? ''));
            $filename = strtolower((string) ($attachment['filename'] ?? ''));
            $mediaTypes[] = self::mediaType($mime, $filename);
            $description = self::cleanText((string) ($attachment['description'] ?? ''));
            if ($description !== '') {
                $attachmentDescriptions[] = $description;
            }
        }

        $embedTitles = [];
        $embedDescriptions = [];
        foreach (is_array($payload['embeds'] ?? null) ? $payload['embeds'] : [] as $embed) {
            if (!is_array($embed)) {
                continue;
            }
            $embedType = strtolower((string) ($embed['type'] ?? ''));
            if ($embedType === 'video' || is_array($embed['video'] ?? null)) {
                $mediaTypes[] = 'video';
            } elseif ($embedType === 'image' || is_array($embed['image'] ?? null)) {
                $mediaTypes[] = 'image';
            }
            $title = self::cleanText((string) ($embed['title'] ?? ''));
            $description = self::cleanText((string) ($embed['description'] ?? ''));
            if ($title !== '') {
                $embedTitles[] = $title;
            }
            if ($description !== '') {
                $embedDescriptions[] = $description;
            }
        }
        $mediaTypes = array_values(array_unique(array_filter($mediaTypes)));
        $hasMedia = $mediaTypes !== [];
        $materialType = count($mediaTypes) > 1
            ? 'mixed'
            : ($mediaTypes[0] ?? 'text');

        $content = self::cleanText((string) ($payload['content'] ?? ''));
        $embedTitle = self::truncate(implode(' · ', $embedTitles), 250);
        $embedDescription = self::truncate(implode("\n", [...$embedDescriptions, ...$attachmentDescriptions]), 12000);
        $explicitTitle = $embedTitle !== '';

        if ($explicitTitle) {
            $title = $embedTitle;
            $descriptionParts = array_filter([$embedDescription, $content], static fn (string $part): bool => $part !== '');
            $description = self::truncate(implode("\n", $descriptionParts), 12000);
        } elseif (!$hasMedia && $content !== '' && $embedDescription === '' && self::length($content) <= 500) {
            // Short text-only prose is a standalone material unless it deterministically
            // pairs with a same-day referenced/previous media source.
            $title = null;
            $description = self::truncate($content, 12000);
        } else {
            $fullText = self::truncate(implode("\n", array_filter([$content, $embedDescription])), 12000);
            [$title, $description] = self::splitTitleDescription($fullText);
            if ($title === null && $embedDescription !== '') {
                $title = self::truncate($embedDescription, 180);
            }
        }

        $contentText = self::truncate(
            implode("\n", array_filter([$content, $embedDescription])),
            12000
        );
        if (!$hasMedia && $title === null && $description === null && $contentText === '') {
            return null;
        }
        if (!$hasMedia && $contentText === '' && $title === null && $description === null) {
            return null;
        }

        return [
            'guild_id' => self::GUILD_ID,
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'created_at_utc' => $createdAt,
            'created_at_iso' => (string) $payload['timestamp'],
            'edited_at_utc' => self::utcDateTime($payload['edited_timestamp'] ?? null),
            'edited_at_iso' => self::validIsoDateTime($payload['edited_timestamp'] ?? null),
            'reference_message_id' => $referenceId,
            'title' => $title === '' ? null : $title,
            'description' => $description === '' ? null : $description,
            'content_text' => $contentText === '' ? null : $contentText,
            'material_type' => $materialType,
            'has_media' => $hasMedia ? 1 : 0,
            'is_caption_candidate' => !$hasMedia && !$explicitTitle && $content !== '' && self::length($content) <= 500 ? 1 : 0,
        ];
    }

    /**
     * Pure deterministic grouping seam. It accepts messages from adjacent Discord
     * pages together, so a source/caption pair is independent of page boundaries.
     *
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    public static function groupMessages(array $messages, int $recapWindowMinutes = 30): array
    {
        $window = max(1, min(240, $recapWindowMinutes)) * 60;
        $active = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !empty($message['is_deleted'])) {
                continue;
            }
            $id = self::snowflake($message['message_id'] ?? $message['messageId'] ?? null);
            $channelId = (string) ($message['channel_id'] ?? $message['channelId'] ?? '');
            if ($id === null || !self::isAllowedChannel($channelId)) {
                continue;
            }
            $message['_id'] = $id;
            $message['_channel'] = $channelId;
            $message['_created'] = (string) ($message['created_at_utc'] ?? $message['createdAt'] ?? '');
            $active[$id] = $message;
        }

        $ordered = array_values($active);
        usort($ordered, static function (array $a, array $b): int {
            return strcmp($a['_created'], $b['_created']) ?: self::compareSnowflakes($a['_id'], $b['_id']);
        });

        $roots = [];
        foreach ($ordered as $message) {
            $id = $message['_id'];
            $target = null;
            $ref = self::snowflake($message['reference_message_id'] ?? $message['referenceMessageId'] ?? null);
            if ($ref !== null && isset($active[$ref])) {
                $referenced = $active[$ref];
                if ($referenced['_channel'] === $message['_channel']
                    && !empty($referenced['has_media'])
                    && self::sameWarsawDay($referenced['_created'], $message['_created'])) {
                    $target = self::snowflake($referenced['target_source_message_id'] ?? $referenced['targetSourceMessageId'] ?? null)
                        ?? $ref;
                }
            }
            if ($target === null && !empty($message['is_caption_candidate']) && empty($message['has_media'])) {
                $captionTime = self::parseUtc($message['_created']);
                $candidates = array_reverse($ordered);
                foreach ($candidates as $candidate) {
                    $candidateId = $candidate['_id'];
                    if ($candidateId === $id || $candidate['_channel'] !== $message['_channel']
                        || (string) ($candidate['author_id'] ?? $candidate['authorId'] ?? '') !== (string) ($message['author_id'] ?? $message['authorId'] ?? '')
                        || empty($candidate['has_media'])
                        || !empty($candidate['is_deleted'])
                        || !self::sameWarsawDay($candidate['_created'], $message['_created'])) {
                        continue;
                    }
                    $candidateTime = self::parseUtc($candidate['_created']);
                    if ($captionTime === null || $candidateTime === null
                        || $candidateTime >= $captionTime
                        || ($captionTime->getTimestamp() - $candidateTime->getTimestamp()) > $window) {
                        continue;
                    }
                    $target = self::snowflake($candidate['target_source_message_id'] ?? $candidate['targetSourceMessageId'] ?? null)
                        ?? $candidateId;
                    break;
                }
            }
            if ($target === null) {
                $roots[$id] = $message;
                continue;
            }
            $root = $active[$target] ?? null;
            if ($root === null || $root['_channel'] !== $message['_channel']
                || !self::sameWarsawDay($root['_created'], $message['_created'])) {
                $roots[$id] = $message;
                continue;
            }
            $rootId = self::snowflake($root['target_source_message_id'] ?? $root['targetSourceMessageId'] ?? null)
                ?? $target;
            if (!isset($roots[$rootId])) {
                $roots[$rootId] = $active[$rootId] ?? $root;
            }
            if (empty($roots[$rootId]['_caption'])) {
                $roots[$rootId]['_caption'] = $message;
            } else {
                // Preserve extra prose as its own item rather than dropping it.
                $roots[$id] = $message;
            }
        }

        $items = [];
        foreach ($roots as $id => $root) {
            $spec = self::channelMap()[$root['_channel']] ?? null;
            if ($spec === null) {
                continue;
            }
            $caption = $root['_caption'] ?? null;
            $description = trim((string) ($root['description'] ?? ''));
            $descriptionMessageId = $id;
            if (is_array($caption)) {
                $captionDescription = trim((string) ($caption['description'] ?? $caption['content_text'] ?? ''));
                if ($captionDescription !== '') {
                    $description = $description === ''
                        ? $captionDescription
                        : $description . "\n\n" . $captionDescription;
                    $descriptionMessageId = (string) $caption['_id'];
                }
            }
            $title = trim((string) ($root['title'] ?? ''));
            if ($title === '') {
                $title = self::deriveTitle((string) ($root['content_text'] ?? $root['description'] ?? ''));
            }
            $items[] = [
                'guildId' => self::GUILD_ID,
                'channelId' => $root['_channel'],
                'sourceMessageId' => (string) $id,
                'descriptionMessageId' => $descriptionMessageId,
                'category' => $spec['category'],
                'title' => $title === '' ? null : $title,
                'description' => $description === '' ? null : $description,
                'authorId' => $root['author_id'] ?? $root['authorId'] ?? null,
                'authorName' => $root['author_name'] ?? $root['authorName'] ?? null,
                'publishedAt' => self::formatIsoUtc((string) $root['_created']),
                'materialType' => (string) ($root['material_type'] ?? $root['materialType'] ?? 'text'),
                'discordUrl' => 'https://discord.com/channels/' . self::GUILD_ID . '/' . $root['_channel'] . '/' . $id,
            ];
        }
        usort($items, static fn (array $a, array $b): int =>
            strcmp((string) $b['publishedAt'], (string) $a['publishedAt'])
            ?: strcmp((string) $a['channelId'], (string) $b['channelId'])
            ?: self::compareSnowflakes((string) $b['sourceMessageId'], (string) $a['sourceMessageId'])
        );
        return $items;
    }

    /**
     * Cron-only synchronizer. Metadata checks are direct per-channel GETs; no
     * guild enumeration and no message request can escape the hard-coded list.
     *
     * @return array{skipped?:bool,status:string,pages:int,verifiedChannels:int,ready:bool}
     */
    public function sync(): array
    {
        $this->seedStateRows();
        $lock = bin2hex(random_bytes(16));
        $locked = $this->db->execute(
            "UPDATE academy_material_sync_state
                SET lock_token = ?, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE),
                    last_attempt_at = UTC_TIMESTAMP(), last_status = 'running', last_error_code = NULL
              WHERE id = 1 AND (lock_token IS NULL OR locked_until IS NULL OR locked_until < UTC_TIMESTAMP())",
            [$lock]
        );
        if ($locked === 0) {
            return ['skipped' => true, 'status' => 'skipped', 'pages' => 0, 'verifiedChannels' => 0, 'ready' => false];
        }

        $deadline = microtime(true) + self::RUN_BUDGET_SECONDS;
        $pages = 0;
        $hadErrors = false;
        $verifiedCount = 0;
        $metadataVerifiedThisRun = [];
        try {
            $guildConfigured = ($this->config->discord['guildId'] ?? '') === self::GUILD_ID;
            $tokenConfigured = (string) ($this->config->discord['botToken'] ?? '') !== '';
            foreach (self::CHANNELS as $index => $channel) {
                if (microtime(true) >= $deadline) {
                    $this->setChannelVerificationError($channel, 'run_budget_exhausted');
                    for ($remaining = $index + 1; $remaining < count(self::CHANNELS); $remaining++) {
                        $this->setChannelVerificationError(self::CHANNELS[$remaining], 'run_budget_exhausted');
                    }
                    $hadErrors = true;
                    break;
                }
                if (!$guildConfigured || !$tokenConfigured) {
                    $this->setChannelUnverified($channel, !$tokenConfigured ? 'not_configured' : 'guild_mismatch');
                    $hadErrors = true;
                    continue;
                }
                try {
                    $metadata = $this->discordGet('/channels/' . $channel['channelId']);
                    $this->verifyMetadata($channel, $metadata);
                    $this->setChannelVerified($channel);
                    $metadataVerifiedThisRun[$channel['channelId']] = true;
                    $verifiedCount++;
                } catch (\Throwable $err) {
                    $this->setChannelVerificationError($channel, $this->safeErrorCode($err));
                    $hadErrors = true;
                }
            }

            $work = $this->db->query(
                "SELECT * FROM academy_material_channel_state
                  WHERE verified = 1
                  ORDER BY audit_cleanup_pending DESC, work_order ASC, channel_id ASC
                  LIMIT " . count(self::CHANNELS)
            );
            $workSlots = 0;
            foreach ($work as $state) {
                if (microtime(true) >= $deadline) {
                    $hadErrors = true;
                    break;
                }
                $channel = self::channelMap()[(string) $state['channel_id']] ?? null;
                if ($channel === null || !self::isAllowedChannel((string) $state['channel_id'])) {
                    continue;
                }
                if (!isset($metadataVerifiedThisRun[$channel['channelId']])) {
                    continue;
                }
                if ($workSlots >= self::MESSAGE_PAGES_PER_RUN) {
                    break;
                }
                $workSlots++;
                try {
                    if ((int) $state['audit_cleanup_pending'] === 1) {
                        $this->cleanupAuditBatch($channel, $state);
                        continue;
                    }
                    if ((int) $state['backfill_complete'] === 0) {
                        $query = ['limit' => self::MESSAGE_PAGE_SIZE];
                        if (is_string($state['backfill_before_message_id']) && $state['backfill_before_message_id'] !== '') {
                            $query['before'] = $state['backfill_before_message_id'];
                        }
                        $messages = $this->discordGet('/channels/' . $channel['channelId'] . '/messages', $query, true);
                        $this->persistMessagePage($channel, $messages, 'backfill', $state);
                    } elseif ($this->auditIsDue($state)) {
                        if ((int) $state['audit_running'] === 0) {
                            $this->db->execute(
                                'UPDATE academy_material_channel_state
                                    SET audit_running = 1, audit_started_at = UTC_TIMESTAMP(),
                                        audit_before_message_id = NULL, audit_content_verified = 1
                                  WHERE channel_id = ?',
                                [$channel['channelId']]
                            );
                            $auditState = $this->db->queryOne(
                                'SELECT audit_started_at FROM academy_material_channel_state WHERE channel_id = ?',
                                [$channel['channelId']]
                            );
                            $state['audit_running'] = 1;
                            $state['audit_before_message_id'] = null;
                            $state['audit_started_at'] = $auditState['audit_started_at'] ?? null;
                            $state['audit_content_verified'] = 1;
                        }
                        $query = ['limit' => self::MESSAGE_PAGE_SIZE];
                        if (is_string($state['audit_before_message_id']) && $state['audit_before_message_id'] !== '') {
                            $query['before'] = $state['audit_before_message_id'];
                        }
                        $messages = $this->discordGet('/channels/' . $channel['channelId'] . '/messages', $query, true);
                        $this->persistMessagePage($channel, $messages, 'audit', $state);
                    } else {
                        $query = ['limit' => self::MESSAGE_PAGE_SIZE];
                        if (is_string($state['incremental_after_message_id']) && $state['incremental_after_message_id'] !== '') {
                            $query['after'] = $state['incremental_after_message_id'];
                        }
                        $messages = $this->discordGet('/channels/' . $channel['channelId'] . '/messages', $query, true);
                        $this->persistMessagePage($channel, $messages, 'incremental', $state);
                    }
                    $pages++;
                } catch (\Throwable $err) {
                    $code = $this->safeErrorCode($err);
                    $this->setChannelSyncError($channel['channelId'], $code);
                    $hadErrors = true;
                }
            }

            $ready = $this->allSourcesReady();
            $accessProblem = $this->accessProblemStatus();
            $status = $hadErrors
                ? ($verifiedCount > 0 ? 'partial' : 'error')
                : ($ready ? 'success' : ($this->hasIncompleteBackfill() ? 'backfill' : ($accessProblem ?? 'backfill')));
            $this->db->execute(
                'UPDATE academy_material_sync_state
                    SET last_status = ?, last_success_at = IF(? = 1, UTC_TIMESTAMP(), last_success_at),
                        last_error_code = ?, pages_processed = ?, verified_channels = ?,
                        updated_at = UTC_TIMESTAMP()
                  WHERE id = 1 AND lock_token = ?',
                [$status, $hadErrors ? 0 : 1, $hadErrors ? 'source_or_channel_error' : null, $pages, $verifiedCount, $lock]
            );
            return [
                'status' => $status,
                'pages' => $pages,
                'verifiedChannels' => $verifiedCount,
                'ready' => $ready,
            ];
        } catch (\Throwable) {
            $this->db->execute(
                "UPDATE academy_material_sync_state
                    SET last_status = 'error', last_error_code = 'sync_error', updated_at = UTC_TIMESTAMP()
                  WHERE id = 1 AND lock_token = ?",
                [$lock]
            );
            return ['status' => 'error', 'pages' => $pages, 'verifiedChannels' => $verifiedCount, 'ready' => false];
        } finally {
            $this->db->execute(
                'UPDATE academy_material_sync_state SET lock_token = NULL, locked_until = NULL WHERE id = 1 AND lock_token = ?',
                [$lock]
            );
        }
    }

    /**
     * Read-only API query. Filtering, search and keyset pagination all execute in
     * MySQL; only limit+1 normalized rows are returned to the caller.
     */
    public function listForApi(string $category = 'all', string $query = '', int $limit = 10, ?string $cursor = null): array
    {
        if ($category !== 'all' && !in_array($category, self::approvedCategories(), true)) {
            throw new HttpException(400, 'invalid_category', 'Nieprawidłowa kategoria materiałów.');
        }
        $query = self::truncate(trim($query), 100);
        $limit = max(1, min(50, $limit));
        $queryHash = hash('sha256', $query);
        $cursorData = $cursor === null || $cursor === '' ? null : $this->decodeCursor($cursor, $category, $queryHash, $limit);

        $allowedIds = array_column(self::CHANNELS, 'channelId');
        $allowParams = implode(',', array_fill(0, count($allowedIds), '?'));
        $snapshotId = $cursorData['snapshotId'] ?? (int) (($this->db->queryOne(
            'SELECT COALESCE(MAX(i.id), 0) AS max_id
               FROM academy_material_items i
               JOIN academy_material_channel_state c ON c.channel_id = i.channel_id AND c.verified = 1
              WHERE i.is_deleted = 0 AND c.message_access_status = \'verified\'
                AND c.verified = 1
                AND c.content_access_status = \'verified\'
                AND i.channel_id IN (' . $allowParams . ')',
            $allowedIds
        )['max_id'] ?? 0));

        $where = [
            'i.is_deleted = 0',
            'c.verified = 1',
            "c.message_access_status = 'verified'",
            "c.content_access_status = 'verified'",
            'i.id <= ?',
            'i.channel_id IN (' . $allowParams . ')',
        ];
        $params = [$snapshotId, ...$allowedIds];
        if ($category !== 'all') {
            $where[] = 'i.category_label = ?';
            $params[] = $category;
        }
        if ($query !== '') {
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $query) ?: [];
            $tokens = array_values(array_filter($tokens, static fn (string $token): bool => self::length($token) >= 3));
            if ($tokens !== []) {
                $where[] = 'MATCH(i.title, i.description) AGAINST (? IN BOOLEAN MODE)';
                $params[] = implode(' ', array_map(static fn (string $token): string => '+' . $token . '*', $tokens));
            } else {
                $where[] = "LOCATE(?, CONCAT_WS(' ', COALESCE(i.title, ''), COALESCE(i.description, ''))) > 0";
                $params[] = $query;
            }
        }
        if ($cursorData !== null) {
            $where[] = '(i.published_at_utc < ? OR (i.published_at_utc = ? AND i.channel_id > ?) OR (i.published_at_utc = ? AND i.channel_id = ? AND i.source_message_id < ?))';
            array_push(
                $params,
                $cursorData['publishedAt'],
                $cursorData['publishedAt'],
                $cursorData['channelId'],
                $cursorData['publishedAt'],
                $cursorData['channelId'],
                $cursorData['sourceMessageId']
            );
        }
        $rows = $this->db->query(
            'SELECT i.guild_id, i.channel_id, i.source_message_id, i.description_message_id,
                    i.category_label, i.title, i.description, i.author_id, i.author_name,
                    i.published_at_utc, i.published_at_iso, i.material_type
               FROM academy_material_items i
               JOIN academy_material_channel_state c ON c.channel_id = i.channel_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY i.published_at_utc DESC, i.channel_id ASC, i.source_message_id DESC
              LIMIT ' . ($limit + 1),
            $params
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = [];
        foreach ($rows as $row) {
            $channelId = (string) $row['channel_id'];
            $messageId = (string) $row['source_message_id'];
            if (!self::isAllowedChannel($channelId) || self::snowflake($messageId) === null) {
                continue;
            }
            $items[] = [
                'guildId' => self::GUILD_ID,
                'channelId' => $channelId,
                'sourceMessageId' => $messageId,
                'descriptionMessageId' => (string) $row['description_message_id'],
                'category' => (string) $row['category_label'],
                'title' => $row['title'] === null ? null : (string) $row['title'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'authorId' => $row['author_id'] === null ? null : (string) $row['author_id'],
                'authorName' => $row['author_name'] === null ? null : (string) $row['author_name'],
                'publishedAt' => (string) $row['published_at_iso'],
                'materialType' => (string) $row['material_type'],
                'discordUrl' => 'https://discord.com/channels/' . self::GUILD_ID . '/' . $channelId . '/' . $messageId,
            ];
        }
        $nextCursor = null;
        if ($hasMore && $items !== []) {
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encodeCursor([
                'snapshotId' => $snapshotId,
                'publishedAt' => (string) $last['published_at_utc'],
                'channelId' => (string) $last['channel_id'],
                'sourceMessageId' => (string) $last['source_message_id'],
            ], $category, $queryHash, $limit);
        }

        return ['items' => $items, 'nextCursor' => $nextCursor, 'sync' => $this->syncState()];
    }

    /** Content-free sync/health status. */
    public function syncState(): array
    {
        $global = $this->db->queryOne(
            'SELECT last_status, last_attempt_at, last_success_at, last_error_code, pages_processed
               FROM academy_material_sync_state WHERE id = 1'
        ) ?? [];
        $stateRows = $this->db->query('SELECT * FROM academy_material_channel_state');
        $byId = [];
        foreach ($stateRows as $row) {
            $byId[(string) $row['channel_id']] = $row;
        }
        $countsRows = $this->db->query(
            'SELECT i.channel_id, COUNT(*) AS item_count
               FROM academy_material_items i
               JOIN academy_material_channel_state c ON c.channel_id = i.channel_id
                    AND c.verified = 1 AND c.message_access_status = \'verified\'
                    AND c.content_access_status = \'verified\'
              WHERE i.is_deleted = 0
              GROUP BY i.channel_id'
        );
        $counts = [];
        foreach ($countsRows as $row) {
            $counts[(string) $row['channel_id']] = (int) $row['item_count'];
        }
        $channels = [];
        $ready = true;
        foreach (self::CHANNELS as $channel) {
            $row = $byId[$channel['channelId']] ?? [];
            $verified = (int) ($row['verified'] ?? 0) === 1;
            $backfillComplete = (int) ($row['backfill_complete'] ?? 0) === 1;
            $messageAccessStatus = (string) ($row['message_access_status'] ?? 'unverified');
            $messageAccessVerified = $messageAccessStatus === 'verified';
            $contentAccessStatus = (string) ($row['content_access_status'] ?? 'unverified');
            $contentAccessVerified = $contentAccessStatus === 'verified';
            $ready = $ready && $verified && $backfillComplete && $messageAccessVerified && $contentAccessVerified;
            $channels[] = [
                'channelId' => $channel['channelId'],
                'label' => $channel['label'],
                'category' => $channel['category'],
                'status' => (string) ($row['sync_status'] ?? 'never'),
                'verified' => $verified,
                'available' => $verified && $messageAccessVerified && $contentAccessVerified,
                'messageAccessStatus' => $messageAccessStatus,
                'messageAccessErrorCode' => $row['message_access_error_code'] ?? null,
                'messageAccessCheckedAt' => self::formatMaybeUtc($row['message_access_checked_at'] ?? null),
                'contentAccessStatus' => $contentAccessStatus,
                'contentAccessErrorCode' => $row['content_access_error_code'] ?? null,
                'contentAccessCheckedAt' => self::formatMaybeUtc($row['content_access_checked_at'] ?? null),
                'importedCount' => $verified && $messageAccessVerified && $contentAccessVerified
                    ? (int) ($counts[$channel['channelId']] ?? 0)
                    : 0,
                'backfillComplete' => $backfillComplete,
                'lastSuccessAt' => self::formatMaybeUtc($row['last_success_at'] ?? null),
                'lastErrorCode' => $row['last_error_code'] ?? $row['verification_error_code'] ?? null,
            ];
        }
        return [
            'status' => (string) ($global['last_status'] ?? 'never'),
            'ready' => $ready,
            'lastSuccessAt' => self::formatMaybeUtc($global['last_success_at'] ?? null),
            'lastAttemptAt' => self::formatMaybeUtc($global['last_attempt_at'] ?? null),
            'lastErrorCode' => $global['last_error_code'] ?? null,
            'pagesProcessed' => (int) ($global['pages_processed'] ?? 0),
            'channels' => $channels,
        ];
    }

    private function seedStateRows(): void
    {
        $this->db->execute(
            "INSERT INTO academy_material_sync_state (id, last_status)
             VALUES (1, 'never') ON DUPLICATE KEY UPDATE id = id"
        );
        foreach (self::CHANNELS as $channel) {
            $this->db->execute(
                "INSERT INTO academy_material_channel_state
                    (channel_id, guild_id, label, category_label, expected_parent_id, sync_status)
                 VALUES (?, ?, ?, ?, ?, 'never')
                 ON DUPLICATE KEY UPDATE guild_id = VALUES(guild_id), label = VALUES(label),
                    category_label = VALUES(category_label), expected_parent_id = VALUES(expected_parent_id)",
                [$channel['channelId'], self::GUILD_ID, $channel['label'], $channel['category'], $channel['parentCategoryId']]
            );
        }
    }

    private function verifyMetadata(array $channel, array $metadata): void
    {
        if (($metadata['id'] ?? null) !== $channel['channelId']
            || ($metadata['guild_id'] ?? null) !== self::GUILD_ID) {
            throw new \RuntimeException('guild_mismatch');
        }
        if ($channel['parentCategoryId'] !== null
            && ($metadata['parent_id'] ?? null) !== $channel['parentCategoryId']) {
            throw new \RuntimeException('category_mismatch');
        }
    }

    private function setChannelVerified(array $channel): void
    {
        $this->db->execute(
            "UPDATE academy_material_channel_state
                SET verified = 1, verification_status = 'verified',
                    last_error_code = IF(last_error_code = verification_error_code, NULL, last_error_code),
                    verification_error_code = NULL,
                    last_metadata_at = UTC_TIMESTAMP(),
                    sync_status = IF(sync_status = 'unverified',
                        IF(backfill_complete = 1, 'incremental', 'backfill'), sync_status)
              WHERE channel_id = ?",
            [$channel['channelId']]
        );
    }

    private function setChannelUnverified(array $channel, string $code): void
    {
        $this->db->execute(
            "UPDATE academy_material_channel_state
                SET verified = 0, verification_status = 'unverified',
                    verification_error_code = ?, sync_status = 'unverified',
                    message_access_status = 'unverified', message_access_error_code = ?,
                    last_error_code = ?, last_attempt_at = UTC_TIMESTAMP()
              WHERE channel_id = ?",
            [$code, $code, $code, $channel['channelId']]
        );
    }

    private function setChannelVerificationError(array $channel, string $code): void
    {
        if (in_array($code, ['http_429', 'http_5xx', 'transport_error', 'run_budget_exhausted'], true)) {
            $this->db->execute(
                'UPDATE academy_material_channel_state
                    SET verification_error_code = ?, last_error_code = ?, last_attempt_at = UTC_TIMESTAMP()
                  WHERE channel_id = ?',
                [$code, $code, $channel['channelId']]
            );
            return;
        }
        $this->setChannelUnverified($channel, $code);
    }

    private function setChannelSyncError(string $channelId, string $code): void
    {
        $accessUnavailable = in_array($code, ['http_401', 'http_403', 'http_404'], true);
        $this->db->execute(
            "UPDATE academy_material_channel_state
                SET sync_status = 'error', last_error_code = ?, last_attempt_at = UTC_TIMESTAMP(),
                    message_access_status = IF(? = 1, 'unavailable', message_access_status),
                    message_access_error_code = IF(? = 1, ?, message_access_error_code)
              WHERE channel_id = ?",
            [$code, $accessUnavailable ? 1 : 0, $accessUnavailable ? 1 : 0, $code, $channelId]
        );
    }

    private function persistMessagePage(array $channel, array $messages, string $phase, array $state): void
    {
        $records = [];
        foreach ($messages as $payload) {
            if (!is_array($payload) || self::snowflake($payload['id'] ?? null) === null) {
                throw new \RuntimeException('invalid_payload');
            }
            $normalized = self::normalizeMessage($payload, $channel['channelId']);
            $records[] = ['payload' => $payload, 'normalized' => $normalized];
        }
        usort($records, static function (array $a, array $b): int {
            $ta = self::utcDateTime($a['payload']['timestamp'] ?? null) ?? '';
            $tb = self::utcDateTime($b['payload']['timestamp'] ?? null) ?? '';
            return strcmp($ta, $tb)
                ?: self::compareSnowflakes((string) $a['payload']['id'], (string) $b['payload']['id']);
        });
        $rawIds = array_map(static fn (array $record): string => (string) $record['payload']['id'], $records);
        $auditStartedAt = $phase === 'audit' ? (string) ($state['audit_started_at'] ?? gmdate('Y-m-d H:i:s')) : null;
        $knownContentMessages = 0;
        if ($rawIds !== []) {
            $idPlaceholders = implode(',', array_fill(0, count($rawIds), '?'));
            $knownRow = $this->db->queryOne(
                'SELECT COUNT(*) AS known_count FROM academy_material_messages
                  WHERE channel_id = ? AND is_deleted = 0 AND message_id IN (' . $idPlaceholders . ')
                    AND (has_media = 1 OR title IS NOT NULL OR description IS NOT NULL OR content_text IS NOT NULL)',
                [$channel['channelId'], ...$rawIds]
            );
            $knownContentMessages = (int) ($knownRow['known_count'] ?? 0);
        }
        $contentAccessStatus = self::assessMessageContentAccess(
            $messages,
            (string) ($state['content_access_status'] ?? 'unverified'),
            $knownContentMessages
        );

        $this->db->withTransaction(function () use ($channel, $records, $rawIds, $phase, $state, $auditStartedAt, $contentAccessStatus): void {
            $contentAccessError = match ($contentAccessStatus) {
                'verified' => null,
                'suspected_missing' => 'message_content_access_missing',
                default => 'message_content_unverified',
            };
            $this->db->execute(
                'UPDATE academy_material_channel_state
                    SET message_access_status = \'verified\', message_access_error_code = NULL,
                        message_access_checked_at = UTC_TIMESTAMP(),
                        content_access_status = ?, content_access_error_code = ?,
                        content_access_checked_at = UTC_TIMESTAMP()
                  WHERE channel_id = ?',
                [$contentAccessStatus, $contentAccessError, $channel['channelId']]
            );
            foreach ($records as $record) {
                $id = (string) $record['payload']['id'];
                if ($record['normalized'] === null) {
                    if ($contentAccessStatus === 'verified') {
                        $this->markMessageUnavailable($channel['channelId'], $id);
                    }
                    continue;
                }
                $this->persistNormalizedMessage($record['normalized'], $auditStartedAt);
            }

            $oldestId = self::snowflakeExtremum($rawIds, false);
            $newestId = self::snowflakeExtremum($rawIds, true);
            $nextWorkOrder = $this->nextWorkOrder();
            $phaseStatus = match ($phase) {
                'backfill' => 'backfill',
                'audit' => 'auditing',
                default => 'incremental',
            };

            if ($phase === 'backfill') {
                $complete = count($records) < self::MESSAGE_PAGE_SIZE;
                $currentHighWater = (string) ($state['incremental_after_message_id'] ?? '');
                if ($newestId !== null && ($currentHighWater === '' || self::compareSnowflakes($newestId, $currentHighWater) > 0)) {
                    $currentHighWater = $newestId;
                }
                $this->db->execute(
                    'UPDATE academy_material_channel_state
                        SET backfill_before_message_id = ?, backfill_complete = ?,
                            incremental_after_message_id = ?, last_page_at = UTC_TIMESTAMP(),
                            last_attempt_at = UTC_TIMESTAMP(), last_success_at = UTC_TIMESTAMP(),
                            last_error_code = NULL, sync_status = ?, work_order = ?,
                            last_audit_at = IF(? = 1, UTC_TIMESTAMP(), last_audit_at)
                      WHERE channel_id = ?',
                    [
                        $complete ? null : $oldestId,
                        $complete ? 1 : 0,
                        $currentHighWater === '' ? null : $currentHighWater,
                        $complete ? 'success' : $phaseStatus,
                        $nextWorkOrder,
                        $complete ? 1 : 0,
                        $channel['channelId'],
                    ]
                );
            } elseif ($phase === 'audit') {
                $complete = count($records) < self::MESSAGE_PAGE_SIZE;
                $auditContentVerified = (int) ($state['audit_content_verified'] ?? 1) === 1
                    && $contentAccessStatus === 'verified';
                $cleanupPending = $complete && $auditContentVerified ? 1 : 0;
                $this->db->execute(
                    'UPDATE academy_material_channel_state
                        SET audit_before_message_id = ?, audit_running = ?,
                            audit_content_verified = ?, audit_cleanup_pending = ?,
                            audit_started_at = IF(? = 1, audit_started_at, NULL),
                            last_audit_at = IF(? = 1, UTC_TIMESTAMP(), last_audit_at),
                            last_page_at = UTC_TIMESTAMP(), last_attempt_at = UTC_TIMESTAMP(),
                            last_success_at = UTC_TIMESTAMP(), last_error_code = NULL,
                            sync_status = ?, work_order = ?
                      WHERE channel_id = ?',
                    [
                        $complete ? null : $oldestId,
                        $complete ? 0 : 1,
                        $auditContentVerified ? 1 : 0,
                        $cleanupPending,
                        (!$complete || $cleanupPending === 1) ? 1 : 0,
                        $complete ? 1 : 0,
                        $complete ? ($cleanupPending === 1 ? 'audit_cleanup' : 'content_unverified') : $phaseStatus,
                        $nextWorkOrder,
                        $channel['channelId'],
                    ]
                );
            } else {
                $current = (string) ($state['incremental_after_message_id'] ?? '');
                if ($newestId !== null && ($current === '' || self::compareSnowflakes($newestId, $current) > 0)) {
                    $current = $newestId;
                }
                $this->db->execute(
                    'UPDATE academy_material_channel_state
                        SET incremental_after_message_id = ?, last_page_at = UTC_TIMESTAMP(),
                            last_attempt_at = UTC_TIMESTAMP(), last_success_at = UTC_TIMESTAMP(),
                            last_error_code = NULL, sync_status = ?, work_order = ?
                      WHERE channel_id = ?',
                    [$current === '' ? null : $current, $phaseStatus, $nextWorkOrder, $channel['channelId']]
                );
            }
        });
    }

    private function persistNormalizedMessage(array $message, ?string $auditStartedAt): void
    {
        $old = $this->db->queryOne(
            'SELECT target_source_message_id, has_media FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
            [$message['channel_id'], $message['message_id']]
        );
        $roots = [$message['message_id'] => true];
        if (!empty($old['target_source_message_id'])) {
            $roots[(string) $old['target_source_message_id']] = true;
        }
        $this->db->execute(
            'INSERT INTO academy_material_messages
                (guild_id, channel_id, message_id, author_id, author_name, created_at_utc,
                 created_at_iso, edited_at_utc, edited_at_iso, reference_message_id,
                 title, description, content_text,
                 material_type, has_media, is_caption_candidate, target_source_message_id,
                 is_deleted, deleted_at, last_audit_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0, NULL, ?)
             ON DUPLICATE KEY UPDATE guild_id = VALUES(guild_id), author_id = VALUES(author_id),
                author_name = VALUES(author_name), created_at_utc = VALUES(created_at_utc),
                created_at_iso = VALUES(created_at_iso), edited_at_utc = VALUES(edited_at_utc),
                edited_at_iso = VALUES(edited_at_iso), reference_message_id = VALUES(reference_message_id),
                title = VALUES(title), description = VALUES(description), content_text = VALUES(content_text),
                material_type = VALUES(material_type), has_media = VALUES(has_media),
                is_caption_candidate = VALUES(is_caption_candidate), target_source_message_id = NULL,
                is_deleted = 0, deleted_at = NULL,
                last_audit_seen_at = COALESCE(VALUES(last_audit_seen_at), last_audit_seen_at)',
            [
                $message['guild_id'], $message['channel_id'], $message['message_id'], $message['author_id'],
                $message['author_name'], $message['created_at_utc'], $message['created_at_iso'],
                $message['edited_at_utc'], $message['edited_at_iso'], $message['reference_message_id'],
                $message['title'], $message['description'],
                $message['content_text'], $message['material_type'], $message['has_media'],
                $message['is_caption_candidate'], $auditStartedAt,
            ]
        );
        $roots += $this->reassociateMessage($message['channel_id'], $message['message_id']);

        $rootRow = $this->db->queryOne(
            'SELECT * FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
            [$message['channel_id'], $message['message_id']]
        );
        if ($rootRow !== null) {
            $params = [$message['channel_id']];
            $conditions = [];
            if ((int) $rootRow['has_media'] === 1) {
                $range = self::localDayUtcRange((string) $rootRow['created_at_utc']);
                $end = min(
                    strtotime($range[1] . ' UTC'),
                    strtotime((string) $rootRow['created_at_utc'] . ' UTC') + $this->recapWindowMinutes * 60
                );
                $conditions[] = '(author_id = ? AND is_caption_candidate = 1 AND created_at_utc >= ? AND created_at_utc <= ?)';
                $params[] = $rootRow['author_id'];
                $params[] = $rootRow['created_at_utc'];
                $params[] = gmdate('Y-m-d H:i:s', $end);
            }
            $conditions[] = 'reference_message_id = ?';
            $params[] = $message['message_id'];
            $candidateRows = $this->db->query(
                'SELECT message_id FROM academy_material_messages
                  WHERE channel_id = ? AND is_deleted = 0 AND message_id <> ? AND (' . implode(' OR ', $conditions) . ')
                  ORDER BY created_at_utc ASC, message_id ASC LIMIT 201',
                [$message['channel_id'], $message['message_id'], ...array_slice($params, 1)]
            );
            foreach ($candidateRows as $candidate) {
                $roots += $this->reassociateMessage($message['channel_id'], (string) $candidate['message_id']);
            }
        }
        foreach (array_keys($roots) as $rootId) {
            $this->refreshMaterialItem($message['channel_id'], (string) $rootId);
        }
    }

    /** @return array<string,bool> affected roots */
    private function reassociateMessage(string $channelId, string $messageId): array
    {
        $row = $this->db->queryOne(
            'SELECT * FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
            [$channelId, $messageId]
        );
        $oldTarget = (string) ($row['target_source_message_id'] ?? '');
        $roots = [$messageId => true];
        if ($oldTarget !== '') {
            $roots[$oldTarget] = true;
        }
        if ($row === null || (int) $row['is_deleted'] === 1) {
            return $roots;
        }
        $target = $this->findCaptionTarget($row);
        $newTarget = $target ?? '';
        if ($newTarget !== $oldTarget) {
            $this->db->execute(
                'UPDATE academy_material_messages SET target_source_message_id = ? WHERE channel_id = ? AND message_id = ?',
                [$target, $channelId, $messageId]
            );
            if ($newTarget !== '') {
                $roots[$newTarget] = true;
            }
        }
        return $roots;
    }

    private function findCaptionTarget(array $caption): ?string
    {
        if ((int) ($caption['has_media'] ?? 0) === 1) {
            return null;
        }
        $channelId = (string) $caption['channel_id'];
        $messageId = (string) $caption['message_id'];
        $captionTime = (string) $caption['created_at_utc'];
        $referenceId = (string) ($caption['reference_message_id'] ?? '');
        if ($referenceId !== '') {
            $referenced = $this->db->queryOne(
                'SELECT * FROM academy_material_messages
                  WHERE channel_id = ? AND message_id = ? AND is_deleted = 0',
                [$channelId, $referenceId]
            );
            if ($referenced !== null && self::sameWarsawDay((string) $referenced['created_at_utc'], $captionTime)) {
                $rootId = (string) ($referenced['target_source_message_id'] ?? '');
                if ($rootId === '') {
                    $rootId = (string) $referenced['message_id'];
                }
                if ($this->rootCanAcceptCaption($channelId, $rootId, $messageId, $captionTime)) {
                    return $rootId;
                }
            }
        }
        if ((int) ($caption['is_caption_candidate'] ?? 0) !== 1) {
            return null;
        }
        $authorId = (string) ($caption['author_id'] ?? '');
        if ($authorId === '') {
            return null;
        }
        $range = self::localDayUtcRange($captionTime);
        $windowStart = max(
            strtotime($range[0] . ' UTC'),
            strtotime($captionTime . ' UTC') - $this->recapWindowMinutes * 60
        );
        $candidates = $this->db->query(
            'SELECT message_id, created_at_utc FROM academy_material_messages
              WHERE channel_id = ? AND author_id = ? AND is_deleted = 0 AND has_media = 1
                AND target_source_message_id IS NULL
                AND created_at_utc >= ? AND created_at_utc < ?
                AND (created_at_utc < ? OR (created_at_utc = ? AND message_id < ?))
              ORDER BY created_at_utc DESC, message_id DESC LIMIT 100',
            [
                $channelId, $authorId, gmdate('Y-m-d H:i:s', $windowStart), $range[1],
                $captionTime, $captionTime, $messageId,
            ]
        );
        foreach ($candidates as $candidate) {
            $candidateId = (string) $candidate['message_id'];
            if ($this->rootCanAcceptCaption($channelId, $candidateId, $messageId, $captionTime)) {
                return $candidateId;
            }
        }
        return null;
    }

    private function rootCanAcceptCaption(string $channelId, string $rootId, string $captionId, string $captionTime): bool
    {
        $root = $this->db->queryOne(
            'SELECT message_id, created_at_utc, description, has_media, is_deleted, target_source_message_id
               FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
            [$channelId, $rootId]
        );
        if ($root === null || (int) $root['is_deleted'] === 1 || (int) $root['has_media'] !== 1
            || !empty($root['target_source_message_id'])
            || !self::sameWarsawDay((string) $root['created_at_utc'], $captionTime)) {
            return false;
        }
        $existing = $this->db->queryOne(
            'SELECT message_id FROM academy_material_messages
              WHERE channel_id = ? AND target_source_message_id = ? AND is_deleted = 0 AND message_id <> ?
              LIMIT 1',
            [$channelId, $rootId, $captionId]
        );
        return $existing === null;
    }

    private function markMessageUnavailable(string $channelId, string $messageId): void
    {
        $existing = $this->db->queryOne(
            'SELECT target_source_message_id, has_media FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
            [$channelId, $messageId]
        );
        if ($existing === null) {
            return;
        }
        $roots = [$messageId => true];
        if (!empty($existing['target_source_message_id'])) {
            $roots[(string) $existing['target_source_message_id']] = true;
        }
        $this->db->execute(
            'UPDATE academy_material_messages
                SET is_deleted = 1, deleted_at = UTC_TIMESTAMP(), target_source_message_id = NULL
              WHERE channel_id = ? AND message_id = ?',
            [$channelId, $messageId]
        );
        $candidates = $this->db->query(
            'SELECT message_id FROM academy_material_messages
              WHERE channel_id = ? AND is_deleted = 0
                AND (reference_message_id = ? OR target_source_message_id = ?)
              ORDER BY created_at_utc ASC, message_id ASC LIMIT 201',
            [$channelId, $messageId, $messageId]
        );
        foreach ($candidates as $candidate) {
            $roots += $this->reassociateMessage($channelId, (string) $candidate['message_id']);
        }
        if ((int) $existing['has_media'] === 1) {
            $deleted = $this->db->queryOne(
                'SELECT created_at_utc, author_id FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
                [$channelId, $messageId]
            );
            if ($deleted !== null) {
                $range = self::localDayUtcRange((string) $deleted['created_at_utc']);
                $candidates = $this->db->query(
                    'SELECT message_id FROM academy_material_messages
                      WHERE channel_id = ? AND is_deleted = 0 AND author_id = ? AND is_caption_candidate = 1
                        AND created_at_utc >= ? AND created_at_utc <= ?
                      ORDER BY created_at_utc ASC, message_id ASC LIMIT 201',
                    [
                        $channelId,
                        $deleted['author_id'],
                        $deleted['created_at_utc'],
                        gmdate('Y-m-d H:i:s', min(
                            strtotime($range[1] . ' UTC'),
                            strtotime((string) $deleted['created_at_utc'] . ' UTC') + $this->recapWindowMinutes * 60
                        )),
                    ]
                );
                foreach ($candidates as $candidate) {
                    $roots += $this->reassociateMessage($channelId, (string) $candidate['message_id']);
                }
            }
        }
        foreach (array_keys($roots) as $rootId) {
            $this->refreshMaterialItem($channelId, (string) $rootId);
        }
    }

    private function refreshMaterialItem(string $channelId, string $sourceMessageId): void
    {
        $root = $this->db->queryOne(
            'SELECT * FROM academy_material_messages WHERE channel_id = ? AND message_id = ?',
            [$channelId, $sourceMessageId]
        );
        if ($root === null || (int) $root['is_deleted'] === 1 || !empty($root['target_source_message_id'])) {
            $this->db->execute(
                'UPDATE academy_material_items SET is_deleted = 1, deleted_at = UTC_TIMESTAMP()
                  WHERE channel_id = ? AND source_message_id = ? AND is_deleted = 0',
                [$channelId, $sourceMessageId]
            );
            return;
        }
        $channel = self::channelMap()[$channelId] ?? null;
        if ($channel === null) {
            return;
        }
        $description = trim((string) ($root['description'] ?? ''));
        $descriptionMessageId = $sourceMessageId;
        $caption = $this->db->queryOne(
            'SELECT message_id, description, content_text FROM academy_material_messages
              WHERE channel_id = ? AND target_source_message_id = ? AND is_deleted = 0
              ORDER BY created_at_utc ASC, message_id ASC LIMIT 1',
            [$channelId, $sourceMessageId]
        );
        if ($caption !== null) {
            $captionDescription = trim((string) ($caption['description'] ?? $caption['content_text'] ?? ''));
            if ($captionDescription !== '') {
                $description = $description === ''
                    ? $captionDescription
                    : $description . "\n\n" . $captionDescription;
                $descriptionMessageId = (string) $caption['message_id'];
            }
        }
        $title = trim((string) ($root['title'] ?? ''));
        if ($title === '') {
            $title = self::deriveTitle((string) ($root['content_text'] ?? $description));
        }
        $this->db->execute(
            'INSERT INTO academy_material_items
                (guild_id, channel_id, source_message_id, description_message_id, category_label,
                 title, description, author_id, author_name, published_at_utc, published_at_iso, material_type,
                 is_deleted, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE guild_id = VALUES(guild_id),
                description_message_id = VALUES(description_message_id),
                category_label = VALUES(category_label), title = VALUES(title),
                description = VALUES(description), author_id = VALUES(author_id),
                author_name = VALUES(author_name), published_at_utc = VALUES(published_at_utc),
                published_at_iso = VALUES(published_at_iso),
                material_type = VALUES(material_type), is_deleted = 0, deleted_at = NULL',
            [
                self::GUILD_ID, $channelId, $sourceMessageId, $descriptionMessageId, $channel['category'],
                $title === '' ? null : self::truncate($title, 250),
                $description === '' ? null : self::truncate($description, 12000),
                $root['author_id'], $root['author_name'], $root['created_at_utc'], $root['created_at_iso'],
                $root['material_type'],
            ]
        );
    }

    private function cleanupAuditBatch(array $channel, array $state): void
    {
        $startedAt = (string) ($state['audit_started_at'] ?? '');
        if ($startedAt === '') {
            $this->db->execute(
                'UPDATE academy_material_channel_state
                    SET audit_cleanup_pending = 0, audit_running = 0, audit_content_verified = 1
                  WHERE channel_id = ?',
                [$channel['channelId']]
            );
            return;
        }
        $rows = $this->db->query(
            'SELECT message_id FROM academy_material_messages
              WHERE channel_id = ? AND is_deleted = 0
                AND (last_audit_seen_at IS NULL OR last_audit_seen_at < ?)
              ORDER BY created_at_utc ASC, message_id ASC LIMIT ' . self::AUDIT_CLEANUP_BATCH,
            [$channel['channelId'], $startedAt]
        );
        if ($rows === []) {
            $this->db->execute(
                'UPDATE academy_material_channel_state
                    SET audit_cleanup_pending = 0, audit_running = 0,
                        audit_started_at = NULL, audit_before_message_id = NULL,
                        last_audit_at = UTC_TIMESTAMP(), last_page_at = UTC_TIMESTAMP(),
                        last_success_at = UTC_TIMESTAMP(), last_error_code = NULL,
                        sync_status = "success", audit_content_verified = 1
                  WHERE channel_id = ?',
                [$channel['channelId']]
            );
            return;
        }
        $this->db->withTransaction(function () use ($channel, $rows): void {
            foreach ($rows as $row) {
                $this->markMessageUnavailable($channel['channelId'], (string) $row['message_id']);
            }
            $this->db->execute(
                'UPDATE academy_material_channel_state
                    SET last_page_at = UTC_TIMESTAMP(), last_success_at = UTC_TIMESTAMP(),
                        sync_status = "audit_cleanup", last_error_code = NULL, work_order = ?
                  WHERE channel_id = ?',
                [$this->nextWorkOrder(), $channel['channelId']]
            );
        });
    }

    private function auditIsDue(array $state): bool
    {
        if ((int) ($state['audit_running'] ?? 0) === 1) {
            return true;
        }
        $last = (string) ($state['last_audit_at'] ?? '');
        return $last === '' || (time() - (strtotime($last . ' UTC') ?: 0)) >= self::AUDIT_INTERVAL_SECONDS;
    }

    private function allSourcesReady(): bool
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(verified = 1 AND backfill_complete = 1
                        AND message_access_status = 'verified' AND content_access_status = 'verified') AS ready_count
               FROM academy_material_channel_state
              WHERE channel_id IN (" . implode(',', array_fill(0, count(self::CHANNELS), '?')) . ')',
            array_column(self::CHANNELS, 'channelId')
        );
        return (int) ($row['total'] ?? 0) === count(self::CHANNELS)
            && (int) ($row['ready_count'] ?? 0) === count(self::CHANNELS);
    }

    private function hasIncompleteBackfill(): bool
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS pending_count
               FROM academy_material_channel_state
              WHERE channel_id IN (' . implode(',', array_fill(0, count(self::CHANNELS), '?')) . ')
                AND backfill_complete = 0',
            array_column(self::CHANNELS, 'channelId')
        );
        return (int) ($row['pending_count'] ?? 0) > 0;
    }

    private function accessProblemStatus(): ?string
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(message_access_status <> 'verified') AS message_unverified_count,
                    SUM(content_access_status <> 'verified') AS content_unverified_count
               FROM academy_material_channel_state
              WHERE channel_id IN (" . implode(',', array_fill(0, count(self::CHANNELS), '?')) . ')',
            array_column(self::CHANNELS, 'channelId')
        );
        if ((int) ($row['total'] ?? 0) !== count(self::CHANNELS)
            || (int) ($row['message_unverified_count'] ?? 0) > 0) {
            return 'message_access_unverified';
        }
        if ((int) ($row['content_unverified_count'] ?? 0) > 0) {
            return 'content_unverified';
        }
        return null;
    }

    private function nextWorkOrder(): int
    {
        $row = $this->db->queryOne(
            'SELECT COALESCE(MAX(work_order), 0) + 1 AS next_order FROM academy_material_channel_state'
        );
        return (int) ($row['next_order'] ?? 1);
    }

    private function discordGet(string $path, array $query = [], bool $expectList = false): array
    {
        if (!self::isApprovedPath($path)) {
            throw new \RuntimeException('unapproved_channel');
        }
        $response = $this->requestWithRetry($path, $query);
        $status = (int) ($response['status'] ?? 0);
        if ($status !== 200) {
            throw new \RuntimeException(self::httpErrorCode($status));
        }
        $body = $response['body'] ?? null;
        if (is_string($body)) {
            $body = json_decode($body, true);
        }
        if (!is_array($body) || ($expectList && !array_is_list($body))) {
            throw new \RuntimeException('invalid_payload');
        }
        return $body;
    }

    private function requestWithRetry(string $path, array $query): array
    {
        $lastCode = 'transport_error';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                if ($this->requester !== null) {
                    $response = ($this->requester)('GET', $path, $query);
                } else {
                    $response = $this->curlGet($path, $query);
                }
            } catch (\Throwable) {
                $lastCode = 'transport_error';
                if ($attempt < 3) {
                    ($this->sleeper)(min(1.0, 0.25 * $attempt));
                    continue;
                }
                throw new \RuntimeException($lastCode);
            }
            if (!is_array($response)) {
                throw new \RuntimeException('invalid_payload');
            }
            $status = (int) ($response['status'] ?? 0);
            if ($status === 429) {
                $retryAfter = self::retryAfterSeconds($response);
                if ($attempt < 3 && $retryAfter <= 5.0) {
                    ($this->sleeper)($retryAfter);
                    continue;
                }
                throw new \RuntimeException('http_429');
            }
            if (($status === 0 || $status >= 500) && $attempt < 3) {
                ($this->sleeper)(min(1.0, 0.25 * $attempt));
                continue;
            }
            if ($status === 0) {
                throw new \RuntimeException('transport_error');
            }
            return $response;
        }
        throw new \RuntimeException($lastCode);
    }

    private function curlGet(string $path, array $query): array
    {
        $base = rtrim((string) ($this->config->discord['apiBase'] ?? 'https://discord.com/api/v10'), '/');
        $url = $base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('transport_error');
        }
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bot ' . (string) $this->config->discord['botToken'],
                'Accept: application/json',
                'User-Agent: AlphaVault/4.2 (https://alphavault.pl)',
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $position = strpos($line, ':');
                if ($position !== false) {
                    $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || strlen((string) $raw) > 5_000_000) {
            return ['status' => 0, 'headers' => $headers, 'body' => null];
        }
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => json_decode((string) $raw, true),
        ];
    }

    private static function isApprovedPath(string $path): bool
    {
        if (preg_match('#^/channels/([0-9]+)(/messages)?$#D', $path, $matches) !== 1) {
            return false;
        }
        return self::isAllowedChannel($matches[1]);
    }

    private function safeErrorCode(\Throwable $err): string
    {
        $message = $err->getMessage();
        if (preg_match('/^(?:http_(?:\d{3}|5xx)|category_mismatch|guild_mismatch|invalid_payload|not_configured|transport_error|unapproved_channel)$/D', $message) === 1) {
            return $message;
        }
        return 'transport_error';
    }

    private static function httpErrorCode(int $status): string
    {
        return $status >= 500 ? 'http_5xx' : ($status > 0 ? 'http_' . $status : 'transport_error');
    }

    private static function retryAfterSeconds(array $response): float
    {
        $headers = [];
        foreach ((array) ($response['headers'] ?? []) as $key => $value) {
            $headers[strtolower((string) $key)] = $value;
        }
        $value = $headers['retry-after'] ?? null;
        if ($value === null && is_array($response['body'] ?? null)) {
            $value = $response['body']['retry_after'] ?? null;
        }
        return is_numeric($value) ? max(0.0, (float) $value) : 1.0;
    }

    private function encodeCursor(array $position, string $category, string $queryHash, int $limit): string
    {
        $payload = json_encode([
            'v' => 1,
            'snapshotId' => (int) $position['snapshotId'],
            'publishedAt' => (string) $position['publishedAt'],
            'channelId' => (string) $position['channelId'],
            'sourceMessageId' => (string) $position['sourceMessageId'],
            'category' => $category,
            'queryHash' => $queryHash,
            'limit' => $limit,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('cursor_error');
        }
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $encoded . '.' . hash_hmac('sha256', $encoded, $this->config->sessionSecret);
    }

    private function decodeCursor(string $cursor, string $category, string $queryHash, int $limit): array
    {
        if (strlen($cursor) > 2048 || preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cursor, $matches) !== 1) {
            throw new HttpException(400, 'invalid_cursor', 'Kursor materiałów jest nieprawidłowy.');
        }
        $expected = hash_hmac('sha256', $matches[1], $this->config->sessionSecret);
        if (!hash_equals($expected, $matches[2])) {
            throw new HttpException(400, 'invalid_cursor', 'Kursor materiałów jest nieprawidłowy.');
        }
        $raw = base64_decode(strtr($matches[1], '-_', '+/'), true);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['v'] ?? null) !== 1
            || ($data['category'] ?? null) !== $category || ($data['queryHash'] ?? null) !== $queryHash
            || (int) ($data['limit'] ?? 0) !== $limit
            || !is_numeric($data['snapshotId'] ?? null)
            || !is_string($data['publishedAt'] ?? null)
            || !is_string($data['channelId'] ?? null)
            || !self::isAllowedChannel($data['channelId'])
            || self::snowflake($data['sourceMessageId'] ?? null) === null) {
            throw new HttpException(400, 'invalid_cursor', 'Kursor materiałów jest nieprawidłowy.');
        }
        return [
            'snapshotId' => (int) $data['snapshotId'],
            'publishedAt' => $data['publishedAt'],
            'channelId' => $data['channelId'],
            'sourceMessageId' => (string) $data['sourceMessageId'],
        ];
    }

    /** @return array<string,array{channelId:string,label:string,category:string,parentCategoryId:?string}> */
    private static function channelMap(): array
    {
        $map = [];
        foreach (self::CHANNELS as $channel) {
            $map[$channel['channelId']] = $channel;
        }
        return $map;
    }

    private static function mediaType(string $mime, string $filename): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif'], true)) {
            return 'image';
        }
        if (in_array($extension, ['mp4', 'mov', 'webm', 'm4v'], true)) {
            return 'video';
        }
        if (in_array($extension, ['mp3', 'wav', 'ogg', 'm4a'], true)) {
            return 'audio';
        }
        return 'file';
    }

    private static function cleanText(string $text): string
    {
        $text = preg_replace('~<?https?://[^\s<>]+>?~iu', '', $text) ?? '';
        $text = preg_replace('~www\.[^\s<>]+~iu', '', $text) ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** @return array{?string,?string} */
    private static function splitTitleDescription(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [null, null];
        }
        $lines = preg_split('/\n/u', $text) ?: [];
        $first = trim((string) ($lines[0] ?? ''));
        $candidate = preg_replace('/^(?:#{1,6}\s+|\*\*)(.*?)(?:\*\*)?$/u', '$1', $first) ?? $first;
        $candidate = trim($candidate, " \t*_#");
        if ($candidate !== '' && self::length($candidate) <= 180 && (count($lines) > 1 || preg_match('/^[^.!?]{3,120}[.!?]\s/u', $candidate) !== 1)) {
            $title = self::truncate($candidate, 180);
            $description = count($lines) > 1 ? trim(implode("\n", array_slice($lines, 1))) : $text;
            return [$title, self::truncate($description, 12000)];
        }
        return [self::truncate($candidate !== '' ? $candidate : $text, 180), self::truncate($text, 12000)];
    }

    private static function deriveTitle(string $text): string
    {
        $text = trim(self::cleanText($text));
        if ($text === '') {
            return '';
        }
        $firstLine = trim((preg_split('/\n/u', $text)[0] ?? $text));
        if ($firstLine !== '') {
            $sentence = preg_split('/(?<=[.!?])\s+/u', $firstLine, 2)[0] ?? $firstLine;
            return self::truncate(trim($sentence), 180);
        }
        return '';
    }

    private static function snowflake(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^[0-9]{1,32}$/D', $value) !== 1) {
            return null;
        }
        return $value;
    }

    /** @param list<string> $ids */
    private static function snowflakeExtremum(array $ids, bool $maximum): ?string
    {
        if ($ids === []) {
            return null;
        }
        $result = $ids[0];
        foreach (array_slice($ids, 1) as $id) {
            $comparison = self::compareSnowflakes($id, $result);
            if (($maximum && $comparison > 0) || (!$maximum && $comparison < 0)) {
                $result = $id;
            }
        }
        return $result;
    }

    private static function compareSnowflakes(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function utcDateTime(mixed $value): ?string
    {
        if (self::validIsoDateTime($value) === null) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function validIsoDateTime(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1
            ? $value
            : null;
    }

    private static function parseUtc(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sameWarsawDay(string $leftUtc, string $rightUtc): bool
    {
        $left = self::parseUtc($leftUtc);
        $right = self::parseUtc($rightUtc);
        if ($left === null || $right === null) {
            return false;
        }
        $zone = new \DateTimeZone(self::TZ);
        return $left->setTimezone($zone)->format('Y-m-d') === $right->setTimezone($zone)->format('Y-m-d');
    }

    /** @return array{string,string} UTC day-start and exclusive next-day boundary */
    private static function localDayUtcRange(string $utc): array
    {
        $date = self::parseUtc($utc);
        if ($date === null) {
            return [$utc, $utc];
        }
        $zone = new \DateTimeZone(self::TZ);
        $localStart = $date->setTimezone($zone)->setTime(0, 0);
        $localEnd = $localStart->modify('+1 day');
        $utcZone = new \DateTimeZone('UTC');
        return [
            $localStart->setTimezone($utcZone)->format('Y-m-d H:i:s'),
            $localEnd->setTimezone($utcZone)->format('Y-m-d H:i:s'),
        ];
    }

    private static function formatIsoUtc(string $date): string
    {
        $parsed = self::parseUtc($date);
        return $parsed?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') ?? '';
    }

    private static function formatMaybeUtc(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }
        return self::formatIsoUtc($date);
    }

    private static function truncate(string $text, int $length): string
    {
        return self::length($text) > $length
            ? (function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length))
            : $text;
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
