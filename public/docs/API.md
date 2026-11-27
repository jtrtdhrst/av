<!-- Wygenerowane z docs/API.md przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->

# Alpha Vault — API i logowanie

Wersja backendu: **4.2.0** · PHP: **8.3** · baza: **MySQL/MariaDB** · sesje: **HttpOnly cookie** · OAuth: **Discord, Oura**

Aplikacja jest serwowana z tego samego originu co API. Frontend zapisuje cały stan użytkownika przez `PUT /api/state`; `localStorage` jest tylko fallbackiem offline i jest namespace'owany po `user.id` (`alphavault.state.v3.<id>.*`).

## 1. Adresy

| Środowisko | Bazowy URL |
| --- | --- |
| Lokalne | `http://127.0.0.1:4173` |
| Produkcja | `https://alphavault.pl` |

API: `/api/*` · logowanie: `/auth/*` · UI: `/` · dokumentacja: `/docs/`.

Serwer MCP działa na osobnej domenie `https://mcp.alphavault.pl`; jego OAuth,
narzędzia i publiczne `GET /health` opisuje [MCP.md](https://github.com/miloszspyrka-code/av/blob/main/docs/MCP.md). Pole health
`revision` pokazuje SHA przekazane przez chroniony workflow jako
`MCP_BUILD_SHA`; nie jest kryptograficzną atestacją integralności runtime.

## 2. Logowanie Discord

### 2.1. Przepływ

1. Użytkownik klika **Zaloguj przez Discord**.
2. `GET /auth/discord?remember=1` tworzy jednorazowy `state` w `oauth_states` i cookie `av_oauth`.
3. Redirect do `https://discord.com/api/oauth2/authorize` ze scope'ami:

   ```text
   identify guilds.members.read
   ```

4. Discord wraca na `GET /auth/discord/callback?code=...&state=...`.
5. Serwer sprawdza `state` (DB + cookie), wymienia `code` na token i pobiera `GET https://discord.com/api/users/@me`.
6. Sprawdza członkostwo i rolę:

   ```text
   GET https://discord.com/api/v10/users/@me/guilds/{DISCORD_GUILD_ID}/member
   Authorization: Bearer <access_token>
   ```

7. Brak członkostwa lub roli → HTTP 403, bez sesji.
8. Chwilowy błąd Discord (429 po 3 próbach, 5xx, timeout): konto już zweryfikowane (`role_verified=1`) dostaje sesję z grace; nowe konto → HTTP 503 z prośbą o ponowną próbę.
9. Sukces → upsert `users`, sesja w MySQL, cookie `av_session`, redirect `/#/pulpit`.

### 2.2. Weryfikacja roli

Wymagane są `DISCORD_GUILD_ID` i `DISCORD_REQUIRED_ROLE_ID`. Backend **fail closed**: brak konfiguracji oznacza odmowę dostępu.

Logowanie sprawdza rolę tokenem użytkownika (`guilds.members.read`). Cykliczna weryfikacja aktywnych sesji (co 5 min) używa `DISCORD_BOT_TOKEN`:

```text
GET https://discord.com/api/v10/guilds/{DISCORD_GUILD_ID}/members/{user_id}
Authorization: Bot <DISCORD_BOT_TOKEN>
```

Brak roli lub członkostwa usuwa wszystkie sesje użytkownika (`access.role_revoked`). Błąd Discorda nie odbiera dostępu osobom już zweryfikowanym. Szczegóły: [DISCORD.md](https://github.com/miloszspyrka-code/av/blob/main/docs/DISCORD.md#3a-cykliczna-weryfikacja-roli-co-5-min).

### 2.3. Tryb demo

Gdy `ALLOW_DEMO_LOGIN=true` (tylko lokalnie/CI):

```text
POST /auth/demo
Content-Type: application/json

{"remember": true}
```

Każda przeglądarka dostaje **własne** konto demo. Identyfikator jest trzymany w cookie `av_demo` (HttpOnly, SameSite=Lax, 30 dni), a w bazie jako `users.discord_id = demo-<24 hex>`. Dzięki temu dwie osoby na tym samym serwerze nie widzą swoich dzienników, a powrót w tej samej przeglądarce zachowuje dane.

Produkcja: `ALLOW_DEMO_LOGIN=false`, endpoint zwraca 403.

### 2.4. Wylogowanie

```text
POST /auth/logout
GET  /auth/logout
```

Usuwa sesję z MySQL i czyści cookie. GET przekierowuje na `/`.

### 2.5. Cookie sesji

| Atrybut | Wartość |
| --- | --- |
| Nazwa | `av_session` |
| Wartość | losowy token 32 B; w DB tylko SHA-256 |
| HttpOnly | tak |
| SameSite | `Lax` |
| Secure | tak przy HTTPS |
| Max-Age | 30 dni |
| Path | `/` |

Cookie demo (tylko `ALLOW_DEMO_LOGIN=true`): `av_demo` — losowy identyfikator 24 hex, HttpOnly, SameSite=Lax, 30 dni. Wiąże przeglądarkę z jej kontem demo; nie zawiera danych użytkownika.

## 3. Endpointy API

Wszystkie odpowiedzi API są JSON. Błędy:

```json
{ "error": "revision_conflict", "message": "Dane zmienione w innej karcie.", "revision": 12 }
```

### 3.1. `GET /api/health`

Publiczny. Sprawdza MySQL i konfigurację.

```json
{
  "ok": true,
  "service": "alpha-vault",
  "version": "4.2.0",
  "db": { "ok": true, "version": "11.8.6-MariaDB", "latencyMs": 1 },
  "uptime": 123,
  "now": "2026-11-12T14:30:00Z",
  "discordConfigured": true,
  "roleCheckConfigured": true,
  "roleRecheckConfigured": true,
  "roleRecheck": {
    "configured": true,
    "intervalSeconds": 300,
    "status": "success",
    "lastAttemptAt": "2026-11-12 18:20:01",
    "lastSuccessAt": "2026-11-12 18:20:02",
    "lastError": null,
    "checked": 12,
    "revoked": 0,
    "errors": 0,
    "stale": false
  },
  "eventsConfigured": true,
  "demoAllowed": false,
  "sync": {
    "status": "success",
    "lastSuccessAt": "2026-11-12 18:15:02",
    "eventsCount": 3,
    "stale": false
  }
}
```

Baza niedostępna → `503 {"ok":false,"error":"db_unavailable"}`.

`sync` to skrót stanu synchronizacji Discord: `status` (`success`/`error`/`never`), `lastSuccessAt` (UTC), `eventsCount` i `stale` (brak sukcesu > 15 min). Jest publiczny, bo zawiera wyłącznie metadane operacyjne — bez tokenów, treści wydarzeń i danych użytkowników.

`roleRecheck` to stan cronu weryfikacji roli (co 5 min): `status` (`success`/`partial`/`error`/`never`/`skipped`), liczniki `checked`/`revoked`/`errors` i `stale`. `roleRecheckConfigured` jest `true`, gdy ustawiono guild, rolę i `DISCORD_BOT_TOKEN`.

`academyMaterials` to publiczny, beztreściowy status sync materiałów Akademii. Zawiera wyłącznie stan kanałów z allowlisty i liczniki; zawartość materiałów wymaga zalogowanego konta z aktywnym planem `full`.

### 3.2. `GET /api/me`

Publiczny. Zawsze `200`, także bez sesji.

```json
{
  "authenticated": true,
  "discordConfigured": true,
  "roleCheckConfigured": true,
  "roleRecheckConfigured": true,
  "roleRecheckSeconds": 300,
  "eventsConfigured": true,
  "demoAllowed": false,
  "isAdmin": false,
  "db": "serwer445119_alphavault",
  "user": {
    "id": 1,
    "username": "kasia",
    "globalName": "Kasia",
    "avatarUrl": "https://cdn.discordapp.com/avatars/...",
    "email": null,
    "isDemo": false,
    "joinedAt": "2026-03-01 08:12:00",
    "subscription": {
      "plan": "full",
      "accessUntil": "2026-12-03",
      "expired": false,
      "roleVerified": true
    }
  }
}
```

### 3.3. `GET /api/state`

Wymaga sesji (`401` bez). Zwraca stan JSON i rewizję.

```json
{
  "state": { "version": 2, "user": {}, "trades": [], "...": "..." },
  "revision": 12,
  "bytes": 41487,
  "updatedAt": "2026-11-12T14:30:00Z",
  "userId": 42
}
```

Nowe konto Discord: `state=null`. Konto demo bez stanu: seed z `db/demo-state.json`. Pole `userId` wiąże odpowiedź z sesją; klient odrzuca stan, jeśli nie należy do zalogowanego konta.

Historyczny mojibake (UTF-8 odczytany jako Windows-1250) jest naprawiany przy odczycie. Jeśli stan się zmieni, serwer zapisuje poprawiony JSON z powrotem do `user_states`, zwiększa `revision` i zapisuje `audit_log.state.repair_encoding`. Poprawny polski tekst i ASCII nie są zmieniane.

### 3.4. `PUT /api/state`

Wymaga sesji i poprawnego `Origin`. Akceptowany jest origin z `APP_URL` oraz jego bliźniaczy host `www` (np. `https://www.alphavault.pl`); `www` i tak przekierowuje 301 na kanoniczny host przez `.htaccess`. Przed zapisem serwer naprawia historyczny mojibake w stringach stanu, więc do MySQL nie trafia podwójnie zakodowany tekst.

```json
{
  "state": { "...": "..." },
  "baseRevision": 12,
  "ownerId": 42
}
```

`ownerId` jest opcjonalny dla zgodności, ale klient zawsze go wysyła. Jeśli `ownerId` nie zgadza się z sesją (np. w karcie zalogowano inne konto), serwer zwraca `409 session_changed` i nie zapisuje stanu.

Przed zapisem wspólne `TradeRules` sprawdza przejście transakcji. Nowa transakcja ręczna wymaga prawidłowego kierunku, daty, pary, dodatnich `entry` i `size`, wymaganego `sl` po właściwej stronie ceny wejścia oraz `tp` po właściwej stronie, jeśli jest podany. Klient nie może pominąć wymogu SL przez ustawienie `source: "CSV"` lub innej wartości; wyjątek braku SL dotyczy wyłącznie zaufanej ścieżki importera, gdy źródło nie dostarcza tej danej. Broker i jednostka formularza (`units`, `quote`, `lots`) wynikają z konta; loty wymagają jawnego mnożnika kontraktu, a `trade.size` jest przechowywane w kanonicznych jednostkach bazowych. API i MCP walidują przejście całego stanu, a importer waliduje mapowany rekord. `long`/`short` są normalizowane do `l`/`s`, pusty wynik do `null`; każdy znany wynik liczbowy (w tym `0`) lub poprawny `closedAt` oznacza zamknięcie. Brak `closedAt` pozostaje nieznany i nigdy nie jest uzupełniany automatycznie. Historyczny ręczny rekord bez SL można zamknąć, jeżeli bazowe pola wykonania pozostają niezmienione. ID istniejącej transakcji i zewnętrzne identyfikatory importu są niezmienne; wykonania importowane są chronione, ale zamknięte ręczne wykonania można korygować i przeliczać. Jawny `resultOverride` zastępuje przeliczony wynik łączny; adnotacje pozostają edytowalne. Kalendarz i statystyki PnL grupują partiale według `partials[].at`, końcową część według `closedAt`, a przy braku czasu realizacji używają `trade.date`. Zapis odrzucony walidacją nie zmienia stanu ani rewizji. Semantyczny no-op zachowuje `revision` i `updatedAt`; `updatedAt` jest zwracane w ISO 8601 UTC.

Odpowiedź:

```json
{ "ok": true, "revision": 13, "bytes": 41500, "updatedAt": "2026-11-12T14:31:00Z", "userId": 42 }
```

| Kod | Znaczenie |
| --- | --- |
| 200 | zapisano |
| 400 | `state` nie jest obiektem |
| 401 | brak sesji |
| 403 | złe `Origin` albo próba zmiany wykonania/identyfikatora zewnętrznego transakcji importowanej |
| 409 | nieaktualne `baseRevision`, `session_changed`, uszkodzony poprzedni stan (`state_corrupt`), próba zmiany ID istniejącej transakcji |
| 422 | nieprawidłowy kierunek, data, para (`invalid_pair`), liczby, brak wymaganej SL, SL/TP, `closedAt`, kształt listy transakcji/kosza albo nowy wpis transakcji w koszu bez dokładnego źródła w aktywnym stanie (`trade_trash_source_invalid`) |
| 413 | przekroczony `MAX_STATE_BYTES` |
| 429 | limit 180 zapisów/min |

### 3.5. `DELETE /api/state`

Czyści stan zalogowanego użytkownika. `200 {"ok":true}`.

### 3.6. `DELETE /api/account`

Trwale usuwa użytkownika (kaskadowo sesje i stan) i czyści cookie. `200 {"ok":true}`.

### 3.7. `GET /api/me/export`

Załącznik JSON z profilem, rewizją i całym stanem.

### 3.8. `GET /api/events`

Wymaga sesji. Zwraca wydarzenia Discord zsynchronizowane przez CRON oraz stan synchronizacji.

```json
{
  "events": [],
  "live": [],
  "consultations": [],
  "unassigned": [],
  "nextConsultation": null,
  "sync": {
    "lastAttemptAt": "2026-11-12 18:00:00",
    "lastSuccessAt": "2026-11-12 18:00:00",
    "status": "success",
    "eventsCount": 3,
    "updatedCount": 3,
    "durationMs": 240,
    "lastHttpStatus": 200,
    "lastError": null,
    "stale": false
  }
}
```

Każde wydarzenie zawiera m.in. `id`, `url` (kanoniczny link `https://discord.com/events/{guild_id}/{event_id}`), `name`, `description`, `entityType`, `status`, `startAt` (UTC ISO), `startLocal` (`Europe/Warsaw` z offsetem), `startLabel`, `localDate`, `localTime`, `weekday`, `endAt`, `durationMinutes`, `host`, `userCount`, `category`, `categorySource`, `recurrenceRule`. `url` jest `null` wyłącznie dla rekordów z identyfikatorem spoza formatu Snowflake (fixtury testowe). `raw_payload` nie jest udostępniany.

### 3.9. `GET /api/macro-events`

Wymaga sesji. Zwraca publikacje makro z MySQL oraz stan synchronizacji. CRON pobiera bieżący tydzień ForexFactory w oknie niedziela 00:00–następna niedziela 00:00 UTC oraz poprzedni pełny tydzień i pełne tygodnie +1, +2 i +3 TradingView, liczone od poniedziałku 00:00 UTC; końce okien są wyłączne. Dzięki pobieraniu poprzedniego tygodnia także cold-start pustej bazy od razu wypełnia historię. Przy nakładaniu okien ForexFactory ma priorytet, a wydarzenia TradingView poza jego oknem są zachowywane. API filtruje rekordy od 14 dni wstecz do około 35 dni naprzód; górna granica jest limitem odpowiedzi, nie gwarancją dostępności feedu poza pełnym tygodniem TV +3. Błąd TradingView zachowuje ostatnie dobre wydarzenia i zwraca `sync.status=partial`; błąd ForexFactory zwraca `sync.status=error`. Oba stany udostępniają `sync.lastError` i nie usuwają poprzednich danych. UI sygnalizuje synchronizację częściową lub nieudaną.

```json
{
  "events": [
    {
      "id": "6f1c...",
      "source": "forexfactory",
      "name": "Core CPI m/m",
      "description": "Prognoza 0.2% · poprzednio 0.3%",
      "currency": "USD",
      "impact": 3,
      "startAt": "2026-11-10T13:30:00Z",
      "startIso": "2026-11-10T08:30:00-05:00",
      "forecast": "0.2%",
      "previous": "0.3%"
    }
  ],
  "sync": {
    "status": "success",
    "lastSuccessAt": "2026-11-12T21:45:00Z",
    "lastError": null,
    "eventsCount": 42,
    "stale": false
  }
}
```

`impact`: `3` = ★★★, `2` = ★★. `startAt` jest w UTC ISO; `startIso` zachowuje oryginalny offset źródła. `status`: `never`, `running`, `success`, `partial` (ForexFactory dla bieżącego tygodnia zapisany, ale feed TradingView dla poprzedniego tygodnia lub tygodni +1…+3 nie jest dostępny) lub `error`; `lastError` zawiera szczegół błędu synchronizacji albo `null`. Sukces z `events: []` czyści jawne okno; błąd API po stronie UI zachowuje ostatni dobry zestaw i wyświetla ostrzeżenie.

### 3.10. `GET /api/admin/events`

Wymaga zalogowania i Discord ID z `ADMIN_DISCORD_IDS`. Zwraca wszystkie rekordy (także soft-deleted), stan synchronizacji i listę do przypisania.

### 3.11. `PUT /api/admin/events/{id}/category`

Wymaga administratora i poprawnego `Origin`.

```json
{ "category": "live" }
```

Dozwolone: `live`, `consultation`, `unassigned`. Ręczne przypisanie (`manual_category`) ma pierwszeństwo przed regułami i przetrwa kolejne synchronizacje.

### 3.12. Urządzenia zdrowotne

Wszystkie endpointy poza ingestem Apple wymagają sesji. Tokeny dostawców są szyfrowane AES-256-GCM i nigdy nie trafiają do odpowiedzi API.

```text
GET    /api/wearables                       lista urządzeń, dzisiejsze metryki, baseline, seria 90 dni, gotowość
POST   /api/wearables/{provider}/sync       ręczna synchronizacja (Oura/Apple)
DELETE /api/wearables/{provider}            odłączenie + usunięcie danych zdrowotnych
POST   /api/wearables/apple/pair            jednorazowy kod parowania dla iPhone (15 min)
POST   /api/wearables/apple/ingest          ingest z iPhone: kod parowania albo Bearer <deviceToken>
```

`GET /api/wearables` zwraca:

```json
{
  "devices": {
    "apple": { "provider": "apple", "label": "Apple Watch · Zdrowie", "configured": true, "canConnect": true, "connected": false, "status": "ready_to_connect" },
    "oura": { "provider": "oura", "label": "Oura Ring", "configured": true, "canConnect": true, "connected": false, "status": "ready_to_connect" }
  },
  "today": {
    "date": "2026-11-12", "sleepMinutes": 312, "hrvMs": 38, "restingHr": 61,
    "bodyBattery": 34, "providerScore": null, "readiness": 65
  },
  "baseline": { "days": 14, "sleepMinutes": 450, "hrvMs": 52, "restingHr": 56 },
  "series": [ { "date": "2026-10-14", "sleepMinutes": 450, "hrvMs": 52, "restingHr": 56 } ],
  "readiness": {
    "score": 65, "title": "Dobra forma", "class": "win", "source": "devices",
    "components": { "sleep": 65, "hrv": 73, "restingHr": 75, "bodyBattery": 34, "physiology": 65 }
  }
}
```

`status` przyjmuje: `not_configured`, `ready_to_connect`, `pending_authorization`, `connected`, `syncing`, `sync_error`, `token_expired`, `revoked`. `connected` oznacza rzeczywiste połączenie użytkownika, nie samo posiadanie credentials. `dataReady` odróżnia OAuth od gotowości danych. Integracje Garmin i Whoop są wyłączone na razie — nie występują w `devices`, a ich endpointy zwracają 404.

Gotowość urządzenia to ważona średnia dostępnych składników: sen 40%, HRV 25%, tętno 10%, wynik regeneracji dostawcy 15%, Body Battery 10%. Wagi są renormalizowane do dostępnych danych. Check-in (jeśli istnieje) wnosi 30%: `0,7 × urządzenie + 0,3 × check-in`.

Apple ingest:

```text
POST /api/wearables/apple/ingest
Authorization: Bearer avw_...        # od drugiego żądania

{"deviceName":"iPhone","days":[{"date":"2026-11-12","sleepMinutes":312,"hrvMs":38,"restingHr":61,"bodyBattery":null,"providerScore":null}]}
```

Pierwsze żądanie zawiera `"code":"AV-XXXX-XXXX"` zamiast Bearer. Odpowiedź zawiera `deviceToken` tylko raz. Kod parowania jest jednorazowy, ważny 15 minut. Zakres pól: sen 0–1440 min, HRV 0–400 ms, tętno 20–220, Body Battery i wynik dostawcy 0–100; maks. 90 dni na żądanie.

Synchronizacja OAuth2:

```text
GET /auth/wearables/oura/start
GET /auth/wearables/oura/callback?code=...&state=...
```

`state` jest jednorazowy (10 min), powiązany z użytkownikiem i dostawcą. Oura używa PKCE S256 (`code_verifier` w `wearable_oauth_states`). Po sukcesie redirect na `/?connected={provider}#cialo`; błąd synchronizacji nie blokuje połączenia i jest widoczny w `lastError`. Odświeżanie tokenów jest serializowane blokadą `GET_LOCK` na `(user_id, provider)`; nowy refresh token jest zapisywany atomowo, a wygasły refresh token zwraca `reauth_required`.

### 3.12. `GET /api/connections`

Wymaga sesji. Zwraca połączenia użytkownika bez sekretów — tylko maskę klucza, status, uprawnienia i licznik importu.

```json
{
  "configured": true,
  "metaApiConfigured": true,
  "connections": [
    {
      "id": 12,
      "provider": "okx",
      "label": "OKX",
      "accountRef": "123456",
      "keyMask": "abcd••••wxyz",
      "permissions": "read-only",
      "status": "connected",
      "lastSyncAt": "2026-11-12 20:00:00",
      "lastSyncStatus": "success",
      "lastError": null,
      "importedCount": 2,
      "platform": null,
      "serverRef": null
    }
  ]
}
```

`configured:false` oznacza brak `INTEGRATIONS_KEY` na serwerze. Endpoint nie zwraca poświadczeń, pełnych kluczy ani hasła inwestora.

### 3.13. `POST /api/connections`

Wymaga sesji i poprawnego `Origin`. Body:

```json
{
  "provider": "okx",
  "fields": { "apiKey": "...", "secretKey": "...", "passphrase": "..." }
}
```

Obsługiwane providery: `weex`, `okx`, `bingx`, `mt4`, `mt5`. Dla MetaTrader pola: `platform`, `server`, `login`, `password`, `type`.

Serwer:

1. waliduje klucz na żywo u dostawcy,
2. dla `okx` i `bingx` odrzuca klucz z prawem handlu lub wypłat (`422 read_only_required`); `weex` akceptuje każdy klucz, który przechodzi odczyt konta, i zapisuje `permissions: "any"`,
3. szyfruje poświadczenia AES-256-GCM (`INTEGRATIONS_KEY`),
4. zapisuje połączenie i zwraca `201` z zamaskowanym kluczem.

MetaTrader jest akceptowany wyłącznie przez MetaApi, gdy `investorMode:true`. Hasło z prawem handlu jest odrzucane, a konto usuwane z MetaApi.

### 3.14. `POST /api/connections/sync`

Wymaga sesji i `Origin`. Synchronizuje wszystkie połączenia użytkownika (opcjonalnie `{"provider":"okx"}`). Zwraca podsumowanie per połączenie: `fetched`, `imported`, `skipped`, `done`, `error`.

Import jest idempotentny — deduplikacja po `external_id` w `exchange_imports` i `extId` w stanie dziennika. Zapytania markerów są dzielone na paczki po maksymalnie 500 ID. Dla każdego połączenia stan dziennika, rewizja i markery `exchange_imports` są zapisywane w jednej transakcji. Błąd walidacji mapowanej transakcji wycofuje cały batch tego połączenia (w tym wcześniejsze rekordy i markery); synchronizacja wielu połączeń nie jest jedną globalną transakcją. E2E sprawdza 501 rekordów w jednym imporcie. Zapisana data transakcji i `closedAt` pochodzą z czasu źródłowego zamknięcia; `importedAt` jest czasem importu w UTC. OKX zgłasza `history_page_limit`, gdy przekroczy limit stron, oraz `history_cursor_missing`, gdy pełna strona nie ma poprawnego kursora; oba błędy przerywają całe połączenie bez częściowego zapisu.

### 3.15. `POST /api/connections/{id}/sync`

Synchronizuje jedno połączenie. Używane po podłączeniu klucza i przez przycisk „Synchronizuj teraz”. Błąd walidacji transakcji zwraca `422 invalid_trade`; wycofany zostaje cały batch tego połączenia, a nie tylko wadliwy rekord. OKX zwraca `502 history_page_limit`, gdy limit stron dostawcy może oznaczać niekompletną historię, lub `502 history_cursor_missing`, gdy pełna strona nie ma nowego kursora. Żaden z tych błędów nie zapisuje częściowego wyniku.

### 3.16. `DELETE /api/connections/{id}`

Wymaga sesji i `Origin`. Usuwa połączenie i poświadczenia. Transakcje pozostają w dzienniku; deduplikacja zachowuje się przy ponownym podłączeniu.

### 3.17. `POST /api/connections/mt/pair`

Wymaga sesji i `Origin`. Body `{"platform":"mt5"}`. Zwraca jednorazowo kod `AV-XXXX-XXXX` do EA. W bazie znajduje się tylko SHA-256 kodu.

### 3.18. `POST /api/mt/ea`

Publiczny endpoint dla Expert Advisor, uwierzytelniany kodem parowania (nie sesją). Body:

```json
{
  "code": "AV-7K3Q-92XD",
  "trades": [
    {
      "ticket": "123456",
      "symbol": "EURUSD",
      "side": "buy",
      "openTime": 1763000000,
      "closeTime": 1763003600,
      "lots": 0.5,
      "openPrice": 1.1,
      "closePrice": 1.105,
      "profit": 250,
      "commission": -2,
      "swap": -1
    }
  ]
}
```

Maks. 200 transakcji w żądaniu. Deduplikacja po `ticket`. Kod można wymienić, generując nowy; nowy kod zastępuje poprzednie połączenie MetaTrader.

### 3.19. `GET /api/health` — integracje

Pole `integrations` zawiera:

```json
{
  "configured": true,
  "metaApiConfigured": true,
  "sync": {
    "status": "success",
    "lastAttemptAt": "2026-11-12 20:00:00",
    "lastSuccessAt": "2026-11-12 20:00:00",
    "connections": 3,
    "imported": 7,
    "lastError": null
  }
}
```

### 3.20. `GET /api/quotes`

Publiczny, read-only pasek notowań i ceny instrumentów w kalkulatorze. Dane pochodzą z Yahoo Finance Chart API (bez klucza) i są cache'owane w pliku `cache/quotes.json`.

- `QUOTES_TTL` (domyślnie 60 s) ogranicza liczbę zapytań do dostawcy; `0` wymusza odświeżenie przy każdym żądaniu.
- `symbols` (opcjonalnie): lista etykiet instrumentów po przecinku, np. `?symbols=SOL/USDT,EUR/USD`. Etykiety z UI są normalizowane (`NAS100 · Nasdaq 100` → `NAS100`). Bez parametru zwracany jest zestaw tickera.
- Sukces: `{"quotes":[...],"updatedAt":"2026-11-12T20:00:00Z","stale":false}`.
- `stale=true` oznacza odpowiedź z cache po błędzie dostawcy albo brak części symboli.
- Notowanie starsze niż 15 min znika z odpowiedzi zamiast być pokazywane jako aktualne.
- Błąd dostawcy bez cache: `{"quotes":[],"updatedAt":null,"stale":true}` (HTTP 200).

```json
{
  "quotes": [
    {"symbol": "BTC", "price": 100000.0, "changePercent": 1.5},
    {"symbol": "EUR/USD", "price": 1.087, "changePercent": -0.2}
  ],
  "updatedAt": "2026-11-12T20:00:00Z",
  "stale": false
}
```

Symbole tickera: `BTC`, `ETH`, `SOL`, `NAS100`, `US500`, `XAUUSD`, `EUR/USD`, `OIL`.
Instrumenty kalkulatora: `BTC/USDT`, `ETH/USDT`, `SOL/USDT`, `XRP/USDT`, `BNB/USDT`, `DOGE/USDT`, `LINK/USDT`, `AVAX/USDT`, `GBP/USD`, `USD/JPY`, `AUD/USD`, `USD/CHF`, `USD/CAD`, `NZD/USD`, `EUR/GBP`, `US30`, `GER40`, `UK100`, `JPN225`, `XAGUSD`.

### 3.21. `GET /api/mcp`

Panel MCP: status, scope i oczekujące propozycje zmian.

- Wymaga sesji panelu (`401` bez logowania).
- Zwraca `{endpoint, enabled, writesEnabled, scopes, proposals[], proposalCount, audit[]}`.
- `enabled` i `writesEnabled` są konfiguracją prezentowaną przez panel Alpha Vault; endpoint nie sprawdza zdalnej dostępności ani konfiguracji `mcp.alphavault.pl`.
- `scopes` odzwierciedla zakresy serwera MCP: `alphavault.read` (46 narzędzi odczytu oraz — gdy
  `MCP_WRITES_ENABLED=true` — 33 narzędzia zapisu) oraz `alphavault.health` (dane fizjologiczne
  urządzeń, osobna zgoda). Nie ma zakresu `alphavault.write`; przy włączonym zapisie 30 operacji
  działa bezpośrednio, a `propose_change`/`approve_proposal` są opcjonalne.
- Odmowa jedynego żądanego scope’u `alphavault.health` kończy autoryzację błędem `access_denied`;
  brak zgody nie jest zastępowany zakresem `alphavault.read`.
- `proposals` zawiera `proposalId`, `kind`, `target`, `baseRevision`, `diffHash`, `changes`,
  `before`, `after`, `diff`, `status`, `canApprove`, `approvalCodeActive`, `approvalCodeExpiresAt`,
  `createdAt`, `expiresAt`, `clientName`.
- Payloady obrazów są redagowane z `before`, `after`, `diff`, podglądów i cache replay;
  odpowiedzi zachowują hash i rozmiar. Jawny odczyt screenów z `includeImages=true` opisuje
  [MCP.md](https://github.com/miloszspyrka-code/av/blob/main/docs/MCP.md) i pozostaje osobnym, opt-in narzędziem.
- Propozycje wygasłe są oznaczane jako `expired` przy odczycie.

### 3.22. `POST /api/mcp/proposals/decide`

Zatwierdzenie lub odrzucenie propozycji MCP.

```json
{ "id": "uuid", "action": "approve" }
```

- Wymaga sesji panelu, `Origin` zgodnego z aplikacją i rate limitu 60/min.
- `approve` wykonuje transakcję MySQL z `SELECT ... FOR UPDATE`, sprawdza `base_revision`,
  target, TTL i rozmiar stanu, zapisuje audyt; `revision` rośnie tylko wtedy, gdy stan faktycznie
  się zmienił.
- `reject` nie zmienia `user_states`.
- Błędy: `not_found` (404), `proposal_closed` (409), `proposal_expired` (409),
  `revision_conflict` (409), `state_too_large` (413).
- Endpoint nie jest dostępny przez MCP. `approve_proposal` wymaga `confirmed: true` i zgodnego `diffHash`;
  klient powinien wysłać flagę po zgodzie użytkownika, ale serwer nie analizuje treści rozmowy.
  Kod z 3.23 jest opcjonalny i jest sprawdzany, gdy agent go poda.

### 3.23. `POST /api/mcp/proposals/{id}/approval-code`

Generuje jednorazowy kod do zatwierdzenia propozycji w rozmowie z agentem.

```json
{ "proposalId": "uuid", "code": "ABCD-EFGH", "expiresAt": "2026-11-13T21:40:00Z" }
```

- Wymaga sesji panelu, `Origin` zgodnego z aplikacją i rate limitu 10/min.
- Kod ma 8 znaków z alfabetu bez `0/O/1/I`, TTL 10 min (nie dłużej niż TTL propozycji).
- W bazie zapisany jest wyłącznie SHA-256; kod nie jest dostępny przez MCP.
- Nowe żądanie unieważnia poprzedni kod; 5 błędnych prób unieważnia kod.
- Błędy: `not_found` (404), `proposal_closed` (409), `proposal_expired` (409).

### 3.24. `GET /api/academy/materials`

Odczyt materiałów z lokalnej bazy. Synchronizację uruchamia wyłącznie istniejący cron; endpoint nie inicjuje żądań do Discorda.

- Wymaga sesji oraz aktualnego entitlementu `full` z potwierdzoną rolą. Konto demo, `solo` lub wygasłe otrzymuje `403`.
- Wyłącznie metoda `GET`; inne metody zwracają `405` i `Allow: GET`.
- Parametry: `category` (`all` albo zatwierdzona kategoria), `q` (wyszukiwanie po tytule/opisie), `limit` (1–50, domyślnie 10), `cursor` (podpisany, nieprzezroczysty kursor keyset).
- Odpowiedź zawiera `items`, `nextCursor` i beztreściowy `sync`. IDs Discord są stringami; `discordUrl` jest kanonicznym linkiem do prawdziwej wiadomości źródłowej. Załączniki, URL-e CDN i pełne payloady Discord nie są zwracane.
- `descriptionMessageId` jest stringiem albo `null`, gdy materiał nie ma osobnej wiadomości opisu. `sync` zawiera globalny status, checkpointy i stany wszystkich 13 zatwierdzonych kanałów, w tym potwierdzenie dostępu, licznik importu i wersję grupowania; nie zawiera treści wiadomości.
- Wyniki ukrywają się dla kanału bez potwierdzonego źródła, dostępu do historii lub treści. HTTP 200 z pustą historią nie oznacza zweryfikowania treści ani `ready=true`.
- Błędy: `401` bez sesji, `403` bez entitlementu, `400 invalid_category` / `invalid_cursor`, `405 method_not_allowed`.

Skrócony przykład odpowiedzi (pełne `sync.channels` zawiera wszystkie 13 kanałów):

```json
{
  "items": [{
    "guildId": "1186734786388176916",
    "channelId": "1499560912611446784",
    "sourceMessageId": "600000000000000001",
    "descriptionMessageId": null,
    "category": "Forex",
    "title": "Lekcja",
    "description": null,
    "authorId": null,
    "authorName": null,
    "publishedAt": "2026-11-24T09:00:00Z",
    "materialType": "video",
    "discordUrl": "https://discord.com/channels/1186734786388176916/1499560912611446784/600000000000000001"
  }],
  "nextCursor": null,
  "sync": { "status": "content_unverified", "ready": false }
}
```

## 4. Bezpieczeństwo

- brak haseł użytkowników; tylko Discord OAuth2,
- sesje: token losowy, w DB tylko SHA-256, cookie HttpOnly/SameSite/Secure,
- tokeny urządzeń: AES-256-GCM z kluczem poza repo; token urządzenia Apple w DB tylko jako SHA-256,
- odłączenie urządzenia usuwa tokeny i wszystkie metryki zdrowotne z bazy,
- CSRF: `Origin` dla mutujących żądań + SameSite=Lax,
- rate limiting w MySQL,
- CSP, HSTS, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`,
- `config.json` w ukrytym `.app`, `600`, deny w `.htaccess`,
- brak sekretów w frontendzie i repo.

## 5. Zmienne konfiguracji

| Zmienna | Opis |
| --- | --- |
| `APP_URL` | publiczny URL, np. `https://alphavault.pl` |
| `APP_ENV` | `production` na produkcji |
| `SESSION_SECRET` | 64+ losowych znaków |
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | MySQL |
| `DISCORD_CLIENT_ID` | Application ID |
| `DISCORD_CLIENT_SECRET` | OAuth2 Client Secret |
| `DISCORD_REDIRECT_URI` | `https://alphavault.pl/auth/discord/callback` |
| `DISCORD_GUILD_ID` | serwer Alpha Moves |
| `DISCORD_REQUIRED_ROLE_ID` | rola Alpha |
| `DISCORD_BOT_TOKEN` | bot read-only do scheduled events i cyklicznej weryfikacji roli |
| `DISCORD_ROLE_RECHECK_SECONDS` | próg rechecku roli; minimum 300 s, domyślnie 300 |
| `ADMIN_DISCORD_IDS` | Discord ID administratorów (po przecinku) |
| `DISCORD_DEFAULT_PLAN` | `solo` lub `full` |
| `DISCORD_TRIAL_DAYS` | 0 = bez daty końca |
| `ALLOW_DEMO_LOGIN` | `false` na produkcji |
| `TRUST_PROXY` | `true` tylko za zaufanym reverse proxy; wtedy rate limit używa `X-Forwarded-For`. Domyślnie `false` (bezpieczne `REMOTE_ADDR`). |
| `MAX_STATE_BYTES` | limit stanu (12 MiB) |
| `QUOTES_BASE` | bazowy URL dostawcy notowań (domyślnie Yahoo Finance) |
| `QUOTES_TTL` | TTL cache notowań w sekundach, 0–3600 (domyślnie 60) |
| `QUOTES_CACHE` | ścieżka pliku cache (domyślnie `cache/quotes.json`) |
| `ACADEMY_RECAP_WINDOW_MINUTES` | okno łączenia krótkiego opisu z materiałem (1–30 min, domyślnie 30) |
| `WEARABLES_ENCRYPTION_KEY` | wymagany klucz AES dla tokenów urządzeń; brak fallbacku na `SESSION_SECRET` |
| `OURA_CLIENT_ID`, `OURA_CLIENT_SECRET` | aplikacja Oura; scope `daily`, PKCE S256 |
| `WEARABLES_ENCRYPTION_KEY_PREVIOUS` | poprzedni klucz AES dla rotacji tokenów urządzeń |
| `OURA_AUTHORIZE_URL`, `OURA_TOKEN_URL`, `OURA_API_BASE` | opcjonalne nadpisania endpointów Oura |
| `WHOOP_*`, `GARMIN_*` | nieaktywne — integracje Whoop i Garmin są wyłączone; zmienne pozostają dla szybkiego przywrócenia |
