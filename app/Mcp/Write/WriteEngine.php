<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Write;

use AlphaVault\Config;
use AlphaVault\Db;
use AlphaVault\Mcp\Analytics\DayPlanService;
use AlphaVault\TradeRules;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\DayPlanLevels;
use AlphaVault\Mcp\Support\DayPlanRecords;
use AlphaVault\Mcp\Support\Numbers;
use AlphaVault\Mcp\Support\PropChallengeService;
use AlphaVault\Mcp\Support\RecordDiff;

/**
 * Jedyne miejsce, w którym MCP zmienia stan użytkownika (`user_states.state`).
 *
 * Silnik nie zna HTTP ani MCP: operacja to nazwa + argumenty. Ten sam kod
 * obsługuje zapis bezpośredni (narzędzia niskiego ryzyka) i zatwierdzenie
 * propozycji (`write_op`), więc podgląd propozycji i wykonanie nie mogą się
 * rozjechać.
 *
 * Kontrakt bezpieczeństwa:
 * - brak `userId` w argumentach; tożsamość pochodzi z tokenu OAuth,
 * - każdy zapis sprawdza `expectedRevision` (optimistic lock na `user_states.revision`),
 * - `idempotencyKey` eliminuje duplikaty po ponowieniu wywołania,
 * - usuwanie jest miękkie: rekord trafia do `state.trash` i wraca przez `restore.record`,
 * - brak operacji na giełdach, kluczach API, Discordzie i kalendarzu makro.
 */
final class WriteEngine
{
    private const EMOTIONS = ['Spokój', 'Skupienie', 'Pewność', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Złość', 'Chciwość'];
    private const BIAS = ['long', 'short', 'neutral', 'none'];
    private const CURRENCIES = ['USDT', 'USD', 'PLN', 'EUR'];
    private const FLOW_TYPES = ['Wpłata', 'Wypłata'];
    private const BLOCK_ORDER = ['1h' => 1, '4h' => 2, 'midnight' => 3];
    private const TRASH_LIMIT = 200;
    private const SCREEN_MAX_BYTES = 200000;
    private const SCREENS_TOTAL_BYTES = 400000;
    private const IDEM_TTL_HOURS = 24;

    /**
     * Wszystkie 30 operacji zapisuje bezpośrednio. Propozycje z diffem są
     * opcjonalną ścieżką przygotowania zmiany przed zapisem.
     */
    public const APPROVAL_REQUIRED = [];

    public const OPERATIONS = [
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
    ];

    public static function requiresApproval(string $op): bool
    {
        return in_array($op, self::APPROVAL_REQUIRED, true);
    }


    /** Felder dziennika, które wolno zmieniać w transakcji importowanej z giełdy. */
    private const EXCHANGE_LOCKED = [
        'entry', 'exit', 'exitPrice', 'price', 'fill', 'fillPrice', 'result', 'pnl', 'r',
        'size', 'volume', 'sl', 'tp', 'fee', 'mae', 'mfe', 'date', 'pair', 'symbol', 'dir', 'side',
        'account', 'source', 'exchangeId', 'durationMin',
    ];

    private const TRADE_EDITABLE = [
        'pair', 'dir', 'entry', 'sl', 'tp', 'size', 'fee',
        'mae', 'mfe', 'grade', 'emotion', 'checklist', 'checkTotal', 'errors',
        'note', 'mentor', 'setup', 'session', 'plan', 'date', 'account',
    ];
    private const CLOSED_TRADE_EXECUTION_FIELDS = [
        'pair', 'dir', 'entry', 'sl', 'tp', 'size', 'fee', 'mae', 'mfe',
        'date', 'account', 'session', 'plan',
    ];

    private const REVIEW_FIELDS = ['grade', 'setup', 'emotion', 'errors', 'note', 'checklist', 'recommendations'];

    private const SETUP_FIELDS = [
        'name', 'tf', 'grade', 'rules', 'bad', 'description', 'confirmation',
        'invalidation', 'stopLoss', 'minRR', 'exitRules', 'examples',
    ];

    private const PLAN_FIELDS = [
        'market', 'markets', 'bias', 'why', 'levels', 'ifMain', 'thenMain',
        'ifAlt', 'thenAlt', 'risk', 'maxTrades', 'until', 'notDo',
    ];

    private const ACCOUNT_FIELDS = [
        'name', 'exchange', 'type', 'currency', 'start', 'balance', 'date',
        'lev', 'goal', 'include', 'primary',
    ];

    private const FLOW_FIELDS = ['date', 'account', 'type', 'amount', 'currency', 'fiat', 'fx', 'op', 'tax'];

    private const PROP_FIELDS = [
        'name', 'firm', 'templateId', 'phase', 'account', 'currency', 'accountSize',
        'startBalance', 'targetPct', 'dailyLossPct', 'maxLossPct', 'drawdownType',
        'dailyBasis', 'minDays', 'startDate', 'endDate', 'notes', 'archived',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
    ) {
    }

    // ------------------------------------------------------------------
    // Wykonanie i podgląd
    // ------------------------------------------------------------------

    /**
     * Wykonuje operację i zapisuje stan. Zwraca odpowiedź dla modelu.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function execute(
        int $userId,
        string $op,
        array $args,
        ?int $expectedRevision,
        string $idempotencyKey,
        ?int $actorId = null,
    ): array {
        $key = trim($idempotencyKey);
        if (mb_strlen($key) < 8 || mb_strlen($key) > 120) {
            throw new WriteException('bad_request', 'idempotencyKey musi mieć 8-120 znaków.');
        }
        $payloadHash = $this->requestHash($op, $args, $expectedRevision);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Serialize MCP writes for one user before reading the idempotency
            // key, including the first write when user_states does not exist.
            $this->db->queryOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $replay = $this->db->queryOne(
                'SELECT payload_hash, response_json, expires_at FROM mcp_idempotency
                  WHERE user_id = ? AND idem_key = ? FOR UPDATE',
                [$userId, $key]
            );
            if ($replay !== null) {
                $expiresAt = strtotime((string) ($replay['expires_at'] ?? '') . ' UTC');
                if ($expiresAt !== false && $expiresAt > time()) {
                    $storedHash = (string) ($replay['payload_hash'] ?? '');
                    if ($storedHash === '' || !hash_equals($storedHash, $payloadHash)) {
                        throw new WriteException(
                            'idempotency_conflict',
                            'Ten sam idempotencyKey użyto z inną treścią lub bez zapisanego fingerprintu. Użyj nowego klucza.',
                            409
                        );
                    }
                    $stored = json_decode((string) ($replay['response_json'] ?? ''), true);
                    $pdo->commit();
                    if (is_array($stored)) {
                        $stored = RecordDiff::redactValue($stored);
                        $stored['duplicate'] = true;
                        return $stored;
                    }
                    throw new WriteException('idempotency_conflict', 'Ten idempotencyKey został już użyty.', 409);
                }
                $this->db->execute(
                    'DELETE FROM mcp_idempotency WHERE user_id = ? AND idem_key = ?',
                    [$userId, $key]
                );
            }

            $row = $this->db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ? FOR UPDATE', [$userId]);
            $state = $row !== null ? json_decode((string) $row['state'], true) : [];
            if (!is_array($state)) {
                $state = [];
            }
            $state = $this->normalizeLegacyUnknownRisk($state);
            $revision = (int) ($row['revision'] ?? 0);
            if ($expectedRevision !== null && $expectedRevision !== $revision) {
                throw new WriteException(
                    'revision_conflict',
                    'Dane zmieniły się od czasu odczytu (oczekiwano wersji ' . $expectedRevision . ', jest ' . $revision . '). Odczytaj rekord ponownie.',
                    409
                );
            }

            $applied = $this->apply($userId, $state, $op, $args);
            $stateChanged = $row === null || !RecordDiff::sameState($state, $applied['state']);
            if (!$stateChanged && $applied['before'] !== null) {
                $applied['state'] = $state;
                $applied['after'] = $applied['before'];
                $applied['result'] = $applied['before'];
                $applied['changes'] = [];
            }

            if ($stateChanged) {
                $json = json_encode($applied['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new WriteException('state_encode_failed', 'Nie można zakodować stanu transakcji.', 400);
                }
                if (strlen($json) > $this->config->maxStateBytes) {
                    throw new WriteException('state_too_large', 'Stan konta przekracza dopuszczalny rozmiar.', 413);
                }

                if ($row === null) {
                    $this->db->execute(
                        'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)',
                        [$userId, $json, strlen($json)]
                    );
                    $newRevision = 1;
                } else {
                    $this->db->execute(
                        'UPDATE user_states SET state = ?, revision = ?, bytes = ? WHERE user_id = ?',
                        [$json, $revision + 1, strlen($json), $userId]
                    );
                    $newRevision = $revision + 1;
                }
            } else {
                $newRevision = $revision;
            }

            $response = [
                'ok' => true,
                'applied' => 'direct',
                'operation' => $op,
                'target' => $applied['target'],
                'revision' => $newRevision,
                'revisionBefore' => $revision,
                'record' => $applied['result'],
                'changes' => array_map(static fn (array $change): string => (string) $change['field'], $applied['changes']),
                'diff' => $applied['changes'],
                'note' => 'Zapis trwały potwierdzony w bazie. Odczytaj rekord, jeśli chcesz pokazać stan po zmianie.',
            ];
            $response = RecordDiff::redactValue($response);
            $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->execute(
                'INSERT INTO mcp_idempotency (user_id, idem_key, tool, payload_hash, response_json, expires_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ' . self::IDEM_TTL_HOURS . ' HOUR)',
                [$userId, $key, mb_substr($op, 0, 64), $payloadHash, $encoded === false ? null : $encoded]
            );

            $pdo->commit();
            if ($stateChanged) {
                $this->audit($actorId ?? $userId, $op, [
                    'target' => $applied['target'],
                    'revision' => $newRevision,
                    'changes' => $response['changes'],
                ]);
            }

            return $response;
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $err;
        }
    }

    /**
     * Podgląd propozycji: ta sama ścieżka co wykonanie, bez zapisu.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $args
     * @return array{state:array,before:?array,after:array,target:string,changes:array<string,mixed>,stateChanges:array}
     */
    public function preview(
        int $userId,
        array $state,
        string $op,
        array $args,
        ?string $preparedTarget = null,
        bool $allowExistingPreparedTarget = false
    ): array
    {
        $applied = $this->apply($userId, $state, $op, $args, $preparedTarget, $allowExistingPreparedTarget);

        return [
            'state' => $applied['state'],
            'before' => RecordDiff::redactValue($applied['before']),
            'after' => RecordDiff::redactValue($applied['after']),
            'target' => $applied['target'],
            'changes' => RecordDiff::redactValue($applied['changes']),
            'stateChanges' => RecordDiff::stateBetween($state, $applied['state']),
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $args
     * @return array{state:array,before:?array,after:array,target:string,result:array,changes:array<string,mixed>}
     */
    public function apply(
        int $userId,
        array $state,
        string $op,
        array $args,
        ?string $preparedTarget = null,
        bool $allowExistingPreparedTarget = false
    ): array
    {
        $state = TradeRules::normalizeTradeDirections($state);
        $state = $this->normalizeLegacyUnknownRisk($state);
        foreach (['trades', 'setups', 'checkins', 'accounts', 'flows'] as $container) {
            if (!isset($state[$container]) || !is_array($state[$container])) {
                $state[$container] = [];
            }
        }
        foreach (['plans', 'tradeReviews'] as $map) {
            if (!isset($state[$map]) || !is_array($state[$map])) {
                $state[$map] = [];
            }
        }
        if (!isset($state['settings']) || !is_array($state['settings'])) {
            $state['settings'] = [];
        }
        if (!isset($state['prop']) || !is_array($state['prop'])) {
            $state['prop'] = ['challenges' => [], 'selectedId' => null];
        }
        if (!isset($state['prop']['challenges']) || !is_array($state['prop']['challenges'])) {
            $state['prop']['challenges'] = [];
        }

        $applied = match ($op) {
            'trade.create' => $this->tradeCreate($userId, $state, $args),
            'trade.update' => $this->tradeUpdate($userId, $state, $args),
            'trade.close' => $this->tradeClose($userId, $state, $args),
            'trade.delete' => $this->tradeDelete($state, $args),
            'review.create' => $this->reviewCreate($state, $args),
            'review.update' => $this->reviewUpdate($state, $args),
            'review.delete' => $this->reviewDelete($state, $args),
            'setup.create' => $this->setupCreate($state, $args),
            'setup.update' => $this->setupUpdate($state, $args),
            'setup.delete' => $this->setupDelete($state, $args),
            'plan.create' => $this->planCreate($state, $args, $preparedTarget, $allowExistingPreparedTarget),
            'plan.update' => $this->planUpdate($state, $args),
            'plan.approve' => $this->planApprove($state, $args),
            'plan.delete' => $this->planDelete($state, $args),
            'checkin.create' => $this->checkinCreate($state, $args),
            'checkin.update' => $this->checkinUpdate($state, $args),
            'checkin.delete' => $this->checkinDelete($state, $args),
            'account.create' => $this->accountCreate($state, $args, $preparedTarget, $allowExistingPreparedTarget),
            'account.update' => $this->accountUpdate($state, $args),
            'account.delete' => $this->accountDelete($state, $args),
            'flow.create' => $this->flowCreate($state, $args, $preparedTarget, $allowExistingPreparedTarget),
            'flow.update' => $this->flowUpdate($state, $args),
            'flow.delete' => $this->flowDelete($state, $args),
            'risk.update' => $this->riskUpdate($state, $args),
            'prop.create' => $this->propCreate($state, $args, $preparedTarget, $allowExistingPreparedTarget),
            'prop.update' => $this->propUpdate($state, $args),
            'prop.delete' => $this->propDelete($state, $args),
            'screenshot.add' => $this->screenshotAdd($state, $args),
            'screenshot.delete' => $this->screenshotDelete($state, $args),
            'restore.record' => $this->restoreRecord($state, $args),
            default => throw new WriteException('unknown_operation', 'Nieznana operacja zapisu: ' . $op),
        };
        $violation = TradeRules::validateTransition($state, $applied['state']);
        if ($violation !== null) {
            throw new WriteException(
                (string) $violation['error'],
                (string) $violation['message'],
                (int) ($violation['status'] ?? 422)
            );
        }

        return $applied;
    }

    // ------------------------------------------------------------------
    // Transakcje
    // ------------------------------------------------------------------

    private function tradeCreate(int $userId, array $state, array $args): array
    {
        $pair = strtoupper(trim((string) ($args['pair'] ?? '')));
        if (preg_match('#^[A-Z0-9._/-]{2,20}$#', $pair) !== 1) {
            throw new WriteException('invalid_pair', 'Para musi mieć 2-20 znaków: A-Z, 0-9, . _ / -.');
        }
        $dir = TradeRules::normalizeDirection($args['dir'] ?? null);
        if ($dir === null) {
            throw new WriteException('invalid_direction', 'Kierunek musi być long albo short.');
        }
        $entry = $this->positive($args['entry'] ?? null, 'entry');
        $size = $this->positive($args['size'] ?? null, 'size');
        $sl = $this->optionalPositive($args['sl'] ?? null, 'sl');
        if ($sl === null) {
            throw new WriteException('stop_loss_required', 'Stop loss jest wymagany przy zapisie transakcji.');
        }
        $tp = $this->optionalPositive($args['tp'] ?? null, 'tp');
        if ($sl !== null && (($dir === 'l' && $sl >= $entry) || ($dir === 's' && $sl <= $entry))) {
            throw new WriteException('invalid_stop_loss', 'Stop loss jest po złej stronie ceny wejścia.');
        }
        if ($tp !== null && (($dir === 'l' && $tp <= $entry) || ($dir === 's' && $tp >= $entry))) {
            throw new WriteException('invalid_take_profit', 'Take profit jest po złej stronie ceny wejścia.');
        }

        $setup = trim((string) ($args['setup'] ?? 'Bez setupu'));
        if ($setup === '') {
            $setup = 'Bez setupu';
        }
        if ($setup !== 'Bez setupu' && !in_array($setup, $this->setupNames($state), true)) {
            throw new WriteException('unknown_setup', 'Setup nie istnieje w Playbooku. Użyj list_setups.');
        }
        $emotion = trim((string) ($args['emotion'] ?? 'Spokój'));
        if (!in_array($emotion, self::EMOTIONS, true)) {
            throw new WriteException('invalid_emotion', 'Nieprawidłowa emocja. Dozwolone: ' . implode(', ', self::EMOTIONS) . '.');
        }
        $checkTotal = array_key_exists('checkTotal', $args)
            ? (int) $args['checkTotal']
            : max(1, min(20, count((array) ($state['settings']['checklist'] ?? [])) ?: 7));
        if ($checkTotal < 1 || $checkTotal > 20) {
            throw new WriteException('invalid_checklist', 'checkTotal musi być w zakresie 1-20.');
        }
        $checklist = array_key_exists('checklist', $args) ? (int) $args['checklist'] : 0;
        if ($checklist < 0 || $checklist > $checkTotal) {
            throw new WriteException('invalid_checklist', 'checklist musi być w zakresie 0-checkTotal.');
        }
        $grade = strtoupper(trim((string) ($args['grade'] ?? '')));
        if ($grade !== '' && !in_array($grade, ['A', 'B', 'C'], true)) {
            throw new WriteException('invalid_grade', 'grade musi być A, B albo C.');
        }
        if ($grade === '') {
            $grade = $checklist >= $checkTotal ? 'A' : ($checklist >= (int) ceil($checkTotal * 0.7) ? 'B' : 'C');
        }

        $date = $this->tradeDate((string) ($args['date'] ?? ''));
        $account = $this->accountName($state, $args['account'] ?? null, false);
        $fee = $this->optionalAmount($args['fee'] ?? null, 'fee') ?? 0.0;
        $trade = [
            'id' => 'manual_' . bin2hex(random_bytes(4)),
            'date' => $date,
            'pair' => $pair,
            'dir' => $dir,
            'entry' => $this->numText($entry),
            'exit' => 'otwarta',
            'sl' => $sl === null ? 'brak' : $this->numText($sl),
            'tp' => $tp === null ? '—' : $this->numText($tp),
            'size' => $this->numText($size),
            'result' => null,
            'r' => null,
            'grade' => $grade,
            'checklist' => $checklist,
            'checkTotal' => $checkTotal,
            'errors' => $this->stringList($args['errors'] ?? [], 'errors', 10, 80),
            'emotion' => $emotion,
            'note' => $this->text($args['note'] ?? '', 2000),
            'mentor' => $this->mentorForGrade($grade, (array) ($args['errors'] ?? []), $checklist, $checkTotal),
            'setup' => $setup,
            'session' => $this->text($args['session'] ?? $this->sessionNow(), 40),
            'mae' => null,
            'mfe' => null,
            'durationMin' => 0,
            'fee' => Numbers::round($fee),
            'plan' => false,
            'source' => 'Ręcznie',
            'account' => $account,
            'screens' => [],
        ];
        $trade['plan'] = $this->planMatches($state, $trade);
        $risk = $this->tradeRisk($state, $trade);
        $trade['risk'] = $risk['risk'];
        $trade['riskPct'] = $risk['riskPct'];

        $trades = is_array($state['trades'] ?? null) ? $state['trades'] : [];
        $trades[] = $trade;
        $state['trades'] = array_values($trades);

        return $this->done($state, null, $trade, 'trade:' . $trade['id'], $trade);
    }

    private function tradeUpdate(int $userId, array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        [$index, $trade] = $this->findTrade($state, $id);
        $changes = $this->changesArray($args, 'changes');
        $exchange = $this->isExchangeTrade($userId, $trade);

        $allowed = [];
        foreach ($changes as $field => $value) {
            $field = (string) $field;
            if (!in_array($field, self::TRADE_EDITABLE, true)) {
                throw new WriteException('field_not_editable', 'Pole "' . $field . '" nie jest edytowalne przez MCP.');
            }
            if ($exchange && in_array($field, self::EXCHANGE_LOCKED, true)) {
                throw new WriteException(
                    'exchange_data_readonly',
                    'Transakcja importowana z giełdy jest tylko do odczytu w polach wykonania. Możesz zmieniać ocenę, emocje, notatkę i checklistę.',
                    403
                );
            }
            $allowed[$field] = $value;
        }
        if ($allowed === []) {
            throw new WriteException('bad_request', 'changes nie może być puste.');
        }
        if (TradeRules::isClosed($trade)
            && array_intersect(array_keys($allowed), self::CLOSED_TRADE_EXECUTION_FIELDS) !== []) {
            throw new WriteException(
                'closed_trade_execution_readonly',
                'Pola wykonania zamkniętej transakcji są niezmienne; poprawiaj wyłącznie ocenę i recenzję.',
                409
            );
        }

        $updated = $trade;
        if (isset($allowed['pair'])) {
            $pair = strtoupper(trim((string) $allowed['pair']));
            if (preg_match('#^[A-Z0-9._/-]{2,20}$#', $pair) !== 1) {
                throw new WriteException('invalid_pair', 'Para musi mieć 2-20 znaków: A-Z, 0-9, . _ / -.');
            }
            $updated['pair'] = $pair;
        }
        if (isset($allowed['dir'])) {
            $dir = TradeRules::normalizeDirection($allowed['dir']);
            if ($dir === null) {
                throw new WriteException('invalid_direction', 'Kierunek musi być long albo short.');
            }
            $updated['dir'] = $dir;
        }
        foreach (['entry', 'sl', 'tp', 'size', 'fee', 'mae', 'mfe'] as $field) {
            if (array_key_exists($field, $allowed)) {
                $value = $allowed[$field];
                if (in_array($field, ['mae', 'mfe'], true)) {
                    $updated[$field] = $this->optionalNonNegativeMetric($value, $field);
                    continue;
                }
                if ($value === null || $value === '' || $value === 'brak' || $value === '—' || $value === '-') {
                    if ($field === 'entry' || $field === 'size') {
                        throw new WriteException('bad_number', $field . ' musi być liczbą dodatnią.');
                    }
                    $updated[$field] = $field === 'sl'
                        ? 'brak'
                        : ($field === 'tp' ? '—' : 0);
                    continue;
                }
                $number = $this->positive($value, $field, $field === 'fee');
                $updated[$field] = $field === 'fee' ? Numbers::round($number) : $this->numText($number);
            }
        }
        if (!TradeRules::isClosed($trade)
            && array_key_exists('sl', $allowed)
            && (($updated['sl'] ?? '') === '' || ($updated['sl'] ?? '') === 'brak')) {
            throw new WriteException('stop_loss_required', 'Stop loss jest wymagany przy otwartej transakcji.');
        }
        foreach (['grade'] as $field) {
            if (array_key_exists($field, $allowed)) {
                $grade = strtoupper(trim((string) $allowed[$field]));
                if (!in_array($grade, ['A', 'B', 'C'], true)) {
                    throw new WriteException('invalid_grade', 'grade musi być A, B albo C.');
                }
                $updated[$field] = $grade;
            }
        }
        if (array_key_exists('emotion', $allowed)) {
            $emotion = trim((string) $allowed['emotion']);
            if (!in_array($emotion, self::EMOTIONS, true)) {
                throw new WriteException('invalid_emotion', 'Nieprawidłowa emocja. Dozwolone: ' . implode(', ', self::EMOTIONS) . '.');
            }
            $updated['emotion'] = $emotion;
        }
        if (array_key_exists('checklist', $allowed) || array_key_exists('checkTotal', $allowed)) {
            $total = (int) ($allowed['checkTotal'] ?? $updated['checkTotal'] ?? 7);
            $done = (int) ($allowed['checklist'] ?? $updated['checklist'] ?? 0);
            if ($total < 1 || $total > 20) {
                throw new WriteException('invalid_checklist', 'checkTotal musi być w zakresie 1-20.');
            }
            if ($done < 0 || $done > $total) {
                throw new WriteException('invalid_checklist', 'checklist musi być w zakresie 0-checkTotal.');
            }
            $updated['checkTotal'] = $total;
            $updated['checklist'] = $done;
        }
        if (array_key_exists('errors', $allowed)) {
            $updated['errors'] = $this->stringList($allowed['errors'], 'errors', 10, 80);
        }
        foreach (['note' => 2000, 'mentor' => 500, 'setup' => 100, 'session' => 40] as $field => $limit) {
            if (array_key_exists($field, $allowed)) {
                $updated[$field] = $this->text($allowed[$field], $limit);
            }
        }
        if (array_key_exists('emotion', $allowed)) {
            $emotion = trim((string) $allowed['emotion']);
            if ($emotion !== '' && !in_array($emotion, self::EMOTIONS, true)) {
                throw new WriteException('invalid_emotion', 'Nieprawidłowa emocja. Dozwolone: ' . implode(', ', self::EMOTIONS) . '.');
            }
            $updated['emotion'] = $emotion;
        }
        if (array_key_exists('plan', $allowed)) {
            $updated['plan'] = (bool) $allowed['plan'];
        }
        if (array_key_exists('date', $allowed)) {
            $updated['date'] = $this->tradeDate((string) $allowed['date']);
        }
        if (array_key_exists('account', $allowed)) {
            $updated['account'] = $this->accountName($state, $allowed['account'], false);
        }
        if (!array_key_exists('mentor', $allowed)
            && array_intersect(array_keys($allowed), ['grade', 'checklist', 'checkTotal', 'errors']) !== []) {
            $updated['mentor'] = $this->mentorForGrade(
                (string) ($updated['grade'] ?? 'C'),
                (array) ($updated['errors'] ?? []),
                (int) ($updated['checklist'] ?? 0),
                (int) ($updated['checkTotal'] ?? 7)
            );
        }

        $executionFieldsChanged = array_intersect(array_keys($allowed), ['entry', 'size', 'sl', 'tp', 'dir']) !== [];
        if ($executionFieldsChanged) {
            $entry = $this->positive($updated['entry'] ?? null, 'entry');
            $size = $this->positive($updated['size'] ?? null, 'size');
            $dir = (string) ($updated['dir'] ?? '');
            $slText = trim((string) ($updated['sl'] ?? ''));
            $tpText = trim((string) ($updated['tp'] ?? ''));
            if ($slText !== '' && $slText !== 'brak' && $slText !== '—') {
                $sl = $this->positive($slText, 'sl');
                if (($dir === 'l' && $sl >= $entry) || ($dir === 's' && $sl <= $entry)) {
                    throw new WriteException('invalid_stop_loss', 'Stop loss jest po złej stronie ceny wejścia.');
                }
            }
            if ($tpText !== '' && $tpText !== 'brak' && $tpText !== '—') {
                $tp = $this->positive($tpText, 'tp');
                if (($dir === 'l' && $tp <= $entry) || ($dir === 's' && $tp >= $entry)) {
                    throw new WriteException('invalid_take_profit', 'Take profit jest po złej stronie ceny wejścia.');
                }
            }
        }

        if (!$exchange && $executionFieldsChanged) {
            $risk = $this->tradeRisk($state, $updated);
            $updated['risk'] = $risk['risk'];
            $updated['riskPct'] = $risk['riskPct'];
        }

        $state['trades'][$index] = $updated;

        return $this->done($state, $trade, $updated, 'trade:' . $id, $updated);
    }

    private function tradeClose(int $userId, array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        [$index, $trade] = $this->findTrade($state, $id);
        if (TradeRules::isClosed($trade)) {
            throw new WriteException('already_closed', 'Transakcja jest już zamknięta.', 409);
        }
        if ($this->isExchangeTrade($userId, $trade)) {
            throw new WriteException('exchange_data_readonly', 'Transakcja importowana z giełdy jest tylko do odczytu.', 403);
        }

        $entry = TradeRules::number($trade['entry'] ?? null);
        $size = TradeRules::number($trade['size'] ?? null);
        if ($entry === null || $entry <= 0 || $size === null || $size <= 0) {
            throw new WriteException('bad_number', 'entry i size muszą być skończonymi liczbami dodatnimi.');
        }
        $directionSign = TradeRules::directionSign($trade['dir'] ?? 'l');
        if ($directionSign === null) {
            throw new WriteException('invalid_direction', 'Kierunek transakcji jest nieprawidłowy.');
        }
        $exitPrice = $this->optionalPositive($args['exitPrice'] ?? null, 'exitPrice');
        $result = array_key_exists('result', $args) && $args['result'] !== null
            ? Numbers::round($this->signedNumber($args['result'], 'result'))
            : null;
        if ($exitPrice === null && $result === null) {
            throw new WriteException('bad_request', 'Podaj exitPrice albo result.');
        }
        if ($result === null) {
            $result = Numbers::round(($exitPrice - $entry) * $size * $directionSign);
        }
        $slText = trim((string) ($trade['sl'] ?? ''));
        $sl = $slText !== '' && $slText !== 'brak' && $slText !== '—' && $slText !== '-'
            ? TradeRules::number($slText)
            : null;
        if ($slText !== '' && $slText !== 'brak' && $slText !== '—' && $slText !== '-'
            && ($sl === null || $sl <= 0)) {
            throw new WriteException('bad_number', 'sl musi być pusty albo skończoną liczbą dodatnią.');
        }
        $risk = $sl === null ? null : abs($entry - $sl) * $size;
        $closedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $openedAt = $this->parseDate((string) ($trade['date'] ?? ''));
        $duration = $openedAt === null ? 0 : max(1, (int) round(($closedAt->getTimestamp() - $openedAt->getTimestamp()) / 60));

        $updated = $trade;
        $updated['exit'] = $exitPrice === null ? null : $this->numText($exitPrice);
        $updated['result'] = $result;
        $updated['r'] = $risk !== null && $risk > 0 ? Numbers::round($result / $risk) : null;
        $updated['durationMin'] = $duration;
        $updated['closedAt'] = $closedAt->format('Y-m-d\TH:i:s\Z');
        $updated['mentor'] = $result > 0
            ? 'Pozycja zamknięta z zyskiem. Sprawdź, czy wyszedłeś zgodnie z planem, a nie z emocji.'
            : ($result < 0
                ? 'Pozycja zamknięta na minusie. Sprawdź, czy SL był ustawiony przed wejściem.'
                : 'Pozycja zamknięta na zero (break-even). Oceń, czy wyjście było zgodne z planem.');
        if (array_key_exists('mfe', $args)) {
            $updated['mfe'] = $this->optionalNonNegativeMetric($args['mfe'], 'mfe');
        }
        $comment = trim((string) ($args['comment'] ?? ''));
        if ($comment !== '') {
            $note = trim((string) ($updated['note'] ?? ''));
            $updated['note'] = $this->text(($note === '' ? '' : $note . ' ') . $comment, 2000);
        }

        $state['trades'][$index] = $updated;

        return $this->done($state, $trade, $updated, 'trade:' . $id, $updated);
    }

    private function tradeDelete(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        [$index, $trade] = $this->findTrade($state, $id);
        $review = is_array($state['tradeReviews'][$id] ?? null) ? $state['tradeReviews'][$id] : null;
        array_splice($state['trades'], $index, 1);
        $trashId = $this->newTrashId();
        $state = $this->trash($state, 'trade', $trade, (string) ($trade['pair'] ?? $id) . ' ' . (string) ($trade['date'] ?? ''), $trashId);
        if ($review !== null) {
            $trashIndex = array_key_last($state['trash']);
            $state['trash'][$trashIndex]['associatedReview'] = $review;
        }
        $state = $this->unsetReview($state, $id);

        return $this->done(
            $state,
            $trade,
            ['deleted' => true, 'tradeId' => $id, 'trashId' => $trashId],
            'trade:' . $id,
            ['tradeId' => $id, 'deleted' => true, 'trashId' => $trashId]
        );
    }

    // ------------------------------------------------------------------
    // Recenzje
    // ------------------------------------------------------------------

    private function reviewCreate(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        $this->findTrade($state, $id);
        $reviews = is_array($state['tradeReviews'] ?? null) ? $state['tradeReviews'] : [];
        if (isset($reviews[$id])) {
            throw new WriteException('review_exists', 'Recenzja już istnieje. Użyj update_trade_review.');
        }
        $review = ['tradeId' => $id, 'exchangeOwned' => false] + $this->reviewPayload($args);
        $reviews[$id] = $review;
        $state['tradeReviews'] = $reviews;

        return $this->done($state, null, $review, 'trade:' . $id, $review);
    }

    private function reviewUpdate(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        $this->findTrade($state, $id);
        $reviews = is_array($state['tradeReviews'] ?? null) ? $state['tradeReviews'] : [];
        $before = is_array($reviews[$id] ?? null) ? $reviews[$id] : null;
        if ($before === null) {
            throw new WriteException('review_missing', 'Brak recenzji. Użyj create_trade_review.');
        }
        $changes = $this->changesArray($args, 'changes');
        $unknown = array_values(array_diff(array_keys($changes), self::REVIEW_FIELDS));
        if ($unknown !== []) {
            throw new WriteException('field_not_editable', 'Pola niedozwolone w recenzji: ' . implode(', ', $unknown) . '.');
        }
        $updated = array_merge($before, $this->reviewPayload($changes, true));
        $reviews[$id] = $updated;
        $state['tradeReviews'] = $reviews;

        return $this->done($state, $before, $updated, 'trade:' . $id, $updated);
    }

    private function reviewDelete(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        $reviews = is_array($state['tradeReviews'] ?? null) ? $state['tradeReviews'] : [];
        $review = $reviews[$id] ?? null;
        if (!is_array($review)) {
            throw new WriteException('review_missing', 'Brak recenzji dla tej transakcji.', 404);
        }
        unset($reviews[$id]);
        $state['tradeReviews'] = $reviews;
        $trashId = $this->newTrashId();
        $state = $this->trash($state, 'review', ['tradeId' => $id, 'review' => $review], 'recenzja ' . $id, $trashId);

        return $this->done(
            $state,
            $review,
            ['deleted' => true, 'tradeId' => $id, 'trashId' => $trashId],
            'trade:' . $id,
            ['tradeId' => $id, 'deleted' => true, 'trashId' => $trashId]
        );
    }

    // ------------------------------------------------------------------
    // Setupy
    // ------------------------------------------------------------------

    private function setupCreate(array $state, array $args): array
    {
        $setups = is_array($state['setups'] ?? null) ? $state['setups'] : [];
        $setup = $this->setupPayload($args, null);
        foreach ($setups as $existing) {
            if (mb_strtolower((string) ($existing['name'] ?? '')) === mb_strtolower((string) $setup['name'])) {
                throw new WriteException('setup_exists', 'Setup o tej nazwie już istnieje. Użyj update_setup.');
            }
        }
        $setup['id'] = 'pb' . bin2hex(random_bytes(4));
        $setup['createdAt'] = $this->isoNow();
        $setup['updatedAt'] = $this->isoNow();
        $setups[] = $setup;
        $state['setups'] = array_values($setups);

        return $this->done($state, null, $setup, 'setup:' . $setup['id'], $setup);
    }

    private function setupUpdate(array $state, array $args): array
    {
        $setups = is_array($state['setups'] ?? null) ? $state['setups'] : [];
        $index = $this->findSetupIndex($setups, $args);
        $before = $setups[$index];
        $changes = $this->changesArray($args, 'changes');
        $unknown = array_values(array_diff(array_keys($changes), self::SETUP_FIELDS));
        if ($unknown !== []) {
            throw new WriteException('field_not_editable', 'Pola niedozwolone w setupie: ' . implode(', ', $unknown) . '.');
        }
        $updated = array_merge($before, $this->setupPayload($changes, $before));
        foreach ($setups as $i => $row) {
            if ($i === $index) {
                continue;
            }
            if (mb_strtolower(trim((string) ($row['name'] ?? ''))) === mb_strtolower(trim((string) ($updated['name'] ?? '')))) {
                throw new WriteException('setup_exists', 'Setup o tej nazwie już istnieje.');
            }
        }
        $updated['updatedAt'] = $this->isoNow();
        $setups[$index] = $updated;
        $state['setups'] = array_values($setups);

        return $this->done($state, $before, $updated, 'setup:' . (string) ($updated['id'] ?? ''), $updated);
    }

    private function setupDelete(array $state, array $args): array
    {
        $setups = is_array($state['setups'] ?? null) ? $state['setups'] : [];
        $index = $this->findSetupIndex($setups, $args);
        $setup = $setups[$index];
        array_splice($setups, $index, 1);
        $state['setups'] = array_values($setups);
        $trashId = $this->newTrashId();
        $state = $this->trash($state, 'setup', $setup, (string) ($setup['name'] ?? 'setup'), $trashId);
        $label = (string) ($setup['name'] ?? '');
        $using = 0;
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if ((string) ($trade['setup'] ?? '') === $label) {
                $using++;
            }
        }

        return $this->done(
            $state,
            $setup,
            ['deleted' => true, 'setupId' => (string) ($setup['id'] ?? ''), 'trashId' => $trashId, 'tradesUsingSetup' => $using],
            'setup:' . (string) ($setup['id'] ?? ''),
            ['setupId' => (string) ($setup['id'] ?? ''), 'deleted' => true, 'trashId' => $trashId, 'tradesUsingSetup' => $using]
        );
    }

    // ------------------------------------------------------------------
    // Plan dnia
    // ------------------------------------------------------------------

    private function planCreate(
        array $state,
        array $args,
        ?string $preparedTarget = null,
        bool $allowExistingPreparedTarget = false
    ): array
    {
        $date = $this->dateKey($args['date'] ?? null);
        $plans = is_array($state['plans'] ?? null) ? $state['plans'] : [];
        $id = '';
        $existingKey = null;
        if ($preparedTarget !== null) {
            if (preg_match('/^plan:(\d{4}-\d{2}-\d{2})(?:#(pl_[a-f0-9]{12}))?$/', $preparedTarget, $match) !== 1
                || !Dates::isValidKey($match[1])
                || (array_key_exists('date', $args) && $date !== $match[1])) {
                throw new WriteException('invalid_proposal_target', 'Cel propozycji planu nie odpowiada podglądowi.');
            }
            $date = $match[1];
            $id = (string) ($match[2] ?? '');
            if ($id === '') {
                // Zgodność wsteczna dla propozycji plan.create utworzonych
                // przed wprowadzeniem stabilnego ID w target_ref.
                $id = DayPlanRecords::uniqueId($state);
            }
            foreach (DayPlanRecords::all($state) as $record) {
                if ($record['id'] === $id) {
                    if (!$allowExistingPreparedTarget || $record['date'] !== $date) {
                        throw new WriteException('plan_id_conflict', 'Id planu z propozycji jest już zajęte.');
                    }
                    $existingKey = $record['key'];
                    break;
                }
            }
        } else {
            $id = DayPlanRecords::uniqueId($state);
        }
        $plan = ['id' => $id, 'date' => $date] + $this->planPayload($args, null);
        $plans[$existingKey ?? DayPlanRecords::insertKey($state, $date, $id)] = $plan;
        $state['plans'] = $plans;

        return $this->done($state, null, $plan, 'plan:' . $date . '#' . $id, $plan);
    }

    private function planUpdate(array $state, array $args): array
    {
        $date = $this->dateKey($args['date'] ?? null);
        $plans = is_array($state['plans'] ?? null) ? $state['plans'] : [];
        $entry = $this->resolvePlan($state, $date, $args['planId'] ?? null);
        if ($entry === null) {
            throw new WriteException('plan_missing', 'Brak planu na ' . $date . '. Użyj create_day_plan.');
        }
        $before = $entry['plan'];
        $changes = $this->changesArray($args, 'changes');
        $unknown = array_values(array_diff(array_keys($changes), self::PLAN_FIELDS));
        if ($unknown !== []) {
            throw new WriteException('field_not_editable', 'Pola niedozwolone w planie: ' . implode(', ', $unknown) . '.');
        }
        $updated = array_merge($before, ['id' => $entry['id'], 'date' => $date], $this->planPayload($changes, $before));
        $plans[$entry['key']] = $updated;
        $state['plans'] = $plans;

        return $this->done($state, $before, $updated, 'plan:' . $date . '#' . $entry['id'], $updated);
    }

    private function planApprove(array $state, array $args): array
    {
        $date = $this->dateKey($args['date'] ?? null);
        $plans = is_array($state['plans'] ?? null) ? $state['plans'] : [];
        $entry = $this->resolvePlan($state, $date, $args['planId'] ?? null);
        if ($entry === null) {
            throw new WriteException('plan_missing', 'Brak planu na ' . $date . '.', 404);
        }
        $previous = $entry['plan'];
        $approved = array_merge($previous, ['id' => $entry['id'], 'date' => $date]);
        $approved['approvedAt'] = (int) round(microtime(true) * 1000);
        $plans[$entry['key']] = $approved;
        $state['plans'] = $plans;

        return $this->done($state, $previous, $approved, 'plan:' . $date . '#' . $entry['id'], $approved);
    }

    private function planDelete(array $state, array $args): array
    {
        $date = $this->dateKey($args['date'] ?? null);
        $plans = is_array($state['plans'] ?? null) ? $state['plans'] : [];
        $entry = $this->resolvePlan($state, $date, $args['planId'] ?? null);
        if ($entry === null) {
            throw new WriteException('plan_missing', 'Brak planu na ' . $date . '.', 404);
        }
        $plan = $entry['plan'];
        unset($plans[$entry['key']]);
        $state['plans'] = $plans;
        $trashId = $this->newTrashId();
        $state = $this->trash($state, 'plan', $plan, 'plan ' . $date, $trashId);

        return $this->done(
            $state,
            $plan,
            ['deleted' => true, 'date' => $date, 'planId' => $entry['id'], 'trashId' => $trashId],
            'plan:' . $date . '#' . $entry['id'],
            ['date' => $date, 'planId' => $entry['id'], 'deleted' => true, 'trashId' => $trashId]
        );
    }

    /**
     * @return array{key:string,date:string,id:string,plan:array}|null
     */
    private function resolvePlan(array $state, string $date, mixed $planId): ?array
    {
        $matches = DayPlanRecords::forDate($state, $date);
        $requestedId = trim((string) ($planId ?? ''));
        if ($requestedId !== '') {
            $matches = array_values(array_filter(
                $matches,
                static fn (array $entry): bool => $entry['id'] === $requestedId
            ));
            if (count($matches) > 1) {
                throw new WriteException('plan_id_ambiguous', 'planId nie identyfikuje jednoznacznie planu na ' . $date . '.', 409);
            }

            return $matches[0] ?? null;
        }
        if (count($matches) > 1) {
            throw new WriteException('plan_id_required', 'Na ' . $date . ' jest wiele planów. Podaj planId, aby wskazać właściwy rekord.');
        }

        return $matches[0] ?? null;
    }

    // ------------------------------------------------------------------
    // Check-in
    // ------------------------------------------------------------------

    private function checkinCreate(array $state, array $args): array
    {
        $date = $this->dateKey($args['date'] ?? null);
        $checkins = is_array($state['checkins'] ?? null) ? $state['checkins'] : [];
        $before = null;
        foreach ($checkins as $index => $row) {
            if ((string) ($row['date'] ?? '') === $date) {
                $before = $row;
                array_splice($checkins, $index, 1);
                break;
            }
        }
        $checkin = ['date' => $date] + $this->checkinPayload($args);
        $checkins[] = $checkin;
        usort($checkins, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
        $state['checkins'] = array_values($checkins);

        return $this->done($state, $before, $checkin, 'checkin:' . $date, $checkin);
    }

    private function checkinUpdate(array $state, array $args): array
    {
        $date = $this->dateKey($args['date'] ?? null);
        $checkins = is_array($state['checkins'] ?? null) ? $state['checkins'] : [];
        $index = null;
        foreach ($checkins as $i => $row) {
            if ((string) ($row['date'] ?? '') === $date) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('checkin_missing', 'Brak check-inu na ' . $date . '. Użyj create_checkin.');
        }
        $before = $checkins[$index];
        $changes = $this->changesArray($args, 'changes');
        $unknown = array_diff(array_keys($changes), ['mood', 'energy', 'note']);
        if ($unknown !== []) {
            throw new WriteException('field_not_editable', 'Pola niedozwolone w check-inie: ' . implode(', ', $unknown) . '.');
        }
        $payload = array_merge([
            'mood' => (string) ($before['mood'] ?? 'Spokój'),
            'energy' => (int) ($before['energy'] ?? 3),
            'note' => (string) ($before['note'] ?? ''),
        ], $changes);
        $checkins[$index] = array_merge($before, $this->checkinPayload($payload));
        $checkins[$index]['date'] = $date;
        $state['checkins'] = array_values($checkins);

        return $this->done($state, $before, $checkins[$index], 'checkin:' . $date, $checkins[$index]);
    }

    private function checkinDelete(array $state, array $args): array
    {
        $date = $this->dateKey($args['date'] ?? null);
        $checkins = is_array($state['checkins'] ?? null) ? $state['checkins'] : [];
        $before = null;
        foreach ($checkins as $index => $row) {
            if ((string) ($row['date'] ?? '') === $date) {
                $before = $row;
                array_splice($checkins, $index, 1);
                break;
            }
        }
        if ($before === null) {
            throw new WriteException('checkin_missing', 'Brak check-inu na ' . $date . '.', 404);
        }
        $state['checkins'] = array_values($checkins);
        $trashId = $this->newTrashId();
        $state = $this->trash($state, 'checkin', $before, 'check-in ' . $date, $trashId);

        return $this->done(
            $state,
            $before,
            ['deleted' => true, 'date' => $date, 'trashId' => $trashId],
            'checkin:' . $date,
            ['date' => $date, 'deleted' => true, 'trashId' => $trashId]
        );
    }

    // ------------------------------------------------------------------
    // Konta
    // ------------------------------------------------------------------

    private function accountCreate(
        array $state,
        array $args,
        ?string $preparedTarget = null,
        bool $allowExistingPreparedTarget = false
    ): array
    {
        $accounts = is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new WriteException('invalid_account_name', 'Nazwa konta musi mieć 1-60 znaków.');
        }
        foreach ($accounts as $existing) {
            if (mb_strtolower(trim((string) ($existing['name'] ?? ''))) === mb_strtolower($name)) {
                throw new WriteException('account_exists', 'Konto o tej nazwie już istnieje.');
            }
        }
        $currency = strtoupper(trim((string) ($args['currency'] ?? 'USDT')));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new WriteException('invalid_currency', 'Waluta musi być jedną z: ' . implode(', ', self::CURRENCIES) . '.');
        }
        $start = $this->amount($args['start'] ?? null, 'start');
        $balance = array_key_exists('balance', $args) ? $this->amount($args['balance'], 'balance') : $start;
        $primary = (bool) ($args['primary'] ?? $accounts === []);
        $accountId = $this->preparedId(
            $preparedTarget,
            'account',
            'acc',
            $accounts,
            $allowExistingPreparedTarget,
            'account_id_conflict',
            'Id konta z propozycji jest już zajęte.'
        ) ?? ('acc' . bin2hex(random_bytes(4)));
        $account = [
            'id' => $accountId,
            'name' => $name,
            'exchange' => $this->text($args['exchange'] ?? '', 40),
            'type' => $this->text($args['type'] ?? 'Futures', 40),
            'currency' => $currency,
            'start' => Numbers::round($start),
            'date' => $this->dateKey($args['date'] ?? null),
            'lev' => $this->text($args['lev'] ?? '', 20),
            'goal' => $this->text($args['goal'] ?? '', 200),
            'balance' => Numbers::round($balance),
            'include' => (bool) ($args['include'] ?? true),
            'primary' => $primary,
        ];
        if ($primary) {
            foreach ($accounts as $index => $existing) {
                $accounts[$index]['primary'] = false;
            }
        }
        $accounts[] = $account;
        $state['accounts'] = array_values($accounts);

        return $this->done($state, null, $account, 'account:' . $account['id'], $account);
    }

    private function accountUpdate(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'accountId', 128);
        $accounts = is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
        $index = null;
        foreach ($accounts as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('account_missing', 'Konto nie istnieje. Użyj get_accounts.', 404);
        }
        $before = $accounts[$index];
        $changes = $this->changesArray($args, 'changes');
        $updated = $before;
        foreach ($changes as $field => $value) {
            if (!in_array($field, self::ACCOUNT_FIELDS, true)) {
                throw new WriteException('field_not_editable', 'Pole "' . $field . '" nie jest edytowalne przez MCP.');
            }
            if ($field === 'name') {
                $name = trim((string) $value);
                if ($name === '' || mb_strlen($name) > 60) {
                    throw new WriteException('invalid_account_name', 'Nazwa konta musi mieć 1-60 znaków.');
                }
                foreach ($accounts as $i => $row) {
                    if ($i !== $index && mb_strtolower(trim((string) ($row['name'] ?? ''))) === mb_strtolower($name)) {
                        throw new WriteException('account_exists', 'Konto o tej nazwie już istnieje.');
                    }
                }
                $updated['name'] = $name;
            } elseif ($field === 'currency') {
                $currency = strtoupper(trim((string) $value));
                if (!in_array($currency, self::CURRENCIES, true)) {
                    throw new WriteException('invalid_currency', 'Waluta musi być jedną z: ' . implode(', ', self::CURRENCIES) . '.');
                }
                $updated['currency'] = $currency;
            } elseif (in_array($field, ['start', 'balance'], true)) {
                $updated[$field] = Numbers::round($this->amount($value, $field));
            } elseif (in_array($field, ['include', 'primary'], true)) {
                $updated[$field] = (bool) $value;
            } elseif ($field === 'date') {
                $updated['date'] = $this->dateKey($value);
            } else {
                $updated[$field] = $this->text($value, $field === 'goal' ? 200 : 40);
            }
        }
        if (!empty($updated['primary'])) {
            foreach ($accounts as $i => $row) {
                if ($i !== $index) {
                    $accounts[$i]['primary'] = false;
                }
            }
        }
        $accounts[$index] = $updated;
        $state['accounts'] = array_values($accounts);

        return $this->done($state, $before, $updated, 'account:' . $id, $updated);
    }

    private function accountDelete(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'accountId', 128);
        $accounts = is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
        $index = null;
        foreach ($accounts as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('account_missing', 'Konto nie istnieje.', 404);
        }
        $account = $accounts[$index];
        $name = (string) ($account['name'] ?? '');
        array_splice($accounts, $index, 1);
        if ($accounts !== [] && !array_filter($accounts, static fn (array $row): bool => !empty($row['primary']))) {
            $accounts[0]['primary'] = true;
        }
        $state['accounts'] = array_values($accounts);
        $trashId = $this->newTrashId();
        $state = $this->trash($state, 'account', $account, $name, $trashId);
        $tradesUsing = 0;
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if ((string) ($trade['account'] ?? '') === $name) {
                $tradesUsing++;
            }
        }

        return $this->done(
            $state,
            $account,
            ['deleted' => true, 'accountId' => $id, 'trashId' => $trashId, 'tradesUsingAccount' => $tradesUsing],
            'account:' . $id,
            ['accountId' => $id, 'deleted' => true, 'trashId' => $trashId, 'tradesUsingAccount' => $tradesUsing]
        );
    }

    // ------------------------------------------------------------------
    // Wpłaty i wypłaty
    // ------------------------------------------------------------------

    private function flowCreate(
        array $state,
        array $args,
        ?string $preparedTarget = null,
        bool $allowExistingPreparedTarget = false
    ): array
    {
        $flows = is_array($state['flows'] ?? null) ? $state['flows'] : [];
        $flow = $this->flowPayload($args, null, $state);
        $flow['id'] = $this->preparedId(
            $preparedTarget,
            'flow',
            'f',
            $flows,
            $allowExistingPreparedTarget,
            'flow_id_conflict',
            'Id wpisu kapitałowego z propozycji jest już zajęte.'
        ) ?? ('f' . bin2hex(random_bytes(4)));
        $flows[] = $flow;
        $state['flows'] = array_values($flows);

        return $this->done($state, null, $flow, 'flow:' . $flow['id'], $flow);
    }

    private function flowUpdate(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'flowId', 128);
        $flows = is_array($state['flows'] ?? null) ? $state['flows'] : [];
        $index = null;
        foreach ($flows as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('flow_missing', 'Wpis kapitałowy nie istnieje.', 404);
        }
        $before = $flows[$index];
        $changes = $this->changesArray($args, 'changes');
        $unknown = array_values(array_diff(array_keys($changes), self::FLOW_FIELDS));
        if ($unknown !== []) {
            throw new WriteException('field_not_editable', 'Pola niedozwolone we wpisie kapitałowym: ' . implode(', ', $unknown) . '.');
        }
        $updated = array_merge($before, $this->flowPayload($changes, $before, $state));
        $flows[$index] = $updated;
        $state['flows'] = array_values($flows);

        return $this->done($state, $before, $updated, 'flow:' . $id, $updated);
    }

    private function flowDelete(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'flowId', 128);
        $flows = is_array($state['flows'] ?? null) ? $state['flows'] : [];
        $index = null;
        foreach ($flows as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('flow_missing', 'Wpis kapitałowy nie istnieje.', 404);
        }
        $flow = $flows[$index];
        array_splice($flows, $index, 1);
        $state['flows'] = array_values($flows);
        $trashId = $this->newTrashId();
        $label = (string) ($flow['type'] ?? '') . ' ' . $this->numText((float) ($flow['amount'] ?? 0)) . ' ' . (string) ($flow['currency'] ?? '');
        $state = $this->trash($state, 'flow', $flow, $label, $trashId);

        return $this->done(
            $state,
            $flow,
            ['deleted' => true, 'flowId' => $id, 'trashId' => $trashId],
            'flow:' . $id,
            ['flowId' => $id, 'deleted' => true, 'trashId' => $trashId]
        );
    }

    // ------------------------------------------------------------------
    // Reguły ryzyka
    // ------------------------------------------------------------------

    private function riskUpdate(array $state, array $args): array
    {
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $changes = $this->riskAliases($this->changesArray($args, 'changes'));
        $reason = trim((string) ($args['reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new WriteException('reason_required', 'reason musi mieć 10-500 znaków i wyjaśniać powód zmiany.');
        }
        $before = [];
        foreach (array_keys($changes) as $field) {
            $before[$field] = $settings[$field] ?? null;
        }
        $updated = $settings;
        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'maxRisk':
                case 'maxDailyLoss':
                    $number = $this->number($value, $field);
                    if ($number <= 0 || $number > 100) {
                        throw new WriteException('invalid_risk_limit', $field . ' musi być w zakresie 0.1-100.');
                    }
                    $current = Numbers::pnum($settings[$field] ?? 1);
                    if ($number > $current) {
                        throw new WriteException(
                            'risk_not_tightened',
                            'MCP może tylko zmniejszać limity ryzyka. Obecnie ' . $this->numText($current) . ', żądano ' . $this->numText($number) . '.',
                            403
                        );
                    }
                    $updated[$field] = Numbers::round($number, 2);
                    break;
                case 'maxLosses':
                    $number = (int) $value;
                    $current = (int) ($settings['maxLosses'] ?? 2);
                    if ($number < 1 || $number > 50) {
                        throw new WriteException('invalid_risk_limit', 'maxLosses musi być w zakresie 1-50.');
                    }
                    if ($number > $current) {
                        throw new WriteException('risk_not_tightened', 'MCP może tylko zmniejszać maxLosses. Obecnie ' . $current . '.', 403);
                    }
                    $updated[$field] = $number;
                    break;
                case 'block':
                    $value = (string) $value;
                    if (!isset(self::BLOCK_ORDER[$value])) {
                        throw new WriteException('invalid_block', 'block musi być 1h, 4h albo midnight.');
                    }
                    $current = (string) ($settings['block'] ?? 'midnight');
                    if (self::BLOCK_ORDER[$value] < (self::BLOCK_ORDER[$current] ?? 3)) {
                        throw new WriteException('risk_not_tightened', 'MCP może tylko wydłużać blokadę po stratach.', 403);
                    }
                    $updated[$field] = $value;
                    break;
                case 'requireReason':
                case 'safetyEnabled':
                case 'pushLimit':
                    if ((bool) $value === false && !empty($settings[$field])) {
                        throw new WriteException('risk_not_tightened', 'MCP nie może wyłączać zabezpieczeń.', 403);
                    }
                    $updated[$field] = (bool) $value;
                    break;
                case 'rules':
                case 'checklist':
                    $list = $this->stringList($value, $field, $field === 'rules' ? 10 : 20, 200);
                    $currentList = array_values(array_map('strval', (array) ($settings[$field] ?? [])));
                    $missing = array_values(array_filter($currentList, static fn (string $rule): bool => !in_array($rule, $list, true)));
                    if ($missing !== []) {
                        throw new WriteException(
                            'risk_not_tightened',
                            'MCP może dopisywać reguły, ale nie usuwać istniejących. Usunięte: ' . implode(' | ', $missing),
                            403
                        );
                    }
                    $updated[$field] = $list;
                    break;
                default:
                    throw new WriteException('field_not_editable', 'Pole "' . $field . '" nie jest edytowalne przez MCP.');
            }
        }
        $state['settings'] = $updated;

        return $this->done(
            $state,
            $before,
            array_intersect_key($updated, $changes),
            'settings:risk',
            ['settings' => array_intersect_key($updated, $changes), 'reason' => $reason]
        );
    }

    /**
     * Mapuje nazwy pól z `get_risk_rules` na pola ustawień.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function riskAliases(array $changes): array
    {
        $aliases = [
            'maxRiskPercentPerTrade' => 'maxRisk',
            'maxConsecutiveLossesPerDay' => 'maxLosses',
            'maxDailyLossPercent' => 'maxDailyLoss',
            'blockDuration' => 'block',
            'requireReasonForOverride' => 'requireReason',
            'notifyOnTrigger' => 'pushLimit',
        ];
        $normalized = [];
        foreach ($changes as $field => $value) {
            $field = (string) $field;
            if ($field === 'safetySwitch') {
                if (is_array($value)) {
                    foreach (['enabled' => 'safetyEnabled', 'requireReasonForOverride' => 'requireReason', 'notifyOnTrigger' => 'pushLimit', 'blockDuration' => 'block'] as $key => $target) {
                        if (array_key_exists($key, $value)) {
                            $normalized[$target] = $value[$key];
                        }
                    }
                } else {
                    $normalized['safetyEnabled'] = (bool) $value;
                }
                continue;
            }
            $normalized[$aliases[$field] ?? $field] = $value;
        }

        return $normalized;
    }

    // ------------------------------------------------------------------
    // Prop challenges
    // ------------------------------------------------------------------

    private function propCreate(
        array $state,
        array $args,
        ?string $preparedTarget = null,
        bool $allowExistingPreparedTarget = false
    ): array
    {
        $challenges = is_array($state['prop']['challenges'] ?? null) ? $state['prop']['challenges'] : [];
        $payload = $this->propPayload($args, null);
        $payload['id'] = $this->preparedId(
            $preparedTarget,
            'prop',
            'prop_',
            $challenges,
            $allowExistingPreparedTarget,
            'prop_id_conflict',
            'Id challenge z propozycji jest już zajęte.'
        ) ?? '';
        $challenge = PropChallengeService::normalize($payload);
        $challenge['createdAt'] = $this->isoNow();
        $challenges[] = $challenge;
        $state['prop']['challenges'] = array_values($challenges);
        if (empty($state['prop']['selectedId'])) {
            $state['prop']['selectedId'] = (string) $challenge['id'];
        }

        return $this->done($state, null, $challenge, 'prop:' . (string) $challenge['id'], $challenge);
    }

    private function propUpdate(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'challengeId', 128);
        $challenges = is_array($state['prop']['challenges'] ?? null) ? $state['prop']['challenges'] : [];
        $index = null;
        foreach ($challenges as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('prop_missing', 'Prop challenge nie istnieje. Użyj get_prop_challenges.', 404);
        }
        $before = $challenges[$index];
        $changes = $this->changesArray($args, 'changes');
        $unknown = array_values(array_diff(array_keys($changes), self::PROP_FIELDS));
        if ($unknown !== []) {
            throw new WriteException('field_not_editable', 'Pola niedozwolone w challenge: ' . implode(', ', $unknown) . '.');
        }
        $updated = PropChallengeService::normalize(array_merge($before, $this->propPayload($changes, $before)));
        $updated['id'] = (string) ($before['id'] ?? $id);
        $updated['createdAt'] = (string) ($before['createdAt'] ?? $this->isoNow());
        $challenges[$index] = $updated;
        $state['prop']['challenges'] = array_values($challenges);

        return $this->done($state, $before, $updated, 'prop:' . $id, $updated);
    }

    private function propDelete(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'challengeId', 128);
        $challenges = is_array($state['prop']['challenges'] ?? null) ? $state['prop']['challenges'] : [];
        $index = null;
        foreach ($challenges as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('prop_missing', 'Prop challenge nie istnieje.', 404);
        }
        $challenge = $challenges[$index];
        array_splice($challenges, $index, 1);
        $state['prop']['challenges'] = array_values($challenges);
        if ((string) ($state['prop']['selectedId'] ?? '') === $id) {
            $state['prop']['selectedId'] = $state['prop']['challenges'] === [] ? null : (string) ($state['prop']['challenges'][0]['id'] ?? '');
        }
        $trashId = $this->newTrashId();
        $state = $this->trash($state, 'prop', $challenge, (string) ($challenge['name'] ?? 'prop'), $trashId);

        return $this->done(
            $state,
            $challenge,
            ['deleted' => true, 'challengeId' => $id, 'trashId' => $trashId],
            'prop:' . $id,
            ['challengeId' => $id, 'deleted' => true, 'trashId' => $trashId]
        );
    }

    // ------------------------------------------------------------------
    // Screeny transakcji
    // ------------------------------------------------------------------

    private function screenshotAdd(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        [$index, $trade] = $this->findTrade($state, $id);
        $position = strtolower(trim((string) ($args['position'] ?? 'before')));
        if (!in_array($position, ['before', 'after'], true)) {
            throw new WriteException('invalid_position', 'position musi być before albo after.');
        }
        $dataUrl = trim((string) ($args['dataUrl'] ?? ''));
        if (preg_match('#^data:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match) !== 1) {
            throw new WriteException('invalid_screenshot', 'dataUrl musi być obrazem PNG/JPEG/WebP jako data URL base64.');
        }
        $decoded = base64_decode($match[2], true);
        $bytes = $decoded === false ? 0 : strlen($decoded);
        if ($bytes <= 0) {
            throw new WriteException('invalid_screenshot', 'Obraz jest pusty albo uszkodzony.');
        }
        if ($bytes > self::SCREEN_MAX_BYTES) {
            throw new WriteException('screenshot_too_large', 'Pojedynczy screen może mieć maksymalnie 200 KB.');
        }
        $screens = is_array($trade['screens'] ?? null) ? $trade['screens'] : [];
        $total = $bytes;
        foreach ($screens as $key => $existing) {
            if ($key === $position) {
                continue;
            }
            $text = (string) $existing;
            $comma = strpos($text, ',');
            $payload = $comma === false ? '' : substr($text, $comma + 1);
            $existingBytes = base64_decode($payload, true);
            $total += $existingBytes === false ? 0 : strlen($existingBytes);
        }
        if ($total > self::SCREENS_TOTAL_BYTES) {
            throw new WriteException('screens_too_large', 'Łączny rozmiar screenów transakcji przekracza 400 KB.');
        }
        $before = isset($screens[$position]) ? ['position' => $position, 'bytes' => 'existing'] : null;
        $screens[$position] = $dataUrl;
        $updated = $trade;
        $updated['screens'] = $screens;
        $state['trades'][$index] = $updated;

        return $this->done(
            $state,
            $before,
            ['position' => $position, 'bytes' => $bytes],
            'trade:' . $id,
            ['tradeId' => $id, 'position' => $position, 'bytes' => $bytes]
        );
    }

    private function screenshotDelete(array $state, array $args): array
    {
        $id = $this->requiredString($args, 'tradeId', 128);
        [$index, $trade] = $this->findTrade($state, $id);
        $position = strtolower(trim((string) ($args['position'] ?? '')));
        if (!in_array($position, ['before', 'after'], true)) {
            throw new WriteException('invalid_position', 'position musi być before albo after.');
        }
        $screens = is_array($trade['screens'] ?? null) ? $trade['screens'] : [];
        if (!isset($screens[$position])) {
            throw new WriteException('screenshot_missing', 'Brak screenu "' . $position . '" dla tej transakcji.', 404);
        }
        $dataUrl = (string) $screens[$position];
        unset($screens[$position]);
        $updated = $trade;
        $updated['screens'] = $screens;
        $state['trades'][$index] = $updated;
        $trashId = $this->newTrashId();
        $state = $this->trash(
            $state,
            'screenshot',
            ['tradeId' => $id, 'position' => $position, 'dataUrl' => $dataUrl],
            'screen ' . $position . ' ' . $id,
            $trashId
        );

        return $this->done(
            $state,
            ['position' => $position, 'bytes' => 'existing'],
            ['deleted' => true, 'position' => $position, 'trashId' => $trashId],
            'trade:' . $id,
            ['tradeId' => $id, 'position' => $position, 'deleted' => true, 'trashId' => $trashId]
        );
    }

    // ------------------------------------------------------------------
    // Przywracanie z kosza
    // ------------------------------------------------------------------

    private function restoreRecord(array $state, array $args): array
    {
        $trashId = $this->requiredString($args, 'trashId', 128);
        $trash = is_array($state['trash'] ?? null) ? $state['trash'] : [];
        $index = null;
        foreach ($trash as $i => $entry) {
            if ((string) ($entry['id'] ?? '') === $trashId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new WriteException('trash_missing', 'Wpis nie istnieje w koszu. Użyj identyfikatora trashId zwróconego przy usunięciu.', 404);
        }
        $entry = $trash[$index];
        $kind = (string) ($entry['kind'] ?? '');
        $record = is_array($entry['record'] ?? null) ? $entry['record'] : [];
        $target = 'trash:' . $trashId;

        switch ($kind) {
            case 'trade':
                $tradeId = (string) ($record['id'] ?? '');
                foreach ($state['trades'] as $existing) {
                    if ($tradeId !== '' && (string) ($existing['id'] ?? '') === $tradeId) {
                        throw new WriteException('restore_conflict', 'Transakcja o tym ID już istnieje.', 409);
                    }
                }
                $associatedReview = is_array($entry['associatedReview'] ?? null)
                    ? $entry['associatedReview']
                    : null;
                if ($associatedReview !== null && $tradeId !== ''
                    && array_key_exists($tradeId, $state['tradeReviews'])) {
                    throw new WriteException('restore_conflict', 'Recenzja dla tej transakcji już istnieje.', 409);
                }
                $state['trades'][] = $record;
                if ($associatedReview !== null && $tradeId !== '') {
                    $state['tradeReviews'][$tradeId] = $associatedReview;
                }
                $target = 'trade:' . $tradeId;
                break;
            case 'review':
                $tradeId = (string) ($record['tradeId'] ?? '');
                $review = is_array($record['review'] ?? null) ? $record['review'] : [];
                if ($tradeId === '' || $review === []) {
                    throw new WriteException('restore_conflict', 'Wpis recenzji jest niekompletny.', 409);
                }
                if (array_key_exists($tradeId, $state['tradeReviews'])) {
                    throw new WriteException('restore_conflict', 'Recenzja dla tej transakcji już istnieje.', 409);
                }
                $state['tradeReviews'][$tradeId] = $review;
                $target = 'trade:' . $tradeId;
                break;
            case 'setup':
                $recordId = (string) ($record['id'] ?? '');
                $recordName = mb_strtolower((string) ($record['name'] ?? ''));
                foreach ($state['setups'] as $existing) {
                    if ((string) ($existing['id'] ?? '') === $recordId
                        || ($recordName !== '' && mb_strtolower((string) ($existing['name'] ?? '')) === $recordName)) {
                        throw new WriteException('restore_conflict', 'Setup o tym ID albo nazwie już istnieje.', 409);
                    }
                }
                $state['setups'][] = $record;
                $target = 'setup:' . $recordId;
                break;
            case 'plan':
                $date = (string) ($record['date'] ?? '');
                if (!Dates::isValidKey($date)) {
                    throw new WriteException('restore_conflict', 'Data planu w koszu jest nieprawidłowa.', 409);
                }
                $record['id'] = trim((string) ($record['id'] ?? '')) ?: $date;
                $duplicateId = array_filter(
                    DayPlanRecords::all($state),
                    static fn (array $entry): bool => $entry['id'] === $record['id']
                );
                if ($duplicateId !== []) {
                    throw new WriteException('restore_conflict', 'Plan o tym planId już istnieje.', 409);
                }
                $record['date'] = $date;
                $plans = is_array($state['plans'] ?? null) ? $state['plans'] : [];
                $plans[DayPlanRecords::insertKey($state, $date, $record['id'])] = $record;
                $state['plans'] = $plans;
                $target = 'plan:' . $date . '#' . $record['id'];
                break;
            case 'checkin':
                $date = (string) ($record['date'] ?? '');
                $exists = false;
                foreach ($state['checkins'] as $row) {
                    if ((string) ($row['date'] ?? '') === $date) {
                        $exists = true;
                        break;
                    }
                }
                if ($date === '' || $exists) {
                    throw new WriteException('restore_conflict', 'Check-in na ten dzień już istnieje.', 409);
                }
                $state['checkins'][] = $record;
                usort($state['checkins'], static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));
                $target = 'checkin:' . $date;
                break;
            case 'account':
                foreach ($state['accounts'] as $existing) {
                    if (mb_strtolower((string) ($existing['name'] ?? '')) === mb_strtolower((string) ($record['name'] ?? ''))) {
                        throw new WriteException('restore_conflict', 'Konto o tej nazwie już istnieje.', 409);
                    }
                }
                $state['accounts'][] = $record;
                $target = 'account:' . (string) ($record['id'] ?? '');
                break;
            case 'flow':
                $state['flows'][] = $record;
                $target = 'flow:' . (string) ($record['id'] ?? '');
                break;
            case 'prop':
                $state['prop']['challenges'][] = $record;
                if (empty($state['prop']['selectedId'])) {
                    $state['prop']['selectedId'] = (string) ($record['id'] ?? '');
                }
                $target = 'prop:' . (string) ($record['id'] ?? '');
                break;
            case 'screenshot':
                $tradeId = (string) ($record['tradeId'] ?? '');
                $position = (string) ($record['position'] ?? '');
                $dataUrl = (string) ($record['dataUrl'] ?? '');
                [$tradeIndex, $trade] = $this->findTrade($state, $tradeId);
                $screens = is_array($trade['screens'] ?? null) ? $trade['screens'] : [];
                if (!in_array($position, ['before', 'after'], true) || $dataUrl === '' || isset($screens[$position])) {
                    throw new WriteException('restore_conflict', 'Nie można przywrócić tego screenu.', 409);
                }
                $screens[$position] = $dataUrl;
                $state['trades'][$tradeIndex]['screens'] = $screens;
                $target = 'trade:' . $tradeId;
                break;
            default:
                throw new WriteException('restore_conflict', 'Nieznany typ wpisu w koszu: ' . $kind, 409);
        }

        array_splice($trash, $index, 1);
        $state['trash'] = array_values($trash);

        return $this->done(
            $state,
            null,
            ['restored' => true, 'kind' => $kind, 'target' => $target],
            $target,
            ['restored' => true, 'kind' => $kind, 'record' => $record]
        );
    }

    // ------------------------------------------------------------------
    // Pomocnicze: stan, rekordy, walidacja
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $result
     * @return array{state:array,before:?array,after:array,target:string,result:array,changes:array}
     */
    private function done(array $state, ?array $before, array $after, string $target, array $result): array
    {
        return [
            'state' => $state,
            'before' => $before,
            'after' => $after,
            'target' => $target,
            'result' => $result,
            'changes' => $this->diff($before, $after),
        ];
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     * @return list<array{field:string,before:mixed,after:mixed}>
     */
    private function diff(?array $before, array $after): array
    {
        return RecordDiff::between($before, $after, ['screens', 'seed']);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $record
     */
    private function trash(array $state, string $kind, array $record, string $label, string $trashId): array
    {
        $items = is_array($state['trash'] ?? null) ? $state['trash'] : [];
        $items[] = [
            'id' => $trashId,
            'kind' => $kind,
            'label' => mb_substr($label, 0, 120),
            'record' => $record,
            'deletedAt' => $this->isoNow(),
        ];
        if (count($items) > self::TRASH_LIMIT) {
            $items = array_slice($items, -self::TRASH_LIMIT);
        }
        $state['trash'] = array_values($items);

        return $state;
    }

    private function newTrashId(): string
    {
        return 'tr_' . bin2hex(random_bytes(6));
    }

    /**
     * @param array<string,mixed> $state
     */
    private function unsetReview(array $state, string $tradeId): array
    {
        $reviews = is_array($state['tradeReviews'] ?? null) ? $state['tradeReviews'] : [];
        if (isset($reviews[$tradeId])) {
            unset($reviews[$tradeId]);
            $state['tradeReviews'] = $reviews;
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array{0:int,1:array<string,mixed>}
     */
    private function findTrade(array $state, string $id): array
    {
        foreach ((array) ($state['trades'] ?? []) as $index => $trade) {
            if (is_array($trade) && (string) ($trade['id'] ?? '') === $id) {
                return [(int) $index, $trade];
            }
        }

        throw new WriteException('trade_missing', 'Transakcja o ID ' . $id . ' nie istnieje. Użyj list_trades.', 404);
    }

    /**
     * @param list<array<string,mixed>> $setups
     * @param array<string,mixed> $args
     */
    private function findSetupIndex(array $setups, array $args): int
    {
        $id = trim((string) ($args['setupId'] ?? ''));
        $name = trim((string) ($args['name'] ?? ''));
        foreach ($setups as $index => $setup) {
            if ($id !== '' && (string) ($setup['id'] ?? '') === $id) {
                return (int) $index;
            }
            if ($id === '' && $name !== '' && mb_strtolower((string) ($setup['name'] ?? '')) === mb_strtolower($name)) {
                return (int) $index;
            }
        }

        throw new WriteException('setup_missing', 'Setup nie istnieje. Użyj list_setups.', 404);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function reviewPayload(array $args, bool $partial = false): array
    {
        $payload = [];
        if (!$partial || array_key_exists('grade', $args)) {
            $grade = strtoupper(trim((string) ($args['grade'] ?? 'B')));
            if (!in_array($grade, ['A', 'B', 'C'], true)) {
                throw new WriteException('invalid_grade', 'grade musi być A, B albo C.');
            }
            $payload['grade'] = $grade;
        }
        if (!$partial || array_key_exists('setup', $args)) {
            $payload['setup'] = $this->text($args['setup'] ?? '', 100);
        }
        if (!$partial || array_key_exists('emotion', $args)) {
            $emotion = trim((string) ($args['emotion'] ?? 'Spokój'));
            if (!in_array($emotion, self::EMOTIONS, true)) {
                throw new WriteException('invalid_emotion', 'Nieprawidłowa emocja. Dozwolone: ' . implode(', ', self::EMOTIONS) . '.');
            }
            $payload['emotion'] = $emotion;
        }
        if (!$partial || array_key_exists('errors', $args)) {
            $payload['errors'] = $this->stringList($args['errors'] ?? [], 'errors', 10, 80);
        }
        if (!$partial || array_key_exists('checklist', $args)) {
            $payload['checklist'] = max(0, min(20, (int) ($args['checklist'] ?? 0)));
        }
        if (!$partial || array_key_exists('note', $args)) {
            $payload['note'] = $this->text($args['note'] ?? '', 2000);
        }
        if (!$partial || array_key_exists('recommendations', $args)) {
            $payload['recommendations'] = $this->stringList($args['recommendations'] ?? [], 'recommendations', 10, 300);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $before
     * @return array<string,mixed>
     */
    private function setupPayload(array $args, ?array $before): array
    {
        $payload = [];
        $name = trim((string) ($args['name'] ?? ($before['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new WriteException('invalid_setup_name', 'Nazwa setupu musi mieć 1-80 znaków.');
        }
        $rules = array_key_exists('rules', $args)
            ? $this->stringList($args['rules'], 'rules', 12, 300)
            : array_values((array) ($before['rules'] ?? []));
        if (count($rules) < 2) {
            throw new WriteException('invalid_setup_rules', 'Setup wymaga co najmniej 2 reguł wejścia.');
        }
        $grade = strtoupper(trim((string) ($args['grade'] ?? ($before['grade'] ?? 'B'))));
        if (!in_array($grade, ['A', 'B', 'C'], true)) {
            throw new WriteException('invalid_grade', 'grade musi być A, B albo C.');
        }
        $payload['name'] = $name;
        $payload['rules'] = array_values($rules);
        $payload['tf'] = $this->text($args['tf'] ?? ($before['tf'] ?? ''), 40);
        $payload['grade'] = $grade;
        $payload['bad'] = (bool) ($args['bad'] ?? ($before['bad'] ?? false));
        foreach (['description' => 1000, 'confirmation' => 500, 'invalidation' => 500, 'stopLoss' => 200, 'minRR' => 40] as $field => $limit) {
            $payload[$field] = $this->text($args[$field] ?? ($before[$field] ?? ''), $limit);
        }
        $payload['exitRules'] = array_key_exists('exitRules', $args)
            ? $this->stringList($args['exitRules'], 'exitRules', 10, 300)
            : array_values((array) ($before['exitRules'] ?? []));
        $payload['examples'] = array_key_exists('examples', $args)
            ? $this->stringList($args['examples'], 'examples', 10, 500)
            : array_values((array) ($before['examples'] ?? []));

        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $before
     * @return array<string,mixed>
     */
    private function planPayload(array $args, ?array $before): array
    {
        $payload = [];
        $markets = array_key_exists('markets', $args)
            ? $this->stringList($args['markets'], 'markets', 12, 40)
            : array_values((array) ($before['markets'] ?? []));
        $market = array_key_exists('market', $args)
            ? $this->text($args['market'], 200)
            : (array_key_exists('markets', $args) ? (string) ($markets[0] ?? '') : $this->text($before['market'] ?? '', 200));
        if ($market === '' && $markets !== []) {
            $market = (string) $markets[0];
        }
        $payload['market'] = $market;
        $payload['markets'] = $markets;
        $bias = strtolower(trim((string) ($args['bias'] ?? ($before['bias'] ?? 'none'))));
        if (!in_array($bias, self::BIAS, true)) {
            throw new WriteException('invalid_bias', 'bias musi być long, short, neutral albo none.');
        }
        $payload['bias'] = $bias;
        $payload['why'] = $this->text($args['why'] ?? ($before['why'] ?? ''), 2000);
        $payload['levels'] = array_key_exists('levels', $args)
            ? $this->levels($args['levels'])
            : array_values((array) ($before['levels'] ?? []));
        foreach (['ifMain' => 500, 'thenMain' => 500, 'ifAlt' => 500, 'thenAlt' => 500, 'notDo' => 1000] as $field => $limit) {
            $payload[$field] = $this->text($args[$field] ?? ($before[$field] ?? ''), $limit);
        }
        $payload['risk'] = $this->text($args['risk'] ?? ($before['risk'] ?? ''), 20);
        $payload['maxTrades'] = max(0, min(20, (int) ($args['maxTrades'] ?? ($before['maxTrades'] ?? 0))));
        $until = trim((string) ($args['until'] ?? ($before['until'] ?? '')));
        if ($until !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $until) !== 1) {
            throw new WriteException('invalid_until', 'until musi mieć format HH:MM.');
        }
        $payload['until'] = $until;

        return $payload;
    }

    /**
     * @return list<array{value:string,note:string}>
     */
    private function levels(mixed $value): array
    {
        try {
            return DayPlanLevels::normalizeList($value, 12, true);
        } catch (\InvalidArgumentException $err) {
            throw new WriteException('invalid_levels', $err->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function checkinPayload(array $args): array
    {
        $mood = trim((string) ($args['mood'] ?? 'Spokój'));
        $moodMod = $this->checkinMoodMod($mood);
        $energy = (int) ($args['energy'] ?? 3);
        if ($energy < 1 || $energy > 5) {
            throw new WriteException('invalid_energy', 'energy musi być w zakresie 1-5.');
        }
        $note = $this->text($args['note'] ?? '', 1000);
        $score = (int) round(max(0, min(100, 50 + ($energy - 3) * 10 + $moodMod)));

        return ['mood' => $mood, 'energy' => $energy, 'note' => $note, 'score' => $score];
    }

    private function checkinMoodMod(string $mood): int
    {
        $map = [
            'Spokój' => 10,
            'Skupienie' => 7,
            'Pewność' => 5,
            'Nuda' => -3,
            'Zmęczenie' => -8,
            'Strach' => -10,
            'FOMO' => -12,
            'Złość' => -14,
            'Chciwość' => -6,
        ];
        if (!isset($map[$mood])) {
            throw new WriteException('invalid_mood', 'Nastrój musi być jednym z: ' . implode(', ', self::EMOTIONS) . '.');
        }

        return $map[$mood];
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function flowPayload(array $args, ?array $before, array $state): array
    {
        $payload = [];
        $payload['date'] = array_key_exists('date', $args)
            ? $this->dateKey($args['date'])
            : (string) ($before['date'] ?? $this->dateKey(null));
        $account = array_key_exists('account', $args)
            ? $this->accountName($state, $args['account'], false)
            : (string) ($before['account'] ?? $this->accountName($state, null, false));
        if ($account === '') {
            throw new WriteException('account_missing', 'Najpierw dodaj konto tradingowe.');
        }
        $payload['account'] = $account;
        $type = trim((string) ($args['type'] ?? ($before['type'] ?? 'Wpłata')));
        if (!in_array($type, self::FLOW_TYPES, true)) {
            throw new WriteException('invalid_flow_type', 'type musi być Wpłata albo Wypłata.');
        }
        $payload['type'] = $type;
        $amount = array_key_exists('amount', $args)
            ? $this->positive($args['amount'], 'amount')
            : (float) ($before['amount'] ?? 0);
        if ($amount <= 0) {
            throw new WriteException('invalid_amount', 'amount musi być liczbą większą od zera.');
        }
        $payload['amount'] = Numbers::round($amount);
        $currency = strtoupper(trim((string) ($args['currency'] ?? ($before['currency'] ?? 'USDT'))));
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new WriteException('invalid_currency', 'Waluta musi być jedną z: ' . implode(', ', self::CURRENCIES) . '.');
        }
        $payload['currency'] = $currency;
        $payload['fiat'] = array_key_exists('fiat', $args)
            ? Numbers::round($this->amount($args['fiat'], 'fiat'))
            : Numbers::round((float) ($before['fiat'] ?? 0));
        $payload['fx'] = array_key_exists('fx', $args)
            ? Numbers::round($this->number($args['fx'], 'fx'), 4)
            : Numbers::round((float) ($before['fx'] ?? Numbers::fxToUsdt($currency)), 4);
        $payload['op'] = $this->text($args['op'] ?? ($before['op'] ?? ''), 200);
        $payload['tax'] = $this->text($args['tax'] ?? ($before['tax'] ?? ($type === 'Wpłata' ? 'cost' : 'revenue')), 20);

        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $before
     * @return array<string,mixed>
     */
    private function propPayload(array $args, ?array $before): array
    {
        $payload = [];
        foreach (self::PROP_FIELDS as $field) {
            if (array_key_exists($field, $args)) {
                $payload[$field] = $args[$field];
            } elseif ($before !== null && array_key_exists($field, $before)) {
                $payload[$field] = $before[$field];
            }
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new WriteException('invalid_prop_name', 'Nazwa challenge musi mieć 1-120 znaków.');
        }
        $payload['name'] = $name;

        return $payload;
    }

    /**
     * Reuses generated IDs stored in proposal targets, so preview and approval
     * apply the same record. Existing IDs are accepted only while replaying the
     * same idempotent proposal.
     *
     * @param list<array> $records
     */
    private function preparedId(
        ?string $target,
        string $resource,
        string $prefix,
        array $records,
        bool $allowExisting,
        string $conflictCode,
        string $conflictMessage
    ): ?string {
        if ($target === null) {
            return null;
        }
        $pattern = '/^' . preg_quote($resource, '/') . ':(' . preg_quote($prefix, '/') . '(?:[a-f0-9]{8}|[a-f0-9]{12}))$/';
        if (preg_match($pattern, $target, $match) !== 1) {
            throw new WriteException('invalid_proposal_target', 'Cel propozycji nie odpowiada typowi rekordu.');
        }
        $id = $match[1];
        foreach ($records as $record) {
            if (is_array($record) && (string) ($record['id'] ?? '') === $id) {
                if (!$allowExisting) {
                    throw new WriteException($conflictCode, $conflictMessage);
                }
                break;
            }
        }

        return $id;
    }

    private function dateKey(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return Dates::todayKey();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            if (!Dates::isValidKey($text)) {
                throw new WriteException('invalid_date', 'Data musi być poprawną datą YYYY-MM-DD.');
            }

            return $text;
        }
        $parsed = Dates::dateKey($text);
        if ($parsed === null) {
            throw new WriteException('invalid_date', 'Nieprawidłowa data: ' . $text . '.');
        }

        return $parsed;
    }

    private function tradeDate(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            return gmdate('Y-m-d\TH:i:s.000\Z');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            if (!Dates::isValidKey($text)) {
                throw new WriteException('invalid_date', 'Data musi być poprawną datą YYYY-MM-DD.');
            }

            return $text . 'T12:00:00.000Z';
        }
        $date = Dates::parseUtc($text);
        if ($date === null) {
            throw new WriteException('invalid_date', 'Nieprawidłowa data transakcji.');
        }

        return $date->format('Y-m-d\TH:i:s.000\Z');
    }

    private function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($text, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function numText(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    private function number(mixed $value, string $field): float
    {
        $number = TradeRules::number($value);
        if ($number === null) {
            throw new WriteException('bad_number', $field . ' musi być skończoną liczbą.');
        }

        return $number;
    }

    private function signedNumber(mixed $value, string $field): float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || (is_string($value) && trim($value) === '')
            || !is_numeric($value)
            || !is_finite((float) $value)) {
            throw new WriteException('bad_number', $field . ' musi być skończoną liczbą.');
        }

        return (float) $value;
    }

    private function positive(mixed $value, string $field, bool $allowZero = false): float
    {
        $number = $this->number($value, $field);
        if ($allowZero ? $number < 0 : $number <= 0) {
            throw new WriteException('bad_number', $field . ' musi być liczbą ' . ($allowZero ? 'nieujemną' : 'dodatnią') . '.');
        }

        return $number;
    }

    private function optionalNonNegativeMetric(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new WriteException('bad_number', $field . ' musi być nieujemną liczbą albo null.');
        }

        return Numbers::round($this->positive($value, $field, true));
    }

    private function optionalPositive(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '' || $value === 'brak' || $value === '—' || $value === '-') {
            return null;
        }

        return $this->positive($value, $field);
    }

    /**
     * Liczba opcjonalna, dopuszczająca zero (np. opłata 0).
     */
    private function optionalAmount(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '' || $value === 'brak' || $value === '—') {
            return null;
        }

        return $this->amount($value, $field);
    }

    private function amount(mixed $value, string $field): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return $this->positive($value, $field, true);
    }

    private function text(mixed $value, int $limit): string
    {
        return mb_substr(trim((string) ($value ?? '')), 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $field, int $max, int $length): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            throw new WriteException('invalid_list', $field . ' musi być tablicą tekstów.');
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new WriteException('invalid_list', $field . ' musi być tablicą tekstów.');
            }
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $out[] = mb_substr($text, 0, $length);
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function changesArray(array $args, string $key): array
    {
        $changes = $args[$key] ?? null;
        if (!is_array($changes) || $changes === []) {
            throw new WriteException('bad_request', $key . ' musi być niepustym obiektem pól do zmiany.');
        }
        $out = [];
        foreach ($changes as $field => $value) {
            $out[(string) $field] = $value;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     */
    private function requiredString(array $args, string $key, int $limit): string
    {
        $value = trim((string) ($args[$key] ?? ''));
        if ($value === '') {
            throw new WriteException('bad_request', $key . ' jest wymagane.');
        }
        if (mb_strlen($value) > $limit) {
            throw new WriteException('bad_request', $key . ' jest za długie (limit ' . $limit . ').');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function accountName(array $state, mixed $value, bool $allowAll = true): string
    {
        $name = trim((string) ($value ?? ''));
        $accounts = array_values(array_filter(
            (array) ($state['accounts'] ?? []),
            static fn (mixed $row): bool => is_array($row) && trim((string) ($row['name'] ?? '')) !== ''
        ));
        if ($name === '') {
            foreach ($accounts as $account) {
                if (!empty($account['primary'])) {
                    return (string) $account['name'];
                }
            }

            return $accounts === [] ? '' : (string) $accounts[0]['name'];
        }
        foreach ($accounts as $account) {
            if (mb_strtolower((string) $account['name']) === mb_strtolower($name)) {
                return (string) $account['name'];
            }
        }
        if ($allowAll && in_array(mb_strtolower($name), ['wszystkie', 'all', 'wszystko'], true)) {
            return 'Wszystkie';
        }

        throw new WriteException('unknown_account', 'Konto "' . $name . '" nie istnieje. Użyj get_accounts.');
    }

    /**
     * @param array<string,mixed> $state
     * @return list<string>
     */
    private function setupNames(array $state): array
    {
        $names = ['Bez setupu'];
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            $name = trim((string) ($setup['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function planMatches(array $state, array $trade): bool
    {
        $plan = (new DayPlanService())->planForTrade($state, $trade);
        if ($plan === null) {
            return false;
        }
        $bias = strtolower(trim((string) ($plan['bias'] ?? '')));
        $biasMatch = in_array($bias, ['', 'none', 'neutral'], true)
            || $bias === ((string) ($trade['dir'] ?? 'l') === 's' ? 'short' : 'long');

        return $biasMatch && (new DayPlanService())->marketMatchesPlan($trade, $plan);
    }

    /**
     * Legacy records without a stop loss stored unknown risk as 0. Normalize
     * that placeholder to null before any operation so the before/after state
     * stays consistent: a note-only edit must not look like a risk mutation
     * (which would trigger whole-state validation of an open no-SL trade).
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function normalizeLegacyUnknownRisk(array $state): array
    {
        if (!is_array($state['trades'] ?? null)) {
            return $state;
        }
        foreach ($state['trades'] as $index => $trade) {
            if (!is_array($trade)
                || !array_key_exists('risk', $trade)
                || !$this->isLegacyUnknownRisk($trade)
            ) {
                continue;
            }
            $trade['risk'] = null;
            if (array_key_exists('riskPct', $trade)) {
                $trade['riskPct'] = null;
            }
            $state['trades'][$index] = $trade;
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $trade
     */
    private function isLegacyUnknownRisk(array $trade): bool
    {
        $slText = trim((string) ($trade['sl'] ?? ''));
        if (!in_array($slText, ['', 'brak', '—', '-'], true)) {
            return false;
        }
        $risk = $trade['risk'];
        if ($risk === null || $risk === '') {
            return false;
        }

        return is_numeric($risk) && (float) $risk === 0.0;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $trade
     * @return array{risk:?float,riskRaw:?float,riskPct:?float}
     */
    private function tradeRisk(array $state, array $trade): array
    {
        $entry = TradeRules::number($trade['entry'] ?? null);
        $size = TradeRules::number($trade['size'] ?? null);
        if ($entry === null || $entry <= 0 || $size === null || $size <= 0) {
            throw new WriteException('bad_number', 'entry i size muszą być skończonymi liczbami dodatnimi.');
        }
        $slText = (string) ($trade['sl'] ?? 'brak');
        $sl = $slText !== '' && $slText !== 'brak' && $slText !== '—' && $slText !== '-'
            ? TradeRules::number($slText)
            : null;
        if ($slText !== '' && $slText !== 'brak' && $slText !== '—' && $slText !== '-' && ($sl === null || $sl <= 0)) {
            throw new WriteException('bad_number', 'sl musi być pusty albo skończoną liczbą dodatnią.');
        }
        $risk = $sl === null ? null : abs($entry - $sl) * $size;
        if ($risk !== null && (!is_finite($risk) || $risk <= 0)) {
            throw new WriteException('bad_number', 'Ryzyko transakcji musi być skończoną liczbą dodatnią.');
        }
        $capital = 0.0;
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (!is_array($account) || ($account['include'] ?? true) === false) {
                continue;
            }
            $capital += Numbers::pnum($account['balance'] ?? 0) * Numbers::fxToUsdt((string) ($account['currency'] ?? 'USDT'));
        }

        $riskPct = $risk !== null && $capital > 0 ? $risk / $capital * 100 : null;
        if ($riskPct !== null && !is_finite($riskPct)) {
            throw new WriteException('bad_number', 'Procent ryzyka musi być skończoną liczbą.');
        }

        return [
            'risk' => $risk === null ? null : Numbers::round($risk, 2),
            'riskRaw' => $risk,
            'riskPct' => $riskPct === null ? null : Numbers::round($riskPct, 2),
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function mentorForGrade(string $grade, array $errors, int $checklist, int $checkTotal): string
    {
        $error = '';
        foreach ($errors as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $error = trim((string) $item);
                break;
            }
        }

        if ($checkTotal > 0 && $checklist < $checkTotal) {
            return 'Checklista niepełna (' . $checklist . '/' . $checkTotal . '). Uzupełnij brakujące punkty przed kolejnym wejściem.';
        }

        return match ($grade) {
            'A' => 'Wejście zgodne z planem i checklistą. Powtórz ten proces.',
            'B' => 'Checklista kompletna (' . $checklist . '/' . $checkTotal . '), ale ocena B. Sprawdź jakość wykonania.',
            default => $error !== ''
                ? 'Checklista kompletna (' . $checklist . '/' . $checkTotal . '). Największy błąd: ' . $error . '. Popraw go w kolejnym wejściu.'
                : 'Checklista kompletna (' . $checklist . '/' . $checkTotal . '), ale ocena C. Wróć do planu przed kolejną transakcją.',
        };
    }

    private function sessionNow(): string
    {
        $hour = (int) (new \DateTimeImmutable('now', Dates::tz()))->format('G');
        if ($hour < 8) {
            return 'Azja';
        }
        if ($hour < 16) {
            return 'Londyn';
        }

        return 'Nowy Jork';
    }

    /**
     * @param array<string,mixed> $trade
     */
    private function isExchangeTrade(int $userId, array $trade): bool
    {
        $id = (string) ($trade['id'] ?? '');
        if ($id !== '') {
            try {
                $row = $this->db->queryOne(
                    'SELECT 1 AS ok FROM exchange_imports WHERE user_id = ? AND trade_id = ? LIMIT 1',
                    [$userId, $id]
                );
                if ($row !== null) {
                    return true;
                }
            } catch (\Throwable) {
                // Brak tabeli importów nie może blokować zapisu.
            }
        }
        $source = mb_strtolower(trim((string) ($trade['source'] ?? '')));

        return $source !== '' && !in_array($source, ['manual', 'panel', 'ręcznie', 'recznie'], true);
    }

    /**
     * @param array<string,mixed> $detail
     */
    private function audit(int $userId, string $action, array $detail): void
    {
        try {
            $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->db->execute(
                'INSERT INTO mcp_audit (proposal_id, user_id, actor, action, detail_json) VALUES (NULL, ?, ?, ?, ?)',
                [$userId, 'agent', mb_substr('mcp.write.' . $action, 0, 80), $encoded === false ? null : $encoded]
            );
        } catch (\Throwable $err) {
            error_log('[mcp] audit ' . $err->getMessage());
        }
    }

    private function requestHash(string $op, array $args, ?int $expectedRevision): string
    {
        $payload = [
            'op' => $op,
            'args' => $args,
            'expectedRevision' => $expectedRevision,
        ];
        $json = json_encode(
            $this->canonical($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $json);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }
}
