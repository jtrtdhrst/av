<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

/**
 * Schematy wejścia i wyjścia narzędzi zapisu MCP.
 *
 * Wszystkie narzędzia zapisu przyjmują `idempotencyKey` (8-120 znaków) oraz
 * opcjonalny `expectedRevision` (optimistic lock na `user_states.revision`).
 * Operacje wysokiego ryzyka przyjmują te same argumenty, ale zamiast zapisu
 * tworzą propozycję `write_op` do zatwierdzenia przez człowieka.
 */
final class WriteSchemas
{
    private static function object(array $properties = [], bool $additionalProperties = true): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => $additionalProperties,
        ];
    }

    private static function tool(array $required, array $properties): array
    {
        $schema = self::object($properties, false);
        $schema['required'] = array_values($required);

        return $schema;
    }

    private static function idem(): array
    {
        return ['type' => 'string', 'minLength' => 8, 'maxLength' => 120, 'description' => 'Unikalny klucz operacji. Ponowienie z tym samym kluczem zwraca zapisany wynik bez duplikatu.'];
    }

    private static function revision(): array
    {
        return ['type' => 'integer', 'minimum' => 0, 'description' => 'Wersja stanu z ostatniego odczytu. Niezgodność kończy się revision_conflict.'];
    }

    private static function text(int $max, string $description = ''): array
    {
        $schema = ['type' => 'string', 'maxLength' => $max];
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    private static function money(): array
    {
        return ['type' => 'number', 'description' => 'Liczba dodatnia. Przecinek dziesiętny jest akceptowany.'];
    }

    private static function changes(string $description): array
    {
        return ['type' => 'object', 'additionalProperties' => true, 'description' => $description];
    }

    private static function strings(int $maxItems, int $maxLength): array
    {
        return ['type' => 'array', 'maxItems' => $maxItems, 'items' => ['type' => 'string', 'maxLength' => $maxLength]];
    }

    private static function emotions(): array
    {
        return [
            'type' => 'string',
            'enum' => ['Spokój', 'Skupienie', 'Pewność', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Złość', 'Chciwość'],
            'description' => 'Emocja przed wejściem, dokładnie jedna z listy.',
        ];
    }

    private static function date(): array
    {
        return ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'description' => 'Data w Europe/Warsaw, YYYY-MM-DD. Bez daty użyta jest dzisiejsza.'];
    }

    private static function tradeId(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
    }

    private static function base(): array
    {
        return ['idempotencyKey' => self::idem()];
    }

    private static function baseWithRevision(): array
    {
        return ['idempotencyKey' => self::idem(), 'expectedRevision' => self::revision()];
    }

    // ------------------------------------------------------------------
    // Transakcje
    // ------------------------------------------------------------------

    public static function inputCreateTrade(): array
    {
        return self::tool(['pair', 'dir', 'entry', 'size', 'idempotencyKey'], self::baseWithRevision() + [
            'pair' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20, 'description' => 'Instrument, np. BTC/USDT.'],
            'dir' => ['type' => 'string', 'enum' => ['long', 'short', 'l', 's']],
            'entry' => self::money(),
            'size' => self::money(),
            'sl' => ['type' => 'number', 'description' => 'Stop loss po właściwej stronie wejścia.'],
            'tp' => ['type' => 'number'],
            'fee' => ['type' => 'number', 'minimum' => 0],
            'setup' => self::text(100, 'Nazwa setupu z Playbooka albo Bez setupu.'),
            'emotion' => self::emotions(),
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'checkTotal' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            'errors' => self::strings(10, 80),
            'note' => self::text(2000),
            'session' => self::text(40),
            'date' => ['type' => 'string', 'maxLength' => 40, 'description' => 'ISO 8601 albo YYYY-MM-DD.'],
            'account' => self::text(60),
        ]);
    }

    public static function inputUpdateTrade(): array
    {
        return self::tool(['tradeId', 'changes', 'idempotencyKey'], self::baseWithRevision() + [
            'tradeId' => self::tradeId(),
            'changes' => self::changes('Pola dziennika do zmiany. Wyniku, R, ceny wyjścia i czasu trwania nie zmieniaj przez update_trade — użyj close_trade. Pola wykonania zamkniętej lub importowanej transakcji są niezmienne; pozostają pola oceny i recenzji. Emocje: Spokój, Skupienie, Pewność, Nuda, Zmęczenie, Strach, FOMO, Złość, Chciwość.'),
        ]);
    }

    public static function inputCloseTrade(): array
    {
        return self::tool(['tradeId', 'idempotencyKey'], self::baseWithRevision() + [
            'tradeId' => self::tradeId(),
            'exitPrice' => ['type' => 'number', 'description' => 'Cena wyjścia. Wynik, R i czas liczy serwer.'],
            'result' => ['type' => 'number', 'description' => 'Wynik USDT, jeśli znany. Bez niego liczony z ceny wyjścia.'],
            'comment' => self::text(500),
            'mfe' => ['type' => 'number'],
        ]);
    }

    public static function inputDeleteTrade(): array
    {
        return self::tool(['tradeId', 'idempotencyKey'], self::baseWithRevision() + ['tradeId' => self::tradeId()]);
    }

    // ------------------------------------------------------------------
    // Recenzje
    // ------------------------------------------------------------------

    public static function inputCreateTradeReview(): array
    {
        return self::tool(['tradeId', 'idempotencyKey'], self::baseWithRevision() + [
            'tradeId' => self::tradeId(),
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'setup' => self::text(100),
            'emotion' => self::emotions(),
            'errors' => self::strings(10, 80),
            'checklist' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'note' => self::text(2000),
            'recommendations' => self::strings(10, 300),
        ]);
    }

    public static function inputUpdateTradeReview(): array
    {
        return self::tool(['tradeId', 'changes', 'idempotencyKey'], self::baseWithRevision() + [
            'tradeId' => self::tradeId(),
            'changes' => self::changes('Pola recenzji: grade, setup, emotion, errors, checklist, note, recommendations. ' . 'Emocje: Spokój, Skupienie, Pewność, Nuda, Zmęczenie, Strach, FOMO, Złość, Chciwość.'),
        ]);
    }

    public static function inputDeleteTradeReview(): array
    {
        return self::tool(['tradeId', 'idempotencyKey'], self::baseWithRevision() + ['tradeId' => self::tradeId()]);
    }

    // ------------------------------------------------------------------
    // Setupy
    // ------------------------------------------------------------------

    public static function inputCreateSetup(): array
    {
        return self::tool(['name', 'rules', 'idempotencyKey'], self::baseWithRevision() + [
            'name' => self::text(80),
            'rules' => self::strings(12, 300),
            'tf' => self::text(40),
            'grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C']],
            'bad' => ['type' => 'boolean'],
            'description' => self::text(1000),
            'confirmation' => self::text(500),
            'invalidation' => self::text(500),
            'stopLoss' => self::text(200),
            'minRR' => self::text(40),
            'exitRules' => self::strings(10, 300),
            'examples' => self::strings(10, 500),
        ]);
    }

    public static function inputUpdateSetup(): array
    {
        return self::tool(['setupId', 'changes', 'idempotencyKey'], self::baseWithRevision() + [
            'setupId' => self::text(128),
            'changes' => self::changes('Pola setupu: name, rules, tf, grade, bad, description, confirmation, invalidation, stopLoss, minRR, exitRules, examples.'),
        ]);
    }

    public static function inputDeleteSetup(): array
    {
        return self::tool(['idempotencyKey'], self::baseWithRevision() + [
            'setupId' => self::text(128),
            'name' => self::text(80),
        ]);
    }

    // ------------------------------------------------------------------
    // Plan dnia
    // ------------------------------------------------------------------

    public static function inputCreateDayPlan(): array
    {
        return self::tool(['idempotencyKey'], self::baseWithRevision() + [
            'date' => self::date(),
            'market' => self::text(200),
            'markets' => self::strings(12, 40),
            'bias' => ['type' => 'string', 'enum' => ['long', 'short', 'neutral', 'none']],
            'why' => self::text(2000),
            'levels' => [
                'type' => 'array',
                'maxItems' => 12,
                'items' => [
                    'anyOf' => [
                        ['type' => 'string', 'maxLength' => 40],
                        self::object(['value' => ['type' => 'string', 'maxLength' => 40], 'note' => ['type' => 'string', 'maxLength' => 200]], false),
                    ],
                ],
            ],
            'ifMain' => self::text(500),
            'thenMain' => self::text(500),
            'ifAlt' => self::text(500),
            'thenAlt' => self::text(500),
            'risk' => self::text(20),
            'maxTrades' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'until' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'notDo' => self::text(1000),
        ]);
    }

    public static function inputUpdateDayPlan(): array
    {
        return self::tool(['changes', 'idempotencyKey'], self::baseWithRevision() + [
            'date' => self::date(),
            'changes' => self::changes('Pola planu: market, markets, bias, why, levels, ifMain, thenMain, ifAlt, thenAlt, risk, maxTrades, until, notDo.'),
        ]);
    }

    public static function inputApproveDayPlan(): array
    {
        return self::tool(['idempotencyKey'], self::baseWithRevision() + ['date' => self::date()]);
    }

    public static function inputDeleteDayPlan(): array
    {
        return self::tool(['idempotencyKey'], self::baseWithRevision() + ['date' => self::date()]);
    }

    // ------------------------------------------------------------------
    // Check-in
    // ------------------------------------------------------------------

    public static function inputCreateCheckin(): array
    {
        return self::tool(['idempotencyKey'], self::baseWithRevision() + [
            'date' => self::date(),
            'mood' => self::emotions(),
            'energy' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'note' => self::text(1000),
        ]);
    }

    public static function inputUpdateCheckin(): array
    {
        return self::tool(['changes', 'idempotencyKey'], self::baseWithRevision() + [
            'date' => self::date(),
            'changes' => self::changes('Pola check-inu: mood, energy, note. ' . 'Emocje: Spokój, Skupienie, Pewność, Nuda, Zmęczenie, Strach, FOMO, Złość, Chciwość.'),
        ]);
    }

    public static function inputDeleteCheckin(): array
    {
        return self::tool(['idempotencyKey'], self::baseWithRevision() + ['date' => self::date()]);
    }

    // ------------------------------------------------------------------
    // Konta
    // ------------------------------------------------------------------

    public static function inputCreateAccount(): array
    {
        return self::tool(['name', 'idempotencyKey'], self::baseWithRevision() + [
            'name' => self::text(60),
            'currency' => ['type' => 'string', 'enum' => ['USDT', 'USD', 'PLN', 'EUR']],
            'exchange' => self::text(40),
            'type' => self::text(40),
            'start' => ['type' => 'number', 'minimum' => 0],
            'balance' => ['type' => 'number', 'minimum' => 0],
            'date' => self::date(),
            'lev' => self::text(20),
            'goal' => self::text(200),
            'include' => ['type' => 'boolean'],
            'primary' => ['type' => 'boolean'],
        ]);
    }

    public static function inputUpdateAccount(): array
    {
        return self::tool(['accountId', 'changes', 'idempotencyKey'], self::baseWithRevision() + [
            'accountId' => self::text(128),
            'changes' => self::changes('Pola konta: name, exchange, type, currency, start, balance, date, lev, goal, include, primary.'),
        ]);
    }

    public static function inputDeleteAccount(): array
    {
        return self::tool(['accountId', 'idempotencyKey'], self::baseWithRevision() + ['accountId' => self::text(128)]);
    }

    // ------------------------------------------------------------------
    // Wpłaty i wypłaty
    // ------------------------------------------------------------------

    public static function inputCreateAccountTransaction(): array
    {
        return self::tool(['amount', 'idempotencyKey'], self::baseWithRevision() + [
            'amount' => self::money(),
            'type' => ['type' => 'string', 'enum' => ['Wpłata', 'Wypłata']],
            'account' => self::text(60),
            'currency' => ['type' => 'string', 'enum' => ['USDT', 'USD', 'PLN', 'EUR']],
            'date' => self::date(),
            'fiat' => ['type' => 'number', 'minimum' => 0],
            'fx' => ['type' => 'number', 'minimum' => 0],
            'op' => self::text(200),
            'tax' => self::text(20),
        ]);
    }

    public static function inputUpdateAccountTransaction(): array
    {
        return self::tool(['flowId', 'changes', 'idempotencyKey'], self::baseWithRevision() + [
            'flowId' => self::text(128),
            'changes' => self::changes('Pola wpisu: date, account, type, amount, currency, fiat, fx, op, tax.'),
        ]);
    }

    public static function inputDeleteAccountTransaction(): array
    {
        return self::tool(['flowId', 'idempotencyKey'], self::baseWithRevision() + ['flowId' => self::text(128)]);
    }

    // ------------------------------------------------------------------
    // Ryzyko
    // ------------------------------------------------------------------

    public static function inputUpdateRiskRules(): array
    {
        return self::tool(['changes', 'reason', 'idempotencyKey'], self::baseWithRevision() + [
            'changes' => self::changes('MCP może tylko zaostrzać: maxRisk↓, maxLosses↓, maxDailyLoss↓, block dłuższy, włączanie zabezpieczeń, dopisywanie reguł i checklisty.'),
            'reason' => self::text(500, 'Uzasadnienie zmiany, 10-500 znaków.'),
        ]);
    }

    // ------------------------------------------------------------------
    // Prop challenges
    // ------------------------------------------------------------------

    public static function inputCreatePropChallenge(): array
    {
        return self::tool(['name', 'idempotencyKey'], self::baseWithRevision() + [
            'name' => self::text(120),
            'firm' => self::text(80),
            'templateId' => self::text(40),
            'phase' => self::text(40),
            'account' => self::text(60),
            'currency' => ['type' => 'string', 'enum' => ['USD', 'USDT', 'EUR', 'PLN']],
            'accountSize' => ['type' => 'number', 'minimum' => 0],
            'startBalance' => ['type' => 'number', 'minimum' => 0],
            'targetPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
            'dailyLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'maxLossPct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'drawdownType' => ['type' => 'string', 'enum' => ['static', 'trailing']],
            'dailyBasis' => ['type' => 'string', 'enum' => ['initial', 'current']],
            'minDays' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60],
            'startDate' => self::date(),
            'endDate' => self::date(),
            'notes' => self::text(1000),
            'archived' => ['type' => 'boolean'],
        ]);
    }

    public static function inputUpdatePropChallenge(): array
    {
        return self::tool(['challengeId', 'changes', 'idempotencyKey'], self::baseWithRevision() + [
            'challengeId' => self::text(128),
            'changes' => self::changes('Pola challenge: name, firm, templateId, phase, account, currency, accountSize, startBalance, targetPct, dailyLossPct, maxLossPct, drawdownType, dailyBasis, minDays, startDate, endDate, notes, archived.'),
        ]);
    }

    public static function inputDeletePropChallenge(): array
    {
        return self::tool(['challengeId', 'idempotencyKey'], self::baseWithRevision() + ['challengeId' => self::text(128)]);
    }

    // ------------------------------------------------------------------
    // Screeny
    // ------------------------------------------------------------------

    public static function inputAddTradeScreenshot(): array
    {
        return self::tool(['tradeId', 'dataUrl', 'idempotencyKey'], self::baseWithRevision() + [
            'tradeId' => self::tradeId(),
            'dataUrl' => ['type' => 'string', 'maxLength' => 280000, 'description' => 'Obraz PNG/JPEG/WebP jako data URL base64. Maks. 200 KB na obraz i 400 KB łącznie.'],
            'position' => ['type' => 'string', 'enum' => ['before', 'after']],
        ]);
    }

    public static function inputDeleteTradeScreenshot(): array
    {
        return self::tool(['tradeId', 'position', 'idempotencyKey'], self::baseWithRevision() + [
            'tradeId' => self::tradeId(),
            'position' => ['type' => 'string', 'enum' => ['before', 'after']],
        ]);
    }

    // ------------------------------------------------------------------
    // Kosz i propozycje
    // ------------------------------------------------------------------

    public static function inputRestoreDeletedRecord(): array
    {
        return self::tool(['trashId', 'idempotencyKey'], self::baseWithRevision() + [
            'trashId' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 128, 'description' => 'Identyfikator wpisu w koszu zwrócony przy usunięciu.'],
        ]);
    }

    public static function inputProposeChange(): array
    {
        return self::tool(['operation', 'args', 'idempotencyKey'], self::baseWithRevision() + [
            'operation' => [
                'type' => 'string',
                'enum' => [
                    'trade.create', 'trade.update', 'trade.close', 'trade.delete',
                    'review.create', 'review.update', 'review.delete',
                    'setup.create', 'setup.update', 'setup.delete',
                    'plan.create', 'plan.update', 'plan.approve', 'plan.delete',
                    'checkin.create', 'checkin.update', 'checkin.delete',
                    'account.create', 'account.update', 'account.delete',
                    'flow.create', 'flow.update', 'flow.delete',
                    'risk.update',
                    'prop.create', 'prop.update', 'prop.delete',
                    'screenshot.add', 'screenshot.delete',
                    'restore.record',
                ],
            ],
            'args' => self::object([], true),
        ]);
    }

    public static function inputApproveProposal(): array
    {
        return self::tool(['proposalId', 'expectedDiffHash', 'confirmed', 'idempotencyKey'], [
            'proposalId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'expectedDiffHash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
            'confirmed' => ['type' => 'boolean', 'description' => 'Ustaw true wyłącznie po wyraźnym „tak” użytkownika w rozmowie. Agent nie zatwierdza sam.'],
            'confirmationCode' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 12, 'description' => 'Opcjonalny kod z panelu AlphaVault. Zatwierdzanie w rozmowie go nie wymaga.'],
            'idempotencyKey' => self::idem(),
        ]);
    }

    public static function inputRejectProposal(): array
    {
        return self::tool(['proposalId', 'idempotencyKey'], [
            'proposalId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'idempotencyKey' => self::idem(),
        ]);
    }

    // ------------------------------------------------------------------
    // Wyjście
    // ------------------------------------------------------------------

    public static function outputWriteResult(): array
    {
        return self::object([
            'ok' => ['type' => 'boolean'],
            'applied' => ['type' => 'string'],
            'operation' => ['type' => 'string'],
            'target' => ['type' => 'string'],
            'revision' => ['type' => 'integer'],
            'revisionBefore' => ['type' => 'integer'],
            'record' => ['type' => 'object'],
            'changes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'diff' => ['type' => 'array'],
            'duplicate' => ['type' => 'boolean'],
            'proposalId' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'baseRevision' => ['type' => 'integer'],
            'diffHash' => ['type' => 'string'],
            'expiresAt' => ['type' => 'string'],
            'preview' => ['type' => 'object'],
            'note' => ['type' => 'string'],
        ]);
    }
}
