<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once __DIR__ . '/mcp-test-environment.php';
$config = mcpLoadIsolatedCiConfig($root);
[, $db] = require $root . '/app/bootstrap.php';

$db->execute("DELETE FROM rate_limits WHERE bucket LIKE 'mcp-%'");
$db->execute("DELETE FROM macro_events WHERE external_id = 'mcp-e2e-macro'");
$start = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$start = $start->setTime(12, 0, 0);
$db->execute(
    'INSERT INTO macro_events (external_id, source, name, description, currency, impact, starts_at_utc, starts_at_iso, forecast, previous, first_seen_at, last_seen_at, updated_at, is_deleted)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)',
    [
        'mcp-e2e-macro',
        'forexfactory',
        'MCP E2E CPI',
        'Dane testowe kalendarza makro.',
        'USD',
        3,
        $start->format('Y-m-d H:i:s'),
        $start->format('Y-m-d\TH:i:s\Z'),
        '3.1%',
        '3.0%',
    ]
);

$demo = json_decode((string) file_get_contents($root . '/db/demo-state.json'), true);
if (!is_array($demo)) {
    fwrite(STDERR, "Brak demo-state.json\n");
    exit(1);
}

/**
 * Fixture MCP: daty relatywne do dziś, żeby testy narzędzi analitycznych
 * (plan dnia, ryzyko, prop, korelacje) miały deterministyczne dane.
 */
$tz = new DateTimeZone('Europe/Warsaw');
$today = new DateTimeImmutable('now', $tz);
$dayKey = static fn (int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
$at = static function (int $offset, int $hour, int $minute = 0) use ($today, $tz): string {
    return $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
        ->setTime($hour, $minute, 0)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s.000\Z');
};
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

$analytics = $demo;
$analytics['settings']['safetyEnabled'] = true;
$analytics['settings']['maxLosses'] = 2;
$analytics['settings']['maxDailyLoss'] = 3;
$analytics['settings']['maxRisk'] = 1;
$analytics['settings']['block'] = 'midnight';
$analytics['settings']['requireReason'] = true;
$analytics['settings']['rules'] = ['Max 1% ryzyka na transakcję', 'Zawsze stop loss', 'Po 2 stratach koniec dnia'];
$analytics['settings']['checklist'] = ['Trend wyższego TF', 'Poziom z planu', 'Potwierdzenie', 'SL przed wejściem', 'R:R >= 2', 'Brak makro 15 min', 'Spokojna głowa'];
$analytics['overrides'] = [];

$analytics['plans'] = [
    $dayKey(0) => [
        'date' => $dayKey(0),
        'market' => 'BTC',
        'markets' => ['BTC'],
        'bias' => 'long',
        'why' => 'D1 w trendzie, retest poziomu po wybiciu.',
        'levels' => [
            ['type' => 'Wsparcie', 'price' => '68000', 'note' => 'strefa retestu'],
            ['type' => 'Opór', 'price' => '71200', 'note' => 'szczyt'],
        ],
        'ifMain' => 'retest i zamknięcie M15 nad',
        'thenMain' => 'long, SL 67500',
        'ifAlt' => 'zamknięcie H1 pod 67500',
        'thenAlt' => 'bez longów do jutra',
        'risk' => '1%',
        'maxTrades' => '3',
        'until' => '21:00',
        'notDo' => 'Nie gram altcoinów i 15 minut przed danymi.',
        'approvedAt' => null,
    ],
    $dayKey(-1) => [
        'date' => $dayKey(-1),
        'market' => 'ETH',
        'markets' => ['ETH'],
        'bias' => 'short',
        'why' => 'Odrzucenie oporu na H1.',
        'levels' => [['type' => 'Opór', 'price' => '3600', 'note' => '']],
        'ifMain' => 'odrzucenie 3600',
        'thenMain' => 'short, SL 3640',
        'ifAlt' => '',
        'thenAlt' => '',
        'risk' => '1%',
        'maxTrades' => '2',
        'until' => '18:00',
        'notDo' => '',
        'approvedAt' => $today->modify('-1 days')->setTime(8, 10)->getTimestamp() * 1000,
    ],
    $dayKey(-2) => [
        'date' => $dayKey(-2),
        'market' => 'SOL',
        'bias' => 'long',
        'why' => 'Szkic bez zatwierdzenia.',
        'levels' => [],
        'ifMain' => '',
        'thenMain' => '',
        'ifAlt' => '',
        'thenAlt' => '',
        'risk' => '1%',
        'maxTrades' => '2',
        'until' => '20:00',
        'notDo' => '',
        'approvedAt' => null,
    ],
    $dayKey(-5) => [
        'date' => $dayKey(-5),
        'market' => 'BTC',
        'markets' => ['BTC'],
        'bias' => 'long',
        'why' => 'Plan na dzień z nadmierną liczbą wejść.',
        'levels' => [],
        'ifMain' => 'retest',
        'thenMain' => 'long',
        'ifAlt' => '',
        'thenAlt' => '',
        'risk' => '1%',
        'maxTrades' => '3',
        'until' => '19:00',
        'notDo' => '',
        'approvedAt' => $today->modify('-5 days')->setTime(8, 0)->getTimestamp() * 1000,
    ],
];

$trade = static function (array $overrides): array {
    return array_merge([
        'id' => 'fx_' . bin2hex(random_bytes(4)),
        'date' => gmdate('c'),
        'pair' => 'BTC/USDT',
        'dir' => 'l',
        'entry' => '100',
        'exit' => '99',
        'sl' => '98',
        'tp' => '106',
        'size' => '1',
        'result' => -1,
        'r' => -1,
        'grade' => 'A',
        'checklist' => 7,
        'checkTotal' => 7,
        'errors' => [],
        'emotion' => 'Spokój',
        'note' => '',
        'mentor' => '',
        'setup' => 'Retest wybicia',
        'session' => 'Londyn',
        'mae' => 0.2,
        'mfe' => 0.4,
        'durationMin' => 45,
        'fee' => 1,
        'plan' => false,
        'source' => 'Ręcznie',
        'account' => 'WEEX',
        'screens' => [],
    ], $overrides);
};

$fixtureTrades = [
    // Dziś: dwie straty (limit 2) i transakcja po blokadzie.
    $trade(['id' => 'fx_today_loss_1', 'date' => $at(0, 9, 0), 'result' => -30, 'r' => -1, 'entry' => '100', 'exit' => '97', 'sl' => '97', 'size' => '10', 'grade' => 'A', 'checklist' => 7, 'checkTotal' => 7]),
    $trade(['id' => 'fx_today_loss_2', 'date' => $at(0, 10, 0), 'result' => -40, 'r' => -1, 'entry' => '100', 'exit' => '96', 'sl' => '96', 'size' => '10', 'grade' => 'B', 'checklist' => 4, 'checkTotal' => 7, 'emotion' => 'FOMO', 'errors' => ['Wejście za wcześnie']]),
    $trade(['id' => 'fx_today_after_lock', 'date' => $at(0, 10, 20), 'result' => -10, 'r' => -1, 'entry' => '100', 'exit' => '99', 'sl' => '99', 'size' => '10', 'grade' => 'C', 'checklist' => 2, 'checkTotal' => 7, 'errors' => ['Wejście po stracie'], 'emotion' => 'Złość']),
    $trade(['id' => 'fx_today_open', 'date' => $at(0, 11, 0), 'pair' => 'ETH/USDT', 'entry' => '3400', 'exit' => 'otwarta', 'sl' => '3350', 'tp' => '3520', 'size' => '0.5', 'result' => null, 'r' => null, 'grade' => 'B', 'checklist' => 6, 'checkTotal' => 7, 'setup' => 'Pullback w trendzie']),
    // Wczoraj: transakcja zgodna z zatwierdzonym planem ETH short.
    $trade(['id' => 'fx_aligned_short', 'date' => $at(-1, 0, 30), 'pair' => 'ETH/USDT', 'dir' => 's', 'entry' => '3600', 'exit' => '3520', 'sl' => '3640', 'tp' => '3480', 'size' => '0.5', 'result' => 40, 'r' => 2, 'grade' => 'A', 'checklist' => 7, 'checkTotal' => 7, 'setup' => 'Odrzucenie oporu', 'screens' => ['before' => $png, 'after' => $png]]),
    // Dwa dni temu: plan w szkicu, więc zgodności nie da się ustalić.
    $trade(['id' => 'fx_draft_plan', 'date' => $at(-2, 14, 0), 'pair' => 'SOL/USDT', 'entry' => '160', 'exit' => '157', 'sl' => '157', 'size' => '5', 'result' => -15, 'r' => -0.75, 'grade' => 'C', 'checklist' => 3, 'checkTotal' => 7, 'setup' => 'Range', 'errors' => ['Brak setupu']]),
    // Trzy dni temu: brak planu.
    $trade(['id' => 'fx_no_plan', 'date' => $at(-3, 15, 0), 'pair' => 'AVAX/USDT', 'entry' => '27', 'exit' => '28', 'sl' => '26.5', 'size' => '10', 'result' => 10, 'r' => 2, 'grade' => 'B', 'checklist' => 5, 'checkTotal' => 7, 'setup' => 'Retest wybicia']),
    // Pięć dni temu: przekroczony limit transakcji, ryzyko i handel po stracie.
    $trade(['id' => 'fx_over_1', 'date' => $at(-5, 9, 0), 'result' => -20, 'r' => -1, 'entry' => '100', 'exit' => '98', 'sl' => '98', 'size' => '10', 'grade' => 'A', 'checklist' => 7, 'checkTotal' => 7]),
    $trade(['id' => 'fx_over_2', 'date' => $at(-5, 9, 10), 'result' => -60, 'r' => -1, 'entry' => '100', 'exit' => '96', 'sl' => '96', 'size' => '15', 'grade' => 'C', 'checklist' => 2, 'checkTotal' => 7, 'errors' => ['Za duża pozycja'], 'emotion' => 'Chciwość']),
    $trade(['id' => 'fx_over_3', 'date' => $at(-5, 12, 0), 'result' => 30, 'r' => 1.5, 'entry' => '100', 'exit' => '102', 'sl' => '98', 'size' => '15', 'grade' => 'B', 'checklist' => 6, 'checkTotal' => 10]),
    $trade(['id' => 'fx_over_4', 'date' => $at(-5, 16, 0), 'result' => -25, 'r' => -0.8, 'entry' => '100', 'exit' => '98.3', 'sl' => '98', 'size' => '13', 'grade' => 'C', 'checklist' => 3, 'checkTotal' => 7]),
    // Siedem dni temu: transakcja bez stop lossa.
    $trade(['id' => 'fx_no_sl', 'date' => $at(-7, 13, 0), 'result' => -18, 'r' => null, 'entry' => '100', 'exit' => '98.2', 'sl' => 'brak', 'size' => '10', 'grade' => 'C', 'checklist' => 1, 'checkTotal' => 10, 'errors' => ['Bez SL']]),
];
$analytics['trades'] = array_values(array_merge($analytics['trades'], $fixtureTrades));
$analytics['trades'] = array_values(array_filter($analytics['trades'], static fn ($row): bool => is_array($row)));
usort($analytics['trades'], static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

$analytics['tradeReviews'] = [
    'fx_aligned_short' => [
        'grade' => 'A',
        'setup' => 'Odrzucenie oporu',
        'emotion' => 'Skupienie',
        'errors' => [],
        'note' => 'Wejście zgodne z planem, SL przed wejściem.',
        'checklist' => 7,
        'recommendations' => ['Utrzymaj zasadę SL przed wejściem.'],
    ],
    'fx_today_loss_1' => [
        'grade' => 'C',
        'setup' => 'Retest wybicia',
        'emotion' => 'Złość',
        'errors' => ['Za duża pozycja'],
        'note' => 'Pozycja za duża względem planu.',
        'checklist' => 3,
    ],
];

$analytics['checkins'] = [
    ['date' => $dayKey(0), 'mood' => 'Spokój', 'energy' => 2, 'note' => 'Mało snu.', 'score' => 45],
    ['date' => $dayKey(-1), 'mood' => 'Skupienie', 'energy' => 4, 'note' => '', 'score' => 78],
    ['date' => $dayKey(-2), 'mood' => 'Zmęczenie', 'energy' => 2, 'note' => '', 'score' => 32],
    ['date' => $dayKey(-5), 'mood' => 'FOMO', 'energy' => 3, 'note' => '', 'score' => 28],
    ['date' => $dayKey(-7), 'mood' => 'Nuda', 'energy' => 3, 'note' => '', 'score' => 47],
];

foreach ($analytics['setups'] as $index => $setup) {
    if (!is_array($setup)) {
        continue;
    }
    $analytics['setups'][$index]['createdAt'] = $today->modify('-' . (30 + $index) . ' days')->format('c');
    $analytics['setups'][$index]['updatedAt'] = $today->modify('-' . (10 + $index) . ' days')->format('c');
    if (($setup['name'] ?? '') === 'Retest wybicia') {
        $analytics['setups'][$index]['description'] = 'Wybicie poziomu z planu i wejście na retest.';
        $analytics['setups'][$index]['confirmation'] = 'Zamknięcie M15 nad poziomem.';
        $analytics['setups'][$index]['invalidation'] = 'Powrót ceny pod poziom.';
        $analytics['setups'][$index]['stopLoss'] = 'Pod knotem retestu';
        $analytics['setups'][$index]['minRR'] = 2.0;
        $analytics['setups'][$index]['exitRules'] = ['TP na następnym oporze', 'Przy 1R przesuń SL na wejście'];
        $analytics['setups'][$index]['examples'] = ['BTC 68 500 retest 2026-10-28'];
    }
}

$users = [
    'mcp-e2e-a' => ['name' => 'MCP E2E A', 'state' => $analytics],
    'mcp-e2e-b' => ['name' => 'MCP E2E B', 'state' => $demo],
];
$users['mcp-e2e-a']['state']['trades'][] = [
    'id' => 'ex1',
    'date' => '2026-11-12T09:00:00.000Z',
    'pair' => 'BTC/USDT',
    'dir' => 'l',
    'entry' => '100',
    'exit' => '110',
    'sl' => '90',
    'tp' => '120',
    'size' => '1',
    'result' => 10,
    'r' => 1,
    'grade' => 'A',
    'checklist' => 7,
    'checkTotal' => 7,
    'errors' => [],
    'emotion' => 'Spokój',
    'note' => 'Import giełdowy E2E',
    'mentor' => 'Test',
    'setup' => 'Pullback w trendzie',
    'session' => 'Londyn',
    'mae' => 0.1,
    'mfe' => 1.2,
    'durationMin' => 60,
    'fee' => 1,
    'plan' => true,
    'source' => 'OKX',
    'account' => 'WEEX',
    'screens' => [],
];
$users['mcp-e2e-b']['state']['trades'] = [[
    'id' => 'trade-b-only',
    'date' => '2026-11-12T09:00:00.000Z',
    'pair' => 'BTC/USDT',
    'dir' => 'l',
    'entry' => '60000',
    'exit' => '61000',
    'sl' => '59000',
    'tp' => '63000',
    'size' => '1',
    'result' => 1000,
    'r' => 1,
    'grade' => 'A',
    'checklist' => 7,
    'checkTotal' => 7,
    'errors' => [],
    'emotion' => 'Skupienie',
    'note' => 'Tylko konto B',
    'mentor' => 'Test',
    'setup' => 'Retest wybicia',
    'session' => 'Londyn',
    'mae' => 0.1,
    'mfe' => 1.2,
    'durationMin' => 60,
    'fee' => 1,
    'plan' => true,
    'source' => 'TEST',
    'account' => 'WEEX',
    'screens' => [],
]];

$result = [];
foreach ($users as $discordId => $data) {
    $row = $db->queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [$discordId]);
    if ($row === null) {
        $db->execute(
            'INSERT INTO users (discord_id, username, global_name, email, is_demo, plan, access_until, role_verified, last_login_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, 1, CURRENT_TIMESTAMP)',
            [$discordId, $discordId, $data['name'], $discordId . '@example.test', 'full', '2099-12-31']
        );
        $userId = $db->lastInsertId();
    } else {
        $userId = (int) $row['id'];
    }

    $json = json_encode($data['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->execute(
        'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE state = VALUES(state), revision = revision + 1, bytes = VALUES(bytes)',
        [$userId, $json, strlen($json)]
    );

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->execute(
        'INSERT INTO sessions (id, user_id, remember, user_agent, ip, expires_at) VALUES (?, ?, 1, ?, ?, UTC_TIMESTAMP() + INTERVAL 2 DAY)',
        [hash('sha256', $token), $userId, 'mcp-e2e', '127.0.0.1']
    );
    $result[$discordId] = ['userId' => $userId, 'session' => $token];
    if ($discordId === 'mcp-e2e-a') {
        $db->execute(
            'INSERT INTO exchange_imports (user_id, provider, external_id, trade_id) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE trade_id = VALUES(trade_id)',
            [$userId, 'okx', 'mcp-e2e-ext-ex1', 'ex1']
        );
    }
}

$target = $root . '/tests/mcp-test-sessions.json';
file_put_contents($target, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($target, 0600);
fwrite(STDOUT, "MCP E2E users ready\n");
