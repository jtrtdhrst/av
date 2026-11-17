<?php
declare(strict_types=1);

/**
 * Testy kontraktowe silników MCP (bez HTTP i bez bazy).
 *
 * Uruchom: php tests/mcp-tools.test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AlphaVault\Mcp\Analytics\BadgesService;
use AlphaVault\Mcp\Analytics\CalculatorService;
use AlphaVault\Mcp\Analytics\DayPlanService;
use AlphaVault\Mcp\Analytics\JournalService;
use AlphaVault\Mcp\Analytics\PerformanceService;
use AlphaVault\Mcp\Analytics\RiskService;
use AlphaVault\Mcp\Analytics\ScreensService;
use AlphaVault\Mcp\Analytics\SetupService;
use AlphaVault\Mcp\Analytics\TaxService;
use AlphaVault\Mcp\Support\DashboardService;
use AlphaVault\Mcp\Support\Metrics;
use AlphaVault\Mcp\Support\PropChallengeService;
use AlphaVault\Mcp\Support\StatsService;
use AlphaVault\Mcp\Support\TradeService;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Tools\Prompts;
use AlphaVault\Mcp\Tools\Schemas;

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

$tz = new DateTimeZone('Europe/Warsaw');
$today = new DateTimeImmutable('now', $tz);
$at = static fn (int $offset, int $hour): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
    ->setTime($hour, 0)
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d\TH:i:s.000\Z');
$dayKey = static fn (int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
$dayPlanInput = Schemas::inputDayPlan();
$dayPlanComplianceInput = Schemas::inputDayPlanCompliance();
check(
    'get_day_plan: schema exposes optional planId and market filters',
    isset($dayPlanInput['properties']['planId'], $dayPlanInput['properties']['market'])
    && !in_array('planId', $dayPlanInput['required'] ?? [], true)
    && !in_array('market', $dayPlanInput['required'] ?? [], true)
);
check(
    'get_day_plan_compliance: schema exposes optional selectors for date ranges',
    isset($dayPlanComplianceInput['properties']['planId'], $dayPlanComplianceInput['properties']['market'])
    && !in_array('planId', $dayPlanComplianceInput['required'] ?? [], true)
    && !in_array('market', $dayPlanComplianceInput['required'] ?? [], true)
);

$trade = static fn (array $o): array => array_merge([
    'id' => 't' . bin2hex(random_bytes(3)),
    'date' => gmdate('c'),
    'pair' => 'BTC/USDT',
    'dir' => 'l',
    'entry' => '100',
    'exit' => '101',
    'sl' => '98',
    'tp' => '106',
    'size' => '1',
    'result' => 1.0,
    'r' => 0.5,
    'grade' => 'A',
    'checklist' => 7,
    'checkTotal' => 7,
    'errors' => [],
    'emotion' => 'Spokój',
    'setup' => 'Retest wybicia',
    'session' => 'Londyn',
    'fee' => 0.5,
    'plan' => true,
    'account' => 'Konto A',
    'screens' => [],
], $o);

$accounts = [
    ['id' => 'a1', 'name' => 'Konto A', 'currency' => 'USDT', 'start' => 1000, 'balance' => 1000, 'include' => true, 'primary' => true],
];

$state = [
    'accounts' => $accounts,
    'trades' => [
        $trade(['id' => 'w1', 'date' => $at(-2, 10), 'result' => 50, 'r' => 2]),
        $trade(['id' => 'l1', 'date' => $at(-1, 10), 'result' => -25, 'r' => -1, 'grade' => 'C', 'errors' => ['Za duża pozycja'], 'emotion' => 'FOMO', 'setup' => 'Range']),
        $trade(['id' => 'w2', 'date' => $at(0, 9), 'result' => 25, 'r' => 1]),
        $trade(['id' => 'l2', 'date' => $at(0, 10), 'result' => -10, 'r' => -0.5, 'sl' => 'brak', 'dir' => 's', 'pair' => 'ETH/USDT', 'session' => 'Nowy Jork']),
        $trade(['id' => 'o1', 'date' => $at(0, 11), 'result' => null, 'r' => null]),
    ],
    'plans' => [
        $dayKey(0) => [
            'date' => $dayKey(0), 'market' => 'BTC', 'markets' => ['BTC'], 'bias' => 'long',
            'risk' => '1%', 'maxTrades' => '1', 'until' => '23:59', 'approvedAt' => 1,
        ],
        $dayKey(-1) => [
            'date' => $dayKey(-1), 'market' => 'BTC', 'bias' => 'long',
            'risk' => '1%', 'maxTrades' => '3', 'until' => '09:00', 'approvedAt' => null,
        ],
    ],
    'settings' => [
        'maxRisk' => 1, 'maxLosses' => 2, 'maxDailyLoss' => 3, 'block' => 'midnight',
        'safetyEnabled' => true, 'requireReason' => true, 'pushLimit' => true,
        'rules' => ['Max 1%', 'SL zawsze'], 'checklist' => ['Trend', 'Poziom'],
    ],
    'overrides' => [],
    'setups' => [
        ['id' => 'pb1', 'name' => 'Retest wybicia', 'tf' => 'H1', 'grade' => 'A', 'rules' => ['A1', 'A2'], 'bad' => false, 'createdAt' => $today->modify('-30 days')->format('c')],
        ['id' => 'pb2', 'name' => 'Range', 'tf' => 'M15', 'grade' => 'B', 'rules' => ['B1', 'B2'], 'bad' => false, 'createdAt' => $today->modify('-20 days')->format('c')],
    ],
    'checkins' => [
        ['date' => $dayKey(-2), 'mood' => 'Skupienie', 'energy' => 4, 'note' => '', 'score' => 78],
        ['date' => $dayKey(-1), 'mood' => 'FOMO', 'energy' => 2, 'note' => '', 'score' => 28],
        ['date' => $dayKey(0), 'mood' => 'Spokój', 'energy' => 3, 'note' => '', 'score' => 60],
    ],
    'flows' => [
        ['id' => 'f1', 'date' => '2026-01-10', 'account' => 'Konto A', 'type' => 'Wpłata', 'amount' => 1000, 'currency' => 'PLN', 'fiat' => 4000, 'fx' => 1, 'tax' => 'cost'],
        ['id' => 'f2', 'date' => '2026-02-10', 'account' => 'Konto A', 'type' => 'Wypłata', 'amount' => 500, 'currency' => 'PLN', 'fiat' => 5000, 'fx' => 1, 'tax' => 'income'],
    ],
    'tax' => ['year' => 2026, 'carryLoss' => 500],
    'prop' => ['challenges' => [], 'selectedId' => null],
    'tradeReviews' => [],
];

// --- Metrics ---
$empty = Metrics::summarize([]);
check('Metrics: pusty zestaw ma hasData=false', $empty['hasData'] === false && $empty['sampleSize'] === 0);
check('Metrics: brak danych daje null, nie zero', $empty['net'] === null && $empty['winRate'] === null && $empty['profitFactor'] === null);
$summary = Metrics::summarize($state['trades']);
check('Metrics: netto i próba', $summary['sampleSize'] === 4 && $summary['net'] === 40.0, json_encode($summary));
check('Metrics: profit factor liczy się z brutto', abs($summary['profitFactor'] - 2.14) < 0.001, (string) $summary['profitFactor']);
check('Metrics: expectancy R', abs($summary['expectancyR'] - 0.38) < 0.001, (string) $summary['expectancyR']);
$breakEvenSummary = Metrics::summarize([$trade(['result' => 0.0, 'r' => 0.0])]);
check('Metrics: break-even osobno od zysku i straty', $breakEvenSummary['sampleSize'] === 1
    && $breakEvenSummary['wins'] === 0 && $breakEvenSummary['losses'] === 0
    && $breakEvenSummary['breakeven'] === 1 && $breakEvenSummary['net'] === 0.0);
$dstRange = Metrics::range([
    ['date' => '2026-10-25T21:59:59Z', 'result' => 1],
    ['date' => '2026-10-25T23:00:00Z', 'result' => 1],
], '2026-10-25', '2026-10-25');
check('Metrics: granice dnia Europe/Warsaw obejmują DST i wykluczają następny dzień', count($dstRange) === 1);
$springDstRange = Metrics::range([
    ['date' => '2027-03-28T21:59:59Z', 'result' => 1],
    ['date' => '2027-03-28T22:30:00Z', 'result' => 1],
], '2027-03-28', '2027-03-28');
check('Metrics: wiosenny dzień DST ma lokalną granicę 22:00Z', count($springDstRange) === 1);
check(
    'Dates: następna północ liczona w Europe/Warsaw, nie jako 24 h UTC',
    Dates::startOfNextLocalDay('2026-10-25')->format('Y-m-d\TH:i:s\Z') === '2026-10-25T23:00:00Z'
    && Dates::startOfNextLocalDay('2027-03-28')->format('Y-m-d\TH:i:s\Z') === '2027-03-28T22:00:00Z'
);
$reverseRangeRejected = false;
try {
    Metrics::range([], '2026-10-26', '2026-10-25');
} catch (InvalidArgumentException) {
    $reverseRangeRejected = true;
}
check('Metrics: odwrócony sąsiedni zakres jest odrzucany', $reverseRangeRejected);
check(
    'Dates: jawny offset parsowany jako chwila i prezentowany w Warszawie',
    Dates::timestamp('2026-03-29T01:30:00+01:00') === (new DateTimeImmutable('2026-03-29T00:30:00Z'))->getTimestamp()
    && Dates::localParts('2026-03-29T01:30:00+01:00')['time'] === '01:30'
    && Dates::timestamp('2026-03-29T01:30:00+0100') === Dates::timestamp('2026-03-29T01:30:00+01:00')
    && Dates::parseUtc('2026-03-29T01:30:00+99:99') === null
    && Dates::parseUtc('tomorrow') === null
);
$tradeService = new TradeService();
$emptySetupStats = $tradeService->setupStats(['trades' => []], 'Bez prób');
check('Setup summary: brak prób zwraca null, nie sztuczne zera', $emptySetupStats['trades'] === 0
    && $emptySetupStats['breakeven'] === 0 && $emptySetupStats['winRate'] === null
    && $emptySetupStats['net'] === null && $emptySetupStats['avgR'] === null);
$setupBreakEven = $tradeService->setupStats(['trades' => [$trade([
    'result' => 0.0, 'r' => 0.0, 'setup' => 'Retest wybicia',
])]], 'Retest wybicia');
check('Setup summary: break-even widoczny oddzielnie', $setupBreakEven['trades'] === 1
    && $setupBreakEven['wins'] === 0 && $setupBreakEven['breakeven'] === 1
    && $setupBreakEven['net'] === 0.0);
$noCapitalStats = (new StatsService($tradeService))->compute([
    'trades' => [$trade(['result' => -20.0, 'r' => -1.0])],
    'accounts' => [],
], 'all');
check('Stats: drawdown procentowy bez realnego kapitału jest null', $noCapitalStats['maxDrawdown'] === -20.0
    && $noCapitalStats['maxDrawdownPct'] === null);
$statsBreakEven = (new StatsService($tradeService))->compute([
    'trades' => [$trade(['result' => 0.0, 'r' => 0.0])],
    'accounts' => [],
], 'all');
check('Stats: break-even spójnie eksponowany', $statsBreakEven['breakeven'] === 1
    && $statsBreakEven['wins'] === 0 && $statsBreakEven['losses'] === 0);

// --- Day plan ---
$plans = new DayPlanService();
$todayDay = $plans->day($state, $dayKey(0));
check('Plan: status approved', $todayDay['plan']['status'] === 'approved');
check('Plan: legacy date map zachowuje id stabilne', $todayDay['plan']['id'] === $dayKey(0));
check('Plan: limit transakcji wykryty', $todayDay['limits']['tradesOverLimit'] === 2, json_encode($todayDay['limits']));
check('Plan: transakcja poza rynkiem niezgodna', $todayDay['compliance']['tradeRows'][1]['aligned'] === false);
check('Plan: brak SL to naruszenie', in_array('missing_stop_loss', $todayDay['compliance']['tradeRows'][1]['reasons'], true));
check('Plan: szkic nie daje zgodności', $plans->day($state, $dayKey(-1))['plan']['status'] === 'draft');
$alignmentDraft = $plans->alignment($state, $state['trades'][1], $state['plans'][$dayKey(-1)]);
check('Plan: szkic = nie da się ustalić', $alignmentDraft['determined'] === false && in_array('plan_not_approved', $alignmentDraft['reasons'], true));
$slashMarketAlignment = $plans->alignment(
    $state,
    array_merge($state['trades'][0], ['pair' => 'BTCUSDT']),
    ['date' => $dayKey(0), 'market' => 'BTC/USDT', 'markets' => ['BTC/USDT'], 'bias' => 'long', 'approvedAt' => 1]
);
check(
    'Plan: zgodność rynku używa tej samej normalizacji co przypisanie',
    !in_array('market_not_in_plan', $slashMarketAlignment['reasons'], true)
);
$accountScopedState = $state;
$accountScopedState['accounts'][] = ['id' => 'a2', 'name' => 'Konto B', 'include' => false];
$accountScopedState['trades'][] = $trade([
    'id' => 'excluded-earlier',
    'date' => $at(0, 8),
    'account' => 'Konto B',
]);
$sequenceTrade = $accountScopedState['trades'][2];
$sequencePlan = $plans->planForTrade($accountScopedState, $sequenceTrade);
check(
    'Plan: sekwencja statystyk pomija wcześniejsze transakcje z wyłączonych kont',
    $plans->tradeSequence($accountScopedState, $sequenceTrade, $sequencePlan) === 2
    && $plans->tradeSequence($accountScopedState, $sequenceTrade, $sequencePlan, true) === 1
);

$multiState = $state;
$multiState['plans'][$dayKey(0) . '#eth-plan'] = [
    'id' => 'eth-plan',
    'date' => $dayKey(0),
    'market' => 'ETH',
    'markets' => ['ETH'],
    'bias' => 'short',
    'risk' => '1%',
    'maxTrades' => '2',
    'approvedAt' => 1,
    'levels' => [['type' => 'liquidity', 'price' => '3 400', 'note' => 'pool']],
];
$formattedMarketState = $state;
$formattedMarketState['plans'][$dayKey(0)]['market'] = 'BTC/USDT';
$formattedMarketState['plans'][$dayKey(0)]['markets'] = ['BTC/USDT'];
$listedMultiPlans = $plans->plansForDate($multiState, $dayKey(0));
$multiDay = $plans->day($multiState, $dayKey(0));
$legacyLevelsState = $state;
$legacyLevelsState['plans'][$dayKey(0)]['levels'] = [['value' => '68100', 'note' => 'old format']];
$legacyLevelsListed = $plans->plansForDate($legacyLevelsState, $dayKey(0));
$legacyLevelsSelected = $plans->planFor($legacyLevelsState, $dayKey(0));
$noMatchPlanDay = $plans->day($multiState, $dayKey(0), 'missing-plan');
$multiHistory = $plans->history($multiState, $dayKey(0), $dayKey(0), 20, 0);
$multiCompliance = $plans->compliance($multiState, $dayKey(0), $dayKey(0), 20, 0);
$filteredCompliance = $plans->compliance($multiState, $dayKey(0), $dayKey(0), 20, 0, 'eth-plan');
$noMatchRangeCompliance = $plans->compliance($multiState, $dayKey(0), $dayKey(0), 20, 0, 'missing-plan');
check(
    'Plan: dzień nie wybiera arbitralnie i pokazuje listę',
    $multiDay['plan'] === null && count($multiDay['plans']) === 2
    && $listedMultiPlans[0]['status'] === 'approved'
    && $listedMultiPlans[1]['status'] === 'approved'
    && $plans->planFor($multiState, $dayKey(0)) === null
);
check(
    'Plan: selektor market i dopasowanie transakcji',
    $plans->planFor($multiState, $dayKey(0), 'ETH')['id'] === 'eth-plan'
    && $plans->planFor($formattedMarketState, $dayKey(0), 'BTCUSDT')['market'] === 'BTC/USDT'
    && $plans->planForTrade($multiState, $state['trades'][3])['id'] === 'eth-plan'
    && $plans->planForTrade($multiState, $state['trades'][3])['status'] === 'approved'
);
$prefixCollisionState = [
    'plans' => [
        $dayKey(0) => ['id' => 'btc-plan', 'date' => $dayKey(0), 'market' => 'BTC', 'markets' => ['BTC'], 'bias' => 'long', 'approvedAt' => 1],
        $dayKey(0) . '#btcbull-plan' => ['id' => 'btcbull-plan', 'date' => $dayKey(0), 'market' => 'BTCBULL/USDT', 'markets' => ['BTCBULL/USDT'], 'bias' => 'long', 'approvedAt' => 1],
    ],
    'trades' => [$trade(['id' => 'btcbull-trade', 'date' => $at(0, 10), 'pair' => 'BTCBULL/USDT'])],
];
check(
    'Plan: dopasowanie legacy BTC nie koliduje z prefiksem BTCBULL',
    !$plans->marketMatchesPlan($prefixCollisionState['trades'][0], $prefixCollisionState['plans'][$dayKey(0)])
    && $plans->planForTrade($prefixCollisionState, $prefixCollisionState['trades'][0])['id'] === 'btcbull-plan'
    && $plans->marketMatchesPlan(['pair' => 'BTCUSDT'], ['market' => 'BTC/USDT'])
);
check(
    'Plan: błędny selektor dnia nie zwraca zgodności z innym planem',
    $noMatchPlanDay['plan'] === null
    && $noMatchPlanDay['compliance']['undeterminedTrades'] === 0
    && $noMatchPlanDay['compliance']['trades'] === 0
    && $noMatchPlanDay['compliance']['planIds'] === []
    && $noMatchRangeCompliance['days'] === []
    && $noMatchRangeCompliance['hasData'] === false
);
check(
    'Plan: poziomy UI zachowują liquidity i price/value',
    $multiDay['plans'][1]['levels'][0]['type'] === 'Liquidity'
    && $multiDay['plans'][1]['levels'][0]['price'] === '3 400'
    && $multiDay['plans'][1]['levels'][0]['value'] === '3 400'
);
check(
    'Plan: get_day_plan list/selection normalizuje legacy value do price/value',
    $legacyLevelsListed[0]['levels'][0]['price'] === '68100'
    && $legacyLevelsListed[0]['levels'][0]['value'] === '68100'
    && $legacyLevelsSelected['levels'][0]['price'] === '68100'
    && $legacyLevelsSelected['levels'][0]['value'] === '68100'
    && $legacyLevelsListed[0]['levels'][0]['note'] === 'old format'
);
check(
    'Plan: historia liczy osobno każdy rekord',
    $multiHistory['total'] === 2
    && array_column($multiHistory['items'], 'planId') === [$dayKey(0), 'eth-plan']
    && array_column($multiHistory['items'], 'trades') === [2, 1]
);
check(
    'Plan: zgodność zachowuje rekordy i dopasowuje rynek',
    count($multiCompliance['days']) === 2
    && array_column($multiCompliance['days'], 'planId') === [$dayKey(0), 'eth-plan']
    && $multiDay['compliance']['tradeRows'][1]['planId'] === 'eth-plan'
);
check(
    'Plan: filtry planId i market działają także w zakresie dat',
    count($filteredCompliance['days']) === 1
    && $filteredCompliance['days'][0]['planId'] === 'eth-plan'
    && $filteredCompliance['filters']['planId'] === 'eth-plan'
    && $plans->compliance($multiState, $dayKey(0), $dayKey(0), 20, 0, null, 'ETH')['days'][0]['planId'] === 'eth-plan'
    && $plans->compliance($formattedMarketState, $dayKey(0), $dayKey(0), 20, 0, null, 'BTCUSDT')['days'][0]['planId'] === $dayKey(0)
);
$sameMarketState = $multiState;
$sameMarketState['plans'][$dayKey(0) . '#eth-plan-2'] = array_merge(
    $multiState['plans'][$dayKey(0) . '#eth-plan'],
    ['id' => 'eth-plan-2']
);
check(
    'Plan: nie zgaduje między planami tego samego rynku',
    $plans->planForTrade($sameMarketState, $state['trades'][3]) === null
);
$dashboard = (new ReflectionClass(DashboardService::class))->newInstanceWithoutConstructor();
$singleDashboardPlans = $dashboard->planOptions($state, $dayKey(0));
$multiDashboardPlans = $dashboard->planOptions($multiState, $dayKey(0));
check(
    'Dashboard: legacy plan pozostaje w plan, duplikaty są jawne',
    $singleDashboardPlans['plan']['market'] === 'BTC'
    && count($singleDashboardPlans['plans']) === 1
    && $multiDashboardPlans['plan'] === null
    && count($multiDashboardPlans['plans']) === 2
    && array_column($multiDashboardPlans['plans'], 'market') === ['BTC', 'ETH']
);

// --- Risk ---
$risk = new RiskService($plans);
$rules = $risk->rules($state);
check('Ryzyko: zasady z ustawień', $rules['maxRiskPercentPerTrade'] === 1.0 && $rules['maxConsecutiveLossesPerDay'] === 2);
$protection = $risk->protection($state);
check('Ryzyko: tryb ochronny po stratach', $protection['triggered'] === false || $protection['losses'] >= 2, json_encode($protection));
$violations = $risk->violations($state, null, null, 100, 0);
$ruleNames = array_column($violations['byRule'], 'rule');
check('Ryzyko: wykryty brak SL', in_array('missing_stop_loss', $ruleNames, true), json_encode($ruleNames));
check('Ryzyko: naruszenie ma oczekiwaną i rzeczywistą wartość', $violations['items'][0]['expected'] !== null && $violations['items'][0]['actual'] !== null);

// --- Performance ---
$perf = new PerformanceService($plans, $risk);
$accountScopedPlanCompliance = $perf->byPlanCompliance(
    $accountScopedState,
    $dayKey(0),
    $dayKey(0),
    1
);
$maxTradeRule = array_values(array_filter(
    $accountScopedPlanCompliance['rulesViolated'],
    static fn (array $rule): bool => $rule['rule'] === 'max_trades_exceeded'
));
check(
    'Plan: raport zgodności liczy limit w zakresie kont statystyk',
    count($maxTradeRule) === 1 && $maxTradeRule[0]['count'] === 1
);
$bySetup = $perf->bySetup($state, null, null, 1);
check('Analityka: suma grup = próba', array_sum(array_column($bySetup['items'], 'sampleSize')) === $bySetup['sampleSize']);
$byGrade = $perf->byGrade($state, null, null, 1);
check('Analityka: oceny pokrywają wszystkie transakcje', array_sum(array_column($byGrade['items'], 'sampleSize')) + $byGrade['ungraded']['sampleSize'] === $byGrade['sampleSize']);
$byError = $perf->byError($state, null, null, 1);
check('Analityka: koszt błędu ujemny', $byError['items'][0]['net'] < 0 && abs($byError['items'][0]['shareOfGrossLossPct'] - 71.43) < 0.01, json_encode($byError['items'][0]));
$patterns = $perf->patterns($state, null, null, 1);
check('Analityka: wzorce nie zmyślają przy małej próbie', $patterns['hasData'] === false && $patterns['items'] === [], json_encode($patterns['items']));

// --- Setup ---
$setups = new SetupService();
$details = $setups->details($state, 'Retest wybicia');
check('Setup: szczegóły po nazwie', $details['found'] === true && $details['setup']['rules'] === ['A1', 'A2']);
check('Setup: brak setupu to found=false', $setups->details($state, 'Nie ma')['found'] === false);
$setupPerf = $setups->performance($state, 'Retest wybicia', null, null, 1);
check('Setup: statystyki tylko tego setupu', $setupPerf['sampleSize'] === 3, (string) $setupPerf['sampleSize']);

// --- Calculator (parity z formułą panelu) ---
$calc = new CalculatorService();
$crypto = $calc->calculate(['balance' => 10000, 'riskPercent' => 1, 'direction' => 'long', 'entry' => 100, 'stopLoss' => 95, 'takeProfit' => 120, 'leverage' => 10]);
check('Kalkulator: max strata', $crypto['maxLoss'] === 100.0);
check('Kalkulator: wartość pozycji', $crypto['positionValue'] === 2000.0);
check('Kalkulator: depozyt', $crypto['marginRequired'] === 200.0);
check('Kalkulator: potencjalny zysk', $crypto['potentialProfit'] === 400.0);
check('Kalkulator: R:R i próg', $crypto['riskReward'] === 4.0 && $crypto['breakevenWinRatePct'] === 20);
$fx = $calc->calculate(['mode' => 'fx', 'instrument' => 'USD/JPY', 'balance' => 10000, 'riskPercent' => 1, 'direction' => 'long', 'entry' => 152.4, 'stopLoss' => 151.9, 'takeProfit' => 153.4, 'leverage' => 30]);
check('Kalkulator: JPY liczy wartość pipsa z ceny', $fx['pipValue'] > 6.0 && $fx['pipValue'] < 6.7, (string) $fx['pipValue']);
check('Kalkulator: JPY 50 pipsów', $fx['stopDistance'] === 50.0, (string) $fx['stopDistance']);
$threw = false;
try {
    $calc->calculate(['balance' => 1000, 'riskPercent' => 1, 'direction' => 'long', 'entry' => 100, 'stopLoss' => 105]);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('Kalkulator: SL po złej stronie odrzucony', $threw);

// --- Tax ---
$tax = new TaxService();
$taxSummary = $tax->summary($state, 2026);
check('PIT: przychód', $taxSummary['pit38']['revenue'] === 5000.0, (string) $taxSummary['pit38']['revenue']);
check('PIT: koszt', $taxSummary['pit38']['costs'] === 4000.0);
check('PIT: strata z lat ubiegłych', $taxSummary['pit38']['carryLossFromPreviousYears'] === 500.0);
check('PIT: dochód', $taxSummary['pit38']['taxableIncome'] === 500.0);
check('PIT: podatek 19%', $taxSummary['pit38']['taxDue19Pct'] === 95.0);
check('PIT: brak dochodu = strata do przeniesienia', $tax->summary(['flows' => [['type' => 'Wpłata', 'fiat' => 100, 'fx' => 1, 'tax' => 'cost', 'date' => '2026-01-01', 'currency' => 'PLN']], 'tax' => ['year' => 2026, 'carryLoss' => 0]], 2026)['pit38']['carryForward'] === 100.0);
$tx = $tax->transactions($state, ['limit' => 10]);
check('Kapitał: wpłata i wypłata', $tx['total'] === 2 && $tx['totals']['deposits'] === 1000.0 && $tx['totals']['withdrawals'] === 500.0);

// --- Badges ---
$propEngine = new PropChallengeService();
$badges = new BadgesService($plans, $risk, $propEngine);
$progress = $badges->progress($state);
check('Odznaki: katalog i poziomy', count($progress['items']) === 10 && $progress['summary']['maxLevels'] === 30);
$first = $progress['items'][0];
check('Odznaki: pierwszy wpis zdobyty', $first['id'] === 'first_trade' && $first['level'] >= 1 && $first['earnedAt'] !== null);
$journal = array_values(array_filter($progress['items'], static fn (array $b): bool => $b['id'] === 'journal'))[0];
check('Odznaki: postęp liczbowe', $journal['value'] === 4 && $journal['goal'] === 10 && $journal['status'] === 'in_progress');

// --- Screens ---
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$screens = new ScreensService();
$meta = $screens->forTrade(['id' => 'x', 'screens' => ['before' => $png]], false);
check('Screeny: metadane bez base64', $meta['screenshots']['count'] === 1 && $meta['screenshots']['images'] === []);
check('Screeny: wymiary PNG', $meta['screenshots']['items'][0]['dimensions'] === ['width' => 1, 'height' => 1]);
$withImages = $screens->forTrade(['id' => 'x', 'screens' => ['before' => $png, 'after' => $png]], true, 1);
check('Screeny: limit obrazów', count($withImages['screenshots']['images']) === 1);
$journalState = $state;
$journalState['trades'][2]['checklistItems'] = ['Poziom'];
$journalState['trades'][2]['checklist'] = 1;
$journalContext = (new JournalService(new TradeService(), $setups, $plans, $screens))->context($journalState, 'w2', false);
$mcpCreatedJournalContext = (new JournalService(new TradeService(), $setups, $plans, $screens))->context($state, 'w2', false);
check(
    'Dziennik MCP: zachowuje wybrane punkty checklisty',
    $journalContext['checklist']['selectedPoints'] === ['Poziom']
    && $journalContext['checklist']['completed'] === 1
    && $mcpCreatedJournalContext['checklist']['selectedPoints'] === []
);

// --- Konto poza statystykami nie ukrywa transakcji z dziennika ---
$tradeReader = new TradeService();
$sanitizedMetrics = $tradeReader->sanitize($trade(['mae' => '', 'mfe' => ' ']), false);
$sanitizedZeroMetrics = $tradeReader->sanitize($trade(['mae' => 0, 'mfe' => 0]), false);
$sanitizedUnknownMeasures = $tradeReader->sanitize($trade([
    'result' => '', 'r' => ' ', 'fee' => '', 'durationMin' => '',
    'mae' => '', 'mfe' => ' ',
]), false);
check(
    'Transakcje: puste metryki API pozostają null, prawdziwe zero zostaje zerem',
    $sanitizedMetrics['mae'] === null && $sanitizedMetrics['mfe'] === null
    && $sanitizedZeroMetrics['mae'] === 0.0 && $sanitizedZeroMetrics['mfe'] === 0.0
    && $sanitizedUnknownMeasures['result'] === null && $sanitizedUnknownMeasures['r'] === null
    && $sanitizedUnknownMeasures['fee'] === null && $sanitizedUnknownMeasures['durationMin'] === null
);
$accountFilterState = [
    'accounts' => [
        ['name' => 'Wyłączone', 'include' => false],
        ['name' => 'Wliczane', 'include' => true],
    ],
    'trades' => [
        $trade(['id' => 'excluded-open', 'account' => 'Wyłączone', 'result' => null]),
        $trade(['id' => 'excluded-unknown-result', 'account' => 'Wyłączone', 'result' => '']),
        $trade(['id' => 'excluded-zero', 'account' => 'Wyłączone', 'result' => 0.0]),
        $trade(['id' => 'included-win', 'account' => 'Wliczane', 'result' => 5.0]),
        $trade(['id' => 'legacy-account-win', 'account' => 'Konto archiwalne', 'result' => 2.0]),
    ],
];
$allAccountTrades = $tradeReader->list($accountFilterState, ['status' => 'all', 'limit' => 10]);
$openAccountTrades = $tradeReader->list($accountFilterState, ['status' => 'open', 'limit' => 10]);
$closedAccountTrades = $tradeReader->list($accountFilterState, ['status' => 'closed', 'limit' => 10]);
$winAccountTrades = $tradeReader->list($accountFilterState, ['status' => 'win', 'limit' => 10]);
$lossAccountTrades = $tradeReader->list($accountFilterState, ['status' => 'loss', 'limit' => 10]);
check('Transakcje: konto wyłączone nadal widoczne w szczególe i liście', $tradeReader->find($accountFilterState, 'excluded-open') !== null
    && $tradeReader->find($accountFilterState, 'excluded-unknown-result') !== null
    && $allAccountTrades['total'] === 5 && $openAccountTrades['total'] === 2
    && $closedAccountTrades['total'] === 3 && $winAccountTrades['total'] === 2
    && $lossAccountTrades['total'] === 0);
check('Transakcje: filtr konta zgodny w statystykach i analityce', count($tradeReader->closedTrades($accountFilterState)) === 2
    && count($tradeReader->openTrades($accountFilterState)) === 0
    && count(Metrics::window($accountFilterState, null, null)['trades']) === 2);

// --- Rewizja trade coach: ocena A nie dowodzi zgodności z planem ---
$reviewState = $state;
$reviewState['tradeReviews']['l1'] = [
    'grade' => 'A', 'setup' => '', 'checklist' => 7, 'errors' => [],
    'emotion' => 'Spokój', 'note' => 'Ocena tradera',
];
$journalService = new JournalService(new TradeService(), $setups, $plans, $screens);
$draftReview = $journalService->review($reviewState, 'l1');
check('Journal: ocena A nie implikuje zatwierdzonego planu',
    $draftReview['reviewAvailable'] === true
    && $draftReview['decisionQuality']['grade'] === 'A'
    && $draftReview['planCompliance']['determined'] === false
    && in_array('plan_not_approved', $draftReview['planCompliance']['reasons'], true)
    && !str_contains($draftReview['decisionQuality']['assessment'], 'zgodna z planem')
);
check('Journal: pusty setup recenzji nie ukrywa setupu transakcji',
    $draftReview['setupCompliance']['effectiveSetup'] === 'Range'
    && $draftReview['setupCompliance']['setupSource'] === 'trade'
    && $draftReview['setupCompliance']['setupExists'] === true
    && $draftReview['setupCompliance']['rules'] === ['B1', 'B2']
    && $draftReview['setupCompliance']['matchesTrade'] === false
);
$noReview = $journalService->review($state, 'l1');
check('Journal: wskazówki używają aktualnych narzędzi zapisu',
    str_contains($noReview['hint'], 'create_trade_review')
    && !str_contains($noReview['hint'], 'propose_trade_review')
);

$prompts = new Prompts();
$dayPrompt = implode("\n", array_column($prompts->prepareTradingDay(), 'content'));
$tradePrompt = implode("\n", array_column($prompts->reviewTrade('l1'), 'content'));
check('MCP prompts: aktualne narzędzia zapisowe',
    str_contains($dayPrompt, 'create_day_plan')
    && !str_contains($dayPrompt, 'create_day_plan_proposal')
    && str_contains($tradePrompt, 'create_trade_review')
    && str_contains($tradePrompt, 'update_trade_review')
    && !str_contains($tradePrompt, 'propose_trade_review')
    && !str_contains($tradePrompt, 'update_trade_proposal')
);
check('MCP prompts: agent nie przypisuje ocenie A zgodności z planem',
    str_contains($tradePrompt, 'determined=false')
    && str_contains($tradePrompt, 'Ocena A nie dowodzi zgodności')
);

echo "\nWynik: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
