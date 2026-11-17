<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Proposals;

use AlphaVault\Db;
use AlphaVault\Mcp\Analytics\DayPlanService;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\DayPlanLevels;
use AlphaVault\Mcp\Support\DayPlanRecords;
use AlphaVault\Mcp\Support\Numbers;
use AlphaVault\Mcp\Support\PropChallengeService;

/**
 * Buduje i waliduje propozycje zmian. Nie zapisuje stanu aplikacji.
 */
final class ProposalBuilder
{
    private const MOODS = ['Spokój', 'Pewność', 'Skupienie', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Chciwość', 'Złość'];
    private const EMOTIONS = ['Spokój', 'Skupienie', 'Pewność', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Złość', 'Chciwość'];

    public function __construct(
        private readonly Db $db,
        private readonly ProposalService $proposals
    ) {
    }

    public function createTrade(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'pair', 'dir', 'entry', 'sl', 'tp', 'size', 'setup', 'emotion', 'grade',
            'checklist', 'checkTotal', 'errors', 'note', 'date', 'account', 'idempotencyKey',
        ]);
        $state = $this->state($userId);

        $pair = strtoupper(trim((string) ($args['pair'] ?? '')));
        if (preg_match('/^[A-Z0-9._\/-]{2,20}$/', $pair) !== 1) {
            throw new ProposalException('bad_request', 'Para musi mieć 2–20 znaków: A–Z, 0–9, . _ / -.', 400);
        }
        $dir = (string) ($args['dir'] ?? '');
        if (!in_array($dir, ['long', 'short', 'l', 's'], true)) {
            throw new ProposalException('bad_request', 'Kierunek musi być long albo short.', 400);
        }
        $direction = in_array($dir, ['short', 's'], true) ? 's' : 'l';

        $entry = $this->positiveNumber($args['entry'] ?? null, 'entry');
        $size = $this->positiveNumber($args['size'] ?? null, 'size');
        if (!array_key_exists('sl', $args) || $args['sl'] === null || $args['sl'] === '') {
            throw new ProposalException('stop_loss_required', 'Stop loss jest wymagany przy zapisie transakcji.', 400);
        }
        $sl = null;
        $tp = null;
        if (array_key_exists('sl', $args) && $args['sl'] !== null && $args['sl'] !== '') {
            $sl = $this->positiveNumber($args['sl'], 'sl');
            if (($direction === 'l' && $sl >= $entry) || ($direction === 's' && $sl <= $entry)) {
                throw new ProposalException('bad_request', 'Stop loss jest po złej stronie ceny wejścia.', 400);
            }
        }
        if (array_key_exists('tp', $args) && $args['tp'] !== null && $args['tp'] !== '') {
            $tp = $this->positiveNumber($args['tp'], 'tp');
            if (($direction === 'l' && $tp <= $entry) || ($direction === 's' && $tp >= $entry)) {
                throw new ProposalException('bad_request', 'Take profit jest po złej stronie ceny wejścia.', 400);
            }
        }

        $setups = $this->setupNames($state);
        $setup = trim((string) ($args['setup'] ?? 'Bez setupu'));
        if ($setup === '') {
            $setup = 'Bez setupu';
        }
        if ($setup !== 'Bez setupu' && !in_array($setup, $setups, true)) {
            throw new ProposalException('bad_request', 'Setup nie istnieje w Playbooku. Użyj list_setups.', 400);
        }

        $emotion = trim((string) ($args['emotion'] ?? 'Spokój'));
        if (!in_array($emotion, self::EMOTIONS, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa emocja.', 400);
        }

        $checkTotal = array_key_exists('checkTotal', $args) ? (int) $args['checkTotal'] : $this->defaultCheckTotal($state);
        if ($checkTotal < 1 || $checkTotal > 20) {
            throw new ProposalException('bad_request', 'checkTotal musi być w zakresie 1–20.', 400);
        }
        $checklist = array_key_exists('checklist', $args) ? (int) $args['checklist'] : 0;
        if ($checklist < 0 || $checklist > $checkTotal) {
            throw new ProposalException('bad_request', 'checklist musi być w zakresie 0–checkTotal.', 400);
        }
        $grade = strtoupper(trim((string) ($args['grade'] ?? '')));
        if ($grade !== '' && !in_array($grade, ['A', 'B', 'C'], true)) {
            throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
        }
        if ($grade === '') {
            $grade = $checklist >= $checkTotal ? 'A' : ($checklist >= (int) ceil($checkTotal * 0.7) ? 'B' : 'C');
        }

        $errors = $this->errors($args['errors'] ?? []);
        $note = mb_substr(trim((string) ($args['note'] ?? '')), 0, 2000);
        $date = $this->tradeDate((string) ($args['date'] ?? ''));
        $account = $this->account($state, $args['account'] ?? null);
        $plan = $this->planForToday($state, $setup, $pair, $date);

        $decimals = $this->decimals($entry);
        $trade = [
            'id' => 'manual_' . $this->shortId(),
            'date' => $date,
            'exit' => 'otwarta',
            'result' => null,
            'r' => null,
            'mae' => null,
            'mfe' => null,
            'durationMin' => 0,
            'fee' => 0,
            'source' => 'Ręcznie',
            'account' => $account,
            'session' => $this->session(),
            'screens' => new \stdClass(),
            'pair' => $pair,
            'dir' => $direction,
            'entry' => $this->formatNumber($entry, $decimals),
            'sl' => $sl !== null ? $this->formatNumber($sl, $decimals) : 'brak',
            'tp' => $tp !== null ? $this->formatNumber($tp, $decimals) : '—',
            'size' => $this->formatNumber($size, $size < 1 ? 4 : 2),
            'grade' => $grade,
            'checklist' => $checklist,
            'checkTotal' => $checkTotal,
            'errors' => $errors,
            'emotion' => $emotion,
            'setup' => $setup,
            'note' => $note,
            'plan' => $plan,
            'mentor' => $this->mentorForGrade($grade, count($errors)),
        ];

        return $this->proposals->create($userId, $clientName, 'manual_trade', 'trade:new', null, $trade, $key, $this->fingerprint($args));
    }

    public function closeTrade(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['tradeId', 'exitPrice', 'mfe', 'idempotencyKey']);
        $tradeId = trim((string) ($args['tradeId'] ?? ''));
        if ($tradeId === '') {
            throw new ProposalException('bad_request', 'Pole tradeId jest wymagane.', 400);
        }
        $exit = $this->positiveNumber($args['exitPrice'] ?? null, 'exitPrice');
        $state = $this->state($userId);
        $trade = $this->findTrade($state, $tradeId);
        if ($trade === null) {
            throw new ProposalException('not_found', 'Transakcja nie istnieje.', 404);
        }
        if ($this->proposals->isExchangeTrade($userId, $trade)) {
            throw new ProposalException('exchange_data_readonly', 'Transakcji importowanej z giełdy nie można zamknąć przez MCP.', 403);
        }
        if (($trade['result'] ?? null) !== null) {
            throw new ProposalException('already_closed', 'Transakcja jest już zamknięta.', 409);
        }

        $entry = (float) ($trade['entry'] ?? 0);
        $size = (float) ($trade['size'] ?? 0);
        $direction = (string) ($trade['dir'] ?? 'l') === 's' ? -1 : 1;
        $result = round(($exit - $entry) * $size * $direction, 2);
        $slRaw = (string) ($trade['sl'] ?? 'brak');
        $risk = ($slRaw !== '' && $slRaw !== 'brak')
            ? abs($entry - (float) $slRaw) * $size
            : abs($entry * 0.01 * $size);
        $r = $risk > 0 ? round($result / $risk, 2) : 0;
        $openedAt = strtotime((string) ($trade['date'] ?? '')) ?: time();
        $after = [
            'id' => $tradeId,
            'exit' => $this->formatNumber($exit, $this->decimals($entry)),
            'result' => $result,
            'r' => $r,
            'mfe' => array_key_exists('mfe', $args)
                ? $this->nonNegativeMetric($args['mfe'], 'mfe')
                : $this->storedMetric($trade['mfe'] ?? null),
            'durationMin' => max(1, (int) round((time() - $openedAt) / 60)),
            'mentor' => $result > 0
                ? 'Pozycja zamknięta z zyskiem. Sprawdź, czy wyszedłeś zgodnie z planem, a nie z emocji.'
                : ($result < 0
                    ? 'Pozycja zamknięta na minusie. Sprawdź, czy SL był ustawiony przed wejściem.'
                    : 'Pozycja zamknięta na zero (break-even). Oceń, czy wyjście było zgodne z planem.'),
        ];
        $before = [
            'exit' => $trade['exit'] ?? null,
            'result' => null,
            'r' => null,
            'mfe' => $trade['mfe'] ?? null,
            'durationMin' => $trade['durationMin'] ?? null,
        ];

        return $this->proposals->create($userId, $clientName, 'close_trade', 'trade:' . $tradeId, $before, $after, $key, $this->fingerprint($args));
    }

    public function tradeReview(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['tradeId', 'note', 'grade', 'setup', 'emotion', 'checklist', 'errors', 'idempotencyKey']);
        $tradeId = trim((string) ($args['tradeId'] ?? ''));
        if ($tradeId === '') {
            throw new ProposalException('bad_request', 'Pole tradeId jest wymagane.', 400);
        }
        $state = $this->state($userId);
        $trade = $this->findTrade($state, $tradeId);
        if ($trade === null) {
            throw new ProposalException('not_found', 'Transakcja nie istnieje.', 404);
        }

        $before = is_array($state['tradeReviews'][$tradeId] ?? null) ? $state['tradeReviews'][$tradeId] : null;
        $after = $before ?? ['tradeId' => $tradeId, 'exchangeOwned' => $this->proposals->isExchangeTrade($userId, $trade)];
        if (array_key_exists('note', $args)) {
            $after['note'] = mb_substr(trim((string) $args['note']), 0, 2000);
        }
        if (array_key_exists('grade', $args)) {
            $grade = strtoupper(trim((string) $args['grade']));
            if (!in_array($grade, ['A', 'B', 'C'], true)) {
                throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
            }
            $after['grade'] = $grade;
        }
        if (array_key_exists('setup', $args)) {
            $setup = trim((string) $args['setup']);
            if ($setup !== 'Bez setupu' && !in_array($setup, $this->setupNames($state), true)) {
                throw new ProposalException('bad_request', 'Setup nie istnieje w Playbooku.', 400);
            }
            $after['setup'] = $setup;
        }
        if (array_key_exists('emotion', $args)) {
            $emotion = trim((string) $args['emotion']);
            if (!in_array($emotion, self::EMOTIONS, true)) {
                throw new ProposalException('bad_request', 'Nieprawidłowa emocja.', 400);
            }
            $after['emotion'] = $emotion;
        }
        if (array_key_exists('checklist', $args)) {
            $total = (int) ($trade['checkTotal'] ?? 20);
            $value = (int) $args['checklist'];
            if ($value < 0 || $value > max(1, $total)) {
                throw new ProposalException('bad_request', 'checklist poza zakresem transakcji.', 400);
            }
            $after['checklist'] = $value;
        }
        if (array_key_exists('errors', $args)) {
            $after['errors'] = $this->errors($args['errors']);
        }
        if (count($after) <= 2) {
            throw new ProposalException('bad_request', 'Brak pól recenzji do zapisania.', 400);
        }
        $after['tradeId'] = $tradeId;
        $after['exchangeOwned'] = $this->proposals->isExchangeTrade($userId, $trade);

        return $this->proposals->create($userId, $clientName, 'trade_review', 'trade:' . $tradeId, $before, $after, $key, $this->fingerprint($args));
    }

    public function saveDayPlan(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'date', 'planId', 'market', 'markets', 'bias', 'why', 'levels', 'ifMain', 'thenMain', 'ifAlt', 'thenAlt',
            'scenarioA', 'scenarioB', 'riskPercent', 'maxTrades', 'until', 'notDo', 'rulesNotToDo', 'idempotencyKey',
        ]);
        $date = trim((string) ($args['date'] ?? Dates::todayKey()));
        if (!Dates::isValidKey($date)) {
            throw new ProposalException('bad_request', 'Data musi być w formacie YYYY-MM-DD.', 400);
        }
        $today = Dates::todayKey();
        $yesterday = (new \DateTimeImmutable('yesterday', Dates::tz()))->format('Y-m-d');
        if (!in_array($date, [$today, $yesterday], true)) {
            throw new ProposalException('bad_request', 'Plan można zaproponować tylko na dziś lub wczoraj.', 400);
        }

        $state = $this->state($userId);
        $records = DayPlanRecords::forDate($state, $date);
        $requestedId = trim((string) ($args['planId'] ?? ''));
        if ($requestedId !== '') {
            $records = array_values(array_filter($records, static fn (array $record): bool => $record['id'] === $requestedId));
            if ($records === []) {
                throw new ProposalException('plan_missing', 'planId nie istnieje dla daty ' . $date . '.', 404);
            }
            if (count($records) > 1) {
                throw new ProposalException('plan_id_ambiguous', 'planId nie identyfikuje jednoznacznie planu na ' . $date . '.', 409);
            }
            $before = $records[0]['plan'];
            $planId = $records[0]['id'];
        } elseif (count($records) > 1) {
            throw new ProposalException('plan_id_required', 'Na ' . $date . ' jest wiele planów. Podaj planId, aby wskazać właściwy rekord.', 409);
        } else {
            $before = null;
            $planId = DayPlanRecords::uniqueId($state);
        }
        $after = $before ?? ['id' => $planId, 'date' => $date, 'approvedAt' => null];
        $after['id'] = $planId;
        $after['date'] = $date;

        if (array_key_exists('market', $args)) {
            $market = trim((string) $args['market']);
            if ($market === '' || mb_strlen($market) > 20) {
                throw new ProposalException('bad_request', 'market musi mieć 1-20 znaków.', 400);
            }
            $after['market'] = $market;
        }
        if (array_key_exists('markets', $args)) {
            $markets = $this->stringList($args['markets'], 'markets', 3, 20, 1);
            $markets = array_values(array_unique(array_map(static fn (string $market): string => strtoupper($market), $markets)));
            $after['markets'] = $markets;
            $after['market'] = $markets[0];
        }
        foreach (['scenarioA' => ['ifMain', 'thenMain'], 'scenarioB' => ['ifAlt', 'thenAlt']] as $field => $target) {
            if (!array_key_exists($field, $args)) {
                continue;
            }
            $scenario = $args[$field];
            if (!is_array($scenario)) {
                throw new ProposalException('bad_request', $field . ' musi być obiektem z condition i action.', 400);
            }
            foreach ($scenario as $scenarioKey => $_) {
                if (!in_array($scenarioKey, ['condition', 'action'], true)) {
                    throw new ProposalException('bad_request', 'Pole ' . $field . ' może mieć tylko condition i action.', 400);
                }
            }
            $condition = mb_substr(trim((string) ($scenario['condition'] ?? '')), 0, 2000);
            $action = mb_substr(trim((string) ($scenario['action'] ?? '')), 0, 2000);
            if ($condition !== '') {
                $after[$target[0]] = $condition;
            }
            if ($action !== '') {
                $after[$target[1]] = $action;
            }
        }
        if (array_key_exists('rulesNotToDo', $args)) {
            $rules = $this->stringList($args['rulesNotToDo'], 'rulesNotToDo', 10, 300);
            $after['notDo'] = implode("\n", $rules);
        }
        if (array_key_exists('bias', $args)) {
            $bias = (string) $args['bias'];
            if (!in_array($bias, ['long', 'short', 'neutral', 'none'], true)) {
                throw new ProposalException('bad_request', 'bias musi być long, short, neutral albo none.', 400);
            }
            $after['bias'] = $bias;
        }
        foreach (['why', 'ifMain', 'thenMain', 'ifAlt', 'thenAlt', 'notDo'] as $field) {
            if (array_key_exists($field, $args)) {
                $after[$field] = mb_substr(trim((string) $args[$field]), 0, 2000);
            }
        }
        if (array_key_exists('levels', $args)) {
            $after['levels'] = $this->levels($args['levels']);
        }
        if (array_key_exists('riskPercent', $args)) {
            $risk = (float) $args['riskPercent'];
            if (!is_finite($risk) || $risk < 0.1 || $risk > 100) {
                throw new ProposalException('bad_request', 'riskPercent musi być w zakresie 0.1–100.', 400);
            }
            $after['risk'] = rtrim(rtrim(number_format($risk, 2, '.', ''), '0'), '.') . '%';
        }
        if (array_key_exists('maxTrades', $args)) {
            $maxTrades = (int) $args['maxTrades'];
            if ($maxTrades < 1 || $maxTrades > 50) {
                throw new ProposalException('bad_request', 'maxTrades musi być w zakresie 1–50.', 400);
            }
            $after['maxTrades'] = (string) $maxTrades;
        }
        if (array_key_exists('until', $args)) {
            $until = trim((string) $args['until']);
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $until) !== 1) {
                throw new ProposalException('bad_request', 'until musi mieć format HH:MM.', 400);
            }
            $after['until'] = $until;
        }
        // approvedAt nigdy nie jest ustawiane przez agenta.
        $after['approvedAt'] = $before['approvedAt'] ?? null;

        return $this->proposals->create($userId, $clientName, 'day_plan', 'plan:' . $date . '#' . $planId, $before, $after, $key, $this->fingerprint($args));
    }

    public function saveCheckin(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['mood', 'energy', 'note', 'date', 'idempotencyKey']);
        $mood = trim((string) ($args['mood'] ?? ''));
        if (!in_array($mood, self::MOODS, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowy nastrój.', 400);
        }
        $energy = (int) ($args['energy'] ?? 0);
        if ($energy < 1 || $energy > 5) {
            throw new ProposalException('bad_request', 'Energia musi być w zakresie 1-5.', 400);
        }
        $date = trim((string) ($args['date'] ?? Dates::todayKey()));
        if (!Dates::isValidKey($date)) {
            throw new ProposalException('bad_request', 'Data check-inu musi być w formacie YYYY-MM-DD.', 400);
        }
        $today = Dates::todayKey();
        $yesterday = (new \DateTimeImmutable('yesterday', Dates::tz()))->format('Y-m-d');
        if (!in_array($date, [$today, $yesterday], true)) {
            throw new ProposalException('bad_request', 'Check-in można zaproponować tylko na dziś lub wczoraj.', 400);
        }
        $state = $this->state($userId);
        $before = null;
        foreach ((array) ($state['checkins'] ?? []) as $checkin) {
            if (is_array($checkin) && (string) ($checkin['date'] ?? '') === $date) {
                $before = $checkin;
                break;
            }
        }
        $after = [
            'date' => $date,
            'mood' => $mood,
            'energy' => $energy,
            'note' => mb_substr(trim((string) ($args['note'] ?? '')), 0, 500),
            'score' => $this->readinessScore($mood, $energy),
        ];

        return $this->proposals->create($userId, $clientName, 'checkin', 'checkin:' . $date, $before, $after, $key, $this->fingerprint($args));
    }

    public function saveSetup(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'id', 'name', 'tf', 'grade', 'rules', 'bad', 'seed',
            'description', 'confirmation', 'invalidation', 'stopLoss', 'minRR', 'exitRules', 'examples',
            'idempotencyKey',
        ]);
        $name = trim((string) ($args['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw new ProposalException('bad_request', 'Nazwa setupu musi mieć 2–80 znaków.', 400);
        }
        $rules = $this->rules($args['rules'] ?? []);
        $tf = trim((string) ($args['tf'] ?? '—'));
        if ($tf === '') {
            $tf = '—';
        }
        if (mb_strlen($tf) > 40) {
            throw new ProposalException('bad_request', 'Interwał może mieć maks. 40 znaków.', 400);
        }
        $grade = strtoupper(trim((string) ($args['grade'] ?? 'B')));
        if (!in_array($grade, ['A', 'B', 'C'], true)) {
            throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
        }
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['setups'] ?? []) as $setup) {
                if (is_array($setup) && (string) ($setup['id'] ?? '') === $id) {
                    $before = $setup;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Setup nie istnieje.', 404);
            }
        } else {
            $id = 'pb_' . $this->shortId();
        }
        $seed = array_key_exists('seed', $args) ? (int) $args['seed'] : random_int(1, 200);
        if ($seed < 1 || $seed > 9999) {
            throw new ProposalException('bad_request', 'seed musi być w zakresie 1–9999.', 400);
        }
        $after = [
            'id' => $id,
            'name' => $name,
            'tf' => $tf,
            'grade' => $grade,
            'rules' => $rules,
            'bad' => (bool) ($args['bad'] ?? false),
            'seed' => $seed,
            'createdAt' => (string) ($before['createdAt'] ?? gmdate('c')),
            'updatedAt' => gmdate('c'),
        ];
        foreach (['description' => 1000, 'confirmation' => 500, 'invalidation' => 500, 'stopLoss' => 200] as $field => $maxLength) {
            if (array_key_exists($field, $args)) {
                $after[$field] = mb_substr(trim((string) $args[$field]), 0, $maxLength);
            }
        }
        if (array_key_exists('minRR', $args) && $args['minRR'] !== null) {
            $minRr = (float) $args['minRR'];
            if ($minRr <= 0 || $minRr > 20) {
                throw new ProposalException('bad_request', 'minRR musi być w zakresie 0.1-20.', 400);
            }
            $after['minRR'] = Numbers::round($minRr, 2);
        }
        if (array_key_exists('exitRules', $args)) {
            $after['exitRules'] = $this->stringList($args['exitRules'], 'exitRules', 10, 200);
        }
        if (array_key_exists('examples', $args)) {
            $after['examples'] = $this->stringList($args['examples'], 'examples', 10, 300);
        }

        return $this->proposals->create($userId, $clientName, 'setup', $before === null ? 'setup:new' : 'setup:' . $id, $before, $after, $key, $this->fingerprint($args));
    }

    public function savePropChallenge(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'id', 'name', 'firm', 'templateId', 'phase', 'account', 'currency', 'accountSize',
            'startBalance', 'targetPct', 'dailyLossPct', 'maxLossPct', 'drawdownType', 'dailyBasis',
            'minDays', 'startDate', 'endDate', 'notes', 'idempotencyKey',
        ]);
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['prop']['challenges'] ?? []) as $challenge) {
                if (is_array($challenge) && (string) ($challenge['id'] ?? '') === $id) {
                    $before = $challenge;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Challenge nie istnieje.', 404);
            }
        }

        $templateId = trim((string) ($args['templateId'] ?? ($before['templateId'] ?? 'custom')));
        if (PropChallengeService::templateById($templateId) === null) {
            throw new ProposalException('bad_request', 'Nieznany templateId.', 400);
        }
        $name = trim((string) ($args['name'] ?? ($before['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ProposalException('bad_request', 'Nazwa challenge\'u musi mieć 1–100 znaków.', 400);
        }
        $account = trim((string) ($args['account'] ?? ($before['account'] ?? '')));
        if ($account !== '' && !in_array($account, $this->accountNames($state), true)) {
            throw new ProposalException('bad_request', 'Konto nie istnieje w ustawieniach AlphaVault.', 400);
        }
        $currency = strtoupper(trim((string) ($args['currency'] ?? ($before['currency'] ?? 'USD'))));
        if (!in_array($currency, PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa waluta.', 400);
        }
        $accountSize = (float) ($args['accountSize'] ?? ($before['accountSize'] ?? 0));
        $startBalance = (float) ($args['startBalance'] ?? ($before['startBalance'] ?? $accountSize));
        if (!is_finite($accountSize) || $accountSize <= 0 || !is_finite($startBalance) || $startBalance <= 0) {
            throw new ProposalException('bad_request', 'accountSize i startBalance muszą być większe od 0.', 400);
        }
        $targetPct = (float) ($args['targetPct'] ?? ($before['targetPct'] ?? 10));
        $dailyLossPct = (float) ($args['dailyLossPct'] ?? ($before['dailyLossPct'] ?? 5));
        $maxLossPct = (float) ($args['maxLossPct'] ?? ($before['maxLossPct'] ?? 10));
        if ($targetPct < 0 || $targetPct > 1000 || $dailyLossPct < 0 || $dailyLossPct > 100 || $maxLossPct < 0 || $maxLossPct > 100) {
            throw new ProposalException('bad_request', 'Limit procentowy poza zakresem.', 400);
        }
        $drawdownType = (string) ($args['drawdownType'] ?? ($before['drawdownType'] ?? 'static'));
        $dailyBasis = (string) ($args['dailyBasis'] ?? ($before['dailyBasis'] ?? 'initial'));
        if (!in_array($drawdownType, PropChallengeService::DRAWDOWN_TYPES, true) || !in_array($dailyBasis, PropChallengeService::DAILY_BASES, true)) {
            throw new ProposalException('bad_request', 'drawdownType lub dailyBasis jest nieprawidłowy.', 400);
        }
        $minDays = (int) ($args['minDays'] ?? ($before['minDays'] ?? 0));
        if ($minDays < 0 || $minDays > 60) {
            throw new ProposalException('bad_request', 'minDays musi być w zakresie 0–60.', 400);
        }
        $startDate = trim((string) ($args['startDate'] ?? ($before['startDate'] ?? Dates::todayKey())));
        $endDate = trim((string) ($args['endDate'] ?? ($before['endDate'] ?? '')));
        if (!Dates::isValidKey($startDate)) {
            throw new ProposalException('bad_request', 'startDate musi mieć format YYYY-MM-DD.', 400);
        }
        if ($endDate !== '' && !Dates::isValidKey($endDate)) {
            throw new ProposalException('bad_request', 'endDate musi mieć format YYYY-MM-DD.', 400);
        }
        if ($endDate !== '' && strcmp($endDate, $startDate) < 0) {
            throw new ProposalException('bad_request', 'endDate nie może być przed startDate.', 400);
        }
        $notes = mb_substr(trim((string) ($args['notes'] ?? ($before['notes'] ?? ''))), 0, 2000);

        $payload = array_merge($before ?? [], [
            'id' => $id !== '' ? $id : 'prop_' . $this->shortId(),
            'name' => $name,
            'firm' => trim((string) ($args['firm'] ?? ($before['firm'] ?? ''))),
            'templateId' => $templateId,
            'phase' => trim((string) ($args['phase'] ?? ($before['phase'] ?? 'Phase 1'))),
            'account' => $account,
            'currency' => $currency,
            'accountSize' => $accountSize,
            'startBalance' => $startBalance,
            'targetPct' => $targetPct,
            'dailyLossPct' => $dailyLossPct,
            'maxLossPct' => $maxLossPct,
            'drawdownType' => $drawdownType,
            'dailyBasis' => $dailyBasis,
            'minDays' => $minDays,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'notes' => $notes,
            'archived' => (bool) ($before['archived'] ?? false),
            'createdAt' => (string) ($before['createdAt'] ?? gmdate('c')),
        ]);
        $after = PropChallengeService::normalize($payload);
        if ($after['endDate'] === '' && $endDate !== '') {
            $after['endDate'] = $endDate;
        }

        return $this->proposals->create($userId, $clientName, 'prop_challenge', $before === null ? 'prop:new' : 'prop:' . $after['id'], $before, $after, $key, $this->fingerprint($args));
    }

    public function saveAccount(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['id', 'name', 'exchange', 'type', 'currency', 'include', 'primary', 'lev', 'goal', 'date', 'idempotencyKey']);
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['accounts'] ?? []) as $account) {
                if (is_array($account) && (string) ($account['id'] ?? '') === $id) {
                    $before = $account;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Konto nie istnieje.', 404);
            }
        }

        $name = trim((string) ($args['name'] ?? ($before['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw new ProposalException('bad_request', 'Nazwa konta musi mieć 2–80 znaków.', 400);
        }
        foreach ($this->accountNames($state) as $existingName) {
            if (strcasecmp($existingName, $name) === 0 && $existingName !== (string) ($before['name'] ?? '')) {
                throw new ProposalException('bad_request', 'Konto o tej nazwie już istnieje.', 400);
            }
        }
        $exchange = trim((string) ($args['exchange'] ?? ($before['exchange'] ?? '')));
        if (mb_strlen($exchange) > 80) {
            throw new ProposalException('bad_request', 'Broker lub giełda może mieć maks. 80 znaków.', 400);
        }
        $type = trim((string) ($args['type'] ?? ($before['type'] ?? 'Futures')));
        if (mb_strlen($type) > 40) {
            throw new ProposalException('bad_request', 'Typ konta może mieć maks. 40 znaków.', 400);
        }
        $currency = strtoupper(trim((string) ($args['currency'] ?? ($before['currency'] ?? 'USDT'))));
        if (!in_array($currency, PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa waluta konta.', 400);
        }
        $lev = trim((string) ($args['lev'] ?? ($before['lev'] ?? '')));
        if (mb_strlen($lev) > 20) {
            throw new ProposalException('bad_request', 'Dźwignia może mieć maks. 20 znaków.', 400);
        }
        $goal = trim((string) ($args['goal'] ?? ($before['goal'] ?? '')));
        if (mb_strlen($goal) > 200) {
            throw new ProposalException('bad_request', 'Cel konta może mieć maks. 200 znaków.', 400);
        }
        $date = trim((string) ($args['date'] ?? ($before['date'] ?? '')));
        if ($date === '') {
            $date = gmdate('Y-m-d');
        }
        if (mb_strlen($date) > 20) {
            throw new ProposalException('bad_request', 'Data konta może mieć maks. 20 znaków.', 400);
        }
        $include = array_key_exists('include', $args) ? (bool) $args['include'] : (bool) ($before['include'] ?? true);
        $primary = array_key_exists('primary', $args) ? (bool) $args['primary'] : (bool) ($before['primary'] ?? false);

        $after = [
            'id' => $before !== null ? $id : 'acc_' . $this->shortId(),
            'name' => $name,
            'exchange' => $exchange,
            'type' => $type,
            'currency' => $currency,
            // start i balance są przenoszone bez zmian; MCP ich nie ustawia.
            'start' => (float) ($before['start'] ?? 0),
            'date' => $date,
            'lev' => $lev,
            'goal' => $goal,
            'balance' => (float) ($before['balance'] ?? 0),
            'include' => $include,
            'primary' => $primary,
        ];

        return $this->proposals->create(
            $userId,
            $clientName,
            'account',
            $before === null ? 'account:new' : 'account:' . $after['id'],
            $before,
            $after,
            $key,
            $this->fingerprint($args)
        );
    }

    public function saveCapitalFlow(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['id', 'date', 'account', 'type', 'amount', 'currency', 'fiat', 'fx', 'note', 'idempotencyKey']);
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['flows'] ?? []) as $flow) {
                if (is_array($flow) && (string) ($flow['id'] ?? '') === $id) {
                    $before = $flow;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Wpis kapitałowy nie istnieje.', 404);
            }
        }

        $typeRaw = strtolower(trim((string) ($args['type'] ?? ($before['type'] ?? 'deposit'))));
        $type = match ($typeRaw) {
            'deposit', 'wpłata', 'wplata' => 'Wpłata',
            'withdrawal', 'withdraw', 'wypłata', 'wyplata' => 'Wypłata',
            default => '',
        };
        if ($type === '') {
            throw new ProposalException('bad_request', 'Typ wpisu musi być deposit albo withdrawal.', 400);
        }
        $date = trim((string) ($args['date'] ?? ($before['date'] ?? '')));
        if ($date === '') {
            $date = gmdate('Y-m-d');
        }
        if (!Dates::isValidKey($date)) {
            throw new ProposalException('bad_request', 'date musi mieć format YYYY-MM-DD.', 400);
        }
        $ts = strtotime($date . ' 12:00:00 UTC');
        if ($ts === false || $ts > time() + 86400 || $ts < time() - 5 * 365 * 86400) {
            throw new ProposalException('bad_request', 'Data wpisu musi mieścić się w ostatnich 5 latach i nie może być z przyszłości.', 400);
        }
        $account = trim((string) ($args['account'] ?? ($before['account'] ?? '')));
        if ($account === '' || !in_array($account, $this->accountNames($state), true)) {
            throw new ProposalException('bad_request', 'Konto nie istnieje w ustawieniach AlphaVault.', 400);
        }
        $amount = $this->positiveNumber($args['amount'] ?? ($before['amount'] ?? null), 'amount');
        $currency = strtoupper(trim((string) ($args['currency'] ?? ($before['currency'] ?? 'PLN'))));
        if (!in_array($currency, PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa waluta wpisu.', 400);
        }
        $fiat = array_key_exists('fiat', $args) ? (float) $args['fiat'] : (float) ($before['fiat'] ?? 0);
        if (!is_finite($fiat) || $fiat < 0) {
            throw new ProposalException('bad_request', 'fiat musi być liczbą większą lub równą 0.', 400);
        }
        $fx = array_key_exists('fx', $args) ? (float) $args['fx'] : (float) ($before['fx'] ?? 1);
        if (!is_finite($fx) || $fx <= 0 || $fx > 1000) {
            throw new ProposalException('bad_request', 'fx musi być w zakresie 0–1000.', 400);
        }
        $op = mb_substr(trim((string) ($args['note'] ?? ($before['op'] ?? ''))), 0, 200);

        $after = array_merge($before ?? [], [
            'id' => $before !== null ? $id : 'flow_' . $this->shortId(),
            'date' => $date,
            'account' => $account,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'fiat' => $fiat,
            'fx' => $fx,
            'op' => $op,
            'tax' => $type === 'Wpłata' ? 'cost' : 'income',
        ]);
        if ($op === '' && $before === null) {
            unset($after['op']);
        }

        return $this->proposals->create(
            $userId,
            $clientName,
            'capital_flow',
            $before === null ? 'flow:new' : 'flow:' . $after['id'],
            $before,
            $after,
            $key,
            $this->fingerprint($args)
        );
    }

    /**
     * Rozszerzona propozycja transakcji ręcznej: pełny dziennik jednego wpisu,
     * łącznie z ceną wyjścia, opłatą i czasem. Wynik i R liczy serwer.
     */
    public function createTradeProposal(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'pair', 'dir', 'entry', 'exit', 'sl', 'tp', 'size', 'fee', 'setup', 'emotion', 'grade',
            'checklist', 'checkTotal', 'errors', 'note', 'date', 'time', 'account', 'session', 'idempotencyKey',
        ]);
        $state = $this->state($userId);

        $pair = strtoupper(trim((string) ($args['pair'] ?? '')));
        if (preg_match('/^[A-Z0-9._\/-]{2,20}$/', $pair) !== 1) {
            throw new ProposalException('bad_request', 'Para musi mieć 2-20 znaków: A-Z, 0-9, . _ / -.', 400);
        }
        $dirRaw = (string) ($args['dir'] ?? '');
        if (!in_array($dirRaw, ['long', 'short', 'l', 's'], true)) {
            throw new ProposalException('bad_request', 'Kierunek musi być long albo short.', 400);
        }
        $direction = in_array($dirRaw, ['short', 's'], true) ? 's' : 'l';
        $entry = $this->positiveNumber($args['entry'] ?? null, 'entry');
        $size = $this->positiveNumber($args['size'] ?? null, 'size');
        if (!array_key_exists('sl', $args) || $args['sl'] === null || $args['sl'] === '') {
            throw new ProposalException('stop_loss_required', 'Stop loss jest wymagany przy zapisie transakcji.', 400);
        }
        $sl = null;
        if (array_key_exists('sl', $args) && $args['sl'] !== null && $args['sl'] !== '') {
            $sl = $this->positiveNumber($args['sl'], 'sl');
            if (($direction === 'l' && $sl >= $entry) || ($direction === 's' && $sl <= $entry)) {
                throw new ProposalException('bad_request', 'Stop loss jest po złej stronie ceny wejścia.', 400);
            }
        }
        $tp = null;
        if (array_key_exists('tp', $args) && $args['tp'] !== null && $args['tp'] !== '') {
            $tp = $this->positiveNumber($args['tp'], 'tp');
            if (($direction === 'l' && $tp <= $entry) || ($direction === 's' && $tp >= $entry)) {
                throw new ProposalException('bad_request', 'Take profit jest po złej stronie ceny wejścia.', 400);
            }
        }
        $exit = null;
        if (array_key_exists('exit', $args) && $args['exit'] !== null && $args['exit'] !== '') {
            $exit = $this->positiveNumber($args['exit'], 'exit');
        }
        $date = $this->tradeDateTime($args['date'] ?? null, $args['time'] ?? null);
        $account = $this->account($state, $args['account'] ?? null);
        $setupNames = $this->setupNames($state);
        $setup = trim((string) ($args['setup'] ?? 'Bez setupu'));
        if ($setup === '') {
            $setup = 'Bez setupu';
        }
        if ($setup !== 'Bez setupu' && !in_array($setup, $setupNames, true)) {
            throw new ProposalException('bad_request', 'Setup nie istnieje w Playbooku. Użyj list_setups.', 400);
        }
        if (mb_strlen($setup) > 100) {
            throw new ProposalException('bad_request', 'Setup może mieć maks. 100 znaków.', 400);
        }
        $emotion = $this->emotion($args['emotion'] ?? null) ?? 'Spokój';
        $checkTotal = array_key_exists('checkTotal', $args) ? (int) $args['checkTotal'] : $this->defaultCheckTotal($state);
        $checklist = array_key_exists('checklist', $args) ? (int) $args['checklist'] : 0;
        if ($checkTotal < 1 || $checkTotal > 20 || $checklist < 0 || $checklist > $checkTotal) {
            throw new ProposalException('bad_request', 'checklist musi być w zakresie 0-checkTotal, a checkTotal 1-20.', 400);
        }
        $grade = $this->grade($args['grade'] ?? null);
        if ($grade === null) {
            $grade = $checklist >= $checkTotal ? 'A' : ($checklist >= (int) ceil($checkTotal * 0.7) ? 'B' : 'C');
        }
        $errors = array_key_exists('errors', $args) ? $this->errors($args['errors']) : [];
        $fee = 0.0;
        if (array_key_exists('fee', $args) && $args['fee'] !== null && $args['fee'] !== '') {
            if (!is_numeric($args['fee']) || (float) $args['fee'] < 0 || (float) $args['fee'] > 100000) {
                throw new ProposalException('bad_request', 'fee musi być liczbą 0-100000.', 400);
            }
            $fee = Numbers::round((float) $args['fee']);
        }
        $session = trim((string) ($args['session'] ?? ''));
        if ($session === '') {
            $session = $this->session();
        }
        $risk = $sl !== null ? abs($entry - $sl) * $size : 0.0;
        $result = $exit !== null ? Numbers::round((($exit - $entry) * $size * ($direction === 's' ? -1 : 1)) + PHP_FLOAT_EPSILON) : null;
        $r = $result !== null && $risk > 0 ? Numbers::round($result / $risk) : null;
        $after = [
            'id' => 'manual_' . $this->shortId(),
            'date' => $date,
            'pair' => $pair,
            'dir' => $direction,
            'entry' => $this->formatNumber($entry, $this->decimals($entry)),
            'exit' => $exit !== null ? $this->formatNumber($exit, $this->decimals($exit)) : 'otwarta',
            'sl' => $sl !== null ? $this->formatNumber($sl, $this->decimals($sl)) : 'brak',
            'tp' => $tp !== null ? $this->formatNumber($tp, $this->decimals($tp)) : '-',
            'size' => $this->formatNumber($size, $this->decimals($size)),
            'result' => $result,
            'r' => $r,
            'grade' => $grade,
            'checklist' => $checklist,
            'checkTotal' => $checkTotal,
            'errors' => $errors,
            'emotion' => $emotion,
            'note' => mb_substr(trim((string) ($args['note'] ?? '')), 0, 2000),
            'mentor' => $this->mentorForGrade($grade, count($errors)),
            'setup' => $setup,
            'session' => $session,
            'mae' => null,
            'mfe' => null,
            'durationMin' => 0,
            'fee' => $fee,
            'plan' => $this->planForToday($state, $setup, $pair, $date),
            'source' => 'Ręcznie',
            'account' => $account,
            'screens' => [],
        ];

        return $this->proposals->create($userId, $clientName, 'manual_trade', 'trade:new', null, $after, $key, $this->fingerprint($args));
    }

    /**
     * Zmiana istniejącej transakcji. Pola finansowe transakcji importowanej
     * z giełdy są zablokowane; dla transakcji ręcznej wynik i R przelicza serwer.
     */
    public function updateTrade(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['tradeId', 'changes', 'idempotencyKey']);
        $tradeId = trim((string) ($args['tradeId'] ?? ''));
        if ($tradeId === '' || mb_strlen($tradeId) > 128) {
            throw new ProposalException('bad_request', 'tradeId musi mieć 1-128 znaków.', 400);
        }
        $changes = $args['changes'] ?? null;
        if (!is_array($changes) || $changes === []) {
            throw new ProposalException('bad_request', 'changes musi być niepustym obiektem zmian.', 400);
        }
        $state = $this->state($userId);
        $before = $this->findTrade($state, $tradeId);
        if ($before === null) {
            throw new ProposalException('not_found', 'Transakcja nie istnieje.', 404);
        }
        $exchange = $this->proposals->isExchangeTrade($userId, $before);
        $journal = ['setup', 'grade', 'emotion', 'errors', 'note', 'checklist', 'checkTotal', 'session'];
        $financial = ['pair', 'dir', 'entry', 'exit', 'sl', 'tp', 'size', 'fee', 'date'];
        foreach ($changes as $field => $_) {
            if (!in_array($field, array_merge($journal, $financial), true)) {
                throw new ProposalException('bad_request', 'Nieznane pole zmiany: ' . $field . '.', 400);
            }
            if ($exchange && in_array($field, $financial, true)) {
                throw new ProposalException('exchange_data_readonly', 'Transakcja importowana z giełdy ma niezmienne dane finansowe.', 403);
            }
        }
        $after = $before;
        if (array_key_exists('setup', $changes)) {
            $after['setup'] = mb_substr(trim((string) $changes['setup']), 0, 100);
        }
        if (array_key_exists('grade', $changes)) {
            $grade = $this->grade($changes['grade']);
            if ($grade === null) {
                throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
            }
            $after['grade'] = $grade;
        }
        if (array_key_exists('emotion', $changes)) {
            $after['emotion'] = $this->emotion($changes['emotion']);
        }
        if (array_key_exists('errors', $changes)) {
            $after['errors'] = $this->errors($changes['errors']);
        }
        if (array_key_exists('note', $changes)) {
            $after['note'] = mb_substr(trim((string) $changes['note']), 0, 2000);
        }
        if (array_key_exists('session', $changes)) {
            $after['session'] = mb_substr(trim((string) $changes['session']), 0, 40);
        }
        $checkTotal = array_key_exists('checkTotal', $changes) ? (int) $changes['checkTotal'] : (int) ($after['checkTotal'] ?? $this->defaultCheckTotal($state));
        $checklist = array_key_exists('checklist', $changes) ? (int) $changes['checklist'] : (int) ($after['checklist'] ?? 0);
        if ($checkTotal < 1 || $checkTotal > 20 || $checklist < 0 || $checklist > $checkTotal) {
            throw new ProposalException('bad_request', 'checklist musi być w zakresie 0-checkTotal, a checkTotal 1-20.', 400);
        }
        $after['checkTotal'] = $checkTotal;
        $after['checklist'] = $checklist;
        if (array_key_exists('pair', $changes)) {
            $pair = strtoupper(trim((string) $changes['pair']));
            if (preg_match('/^[A-Z0-9._\/-]{2,20}$/', $pair) !== 1) {
                throw new ProposalException('bad_request', 'Para musi mieć 2-20 znaków: A-Z, 0-9, . _ / -.', 400);
            }
            $after['pair'] = $pair;
        }
        if (array_key_exists('dir', $changes)) {
            $dirRaw = (string) $changes['dir'];
            if (!in_array($dirRaw, ['long', 'short', 'l', 's'], true)) {
                throw new ProposalException('bad_request', 'Kierunek musi być long albo short.', 400);
            }
            $after['dir'] = in_array($dirRaw, ['short', 's'], true) ? 's' : 'l';
        }
        foreach (['entry', 'sl', 'tp', 'size', 'fee', 'exit'] as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $value = $changes[$field];
            if ($field === 'sl' && ($value === null || $value === '' || $value === 'brak')) {
                throw new ProposalException('stop_loss_required', 'Stop loss jest wymagany przy transakcji.', 400);
            }
            if ($field === 'tp' && ($value === null || $value === '' || $value === '—')) {
                $after['tp'] = '—';
                continue;
            }
            if ($field === 'fee') {
                if (!is_numeric($value) || (float) $value < 0) {
                    throw new ProposalException('bad_request', 'fee musi być liczbą nieujemną.', 400);
                }
                $after['fee'] = Numbers::round((float) $value);
                continue;
            }
            $number = $this->positiveNumber($value, $field);
            $after[$field] = $this->formatNumber($number, $this->decimals($number));
        }
        if (array_key_exists('date', $changes)) {
            $after['date'] = $this->tradeDateTime($changes['date'], null);
        }
        $direction = (string) ($after['dir'] ?? 'l') === 's' ? -1 : 1;
        $entry = Numbers::pnum($after['entry'] ?? 0);
        $sizeValue = Numbers::pnum($after['size'] ?? 0);
        $slRaw = (string) ($after['sl'] ?? 'brak');
        $risk = ($slRaw !== '' && $slRaw !== 'brak' && $slRaw !== '—') ? abs($entry - Numbers::pnum($slRaw)) * $sizeValue : 0.0;
        $exitRaw = $after['exit'] ?? null;
        if ($exitRaw !== null && $exitRaw !== '' && $entry > 0 && $sizeValue > 0) {
            $exitValue = Numbers::pnum($exitRaw);
            $result = Numbers::round((($exitValue - $entry) * $sizeValue * $direction) + PHP_FLOAT_EPSILON);
            $after['result'] = $result;
            $after['r'] = $risk > 0 ? Numbers::round($result / $risk) : null;
        } elseif (($after['result'] ?? null) !== null && $risk > 0) {
            $after['r'] = Numbers::round((float) $after['result'] / $risk);
        }
        $after['mentor'] = $this->mentorForGrade((string) ($after['grade'] ?? 'B'), count((array) ($after['errors'] ?? [])));

        return $this->proposals->create($userId, $clientName, 'trade_update', 'trade:' . $tradeId, $before, $after, $key, $this->fingerprint($args));
    }

    /**
     * Zmiana setupu: najpierw stan bieżący, potem jawne zmiany. Diff pokazuje
     * każdą zmienioną regułę, więc wiadomo dokładnie co się zmienia.
     */
    public function updateSetup(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['setupId', 'changes', 'idempotencyKey']);
        $setupId = trim((string) ($args['setupId'] ?? ''));
        if ($setupId === '') {
            throw new ProposalException('bad_request', 'setupId nie może być pusty.', 400);
        }
        $changes = $args['changes'] ?? null;
        if (!is_array($changes) || $changes === []) {
            throw new ProposalException('bad_request', 'changes musi być niepustym obiektem zmian.', 400);
        }
        $state = $this->state($userId);
        $before = null;
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (is_array($setup) && (string) ($setup['id'] ?? '') === $setupId) {
                $before = $setup;
                break;
            }
        }
        if ($before === null) {
            throw new ProposalException('not_found', 'Setup nie istnieje w Playbooku.', 404);
        }
        $allowed = ['name', 'tf', 'grade', 'rules', 'bad', 'description', 'confirmation', 'invalidation', 'stopLoss', 'minRR', 'exitRules', 'examples'];
        foreach ($changes as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('bad_request', 'Nieznane pole zmiany setupu: ' . $field . '.', 400);
            }
        }
        $after = $before;
        if (array_key_exists('name', $changes)) {
            $name = trim((string) $changes['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
                throw new ProposalException('bad_request', 'Nazwa setupu musi mieć 2-80 znaków.', 400);
            }
            $after['name'] = $name;
        }
        if (array_key_exists('tf', $changes)) {
            $tf = trim((string) $changes['tf']);
            $after['tf'] = $tf === '' ? '-' : mb_substr($tf, 0, 40);
        }
        if (array_key_exists('grade', $changes)) {
            $grade = strtoupper(trim((string) $changes['grade']));
            if (!in_array($grade, ['A', 'B', 'C'], true)) {
                throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
            }
            $after['grade'] = $grade;
        }
        if (array_key_exists('rules', $changes)) {
            $after['rules'] = $this->rules($changes['rules']);
        }
        if (array_key_exists('bad', $changes)) {
            $after['bad'] = (bool) $changes['bad'];
        }
        if (array_key_exists('description', $changes)) {
            $after['description'] = mb_substr(trim((string) $changes['description']), 0, 1000);
        }
        if (array_key_exists('confirmation', $changes)) {
            $after['confirmation'] = mb_substr(trim((string) $changes['confirmation']), 0, 500);
        }
        if (array_key_exists('invalidation', $changes)) {
            $after['invalidation'] = mb_substr(trim((string) $changes['invalidation']), 0, 500);
        }
        if (array_key_exists('stopLoss', $changes)) {
            $after['stopLoss'] = mb_substr(trim((string) $changes['stopLoss']), 0, 200);
        }
        if (array_key_exists('minRR', $changes)) {
            if ($changes['minRR'] === null) {
                unset($after['minRR']);
            } else {
                $minRr = (float) $changes['minRR'];
                if ($minRr <= 0 || $minRr > 20) {
                    throw new ProposalException('bad_request', 'minRR musi być w zakresie 0.1-20.', 400);
                }
                $after['minRR'] = Numbers::round($minRr, 2);
            }
        }
        if (array_key_exists('exitRules', $changes)) {
            $after['exitRules'] = $this->stringList($changes['exitRules'], 'exitRules', 10, 200);
        }
        if (array_key_exists('examples', $changes)) {
            $after['examples'] = $this->stringList($changes['examples'], 'examples', 10, 300);
        }
        $after['updatedAt'] = gmdate('c');

        return $this->proposals->create($userId, $clientName, 'setup', 'setup:' . $setupId, $before, $after, $key, $this->fingerprint($args));
    }

    /**
     * Zmiana zasad ryzyka. Dozwolone jest wyłącznie utrzymanie albo zaostrzenie
     * zabezpieczeń: MCP nie wyłącza wyłącznika, nie podnosi limitów strat i nie
     * skraca blokady.
     */
    public function saveRiskRules(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['changes', 'reason', 'idempotencyKey']);
        $changes = $args['changes'] ?? null;
        if (!is_array($changes) || $changes === []) {
            throw new ProposalException('bad_request', 'changes musi być niepustym obiektem zmian.', 400);
        }
        $reason = trim((string) ($args['reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new ProposalException('bad_request', 'reason musi mieć 10-500 znaków.', 400);
        }
        $allowed = ['maxRisk', 'maxLosses', 'maxDailyLoss', 'block', 'requireReason', 'safetyEnabled', 'pushLimit', 'rules', 'checklist'];
        foreach ($changes as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                throw new ProposalException('bad_request', 'Nieznane pole zmiany zasad: ' . $field . '.', 400);
            }
        }
        $state = $this->state($userId);
        $settings = is_array($state['settings'] ?? null) ? $state['settings'] : [];
        $current = [
            'maxRisk' => (float) ($settings['maxRisk'] ?? 1),
            'maxLosses' => (int) ($settings['maxLosses'] ?? 2),
            'maxDailyLoss' => (float) ($settings['maxDailyLoss'] ?? 3),
            'block' => (string) ($settings['block'] ?? 'midnight'),
            'requireReason' => ($settings['requireReason'] ?? true) !== false,
            'safetyEnabled' => ($settings['safetyEnabled'] ?? true) !== false,
            'pushLimit' => ($settings['pushLimit'] ?? true) !== false,
            'rules' => array_values(array_map('strval', is_array($settings['rules'] ?? null) ? $settings['rules'] : [])),
            'checklist' => array_values(array_map('strval', is_array($settings['checklist'] ?? null) ? $settings['checklist'] : [])),
        ];
        $before = $current;
        $after = $current;
        if (array_key_exists('safetyEnabled', $changes) && !((bool) $changes['safetyEnabled'])) {
            throw new ProposalException('forbidden', 'MCP nie może wyłączać wyłącznika bezpieczeństwa.', 403);
        }
        if (array_key_exists('requireReason', $changes) && !((bool) $changes['requireReason'])) {
            throw new ProposalException('forbidden', 'MCP nie może wyłączyć wymogu powodu obejścia.', 403);
        }
        if (array_key_exists('pushLimit', $changes) && !((bool) $changes['pushLimit'])) {
            throw new ProposalException('forbidden', 'MCP nie może wyłączać powiadomienia o limicie.', 403);
        }
        if (array_key_exists('maxRisk', $changes)) {
            $maxRisk = (float) $changes['maxRisk'];
            if ($maxRisk < 0.1 || $maxRisk > 100) {
                throw new ProposalException('bad_request', 'maxRisk musi być w zakresie 0.1-100.', 400);
            }
            if ($maxRisk > $current['maxRisk'] + 0.0001) {
                throw new ProposalException('forbidden', 'MCP może tylko zmniejszać maksymalne ryzyko na transakcję. Podniesienie limitu wymaga decyzji w panelu.', 403);
            }
            $after['maxRisk'] = Numbers::round($maxRisk, 2);
        }
        if (array_key_exists('maxLosses', $changes)) {
            $maxLosses = (int) $changes['maxLosses'];
            if ($maxLosses < 1 || $maxLosses > 50) {
                throw new ProposalException('bad_request', 'maxLosses musi być w zakresie 1-50.', 400);
            }
            if ($maxLosses > $current['maxLosses']) {
                throw new ProposalException('forbidden', 'MCP może tylko zmniejszać liczbę strat uruchamiających blokadę.', 403);
            }
            $after['maxLosses'] = $maxLosses;
        }
        if (array_key_exists('maxDailyLoss', $changes)) {
            $daily = (float) $changes['maxDailyLoss'];
            if ($daily < 0.1 || $daily > 100) {
                throw new ProposalException('bad_request', 'maxDailyLoss musi być w zakresie 0.1-100.', 400);
            }
            if ($daily > $current['maxDailyLoss'] + 0.0001) {
                throw new ProposalException('forbidden', 'MCP może tylko zmniejszać dzienny limit straty.', 403);
            }
            $after['maxDailyLoss'] = Numbers::round($daily, 2);
        }
        if (array_key_exists('block', $changes)) {
            $block = (string) $changes['block'];
            if (!in_array($block, ['1h', '4h', 'midnight'], true)) {
                throw new ProposalException('bad_request', 'block musi być 1h, 4h albo midnight.', 400);
            }
            $rank = ['1h' => 1, '4h' => 2, 'midnight' => 3];
            if (($rank[$block] ?? 0) < ($rank[$current['block']] ?? 3)) {
                throw new ProposalException('forbidden', 'MCP nie może skracać blokady ochronnej.', 403);
            }
            $after['block'] = $block;
        }
        if (array_key_exists('rules', $changes)) {
            $after['rules'] = $this->stringList($changes['rules'], 'rules', 10, 200, 1);
        }
        if (array_key_exists('checklist', $changes)) {
            $after['checklist'] = $this->stringList($changes['checklist'], 'checklist', 20, 100, 1);
        }
        $after['reason'] = $reason;

        return $this->proposals->create($userId, $clientName, 'risk_rules', 'settings:risk', $before, $after, $key, $this->fingerprint($args));
    }

    private function stringList(mixed $value, string $field, int $maxItems, int $maxLength, int $minItems = 0): array
    {
        if (!is_array($value)) {
            throw new ProposalException('bad_request', $field . ' musi być tablicą tekstów.', 400);
        }
        if (count($value) < $minItems || count($value) > $maxItems) {
            throw new ProposalException('bad_request', $field . ' musi mieć ' . $minItems . '-' . $maxItems . ' pozycji.', 400);
        }
        $out = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text === '' || mb_strlen($text) > $maxLength) {
                throw new ProposalException('bad_request', 'Pozycja ' . $field . ' musi mieć 1-' . $maxLength . ' znaków.', 400);
            }
            $out[] = $text;
        }

        return $out;
    }

    private function emotion(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $emotion = trim((string) $value);
        if ($emotion === '') {
            return null;
        }
        if (!in_array($emotion, self::EMOTIONS, true)) {
            throw new ProposalException('bad_request', 'Nieznana emocja. Dozwolone: ' . implode(', ', self::EMOTIONS) . '.', 400);
        }

        return $emotion;
    }

    private function grade(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $grade = strtoupper(trim((string) $value));
        if (!in_array($grade, ['A', 'B', 'C'], true)) {
            throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
        }

        return $grade;
    }

    /**
     * Data i czas transakcji: ISO 8601 albo YYYY-MM-DD + HH:MM w Europe/Warsaw.
     * Data z przyszłości jest odrzucana.
     */
    private function tradeDateTime(mixed $date, mixed $time): string
    {
        $dateRaw = trim((string) ($date ?? ''));
        if ($dateRaw === '') {
            return gmdate('c');
        }
        $timeRaw = trim((string) ($time ?? ''));
        if ($timeRaw !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeRaw) !== 1) {
            throw new ProposalException('bad_request', 'time musi mieć format HH:MM.', 400);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
            if (!Dates::isValidKey($dateRaw)) {
                throw new ProposalException('bad_request', 'Nieprawidłowa data transakcji.', 400);
            }
            $dateRaw .= 'T' . ($timeRaw !== '' ? $timeRaw : '00:00') . ':00';
        }
        try {
            $local = (new \DateTimeImmutable($dateRaw, Dates::tz()));
        } catch (\Throwable) {
            throw new ProposalException('bad_request', 'Nieprawidłowa data transakcji.', 400);
        }
        $utc = $local->setTimezone(new \DateTimeZone('UTC'));
        if ($utc->getTimestamp() > time() + 300) {
            throw new ProposalException('bad_request', 'Transakcja nie może być z przyszłości.', 400);
        }
        if ($utc->format('Y') < '2000') {
            throw new ProposalException('bad_request', 'Data transakcji jest zbyt odległa.', 400);
        }

        return $utc->format('Y-m-d\TH:i:s.000\Z');
    }

    private function state(int $userId): array
    {
        $row = $this->db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return [];
        }
        $state = json_decode((string) $row['state'], true);

        return is_array($state) ? $state : [];
    }

    private function idempotencyKey(array $args): string
    {
        $key = trim((string) ($args['idempotencyKey'] ?? ''));
        if (mb_strlen($key) < 8 || mb_strlen($key) > 120) {
            throw new ProposalException('bad_request', 'idempotencyKey musi mieć 8-120 znaków.', 400);
        }

        return $key;
    }

    /**
     * Odcisk surowego wniosku agenta. Nie zawiera pól generowanych przez
     * serwer (id, createdAt, seed, czas), więc ponowienie tego samego
     * idempotencyKey daje tę samą propozycję.
     */
    private function fingerprint(array $args): string
    {
        unset($args['idempotencyKey']);
        $normalized = $this->sortKeys($args);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ProposalException('bad_request', 'Nie można zakodować wniosku.', 400);
        }

        return $json;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeys($item);
        }

        return $value;
    }

    private function rejectFields(array $args, array $allowed): void
    {
        $forbidden = ['entry', 'exit', 'exitPrice', 'price', 'fill', 'fillPrice', 'result', 'pnl', 'size', 'qty', 'quantity', 'sl', 'tp', 'leverage', 'fee', 'fees', 'balance', 'start', 'equity', 'source', 'exchangeId', 'externalId'];
        foreach ($args as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                if (in_array($field, $forbidden, true)) {
                    throw new ProposalException('exchange_data_readonly', 'Pole ' . $field . ' należy do danych giełdowych i jest tylko do odczytu.', 403);
                }
                throw new ProposalException('bad_request', 'Nieznane pole: ' . $field . '.', 400);
            }
        }
    }

    private function positiveNumber(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new ProposalException('bad_request', 'Pole ' . $field . ' musi być liczbą.', 400);
        }
        $number = (float) $value;
        if (!is_finite($number) || $number <= 0) {
            throw new ProposalException('bad_request', 'Pole ' . $field . ' musi być większe od 0.', 400);
        }

        return $number;
    }

    private function nonNegativeMetric(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new ProposalException('bad_request', 'Pole ' . $field . ' musi być nieujemną liczbą albo null.', 400);
        }

        return round((float) $value, 4);
    }

    private function storedMetric(mixed $value): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '') || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 0 ? round($number, 4) : null;
    }

    private function errors(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ProposalException('bad_request', 'errors musi być tablicą.', 400);
        }
        if (count($value) > 10) {
            throw new ProposalException('bad_request', 'Maksymalnie 10 błędów.', 400);
        }
        $out = [];
        foreach ($value as $error) {
            $text = trim((string) $error);
            if ($text === '' || mb_strlen($text) > 60) {
                throw new ProposalException('bad_request', 'Każdy błąd musi mieć 1–60 znaków.', 400);
            }
            $out[] = $text;
        }

        return $out;
    }

    private function rules(mixed $value): array
    {
        if (!is_array($value) || count($value) < 2 || count($value) > 5) {
            throw new ProposalException('bad_request', 'Setup musi mieć 2–5 zasad.', 400);
        }
        $out = [];
        foreach ($value as $rule) {
            $text = trim((string) $rule);
            if (mb_strlen($text) < 3 || mb_strlen($text) > 200) {
                throw new ProposalException('bad_request', 'Zasada musi mieć 3–200 znaków.', 400);
            }
            $out[] = $text;
        }

        return $out;
    }

    private function levels(mixed $value): array
    {
        try {
            return DayPlanLevels::normalizeList($value, 10, false, true, 30, true);
        } catch (\InvalidArgumentException $err) {
            throw new ProposalException('bad_request', $err->getMessage(), 400);
        }
    }

    private function setupNames(array $state): array
    {
        $names = [];
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (is_array($setup) && trim((string) ($setup['name'] ?? '')) !== '') {
                $names[] = (string) $setup['name'];
            }
        }

        return $names;
    }

    private function accountNames(array $state): array
    {
        $names = [];
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (is_array($account) && trim((string) ($account['name'] ?? '')) !== '') {
                $names[] = (string) $account['name'];
            }
        }

        return $names;
    }

    private function account(array $state, mixed $requested): string
    {
        $requested = trim((string) ($requested ?? ''));
        if ($requested !== '') {
            if (!in_array($requested, $this->accountNames($state), true)) {
                throw new ProposalException('bad_request', 'Konto nie należy do użytkownika.', 400);
            }
            return $requested;
        }
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (is_array($account) && ($account['include'] ?? true) !== false && trim((string) ($account['name'] ?? '')) !== '') {
                return (string) $account['name'];
            }
        }

        return 'Konto główne';
    }

    private function defaultCheckTotal(array $state): int
    {
        $points = (array) ($state['settings']['checklist'] ?? []);
        if ($points !== []) {
            return max(1, min(20, count($points)));
        }

        return 7;
    }

    private function planForToday(array $state, string $setup, string $pair, string $date): bool
    {
        if ($setup === 'Bez setupu') {
            return false;
        }
        $plan = (new DayPlanService())->planForTrade($state, ['pair' => $pair, 'date' => $date]);

        return $plan !== null && !empty($plan['approvedAt']);
    }

    private function mentorForGrade(string $grade, int $errorCount): string
    {
        if ($grade === 'A') {
            return 'Wzorowo: plan, pełna checklista, SL, spokojna głowa. Tak wyglądają Twoje najlepsze transakcje.';
        }
        if ($grade === 'B') {
            return 'Setup z planu, drobne odstępstwa. Trzymaj się checklisty do końca.';
        }
        if ($errorCount > 0) {
            return 'Ocena C i ' . $errorCount . ' błędów. To nie pech, to decyzja. Wróć do planu.';
        }

        return 'Ocena C: brak setupu albo pośpiech. Odczekaj i wróć z planem.';
    }

    private function tradeDate(string $value): string
    {
        if (trim($value) === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new ProposalException('bad_request', 'Data jest nieprawidłowa.', 400);
        }
        if ($ts > time() + 300) {
            throw new ProposalException('bad_request', 'Data nie może być z przyszłości.', 400);
        }
        if ($ts < time() - 2 * 365 * 86400) {
            throw new ProposalException('bad_request', 'Data nie może być starsza niż 2 lata.', 400);
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private function session(): string
    {
        $hour = (int) (new \DateTimeImmutable('now', Dates::tz()))->format('G');
        if ($hour < 8) {
            return 'Azja';
        }
        if ($hour < 13) {
            return 'Londyn';
        }
        if ($hour < 22) {
            return 'Nowy Jork';
        }

        return 'Po godzinach';
    }

    private function readinessScore(string $mood, int $energy): int
    {
        $mod = ['Spokój' => 10, 'Skupienie' => 7, 'Pewność' => 5, 'Nuda' => -3, 'Zmęczenie' => -8, 'Strach' => -10, 'FOMO' => -12, 'Złość' => -14, 'Chciwość' => -6][$mood] ?? 0;

        return (int) max(0, min(100, round(50 + ($energy - 3) * 10 + $mod)));
    }

    private function findTrade(array $state, string $id): ?array
    {
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (is_array($trade) && (string) ($trade['id'] ?? '') === $id) {
                return $trade;
            }
        }

        return null;
    }

    private function formatNumber(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function decimals(float $value): int
    {
        if ($value < 1) return 4;
        if ($value < 20) return 3;
        if ($value < 1000) return 2;

        return 0;
    }

    private function shortId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
