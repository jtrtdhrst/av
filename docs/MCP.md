# AlphaVault MCP 2.1 — OAuth 2.1, narzędzia, propozycje i zatwierdzanie kodem

> **Status:** kod w `main` (MCP 2.1), endpoint produkcyjny `https://mcp.alphavault.pl/mcp`.
> Domyślnie `MCP_ENABLED=true` na domenie MCP, `MCP_WRITES_ENABLED=false` — narzędzia zapisujące
> nie są wtedy widoczne, a scope `alphavault.write` nie może zostać przyznany.
>
> Repozytorium: `miloszspyrka-code/av` · Aplikacja: <https://alphavault.pl>

## 1. Architektura

MCP jest osobnym front controllerem PHP 8.3 na `mcp.alphavault.pl`. Korzysta z tej samej bazy MySQL
i tego samego konta LH.pl co AlphaVault. Nie ma stałego procesu Node.js.

```text
ChatGPT / Claude / Grok
          │ HTTPS + OAuth 2.1 Bearer
          ▼
https://mcp.alphavault.pl/mcp
          ├── OAuth 2.1 AS (authorize, token, register, revoke, discovery)
          ├── MCP Streamable HTTP (JSON-RPC 2.0)
          ├── Tools (odczyt + propozycje zmian)
          ├── Prompts (scenariusze pracy)
          └── MySQL: users, user_states, mcp_*, macro_events, exchange_*
```

Zasady:

- tylko dane właściciela tokenu OAuth; `user_id` nigdy nie jest argumentem narzędzia,
- brak zewnętrznej synchronizacji Discord, giełd i makro z MCP,
- agent nie zatwierdza własnych propozycji: `approve_proposal` wymaga jednorazowego kodu wydanego
  wyłącznie w sesji panelu AlphaVault,
- dane giełdowe są niezmienne,
- puste dane = puste tablice lub `null`, bez tekstów zastępczych.

## 2. OAuth 2.1

| Endpoint | Rola |
| --- | --- |
| `GET /.well-known/oauth-protected-resource` | metadata resource servera |
| `GET /.well-known/oauth-authorization-server` | metadata AS |
| `POST /oauth/register` | Dynamic Client Registration |
| `GET/POST /oauth/authorize` | ekran zgody i decyzja |
| `POST /oauth/token` | code + PKCE S256, refresh |
| `POST /oauth/revoke` | unieważnienie tokenu |
| `GET /oauth/login` | logowanie Discord na domenie MCP |
| `GET /oauth/discord/callback` | powrót z Discorda |
| `GET /oauth/connected` | aktywne połączenia i unieważnienie |

- Authorization Code + PKCE `S256`, public client (`none`) lub `client_secret_post`.
- `/oauth/authorize` przyjmuje żądanie autoryzacji także metodą `POST` (tak robi ChatGPT):
  parametry z ciała formularza są normalizowane `303` do `GET` tego samego endpointu, więc logowanie,
  zgoda i decyzja mają jeden przebieg. Decyzja na ekranie zgody nie wymaga kontroli `Origin`:
  ciasteczko sesji jest `SameSite=Lax` (cross-site `POST` go nie wyśle), a stan zmienia wyłącznie
  poprawny token CSRF z wiersza `mcp_consents` albo podpisany `consent_token` związany z sesją
  i użytkownikiem. Żądanie autoryzacji nic nie zmienia, a jego poprawność wymusza serwer OAuth
  (`client_id` + dokładny `redirect_uri`).
- `/oauth/connected/revoke` jest zwykłym formularzem HTML, więc broni go token CSRF podpisany
  kluczem sesji (`hmac(sessionId)`); endpoint akceptuje dosłowne `Origin: null`, obcy `Origin`
  nadal dostaje `403 bad_origin`. `mcp/public/.htaccess` ustawia
  `Referrer-Policy: strict-origin-when-cross-origin`, bo przy `no-referrer` Chromium i Firefox
  zerują `Origin` formularzy `POST` – wcześniej blokowało to unieważnianie dostępu.
- Dokładne `redirect_uri`; allowlista hostów: `chatgpt.com`, `chat.openai.com`, `platform.openai.com`,
  `claude.ai`, `grok.com`, `x.ai` (localhost tylko poza produkcją).
- Access token: JWT RS256, 1 h, `aud=https://mcp.alphavault.pl/mcp`; w bazie `mcp_access_tokens`.
- Refresh token: 30 dni, rotacja przy każdym użyciu; ponowne użycie unieważnia rodzinę.
- tokeny wyłącznie w `Authorization: Bearer`; brak tokenu → `401` z `WWW-Authenticate`,
  brak scope → `403 insufficient_scope`.

### 2.1. Scope

| Scope | Znaczenie |
| --- | --- |
| `alphavault.read` | 46 narzędzi odczytu, w tym lista i status propozycji |
| `alphavault.health` | dostęp do danych zdrowotnych z podłączonego urządzenia (sen, HRV, tętno); osobna zgoda |
| `alphavault.write` | 20 narzędzi: 18 propozycji zmian, zatwierdzenie kodem i odrzucenie; **nie** zapis bezpośredni |

`alphavault.write` jest przyznawany tylko wtedy, gdy:

1. `MCP_WRITES_ENABLED=true`,
2. klient poprosi o ten scope,
3. użytkownik zaznaczy na ekranie zgody „Zezwalam na przygotowywanie propozycji zmian”.

Brak checkboxa = token tylko z `alphavault.read`.

`alphavault.health` jest przyznawany tylko wtedy, gdy klient poprosi o ten scope, a użytkownik
zaznaczy osobną zgodę. Bez tego zakresu narzędzia gotowości zwracają `health=null` i
`healthAccess=scope_missing`; dodatkowo wymagane jest aktywne połączenie urządzenia
(`healthAccess=no_device` bez niego).

### 2.2. Feature flags

| Zmienna | Domyślnie | Działanie |
| --- | --- | --- |
| `MCP_ENABLED` | `false` (aplikacja), `true` (domena MCP) | kill switch całego MCP |
| `MCP_WRITES_ENABLED` | `false` | `false` ukrywa 20 narzędzi zapisu (`propose_*`, `create_*_proposal`, `update_*_proposal`, `approve_proposal`, `reject_proposal`), blokuje scope write i bezpośrednie wywołania |
| `MCP_PILOT_DISCORD_IDS` | puste | opcjonalna allowlista pilotowa |

## 3. Narzędzia odczytu (46)

| Narzędzie | Źródło | Uwagi |
| --- | --- | --- |
| `get_profile` | `users` | plan, dostęp, rola, admin |
| `get_dashboard` | `user_states`, `discord_*` | wynik dnia, plan, gotowość, limity, kapitał, sync |
| `list_trades` | `user_states.trades` | filtry i paginacja; bez screenów |
| `get_trade` | `user_states.trades` | szczegóły + recenzja |
| `get_stats` | transakcje zamknięte | netto, WR, PF, expectancy, DD, oceny, setupy |
| `get_events` | `discord_scheduled_events` | UTC + `Europe/Warsaw`; bez `raw_payload` |
| `get_day_plan` | `user_states.plans` | plan lub `null` |
| `list_setups` | `user_states.setups` | zasady + statystyki |
| `get_macro_calendar` | `macro_events` | `forecast`, `previous`, `impact`; bez `raw_payload` |
| `get_prop_challenges` | `user_states.prop.challenges` | metryki z `PropChallengeService` (parytet z `public/js/prop.js`) |
| `get_checkin_history` | `user_states.checkins` | nastrój, energia, notatka, score |
| `get_accounts` | `user_states.accounts` | typ, broker, waluta, saldo, `include`, `primary`; bez poświadczeń |
| `get_capital_summary` | konta + `flows` | kapitał, start, przepływy, waluty; przeliczenia USDT orientacyjne |
| `get_connection_status` | `exchange_connections` | status, permissions, `lastSyncAt`, `importedCount`, oczyszczony błąd |
| `get_trade_context` | transakcja + recenzja + setup + plan | zgodność z planem, statystyki setupu; brak rekordu → `found=false` |
| `list_pending_proposals` | `mcp_proposals` | oczekujące propozycje z diffem i `diffHash`; bez kodu |
| `get_proposal_status` | `mcp_proposals` | status, diff, `diffHash`, TTL, czy aktywny jest kod; nieistniejące ID → `found=false` |
| `get_day_plan_history` | `user_states.plans` | historia planów, status, rynki, limity, skrót zgodności; paginacja |
| `get_day_plan_compliance` | plan dnia + transakcje | zgodność z reguł planu, naruszone reguły, wynik obu grup |
| `get_day_plan_context` | plan + transakcje + makro + check-in + ryzyko | pełny kontekst dnia w jednym wywołaniu |
| `get_trade_journal_context` | transakcja + recenzja + setup + plan | pełny kontekst transakcji, w tym ryzyko i screeny (metadane) |
| `get_trade_review` | `user_states.tradeReviews` | recenzja albo `reviewAvailable=false` |
| `get_trade_screenshots` | `user_states.trades[].screens` | metadane zawsze; obrazy tylko `includeImages=true`, maks. 2 × 200 KB |
| `get_setup_details` | `user_states.setups` | zasady, potwierdzenie, unieważnienie, min. R:R, przykłady |
| `get_setup_performance` | transakcje po nazwie setupu | WR, PF, expectancy, DD, A/B/C, błędy, instrumenty, sesje |
| `get_readiness_context` | check-in + urządzenia | dane zdrowotne tylko z `alphavault.health` i zgodą urządzenia |
| `get_checkin_trading_correlation` | check-iny + transakcje | grupy, korelacje Pearsona, ograniczenia statystyczne |
| `get_risk_rules` | `settings` | limity, blokada, zasady osobiste, checklista |
| `get_protection_status` | `settings` + dzisiejsze transakcje + `overrides` | tryb ochronny, powód, blokada, obejścia |
| `get_risk_violations` | transakcje + plan + ustawienia | naruszenia z oczekiwaną i rzeczywistą wartością oraz istotnością |
| `get_prop_challenge_context` | `user_states.prop` | kontekst challenge'u i metryki silnika |
| `get_prop_challenge_risk` | `PropChallengeService` | budżet ryzyka, bufory, margines, naruszenia |
| `get_performance_by_setup` | transakcje zamknięte | metryki per setup |
| `get_performance_by_emotion` | transakcje zamknięte | metryki per emocja |
| `get_performance_by_session` | transakcje zamknięte | sesje + przedziały godzin w `Europe/Warsaw` |
| `get_performance_by_error` | transakcje zamknięte | koszt błędu i udział w wyniku |
| `get_performance_by_grade` | transakcje zamknięte | A/B/C i indeks jakości ocen |
| `get_performance_by_market` | transakcje zamknięte | klasa rynku i instrument |
| `get_performance_by_day_plan_compliance` | plan + transakcje | zgodne vs niezgodne, tylko ustalone przypadki |
| `get_trade_patterns` | transakcje + plan + naruszenia | wzorce z dowodami, próbą, zakresem i pewnością |
| `get_calculator_result` | `CalculatorService` | te same formuły co kalkulator w panelu |
| `get_account_transactions` | `user_states.flows` | wpłaty i wypłaty, PLN z `fiat * fx`, klasyfikacja podatkowa |
| `get_tax_summary` | `flows` + `tax` | PIT-38 pomocniczo: przychód, koszt, dochód, 19%, strata do przeniesienia |
| `get_macro_event_details` | `macro_events` | UTC i lokalny czas, prognoza, poprzednia wartość; `actual` = null bez danych |
| `get_integration_sync_history` | `exchange_sync_runs` | pobrane, zaimportowane, pominięte, status, oczyszczony błąd |
| `get_badges_progress` | transakcje, plany, setupy, challenge'e | poziomy brąz/srebro/złoto i data zdobycia |

`get_macro_calendar` domyślnie zwraca wydarzenia od dziś w przód; pełną historię udostępnia
`includePast=true`. Liczby są rozdzielone: `matchedCount`/`total` (spełniają filtr),
`returnedCount` (w tej odpowiedzi), `storedCount` (wszystkie w bazie), `sync.eventsCount`
(z ostatniej synchronizacji). `get_trade`, `get_trade_context` i `get_proposal_status`
zwracają `found=false` dla nieistniejącego identyfikatora — bez wyjątku i bez ujawniania cudzych danych.

Każde narzędzie ma `inputSchema` (`additionalProperties: false`), `outputSchema`, `annotations`
i `_meta.securitySchemes`. Limity: 100 pozycji na stronę (200 dla naruszeń), zakres dat do 370 dni,
obrazy 2 × 200 KB.

## 4. Propozycje zmian (18) i decyzje (2)

| Narzędzie | Model docelowy |
| --- | --- |
| `propose_create_trade` | nowa ręczna transakcja: `pair`, `dir`, `entry`, `size`, `sl`, `tp`, `setup`, `emotion`, `grade`, `checklist`, `checkTotal`, `errors`, `note`, `date`, `account` |
| `propose_close_trade` | zamknięcie ręcznej pozycji: `tradeId`, `exitPrice`; wynik i R liczone jak w UI |
| `propose_trade_review` | `note`, `grade`, `setup`, `emotion`, `checklist`, `errors`; osobny `tradeReviews` |
| `propose_save_day_plan` | `market`, `bias`, `why`, `levels`, `ifMain/thenMain`, `ifAlt/thenAlt`, `riskPercent`, `maxTrades`, `until`, `notDo`; bez `approvedAt` |
| `propose_save_checkin` | `mood`, `energy`, opcjonalnie `note`; score jak w `public/js/state.js` |
| `propose_save_setup` | `id?`, `name`, `tf`, `grade`, `rules` (2–5), `bad`, `seed`; bez usuwania Playbooka |
| `propose_save_prop_challenge` | konfiguracja zgodna z `user_states.prop.challenges` i `public/js/prop.js` |
| `propose_save_account` | `id?`, `name`, `exchange`, `type`, `currency`, `include`, `primary`, `lev`, `goal`, `date`; bez salda i kapitału startowego |
| `propose_save_capital_flow` | `id?`, `date`, `account`, `type` (deposit/withdrawal), `amount`, `currency`, `fiat`, `fx`, `note`; wpis historii, bez zmiany salda |
| `create_day_plan_proposal` | plan z `markets` (1–3), `bias`, `reason`, `levels`, `scenarioA`/`scenarioB` (condition + action), `riskPercent`, `maxTrades`, `until`, `rulesNotToDo`; bez `approvedAt` |
| `create_trade_proposal` | pełny wpis ręczny: konto, instrument, kierunek, data i czas, wejście, wyjście, wielkość, SL, TP, opłata, setup, ocena, emocje, błędy, notatka, checklista; wynik i R liczy serwer |
| `update_trade_proposal` | `tradeId` + `changes` (jawne pola); dane finansowe transakcji giełdowej zablokowane; wynik i R przeliczane |
| `create_setup_proposal` | nazwa, opis, `entryRules`, interwały, potwierdzenie, unieważnienie, wymagany SL, `minRR`, `exitRules`, `examples` |
| `update_setup_proposal` | `setupId` + `changes`; diff pokazuje zmienione reguły i metadane, bez usuwania setupu |
| `create_checkin_proposal` | `mood`, `energy`, `note?`, `date?` (dziś lub wczoraj); score jak w panelu |
| `create_risk_rules_proposal` | `changes` + `reason`; wyłącznie utrzymanie lub zaostrzenie zabezpieczeń |
| `create_prop_challenge_proposal` | `templateId` + nadpisania parametrów; bez zmian transakcji i salda |
| `update_prop_challenge_proposal` | `challengeId` + `changes`; bez zmian historycznych transakcji |
| `approve_proposal` | `proposalId`, `expectedDiffHash`, `confirmationCode`, `idempotencyKey` |
| `reject_proposal` | `proposalId`, `idempotencyKey` |

Wszystkie propozycje wymagają `idempotencyKey` (8–120 znaków). Ponowienie z tym samym kluczem i tym
samym wnioskiem zwraca istniejącą propozycję. Ten sam klucz z inną treścią → `409 idempotency_conflict`.
Decyzje mają własny `idempotencyKey`; powtórzenie zwraca zapisany wynik z `duplicate=true`.

### 4.1. Przykłady wywołań

```json
{ "tool": "propose_create_trade", "arguments": {
  "pair": "BTCUSDT", "dir": "long", "entry": 62000, "size": 0.01,
  "sl": 61000, "tp": 64000, "setup": "Pullback w trendzie", "emotion": "Spokój",
  "grade": "A", "checklist": 7, "checkTotal": 7, "note": "Wejście z planu",
  "account": "WEEX", "idempotencyKey": "trade-2026-11-13-btc-1"
} }
```

```json
{ "tool": "propose_close_trade", "arguments": {
  "tradeId": "manual_1a2b3c4d", "exitPrice": 63500, "idempotencyKey": "close-2026-11-13-btc-1"
} }
```

```json
{ "tool": "propose_trade_review", "arguments": {
  "tradeId": "manual_1a2b3c4d", "note": "Wejście zgodne z planem, wyjście przed TP.",
  "grade": "B", "setup": "Pullback w trendzie", "emotion": "Skupienie",
  "checklist": 6, "errors": ["Wyjście przed TP"], "idempotencyKey": "review-2026-11-13-btc-1"
} }
```

```json
{ "tool": "propose_save_day_plan", "arguments": {
  "date": "2026-11-13", "market": "BTC", "bias": "long", "why": "Retest wsparcia",
  "levels": [{ "type": "support", "price": "61000", "note": "Dzienny poziom" }],
  "ifMain": "Retest 61k", "thenMain": "Wejście long po potwierdzeniu",
  "ifAlt": "Zejście pod 60k", "thenAlt": "Brak transakcji",
  "riskPercent": 1, "maxTrades": 2, "until": "16:00", "notDo": "Brak wejścia bez potwierdzenia",
  "idempotencyKey": "plan-2026-11-13"
} }
```

```json
{ "tool": "propose_save_checkin", "arguments": {
  "mood": "Spokój", "energy": 4, "note": "Dobry sen", "idempotencyKey": "checkin-2026-11-13"
} }
```

```json
{ "tool": "propose_save_setup", "arguments": {
  "name": "Retest wybicia", "tf": "M15", "grade": "A",
  "rules": ["Wybicie z konsolidacji", "Retest poziomu", "SL pod retestem"],
  "bad": false, "idempotencyKey": "setup-retest-1"
} }
```

```json
{ "tool": "propose_save_prop_challenge", "arguments": {
  "name": "FTMO 100k", "firm": "FTMO", "templateId": "ftmo-2step", "phase": "Phase 1",
  "account": "FTMO", "currency": "USD", "accountSize": 100000, "startBalance": 100000,
  "targetPct": 10, "dailyLossPct": 5, "maxLossPct": 10, "drawdownType": "static",
  "dailyBasis": "initial", "minDays": 4, "startDate": "2026-11-13",
  "idempotencyKey": "prop-ftmo-100k-1"
} }
```

```json
{ "tool": "propose_save_account", "arguments": {
  "name": "FTMO", "exchange": "FTMO", "type": "Futures", "currency": "USD",
  "include": true, "primary": false, "lev": "1:100", "goal": "Przejść Phase 1",
  "idempotencyKey": "account-ftmo-1"
} }
```

```json
{ "tool": "propose_save_capital_flow", "arguments": {
  "type": "deposit", "amount": 1000, "account": "WEEX", "currency": "PLN",
  "fiat": 4000, "fx": 1, "note": "P2P", "idempotencyKey": "flow-deposit-2026-11-13"
} }
```

```json
{ "tool": "get_calculator_result", "arguments": {
  "balance": 10000, "riskPercent": 1, "direction": "long", "entry": 100,
  "stopLoss": 95, "takeProfit": 120, "leverage": 10
} }
```

Odpowiedź: `maxLoss=100`, `positionValue=2000`, `marginRequired=200`, `potentialProfit=400`,
`riskReward=4`, `breakevenWinRatePct=20` — te same liczby co kalkulator w panelu.

```json
{ "tool": "create_trade_proposal", "arguments": {
  "pair": "BTC/USDT", "dir": "long", "entry": 62000, "exit": 63500, "size": 0.01,
  "sl": 61000, "tp": 64000, "fee": 1.2, "setup": "Pullback w trendzie",
  "grade": "A", "checklist": 7, "checkTotal": 7, "note": "Wejście z planu",
  "date": "2026-11-13", "time": "09:30", "account": "WEEX",
  "idempotencyKey": "trade-2026-11-13-btc-2"
} }
```

```json
{ "tool": "create_risk_rules_proposal", "arguments": {
  "changes": { "maxRisk": 0.5, "maxLosses": 1 },
  "reason": "Zaostrzenie zasad po dwóch stratach z rzędu.",
  "idempotencyKey": "risk-rules-2026-11-13"
} }
```

```json
{ "tool": "get_proposal_status", "arguments": { "proposalId": "6f1c2a9e-..." } }
```

```json
{ "tool": "approve_proposal", "arguments": {
  "proposalId": "6f1c2a9e-...", "expectedDiffHash": "<diffHash z propose_*>",
  "confirmationCode": "ABCD-EFGH", "idempotencyKey": "approve-6f1c2a9e-1"
} }
```

```json
{ "tool": "reject_proposal", "arguments": {
  "proposalId": "6f1c2a9e-...", "idempotencyKey": "reject-6f1c2a9e-1"
} }
```

## 5. Przepływ zatwierdzania

### 5.1. Panel (zawsze dostępny)

```text
Agent AI → propose_* → walidacja → mcp_proposals (pending)
                                      │
                                      ▼
                          Panel AlphaVault → podgląd diff
                                      │
                 Zatwierdź / Odrzuć (sesja + CSRF)
                                      │
                                      ▼
                     transakcja MySQL + SELECT ... FOR UPDATE
                     → nowa revision + audit → user_states
```

### 5.2. Zatwierdzanie w rozmowie kodem jednorazowym

MCP nie ma mechanizmu, któremu serwer może zaufać, że model rzeczywiście pokazał użytkownikowi diff.
Dlatego zgoda jest dowodzona kodem, którego agent nie zna:

```text
1. Agent → propose_* → pending + diffHash
2. Użytkownik → panel → "Kod do rozmowy"
   POST /api/mcp/proposals/{id}/approval-code (sesja + CSRF)
   → kod XXXXX-XXXX, TTL 10 min, jednorazowy, w bazie tylko SHA-256
3. Użytkownik wpisuje kod w rozmowie
4. Agent → approve_proposal(proposalId, expectedDiffHash, confirmationCode, idempotencyKey)
5. Serwer sprawdza owner, status, TTL, base_revision, diffHash, kod i limit prób
   → transakcja MySQL + FOR UPDATE → zapis → revision + audit
```

Zasady:

- TTL propozycji: 30 minut; TTL kodu: 10 minut, nie dłużej niż TTL propozycji.
- Kod jest jednorazowy. Nowe żądanie kodu unieważnia poprzedni.
- Pięć błędnych prób unieważnia kod; kolejne wywołanie wymaga nowego kodu.
- `userConfirmed=true`, zdanie „potwierdzam” ani żaden argument agenta nie są dowodem zgody.
- Kod powstaje wyłącznie w sesji panelu; nie ma narzędzia MCP, które go tworzy.
- Zatwierdzenie ponownie sprawdza: właściciela, status, TTL, `base_revision`, `diffHash`, target
  i rozmiar stanu.
- `diffHash` pochodzi z `propose_*`/`get_proposal_status` i obejmuje `kind`, target oraz diff.
- Konflikt rewizji → `409 revision_conflict`; użytkownik prosi agenta o nową propozycję.
- Brak częściowego zapisu: błąd = rollback.
- Decyzja jest idempotentna po `idempotencyKey`; powtórzenie zwraca zapisany wynik.
- Odrzucenie (`reject_proposal`) nie zmienia danych i nie wymaga kodu; wymaga jednoznacznego
  polecenia użytkownika.

## 6. Model danych

Tabele addytywne (szczegóły w [BAZA-DANYCH.md](BAZA-DANYCH.md)):

| Tabela | Przeznaczenie |
| --- | --- |
| `mcp_clients` | klienci DCR |
| `mcp_auth_codes` | kody autoryzacyjne |
| `mcp_access_tokens` | access tokeny (JWT, `jti`) |
| `mcp_refresh_tokens` | refresh tokeny i rotacja |
| `mcp_login_requests` | stan logowania Discord |
| `mcp_consents` | ekran zgody, CSRF, TTL |
| `mcp_proposals` | propozycje zmian, diff, idempotencja, TTL |
| `mcp_audit` | audyt agenta i użytkownika |
| `mcp_idempotency` | rezerwa pod przyszłe operacje bezpośrednie |

`mcp_proposals`:

| Kolumna | Opis |
| --- | --- |
| `id` | UUID |
| `user_id` | właściciel |
| `client_name` | klient OAuth |
| `kind` | `manual_trade`, `close_trade`, `trade_review`, `trade_update`, `day_plan`, `checkin`, `setup`, `prop_challenge`, `account`, `capital_flow`, `risk_rules` |
| `target_ref` | `trade:new`, `trade:<id>`, `plan:<data>`, `checkin:<data>`, `setup:new`, `setup:<id>`, `prop:new`, `prop:<id>`, `account:new`, `flow:new`, `settings:risk` |
| `base_revision` | rewizja `user_states` w chwili utworzenia |
| `payload_hash` | hash wniosku do idempotencji |
| `before_json`, `after_json`, `diff_json` | podgląd zmiany |
| `status` | `pending`, `approved`, `rejected`, `expired` |
| `idempotency_key` | unikalny per użytkownik |
| `diff_hash` | SHA-256 z `kind`, target i diffu; dowód, że zatwierdzany jest ten sam diff |
| `approval_code_hash` | SHA-256 jednorazowego kodu; nigdy nie wraca przez MCP |
| `approval_code_expires_at`, `approval_code_attempts`, `approval_code_used_at` | TTL, limit 5 prób, jednorazowość |
| `decision_key`, `decision_result_json` | idempotencja decyzji i zapisany wynik |
| `expires_at`, `decided_at`, `decided_by` | TTL i audyt |

## 7. Ochrona danych giełdowych

MCP nigdy nie zmienia: `entry`, `exit`, `exitPrice`, `price`, `fill`, `fillPrice`, `result`, `pnl`,
`size`, `qty`, `quantity`, `sl`, `tp`, `leverage`, `fee`, `fees`, `balance`, `start`, `equity`,
`source`, `exchangeId`, `externalId`.

Allowlisty są per operacja, nie globalne:

- `propose_create_trade` jawnie dopuszcza `entry`, `size`, `sl`, `tp` **wyłącznie** dla nowej
  transakcji ręcznej (`source = Ręcznie`). Każde inne pole finansowe → `403 exchange_data_readonly`.
- `create_trade_proposal` dopuszcza pełny wpis ręczny (także `exit`, `fee`, `date`, `time`) i sam
  liczy wynik oraz R. `update_trade_proposal` przy transakcji z giełdy dopuszcza wyłącznie pola
  dziennika (`setup`, `grade`, `emotion`, `errors`, `note`, `checklist`, `checkTotal`, `session`).
- `create_risk_rules_proposal` może tylko utrzymać albo zaostrzyć zabezpieczenia: nie wyłącza
  wyłącznika, nie podnosi `maxRisk`, `maxLosses`, `maxDailyLoss` i nie skraca blokady.
- `propose_close_trade` dopuszcza `exitPrice` **wyłącznie** dla otwartej transakcji ręcznej; odmawia
  dla `exchange_imports` i źródła innego niż ręczne.
- `propose_trade_review` nie przyjmuje żadnego pola finansowego; recenzja trafia do
  `user_states.tradeReviews`.
- `propose_save_account` nie przyjmuje `balance` ani `start`; nowe konto ma saldo 0, a edycja
  zachowuje obecne saldo.
- `propose_save_capital_flow` zapisuje wpis historii (`flows`), ale nie zmienia `accounts.balance`,
  `start`, equity ani prowizji. Wpis jest deklaracją użytkownika dla historii i PIT-38.
- Zatwierdzenie ponownie sprawdza target; usunięty setup, challenge, konto lub wpis →
  `409 target_missing`, bez wskrzeszania rekordu.

## 8. Panel AlphaVault

Sekcja „Mentor AI → Integracje MCP” zawiera listę oczekujących propozycji:

- typ zmiany, klient, data utworzenia, TTL, target,
- czytelny diff `before → after`,
- Zatwierdź / Odrzuć,
- status po decyzji i komunikat konfliktu rewizji.

Endpointy panelu:

| Metoda | Ścieżka | Opis |
| --- | --- | --- |
| `GET` | `/api/mcp` | propozycje pending + audyt + informacje o scope |
| `POST` | `/api/mcp/proposals/decide` | `{id, action: approve\|reject}` |
| `POST` | `/api/mcp/proposals/{id}/approval-code` | jednorazowy kod do zatwierdzenia w rozmowie |

Wymagane: sesja panelu (`requireAuth`), `requireSameOrigin`, rate limit 60/min (kod: 10/min),
własność propozycji. Kod jest pokazywany raz i nigdy nie jest zwracany przez MCP.

## 9. Prompty

| Prompt | Zawartość |
| --- | --- |
| `prepare_trading_day` | dashboard, plan, makro, wydarzenia, gotowość, limity |
| `review_trade` | transakcja, setup, plan, checklista, emocje, błędy |
| `weekly_review` | wyniki, WR, PF, setupy, emocje, błędy, challenge'e |

Prompty nie wykonują zapisu. Mogą zawierać wyłącznie instrukcje i identyfikatory przekazane przez
użytkownika.

## 10. Testy

| Test | Zakres |
| --- | --- |
| `php tests/mcp.test.php` | propozycje, kod jednorazowy, idempotencja decyzji, konflikt rewizji, TTL, ochrona giełdy, target_missing, izolacja, audyt |
| `php tests/mcp-tools.test.php` | silniki bez HTTP i DB: Metrics (null vs zero), plan i zgodność, ryzyko, wydajność per grupa, kalkulator, PIT, odznaki, screeny |
| `node tests/mcp.e2e.mjs` | OAuth, PKCE, DCR, tokeny, 46 read + 20 write, approve kodem, konflikt diffu, prompts, writes on/off |
| `node tests/mcp-tools.e2e.mjs` | 38 nowych narzędzi: poprawne wywołania, brak danych, nieistniejące ID, walidacja, izolacja, 9 cykli propozycji (diff, approve, reject, expire, idempotencja, konflikt), 6 workflow |
| `node tests/mcp-login.e2e.mjs` | logowanie Discord na mocku: rola, brak roli, duplikat konta |
| `node tests/mcp-ui.e2e.mjs` | panel: diff, kod do rozmowy, approve, reject, reload, trwałość |
| `node tests/prop-parity.mjs` | parytet PHP ↔ JS silnika prop challenge |
| `node tests/mcp-user-service.php` | idempotentne dopasowanie konta po Discord ID |

CI: `.github/workflows/ci.yml` — krok `MCP security tests`, `MCP prop parity`, `MCP E2E`,
`MCP login E2E`, `MCP panel E2E`.

## 11. Wdrożenie

- `alphavault.pl`: `deploy-alphavault.yml` (public + app + db, bez `vendor/`).
- `mcp.alphavault.pl`: `Deploy AlphaVault MCP` → `scripts/deploy-mcp.sh`, ref `main`.
- Deploy MCP nie dotyka `alphavault.pl` ani WordPressa.
- Domyślnie `MCP_WRITES_ENABLED=false`. Pilot wymaga jednocześnie `writes_enabled=true` i niepustego
  `pilot_discord_ids`; skrypt deployu odrzuca włączenie zapisu bez allowlisty.
- Migracja `db/migrations/20261115_mcp_approval.sql` dodaje `diff_hash`, kolumny kodu i decyzji.
- Rollback: `MCP_ENABLED=false`, unieważnienie tokenów, restore `.app` z backupu.
- Włączenie zapisu dla pilota: `MCP_WRITES_ENABLED=true` + ponowne połączenie klienta MCP, żeby
  uzyskał scope `alphavault.write`; stary token zachowuje wyłącznie `alphavault.read`.

## 12. Zmienne środowiskowe MCP

| Zmienna | Domyślnie | Opis |
| --- | --- | --- |
| `MCP_ENABLED` | `false` | główny kill switch |
| `MCP_WRITES_ENABLED` | `false` | propozycje zmian i decyzje |
| `MCP_PILOT_DISCORD_IDS` | puste | opcjonalna allowlista Discord ID; puste = wszyscy z scope write, ustawione = zapis tylko dla pilota |
| `MCP_ISSUER` | `APP_URL` | issuer OAuth |
| `MCP_RESOURCE` | `APP_URL/mcp` | audience/resource |
| `MCP_PRIVATE_KEY_PATH` | `keys/oauth-private.pem` | klucz RSA |
| `MCP_PUBLIC_KEY_PATH` | `keys/oauth-public.pem` | klucz publiczny |
| `MCP_ENCRYPTION_KEY_PATH` | `keys/oauth-encryption.key` | klucz szyfrowania tokenów |
| `MCP_ALLOWED_REDIRECT_HOSTS` | lista klientów AI | allowlista redirect URI |
| `MCP_ACCESS_TOKEN_TTL` | `3600` | sekundy |
| `MCP_REFRESH_TOKEN_TTL` | `2592000` | sekundy |
| `MCP_AUTH_CODE_TTL` | `300` | sekundy |
| `MCP_BODY_LIMIT` | `65536` | bajty |
| `MCP_LOG_FILE` | `logs/mcp.log` | log zgody i błędów |
| `SESSION_COOKIE` | `av_session` / `mcp_session` | cookie sesji |

### 12.1. Prywatność

Dane tradingowe są przekazywane wyłącznie klientowi AI, którego połączenie użytkownik autoryzował.
Polityka prywatności §13.2 opisuje zakres MCP, zgody i wycofanie tokenu:
[/polityka-prywatnosci](https://alphavault.pl/polityka-prywatnosci). Ekran zgody pokazuje zakres
dostępu i link do polityki; token można unieważnić na `/oauth/connected`.

## 13. Powiązania

- [PRD.md](../PRD.md)
- [DOCUMENTATION.md](../DOCUMENTATION.md)
- [API.md](API.md)
- [ARCHITEKTURA.md](ARCHITEKTURA.md)
- [BAZA-DANYCH.md](BAZA-DANYCH.md)
- [DEPLOY.md](DEPLOY.md)
- [TESTOWANIE.md](TESTOWANIE.md)
- [AGENTS.md](../AGENTS.md)
- [Polityka prywatności](https://alphavault.pl/polityka-prywatnosci)
