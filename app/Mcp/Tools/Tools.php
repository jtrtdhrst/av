<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

use AlphaVault\Auth;
use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Analytics\BadgesService;
use AlphaVault\Mcp\Analytics\CalculatorService;
use AlphaVault\Mcp\Analytics\DayPlanService;
use AlphaVault\Mcp\Analytics\JournalService;
use AlphaVault\Mcp\Analytics\MacroDetailsService;
use AlphaVault\Mcp\Analytics\PerformanceService;
use AlphaVault\Mcp\Analytics\PropContextService;
use AlphaVault\Mcp\Analytics\ReadinessService;
use AlphaVault\Mcp\Analytics\RiskService;
use AlphaVault\Mcp\Analytics\ScreensService;
use AlphaVault\Mcp\Analytics\SetupService;
use AlphaVault\Mcp\Analytics\SyncHistoryService;
use AlphaVault\Mcp\Analytics\TaxService;
use AlphaVault\Mcp\Proposals\ProposalBuilder;
use AlphaVault\Mcp\Proposals\ProposalException;
use AlphaVault\Mcp\Proposals\ProposalService;
use AlphaVault\Mcp\Support\CapitalService;
use AlphaVault\Mcp\Support\ConnectionsService;
use AlphaVault\Mcp\Support\DashboardService;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\EventsService;
use AlphaVault\Mcp\Support\MacroService;
use AlphaVault\Mcp\Support\ProfileService;
use AlphaVault\Mcp\Support\PropChallengeService;
use AlphaVault\Mcp\Support\StateRepository;
use AlphaVault\Mcp\Support\StatsService;
use AlphaVault\Mcp\Support\TradeContextService;
use AlphaVault\Mcp\Support\TradeService;
use AlphaVault\Wearables;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\RequestContext;

final class Tools
{
    private ProfileService $profiles;
    private StateRepository $states;
    private TradeService $trades;
    private StatsService $stats;
    private EventsService $events;
    private DashboardService $dashboard;
    private MacroService $macro;
    private CapitalService $capital;
    private ConnectionsService $connections;
    private TradeContextService $tradeContext;
    private PropChallengeService $prop;
    private ProposalBuilder $builder;
    private ProposalService $proposals;
    private DayPlanService $dayPlans;
    private RiskService $risk;
    private PerformanceService $performance;
    private SetupService $setupDetails;
    private ReadinessService $readiness;
    private PropContextService $propContext;
    private CalculatorService $calculator;
    private TaxService $tax;
    private MacroDetailsService $macroDetails;
    private SyncHistoryService $syncHistory;
    private BadgesService $badges;
    private JournalService $journal;
    private ScreensService $screens;
    private Wearables $wearables;
    private bool $writesEnabled;
    /** @var list<string> */
    private array $pilotIds;

    public function __construct(Config $config, Db $db, Auth $auth)
    {
        $this->writesEnabled = (bool) $config->mcp['writesEnabled'];
        $this->pilotIds = array_values(array_map('strval', (array) ($config->mcp['pilotDiscordIds'] ?? [])));
        $this->profiles = new ProfileService($config, $db, $auth);
        $this->states = new StateRepository($db);
        $this->trades = new TradeService();
        $this->stats = new StatsService($this->trades);
        $this->events = new EventsService($config, $db);
        $this->dashboard = new DashboardService($this->trades, $this->stats, $this->events);
        $this->macro = new MacroService($db);
        $this->capital = new CapitalService();
        $this->connections = new ConnectionsService($db);
        $this->tradeContext = new TradeContextService($this->trades);
        $this->prop = new PropChallengeService();
        $this->proposals = new ProposalService($db, $config);
        $this->builder = new ProposalBuilder($db, $this->proposals);
        $this->dayPlans = new DayPlanService();
        $this->risk = new RiskService($this->dayPlans);
        $this->performance = new PerformanceService($this->dayPlans, $this->risk);
        $this->setupDetails = new SetupService();
        $this->readiness = new ReadinessService();
        $this->propContext = new PropContextService($this->prop);
        $this->calculator = new CalculatorService();
        $this->tax = new TaxService();
        $this->macroDetails = new MacroDetailsService($db);
        $this->syncHistory = new SyncHistoryService($db);
        $this->badges = new BadgesService($this->dayPlans, $this->risk, $this->prop);
        $this->screens = new ScreensService();
        $this->journal = new JournalService($this->trades, $this->setupDetails, $this->dayPlans, $this->screens);
        $this->wearables = new Wearables($config, $db, $auth);
    }

    // ------------------------------------------------------------------
    // Odczyt
    // ------------------------------------------------------------------

    public function getProfile(RequestContext $context): array
    {
        return $this->profiles->profile($this->user($context));
    }

    public function getDashboard(RequestContext $context): array
    {
        return $this->dashboard->dashboard($this->state($context), $this->healthAccess($context));
    }

    public function listTrades(RequestContext $context, string $status = 'all', ?string $pair = null, ?string $setup = null, ?string $grade = null, ?string $from = null, ?string $to = null, int $limit = 20, int $offset = 0): array
    {
        if (!in_array($status, ['all', 'open', 'closed', 'win', 'loss'], true)) {
            throw new ToolCallException('status musi być all, open, closed, win albo loss.');
        }
        if ($grade !== null && !in_array(strtoupper($grade), ['A', 'B', 'C'], true)) {
            throw new ToolCallException('grade musi być A, B albo C.');
        }
        try {
            return $this->trades->list($this->state($context), [
                'status' => $status,
                'pair' => $pair,
                'setup' => $setup,
                'grade' => $grade,
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getTrade(RequestContext $context, string $id): array
    {
        if (trim($id) === '') {
            throw new ToolCallException('id nie może być pusty.');
        }
        $trade = $this->trades->find($this->state($context), $id);

        return ['found' => $trade !== null, 'trade' => $trade];
    }

    public function getStats(RequestContext $context, string $period = '30d'): array
    {
        try {
            return $this->stats->compute($this->state($context), $period);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getEvents(RequestContext $context, string $category = 'all', ?string $from = null, ?string $to = null, int $limit = 20): array
    {
        try {
            return $this->events->list([
                'category' => $category,
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getDayPlan(RequestContext $context, ?string $date = null): array
    {
        $date = $date ?? Dates::todayKey();
        if (!Dates::isValidKey($date)) {
            throw new ToolCallException('date musi mieć format YYYY-MM-DD.');
        }
        $state = $this->state($context);
        $plan = $state['plans'][$date] ?? null;

        return ['date' => $date, 'plan' => is_array($plan) ? $plan : null];
    }

    public function listSetups(RequestContext $context): array
    {
        $state = $this->state($context);
        $items = [];
        foreach (($state['setups'] ?? []) as $setup) {
            if (!is_array($setup)) {
                continue;
            }
            $name = (string) ($setup['name'] ?? '');
            $items[] = [
                'id' => $setup['id'] ?? null,
                'name' => $name,
                'grade' => $setup['grade'] ?? null,
                'timeframe' => $setup['tf'] ?? null,
                'rules' => array_values(array_map('strval', is_array($setup['rules'] ?? null) ? $setup['rules'] : [])),
                'avoid' => (bool) ($setup['bad'] ?? false),
                'stats' => $this->trades->setupStats($state, $name),
            ];
        }

        return ['items' => $items, 'total' => count($items)];
    }

    public function getMacroCalendar(RequestContext $context, ?string $from = null, ?string $to = null, ?string $currency = null, ?int $impact = null, int $limit = 50, int $offset = 0, bool $includePast = false): array
    {
        if (!$includePast && ($from === null || trim($from) === '')) {
            $from = Dates::todayKey();
        }
        try {
            return $this->macro->list([
                'from' => $from,
                'to' => $to,
                'currency' => $currency,
                'impact' => $impact,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPropChallenges(RequestContext $context): array
    {
        $state = $this->state($context);
        $challenges = is_array($state['prop']['challenges'] ?? null) ? $state['prop']['challenges'] : [];
        $accountNames = array_values(array_filter(array_map(
            static fn (array $account): string => (string) ($account['name'] ?? ''),
            array_filter((array) ($state['accounts'] ?? []), 'is_array')
        ), static fn (string $name): bool => $name !== ''));
        $items = [];
        foreach ($challenges as $challenge) {
            if (!is_array($challenge)) {
                continue;
            }
            $normalized = PropChallengeService::normalize($challenge);
            $metrics = $this->prop->compute($challenge, (array) ($state['trades'] ?? []), null, $accountNames);
            $items[] = array_merge($normalized, ['metrics' => $metrics]);
        }

        return [
            'items' => $items,
            'total' => count($items),
            'selectedId' => $state['prop']['selectedId'] ?? null,
        ];
    }

    public function getCheckinHistory(RequestContext $context, ?string $from = null, ?string $to = null, int $limit = 50, int $offset = 0): array
    {
        $fromTs = $this->dayStart($from, 'from');
        $toTs = $this->dayEnd($to, 'to');
        $items = [];
        foreach ((array) ($this->state($context)['checkins'] ?? []) as $checkin) {
            if (!is_array($checkin)) {
                continue;
            }
            $date = (string) ($checkin['date'] ?? '');
            if (!Dates::isValidKey($date)) {
                continue;
            }
            $ts = Dates::startOfLocalDay($date)->getTimestamp();
            if ($fromTs !== null && $ts < $fromTs) {
                continue;
            }
            if ($toTs !== null && $ts >= $toTs) {
                continue;
            }
            $items[] = [
                'date' => $date,
                'mood' => (string) ($checkin['mood'] ?? ''),
                'energy' => (int) ($checkin['energy'] ?? 0),
                'note' => (string) ($checkin['note'] ?? ''),
                'score' => (int) ($checkin['score'] ?? 0),
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        $total = count($items);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $page = array_slice($items, $offset, $limit);

        return [
            'items' => $page,
            'total' => $total,
            'nextOffset' => ($offset + count($page)) < $total ? $offset + count($page) : null,
        ];
    }

    public function getAccounts(RequestContext $context): array
    {
        $items = $this->capital->accounts($this->state($context));

        return ['items' => $items, 'total' => count($items)];
    }

    public function getCapitalSummary(RequestContext $context): array
    {
        return $this->capital->summary($this->state($context));
    }

    public function getConnectionStatus(RequestContext $context): array
    {
        return $this->connections->status((int) $this->user($context)['id']);
    }

    public function getTradeContext(RequestContext $context, string $tradeId): array
    {
        $id = trim($tradeId);
        if ($id === '') {
            throw new ToolCallException('tradeId nie może być pusty.');
        }
        $contextData = $this->tradeContext->context($this->state($context), $id);

        return $contextData ?? [
            'found' => false,
            'trade' => null,
            'review' => null,
            'setup' => null,
            'setupStats' => null,
            'plan' => null,
            'planMatch' => null,
            'date' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Plan dnia, dziennik, playbook, gotowość, ryzyko, prop, analityka
    // ------------------------------------------------------------------

    public function getDayPlanHistory(RequestContext $context, ?string $from = null, ?string $to = null, int $limit = 20, int $offset = 0): array
    {
        try {
            return $this->dayPlans->history($this->state($context), $from, $to, $limit, $offset);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getDayPlanCompliance(RequestContext $context, ?string $date = null, ?string $from = null, ?string $to = null, int $limit = 20, int $offset = 0): array
    {
        $state = $this->state($context);
        try {
            if ($date !== null && trim($date) !== '') {
                $day = $this->dayPlans->day($state, trim($date));

                return [
                    'hasData' => true,
                    'sampleSize' => $day['compliance']['alignedTrades'] + $day['compliance']['violatingTrades'],
                    'days' => [$day['compliance']],
                    'totalDays' => 1,
                    'nextOffset' => null,
                    'summary' => [
                        'tradesAnalyzed' => $day['compliance']['alignedTrades'] + $day['compliance']['violatingTrades'],
                        'alignedTrades' => $day['compliance']['alignedTrades'],
                        'violatingTrades' => $day['compliance']['violatingTrades'],
                        'undeterminedTrades' => $day['compliance']['undeterminedTrades'],
                        'compliancePct' => $day['compliance']['compliancePct'],
                        'aligned' => $day['compliance']['resultAligned'],
                        'violating' => $day['compliance']['resultViolating'],
                        'rulesViolated' => $day['compliance']['rulesViolated'],
                    ],
                    'range' => ['from' => $day['date'], 'to' => $day['date'], 'timezone' => Dates::TZ],
                    'filters' => ['date' => $day['date'], 'from' => null, 'to' => null],
                ];
            }

            return $this->dayPlans->compliance($state, $from, $to, $limit, $offset);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getDayPlanContext(RequestContext $context, ?string $date = null): array
    {
        $date = $date !== null && trim($date) !== '' ? trim($date) : Dates::todayKey();
        $state = $this->state($context);
        try {
            $day = $this->dayPlans->day($state, $date);
            $macro = $this->macro->list(['from' => $date, 'to' => $date, 'limit' => 20]);
            $checkin = $this->readiness->context($state, $date, null, $this->healthAccess($context));
            $protection = $this->risk->protection($state);

            return array_merge($day, [
                'macro' => $macro['items'],
                'checkin' => $checkin['checkin'],
                'readiness' => $checkin['readiness'],
                'protection' => [
                    'active' => $protection['active'],
                    'triggered' => $protection['triggered'],
                    'overridden' => $protection['overridden'],
                    'reason' => $protection['reason'],
                    'blockedUntil' => $protection['blockedUntil'],
                ],
                'riskRules' => $this->risk->rules($state),
                'currency' => 'USDT',
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getTradeJournalContext(RequestContext $context, string $tradeId): array
    {
        $id = trim($tradeId);
        if ($id === '') {
            throw new ToolCallException('tradeId nie może być pusty.');
        }

        return $this->journal->context($this->state($context), $id, false);
    }

    public function getTradeReview(RequestContext $context, string $tradeId): array
    {
        $id = trim($tradeId);
        if ($id === '') {
            throw new ToolCallException('tradeId nie może być pusty.');
        }

        return $this->journal->review($this->state($context), $id);
    }

    public function getTradeScreenshots(RequestContext $context, string $tradeId, ?string $kind = null, bool $includeImages = false, int $maxImages = 1): array
    {
        $id = trim($tradeId);
        if ($id === '') {
            throw new ToolCallException('tradeId nie może być pusty.');
        }
        if ($kind !== null && !in_array($kind, ['before', 'after'], true)) {
            throw new ToolCallException('kind musi być before albo after.');
        }
        $trade = null;
        foreach ((array) ($this->state($context)['trades'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                $trade = $row;
                break;
            }
        }
        if ($trade === null) {
            return ['found' => false, 'tradeId' => $id, 'screenshots' => null];
        }
        $screens = $this->screens->forTrade($trade, $includeImages, $maxImages);
        if ($kind !== null) {
            $screens['screenshots']['items'] = array_values(array_filter(
                $screens['screenshots']['items'],
                static fn (array $item): bool => $item['kind'] === $kind
            ));
            $screens['screenshots']['images'] = array_values(array_filter(
                $screens['screenshots']['images'],
                static fn (array $item): bool => $item['kind'] === $kind
            ));
        }

        return ['found' => true] + $screens;
    }

    public function getSetupDetails(RequestContext $context, string $setupId): array
    {
        if (trim($setupId) === '') {
            throw new ToolCallException('setupId nie może być pusty.');
        }

        return $this->setupDetails->details($this->state($context), $setupId);
    }

    public function getSetupPerformance(RequestContext $context, string $setupId, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        if (trim($setupId) === '') {
            throw new ToolCallException('setupId nie może być pusty.');
        }
        try {
            return $this->setupDetails->performance($this->state($context), $setupId, $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getReadinessContext(RequestContext $context, ?string $date = null): array
    {
        $access = $this->healthAccess($context);
        $wearables = $access === 'granted' ? $this->wearables->listForUser((int) $this->user($context)['id']) : null;
        try {
            return $this->readiness->context($this->state($context), $date, $wearables, $access);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getCheckinTradingCorrelation(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 5): array
    {
        try {
            return $this->readiness->correlation($this->state($context), $from, $to, $minSample, $this->healthAccess($context));
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getRiskRules(RequestContext $context): array
    {
        return $this->risk->rules($this->state($context));
    }

    public function getProtectionStatus(RequestContext $context): array
    {
        return $this->risk->protection($this->state($context));
    }

    public function getRiskViolations(RequestContext $context, ?string $from = null, ?string $to = null, int $limit = 50, int $offset = 0): array
    {
        try {
            return $this->risk->violations($this->state($context), $from, $to, $limit, $offset);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPropChallengeContext(RequestContext $context, ?string $challengeId = null): array
    {
        return $this->propContext->context($this->state($context), $challengeId);
    }

    public function getPropChallengeRisk(RequestContext $context, ?string $challengeId = null): array
    {
        return $this->propContext->risk($this->state($context), $challengeId);
    }

    public function getPerformanceBySetup(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        try {
            return $this->performance->bySetup($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPerformanceByEmotion(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        try {
            return $this->performance->byEmotion($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPerformanceBySession(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        try {
            return $this->performance->bySession($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPerformanceByError(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        try {
            return $this->performance->byError($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPerformanceByGrade(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        try {
            return $this->performance->byGrade($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPerformanceByMarket(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        try {
            return $this->performance->byMarket($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getPerformanceByDayPlanCompliance(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 3): array
    {
        try {
            return $this->performance->byPlanCompliance($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getTradePatterns(RequestContext $context, ?string $from = null, ?string $to = null, int $minSample = 5): array
    {
        try {
            return $this->performance->patterns($this->state($context), $from, $to, $minSample);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getCalculatorResult(
        RequestContext $context,
        float $balance,
        float $riskPercent,
        string $direction,
        float $entry,
        float $stopLoss,
        string $mode = 'crypto',
        ?float $takeProfit = null,
        float $leverage = 1,
        ?string $instrument = null,
        ?string $currency = null
    ): array {
        if ($mode === 'fx' && ($instrument === null || trim($instrument) === '')) {
            throw new ToolCallException('W trybie fx instrument jest wymagany.');
        }
        try {
            return $this->calculator->calculate([
                'mode' => $mode,
                'direction' => $direction,
                'balance' => $balance,
                'riskPercent' => $riskPercent,
                'entry' => $entry,
                'stopLoss' => $stopLoss,
                'takeProfit' => $takeProfit,
                'leverage' => $leverage,
                'instrument' => $instrument,
                'currency' => $currency,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getAccountTransactions(RequestContext $context, ?string $account = null, ?string $type = null, ?string $from = null, ?string $to = null, int $limit = 50, int $offset = 0): array
    {
        try {
            return $this->tax->transactions($this->state($context), [
                'account' => $account,
                'type' => $type,
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getTaxSummary(RequestContext $context, ?int $year = null): array
    {
        try {
            return $this->tax->summary($this->state($context), $year);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getMacroEventDetails(RequestContext $context, string $eventId): array
    {
        try {
            return $this->macroDetails->details($eventId);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getIntegrationSyncHistory(RequestContext $context, ?string $provider = null, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        try {
            return $this->syncHistory->history((int) $this->user($context)['id'], [
                'provider' => $provider,
                'status' => $status,
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (\InvalidArgumentException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    public function getBadgesProgress(RequestContext $context): array
    {
        return $this->badges->progress($this->state($context));
    }

    // ------------------------------------------------------------------
    // Cykl życia propozycji
    // ------------------------------------------------------------------

    public function listPendingProposals(RequestContext $context): array
    {
        $user = $this->user($context);
        $items = $this->proposals->list((int) $user['id'], 'pending');

        return ['items' => $items, 'total' => count($items)];
    }

    public function getProposalStatus(RequestContext $context, string $proposalId): array
    {
        $id = trim($proposalId);
        if ($id === '') {
            throw new ToolCallException('proposalId nie może być pusty.');
        }
        $user = $this->user($context);
        try {
            return $this->proposals->status((int) $user['id'], $id);
        } catch (ProposalException $err) {
            // Nieistniejąca propozycja to NOT_FOUND, nie błąd argumentu: stabilna
            // odpowiedź found=false, tak samo jak get_trade i get_trade_context.
            if ($err->errorCode === 'not_found') {
                return [
                    'found' => false,
                    'proposalId' => $id,
                    'status' => null,
                    'diffHash' => null,
                    'changes' => [],
                    'diff' => [],
                    'canApprove' => false,
                    'approvalCodeActive' => false,
                    'note' => 'Propozycja nie istnieje albo należy do innego użytkownika.',
                ];
            }
            throw new ToolCallException($err->getMessage());
        }
    }

    public function approveProposal(
        RequestContext $context,
        string $proposalId,
        string $expectedDiffHash,
        string $confirmationCode,
        string $idempotencyKey
    ): array {
        return $this->propose($context, fn (int $userId, string $client): array => $this->proposals->approveWithCode(
            $userId,
            $proposalId,
            $expectedDiffHash,
            $confirmationCode,
            $idempotencyKey,
            $client
        ));
    }

    public function rejectProposal(RequestContext $context, string $proposalId, string $idempotencyKey): array
    {
        return $this->propose($context, fn (int $userId, string $client): array => $this->proposals->rejectWithKey(
            $userId,
            $proposalId,
            $idempotencyKey,
            $client
        ));
    }

    // ------------------------------------------------------------------
    // Propozycje zmian
    // ------------------------------------------------------------------

    public function proposeCreateTrade(
        RequestContext $context,
        string $pair,
        string $dir,
        float $entry,
        float $size,
        string $idempotencyKey,
        ?float $sl = null,
        ?float $tp = null,
        ?string $setup = null,
        ?string $emotion = null,
        ?string $grade = null,
        ?int $checklist = null,
        ?int $checkTotal = null,
        ?array $errors = null,
        ?string $note = null,
        ?string $date = null,
        ?string $account = null
    ): array {
        $args = [
            'pair' => $pair,
            'dir' => $dir,
            'entry' => $entry,
            'size' => $size,
            'idempotencyKey' => $idempotencyKey,
        ];
        foreach (['sl' => $sl, 'tp' => $tp, 'setup' => $setup, 'emotion' => $emotion, 'grade' => $grade, 'checklist' => $checklist, 'checkTotal' => $checkTotal, 'errors' => $errors, 'note' => $note, 'date' => $date, 'account' => $account] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->createTrade($userId, $args, $client));
    }

    public function proposeCloseTrade(RequestContext $context, string $tradeId, float $exitPrice, string $idempotencyKey): array
    {
        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->closeTrade($userId, [
            'tradeId' => $tradeId,
            'exitPrice' => $exitPrice,
            'idempotencyKey' => $idempotencyKey,
        ], $client));
    }

    public function proposeTradeReview(
        RequestContext $context,
        string $tradeId,
        string $idempotencyKey,
        ?string $note = null,
        ?string $grade = null,
        ?string $setup = null,
        ?string $emotion = null,
        ?int $checklist = null,
        ?array $errors = null
    ): array {
        $args = ['tradeId' => $tradeId, 'idempotencyKey' => $idempotencyKey];
        foreach (['note' => $note, 'grade' => $grade, 'setup' => $setup, 'emotion' => $emotion, 'checklist' => $checklist, 'errors' => $errors] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->tradeReview($userId, $args, $client));
    }

    public function proposeSaveDayPlan(
        RequestContext $context,
        string $idempotencyKey,
        ?string $date = null,
        ?string $market = null,
        ?string $bias = null,
        ?string $why = null,
        ?array $levels = null,
        ?string $ifMain = null,
        ?string $thenMain = null,
        ?string $ifAlt = null,
        ?string $thenAlt = null,
        ?float $riskPercent = null,
        ?int $maxTrades = null,
        ?string $until = null,
        ?string $notDo = null
    ): array {
        $args = ['idempotencyKey' => $idempotencyKey];
        foreach ([
            'date' => $date, 'market' => $market, 'bias' => $bias, 'why' => $why, 'levels' => $levels,
            'ifMain' => $ifMain, 'thenMain' => $thenMain, 'ifAlt' => $ifAlt, 'thenAlt' => $thenAlt,
            'riskPercent' => $riskPercent, 'maxTrades' => $maxTrades, 'until' => $until, 'notDo' => $notDo,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveDayPlan($userId, $args, $client));
    }

    public function proposeSaveCheckin(RequestContext $context, string $mood, int $energy, string $idempotencyKey, ?string $note = null): array
    {
        $args = ['mood' => $mood, 'energy' => $energy, 'idempotencyKey' => $idempotencyKey];
        if ($note !== null) {
            $args['note'] = $note;
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveCheckin($userId, $args, $client));
    }

    public function proposeSaveSetup(
        RequestContext $context,
        string $name,
        array $rules,
        string $idempotencyKey,
        ?string $id = null,
        ?string $tf = null,
        ?string $grade = null,
        ?bool $bad = null,
        ?int $seed = null
    ): array {
        $args = ['name' => $name, 'rules' => $rules, 'idempotencyKey' => $idempotencyKey];
        foreach (['id' => $id, 'tf' => $tf, 'grade' => $grade, 'bad' => $bad, 'seed' => $seed] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveSetup($userId, $args, $client));
    }

    public function proposeSavePropChallenge(
        RequestContext $context,
        string $idempotencyKey,
        ?string $id = null,
        ?string $name = null,
        ?string $firm = null,
        ?string $templateId = null,
        ?string $phase = null,
        ?string $account = null,
        ?string $currency = null,
        ?float $accountSize = null,
        ?float $startBalance = null,
        ?float $targetPct = null,
        ?float $dailyLossPct = null,
        ?float $maxLossPct = null,
        ?string $drawdownType = null,
        ?string $dailyBasis = null,
        ?int $minDays = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $notes = null
    ): array {
        $args = ['idempotencyKey' => $idempotencyKey];
        foreach ([
            'id' => $id, 'name' => $name, 'firm' => $firm, 'templateId' => $templateId, 'phase' => $phase,
            'account' => $account, 'currency' => $currency, 'accountSize' => $accountSize, 'startBalance' => $startBalance,
            'targetPct' => $targetPct, 'dailyLossPct' => $dailyLossPct, 'maxLossPct' => $maxLossPct,
            'drawdownType' => $drawdownType, 'dailyBasis' => $dailyBasis, 'minDays' => $minDays,
            'startDate' => $startDate, 'endDate' => $endDate, 'notes' => $notes,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->savePropChallenge($userId, $args, $client));
    }

    public function proposeSaveAccount(
        RequestContext $context,
        string $name,
        string $idempotencyKey,
        ?string $id = null,
        ?string $exchange = null,
        ?string $type = null,
        ?string $currency = null,
        ?bool $include = null,
        ?bool $primary = null,
        ?string $lev = null,
        ?string $goal = null,
        ?string $date = null
    ): array {
        $args = ['name' => $name, 'idempotencyKey' => $idempotencyKey];
        foreach ([
            'id' => $id, 'exchange' => $exchange, 'type' => $type, 'currency' => $currency,
            'include' => $include, 'primary' => $primary, 'lev' => $lev, 'goal' => $goal, 'date' => $date,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveAccount($userId, $args, $client));
    }

    public function proposeSaveCapitalFlow(
        RequestContext $context,
        string $type,
        float $amount,
        string $account,
        string $currency,
        string $idempotencyKey,
        ?string $id = null,
        ?string $date = null,
        ?float $fiat = null,
        ?float $fx = null,
        ?string $note = null
    ): array {
        $args = [
            'type' => $type,
            'amount' => $amount,
            'account' => $account,
            'currency' => $currency,
            'idempotencyKey' => $idempotencyKey,
        ];
        foreach (['id' => $id, 'date' => $date, 'fiat' => $fiat, 'fx' => $fx, 'note' => $note] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveCapitalFlow($userId, $args, $client));
    }

    public function createDayPlanProposal(
        RequestContext $context,
        string $idempotencyKey,
        ?array $markets = null,
        ?string $bias = null,
        ?string $reason = null,
        ?array $levels = null,
        ?array $scenarioA = null,
        ?array $scenarioB = null,
        ?float $riskPercent = null,
        ?int $maxTrades = null,
        ?string $until = null,
        ?array $rulesNotToDo = null,
        ?string $date = null
    ): array {
        $args = ['idempotencyKey' => $idempotencyKey];
        foreach ([
            'markets' => $markets, 'bias' => $bias, 'why' => $reason, 'levels' => $levels,
            'scenarioA' => $scenarioA, 'scenarioB' => $scenarioB, 'riskPercent' => $riskPercent,
            'maxTrades' => $maxTrades, 'until' => $until, 'rulesNotToDo' => $rulesNotToDo, 'date' => $date,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveDayPlan($userId, $args, $client));
    }

    public function createTradeProposal(
        RequestContext $context,
        string $pair,
        string $dir,
        float $entry,
        float $size,
        string $idempotencyKey,
        ?float $exit = null,
        ?float $sl = null,
        ?float $tp = null,
        ?float $fee = null,
        ?string $setup = null,
        ?string $emotion = null,
        ?string $grade = null,
        ?int $checklist = null,
        ?int $checkTotal = null,
        ?array $errors = null,
        ?string $note = null,
        ?string $date = null,
        ?string $time = null,
        ?string $account = null,
        ?string $session = null
    ): array {
        $args = [
            'pair' => $pair,
            'dir' => $dir,
            'entry' => $entry,
            'size' => $size,
            'idempotencyKey' => $idempotencyKey,
        ];
        foreach ([
            'exit' => $exit, 'sl' => $sl, 'tp' => $tp, 'fee' => $fee, 'setup' => $setup, 'emotion' => $emotion,
            'grade' => $grade, 'checklist' => $checklist, 'checkTotal' => $checkTotal, 'errors' => $errors,
            'note' => $note, 'date' => $date, 'time' => $time, 'account' => $account, 'session' => $session,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->createTradeProposal($userId, $args, $client));
    }

    public function updateTradeProposal(RequestContext $context, string $tradeId, array $changes, string $idempotencyKey): array
    {
        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->updateTrade($userId, [
            'tradeId' => $tradeId,
            'changes' => $changes,
            'idempotencyKey' => $idempotencyKey,
        ], $client));
    }

    public function createSetupProposal(
        RequestContext $context,
        string $name,
        array $entryRules,
        string $idempotencyKey,
        ?string $description = null,
        ?string $timeframes = null,
        ?string $confirmation = null,
        ?string $invalidation = null,
        ?string $stopLoss = null,
        ?float $minRR = null,
        ?array $exitRules = null,
        ?array $examples = null,
        ?string $grade = null
    ): array {
        $args = ['name' => $name, 'rules' => $entryRules, 'idempotencyKey' => $idempotencyKey];
        foreach ([
            'description' => $description, 'tf' => $timeframes, 'confirmation' => $confirmation,
            'invalidation' => $invalidation, 'stopLoss' => $stopLoss, 'minRR' => $minRR,
            'exitRules' => $exitRules, 'examples' => $examples, 'grade' => $grade,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveSetup($userId, $args, $client));
    }

    public function updateSetupProposal(RequestContext $context, string $setupId, array $changes, string $idempotencyKey): array
    {
        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->updateSetup($userId, [
            'setupId' => $setupId,
            'changes' => $changes,
            'idempotencyKey' => $idempotencyKey,
        ], $client));
    }

    public function createCheckinProposal(RequestContext $context, string $mood, int $energy, string $idempotencyKey, ?string $note = null, ?string $date = null): array
    {
        $args = ['mood' => $mood, 'energy' => $energy, 'idempotencyKey' => $idempotencyKey];
        if ($note !== null) {
            $args['note'] = $note;
        }
        if ($date !== null) {
            $args['date'] = $date;
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveCheckin($userId, $args, $client));
    }

    public function createRiskRulesProposal(RequestContext $context, array $changes, string $reason, string $idempotencyKey): array
    {
        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->saveRiskRules($userId, [
            'changes' => $changes,
            'reason' => $reason,
            'idempotencyKey' => $idempotencyKey,
        ], $client));
    }

    public function createPropChallengeProposal(
        RequestContext $context,
        string $idempotencyKey,
        string $templateId = 'custom',
        ?string $name = null,
        ?string $account = null,
        ?float $accountSize = null,
        ?float $startBalance = null,
        ?float $targetPct = null,
        ?float $dailyLossPct = null,
        ?float $maxLossPct = null,
        ?string $drawdownType = null,
        ?string $dailyBasis = null,
        ?int $minDays = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $notes = null,
        ?string $currency = null,
        ?string $phase = null
    ): array {
        $args = ['templateId' => $templateId, 'idempotencyKey' => $idempotencyKey];
        foreach ([
            'name' => $name, 'account' => $account, 'accountSize' => $accountSize, 'startBalance' => $startBalance,
            'targetPct' => $targetPct, 'dailyLossPct' => $dailyLossPct, 'maxLossPct' => $maxLossPct,
            'drawdownType' => $drawdownType, 'dailyBasis' => $dailyBasis, 'minDays' => $minDays,
            'startDate' => $startDate, 'endDate' => $endDate, 'notes' => $notes, 'currency' => $currency,
            'phase' => $phase,
        ] as $key => $value) {
            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->savePropChallenge($userId, $args, $client));
    }

    public function updatePropChallengeProposal(RequestContext $context, string $challengeId, array $changes, string $idempotencyKey): array
    {
        $args = ['id' => $challengeId, 'idempotencyKey' => $idempotencyKey];
        $allowed = [
            'name', 'firm', 'templateId', 'phase', 'account', 'currency', 'accountSize', 'startBalance',
            'targetPct', 'dailyLossPct', 'maxLossPct', 'drawdownType', 'dailyBasis', 'minDays',
            'startDate', 'endDate', 'notes',
        ];
        foreach ($changes as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new ToolCallException('Nieznane pole zmiany challenge\'u: ' . $key . '.');
            }
            $args[$key] = $value;
        }

        return $this->propose($context, fn (int $userId, string $client): array => $this->builder->savePropChallenge($userId, $args, $client));
    }

    private function propose(RequestContext $context, callable $build): array
    {
        if (!$this->writesEnabled) {
            throw new ToolCallException('Propozycje zmian są wyłączone (MCP_WRITES_ENABLED=false).');
        }
        $token = $context->getAccessToken();
        if ($token === null) {
            throw new ToolCallException('Brak tokenu OAuth.');
        }
        $user = $this->user($context);
        if ($this->pilotIds !== [] && !in_array((string) ($user['discordId'] ?? ''), $this->pilotIds, true)) {
            throw new ToolCallException('Zapis MCP jest włączony tylko dla kont pilotażowych.');
        }
        $client = $token->getClientId() ?? 'Agent MCP';
        try {
            return $build((int) $user['id'], $client);
        } catch (ProposalException $err) {
            throw new ToolCallException($err->getMessage());
        }
    }

    /**
     * Dostęp do danych zdrowotnych: zakres alphavault.health i aktywne
     * połączenie urządzenia z zapisaną zgodą użytkownika.
     */
    private function healthAccess(RequestContext $context): string
    {
        $token = $context->getAccessToken();
        if ($token === null || !$token->hasScope('alphavault.health')) {
            return 'scope_missing';
        }
        try {
            $summary = $this->wearables->listForUser((int) $this->user($context)['id']);
        } catch (\Throwable) {
            return 'unavailable';
        }
        foreach ((array) ($summary['devices'] ?? []) as $device) {
            if (is_array($device) && !empty($device['connected'])) {
                return 'granted';
            }
        }

        return 'no_device';
    }

    private function user(RequestContext $context): array
    {        $token = $context->getAccessToken();
        $subject = $token?->getSubject();
        if ($subject === null || !ctype_digit($subject)) {
            throw new ToolCallException('Brak zalogowanego użytkownika AlphaVault.');
        }
        $user = $this->profiles->findUser((int) $subject);
        if ($user === null) {
            throw new ToolCallException('Konto AlphaVault nie istnieje.');
        }

        return $user;
    }

    private function state(RequestContext $context): array
    {
        $user = $this->user($context);
        $loaded = $this->states->load((int) $user['id']);

        return $loaded['state'] ?? StateRepository::emptyState();
    }

    private function dayStart(?string $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!Dates::isValidKey($value)) {
            throw new ToolCallException($field . ' musi mieć format YYYY-MM-DD.');
        }

        return Dates::startOfLocalDay($value)->getTimestamp();
    }

    private function dayEnd(?string $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!Dates::isValidKey($value)) {
            throw new ToolCallException($field . ' musi mieć format YYYY-MM-DD.');
        }

        return Dates::startOfLocalDay($value)->modify('+1 day')->getTimestamp();
    }
}
