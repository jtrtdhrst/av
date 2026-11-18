<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once __DIR__ . '/mcp-test-environment.php';
mcpLoadIsolatedCiConfig($root);
[$config, $db] = require $root . '/app/bootstrap.php';
require_once $root . '/app/DiscordEvents.php';

use AlphaVault\DiscordEvents;

$guild = (string) ($config->discord['guildId'] ?? '');
$db->execute('DELETE FROM discord_scheduled_events WHERE guild_id = ?', [$guild]);

$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $utc);
// Wydarzenie "live" jest 2 h do przodu, ale zawsze w bieżącym tygodniu Europe/Warsaw.
// Harmonogram tygodnia w UI pokazuje domyślnie bieżący tydzień, a bez tej klamry
// wydarzenie z niedzielnego wieczoru wpadało w poniedziałek następnego tygodnia
// i siatka tygodnia była pusta (fałszywy FAIL w niedziele 22:00-24:00).
$warsaw = new DateTimeZone('Europe/Warsaw');
$nowLocal = new DateTimeImmutable('now', $warsaw);
$liveLocal = $nowLocal->modify('+2 hours');
$weekEnd = $nowLocal->modify('next monday')->setTime(0, 0)->modify('-1 minute');
if ($liveLocal > $weekEnd) {
    $liveLocal = $weekEnd;
}
if ($liveLocal <= $nowLocal) {
    $liveLocal = $nowLocal->modify('+1 minute');
}
$liveStart = $liveLocal->setTimezone($utc);
$liveEnd = $liveStart->modify('+1 hour');
$consultStart = $now->modify('+2 days')->setTime(17, 0);
$consultEnd = $consultStart->modify('+1 hour');
$externalStart = $now->modify('+3 days')->setTime(18, 0);

$fixture = [
    [
        'id' => '1570153900022173746', 'guild_id' => $guild, 'name' => 'AT - Marcin - AKADEMIA',
        'description' => 'Analiza rynku', 'scheduled_start_time' => $liveStart->format(DATE_ATOM),
        'scheduled_end_time' => $liveEnd->format(DATE_ATOM), 'status' => 1, 'entity_type' => 2,
        'channel_id' => '111', 'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true],
        'user_count' => 8, 'entity_metadata' => null,
    ],
    [
        'id' => '1570153900022173747', 'guild_id' => $guild, 'name' => 'Konsultacja 1:1 - Marcin',
        'description' => 'Konsultacja indywidualna', 'scheduled_start_time' => $consultStart->format(DATE_ATOM),
        'scheduled_end_time' => $consultEnd->format(DATE_ATOM), 'status' => 1, 'entity_type' => 2,
        'channel_id' => '112', 'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true],
        'user_count' => 3, 'entity_metadata' => null,
    ],
    [
        'id' => '1570153900022173748', 'guild_id' => $guild, 'name' => 'Wydarzenie zewnętrzne - Hubert',
        'description' => 'Spotkanie', 'scheduled_start_time' => $externalStart->format(DATE_ATOM),
        'status' => 1, 'entity_type' => 3, 'channel_id' => null,
        'creator' => ['id' => '9', 'username' => 'bot', 'bot' => true], 'user_count' => 3,
        'entity_metadata' => ['location' => 'Discord'],
    ],
];

$sync = new DiscordEvents($config, $db);
$result = $sync->syncPayload($fixture);
echo 'seeded=' . $result['events_count'] . "\n";
