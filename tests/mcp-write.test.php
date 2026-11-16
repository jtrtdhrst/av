<?php
declare(strict_types=1);

/**
 * Testy kontraktowe silnika zapisu MCP (bez HTTP i bez bazy).
 *
 * Sprawdza rejestr operacji, routing zgody człowieka, schematy wejścia oraz
 * walidację i formuły `WriteEngine::apply()` na czystym stanie.
 *
 * Uruchom: php tests/mcp-write.test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AlphaVault\Mcp\Tools\WriteSchemas;
use AlphaVault\Mcp\Write\WriteEngine;
use AlphaVault\Mcp\Write\WriteException;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$name}" . ($info !== '' ? "  {$info}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($info !== '' ? "  {$info}" : '') . "\n";
    }
}

$dayKey = static fn (int $offset): string => (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))
    ->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
    ->format('Y-m-d');
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

// --- Schematy wejścia -------------------------------------------------
$schemaMethods = [];
foreach ((new ReflectionClass(WriteSchemas::class))->getMethods(ReflectionMethod::IS_STATIC) as $method) {
    if (str_starts_with($method->getName(), 'input')) {
        $schemaMethods[] = $method->getName();
    }
}
sort($schemaMethods);
check('Schematy: 33 narzędzia zapisu', count($schemaMethods) === 33, (string) count($schemaMethods));

$schemaProblems = [];
foreach ($schemaMethods as $method) {
    $schema = WriteSchemas::{$method}();
    if (!in_array('idempotencyKey', $schema['required'] ?? [], true)) {
        $schemaProblems[] = $method . ':idempotencyKey';
    }
    if (($schema['additionalProperties'] ?? null) !== false) {
        $schemaProblems[] = $method . ':additionalProperties';
    }
}
check('Schematy: idempotencyKey i zamknięty kontrakt', $schemaProblems === [], implode(', ', $schemaProblems));
check(
    'Schematy: approve_proposal wymaga kodu i hash',
    in_array('confirmationCode', WriteSchemas::inputApproveProposal()['required'], true)
    && in_array('expectedDiffHash', WriteSchemas::inputApproveProposal()['required'], true)
);

// --- Rejestr operacji i routing zgody --------------------------------
$operations = WriteEngine::OPERATIONS;
check('Operacje: 30 unikalnych', count($operations) === 30 && count(array_unique($operations)) === 30, (string) count($operations));

$expectedApproval = [
    'trade.delete', 'review.delete', 'setup.delete', 'plan.delete', 'plan.approve',
    'checkin.delete', 'account.create', 'account.update', 'account.delete',
    'flow.create', 'flow.update', 'flow.delete', 'risk.update',
    'prop.create', 'prop.update', 'prop.delete', 'screenshot.delete', 'restore.record',
];
$approval = WriteEngine::APPROVAL_REQUIRED;
sort($approval);
sort($expectedApproval);
check('Operacje: 18 wymaga zgody człowieka', $approval === $expectedApproval, implode(', ', $approval));
$directOps = array_values(array_diff($operations, $approval));
check('Operacje: 12 bezpośrednich', count($directOps) === 12, implode(', ', $directOps));
$routingProblems = [];
foreach ($approval as $op) {
    if (!WriteEngine::requiresApproval($op)) {
        $routingProblems[] = $op . ':brak-zgody';
    }
}
foreach ($directOps as $op) {
    if (WriteEngine::requiresApproval($op)) {
        $routingProblems[] = $op . ':zbedna-zgoda';
    }
}
check('Operacje: routing zgodny z listą', $routingProblems === [], implode(', ', $routingProblems));
check('Operacje: decyzje nie są operacjami zapisu', !in_array('approve_proposal', $operations, true) && !in_array('reject_proposal', $operations, true));

// --- Silnik na czystym stanie (bez DB) -------------------------------
$engine = (new ReflectionClass(WriteEngine::class))->newInstanceWithoutConstructor();
$apply = static fn (array $state, string $op, array $args): array => $engine->apply(1, $state, $op, $args);
$errorOf = static function (array $state, string $op, array $args) use ($engine): string {
    try {
        $engine->apply(1, $state, $op, $args);
    } catch (WriteException $err) {
        return $err->errorCode;
    }

    return '';
};
$base = static fn (): array => [
    'trades' => [], 'setups' => [], 'checkins' => [], 'accounts' => [], 'flows' => [],
    'plans' => [], 'tradeReviews' => [], 'trash' => [],
    'settings' => [
        'safetyEnabled' => true, 'requireReason' => true, 'pushLimit' => true,
        'maxRisk' => 1, 'maxLosses' => 2, 'maxDailyLoss' => 3, 'block' => 'midnight',
        'rules' => ['Max 1% ryzyka', 'Zawsze stop loss'],
        'checklist' => ['Trend', 'Poziom', 'Potwierdzenie'],
    ],
    'prop' => ['challenges' => [], 'selectedId' => null],
];
$withAccount = static function () use ($base): array {
    $state = $base();
    $state['accounts'][] = ['id' => 'acc1', 'name' => 'WEEX', 'start' => 1000, 'balance' => 1000, 'primary' => true];

    return $state;
};

// Check-in
$mood = $apply($base(), 'checkin.create', ['date' => $dayKey(0), 'mood' => 'Skupienie', 'energy' => 5, 'note' => '']);
check('Check-in: wynik 77', $mood['result']['score'] === 77, (string) $mood['result']['score']);
$calm = $apply($base(), 'checkin.create', ['date' => $dayKey(0), 'mood' => 'Spokój', 'energy' => 3]);
check('Check-in: wynik 60', $calm['result']['score'] === 60, (string) $calm['result']['score']);
$upsert = $apply($mood['state'], 'checkin.create', ['date' => $dayKey(0), 'mood' => 'Spokój', 'energy' => 3]);
check('Check-in: kolejny wpis w tym dniu nadpisuje', count($upsert['state']['checkins']) === 1 && $upsert['state']['checkins'][0]['score'] === 60);
check('Check-in: energy poza zakresem', $errorOf($base(), 'checkin.create', ['energy' => 9]) === 'invalid_energy');
check('Check-in: nieznany nastrój', $errorOf($base(), 'checkin.create', ['mood' => 'Euforia']) === 'invalid_mood');
check('Check-in: edycja nieznanego pola', $errorOf($mood['state'], 'checkin.update', ['date' => $dayKey(0), 'changes' => ['score' => 1]]) === 'field_not_editable');

// Plan dnia
$planState = $apply($base(), 'plan.create', [
    'date' => $dayKey(-3), 'markets' => ['BTC', 'ETH'], 'bias' => 'long',
    'levels' => ['68000', ['value' => '67500', 'note' => 'retest']],
    'risk' => '0.5%', 'maxTrades' => 2, 'until' => '20:00',
])['state'];
$plan = $planState['plans'][$dayKey(-3)];
check('Plan: market wynika z markets', $plan['market'] === 'BTC');
check('Plan: levels znormalizowane', $plan['levels'][0] === ['value' => '68000', 'note' => ''] && $plan['levels'][1]['note'] === 'retest');
check('Plan: szkic bez approvedAt', !array_key_exists('approvedAt', $plan));
check('Plan: duplikat daty', $errorOf($planState, 'plan.create', ['date' => $dayKey(-3)]) === 'plan_exists');
check('Plan: until walidacja', $errorOf($base(), 'plan.create', ['date' => $dayKey(-4), 'until' => '25:00']) === 'invalid_until');
$planUpdated = $apply($planState, 'plan.update', ['date' => $dayKey(-3), 'changes' => ['risk' => '0.25%']]);
check('Plan: update zachowuje rynki', $planUpdated['state']['plans'][$dayKey(-3)]['markets'] === ['BTC', 'ETH'] && $planUpdated['state']['plans'][$dayKey(-3)]['risk'] === '0.25%');
check('Plan: update blokuje approvedAt', $errorOf($planState, 'plan.update', ['date' => $dayKey(-3), 'changes' => ['approvedAt' => 1]]) === 'field_not_editable');
$planApproved = $apply($planUpdated['state'], 'plan.approve', ['date' => $dayKey(-3)]);
check('Plan: approve ustawia approvedAt', is_int($planApproved['result']['approvedAt']));
check('Plan: approve diff tylko approvedAt', array_column($planApproved['changes'], 'field') === ['approvedAt']);
check('Plan: approve bez planu', $errorOf($base(), 'plan.approve', ['date' => $dayKey(-9)]) === 'plan_missing');

// Recenzje
$reviewState = $withAccount();
$reviewState['trades'][] = ['id' => 't1', 'pair' => 'BTC/USDT', 'dir' => 'l', 'entry' => '100', 'size' => '1', 'date' => '2026-01-02T12:00:00.000Z'];
$review = $apply($reviewState, 'review.create', ['tradeId' => 't1', 'grade' => 'A', 'note' => 'E2E', 'recommendations' => ['R1']]);
check('Recenzja: zapis', $review['result']['grade'] === 'A' && $review['result']['recommendations'] === ['R1']);
check('Recenzja: duplikat', $errorOf($review['state'], 'review.create', ['tradeId' => 't1']) === 'review_exists');
check('Recenzja: nieznane pole', $errorOf($review['state'], 'review.update', ['tradeId' => 't1', 'changes' => ['id' => 'x']]) === 'field_not_editable');
check('Recenzja: brak transakcji', $errorOf($reviewState, 'review.create', ['tradeId' => 'brak']) === 'trade_missing');

// Setupy
$setup = $apply($base(), 'setup.create', ['name' => 'S1', 'rules' => ['A', 'B']]);
check('Setup: tworzenie', $setup['result']['id'] !== '' && $setup['result']['rules'] === ['A', 'B']);
check('Setup: minimum reguł', $errorOf($base(), 'setup.create', ['name' => 'S2', 'rules' => ['A']]) === 'invalid_setup_rules');
check('Setup: duplikat nazwy', $errorOf($setup['state'], 'setup.create', ['name' => 's1', 'rules' => ['A', 'B']]) === 'setup_exists');
$s3 = $apply($setup['state'], 'setup.create', ['name' => 'S3', 'rules' => ['A', 'B']]);
check(
    'Setup: duplikat przy zmianie nazwy',
    $errorOf($s3['state'], 'setup.update', ['setupId' => $s3['result']['id'], 'changes' => ['name' => 'S1']]) === 'setup_exists'
);

// Transakcje, kosz i przywracanie
$trade = $apply($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 2,
    'sl' => 95, 'tp' => 120, 'date' => '2026-01-02', 'account' => 'WEEX',
]);
check(
    'Transakcja: tworzenie',
    $trade['result']['source'] === 'Ręcznie' && $trade['result']['exit'] === 'otwarta'
    && $trade['result']['sl'] === '95' && $trade['result']['result'] === null
);
check('Transakcja: setup spoza Playbooka', $errorOf($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'setup' => 'Nie ma takiego',
]) === 'unknown_setup');
check('Transakcja: konto spoza listy', $errorOf($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'account' => 'Nie ma',
]) === 'unknown_account');
$tradeId = $trade['result']['id'];
$deleted = $apply($trade['state'], 'trade.delete', ['tradeId' => $tradeId]);
check(
    'Transakcja: delete do kosza',
    $deleted['state']['trades'] === [] && $deleted['state']['trash'][0]['kind'] === 'trade'
    && $deleted['result']['trashId'] !== ''
);
$restored = $apply($deleted['state'], 'restore.record', ['trashId' => $deleted['result']['trashId']]);
check('Kosz: przywracanie', count($restored['state']['trades']) === 1 && $restored['state']['trash'] === []);
check('Kosz: brak wpisu', $errorOf($base(), 'restore.record', ['trashId' => 'tr_brak']) === 'trash_missing');
$conflict = $deleted['state'];
$conflict['trades'][] = $trade['result'];
check('Kosz: konflikt identyfikatora', $errorOf($conflict, 'restore.record', ['trashId' => $deleted['result']['trashId']]) === 'restore_conflict');

// Screeny
$shot = $apply($trade['state'], 'screenshot.add', ['tradeId' => $tradeId, 'dataUrl' => $png, 'position' => 'before']);
check('Screen: dodanie', $shot['result']['bytes'] > 0 && $shot['result']['position'] === 'before');
check('Screen: zły data URL', $errorOf($trade['state'], 'screenshot.add', ['tradeId' => $tradeId, 'dataUrl' => 'nie-obraz']) === 'invalid_screenshot');
check('Screen: brak przed usunięciem', $errorOf($trade['state'], 'screenshot.delete', ['tradeId' => $tradeId, 'position' => 'before']) === 'screenshot_missing');
$shotDeleted = $apply($shot['state'], 'screenshot.delete', ['tradeId' => $tradeId, 'position' => 'before']);
check('Screen: usunięcie do kosza', $shotDeleted['state']['trash'][0]['kind'] === 'screenshot');

// Konta i wpłaty
$account = $apply($base(), 'account.create', ['name' => 'K1', 'start' => 1000, 'currency' => 'USDT']);
check('Konto: tworzenie', (float) $account['result']['start'] === 1000.0 && $account['result']['currency'] === 'USDT');
check('Konto: duplikat nazwy', $errorOf($account['state'], 'account.create', ['name' => 'k1']) === 'account_exists');
check('Konto: waluta', $errorOf($base(), 'account.create', ['name' => 'K2', 'currency' => 'GBP']) === 'invalid_currency');
check('Konto: zmiana nieznanego pola', $errorOf($account['state'], 'account.update', ['accountId' => $account['result']['id'], 'changes' => ['stopLoss' => 1]]) === 'field_not_editable');
$flow = $apply($account['state'], 'flow.create', ['amount' => 250, 'type' => 'Wpłata', 'account' => 'K1', 'date' => '2026-01-02']);
check('Wpis: tworzenie', $flow['result']['type'] === 'Wpłata' && (float) $flow['result']['amount'] === 250.0);
check('Wpis: brak konta', $errorOf($base(), 'flow.create', ['amount' => 10]) === 'account_missing');
check('Wpis: kwota', $errorOf($account['state'], 'flow.create', ['amount' => 0, 'account' => 'K1']) === 'bad_number');
$flowDeleted = $apply($flow['state'], 'flow.delete', ['flowId' => $flow['result']['id']]);
check('Wpis: delete do kosza', $flowDeleted['state']['flows'] === [] && $flowDeleted['state']['trash'][0]['kind'] === 'flow');

// Reguły ryzyka
$tightened = $apply($base(), 'risk.update', [
    'changes' => ['maxRisk' => 0.5, 'maxLosses' => 1, 'rules' => ['Max 1% ryzyka', 'Zawsze stop loss', 'Po 2 stratach koniec']],
    'reason' => 'Zaostrzenie zasad po serii strat',
]);
check(
    'Ryzyko: zaostrzenie zapisane',
    (float) $tightened['result']['settings']['maxRisk'] === 0.5
    && (int) $tightened['result']['settings']['maxLosses'] === 1
    && count($tightened['result']['settings']['rules']) === 3
);
check('Ryzyko: nie można poluzować', $errorOf($base(), 'risk.update', ['changes' => ['maxRisk' => 5], 'reason' => 'Chcę większe ryzyko']) === 'risk_not_tightened');
check('Ryzyko: nie można wyłączyć zabezpieczeń', $errorOf($base(), 'risk.update', ['changes' => ['safetyEnabled' => false], 'reason' => 'Wyłączam zabezpieczenie']) === 'risk_not_tightened');
check('Ryzyko: nie można usunąć reguł', $errorOf($base(), 'risk.update', ['changes' => ['rules' => ['Max 1% ryzyka']], 'reason' => 'Skracam listę reguł']) === 'risk_not_tightened');
check('Ryzyko: reason wymagany', $errorOf($base(), 'risk.update', ['changes' => ['maxRisk' => 0.5], 'reason' => 'krótko']) === 'reason_required');
check('Ryzyko: pole niedozwolone', $errorOf($base(), 'risk.update', ['changes' => ['maxTrades' => 1], 'reason' => 'Nieznane pole testowe']) === 'field_not_editable');

// Prop challenge
$prop = $apply($base(), 'prop.create', ['name' => 'P1', 'templateId' => 'ftmo-1step', 'accountSize' => 10000]);
check('Prop: tworzenie', $prop['result']['name'] === 'P1' && (float) $prop['result']['accountSize'] === 10000.0);
check('Prop: nazwa wymagana', $errorOf($base(), 'prop.create', ['name' => ' ']) === 'invalid_prop_name');
check('Prop: nieznane pole', $errorOf($prop['state'], 'prop.update', ['challengeId' => $prop['result']['id'], 'changes' => ['stopLoss' => 1]]) === 'field_not_editable');
$propDeleted = $apply($prop['state'], 'prop.delete', ['challengeId' => $prop['result']['id']]);
check('Prop: delete do kosza', $propDeleted['state']['prop']['challenges'] === [] && $propDeleted['state']['trash'][0]['kind'] === 'prop');

echo "\nWynik: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
