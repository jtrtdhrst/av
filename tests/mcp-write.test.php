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
use AlphaVault\Mcp\Support\RecordDiff;
use AlphaVault\Mcp\Support\TradeService;
use AlphaVault\TradeRules;

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
    'Schematy: approve_proposal wymaga confirmed i hash, kod panelu jest opcjonalny',
    in_array('confirmed', WriteSchemas::inputApproveProposal()['required'], true)
    && in_array('expectedDiffHash', WriteSchemas::inputApproveProposal()['required'], true)
    && !in_array('confirmationCode', WriteSchemas::inputApproveProposal()['required'], true)
);
check('Schemat: utworzenie transakcji wymaga SL', in_array('sl', WriteSchemas::inputCreateTrade()['required'], true));
$tradeMetricSchemas = WriteSchemas::inputUpdateTrade()['properties']['changes']['properties'] ?? [];
check(
    'Schematy: MAE/MFE nieujemne albo null',
    ($tradeMetricSchemas['mae']['type'] ?? null) === ['number', 'null']
    && ($tradeMetricSchemas['mae']['minimum'] ?? null) === 0
    && ($tradeMetricSchemas['mfe']['type'] ?? null) === ['number', 'null']
    && ($tradeMetricSchemas['mfe']['minimum'] ?? null) === 0
);
$closeMfeSchema = WriteSchemas::inputCloseTrade()['properties']['mfe'] ?? [];
check(
    'Schemat: zamknięcie przyjmuje wyłącznie numeryczne MFE albo null',
    ($closeMfeSchema['type'] ?? null) === ['number', 'null']
    && ($closeMfeSchema['minimum'] ?? null) === 0
);

// --- Rejestr operacji i routing zapisu --------------------------------
$operations = WriteEngine::OPERATIONS;
check('Operacje: 30 unikalnych', count($operations) === 30 && count(array_unique($operations)) === 30, (string) count($operations));
check('Operacje: wszystkie 30 bezpośrednie', WriteEngine::APPROVAL_REQUIRED === [], implode(', ', WriteEngine::APPROVAL_REQUIRED));
$routingProblems = [];
foreach ($operations as $op) {
    if (WriteEngine::requiresApproval($op)) {
        $routingProblems[] = $op . ':zbedna-zgoda';
    }
}
check('Operacje: routing bez propozycji', $routingProblems === [], implode(', ', $routingProblems));
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
$updated = $apply($mood['state'], 'checkin.update', ['date' => $dayKey(0), 'changes' => ['mood' => 'Spokój', 'energy' => 3]]);
check(
    'Check-in: update zapisuje stan',
    $updated['state']['checkins'][0]['mood'] === 'Spokój'
    && $updated['state']['checkins'][0]['score'] === 60
    && $updated['state']['checkins'][0]['energy'] === 3
);
check('Check-in: energy poza zakresem', $errorOf($base(), 'checkin.create', ['energy' => 9]) === 'invalid_energy');
check('Check-in: nieznany nastrój', $errorOf($base(), 'checkin.create', ['mood' => 'Euforia']) === 'invalid_mood');
check('Check-in: edycja nieznanego pola', $errorOf($mood['state'], 'checkin.update', ['date' => $dayKey(0), 'changes' => ['score' => 1]]) === 'field_not_editable');

// Plan dnia
$planState = $apply($base(), 'plan.create', [
    'date' => $dayKey(-3), 'markets' => ['BTC', 'ETH'], 'bias' => 'long',
    'levels' => [
        '68000',
        ['value' => '67500', 'note' => 'retest'],
        ['type' => 'resistance', 'price' => '69000'],
        ['type' => 'liquidity', 'price' => 67000],
        ['type' => 'POI', 'value' => '66000'],
        ['type' => 'Płynność', 'value' => '65500'],
    ],
    'risk' => '0.5%', 'maxTrades' => 2, 'until' => '20:00',
])['state'];
$plan = $planState['plans'][$dayKey(-3)];
check('Plan: własne id i data', $plan['id'] !== '' && $plan['date'] === $dayKey(-3));
check('Plan: market wynika z markets', $plan['market'] === 'BTC');
check(
    'Plan: levels price/value i typy round-trip',
    $plan['levels'][0]['price'] === '68000' && $plan['levels'][0]['value'] === '68000'
    && $plan['levels'][1]['note'] === 'retest'
    && $plan['levels'][2]['type'] === 'Opór'
    && $plan['levels'][3]['type'] === 'Liquidity'
    && $plan['levels'][4]['type'] === 'POI'
    && $plan['levels'][5]['type'] === 'Liquidity'
);
check('Plan: szkic bez approvedAt', !array_key_exists('approvedAt', $plan));
$secondPlan = $apply($planState, 'plan.create', ['date' => $dayKey(-3), 'market' => 'SOL']);
$secondId = $secondPlan['result']['id'];
check(
    'Plan: wiele planów na datę z unikalnym id i kluczem',
    $secondId !== $plan['id']
    && isset($secondPlan['state']['plans'][$dayKey(-3) . '#' . $secondId])
    && $secondPlan['state']['plans'][$dayKey(-3) . '#' . $secondId]['date'] === $dayKey(-3)
);
$proposalPreview = $engine->preview(1, $base(), 'plan.create', ['market' => 'SOL']);
$proposalTarget = $proposalPreview['target'];
$proposalTargetParts = array_pad(explode('#', substr($proposalTarget, 5), 2), 2, '');
$proposalReplay = $engine->apply(1, $base(), 'plan.create', ['market' => 'SOL'], $proposalTarget);
check(
    'Plan: replay propozycji zachowuje id z podglądu',
    $proposalTargetParts[1] !== ''
    && ($proposalPreview['after']['id'] ?? '') === $proposalTargetParts[1]
    && $proposalReplay['target'] === $proposalTarget
    && ($proposalReplay['result']['id'] ?? '') === $proposalTargetParts[1]
    && ($proposalReplay['state']['plans'][$proposalTargetParts[0]]['id'] ?? '') === $proposalTargetParts[1]
);
$invalidPreparedTarget = '';
try {
    $engine->apply(1, $base(), 'plan.create', ['market' => 'SOL'], 'plan:' . $dayKey(0) . '#invalid');
} catch (WriteException $err) {
    $invalidPreparedTarget = $err->errorCode;
}
check('Plan: odrzuca nieprawidłowy target propozycji', $invalidPreparedTarget === 'invalid_proposal_target');
$invalidDateTarget = '';
try {
    $engine->apply(1, $base(), 'plan.create', [], 'plan:2026-02-30#pl_abcdef123456');
} catch (WriteException $err) {
    $invalidDateTarget = $err->errorCode;
}
check('Plan: odrzuca niepoprawną datę w target propozycji', $invalidDateTarget === 'invalid_proposal_target');
$mismatchedDateTarget = '';
try {
    $engine->apply(
        1,
        $base(),
        'plan.create',
        ['date' => $dayKey(0)],
        'plan:' . $dayKey(-1) . '#pl_abcdef123456'
    );
} catch (WriteException $err) {
    $mismatchedDateTarget = $err->errorCode;
}
check('Plan: odrzuca target z inną datą niż args', $mismatchedDateTarget === 'invalid_proposal_target');
$collisionState = $base();
$collisionDate = $dayKey(-4);
$collisionState['plans'][$collisionDate] = [
    'id' => $proposalTargetParts[1],
    'date' => $collisionDate,
];
$collisionError = '';
try {
    $engine->apply(1, $collisionState, 'plan.create', ['market' => 'SOL'], $proposalTarget);
} catch (WriteException $err) {
    $collisionError = $err->errorCode;
}
check('Plan: odrzuca zajęte ID z targetu propozycji', $collisionError === 'plan_id_conflict');
$preparedPlanDate = $dayKey(-5);
$preparedPlanId = 'pl_abcdef123456';
$preparedPlan = $engine->apply(
    1,
    $base(),
    'plan.create',
    ['market' => 'SOL'],
    'plan:' . $preparedPlanDate . '#' . $preparedPlanId
);
check(
    'Plan: zatwierdzana propozycja zachowuje id i datę podglądu',
    $preparedPlan['target'] === 'plan:' . $preparedPlanDate . '#' . $preparedPlanId
    && $preparedPlan['result']['id'] === $preparedPlanId
    && count($preparedPlan['state']['plans']) === 1
    && ($preparedPlan['state']['plans'][$preparedPlanDate]['id'] ?? '') === $preparedPlanId
);
$legacyPlanDate = $dayKey(-6);
$legacyPlanTarget = $engine->apply(
    1,
    $base(),
    'plan.create',
    ['date' => $legacyPlanDate, 'market' => 'BTC'],
    'plan:' . $legacyPlanDate
);
$legacyPlanId = (string) ($legacyPlanTarget['result']['id'] ?? '');
check(
    'Plan: target legacy plan:<date> zachowuje datę i przygotowuje ID',
    preg_match('/^pl_[a-f0-9]{12}$/', $legacyPlanId) === 1
    && $legacyPlanTarget['target'] === 'plan:' . $legacyPlanDate . '#' . $legacyPlanId
    && ($legacyPlanTarget['state']['plans'][$legacyPlanDate]['id'] ?? '') === $legacyPlanId
);
check('Plan: update nie zgaduje przy duplikacie', $errorOf($secondPlan['state'], 'plan.update', ['date' => $dayKey(-3), 'changes' => ['risk' => '1%']]) === 'plan_id_required');
check('Plan: approve nie zgaduje przy duplikacie', $errorOf($secondPlan['state'], 'plan.approve', ['date' => $dayKey(-3)]) === 'plan_id_required');
check('Plan: delete nie zgaduje przy duplikacie', $errorOf($secondPlan['state'], 'plan.delete', ['date' => $dayKey(-3)]) === 'plan_id_required');
check('Plan: until walidacja', $errorOf($base(), 'plan.create', ['date' => $dayKey(-4), 'until' => '25:00']) === 'invalid_until');
$planUpdated = $apply($secondPlan['state'], 'plan.update', ['date' => $dayKey(-3), 'planId' => $secondId, 'changes' => ['risk' => '0.25%']]);
check(
    'Plan: update celuje w planId',
    $planUpdated['state']['plans'][$dayKey(-3)]['markets'] === ['BTC', 'ETH']
    && $planUpdated['state']['plans'][$dayKey(-3) . '#' . $secondId]['risk'] === '0.25%'
);
$marketsUpdated = $apply($planState, 'plan.update', [
    'date' => $dayKey(-3), 'planId' => $plan['id'], 'changes' => ['markets' => ['SOL']],
]);
$replacedMarketPlan = $marketsUpdated['state']['plans'][$dayKey(-3)];
check(
    'Plan: zmiana markets aktualizuje też rynek główny',
    $replacedMarketPlan['markets'] === ['SOL'] && $replacedMarketPlan['market'] === 'SOL'
);
check('Plan: update blokuje approvedAt', $errorOf($planState, 'plan.update', ['date' => $dayKey(-3), 'changes' => ['approvedAt' => 1]]) === 'field_not_editable');
$planApproved = $apply($planUpdated['state'], 'plan.approve', ['date' => $dayKey(-3), 'planId' => $secondId]);
check(
    'Plan: approve aktualizuje tylko planId',
    is_int($planApproved['result']['approvedAt'])
    && !isset($planApproved['state']['plans'][$dayKey(-3)]['approvedAt'])
);
check('Plan: approve diff tylko approvedAt', array_column($planApproved['changes'], 'field') === ['approvedAt']);
check('Plan: approve bez planu', $errorOf($base(), 'plan.approve', ['date' => $dayKey(-9)]) === 'plan_missing');
$planDeleted = $apply($planApproved['state'], 'plan.delete', ['date' => $dayKey(-3), 'planId' => $secondId]);
check(
    'Plan: delete usuwa wskazany rekord do kosza',
    count($planDeleted['state']['plans']) === 1
    && $planDeleted['state']['plans'][$dayKey(-3)]['id'] === $plan['id']
    && $planDeleted['state']['trash'][0]['record']['id'] === $secondId
);

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
$setupDeleted = $apply($setup['state'], 'setup.delete', ['setupId' => $setup['result']['id']]);
$setupCollision = $setupDeleted['state'];
$setupCollision['setups'][] = ['id' => 'setup-new', 'name' => 'S1'];
check(
    'Kosz: przywrócenie setupu z kolizją nazwy zachowuje kosz',
    $errorOf($setupCollision, 'restore.record', ['trashId' => $setupDeleted['result']['trashId']]) === 'restore_conflict'
    && count($setupCollision['trash']) === 1
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
    && $trade['result']['mae'] === null && $trade['result']['mfe'] === null
);
$offsetTrade = $apply($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'sl' => 95,
    'date' => '2026-03-29T01:30:00+01:00', 'account' => 'WEEX',
]);
check('Transakcja: jawny offset konwertowany do UTC', $offsetTrade['result']['date'] === '2026-03-29T00:30:00.000Z');
check('Transakcja: nieistniejąca data odrzucona', $errorOf($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'sl' => 95, 'date' => '2026-02-30T12:00:00Z',
]) === 'invalid_date');
check('Transakcja: względne wyrażenie daty odrzucone', $errorOf($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'sl' => 95, 'date' => 'tomorrow',
]) === 'invalid_date');
$freeTrade = $apply($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'sl' => 95, 'fee' => 0, 'date' => '2026-01-02',
]);
check('Transakcja: fee 0 dozwolone', (float) $freeTrade['result']['fee'] === 0.0, (string) ($freeTrade['result']['fee'] ?? 'brak'));
check('Transakcja: zapis bez SL odrzucony', $errorOf($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1,
]) === 'stop_loss_required');
check('Transakcja: setup spoza Playbooka', $errorOf($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'sl' => 95, 'setup' => 'Nie ma takiego',
]) === 'unknown_setup');
check('Transakcja: konto spoza listy', $errorOf($withAccount(), 'trade.create', [
    'pair' => 'BTC/USDT', 'dir' => 'long', 'entry' => 100, 'size' => 1, 'sl' => 95, 'account' => 'Nie ma',
]) === 'unknown_account');
$tradeId = $trade['result']['id'];
$closedTrade = $apply($trade['state'], 'trade.close', ['tradeId' => $tradeId, 'exitPrice' => 110]);
check(
    'Transakcja: zamknięcie liczy PnL i R z rzeczywistego SL',
    $closedTrade['result']['result'] === 20.0 && $closedTrade['result']['r'] === 2.0
);
$adjustedRisk = $apply($trade['state'], 'trade.update', [
    'tradeId' => $tradeId, 'changes' => ['sl' => 90],
]);
check(
    'Transakcja: zmiana SL przed zamknięciem przelicza ryzyko i nie tworzy R',
    $adjustedRisk['result']['risk'] === 20.0 && $adjustedRisk['result']['r'] === null
);
$closedAdjusted = $apply($adjustedRisk['state'], 'trade.close', ['tradeId' => $tradeId, 'exitPrice' => 110]);
check(
    'Transakcja: zamknięcie po zmianie SL liczy R z nowego ryzyka',
    $closedAdjusted['result']['result'] === 20.0 && $closedAdjusted['result']['r'] === 1.0
);
check(
    'Transakcja: zamknięta pozycja nie pozwala zmienić SL',
    $errorOf($closedTrade['state'], 'trade.update', [
        'tradeId' => $tradeId, 'changes' => ['sl' => 90],
    ]) === 'closed_trade_execution_readonly'
);
check(
    'Transakcja: odrzuca SL po złej stronie po zmianie wejścia',
    $errorOf($trade['state'], 'trade.update', [
        'tradeId' => $tradeId, 'changes' => ['entry' => 90],
    ]) === 'invalid_stop_loss'
);
check(
    'Transakcja: odrzuca SL po złej stronie po zmianie kierunku',
    $errorOf($trade['state'], 'trade.update', [
        'tradeId' => $tradeId, 'changes' => ['dir' => 'short'],
    ]) === 'invalid_stop_loss'
);
$legacyNoStop = $trade['state'];
$legacyNoStop['trades'][0]['sl'] = 'brak';
$legacyNoStop['trades'][0]['risk'] = 0;
$legacyEdited = $apply($legacyNoStop, 'trade.update', [
    'tradeId' => $tradeId, 'changes' => ['note' => 'Historyczna notatka'],
]);
$legacyClosed = $apply($legacyEdited['state'], 'trade.close', [
    'tradeId' => $tradeId, 'result' => 20,
]);
check(
    'Transakcja historyczna bez SL: adnotacja działa, ryzyko i R pozostają nieznane',
    $legacyEdited['result']['risk'] === null
    && $legacyClosed['result']['risk'] === null
    && $legacyClosed['result']['r'] === null
    && $legacyClosed['result']['exit'] === null
);
$importedState = $trade['state'];
$importedState['trades'][0]['source'] = 'CSV';
check(
    'Transakcja importowana: R nie podlega edycji MCP',
    $errorOf($importedState, 'trade.update', [
        'tradeId' => $tradeId, 'changes' => ['r' => 1],
    ]) === 'field_not_editable'
);
check(
    'Transakcja: tekst z doklejonymi znakami nie jest liczbą',
    $errorOf($trade['state'], 'trade.update', [
        'tradeId' => $tradeId, 'changes' => ['entry' => '100abc'],
    ]) === 'bad_number'
);
$clearedMetrics = $apply($trade['state'], 'trade.update', [
    'tradeId' => $tradeId, 'changes' => ['mae' => null, 'mfe' => null],
]);
check(
    'Transakcja: MAE/MFE można wyczyścić tylko przez null',
    $clearedMetrics['result']['mae'] === null && $clearedMetrics['result']['mfe'] === null
);
check(
    'Transakcja: nie można usunąć wymaganego SL',
    $errorOf($trade['state'], 'trade.update', [
        'tradeId' => $tradeId, 'changes' => ['sl' => null],
    ]) === 'stop_loss_required'
);
$invalidMetricValues = ['', 'brak', '—', -0.25];
$invalidMetricCodes = array_map(
    static fn (mixed $value): string => $errorOf($trade['state'], 'trade.update', [
        'tradeId' => $tradeId, 'changes' => ['mfe' => $value],
    ]),
    $invalidMetricValues
);
check(
    'Transakcja: tekstowe placeholdery nie czyszczą MFE',
    $invalidMetricCodes === ['bad_number', 'bad_number', 'bad_number', 'bad_number'],
    implode(',', $invalidMetricCodes)
);
check(
    'Transakcja: zamknięcie odrzuca tekstowe MFE',
    $errorOf($trade['state'], 'trade.close', [
        'tradeId' => $tradeId, 'result' => 0, 'mfe' => '1',
    ]) === 'bad_number'
);
check('Transakcja: wspólna walidacja odrzuca SL LONG po złej stronie', $errorOf(
    $trade['state'],
    'trade.update',
    ['tradeId' => $tradeId, 'changes' => ['sl' => 105]]
) === 'invalid_stop_loss');
check('Transakcja: wspólna walidacja odrzuca TP LONG po złej stronie', $errorOf(
    $trade['state'],
    'trade.update',
    ['tradeId' => $tradeId, 'changes' => ['tp' => 99]]
) === 'invalid_take_profit');
check('Transakcja: błędny update pozostawia wejściowy stan bez zmian', $trade['state']['trades'][0]['sl'] === '95');
$shortTrade = $apply($withAccount(), 'trade.create', [
    'pair' => 'ETH/USDT', 'dir' => 'short', 'entry' => 100, 'size' => 2,
    'sl' => 105, 'tp' => 90, 'date' => '2026-01-02', 'account' => 'WEEX',
]);
$shortId = $shortTrade['result']['id'];
check('Transakcja: wspólna walidacja odrzuca SL SHORT po złej stronie', $errorOf(
    $shortTrade['state'],
    'trade.update',
    ['tradeId' => $shortId, 'changes' => ['sl' => 95]]
) === 'invalid_stop_loss');
check('Transakcja: wspólna walidacja odrzuca TP SHORT po złej stronie', $errorOf(
    $shortTrade['state'],
    'trade.update',
    ['tradeId' => $shortId, 'changes' => ['tp' => 105]]
) === 'invalid_take_profit');
$legacyLongAlias = $trade['state'];
$legacyLongAlias['trades'][0]['dir'] = 'long';
$closedLongAlias = $apply($legacyLongAlias, 'trade.close', [
    'tradeId' => $tradeId, 'exitPrice' => 110,
]);
$legacyShortAlias = $shortTrade['state'];
$legacyShortAlias['trades'][0]['dir'] = 'short';
$closedShortAlias = $apply($legacyShortAlias, 'trade.close', [
    'tradeId' => $shortId, 'exitPrice' => 90,
]);
check(
    'Transakcja: alias long/short zachowuje znak wyniku przy zamykaniu',
    (float) $closedLongAlias['result']['result'] === 20.0
    && (float) $closedShortAlias['result']['result'] === 20.0
    && (new TradeService())->sanitize($closedShortAlias['result'], true)['side'] === 'short'
);
$shortPartial = $apply($shortTrade['state'], 'trade.update', [
    'tradeId' => $shortId,
    'changes' => ['dir' => 'long', 'entry' => 100, 'sl' => 95, 'tp' => 110],
]);
check(
    'Transakcja: częściowa aktualizacja waliduje końcowy stan i risk',
    $shortPartial['result']['dir'] === 'l'
    && $shortPartial['result']['sl'] === '95'
    && $shortPartial['result']['tp'] === '110'
    && (float) $shortPartial['result']['risk'] === 10.0
);
$mentorUpdate = $apply($trade['state'], 'trade.update', [
    'tradeId' => $tradeId,
    'changes' => ['grade' => 'A', 'checklist' => 5, 'checkTotal' => 7, 'emotion' => 'Skupienie'],
]);
check(
    'Transakcja: mentor po zmianie oceny i checklisty jest zgodny z liczbą punktów',
    str_contains((string) $mentorUpdate['result']['mentor'], 'Checklista niepełna (5/7)')
    && $mentorUpdate['result']['emotion'] === 'Skupienie'
);
check('Transakcja: nieprawidłowa emocja przy update odrzucona', $errorOf($trade['state'], 'trade.update', [
    'tradeId' => $tradeId, 'changes' => ['emotion' => 'Nieznana'],
]) === 'invalid_emotion');
foreach ([
    'result' => null,
    'r' => null,
    'exit' => '100',
    'durationMin' => 1,
] as $field => $value) {
    check(
        'Transakcja: pola zamknięcia zmieniane wyłącznie przez close_trade (' . $field . ')',
        $errorOf($trade['state'], 'trade.update', ['tradeId' => $tradeId, 'changes' => [$field => $value]]) === 'field_not_editable'
    );
}
$closedAtResultOnly = $apply($trade['state'], 'trade.close', ['tradeId' => $tradeId, 'result' => 0]);
check(
    'Transakcja: close_trade wynikiem zapisuje datę i nie zostawia statusu otwartej',
    $closedAtResultOnly['result']['result'] === 0.0
    && $closedAtResultOnly['result']['exit'] === null
    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $closedAtResultOnly['result']['closedAt']) === 1
);
check(
    'Transakcja: break-even nie może być zamknięty drugi raz',
    $errorOf($closedAtResultOnly['state'], 'trade.close', ['tradeId' => $tradeId, 'result' => 10]) === 'already_closed'
);
check(
    'Transakcja: wykonanie zamkniętej pozycji jest niezmienne',
    $errorOf($closedAtResultOnly['state'], 'trade.update', ['tradeId' => $tradeId, 'changes' => ['entry' => 101]]) === 'closed_trade_execution_readonly'
);
 $legacyClosed = $trade['result'];
 $legacyClosed['exit'] = '100';
 $legacyClosed['result'] = 0;
 $legacyClosed['closedAt'] = null;
 $legacyState = $trade['state'];
 $legacyState['trades'][0] = $legacyClosed;
$legacyNote = $apply($legacyState, 'trade.update', [
     'tradeId' => $tradeId, 'changes' => ['note' => 'zachowaj czas jako nieznany'],
 ]);
$legacyOutput = (new TradeService())->sanitize($legacyNote['result'], true);
check(
    'Transakcja historyczna: wynik 0 bez closedAt pozostaje zamknięty i bez backfillu',
    TradeRules::isClosed($legacyNote['result'])
    && TradeRules::closedAtStatus($legacyNote['result']) === 'unknown'
    && $legacyNote['result']['closedAt'] === null
    && $legacyOutput['closed'] === true
    && $legacyOutput['closedAtStatus'] === 'unknown'
    && $legacyOutput['closedAt'] === null
    && $errorOf($legacyState, 'trade.close', ['tradeId' => $tradeId, 'result' => 1]) === 'already_closed'
);
$malformedLegacy = ['trades' => [[
    'id' => 'legacy-malformed', 'dir' => 'l', 'entry' => 'bad', 'size' => '',
    'sl' => 'bad', 'result' => 3, 'closedAt' => null, 'source' => 'Ręcznie', 'note' => '',
]]];
$malformedReview = $apply($malformedLegacy, 'trade.update', [
    'tradeId' => 'legacy-malformed', 'changes' => ['note' => 'adnotacja bez przeliczenia ryzyka'],
]);
check(
    'Transakcja historyczna: edycja notatki nie przelicza ryzyka z błędnych starych liczb',
    $malformedReview['result']['note'] === 'adnotacja bez przeliczenia ryzyka'
    && $malformedReview['result']['entry'] === 'bad'
    && $malformedReview['result']['size'] === ''
);
$timestampOnly = $legacyState;
$timestampOnly['trades'][0]['result'] = null;
$timestampOnly['trades'][0]['closedAt'] = '2026-11-17T10:30:00Z';
check(
    'Transakcja: jawny closedAt oznacza zamknięcie bez wyniku',
    TradeRules::isClosed($timestampOnly['trades'][0])
    && TradeRules::closedAtStatus($timestampOnly['trades'][0]) === 'known'
    && (new TradeService())->sanitize($timestampOnly['trades'][0], true)['closed'] === true
    && $errorOf($timestampOnly, 'trade.close', ['tradeId' => $tradeId, 'result' => 1]) === 'already_closed'
);
$identityBefore = ['trades' => [
    $closedAtResultOnly['result'],
    $shortTrade['result'],
]];
$identityAfterReordered = ['trades' => [
    $shortTrade['result'],
    array_replace($closedAtResultOnly['result'], ['id' => $tradeId . '_renamed', 'entry' => '101']),
]];
$droppedProtectedTrade = TradeRules::validateTransition($identityBefore, ['trades' => []]);
$softDeletedProtectedTrade = TradeRules::validateTransition($identityBefore, [
    'trades' => [$shortTrade['result']],
    'trash' => [['id' => 'trash-close', 'kind' => 'trade', 'record' => $closedAtResultOnly['result']]],
]);
$idlessTrade = ['pair' => 'IDLESS/USDT', 'dir' => 'l', 'entry' => '10', 'size' => '1'];
$idlessDelete = TradeRules::validateTransition(
    ['trades' => [$idlessTrade]],
    ['trades' => []]
);
$idlessSoftDeleteState = [
    'trades' => [],
    'trash' => [['id' => 'trash-idless', 'kind' => 'trade', 'record' => $idlessTrade]],
];
$idlessSoftDelete = TradeRules::validateTransition(
    ['trades' => [$idlessTrade]],
    $idlessSoftDeleteState
);
$idlessRestoreState = ['trades' => [$idlessTrade], 'trash' => []];
$idlessRestore = TradeRules::validateTransition($idlessSoftDeleteState, $idlessRestoreState);
$idlessMissingTrashId = TradeRules::validateTransition(
    ['trades' => [$idlessTrade]],
    ['trades' => [], 'trash' => [['kind' => 'trade', 'record' => $idlessTrade]]]
);
$malformedTrashEntry = TradeRules::validateTransition([], ['trades' => [], 'trash' => [null]]);
$idlessMutation = TradeRules::validateTransition(
    ['trades' => [$idlessTrade]],
    ['trades' => [array_replace($idlessTrade, ['entry' => '11'])]]
);
$idlessNewTrade = TradeRules::validateTransition([], ['trades' => [$idlessTrade]]);
$idlessDuplicate = TradeRules::validateTransition(
    ['trades' => [$idlessTrade]],
    ['trades' => [$idlessTrade, $idlessTrade]]
);
$idlessNoteEdit = TradeRules::validateTransition(
    ['trades' => [$idlessTrade]],
    ['trades' => [array_replace($idlessTrade, ['note' => 'adnotacja'])]]
);
$aliasWithExecutionChange = TradeRules::validateTransition(
    ['trades' => [$closedAtResultOnly['result']]],
    ['trades' => [array_replace($closedAtResultOnly['result'], ['dir' => 'long', 'entry' => '101'])]]
);
check(
    'Transakcja: ochrona ID nie zależy od kolejności; usunięcie wymaga oryginału w koszu',
    TradeRules::validateTransition($identityBefore, $identityAfterReordered)['error'] === 'trade_id_immutable'
    && ($droppedProtectedTrade['error'] ?? '') === 'trade_id_immutable'
    && $softDeletedProtectedTrade === null
    && ($idlessDelete['error'] ?? '') === 'trade_id_immutable'
    && $idlessSoftDelete === null
    && $idlessRestore === null
    && ($idlessMissingTrashId['error'] ?? '') === 'missing_trash_id'
    && ($malformedTrashEntry['error'] ?? '') === 'invalid_trash_entry'
    && ($idlessMutation['error'] ?? '') === 'trade_id_immutable'
    && ($idlessNewTrade['error'] ?? '') === 'missing_trade_id'
    && ($idlessDuplicate['error'] ?? '') === 'missing_trade_id'
    && $idlessNoteEdit === null
    && ($aliasWithExecutionChange['error'] ?? '') === 'closed_trade_execution_readonly'
    && !TradeRules::executionChanged(
        $closedAtResultOnly['result'],
        array_replace($closedAtResultOnly['result'], ['dir' => 'long'])
    )
);
$tradeTrashBefore = [
    'trades' => [],
    'trash' => [[
        'id' => 'trash-closed',
        'kind' => 'trade',
        'record' => $closedAtResultOnly['result'],
    ]],
];
$tradeTrashTamper = $tradeTrashBefore;
$tradeTrashTamper['trash'][0]['record']['result'] = 50;
$aliasTradeTrashRestore = [
    'trades' => [array_replace($closedAtResultOnly['result'], ['dir' => 'long'])],
    'trash' => [],
];
check(
    'Transakcja w koszu: rekord jest niezmienny, alias kierunku nie blokuje restore',
    (TradeRules::validateTransition($tradeTrashBefore, $tradeTrashTamper)['error'] ?? '') === 'trade_trash_immutable'
    && TradeRules::validateTransition($tradeTrashBefore, $aliasTradeTrashRestore) === null
);
foreach (['session' => 'Asia', 'plan' => false] as $field => $value) {
    check(
        'Transakcja: kontekst zamkniętej pozycji niezmienny (' . $field . ')',
        $errorOf($closedAtResultOnly['state'], 'trade.update', [
            'tradeId' => $tradeId, 'changes' => [$field => $value],
        ]) === 'closed_trade_execution_readonly'
    );
}
$reviewedTrade = $apply($trade['state'], 'review.create', [
    'tradeId' => $tradeId, 'grade' => 'A', 'note' => 'Recenzja do zachowania',
]);
$reviewDeleteWithoutAssociation = TradeRules::validateTransition($reviewedTrade['state'], [
    'trades' => [],
    'tradeReviews' => [],
    'trash' => [['id' => 'trash-without-review', 'kind' => 'trade', 'record' => $trade['result']]],
]);
check(
    'Transakcja: cały-stan nie może zgubić recenzji przy soft delete',
    ($reviewDeleteWithoutAssociation['error'] ?? '') === 'trade_review_delete_mismatch'
);
$deleted = $apply($reviewedTrade['state'], 'trade.delete', ['tradeId' => $tradeId]);
check(
    'Transakcja: delete do kosza zachowuje powiązaną recenzję',
    $deleted['state']['trades'] === [] && $deleted['state']['trash'][0]['kind'] === 'trade'
    && $deleted['state']['tradeReviews'] === []
    && $deleted['state']['trash'][0]['associatedReview'] === $reviewedTrade['state']['tradeReviews'][$tradeId]
    && $deleted['result']['trashId'] !== ''
);
$restored = $apply($deleted['state'], 'restore.record', ['trashId' => $deleted['result']['trashId']]);
check(
    'Kosz: przywracanie transakcji odtwarza recenzję',
    count($restored['state']['trades']) === 1
    && $restored['state']['trash'] === []
    && $restored['state']['tradeReviews'][$tradeId] === $reviewedTrade['state']['tradeReviews'][$tradeId]
);
check('Kosz: brak wpisu', $errorOf($base(), 'restore.record', ['trashId' => 'tr_brak']) === 'trash_missing');
$conflict = $deleted['state'];
$conflict['trades'][] = $trade['result'];
$reviewConflict = $deleted['state'];
$reviewConflict['tradeReviews'][$tradeId] = ['tradeId' => $tradeId, 'grade' => 'B'];
check(
    'Kosz: konflikty przywracania nie konsumują rekordu ani recenzji',
    $errorOf($conflict, 'restore.record', ['trashId' => $deleted['result']['trashId']]) === 'restore_conflict'
    && $errorOf($reviewConflict, 'restore.record', ['trashId' => $deleted['result']['trashId']]) === 'restore_conflict'
    && $reviewConflict['trash'][0]['associatedReview'] === $reviewedTrade['state']['tradeReviews'][$tradeId]
);

// Screeny
$shot = $apply($trade['state'], 'screenshot.add', ['tradeId' => $tradeId, 'dataUrl' => $png, 'position' => 'before']);
check('Screen: dodanie', $shot['result']['bytes'] > 0 && $shot['result']['position'] === 'before');
check('Screen: zły data URL', $errorOf($trade['state'], 'screenshot.add', ['tradeId' => $tradeId, 'dataUrl' => 'nie-obraz']) === 'invalid_screenshot');
check('Screen: brak przed usunięciem', $errorOf($trade['state'], 'screenshot.delete', ['tradeId' => $tradeId, 'position' => 'before']) === 'screenshot_missing');
$shotDeleted = $apply($shot['state'], 'screenshot.delete', ['tradeId' => $tradeId, 'position' => 'before']);
check('Screen: usunięcie do kosza', $shotDeleted['state']['trash'][0]['kind'] === 'screenshot');
$restoredShot = $apply($shotDeleted['state'], 'restore.record', ['trashId' => $shotDeleted['result']['trashId']]);
$publicRestoreResult = RecordDiff::redactValue($restoredShot['result']);
check(
    'Screen: restore zachowuje payload w stanie, ale redaguje odpowiedź MCP',
    $restoredShot['state']['trades'][0]['screens']['before'] === $png
    && !str_contains(json_encode($publicRestoreResult) ?: '', $png)
    && ($publicRestoreResult['record']['dataUrl']['bytes'] ?? 0) > 0
);

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
$alias = $apply($base(), 'risk.update', [
    'changes' => ['maxRiskPercentPerTrade' => 0.5, 'maxConsecutiveLossesPerDay' => 1],
    'reason' => 'Aliasy nazw z get_risk_rules',
]);
check(
    'Ryzyko: aliasy z get_risk_rules',
    (float) $alias['result']['settings']['maxRisk'] === 0.5 && (int) $alias['result']['settings']['maxLosses'] === 1
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
$propDiff = RecordDiff::between(
    ['name' => 'Old', 'accountSize' => 10000, 'optional' => null],
    ['name' => 'New', 'accountSize' => 10000.0]
);
check(
    'Diff: int/float nie tworzy zmian, a null/missing jest widoczną zmianą',
    array_column($propDiff, 'field') === ['name', 'optional']
);
check(
    'Diff: duże inty porównywane bez utraty precyzji',
    !RecordDiff::sameValue(9007199254740993, 9007199254740992.0)
);
check(
    'Diff: brak pustych kontenerów jest równoważny stanowi kanonicznemu',
    RecordDiff::sameState(
        ['trades' => []],
        [
            'trades' => [], 'setups' => [], 'checkins' => [], 'accounts' => [], 'flows' => [],
            'plans' => [], 'tradeReviews' => [], 'settings' => [],
            'prop' => ['challenges' => [], 'selectedId' => null],
        ]
    )
);
check('Walidacja liczb: separator NBSP i przecinek', TradeRules::number("22\u{00a0}029,82") === 22029.82);
$precisionState = TradeRules::normalizeTradeState(['trades' => [[
    'result' => '-0.000000001',
    'size' => '0.000000001',
], [
    'result' => 1.0e-9,
    'size' => 1.0e-9,
]]]);
check(
    'Transakcja: normalizacja nie obcina precyzji do zera',
    $precisionState['trades'][0]['result'] === '-0.000000001'
    && $precisionState['trades'][0]['size'] === '0.000000001'
    && $precisionState['trades'][1]['result'] === 1.0e-9
    && $precisionState['trades'][1]['size'] === 1.0e-9
);
$placeholderResult = TradeRules::normalizeTradeState(['trades' => [
    ['result' => ' BRAK '],
    ['result' => ' — '],
    ['result' => '0'],
]]);
check(
    'Transakcja: placeholder wyniku staje się null, zero pozostaje zamknięciem',
    $placeholderResult['trades'][0]['result'] === null
    && $placeholderResult['trades'][1]['result'] === null
    && $placeholderResult['trades'][2]['result'] === '0'
    && !TradeRules::isClosed($placeholderResult['trades'][0])
    && TradeRules::isClosed($placeholderResult['trades'][2])
);
check(
    'State diff: brak kosza i pusty kosz są semantycznie równoważne',
    RecordDiff::sameState(['trades' => []], ['trades' => [], 'trash' => []])
);
$screenDiff = RecordDiff::stateBetween(
    ['trades' => [['id' => 'screen-diff', 'screens' => ['before' => 'data:image/png;base64,AAEC']]]],
    ['trades' => [['id' => 'screen-diff', 'screens' => ['before' => 'data:image/png;base64,AQID']]]]
);
check(
    'State diff: screen ma tylko hash i rozmiar, bez payloadu base64',
    count($screenDiff) === 1
    && ($screenDiff[0]['field'] ?? '') === 'state.trades[id:screen-diff].screens'
    && ($screenDiff[0]['before']['bytes'] ?? null) === 3
    && ($screenDiff[0]['after']['bytes'] ?? null) === 3
    && !str_contains(json_encode($screenDiff) ?: '', 'AAEC')
);
$nestedImage = RecordDiff::redactValue([
    'trash' => [['record' => ['dataUrl' => $png]]],
    'preview' => ['screens' => ['before' => $png]],
    'unkeyed' => $png,
]);
check(
    'Redakcja screenów obejmuje dataUrl, preview, kosz i replay payloady',
    !str_contains(json_encode($nestedImage) ?: '', $png)
    && ($nestedImage['trash'][0]['record']['dataUrl']['bytes'] ?? 0) > 0
    && ($nestedImage['preview']['screens']['bytes'] ?? 0) > 0
);
$propDeleted = $apply($prop['state'], 'prop.delete', ['challengeId' => $prop['result']['id']]);
check('Prop: delete do kosza', $propDeleted['state']['prop']['challenges'] === [] && $propDeleted['state']['trash'][0]['kind'] === 'prop');

echo "\nWynik: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
