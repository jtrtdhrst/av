# Changelog

## 3.9.8 - 2026-11-15

### Naprawione
- MCP: unieważnianie dostępu na `/oauth/connected` kończyło się `403 bad_origin` z `origin: "null"`. Strona MCP wysyłała `Referrer-Policy: no-referrer`, a Chromium i Firefox zerują wtedy `Origin` formularzy `POST`. `mcp/public/.htaccess` ustawia teraz `strict-origin-when-cross-origin`, a `POST /oauth/connected/revoke` akceptuje dosłowne `Origin: null`, bo broni go token CSRF podpisany kluczem sesji. Obcy `Origin` nadal dostaje `403`.

### Testy
- `tests/mcp-login.e2e.mjs`: `GET /oauth/connected`, unieważnienie z `Origin: null` (302, token przestaje działać, drugi klient zachowuje dostęp), obcy `Origin` i zły CSRF bez efektu (403).
- `tests/seed-discord-events.php`: wydarzenie live jest zawsze w bieżącym tygodniu `Europe/Warsaw` (klamra do końca tygodnia), więc siatka tygodnia w UI nie jest pusta w niedziele po 22:00.

## 3.9.7 - 2026-11-15

### Dodane
- MCP 2.2: 38 nowych narzędzi — plan dnia (historia, zgodność, kontekst, propozycja), dziennik (kontekst, recenzja, screeny, propozycje transakcji i zmian), Playbook (szczegóły, skuteczność, propozycje), gotowość i korelacje z check-inami, zasady ryzyka i tryb ochronny (status, naruszenia, propozycja zaostrzenia), prop challenge (kontekst, budżet ryzyka, propozycje), analityka (setup, emocja, sesja, błąd, ocena, rynek, zgodność z planem, wzorce), kalkulator Alpha, historia kapitału, PIT-38, szczegóły wydarzenia makro, historia synchronizacji i postęp odznak.
- MCP: 46 narzędzi odczytu i 20 zapisu; rejestr w `McpController`, schematy wejścia w `Schemas`, opisy dla modelu.
- MCP: osobny zakres `alphavault.health` z checkboxem na ekranie zgody; dane zdrowotne tylko przy zgodzie urządzenia.
- MCP: wspólny silnik `Metrics` dla `get_stats` i raportów analitycznych; brak danych daje `null` i `hasData=false`.
- MCP: propozycje `trade_update` i `risk_rules`; `risk_rules` może tylko zaostrzać zabezpieczenia.
- MCP: `get_proposal_status` dla nieistniejącego ID zwraca `found=false` zamiast błędu.
- MCP: `get_macro_calendar` rozdziela `matchedCount`, `returnedCount`, `storedCount` i `sync.eventsCount`.
- Testy: `tests/mcp-tools.test.php` (kontrakty silników) i `tests/mcp-tools.e2e.mjs` (38 narzędzi, 6 workflow, izolacja, cykle propozycji).

### Naprawione
- Metryki bez transakcji zwracały `0`; teraz `null` z `hasData=false`, żeby brak danych nie wyglądał jak wynik.
- Zgodność z planem nie zależy już od zysku: liczą się rynek, kierunek, limit transakcji, ryzyko, godzina i SL.

### Testy
- CI: `MCP tools contract tests`, `MCP tools E2E` oraz istniejące `MCP security`, `MCP E2E`, `MCP login E2E`.

## 3.9.6 - 2026-11-15

### Dodane
- Pulpit: karta „Saldo portfela” z sumą sald, wynikiem od kapitału startowego i skrótem do „Konto i zasady”; kapitał startowy i saldo konta są edytowalne w tabeli.
- Plan dnia: lista wszystkich instrumentów + „Inny rynek…” z własną nazwą obok chipów; bias jako przyciski.
- Kalkulator: wybór portfela ustawia saldo, dźwignię i limit MM z zasad; wybór R:R 1:1/1:2/1:3/1:5 lub własny steruje sugestią SL/TP; stan wraca po przeładowaniu.
- Akademia: „Dołącz” od 15 minut przed startem, przypomnienie 5/15/30/60 min, harmonogram tydzień/miesiąc z nawigacją, kategorie nagrań i odtwarzanie z linku (YouTube w panelu), słownik krypto z wyszukiwaniem, Alpha Testy z wynikiem, złote zasady bezpieczeństwa.
- Statystyki: kalendarz PnL z własnym zakresem 7/30 dni albo miesiąc i nawigacją okresu.
- Kalendarz makro: poprzedni/ten/następny/+2/+3 tydzień i zakres dat w nagłówku.

### Naprawione
- Responsywność: usunięte obcinanie treści (`main{overflow-x:clip}`), gridy `minmax(0,1fr)`, poprawione wiersze poziomów, tabele i pasek górny; na 390 i 1024 px żaden widok nie wystaje poza ekran.
- Stary JS po deployu: `index.php` dokleja do skryptów `?v=<mtime>`, więc service worker nie serwuje poprzedniej wersji, a nowe przyciski (m.in. harmonogram Akademii) działają od pierwszego odświeżenia.
- MCP w ChatGPT: `POST /oauth/authorize` z obcym `Origin` (tak klient wysyła żądanie autoryzacji) nie kończy się już `403 bad_origin` — parametry są normalizowane `303` do `GET`, a decyzja na ekranie zgody opiera się na sesji `SameSite=Lax` i tokenie CSRF, nie na `Origin` przeglądarki.

### Testy
- UI e2e: saldo portfela i edycja salda, select rynku i własny rynek, portfel/MM i R:R w kalkulatorze, kalendarz PnL, harmonogram i materiały Akademii, nawigacja tygodni makro.
- PWA e2e: brak przepełnienia we wszystkich 15 widokach przy 390 i 1024 px (prostokąty elementów + `scrollWidth`).

## 3.9.5 — 2026-11-13

### Naprawione
- Plan dnia: chipy rynku (`BTC/ETH/SOL/NAS100/XAUUSD`) i biasu (`Long/Short/Neutralnie/Nie gram`) mają obsługę kliknięcia; wcześniej zmieniały tylko wygląd w innych miejscach i nie trafiały do stanu.
- Zatwierdzenie i „Skopiuj z wczoraj” czekają na `PUT /api/state`; sukces jest potwierdzany, a błąd zapisu pokazany toastem zamiast cichego pozostawienia zmian tylko w przeglądarce.
- `renderPlan()` nie nadpisuje pól, gdy użytkownik pisze w edytorze planu (render po notowaniach nie kasuje treści).
- Autosave planu wydłużony z 200 ms do 700 ms i połączony z natychmiastowym zapisem akcji; mniej PUT-ów całego stanu.
- `www.alphavault.pl` przekierowuje 301 na `alphavault.pl`, a `requireSameOrigin()` akceptuje bliźniaczy host `www`, więc zapis nie kończy się 403 przy wejściu z `www`.

### Testy
- UI e2e: chipy rynku/biasu, poziomy, zatwierdzenie, odczyt planu z MySQL przez `GET /api/state` i odtworzenie po pełnym reloadzie.
- API e2e: `PUT /api/state` z `Origin` hosta `www.*` = 200, obcy origin nadal 403.

## 3.9.4 — 2026-11-13

### Dodane
- `app/DemoState.php` + `bin/sync-demo-state.php`: daty stanu demo (`db/demo-state.json`) są przesuwane na dziś (`Europe/Warsaw`) z zachowaniem odstępów; seed `GET /api/state` i `tests/reset-demo.php` używają tego samego mechanizmu.
- Wrapper LH Cron `*/5` uruchamia `bin/sync-demo-state.php` co 60 min (throttle `.runtime/demo.stamp`); operacja jest idempotentna i dotyczy wyłącznie kont `is_demo = 1`.

### Zmienione
- Demo po zmianie dnia pokazuje dzisiejsze transakcje, plan dnia i tryb ochronny bez ręcznego resetu stanu.

### Testy
- `tests/demo-state.test.php` (15/15): delta 0, przesunięcie dat, idempotencja, plany jako punkt zaczepienia, przesunięcie wstecz, błędne dane, `Europe/Warsaw`.

## 3.9.3 — 2026-11-13

### Zmienione
- Nagłówek panelu: usunięty techniczny status bazy danych z paska nad aplikacją; pasek pokazuje wyłącznie logowanie, tryb offline albo błąd ostatniego zapisu.
- Chip giełd w nagłówku renderuje się tylko przy aktywnym połączeniu (nazwa giełdy lub MetaTrader i czas synchronizacji); bez połączeń pozostaje ukryty, zamiast „brak połączeń”. Usunięty statyczny placeholder „WEEX · 10:30”.
- Ticker bez zmian funkcjonalnych: `GET /api/quotes` co 60 s, ostatni dobry zestaw przy błędzie.

### Testy
- UI e2e: brak statusu bazy w nagłówku, ukryty chip giełd bez połączeń, ticker z podstawionego API.
- UI integracje e2e: chip giełd pojawia się z „OKX” po połączeniu i jest ukryty bez połączeń.

## 3.9.1 - 2026-11-13

### Zmienione
- Jeden wpis LH Cron `*/5` uruchamia teraz także sync makro (co 15 min) i sync urządzeń (co 30 min) przez throttle `.runtime/*.stamp`; osobne wpisy w panelu nie są potrzebne.

## 3.9.2 — 2026-11-13

### Dodane
- Kalkulator Alpha: cena instrumentu pochodzi z `GET /api/quotes?symbols=…` (Yahoo Finance) i odświeża się co 60 s; ticker i kalkulator korzystają z jednego cache.
- `Quotes` obsługuje instrumenty kalkulatora (pary USDT, FX, indeksy, metale) i normalizuje etykiety UI (`NAS100 · Nasdaq 100` → `NAS100`).

### Zmienione
- Nagłówek panelu: usunięta adnotacja planu i „zapis automatyczny”; status to `POŁĄCZONO Z MYSQL` (błąd zapisu oznaczany osobno).
- Etykieta „Cena live” bez znacznika `demo`; przy braku notowań wartość `—` i komunikat „brak notowań” zamiast ceny statycznej.

### Testy
- `tests/quotes.test.php`: filtr instrumentów, normalizacja etykiet, nieznany symbol.
- `tests/quotes.e2e.mjs`: `?symbols=` pobiera tylko zamówione instrumenty.
- UI e2e: cena live z podstawionego API, brak `demo` w etykiecie i planu w nagłówku.

## 3.9.0 — 2026-11-13

### Naprawione
- Demo: każda przeglądarka ma osobne konto `demo-<hash>` (cookie `av_demo`), koniec współdzielonego `demo-local` i wycieku dziennika między osobami.
- Frontend: cache `localStorage` jest namespace'owany po `user.id` (`alphavault.state.v3.<id>.*`), drafty również; wylogowanie i zmiana konta usuwają prywatny cache, a ekran logowania nie renderuje danych poprzednika.
- `PUT /api/state` przyjmuje `ownerId`; karta z sesją innego konta dostaje `409 session_changed` i nie nadpisuje stanu. Stan z obcym `user.discordId` jest odrzucany również bez `ownerId`.
- Migracje: `php app/db/migrate.php` wykonuje teraz `db/migrations/*.sql` z wersjonowaniem w `schema_migrations` (naprawia brak tabel MCP na czystej bazie).
- Rate limit używa `REMOTE_ADDR`, a `X-Forwarded-For` tylko przy `TRUST_PROXY=true`.

### Bezpieczeństwo
- Kody parowania EA wygasają po 15 minutach, jeśli nie zostały użyte, i po 90 dniach od ostatniej synchronizacji; API nie zwraca prefiksu hasha kodu, a EA nie loguje kodu.
- Parowanie EA nie usuwa połączenia MetaApi; wiersze EA są wyłączone z serwerowego pollingu.
- Nowy kod Apple unieważnia poprzednie nieużyte kody.
- Tokeny MCP mają domyślny TTL 90 dni.

### Testy
- Nowy `tests/isolation.e2e.mjs` — 9/9 scenariuszy izolacji dwóch kont i blokady stanu z obcej karty.
- API 23/23, UI 53/53, integracje 20/20, wearables 42/42, PWA 20/20, MCP 17/17.

## 3.8.1 — 2026-11-12

### Zmienione
- Urządzenia: Garmin Connect i Whoop wyłączone na razie — brak kafelków w „Ciało”, brak wpisów w `GET /api/wearables`, endpointy OAuth/sync/DELETE zwracają 404. Pozostają Apple Watch i Oura Ring.
- Implementacja i konfiguracja Garmin/Whoop zachowane w kodzie (`app/Wearables.php`, `app/Config.php`, zmienne env) do szybkiego przywrócenia.
- Testy urządzeń przepisane na Oura + Apple z asercją braku Garmin/Whoop w API i UI.
- Dokumentacja: API, architektura, baza, funkcje, testy, PRD.

### Testy
- `npm run test:wearables` — OAuth2/PKCE Oura, Apple, rotacja tokenów i klucza, wyłączenie Garmin/Whoop, UI.
- `tests/wearables-config.test.php` — lista dostawców i wymóg klucza szyfrowania.

## 3.8.0 — 2026-11-12

### Dodane
- Cykliczna weryfikacja roli Alpha: cron co 5 minut (`bin/check-discord-roles.php`) sprawdza aktywne sesje botem (`GET /guilds/{id}/members/{user_id}`) i usuwa wszystkie sesje przy braku roli lub członkostwa (`access.role_revoked`).
- `users.role_checked_at`, `role_check_status`, `role_check_error` oraz tabela `role_check_state` z blokadą cronu i licznikami; stan w `/api/health.roleRecheck`.
- Frontend: pełnoekranowa bramka `#roleGate` przy 401 lub `roleVerified=false`, komunikat o wymaganej roli, przycisk „Sprawdź ponownie” i kontrola dostępu co 5 minut.
- `DISCORD_ROLE_RECHECK_SECONDS` (minimum 300 s) i `DISCORD_API_BASE` (tylko testy/mock).

### Zmienione
- Logowanie: brak roli/członkostwa = 403; chwilowy błąd Discorda (429/5xx) = 503 dla nowych kont, ale zweryfikowane konto dostaje dostęp z grace, żeby kupujący nie tracili panelu.
- `sync-discord-events.sh` uruchamia po synchronizacji recheck roli; bez dodatkowego wpisu w LH Cron.
- Dokumentacja: API, Discord, baza, deploy, funkcje, testy.

### Testy
- `npm run test:roles` — 18 scenariuszy: decyzje login, fail-open, 404, 429 z Retry-After, revoke sesji, audit, blokada cronu.
- UI e2e: bramka roli blokuje panel.

## 3.7.1 — 2026-11-12

### Naprawione
- PIT-38: zdarzenia podatkowe i transakcje są liczone wyłącznie z wybranego roku podatkowego; zmiana roku w stanie nie miesza lat.
- Nadwyżka kosztów z poprzedniego roku jest edytowalna w panelu i zapisywana w stanie; nowe konta startują z 0,00 zł zamiast demo 380,00 zł. Stare stany bez wersji schematu są migrowane przy logowaniu i zapisie.
- „Neutralne podatkowo” liczy transakcje z dziennika (futures w USDT i krypto–krypto), a „Forex i CFD (MT5)” rozpoznaje konto/źródło MT4/MT5 zamiast pola `source`.
- „Do przeniesienia na kolejny rok” liczy się z kosztów, nadwyżki i przychodu roku podatkowego; CSV i wydruk PDF mają rok w nazwie i podsumowaniu, a także liczbę transakcji neutralnych i MT4/MT5.
- Formularz wpłaty/wypłaty wymaga kursu NBP dla waluty obcej i nie wlicza zdarzenia spoza roku podatkowego do zestawienia.

### Dodane
- `npm run test:tax` — 8 scenariuszy: odczyt z MySQL, edycja nadwyżki, CSV, PDF, formularz wpłaty/wypłaty, puste konto i brak błędów JS.


## 3.7.0 - 2026-11-12

### Zmienione
- Oura: OAuth2 Authorization Code + PKCE S256, scope `daily` zamiast nieistniejącego `sleep`, paginacja API v2 i wymagany `User-Agent`.
- WHOOP: API v2 (`/developer/v2/recovery`, `/developer/v2/activity/sleep`), scope `read:recovery read:sleep offline`, obsługa rotacji refresh tokenów.
- Garmin: OAuth2 PKCE S256 z tokenem `https://diauth.garmin.com/di-oauth2-service/oauth/token`, pobieranie User ID i uprawnień, wyrejestrowanie przez `DELETE /wellness-api/rest/user/registration`. Business Starter jest push-only; do czasu adaptera push status to `awaiting_provider_spec`, bez udawania pull.
- Tokeny: wymagany `WEARABLES_ENCRYPTION_KEY` bez fallbacku na `SESSION_SECRET`, rotacja przez `WEARABLES_ENCRYPTION_KEY_PREVIOUS`, `refresh_token_expires_at`, blokada `GET_LOCK` i atomowy zapis nowego refresh tokenu.
- Statusy urządzeń: `not_configured`, `ready_to_connect`, `pending_authorization`, `connected`, `syncing`, `sync_error`, `token_expired`, `revoked`, `awaiting_provider_access`, `awaiting_provider_spec`.
- Odłączenie wycofuje dostęp u dostawcy, a potem usuwa tokeny i metryki.
- Migracja addytywna: `wearable_oauth_states.code_verifier`, `wearable_connections.refresh_token_expires_at`.

### Testy
- `npm run test:wearables`: 42/42 PASS (PKCE, state, rotacja tokenów i klucza, błędy 401/429/JSON, izolacja użytkowników, Garmin push pending, UI).
- `tests/wearables-config.test.php`: 4/4 PASS (brak fallbacku klucza, start OAuth 503).


## 3.6.0 - 2026-11-12

### Dodane
- Kalendarz makro w MySQL: `macro_events` + `macro_sync_state` oraz `GET /api/macro-events`.
- Sync `bin/sync-macro-events.php`: bieżący tydzień ForexFactory, następny tydzień TradingView; UPSERT, soft delete, tylko ★★/★★★.
- Widget „MAKRO · ten tydzień” i strona kalendarza korzystają z tego samego API; usunięto demo z `data.js`.
- Testy: `npm run test:macro` 21/21; API 23/23; UI 52/52.

### Zmienione
- Puste dane makro = puste pola (bez „Brak wydarzeń”, „spokojnie do końca tygodnia”).
- Deploy uruchamia jednorazowy sync makro po migracji; LH Cron co 15 min.

## 3.5.0 - 2026-11-12

### Zmienione
- WEEX: każdy klucz API przechodzi połączenie. Usunięte odrzucanie kluczy z prawem handlu lub wypłat (`read_only_required`) i próba zlecenia na nieistniejącym symbolu; wymagany jest wyłącznie działający odczyt konta. Połączenie zapisuje `permissions: any`.
- Giełdy UI: instrukcja WEEX bez nakazu „tylko odczyt”, nota o warunkach per giełda, wiersz uprawnień pokazuje „dowolne (odczyt wystarcza)”.
- Polityka prywatności i Regulamin: usunięte zdanie o odrzucaniu kluczy z handlem/wypłatami; Regulamin wskazuje WEEX jako akceptujący dowolne uprawnienia, OKX i BingX nadal tylko odczyt.
- Dokumentacja: API, architektura, funkcje, testowanie.

### Testy
- Integracje: WEEX z kluczem `weex-trade` = 201 i `permissions: any` (było 422).
- UI integracje: formularz WEEX przyjmuje klucz z handlem i pokazuje uprawnienia „dowolne (odczyt wystarcza)”.

## 3.4.0 — 2026-11-12

### Dodane
- Integracje giełd: WEEX, OKX i BingX — klucze tylko do odczytu, szyfrowanie AES-256-GCM, walidacja uprawnień i automatyczna synchronizacja transakcji.
- MetaTrader 4/5 przez MetaApi (hasło inwestora, `investorMode`) oraz przez EA z kodem parowania (`integrations/mt5/AlphaVault.mq5`).
- Endpointy `/api/connections*`, `/api/mt/ea`, worker `bin/sync-exchange-trades.php` i tabela deduplikacji `exchange_imports`.
- Testy: integracje 19/19, UI integracje 11/11; dokumentacja API, bazy, deployu i testów.

## 3.3.0 — 2026-11-12

### Dodane
- Prop challenge: 9 szablonów (FTMO 2-Step/1-Step/Swing, FundedNext, FundingPips, Topstep, Apex, Breakout, własne), cel zysku, dzienny limit, obsunięcie static/trailing, dni, krzywa kapitału, alerty 75/90/100%, banner na pulpicie, archiwum i pełna edycja.
- `public/js/prop.js` — czysty, testowalny silnik limitów; `public/js/propui.js` — widok, edytor, szablony i powiadomienia.
- `prop.challenges` + `prop.selectedId` w JSON stanu; demo ma 3 przykładowe challenge'e.
- Testy: `npm run test:prop` (27), API 21/21, UI 51/51; dokumentacja [docs/PROP.md](docs/PROP.md).

## 3.2.0 — 2026-11-12

### Dodane
- Integracje Garmin Connect, Oura Ring i Whoop (OAuth2, szyfrowane tokeny, ręczny i cronowy sync).
- Apple Watch · Zdrowie: kod parowania i ingest z tokenem urządzenia.
- Gotowość łącząca sen, HRV, tętno, Body Battery, regenerację i check-in.
- UI „Ciało”: urządzenia, metryki, wykres sen → wynik dnia, odłączenie z usunięciem danych.
- `npm run test:wearables` — 33 scenariusze API + UI na lokalnym dostawcy OAuth.

### Naprawione
- `Db::migrate()` nie rozcina komentarzy SQL na bajcie `0x85` (litera ą/ń w UTF-8).

## 3.1.2 — 2026-11-12

### Zmienione
- Akademia: usunięty countdown „Start za”; karta live ukrywa się bez wydarzenia.
- „Dołącz” to link do `https://discord.com/events/{guild}/{event}`; usunięty modal z `discord.com/app`.
- Usunięte noty techniczne o kluczu API tylko do odczytu (Giełdy, Konto).

### Dodane
- Pole `url` w `/api/events` — kanoniczny link wydarzenia Discord.
- Testy API/UI/empty: format linku, brak countdownu, ukryta karta live.

## 3.1.1 — 2026-11-12

### Produkcja / CI-CD
- `main` chroniony: wymagany PR i check `PHP + API + UI`, linear history, `enforce_admins`, bez force push/delete; merge commit wyłączony.
- `DEPLOY_ENABLED=true`; merge/push do `main` auto-deployuje. Environment `alphavault-production` dopuszcza tylko `main`.
- Repo publiczne `miloszspyrka-code/av`; secret scanning, push protection i Dependabot security updates.
- LH Cron potwierdzony: `*/5 * * * *`, log `18:10:02`; wrapper `.app/cron/sync-discord-events.sh`.
- Deploy health: `/api/health` wszystkie flagi `true`, `/docs/` 200, `.app` i `config.json` → 403.

## 3.1.0 — 2026-11-12

### Dodane
- `AGENTS.md` — kontrakt agenta AV-engineer.
- `PRD.md` — wymagania produktowe, FR-xx i kryteria akceptacji.
- `DOCUMENTATION.md` — działanie aplikacji i punkty styku P1–P14.
- `scripts/sync-docs.mjs` — generowanie `public/docs` ze źródeł.
- `tests/docs.test.mjs` — test linków, kodowania i spójności dokumentacji.
- `config/discord_event_rules.example.json` — przykład reguł klasyfikacji.
- `monitor-production.yml` — monitoring health i synchronizacji co 15 min; `/api/health` zwraca skrót `sync`.

### Zmienione
- Deploy synchronizuje katalog `config/`, bez nadpisywania aktywnego `discord_event_rules.json`.
- CI uruchamia test dokumentacji przed testami PHP.
- README, API, architektura, baza, funkcje, testy i deploy wskazują na nowe dokumenty.
- Kod i `config.json` przeniesione do `~/public_html/alphavault.pl/.app` z powodu `open_basedir` LH; katalog blokowany przez `.htaccess`.
- `/docs/` kierowane do indeksu dokumentacji (wcześniej Apache zwracał 403).

### Wdrożone
- Produkcja `https://alphavault.pl` → 200, health OK.
- Realny sync Discord → 3 wydarzenia VOICE, bez duplikatów.
- Prod UI read-only: live, harmonogram, puste konsultacje.
- LH Cron działa co 5 min (logi 18:05:05, 18:10:02).

## 3.0.0 — 2026-11-12

### Zmienione
- Backend przepisany z Express 4 / Node.js na PHP 8.3.
- Produkcja na LH.pl: Apache + PHP-FPM, bez procesu Node.
- Schemat MySQL/MariaDB: `utf8mb4_unicode_ci`, tabela `rate_limits`.
- Weryfikacja roli Discord przez OAuth `guilds.members.read` (bez bota i `DISCORD_BOT_TOKEN`).
- Deployment GitHub Actions: SSH/rsync, backup, migracja PHP, health check, rollback.
- `config.json` generowany z sekretów poza routingiem (`.app`, deny).
- CI: PHP lint + MySQL 8.4 + API/UI e2e.

### Zachowane
- Dotychczasowy frontend, widoki, wykresy, modale i funkcje biznesowe.
- Kontrakty API `/api/health`, `/api/me`, `/api/state`, `/auth/discord`, `/auth/discord/callback`.
- Sesje, CSRF, rate limiting, audit log, eksport JSON.

## 2.1.0 — 2026-11-12

- Backend Node.js/Express, MySQL 8.4, Discord OAuth2, demo login, testy API/UI.
- Poprzednia wersja produkcyjna; kod zachowany w `legacy/`.
