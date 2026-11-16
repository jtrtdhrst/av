<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

final class Schemas
{
    public static function object(array $properties = [], array $required = [], bool $additionalProperties = true): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => $additionalProperties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    public static function nullable(array $schema): array
    {
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    private static function idem(): array
    {
        return ['type' => 'string', 'minLength' => 8, 'maxLength' => 120];
    }

    private static function input(array $properties = [], array $required = [], bool $additionalProperties = false): array
    {
        return self::object($properties, $required, $additionalProperties);
    }

    // ------------------------------------------------------------------
    // Odczyt
    // ------------------------------------------------------------------

    public static function inputProfile(): array { return self::input(); }
    public static function inputDashboard(): array { return self::input(); }

    public static function inputListTrades(): array
    {
        return self::input([
            'status' => ['type' => 'string', 'enum' => ['all', 'open', 'closed', 'win', 'loss'], 'default' => 'all'],
            'pair' => ['type' => 'string', 'maxLength' => 32],
            'setup' => ['type' => 'string', 'maxLength' => 100],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputGetTrade(): array
    {
        return self::input(['id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]], ['id']);
    }

    public static function inputStats(): array
    {
        return self::input(['period' => ['type' => 'string', 'enum' => ['7d', '30d', '90d', 'all'], 'default' => '30d']]);
    }

    public static function inputEvents(): array
    {
        return self::input([
            'category' => ['type' => 'string', 'enum' => ['all', 'live', 'consultation', 'unassigned'], 'default' => 'all'],
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputDayPlan(): array
    {
        return self::input(['date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']]);
    }

    public static function inputSetups(): array { return self::input(); }

    public static function inputMacroCalendar(): array
    {
        return self::input([
            'from' => ['type' => 'string', 'maxLength' => 40],
            'to' => ['type' => 'string', 'maxLength' => 40],
            'currency' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2,8}$'],
            'impact' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
            'includePast' => ['type' => 'boolean', 'default' => false, 'description' => 'Domyślnie kalendarz zwraca wydarzenia od dziś w przód. true udostępnia również historię.'],
        ]);
    }

    public static function inputPropChallenges(): array { return self::input(); }

    public static function inputCheckinHistory(): array
    {
        return self::input([
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputAccounts(): array { return self::input(); }
    public static function inputCapitalSummary(): array { return self::input(); }
    public static function inputConnectionStatus(): array { return self::input(); }

    public static function inputTradeContext(): array
    {
        return self::input(['tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]], ['tradeId']);
    }

    // ------------------------------------------------------------------
    // Plan dnia, dziennik, playbook, gotowość, ryzyko, prop, analityka
    // ------------------------------------------------------------------

    private static function dateRange(): array
    {
        return [
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
        ];
    }

    private static function minSample(): array
    {
        return ['minSample' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 100, 'default' => 3]];
    }

    public static function inputDayPlanHistory(): array
    {
        return self::input(self::dateRange() + [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputDayPlanCompliance(): array
    {
        return self::input([
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
        ] + self::dateRange() + [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputDayPlanContext(): array
    {
        return self::input(['date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']]);
    }

    public static function inputTradeJournal(): array
    {
        return self::input(['tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128]], ['tradeId']);
    }

    public static function inputTradeScreenshots(): array
    {
        return self::input([
            'tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'kind' => ['type' => 'string', 'enum' => ['before', 'after']],
            'includeImages' => ['type' => 'boolean', 'default' => false, 'description' => 'true zwraca obrazy w base64 w limitach 2 obrazów i 200 KB każdy.'],
            'maxImages' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2, 'default' => 1],
        ], ['tradeId']);
    }

    public static function inputSetupDetails(): array
    {
        return self::input(['setupId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]], ['setupId']);
    }

    public static function inputSetupPerformance(): array
    {
        return self::input(['setupId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]] + self::dateRange() + self::minSample(), ['setupId']);
    }

    public static function inputReadiness(): array
    {
        return self::input(['date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']]);
    }

    public static function inputCheckinCorrelation(): array
    {
        return self::input(self::dateRange() + [
            'minSample' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 100, 'default' => 5],
        ]);
    }

    public static function inputRiskViolations(): array
    {
        return self::input(self::dateRange() + [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputPropContext(): array
    {
        return self::input(['challengeId' => ['type' => 'string', 'maxLength' => 64]]);
    }

    public static function inputPerformance(): array
    {
        return self::input(self::dateRange() + self::minSample());
    }

    public static function inputTradePatterns(): array
    {
        return self::input(self::dateRange() + [
            'minSample' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 100, 'default' => 5],
        ]);
    }

    public static function inputCalculator(): array
    {
        return self::input([
            'mode' => ['type' => 'string', 'enum' => ['crypto', 'fx'], 'default' => 'crypto'],
            'balance' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'riskPercent' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100],
            'direction' => ['type' => 'string', 'enum' => ['long', 'short']],
            'entry' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'stopLoss' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'takeProfit' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'leverage' => ['type' => 'number', 'minimum' => 1, 'maximum' => 1000, 'default' => 1],
            'instrument' => ['type' => 'string', 'maxLength' => 40],
            'currency' => ['type' => 'string', 'pattern' => '^[A-Za-z]{3,5}$'],
        ], ['balance', 'riskPercent', 'direction', 'entry', 'stopLoss']);
    }

    public static function inputAccountTransactions(): array
    {
        return self::input([
            'account' => ['type' => 'string', 'maxLength' => 100],
            'type' => ['type' => 'string', 'enum' => ['deposit', 'withdrawal']],
        ] + self::dateRange() + [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputTaxSummary(): array
    {
        return self::input(['year' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100]]);
    }

    public static function inputMacroDetails(): array
    {
        return self::input(['eventId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]], ['eventId']);
    }

    public static function inputSyncHistory(): array
    {
        return self::input([
            'provider' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{2,16}$'],
            'status' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{2,20}$'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public static function inputBadges(): array { return self::input(); }


    // ------------------------------------------------------------------
    // Propozycje
    // ------------------------------------------------------------------

    public static function inputProposeCreateTrade(): array
    {
        return self::input([
            'pair' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._/-]{2,20}$'],
            'dir' => ['type' => 'string', 'enum' => ['long', 'short', 'l', 's']],
            'entry' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'size' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'sl' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'tp' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'setup' => ['type' => 'string', 'maxLength' => 100],
            'emotion' => ['type' => 'string', 'maxLength' => 30],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'checkTotal' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            'errors' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 60]],
            'note' => ['type' => 'string', 'maxLength' => 2000],
            'date' => ['type' => 'string', 'maxLength' => 40],
            'account' => ['type' => 'string', 'maxLength' => 100],
            'idempotencyKey' => self::idem(),
        ], ['pair', 'dir', 'entry', 'size', 'idempotencyKey']);
    }

    public static function inputProposeCloseTrade(): array
    {
        return self::input([
            'tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'exitPrice' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'idempotencyKey' => self::idem(),
        ], ['tradeId', 'exitPrice', 'idempotencyKey']);
    }

    public static function inputProposeTradeReview(): array
    {
        return self::input([
            'tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'note' => ['type' => 'string', 'maxLength' => 2000],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'setup' => ['type' => 'string', 'maxLength' => 100],
            'emotion' => ['type' => 'string', 'maxLength' => 30],
            'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'errors' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 60]],
            'idempotencyKey' => self::idem(),
        ], ['tradeId', 'idempotencyKey']);
    }

    public static function inputProposeDayPlan(): array
    {
        return self::input([
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'market' => ['type' => 'string', 'maxLength' => 20],
            'bias' => ['type' => 'string', 'enum' => ['long', 'short', 'neutral', 'none']],
            'why' => ['type' => 'string', 'maxLength' => 2000],
            'levels' => [
                'type' => 'array', 'maxItems' => 10,
                'items' => self::input([
                    'type' => ['type' => 'string', 'maxLength' => 20],
                    'price' => ['type' => 'string', 'maxLength' => 30],
                    'note' => ['type' => 'string', 'maxLength' => 200],
                ]),
            ],
            'ifMain' => ['type' => 'string', 'maxLength' => 2000],
            'thenMain' => ['type' => 'string', 'maxLength' => 2000],
            'ifAlt' => ['type' => 'string', 'maxLength' => 2000],
            'thenAlt' => ['type' => 'string', 'maxLength' => 2000],
            'riskPercent' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100],
            'maxTrades' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            'until' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'notDo' => ['type' => 'string', 'maxLength' => 2000],
            'idempotencyKey' => self::idem(),
        ], ['idempotencyKey']);
    }

    public static function inputProposeCheckin(): array
    {
        return self::input([
            'mood' => ['type' => 'string', 'maxLength' => 30],
            'energy' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'note' => ['type' => 'string', 'maxLength' => 500],
            'idempotencyKey' => self::idem(),
        ], ['mood', 'energy', 'idempotencyKey']);
    }

    public static function inputProposeSetup(): array
    {
        return self::input([
            'id' => ['type' => 'string', 'maxLength' => 64],
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
            'tf' => ['type' => 'string', 'maxLength' => 40],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'rules' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5, 'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200]],
            'bad' => ['type' => 'boolean'],
            'seed' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999],
            'idempotencyKey' => self::idem(),
        ], ['name', 'rules', 'idempotencyKey']);
    }

    public static function inputProposeAccount(): array
    {
        return self::input([
            'id' => ['type' => 'string', 'maxLength' => 64],
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
            'exchange' => ['type' => 'string', 'maxLength' => 80],
            'type' => ['type' => 'string', 'maxLength' => 40],
            'currency' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'EUR', 'PLN']],
            'include' => ['type' => 'boolean'],
            'primary' => ['type' => 'boolean'],
            'lev' => ['type' => 'string', 'maxLength' => 20],
            'goal' => ['type' => 'string', 'maxLength' => 200],
            'date' => ['type' => 'string', 'maxLength' => 20],
            'idempotencyKey' => self::idem(),
        ], ['name', 'idempotencyKey']);
    }

    public static function inputProposeCapitalFlow(): array
    {
        return self::input([
            'id' => ['type' => 'string', 'maxLength' => 64],
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'account' => ['type' => 'string', 'maxLength' => 100],
            'type' => ['type' => 'string', 'enum' => ['deposit', 'withdrawal', 'Wpłata', 'Wypłata']],
            'amount' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'currency' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'EUR', 'PLN']],
            'fiat' => ['type' => 'number', 'minimum' => 0],
            'fx' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 1000],
            'note' => ['type' => 'string', 'maxLength' => 200],
            'idempotencyKey' => self::idem(),
        ], ['type', 'amount', 'account', 'currency', 'idempotencyKey']);
    }

    public static function inputListPendingProposals(): array { return self::input(); }

    public static function inputProposalStatus(): array
    {
        return self::input(['proposalId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]], ['proposalId']);
    }

    public static function inputApproveProposal(): array
    {
        return self::input([
            'proposalId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'expectedDiffHash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
            'confirmationCode' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 12],
            'idempotencyKey' => self::idem(),
        ], ['proposalId', 'expectedDiffHash', 'confirmationCode', 'idempotencyKey']);
    }

    public static function inputRejectProposal(): array
    {
        return self::input([
            'proposalId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'idempotencyKey' => self::idem(),
        ], ['proposalId', 'idempotencyKey']);
    }

    // ------------------------------------------------------------------
    // Nowe propozycje (plan, transakcja, setup, check-in, ryzyko, prop)
    // ------------------------------------------------------------------

    public static function inputCreateDayPlanProposal(): array
    {
        $scenario = self::input([
            'condition' => ['type' => 'string', 'maxLength' => 2000],
            'action' => ['type' => 'string', 'maxLength' => 2000],
        ]);

        return self::input([
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'markets' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20]],
            'bias' => ['type' => 'string', 'enum' => ['long', 'short', 'neutral', 'none']],
            'reason' => ['type' => 'string', 'maxLength' => 2000],
            'levels' => [
                'type' => 'array', 'maxItems' => 10,
                'items' => self::input([
                    'type' => ['type' => 'string', 'maxLength' => 20],
                    'price' => ['type' => 'string', 'maxLength' => 30],
                    'note' => ['type' => 'string', 'maxLength' => 200],
                ]),
            ],
            'scenarioA' => $scenario,
            'scenarioB' => $scenario,
            'riskPercent' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100],
            'maxTrades' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            'until' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'rulesNotToDo' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300]],
            'idempotencyKey' => self::idem(),
        ], ['idempotencyKey']);
    }

    public static function inputCreateTradeProposal(): array
    {
        return self::input([
            'pair' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._/-]{2,20}$'],
            'dir' => ['type' => 'string', 'enum' => ['long', 'short', 'l', 's']],
            'entry' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'exit' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'size' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'sl' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'tp' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'fee' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
            'setup' => ['type' => 'string', 'maxLength' => 100],
            'emotion' => ['type' => 'string', 'maxLength' => 30],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'checkTotal' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            'errors' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 60]],
            'note' => ['type' => 'string', 'maxLength' => 2000],
            'date' => ['type' => 'string', 'maxLength' => 40],
            'time' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'account' => ['type' => 'string', 'maxLength' => 100],
            'session' => ['type' => 'string', 'maxLength' => 40],
            'idempotencyKey' => self::idem(),
        ], ['pair', 'dir', 'entry', 'size', 'idempotencyKey']);
    }

    public static function inputUpdateTradeProposal(): array
    {
        return self::input([
            'tradeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'changes' => self::input([
                'pair' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._/-]{2,20}$'],
                'dir' => ['type' => 'string', 'enum' => ['long', 'short', 'l', 's']],
                'entry' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'exit' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'sl' => self::nullable(['type' => 'number', 'exclusiveMinimum' => 0]),
                'tp' => self::nullable(['type' => 'number', 'exclusiveMinimum' => 0]),
                'size' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'fee' => ['type' => 'number', 'minimum' => 0],
                'setup' => ['type' => 'string', 'maxLength' => 100],
                'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
                'emotion' => ['type' => 'string', 'maxLength' => 30],
                'errors' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 60]],
                'note' => ['type' => 'string', 'maxLength' => 2000],
                'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
                'checkTotal' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                'session' => ['type' => 'string', 'maxLength' => 40],
                'date' => ['type' => 'string', 'maxLength' => 40],
            ], [], false),
            'idempotencyKey' => self::idem(),
        ], ['tradeId', 'changes', 'idempotencyKey']);
    }

    public static function inputCreateSetupProposal(): array
    {
        return self::input([
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
            'description' => ['type' => 'string', 'maxLength' => 1000],
            'entryRules' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5, 'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200]],
            'timeframes' => ['type' => 'string', 'maxLength' => 40],
            'confirmation' => ['type' => 'string', 'maxLength' => 500],
            'invalidation' => ['type' => 'string', 'maxLength' => 500],
            'stopLoss' => ['type' => 'string', 'maxLength' => 200],
            'minRR' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 20],
            'exitRules' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 200]],
            'examples' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'idempotencyKey' => self::idem(),
        ], ['name', 'entryRules', 'idempotencyKey']);
    }

    public static function inputUpdateSetupProposal(): array
    {
        return self::input([
            'setupId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'changes' => self::input([
                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                'tf' => ['type' => 'string', 'maxLength' => 40],
                'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
                'rules' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5, 'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200]],
                'bad' => ['type' => 'boolean'],
                'description' => ['type' => 'string', 'maxLength' => 1000],
                'confirmation' => ['type' => 'string', 'maxLength' => 500],
                'invalidation' => ['type' => 'string', 'maxLength' => 500],
                'stopLoss' => ['type' => 'string', 'maxLength' => 200],
                'minRR' => self::nullable(['type' => 'number', 'minimum' => 0.1, 'maximum' => 20]),
                'exitRules' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 200]],
                'examples' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
            ], [], false),
            'idempotencyKey' => self::idem(),
        ], ['setupId', 'changes', 'idempotencyKey']);
    }

    public static function inputCreateCheckinProposal(): array
    {
        return self::input([
            'mood' => ['type' => 'string', 'maxLength' => 30],
            'energy' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'note' => ['type' => 'string', 'maxLength' => 500],
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'idempotencyKey' => self::idem(),
        ], ['mood', 'energy', 'idempotencyKey']);
    }

    public static function inputCreateRiskRulesProposal(): array
    {
        return self::input([
            'changes' => self::input([
                'maxRisk' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100],
                'maxLosses' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'maxDailyLoss' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 100],
                'block' => ['type' => 'string', 'enum' => ['1h', '4h', 'midnight']],
                'safetyEnabled' => ['type' => 'boolean', 'description' => 'Można tylko potwierdzić true; MCP nie wyłącza wyłącznika.'],
                'requireReason' => ['type' => 'boolean'],
                'pushLimit' => ['type' => 'boolean'],
                'rules' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]],
                'checklist' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]],
            ], [], false),
            'reason' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
            'idempotencyKey' => self::idem(),
        ], ['changes', 'reason', 'idempotencyKey']);
    }

    public static function inputCreatePropChallengeProposal(): array
    {
        return self::input([
            'templateId' => ['type' => 'string', 'maxLength' => 40, 'default' => 'custom'],
            'name' => ['type' => 'string', 'maxLength' => 100],
            'account' => ['type' => 'string', 'maxLength' => 100],
            'phase' => ['type' => 'string', 'maxLength' => 50],
            'currency' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'EUR', 'PLN']],
            'accountSize' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'startBalance' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'targetPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
            'dailyLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'maxLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'drawdownType' => ['type' => 'string', 'enum' => ['static', 'trailing']],
            'dailyBasis' => ['type' => 'string', 'enum' => ['initial', 'dayStart']],
            'minDays' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60],
            'startDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'endDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'notes' => ['type' => 'string', 'maxLength' => 2000],
            'idempotencyKey' => self::idem(),
        ], ['idempotencyKey']);
    }

    public static function inputUpdatePropChallengeProposal(): array
    {
        return self::input([
            'challengeId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'changes' => self::input([
                'name' => ['type' => 'string', 'maxLength' => 100],
                'firm' => ['type' => 'string', 'maxLength' => 100],
                'templateId' => ['type' => 'string', 'maxLength' => 40],
                'phase' => ['type' => 'string', 'maxLength' => 50],
                'account' => ['type' => 'string', 'maxLength' => 100],
                'currency' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'EUR', 'PLN']],
                'accountSize' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'startBalance' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'targetPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
                'dailyLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'maxLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'drawdownType' => ['type' => 'string', 'enum' => ['static', 'trailing']],
                'dailyBasis' => ['type' => 'string', 'enum' => ['initial', 'dayStart']],
                'minDays' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60],
                'startDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
                'endDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
                'notes' => ['type' => 'string', 'maxLength' => 2000],
            ], [], false),
            'idempotencyKey' => self::idem(),
        ], ['challengeId', 'changes', 'idempotencyKey']);
    }

    public static function inputProposePropChallenge(): array
    {        return self::input([
            'id' => ['type' => 'string', 'maxLength' => 64],
            'name' => ['type' => 'string', 'maxLength' => 100],
            'firm' => ['type' => 'string', 'maxLength' => 100],
            'templateId' => ['type' => 'string', 'maxLength' => 40],
            'phase' => ['type' => 'string', 'maxLength' => 50],
            'account' => ['type' => 'string', 'maxLength' => 100],
            'currency' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'EUR', 'PLN']],
            'accountSize' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'startBalance' => ['type' => 'number', 'exclusiveMinimum' => 0],
            'targetPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
            'dailyLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'maxLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'drawdownType' => ['type' => 'string', 'enum' => ['static', 'trailing']],
            'dailyBasis' => ['type' => 'string', 'enum' => ['initial', 'dayStart']],
            'minDays' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60],
            'startDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'endDate' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'notes' => ['type' => 'string', 'maxLength' => 2000],
            'idempotencyKey' => self::idem(),
        ], ['idempotencyKey']);
    }

    // ------------------------------------------------------------------
    // Wyjście — schematy permissive, żeby SDK nie odrzucało nowych pól.
    // ------------------------------------------------------------------

    public static function outputProfile(): array
    {
        return self::object([
            'id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'nickname' => ['type' => 'string'],
            'plan' => ['type' => 'string'], 'accessUntil' => self::nullable(['type' => 'string']),
            'expired' => ['type' => 'boolean'], 'roleVerified' => ['type' => 'boolean'], 'isDemo' => ['type' => 'boolean'], 'isAdmin' => ['type' => 'boolean'],
        ]);
    }

    public static function outputDashboard(): array { return self::object(); }
    public static function outputTradeList(): array { return self::object(); }
    public static function outputTrade(): array { return self::object(); }
    public static function outputStats(): array { return self::object(); }
    public static function outputEvents(): array { return self::object(); }
    public static function outputDayPlan(): array { return self::object(); }
    public static function outputSetups(): array { return self::object(); }
    public static function outputMacro(): array { return self::object(); }
    public static function outputPropChallenges(): array { return self::object(); }
    public static function outputCheckins(): array { return self::object(); }
    public static function outputAccounts(): array { return self::object(); }
    public static function outputCapital(): array { return self::object(); }
    public static function outputConnections(): array { return self::object(); }
    public static function outputTradeContext(): array { return self::object(); }

    public static function outputProposal(): array
    {
        return self::object([
            'proposalId' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'target' => ['type' => 'string'],
            'baseRevision' => ['type' => 'integer'],
            'diffHash' => ['type' => 'string'],
            'changes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'diff' => ['type' => 'array'],
            'expiresAt' => ['type' => 'string'],
            'duplicate' => ['type' => 'boolean'],
            'note' => ['type' => 'string'],
        ]);
    }

    public static function outputProposalStatus(): array
    {
        return self::object([
            'found' => ['type' => 'boolean'],
            'proposalId' => self::nullable(['type' => 'string']),
            'kind' => self::nullable(['type' => 'string']),
            'target' => self::nullable(['type' => 'string']),
            'status' => self::nullable(['type' => 'string']),
            'baseRevision' => self::nullable(['type' => 'integer']),
            'diffHash' => self::nullable(['type' => 'string']),
            'changes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'before' => self::nullable(['type' => 'object']),
            'after' => self::nullable(['type' => 'object']),
            'diff' => ['type' => 'array'],
            'createdAt' => self::nullable(['type' => 'string']),
            'expiresAt' => self::nullable(['type' => 'string']),
            'expiresAtIso' => self::nullable(['type' => 'string']),
            'decidedAt' => self::nullable(['type' => 'string']),
            'clientName' => self::nullable(['type' => 'string']),
            'canApprove' => ['type' => 'boolean'],
            'approvalCodeActive' => ['type' => 'boolean'],
            'approvalCodeExpiresAt' => self::nullable(['type' => 'string']),
            'note' => ['type' => 'string'],
        ]);
    }

    public static function outputProposalList(): array
    {
        return self::object([
            'items' => ['type' => 'array'],
            'total' => ['type' => 'integer'],
        ]);
    }

    public static function outputProposalDecision(): array
    {
        return self::object([
            'ok' => ['type' => 'boolean'],
            'status' => ['type' => 'string'],
            'proposalId' => ['type' => 'string'],
            'revision' => ['type' => 'integer'],
            'kind' => ['type' => 'string'],
            'target' => ['type' => 'string'],
            'diffHash' => ['type' => 'string'],
            'duplicate' => ['type' => 'boolean'],
        ]);
    }
}
