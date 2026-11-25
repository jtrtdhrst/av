<?php
declare(strict_types=1);

namespace AlphaVault;

/**
 * Wspólne reguły cyklu życia transakcji dla MCP, panelu i importów.
 *
 * `result !== null` (w tym 0) albo jawny, poprawny `closedAt` oznacza
 * zamknięcie. Brak `closedAt` przy zamkniętej transakcji oznacza nieznany czas;
 * nie wolno go uzupełniać na podstawie czasu odczytu ani importu.
 */
final class TradeRules
{
    private const PRICE_FIELDS = ['entry', 'size'];
    private const EXECUTION_FIELDS = [
        'id', 'pair', 'dir', 'entry', 'exit', 'sl', 'tp', 'size', 'initialSize',
        'partials', 'partialResult', 'resultOverride', 'sizeUnit', 'sizeContractSize',
        'result', 'r',
        'fee', 'mae', 'mfe', 'durationMin', 'date', 'closedAt', 'risk',
        'riskPct', 'source', 'account', 'session', 'plan', 'importedAt',
        'exchangeId', 'externalId', 'extId',
    ];
    private const MANUAL_SOURCES = ['', 'manual', 'panel', 'ręcznie', 'recznie'];
    private const NUMERIC_FIELDS = [
        'entry', 'exit', 'sl', 'tp', 'size', 'initialSize', 'partialResult',
        'resultOverride', 'sizeContractSize', 'result', 'r', 'risk', 'riskPct',
        'fee', 'mae', 'mfe', 'durationMin',
    ];

    /**
     * @return array{error:string,message:string,status:int}|null
     */
    public static function validate(array $trade, bool $allowMissingStopLoss = false): ?array
    {
        $tradeDate = $trade['date'] ?? null;
        if (!self::hasValidTradeDate($tradeDate)) {
            return self::failure('invalid_trade_date', 'date musi być poprawną datą albo timestampem ISO 8601.');
        }
        $pair = trim((string) ($trade['pair'] ?? ''));
        if (preg_match('#^[A-Z0-9._/-]{2,20}$#i', $pair) !== 1) {
            return self::failure('invalid_pair', 'Para musi mieć 2-20 znaków: A-Z, 0-9, . _ / -.');
        }
        $direction = self::normalizeDirection($trade['dir'] ?? null);
        if ($direction === null) {
            return self::failure('invalid_direction', 'Kierunek musi być long albo short.');
        }
        $isLong = $direction === 'l';

        foreach (self::PRICE_FIELDS as $field) {
            $value = self::number($trade[$field] ?? null);
            if ($value === null || $value <= 0) {
                return self::failure('bad_number', $field . ' musi być skończoną liczbą dodatnią.');
            }
        }

        $entry = (float) self::number($trade['entry']);
        $size = (float) self::number($trade['size']);
        $levels = [];
        foreach (['sl', 'tp'] as $field) {
            $raw = $trade[$field] ?? null;
            if (self::isEmptyLevel($raw)) {
                $levels[$field] = null;
                continue;
            }
            $value = self::number($raw);
            if ($value === null || $value <= 0) {
                return self::failure('bad_number', $field . ' musi być pusty albo skończoną liczbą dodatnią.');
            }
            $levels[$field] = $value;
        }

        if ($levels['sl'] === null && !$allowMissingStopLoss) {
            return self::failure('stop_loss_required', 'Stop loss jest wymagany dla transakcji ręcznej.');
        }
        // SL równy wejściu oznacza break-even i jest dozwolony.
        if ($levels['sl'] !== null
            && (($isLong && $levels['sl'] > $entry) || (!$isLong && $levels['sl'] < $entry))) {
            return self::failure('invalid_stop_loss', 'Stop loss jest po złej stronie ceny wejścia.');
        }
        if ($levels['tp'] !== null
            && (($isLong && $levels['tp'] <= $entry) || (!$isLong && $levels['tp'] >= $entry))) {
            return self::failure('invalid_take_profit', 'Take profit jest po złej stronie ceny wejścia.');
        }

        $risk = self::riskAmount($entry, $size, $levels['sl']);
        if (!is_finite($risk) || $risk < 0) {
            return self::failure('bad_number', 'Ryzyko transakcji musi być skończoną liczbą nieujemną.');
        }

        foreach (['exit', 'result', 'r', 'risk', 'riskPct', 'fee', 'mae', 'mfe', 'durationMin'] as $field) {
            if (!array_key_exists($field, $trade)
                || $trade[$field] === null
                || ($field === 'result'
                    ? self::isEmptyResult($trade[$field])
                    : self::isEmptyLevel($trade[$field]))
                || ($field === 'exit' && strtolower(trim((string) $trade[$field])) === 'otwarta')) {
                continue;
            }
            $value = self::number($trade[$field]);
            if ($value === null || (in_array($field, ['exit', 'risk', 'riskPct', 'mae', 'mfe', 'durationMin'], true) && $value < 0)) {
                return self::failure('bad_number', $field . ' musi być skończoną liczbą.');
            }
            if ($field === 'exit' && $value <= 0) {
                return self::failure('bad_number', 'exit musi być liczbą dodatnią albo pustą.');
            }
        }
        if (array_key_exists('closedAt', $trade)
            && $trade['closedAt'] !== null
            && $trade['closedAt'] !== ''
            && !self::hasValidClosedAt($trade)) {
            return self::failure('invalid_closed_at', 'closedAt musi być poprawnym znacznikiem ISO 8601.');
        }

        return null;
    }

    /**
     * Waliduje przejście całego dokumentu stanu. Nie blokuje odczytu ani
     * edycji notatek w historycznych rekordach, które już wcześniej były złe.
     *
     * @return array{error:string,message:string,status:int,tradeId?:string}|null
     */
    public static function validateTransition(array $beforeState, array $afterState): ?array
    {
        if (array_key_exists('trades', $afterState)
            && (!is_array($afterState['trades']) || !array_is_list($afterState['trades']))) {
            return self::failure('invalid_trade_list', 'trades musi być listą transakcji.');
        }
        if (array_key_exists('trash', $afterState)
            && (!is_array($afterState['trash']) || !array_is_list($afterState['trash']))) {
            return self::failure('invalid_trash_list', 'trash musi być listą wpisów kosza.');
        }

        $beforeTrades = array_values((array) ($beforeState['trades'] ?? []));
        $beforeById = [];
        $beforeActiveById = [];
        $beforeActiveWithoutId = [];
        $beforeByExternalId = [];
        foreach ($beforeTrades as $beforeTrade) {
            if (!is_array($beforeTrade)) {
                continue;
            }
            $id = trim((string) ($beforeTrade['id'] ?? ''));
            if ($id !== '') {
                $beforeById[$id] = $beforeTrade;
                $beforeActiveById[$id] = $beforeTrade;
            } else {
                $beforeActiveWithoutId[] = $beforeTrade;
            }
            foreach (['extId', 'externalId', 'exchangeId'] as $field) {
                $externalId = trim((string) ($beforeTrade[$field] ?? ''));
                if ($externalId !== '') {
                    $beforeByExternalId[$externalId] = $beforeTrade;
                }
            }
        }
        $beforeTrash = array_values((array) ($beforeState['trash'] ?? []));
        $beforeTrashTradeIds = [];
        $beforeTrashTradesWithoutTradeId = [];
        foreach ($beforeTrash as $trashEntry) {
            if (!is_array($trashEntry) || (string) ($trashEntry['kind'] ?? '') !== 'trade'
                || !is_array($trashEntry['record'] ?? null)) {
                continue;
            }
            $beforeTrade = $trashEntry['record'];
            $tradeId = trim((string) ($beforeTrade['id'] ?? ''));
            $trashId = trim((string) ($trashEntry['id'] ?? ''));
            if ($tradeId === '') {
                $beforeTrashTradesWithoutTradeId[] = $beforeTrade;
            }
            if ($tradeId !== '' && $trashId !== '') {
                $beforeTrashTradeIds[$tradeId] = $trashId;
            }
            if ($tradeId !== '' && !isset($beforeById[$tradeId])) {
                $beforeById[$tradeId] = $beforeTrade;
            }
            foreach (['extId', 'externalId', 'exchangeId'] as $field) {
                $externalId = trim((string) ($beforeTrade[$field] ?? ''));
                if ($externalId !== '' && !isset($beforeByExternalId[$externalId])) {
                    $beforeByExternalId[$externalId] = $beforeTrade;
                }
            }
        }

        $afterTrades = array_values((array) ($afterState['trades'] ?? []));
        $afterIds = [];
        foreach ($afterTrades as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $id = trim((string) ($trade['id'] ?? ''));
            if ($id !== '') {
                if (isset($afterIds[$id])) {
                    return self::failure('duplicate_trade_id', 'Identyfikator transakcji musi być unikalny.', 422, $id);
                }
                $afterIds[$id] = true;
            }
        }

        $afterTrash = array_values((array) ($afterState['trash'] ?? []));
        $afterTrashById = [];
        $afterTradeTrashIds = [];
        $afterTradeTrashWithoutIds = [];
        $newTrashEntries = 0;
        $newTrashIndexes = [];
        foreach ($afterTrash as $index => $entry) {
            if (!is_array($entry)) {
                $preservedMalformedEntry = false;
                foreach ($beforeTrash as $oldEntry) {
                    if (self::sameValue($oldEntry, $entry)) {
                        $preservedMalformedEntry = true;
                        break;
                    }
                }
                if (!$preservedMalformedEntry) {
                    return self::failure('invalid_trash_entry', 'Każdy wpis kosza musi być obiektem.');
                }
                continue;
            }
            $legacyEntryExists = false;
            $trashId = trim((string) ($entry['id'] ?? ''));
            if ((string) ($entry['kind'] ?? '') === 'trade' && !is_array($entry['record'] ?? null)) {
                $preservedMalformedEntry = false;
                foreach ($beforeTrash as $oldEntry) {
                    if (is_array($oldEntry)
                        && (string) ($oldEntry['kind'] ?? '') === 'trade'
                        && self::sameValue($oldEntry, $entry)) {
                        $preservedMalformedEntry = true;
                        break;
                    }
                }
                if (!$preservedMalformedEntry) {
                    return self::failure('invalid_trash_entry', 'Wpis transakcji w koszu musi zawierać rekord.');
                }
            }
            if ((string) ($entry['kind'] ?? '') === 'trade' && is_array($entry['record'] ?? null)) {
                $recordId = trim((string) ($entry['record']['id'] ?? ''));
                if ($trashId === '') {
                    foreach ($beforeTrash as $oldEntry) {
                        if (is_array($oldEntry)
                            && trim((string) ($oldEntry['id'] ?? '')) === ''
                            && self::sameValue($oldEntry, $entry)) {
                            $legacyEntryExists = true;
                            break;
                        }
                    }
                    if (!$legacyEntryExists) {
                        return self::failure('missing_trash_id', 'Nowy wpis transakcji w koszu musi mieć stabilny identyfikator.', 422);
                    }
                }
                if ($recordId !== '') {
                    if (isset($afterIds[$recordId]) || isset($afterTradeTrashIds[$recordId])) {
                        return self::failure(
                            'duplicate_trade_id',
                            'Identyfikator transakcji może wystąpić tylko raz w aktywnych danych i koszu.',
                            422,
                            $recordId
                        );
                    }
                    if (isset($beforeTrashTradeIds[$recordId])
                        && $beforeTrashTradeIds[$recordId] !== $trashId) {
                        return self::failure(
                            'trade_trash_immutable',
                            'Istniejącego wpisu transakcji w koszu nie można przenieść ani podmienić.',
                            409,
                            $recordId
                        );
                    }
                    $afterTradeTrashIds[$recordId] = true;
                } else {
                    foreach ($afterTradeTrashWithoutIds as $oldRecord) {
                        if (self::sameTrade($oldRecord, $entry['record'])) {
                            return self::failure('duplicate_trade_id', 'Id-less trade records in the bin must not be duplicated.', 422);
                        }
                    }
                    $afterTradeTrashWithoutIds[] = $entry['record'];
                }
            }
            if ($trashId === '') {
                if (!$legacyEntryExists) {
                    $newTrashEntries++;
                    $newTrashIndexes[] = $index;
                }
                continue;
            }
            if (isset($afterTrashById[$trashId])) {
                return self::failure('duplicate_trash_id', 'Identyfikator wpisu kosza musi być unikalny.', 422);
            }
            $afterTrashById[$trashId] = $entry;
            $foundBefore = false;
            foreach ($beforeTrash as $oldEntry) {
                if (is_array($oldEntry) && (string) ($oldEntry['id'] ?? '') === $trashId) {
                    $foundBefore = true;
                    break;
                }
            }
            if (!$foundBefore) {
                $newTrashEntries++;
                $newTrashIndexes[] = $index;
            }
        }
        $beforeTradeTrash = [];
        $beforeLegacyTradeTrash = [];
        foreach ($beforeTrash as $index => $entry) {
            if (is_array($entry) && (string) ($entry['kind'] ?? '') === 'trade'
                && is_array($entry['record'] ?? null)) {
                $trashId = trim((string) ($entry['id'] ?? ''));
                if ($trashId !== '') {
                    $beforeTradeTrash[$trashId] = ['index' => $index, 'entry' => $entry];
                } else {
                    $beforeLegacyTradeTrash[] = ['index' => $index, 'entry' => $entry];
                }
            }
        }
        foreach ($newTrashIndexes as $index) {
            $entry = $afterTrash[$index] ?? null;
            if (!is_array($entry)
                || (string) ($entry['kind'] ?? '') !== 'trade'
                || !is_array($entry['record'] ?? null)) {
                continue;
            }
            $record = $entry['record'];
            $recordId = trim((string) ($record['id'] ?? ''));
            $sourceTrade = $recordId !== '' ? ($beforeActiveById[$recordId] ?? null) : null;
            if ($sourceTrade === null && $recordId === '') {
                foreach ($beforeActiveWithoutId as $candidate) {
                    if (self::sameTrade($candidate, $record)) {
                        $sourceTrade = $candidate;
                        break;
                    }
                }
            }
            if (!is_array($sourceTrade) || !self::sameTrade($sourceTrade, $record)) {
                return self::failure(
                    'trade_trash_source_invalid',
                    'Nowy wpis transakcji w koszu musi zachowywać dokładny rekord istniejącej transakcji.',
                    422,
                    $recordId
                );
            }
        }
        $maxTrimmedPrefix = max(0, count($beforeTrash) + $newTrashEntries - 200);
        $newEntriesAtTail = true;
        foreach ($newTrashIndexes as $index) {
            if ($index < count($afterTrash) - $newTrashEntries) {
                $newEntriesAtTail = false;
                break;
            }
        }
        foreach ($beforeTradeTrash as $trashId => $old) {
            $oldEntry = $old['entry'];
            if (isset($afterTrashById[$trashId])) {
                $current = $afterTrashById[$trashId];
                if ((string) ($current['kind'] ?? '') !== 'trade'
                    || !is_array($current['record'] ?? null)
                    || !self::sameTrade($oldEntry['record'], $current['record'])
                    || !self::sameValue($oldEntry['associatedReview'] ?? null, $current['associatedReview'] ?? null)) {
                    return self::failure(
                        'trade_trash_immutable',
                        'Transakcji i powiązanej recenzji w koszu nie można zmieniać.',
                        409,
                        (string) ($oldEntry['record']['id'] ?? '')
                    );
                }
                continue;
            }
            $restored = self::hasExactTradeInState($afterState, $oldEntry['record']);
            if ($restored) {
                $associatedReview = $oldEntry['associatedReview'] ?? null;
                $tradeId = trim((string) ($oldEntry['record']['id'] ?? ''));
                $afterReviews = is_array($afterState['tradeReviews'] ?? null) ? $afterState['tradeReviews'] : [];
                if (is_array($associatedReview) && $tradeId !== ''
                    && !self::sameValue($associatedReview, $afterReviews[$tradeId] ?? null)) {
                    return self::failure(
                        'trade_review_restore_mismatch',
                        'Przywrócenie transakcji musi zachować jej powiązaną recenzję.',
                        409,
                        $tradeId
                    );
                }
                continue;
            }
            $trimIsValid = count($afterTrash) >= 200
                && $newTrashEntries > 0
                && $newEntriesAtTail
                && $old['index'] < $maxTrimmedPrefix;
            if (!$trimIsValid) {
                return self::failure(
                    'trade_trash_immutable',
                    'Wpisu zamkniętej transakcji w koszu nie można usunąć ani zmienić.',
                    409,
                    (string) ($oldEntry['record']['id'] ?? '')
                );
            }
        }
        foreach ($beforeLegacyTradeTrash as $old) {
            $oldEntry = $old['entry'];
            $unchanged = false;
            foreach ($afterTrash as $entry) {
                if (is_array($entry) && trim((string) ($entry['id'] ?? '')) === ''
                    && self::sameValue($oldEntry, $entry)) {
                    $unchanged = true;
                    break;
                }
            }
            if ($unchanged) {
                continue;
            }
            if (self::hasExactTradeInState($afterState, $oldEntry['record'])) {
                $tradeId = trim((string) ($oldEntry['record']['id'] ?? ''));
                $reviews = is_array($afterState['tradeReviews'] ?? null) ? $afterState['tradeReviews'] : [];
                $associatedReview = $oldEntry['associatedReview'] ?? null;
                if (!is_array($associatedReview)
                    || $tradeId === ''
                    || self::sameValue($associatedReview, $reviews[$tradeId] ?? null)) {
                    continue;
                }
            }
            $trimIsValid = count($afterTrash) >= 200
                && $newTrashEntries > 0
                && $newEntriesAtTail
                && $old['index'] < $maxTrimmedPrefix;
            if (!$trimIsValid) {
                return self::failure(
                    'trade_trash_immutable',
                    'Wpisu transakcji bez identyfikatora kosza nie można zmieniać ani usuwać.',
                    409,
                    (string) ($oldEntry['record']['id'] ?? '')
                );
            }
        }

        // Existing trade identity is independent of list order. A removed trade
        // must be preserved verbatim in the soft-delete bin; otherwise changing
        // its id (or omitting it) would turn an update into a new record.
        foreach ($beforeActiveById as $id => $beforeTrade) {
            if (isset($afterIds[$id])) {
                continue;
            }
            if (!self::hasExactTradeInTrash($afterState, $beforeTrade)) {
                return self::failure(
                    'trade_id_immutable',
                    'Istniejącej transakcji nie można usunąć ani zmienić jej ID; przenieś ją do kosza z zachowaniem oryginalnego rekordu.',
                    409,
                    (string) $id
                );
            }
            $beforeReviews = is_array($beforeState['tradeReviews'] ?? null) ? $beforeState['tradeReviews'] : [];
            $afterReviews = is_array($afterState['tradeReviews'] ?? null) ? $afterState['tradeReviews'] : [];
            if (is_array($beforeReviews[$id] ?? null)) {
                $reviewPreserved = false;
                foreach ((array) ($afterState['trash'] ?? []) as $entry) {
                    if (is_array($entry)
                        && (string) ($entry['kind'] ?? '') === 'trade'
                        && is_array($entry['record'] ?? null)
                        && self::sameTrade($entry['record'], $beforeTrade)
                        && self::sameValue($entry['associatedReview'] ?? null, $beforeReviews[$id])) {
                        $reviewPreserved = true;
                        break;
                    }
                }
                if (!$reviewPreserved || array_key_exists($id, $afterReviews)) {
                    return self::failure(
                        'trade_review_delete_mismatch',
                        'Usunięcie transakcji musi przenieść jej recenzję do tego samego wpisu kosza.',
                        409,
                        (string) $id
                    );
                }
            }
        }
        $afterActiveWithoutId = [];
        foreach ($afterTrades as $index => $trade) {
            if (is_array($trade) && trim((string) ($trade['id'] ?? '')) === '') {
                $afterActiveWithoutId[$index] = $trade;
            }
        }
        $afterTrashWithoutId = [];
        foreach ((array) ($afterState['trash'] ?? []) as $trashEntry) {
            if (is_array($trashEntry) && (string) ($trashEntry['kind'] ?? '') === 'trade'
                && is_array($trashEntry['record'] ?? null)
                && trim((string) ($trashEntry['record']['id'] ?? '')) === '') {
                $afterTrashWithoutId[] = $trashEntry['record'];
            }
        }
        $beforeWithoutIdByAfterIndex = [];
        foreach ($beforeActiveWithoutId as $beforeTrade) {
            $matchedActive = false;
            foreach ($afterActiveWithoutId as $index => $candidate) {
                if (!self::executionChanged($beforeTrade, $candidate)) {
                    $beforeWithoutIdByAfterIndex[$index] = $beforeTrade;
                    unset($afterActiveWithoutId[$index]);
                    $matchedActive = true;
                    break;
                }
            }
            if (!$matchedActive && !self::consumeExactTrade($afterTrashWithoutId, $beforeTrade)) {
                return self::failure(
                    'trade_id_immutable',
                    'Transakcji bez identyfikatora nie można zmienić ani usunąć bez zachowania oryginału w koszu.',
                    409
                );
            }
        }
        foreach ($beforeTrashTradesWithoutTradeId as $beforeTrade) {
            $matchedIndex = null;
            foreach ($afterActiveWithoutId as $index => $candidate) {
                if (self::sameTrade($beforeTrade, $candidate)) {
                    $matchedIndex = $index;
                    break;
                }
            }
            if ($matchedIndex !== null) {
                $beforeWithoutIdByAfterIndex[$matchedIndex] = $beforeTrade;
                unset($afterActiveWithoutId[$matchedIndex]);
            }
        }
        if ($afterActiveWithoutId !== []) {
            return self::failure(
                'missing_trade_id',
                'Nowa transakcja musi mieć stabilny identyfikator.',
                422
            );
        }

        foreach ($afterTrades as $position => $trade) {
            if (!is_array($trade)) {
                return self::failure('invalid_trade', 'Każda transakcja musi być obiektem.');
            }
            $id = trim((string) ($trade['id'] ?? ''));
            $before = $id !== '' ? ($beforeById[$id] ?? null) : null;
            if ($before === null) {
                foreach (['extId', 'externalId', 'exchangeId'] as $field) {
                    $externalId = trim((string) ($trade[$field] ?? ''));
                    if ($externalId !== '' && isset($beforeByExternalId[$externalId])) {
                        $before = $beforeByExternalId[$externalId];
                        break;
                    }
                }
            }
            if ($before === null && $id === '') {
                $before = $beforeWithoutIdByAfterIndex[$position] ?? null;
            }

            if ($before !== null) {
                $beforeId = trim((string) ($before['id'] ?? ''));
                if ($beforeId !== '' && $beforeId !== $id) {
                    return self::failure('trade_id_immutable', 'Identyfikatora istniejącej transakcji nie można zmienić.', 409, $id);
                }
                if (!isset($beforeActiveById[$id]) && isset($beforeById[$id])
                    && !self::sameTrade($beforeById[$id], $trade)) {
                    return self::failure(
                        'trade_id_immutable',
                        'Przywrócona transakcja musi zachować oryginalny rekord z kosza.',
                        409,
                        $id
                    );
                }
            }

            if ($before !== null && self::executionChanged($before, $trade)) {
                if (self::isExecutionReadonly($before)) {
                    return self::failure(
                        'exchange_data_readonly',
                        'Pola wykonania transakcji importowanej są tylko do odczytu.',
                        403,
                        $id
                    );
                }
            }

            if ($before !== null && !self::domainFieldsChanged($before, $trade)) {
                continue;
            }

            $allowMissingLegacyStopLoss = $before !== null
                && self::isEmptyLevel($before['sl'] ?? null)
                && self::isClosed($trade)
                && self::sameTradeField('pair', $before['pair'] ?? null, $trade['pair'] ?? null)
                && self::sameTradeField('dir', $before['dir'] ?? null, $trade['dir'] ?? null)
                && self::sameTradeField('entry', $before['entry'] ?? null, $trade['entry'] ?? null)
                && self::sameTradeField('size', $before['size'] ?? null, $trade['size'] ?? null)
                && self::sameTradeField('date', $before['date'] ?? null, $trade['date'] ?? null);
            $violation = self::validate($trade, $allowMissingLegacyStopLoss);
            if ($violation !== null) {
                if ($id !== '') {
                    $violation['tradeId'] = $id;
                }
                return $violation;
            }
        }

        return null;
    }

    public static function number(mixed $value): ?float
    {
        if (is_bool($value) || $value === null || !is_scalar($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
            return is_finite($number) ? $number : null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $text = str_replace(["\u{00a0}", ' '], '', $text);
        $text = preg_replace('/[$€£%x×]/iu', '', $text) ?? $text;
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            if ($comma > $dot) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($comma !== false) {
            $text = str_replace(',', '.', $text);
        }
        if (!is_numeric($text)) {
            return null;
        }
        $number = (float) $text;
        return is_finite($number) ? $number : null;
    }

    public static function isClosed(array $trade): bool
    {
        return (array_key_exists('result', $trade) && !self::isEmptyResult($trade['result']))
            || self::hasValidClosedAt($trade);
    }

    public static function normalizeDirection(mixed $direction): ?string
    {
        if (!is_scalar($direction)) {
            return null;
        }
        return match (strtolower(trim((string) $direction))) {
            'l', 'long' => 'l',
            's', 'short' => 's',
            default => null,
        };
    }

    public static function directionSign(mixed $direction): ?int
    {
        return match (self::normalizeDirection($direction)) {
            'l' => 1,
            's' => -1,
            default => null,
        };
    }

    /**
     * Normalize valid legacy direction aliases without changing malformed rows.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function normalizeTradeDirections(array $state): array
    {
        if (!is_array($state['trades'] ?? null)) {
            return $state;
        }
        foreach ($state['trades'] as $index => $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $direction = self::normalizeDirection($trade['dir'] ?? null);
            if ($direction !== null) {
                $trade['dir'] = $direction;
                $state['trades'][$index] = $trade;
            }
        }
        return $state;
    }

    /**
     * Canonicalizes valid values before a whole-state API write so that a value
     * validated as 1234.56 is not persisted as an ambiguous locale string.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    public static function normalizeTradeState(array $state): array
    {
        $state = self::normalizeTradeDirections($state);
        if (!is_array($state['trades'] ?? null)) {
            return $state;
        }
        foreach ($state['trades'] as $index => $trade) {
            if (!is_array($trade)) {
                continue;
            }
            if (is_string($trade['pair'] ?? null)) {
                $trade['pair'] = trim($trade['pair']);
            }
            if (array_key_exists('result', $trade) && self::isEmptyResult($trade['result'])) {
                $trade['result'] = null;
            }
            if (array_key_exists('closedAt', $trade)
                && is_string($trade['closedAt'])
                && trim($trade['closedAt']) === '') {
                $trade['closedAt'] = null;
            }
            foreach (self::NUMERIC_FIELDS as $field) {
                if (!array_key_exists($field, $trade)
                    || !is_string($trade[$field])
                    || self::isEmptyLevel($trade[$field])
                    || ($field === 'exit' && $trade[$field] === 'otwarta')) {
                    continue;
                }
                $normalized = self::normalizeNumberText($trade[$field]);
                if ($normalized !== null) {
                    $trade[$field] = $normalized;
                }
            }
            $state['trades'][$index] = $trade;
        }
        return $state;
    }

    public static function closedAtStatus(array $trade): string
    {
        if (!self::isClosed($trade)) {
            return 'open';
        }
        return self::hasValidClosedAt($trade) ? 'known' : 'unknown';
    }

    public static function hasValidClosedAt(array $trade): bool
    {
        $value = $trade['closedAt'] ?? null;
        if (!is_string($value)
            || preg_match(
                '/\A(?<local>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,9})?(?<zone>Z|(?<sign>[+-])(?<hours>\d{2}):(?<minutes>\d{2}))\z/',
                $value,
                $parts
            ) !== 1) {
            return false;
        }
        if (isset($parts['hours']) && (
            (int) $parts['hours'] > 14
            || ((int) $parts['hours'] === 14 && (int) $parts['minutes'] !== 0)
            || (int) $parts['minutes'] > 59
        )) {
            return false;
        }
        try {
            $parsed = new \DateTimeImmutable($value);
            return $parsed->format('Y-m-d\TH:i:s') === $parts['local'];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasValidTradeDate(mixed $value): bool
    {
        if (!is_string($value) || $value === '' || trim($value) !== $value) {
            return false;
        }
        if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/', $value, $parts) === 1) {
            return checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year']);
        }

        return self::hasValidClosedAt(['closedAt' => $value]);
    }

    public static function isExecutionReadonly(array $trade): bool
    {
        $source = mb_strtolower(trim((string) ($trade['source'] ?? '')));
        return !in_array($source, self::MANUAL_SOURCES, true)
            || self::hasExternalIdentifier($trade['extId'] ?? null)
            || self::hasExternalIdentifier($trade['externalId'] ?? null)
            || self::hasExternalIdentifier($trade['exchangeId'] ?? null);
    }

    private static function hasExternalIdentifier(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    public static function executionChanged(array $before, array $after): bool
    {
        foreach (self::EXECUTION_FIELDS as $field) {
            if (!self::sameTradeField($field, $before[$field] ?? null, $after[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    public static function riskAmount(float $entry, float $size, ?float $stopLoss): float
    {
        return $stopLoss !== null
            ? abs($entry - $stopLoss) * $size
            : abs($entry * 0.01 * $size);
    }

    /**
     * Rebuilds derived metrics for edits to an already-closed manual trade.
     * Stored execution data is the source for partial legs and their overrides;
     * client-supplied result/R fields are never used as calculation inputs.
     *
     * @return array{error:string,message:string,status:int,tradeId?:string}|null
     */
    public static function recalculateClosedManualTradeEdits(array $beforeState, array &$afterState): ?array
    {
        $beforeById = [];
        foreach ((array) ($beforeState['trades'] ?? []) as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $id = trim((string) ($trade['id'] ?? ''));
            if ($id !== '') {
                $beforeById[$id] = $trade;
            }
        }

        if (!is_array($afterState['trades'] ?? null)) {
            return null;
        }

        foreach ($afterState['trades'] as $index => $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $id = trim((string) ($trade['id'] ?? ''));
            if ($id === '' || !isset($beforeById[$id])) {
                continue;
            }
            $before = $beforeById[$id];
            if (!self::isClosed($before)
                || self::isExecutionReadonly($before)
                || !self::closedTradeMetricsChanged($before, $trade)) {
                continue;
            }

            $recalculated = self::recalculateClosedManualTrade($before, $trade, $afterState);
            if (isset($recalculated['error'], $recalculated['status'], $recalculated['message'])) {
                return $recalculated;
            }
            $afterState['trades'][$index] = $recalculated;
        }

        return null;
    }

    private static function closedTradeMetricsChanged(array $before, array $after): bool
    {
        foreach ([
            'entry', 'dir', 'exit', 'sl', 'tp', 'size', 'initialSize', 'partials',
            'partialResult', 'resultOverride', 'result', 'r', 'risk', 'riskPct',
        ] as $field) {
            if (!self::sameTradeField($field, $before[$field] ?? null, $after[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $requested
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function recalculateClosedManualTrade(array $before, array $requested, array $state): array
    {
        // Preserve fields omitted by an older client and keep the original close
        // timestamp byte-for-byte (including the unknown-time null case).
        $updated = array_replace($before, $requested);
        if (array_key_exists('closedAt', $before)) {
            $updated['closedAt'] = $before['closedAt'];
        } else {
            unset($updated['closedAt']);
        }

        $partials = $before['partials'] ?? [];
        if ($partials === null) {
            $partials = [];
        }
        if (!is_array($partials) || !array_is_list($partials)) {
            return self::closedTradeMetricsUnsupported('partials nie są poprawną listą', $before);
        }
        $hasPartials = $partials !== [];
        $remainingFraction = 1.0;
        $partialPercentages = [];
        if ($hasPartials) {
            foreach ($partials as $partial) {
                if (!is_array($partial)) {
                    return self::closedTradeMetricsUnsupported('częściowe zamknięcie nie jest obiektem', $before);
                }
                $pct = self::number($partial['pct'] ?? null);
                if ($pct === null || $pct <= 0 || $pct >= 100) {
                    return self::closedTradeMetricsUnsupported('procent częściowego zamknięcia jest nieprawidłowy', $before);
                }
                $partialPercentages[] = $pct;
                $remainingFraction *= 1 - ($pct / 100);
                if (!is_finite($remainingFraction) || $remainingFraction <= 0) {
                    return self::closedTradeMetricsUnsupported('częściowe zamknięcia nie pozostawiają dodatniej wielkości', $before);
                }
            }
        }

        $storedSize = self::number($before['size'] ?? null);
        $storedInitialSize = self::number($before['initialSize'] ?? null);
        $initialSizeChanged = array_key_exists('initialSize', $requested)
            && !self::sameTradeField('initialSize', $before['initialSize'] ?? null, $requested['initialSize']);
        $sizeChanged = array_key_exists('size', $requested)
            && !self::sameTradeField('size', $before['size'] ?? null, $requested['size']);

        if ($hasPartials) {
            if ($storedInitialSize === null || $storedInitialSize <= 0
                || $storedSize === null || $storedSize <= 0) {
                return self::closedTradeMetricsUnsupported('brak poprawnego initialSize lub pozostałej wielkości dla partials', $before);
            }
            $expectedStoredSize = $storedInitialSize * $remainingFraction;
            $sizeTolerance = max(1.0, abs($expectedStoredSize)) * 1.0e-7;
            if (!is_finite($expectedStoredSize) || abs($expectedStoredSize - $storedSize) > $sizeTolerance) {
                return self::closedTradeMetricsUnsupported('initialSize, size i sekwencja partials są niespójne', $before);
            }

            if ($initialSizeChanged) {
                $initialSize = self::number($requested['initialSize']);
            } elseif ($sizeChanged) {
                $requestedRemaining = self::number($requested['size']);
                $initialSize = $requestedRemaining !== null
                    ? $requestedRemaining / $remainingFraction
                    : null;
            } else {
                $initialSize = $storedInitialSize;
            }
        } else {
            if ($initialSizeChanged) {
                $initialSize = self::number($requested['initialSize']);
            } elseif ($sizeChanged) {
                $initialSize = self::number($requested['size']);
            } else {
                $initialSize = $storedInitialSize ?? $storedSize;
                if ($storedInitialSize !== null && $storedInitialSize > 0
                    && $storedSize !== null && $storedSize > 0) {
                    $sizeTolerance = max(1.0, abs($storedInitialSize)) * 1.0e-8;
                    if (abs($storedInitialSize - $storedSize) > $sizeTolerance) {
                        return self::closedTradeMetricsUnsupported('initialSize różni się od size, ale brak danych partials', $before);
                    }
                }
            }
        }
        if ($initialSize === null || !is_finite($initialSize) || $initialSize <= 0) {
            return self::closedTradeMetricsUnsupported(
                $hasPartials ? 'brak poprawnego initialSize dla transakcji z częściowymi zamknięciami' : 'brak poprawnej wielkości',
                $before
            );
        }

        $entry = self::number($updated['entry'] ?? null);
        if ($entry === null || $entry <= 0) {
            return self::failure('bad_number', 'entry musi być skończoną liczbą dodatnią.', 422, self::tradeId($before));
        }
        $directionSign = self::directionSign($updated['dir'] ?? null);
        if ($directionSign === null) {
            return self::failure('invalid_direction', 'Kierunek musi być long albo short.', 422, self::tradeId($before));
        }

        $overrideValue = array_key_exists('resultOverride', $requested)
            ? $requested['resultOverride']
            : ($before['resultOverride'] ?? null);
        $hasAggregateOverride = !self::isEmptyResult($overrideValue);
        $aggregateOverride = null;
        if ($hasAggregateOverride) {
            $aggregateOverride = self::number($overrideValue);
            if ($aggregateOverride === null) {
                return self::closedTradeMetricsUnsupported('resultOverride nie jest liczbą', $before);
            }
        }
        if (array_key_exists('resultOverride', $before) || array_key_exists('resultOverride', $requested)) {
            $updated['resultOverride'] = $hasAggregateOverride ? self::roundTradeMetric((float) $aggregateOverride) : null;
        }

        $exitRaw = $updated['exit'] ?? null;
        $exit = self::number($exitRaw);
        $exitIsEmpty = self::isEmptyLevel($exitRaw)
            || (is_string($exitRaw) && strtolower(trim($exitRaw)) === 'otwarta');
        if (!$exitIsEmpty && ($exit === null || $exit <= 0)) {
            return self::failure('bad_number', 'exit musi być liczbą dodatnią albo pustą.', 422, self::tradeId($before));
        }

        $entryFields = ['entry', 'dir', 'size', 'initialSize', 'exit'];
        $pnlInputsChanged = false;
        foreach ($entryFields as $field) {
            if (array_key_exists($field, $requested)
                && !self::sameTradeField($field, $before[$field] ?? null, $requested[$field])) {
                $pnlInputsChanged = true;
                break;
            }
        }
        if (!$pnlInputsChanged && array_key_exists('partials', $requested)) {
            $pnlInputsChanged = self::partialPnlInputsChanged($partials, $requested['partials']);
        }

        $partialResult = 0.0;
        $remaining = $initialSize;
        $updatedPartials = [];
        if ($hasPartials) {
            foreach ($partials as $index => $partial) {
                $pct = $partialPercentages[$index];
                $legSize = $remaining * $pct / 100;
                if (!is_finite($legSize) || $legSize <= 0) {
                    return self::closedTradeMetricsUnsupported('wielkość częściowej nogi przekracza zakres liczbowy', $before);
                }

                $priorResult = self::number($partial['result'] ?? null);
                $manualOverride = $partial['resultOverride'] ?? false;
                if (!is_bool($manualOverride)) {
                    return self::closedTradeMetricsUnsupported('znacznik override częściowego zamknięcia jest nieprawidłowy', $before);
                }
                if ($manualOverride) {
                    if ($priorResult === null) {
                        return self::closedTradeMetricsUnsupported('wynik override częściowego zamknięcia jest nieprawidłowy', $before);
                    }
                    $legResult = $priorResult;
                } else {
                    $partialExit = self::number($partial['price'] ?? null);
                    if ($partialExit !== null && $partialExit > 0) {
                        $legResult = ($partialExit - $entry) * $legSize * $directionSign;
                    } elseif ($priorResult !== null) {
                        // Older UI versions stored manual leg results without an
                        // override flag; keep that value and make its provenance explicit.
                        $partial['resultOverride'] = true;
                        $legResult = $priorResult;
                    } else {
                        return self::closedTradeMetricsUnsupported('brak ceny dla częściowego zamknięcia bez override', $before);
                    }
                }
                if (!is_finite($legResult)) {
                    return self::closedTradeMetricsUnsupported('wynik częściowej nogi przekracza zakres liczbowy', $before);
                }

                $partial['result'] = self::roundTradeMetric($legResult);
                $partialResult += $partial['result'];
                if (!is_finite($partialResult)) {
                    return self::closedTradeMetricsUnsupported('suma częściowych wyników przekracza zakres liczbowy', $before);
                }
                $updatedPartials[] = $partial;
                $remaining = max(0.0, $remaining - $legSize);
                if (!is_finite($remaining) || $remaining <= 0) {
                    return self::closedTradeMetricsUnsupported('częściowe zamknięcia nie pozostawiają dodatniej wielkości', $before);
                }
            }
        }

        if ($hasPartials
            || array_key_exists('initialSize', $before)
            || array_key_exists('initialSize', $requested)
            || $sizeChanged) {
            $updated['initialSize'] = self::tradeNumberText($initialSize);
        }
        $updated['size'] = self::tradeNumberText($hasPartials ? $remaining : $initialSize);
        $updated['partials'] = $hasPartials ? $updatedPartials : $partials;
        $updated['partialResult'] = $hasPartials ? self::roundTradeMetric($partialResult) : null;

        if ($hasPartials) {
            if ($hasAggregateOverride) {
                $result = self::roundTradeMetric((float) $aggregateOverride);
            } elseif ($exit !== null && $exit > 0) {
                $finalLegResult = ($exit - $entry) * $remaining * $directionSign;
                if (!is_finite($finalLegResult)) {
                    return self::closedTradeMetricsUnsupported('wynik końcowej nogi przekracza zakres liczbowy', $before);
                }
                $result = self::roundTradeMetric($partialResult + $finalLegResult);
            } elseif (!$pnlInputsChanged) {
                $result = self::number($before['result'] ?? null);
            } else {
                return self::closedTradeMetricsUnsupported('brak ceny wyjścia dla pozostałej części transakcji', $before);
            }
        } elseif ($hasAggregateOverride) {
            $result = self::roundTradeMetric((float) $aggregateOverride);
        } elseif ($exit !== null && $exit > 0) {
            $calculatedResult = ($exit - $entry) * $initialSize * $directionSign;
            if (!is_finite($calculatedResult)) {
                return self::closedTradeMetricsUnsupported('wynik przekracza zakres liczbowy', $before);
            }
            $result = self::roundTradeMetric($calculatedResult);
        } else {
            $result = self::number($before['result'] ?? null);
        }
        $updated['result'] = $result;

        // Trade sizes are persisted in base units. Display-unit metadata is not
        // multiplied here; risk uses the original position size, not the remainder.
        $stopLossRaw = $updated['sl'] ?? null;
        $stopLoss = self::isEmptyLevel($stopLossRaw) ? null : self::number($stopLossRaw);
        $risk = $stopLoss !== null ? abs($entry - $stopLoss) * $initialSize : null;
        if ($risk !== null && (!is_finite($risk) || $risk < 0)) {
            return self::failure('bad_number', 'Ryzyko transakcji musi być skończoną liczbą nieujemną.', 422, self::tradeId($before));
        }
        $capital = 0.0;
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (!is_array($account) || ($account['include'] ?? true) === false) {
                continue;
            }
            $balance = self::number($account['balance'] ?? null) ?? 0.0;
            $currency = strtoupper((string) ($account['currency'] ?? 'USDT'));
            $fx = match ($currency) {
                'EUR' => 1.08,
                'PLN' => 0.25,
                default => 1.0,
            };
            $capital += $balance * $fx;
        }
        $riskPct = $risk !== null && $capital > 0 ? $risk / $capital * 100 : null;
        if (($riskPct !== null && !is_finite($riskPct))
            || ($risk !== null && !is_finite($risk))) {
            return self::failure('bad_number', 'Procent ryzyka musi być skończoną liczbą.', 422, self::tradeId($before));
        }
        $updated['risk'] = $risk === null ? null : self::roundTradeMetric($risk);
        $updated['riskPct'] = $riskPct === null ? null : self::roundTradeMetric($riskPct);
        $rawResult = self::number($result);
        $r = $rawResult !== null && $risk !== null && $risk > 0 ? $rawResult / $risk : null;
        if ($r !== null && !is_finite($r)) {
            return self::closedTradeMetricsUnsupported('wielkość R przekracza zakres liczbowy', $before);
        }
        $updated['r'] = $r === null ? null : self::roundTradeMetric($r);

        return $updated;
    }

    /**
     * @param list<mixed> $beforePartials
     */
    private static function partialPnlInputsChanged(array $beforePartials, mixed $afterPartials): bool
    {
        if (!is_array($afterPartials) || !array_is_list($afterPartials)
            || count($beforePartials) !== count($afterPartials)) {
            return true;
        }
        foreach ($beforePartials as $index => $before) {
            $after = $afterPartials[$index] ?? null;
            if (!is_array($before) || !is_array($after)) {
                return true;
            }
            foreach (['pct', 'price', 'resultOverride'] as $field) {
                if (!self::sameTradeField($field === 'pct' ? 'size' : $field, $before[$field] ?? null, $after[$field] ?? null)) {
                    return true;
                }
            }
            if (($before['resultOverride'] ?? false) === true
                && !self::sameTradeField('result', $before['result'] ?? null, $after['result'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private static function tradeId(array $trade): string
    {
        return trim((string) ($trade['id'] ?? ''));
    }

    private static function tradeNumberText(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
        return $text === '' || $text === '-' ? '0' : $text;
    }

    private static function roundTradeMetric(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * @return array{error:string,message:string,status:int,tradeId?:string}
     */
    private static function closedTradeMetricsUnsupported(string $reason, array $trade): array
    {
        return self::failure(
            'closed_trade_metrics_unsupported',
            'Nie można bezpiecznie przeliczyć metryk zamkniętej transakcji: ' . $reason . '.',
            409,
            self::tradeId($trade)
        );
    }

    private static function domainFieldsChanged(array $before, array $after): bool
    {
        foreach (self::EXECUTION_FIELDS as $field) {
            if (!self::sameTradeField($field, $before[$field] ?? null, $after[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private static function sameTradeField(string $field, mixed $before, mixed $after): bool
    {
        if ($field === 'dir') {
            $beforeDirection = self::normalizeDirection($before);
            $afterDirection = self::normalizeDirection($after);
            if ($beforeDirection !== null && $afterDirection !== null) {
                return $beforeDirection === $afterDirection;
            }
        }
        if ($field === 'pair' && is_string($before) && is_string($after)) {
            return strtoupper(trim($before)) === strtoupper(trim($after));
        }
        if ($field === 'result' && self::isEmptyResult($before) && self::isEmptyResult($after)) {
            return true;
        }
        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            $beforeNumber = self::number($before);
            $afterNumber = self::number($after);
            if ($beforeNumber !== null && $afterNumber !== null) {
                return $beforeNumber === $afterNumber;
            }
        }
        return self::sameValue($before, $after);
    }

    private static function sameTrade(array $left, array $right): bool
    {
        $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
        foreach ($keys as $key) {
            if (array_key_exists($key, $left) !== array_key_exists($key, $right)) {
                return false;
            }
            if (array_key_exists($key, $left)
                && !self::sameTradeField((string) $key, $left[$key], $right[$key])) {
                return false;
            }
        }
        return true;
    }

    private static function sameValue(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            if (!is_finite((float) $left) || !is_finite((float) $right)) {
                return false;
            }
            if (is_int($left) && is_float($right)) {
                return floor($right) === $right && sprintf('%.0f', $right) === (string) $left;
            }
            if (is_float($left) && is_int($right)) {
                return floor($left) === $left && sprintf('%.0f', $left) === (string) $right;
            }
            return $left === $right;
        }
        if (!is_array($left) || !is_array($right) || array_is_list($left) !== array_is_list($right)
            || count($left) !== count($right)) {
            return false;
        }
        if (array_is_list($left)) {
            foreach ($left as $index => $value) {
                if (!self::sameValue($value, $right[$index])) {
                    return false;
                }
            }
            return true;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::sameValue($value, $right[$key])) {
                return false;
            }
        }
        return true;
    }

    private static function hasExactTradeInTrash(array $state, array $trade): bool
    {
        foreach ((array) ($state['trash'] ?? []) as $entry) {
            if (is_array($entry)
                && (string) ($entry['kind'] ?? '') === 'trade'
                && is_array($entry['record'] ?? null)
                && self::sameTrade($entry['record'], $trade)) {
                return true;
            }
        }
        return false;
    }

    private static function hasExactTradeInState(array $state, array $trade): bool
    {
        foreach ((array) ($state['trades'] ?? []) as $candidate) {
            if (is_array($candidate) && self::sameTrade($candidate, $trade)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $trades
     */
    private static function consumeExactTrade(array &$trades, array $trade, bool $executionOnly = false): bool
    {
        foreach ($trades as $index => $candidate) {
            if ($executionOnly
                ? !self::executionChanged($trade, $candidate)
                : self::sameTrade($candidate, $trade)) {
                array_splice($trades, $index, 1);
                return true;
            }
        }
        return false;
    }

    private static function isEmptyLevel(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 'brak' || $value === '—' || $value === '-';
    }

    private static function isEmptyResult(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_string($value) && in_array(mb_strtolower(trim($value)), ['brak', '—', '-'], true));
    }

    private static function normalizeNumberText(mixed $value): ?string
    {
        if (is_bool($value) || $value === null || !is_scalar($value)) {
            return null;
        }
        $text = str_replace(["\u{00a0}", ' '], '', trim((string) $value));
        $text = preg_replace('/[$€£%x×]/iu', '', $text) ?? $text;
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            if ($comma > $dot) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($comma !== false) {
            $text = str_replace(',', '.', $text);
        }
        if (!is_numeric($text) || !is_finite((float) $text)) {
            return null;
        }
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:e([+-]?\d+))?$/i', $text, $matches) !== 1) {
            return strtolower($text);
        }
        $exponent = (int) ($matches[4] ?? 0);
        if (abs($exponent) > 300) {
            return strtolower($text);
        }
        $sign = $matches[1] === '-' ? '-' : '';
        $whole = $matches[2] !== '' ? $matches[2] : '0';
        $fraction = $matches[3] ?? '';
        $digits = $whole . $fraction;
        $decimalPosition = strlen($whole) + $exponent;
        if ($decimalPosition <= 0) {
            $whole = '0';
            $fraction = str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $whole = $digits . str_repeat('0', $decimalPosition - strlen($digits));
            $fraction = '';
        } else {
            $whole = substr($digits, 0, $decimalPosition);
            $fraction = substr($digits, $decimalPosition);
        }
        $integer = ltrim($whole, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $isZero = $integer === '0' && $fraction === '';
        return ($sign !== '' && !$isZero ? $sign : '')
            . $integer
            . ($fraction !== '' ? '.' . $fraction : '');
    }

    /**
     * @return array{error:string,message:string,status:int,tradeId?:string}
     */
    private static function failure(string $error, string $message, int $status = 422, ?string $tradeId = null): array
    {
        $failure = ['error' => $error, 'message' => $message, 'status' => $status];
        if ($tradeId !== null && $tradeId !== '') {
            $failure['tradeId'] = $tradeId;
        }
        return $failure;
    }
}
