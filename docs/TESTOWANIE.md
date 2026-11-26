# Testy

## Gdzie uruchamiamy testy

**Testy uruchamia wyłącznie GitHub Actions.** AV-engineer nie uruchamia testów, serwera PHP, MySQL ani Playwright lokalnie. Opisane niżej komendy i pliki identyfikują suite CI, nie są instrukcją lokalnego uruchamiania. Lokalny wynik nie jest dowodem i nie może zamykać zadania.

- PR → `.github/workflows/ci.yml` → wymagany check `PHP + API + UI`.
- `main` → ten sam workflow → dopiero po `success` startuje deploy.
- Produkcja → `deploy-alphavault.yml` (krok `Verify production E2E`) i `monitor-production.yml`.

CI używa MySQL 8.4, PHP 8.3, Node 24 i systemowego Chrome. Konfiguracja testowa jest generowana w workflow; nie ma potrzeby utrzymywać lokalnego `tests/config.test.json` do weryfikacji zmiany.

## Zakres CI

| Krok CI | Co sprawdza |
| --- | --- |
| Documentation | `sync-docs --check` i `tests/docs.test.mjs` |
| Encoding guard | `test:encoding` — UTF-8 i brak mojibake |
| PHP syntax check | `php -l` wszystkich plików w `app/` i `public/` |
| Apply database schema | migracja `app/db/migrate.php` na MySQL 8.4 |
| Role recheck tests | decyzje login i cron recheck na mocku Discord |
| Discord events tests | DB/sync, DST, UPSERT, soft delete |
| Macro events tests | normalizacja FF/TradingView, poprzedni pełny tydzień i pełne tygodnie +1…+3 (także cold-start), API −14/+35 dni, pusty/odfiltrowany feed, partial/error, zachowanie ostatnich dobrych danych |
| Quotes tests | cache, TTL, fallback, E2E notowań |
| Demo state tests | przesunięcie dat fixture demo, idempotencja, plany jako punkt zaczepienia |
| Wearables E2E | OAuth2/PKCE, rotacja tokenów, UI, bezpieczeństwo |
| API E2E | health, sesja, CSRF, walidacja SL/źródła/daty/closedAt, korekta ręcznego zamkniętego wykonania i łączny `resultOverride`, niezmienność zewnętrznych ID/importowanych wykonań, no-op zachowuje `revision` i ISO `updatedAt`, legacy close bez SL, stan, events, macro, admin, eksport i cleanup fixture |
| UI E2E | 15 widoków, Akademia bez karty konsultacji z kartą live na pełną szerokość, panel konsultacji ze skrótu pulpitu (wydarzenie, przypomnienia, rezerwacja, Dziennik), rozwijane konta, wiele planów dnia, poziomy i podsumowanie realizacji; natychmiastowe oznaczenie nie gubi szkicu tekstu, kopia oznaczonego planu z wczoraj resetuje status; historyczna checklista przy zmianie konfiguracji, wymagany SL, PnL po dacie realizacji, brak sukcesu przy błędzie zapisu zamknięcia, import CSV bez fikcyjnych metryk/PnL, statystyki i cleanup fixture |
| Integracje E2E | mocki OKX/BingX/WEEX/MetaApi/EA, batch 501, rollback, czas źródłowy/UTC, limit stron i brak kursora bez częściowego importu |
| Tax E2E | PIT-38, CSV/PDF, wpłaty/wypłaty |
| PWA E2E | manifest, SW, instalacja, push, offline |
| UI empty-state E2E | sukces z `[]`: karta konsultacji nie wraca, skrót pozostaje, szczegóły wydarzenia są puste |
| MCP unit/E2E | 79 narzędzi z runtime rejestru, klucze idempotencji 8–120 znaków, stabilny hash screenów przy zmianie kolejności kluczy JSON, screen `write_op` po MySQL round-trip, regresje dat/metryk/transakcji, semantyczny no-op bez zmiany rewizji, snapshot propozycji z kontrolą rewizji replay, redakcja screenów w odpowiedziach/diffach/replay, fixture'y testowe blokowane poza odizolowanym CI |
| MCP deploy guard | tylko `main` lub pełny SHA z `main` z udanym `ci.yml` dla dokładnego SHA; health revision i liczba narzędzi |

## Podatki PIT-38 — 10 scenariuszy

`npm run test:tax` (`tests/tax.e2e.mjs`) zapisuje deterministyczny stan do MySQL, wczytuje go ponownie i klika panel Podatki:

- przychód, koszty, nadwyżka, dochód i podatek 19% dla roku 2026,
- zdarzenie z 2025 nie wchodzi do zestawienia 2026,
- edycja nadwyżki kosztów z poprzedniego roku,
- neutralne podatkowo (47) i forex/MT5 (2) liczone z dziennika,
- eksport CSV z podsumowaniem, przecinkiem dziesiętnym i rokiem w nazwie; `podatki.csv` w paczce ZIP ma ten sam format,
- wydruk PDF z rokiem, nadwyżką i licznikami,
- formularz wpłaty/wypłaty wymaga dodatnich kwot i kursu NBP dla EUR, zasila PIT-38 i sumuje saldo konta numerycznie,
- wpływy bez daty, bez kursu waluty obcej i z ujemną kwotą są pomijane, a UI ostrzega o nich w podatku,
- tekstowy carry (`"1000,50"`) jest liczony numerycznie,
- puste konto nie dziedziczy demo 380 zł; brak błędów JS.

Test przywraca poprzedni stan demo po zakończeniu.

## Urządzenia zdrowotne — 42 scenariusze + 6 testów konfiguracji

`npm run test:wearables` uruchamia własny serwer aplikacji oraz lokalny, deterministyczny dostawcę OAuth (`tests/fake-health-provider.php`). Nie używa prawdziwych kont ani danych zdrowotnych.

Zakres:

- `GET /api/wearables` bez sesji = 401,
- OAuth2 + PKCE: start → authorize → callback → token → dane → gotowość dla Oura,
- scope Oura `daily`,
- jednorazowy `state`, wygasły `state`, błędny `code_verifier` = 502,
- rotacja refresh tokenów, brak podwójnego odświeżania, wygasły refresh token = 401,
- mapowanie 401/429/niepoprawnego JSON, powrót do `connected`,
- rotacja klucza AES z `WEARABLES_ENCRYPTION_KEY_PREVIOUS`, błędny klucz = `token_expired`,
- izolacja użytkowników: odczyt i DELETE nie dotykają cudzego połączenia,
- Garmin i Whoop wyłączone: brak w `devices`, start/sync/DELETE = 404,
- poprawność snu (312 min), HRV (38 ms), tętna (61) i baseline (HRV 52) dla Oura,
- ręczna synchronizacja zwraca 14 dni (paginacja Oura),
- odłączenie wycofuje dostęp u dostawcy i usuwa połączenie, metryki i baseline,
- Apple: jednorazowy kod parowania, ingest dwóch dni, token urządzenia, odrzucenie powtórzonego kodu i złego tokenu,
- gotowość łączy urządzenie i check-in (73/100),
- UI: tylko Oura i Apple w „Urządzeniach”, puste pola bez urządzenia, check-in 80/100, połączenie i metryki Oura, odłączenie czyści pola, Apple przez kod parowania, brak błędów JS,
- brak tokenów dostawców w odpowiedziach API i logach serwera.

`tests/wearables-config.test.php` (5/5) sprawdza listę dostawców (tylko Apple i Oura), brak maskowania `WEARABLES_ENCRYPTION_KEY` przez `SESSION_SECRET`, start OAuth = 503 bez klucza oraz gotowość Oura po ustawieniu klucza.

Wymaga Chrome/Edge (Playwright). Jeśli brak przeglądarki, test API nadal przechodzi, a część UI jest jawnie pominięta.

## Notowania rynkowe - 14 scenariuszy

`tests/quotes.test.php` (`npm run test:quotes`) testuje `app/Quotes.php` bez sieci i bez bazy: świeży zestaw, cache w TTL, fallback po błędzie dostawcy, pusty payload bez cache, cząstkowe pobranie, wygasanie pojedynczego notowania (900 s) i uszkodzony plik cache.

## Daty demo - 15 scenariuszy

`tests/demo-state.test.php` (`npm run test:demo-state`) sprawdza `app/DemoState.php` bez bazy i bez sieci: delta 0 dla daty fixture, przesunięcie transakcji, planów, check-inów i dat przyszłych, idempotencję, plany jako punkt zaczepienia, przesunięcie wstecz, niepoprawny JSON, brak dat oraz strefę `Europe/Warsaw`.

## Discord DB/sync - 24 scenariusze

`tests/discord-events.test.php` sprawdza:

- DST: `2026-10-25T00:30:00Z`, `2026-10-25T01:30:00Z`, `2027-03-28T00:30:00Z`, `2027-03-28T01:30:00Z`,
- prowadzącego z nazwy `X - Host - Y`,
- brak prowadzącego, gdy nie ma separatora,
- pierwszy sync: 4 wydarzenia (VOICE, konsultacja, EXTERNAL, Stage),
- mapowanie `STAGE_INSTANCE`,
- brak duracji przy braku `scheduled_end_time`,
- `user_count = NULL` pozostaje `NULL`,
- `EXTERNAL` + `entity_metadata.location`,
- listę i klasyfikację `live` / `consultation`,
- `nextConsultation`,
- drugi sync bez duplikatów,
- aktualizację zmienionych pól (UPSERT),
- zachowanie `manual_category` po synchronizacji,
- błędny payload nie zmienia bazy,
- pusty sukces czyści listę i soft-deletuje rekordy,
- powrót wydarzenia przywraca rekord.

## Rola i recheck — 18 scenariuszy

`npm run test:roles`:

- `tests/role-guard.test.php` — interpretacja odpowiedzi Discord (`ok`, `missing`, `not_member`, 429, 5xx, 403 bota) i decyzje logowania (403, 503, grace dla zweryfikowanych, fail closed bez konfiguracji),
- `tests/role-recheck.e2e.mjs` — lokalny mock Discord + MySQL: rola obecna utrzymuje sesję, brak roli usuwa sesję i dodaje `access.role_revoked`, błąd 500 nie odbiera dostępu, 404 odbiera, 429 jest ponawiane, blokada cronu pomija równoległy przebieg.

Test nie używa prawdziwego Discorda ani danych klientów.

## Prop challenge — 27 scenariuszy

`tests/prop.unit.mjs` (`npm run test:prop`) testuje czysty silnik `public/js/prop.js` bez serwera i bazy:

- katalog szablonów i normalizację danych,
- filtr konta i zakresu dat (włącznie z końcem),
- cel zysku, brak 1 centa, min. dni handlowe,
- dzienny limit 75/90/100%, podstawę initial/dayStart, limit 0,
- obsunięcie static i trailing, linię stopu i bufor,
- termin, brak terminu, min. dni przed deadlinem,
- statystyki, serię strat, najlepszy/najgorszy dzień,
- archiwum poza metrykami i zachowanie waluty.

## Routing i adresy podstron — 18 scenariuszy

`tests/router.unit.mjs` (`npm run test:router`) ładuje `public/js/actions.js` w izolowanym kontekście `node:vm` (stuby DOM, bez serwera i przeglądarki) i sprawdza:

- każdy z 15 widoków ma slug, slugi są unikalne i zgodne z `^[a-z0-9]+(-[a-z0-9]+)*$`,
- brak starych krótkich adresów (`trans`, `akad`, `ust`, …) w `AV.viewSlugs`,
- mapowanie w obie strony `id → slug → id` oraz aliasy (`#/akad`, `#/trans`),
- `AV.viewFromHash()`: wielkość liter, końcowy `/`, nieznany slug i pusty hash → `pulpit`,
- `AV.showView()` zapisuje czytelny slug w `location.hash` i sprowadza nieznane id do `pulpit`.

Test nie wymaga Chrome ani PHP — chroni kontrakt adresów przed regresją przy zmianach w routingu.

## Makro — zakres CI

CI uruchamia `tests/macro-events.test.php`; AV-engineer nie uruchamia tej suite lokalnie:

- normalizacja ForexFactory: High→3, Medium→2, Low/Holiday pomijane,
- rzeczywiste nakładanie okna ForexFactory niedziela–niedziela UTC i TradingView: FF ma priorytet, a rekordy TV poza jego oknem pozostają aktywne,
- konwersja oryginalnego ISO z offsetem na UTC,
- stabilny `external_id` i opis z prognozą/poprzednim odczytem,
- normalizacja TradingView: importance 1→3, 0→2, filtr szumu i mapowanie kraju na walutę,
- UPSERT bez duplikatów,
- soft delete po braku w feedzie i przywrócenie po powrocie,
- poprzedni pełny tydzień oraz pełne tygodnie TradingView +1, +2 i +3; ostatnia sekunda tygodnia +3 wchodzi do wyniku, a wyłączna granica początku +4 już nie,
- cold-start synchronizacji z pustą tabelą wypełnia wydarzenia z poprzedniego tygodnia, bieżącego tygodnia FF i tygodnia +3 TV,
- okno API obejmuje poprzedni pełny tydzień przy nawigacji kalendarza oraz granice −14/+35 dni,
- soft delete ograniczony do zakresu danego feedu; ForexFactory nie usuwa dalszych tygodni TradingView,
- pusty sukces czyści wyłącznie własny zakres, a brak jawnych granic nie czyści danych,
- poprawna lista feedu zawierająca wyłącznie rekordy odfiltrowane soft-delete'uje stare dane w swoim oknie; pusty obiekt JSON, nieprawidłowy kraj FF i niepoprawne `importance` TV są błędami i nie czyszczą dobrych danych,
- błędy TradingView i ForexFactory w `sync()` zapisują `partial`/`error`, `lastError`, zachowują `lastSuccessAt` i ostatnie dobre rekordy,
- `syncState` (status, świeżość, licznik i błąd częściowego feedu); UI sygnalizuje `partial`/`error`/stale bez czyszczenia danych.

Suite używa bazy testowej skonfigurowanej przez workflow CI; nie pobiera feedów z sieci.

## API — 23 scenariusze

`tests/api.e2e.mjs` sprawdza:
- health + MySQL,
- `/api/me` bez sesji,
- `/api/state`, `/api/events` i `/api/macro-events` bez sesji = 401,
- demo login + cookie HttpOnly/SameSite,
- OAuth start bez konfiguracji = 503,
- callback ze złym `state` = 400,
- `/api/me` z sesją,
- `/api/events` z sesją,
- `/api/macro-events` z sesją (kształt, impact 2/3 i UTC ISO),
- `/api/admin/events` bez uprawnień = 403,
- GET/PUT state + MySQL,
- round-trip `prop.challenges` i `selectedId` przez MySQL,
- konflikt rewizji = 409,
- CSRF / złe `Origin` = 403,
- UI `/`,
- `/docs/API.md`,
- eksport JSON,
- przywrócenie stanu,
- logout + unieważnienie sesji.

## Izolacja użytkowników - 9 scenariuszy

`tests/isolation.e2e.mjs` (`npm run test:isolation`) używa dwóch niezależnych jarów cookie:

- osobne konto demo per przeglądarka (różne `user.id` i `av_demo`),
- powrót w tej samej przeglądarce do tego samego demo,
- `/api/me` zwraca właściwe konto,
- stan dziennika A i B jest rozdzielony,
- zapis z karty A z `ownerId` konta B = 409 `session_changed` i brak zmiany stanu,
- zapis stanu A bez `ownerId` do sesji B = 409 (zgodność ze starym klientem),
- listy połączeń integracji są rozdzielone,
- tokeny MCP A nie są widoczne w B,
- wylogowanie A nie wpływa na B.

Migracje: `php app/db/migrate.php` na czystej bazie tworzy wszystkie tabele z `db/schema.sql` oraz `db/migrations/*.sql` (w tym `mcp_*`). Runner jest idempotentny.

## UI — pełny przebieg

`tests/ui.e2e.mjs` używa Playwright + systemowego Chrome/Edge:

- start UI i modal logowania,
- demo login + sesja,
- 47 transakcji z MySQL,
- live i konsultacja z `/api/events`; Akademia bez karty konsultacji, live na pełną szerokość, szczegóły/akcje konsultacji zachowane w panelu ze skrótu pulpitu; poprawna przyszła rezerwacja lokalna widoczna bez wydarzenia Discord, przeszła ukryta,
- dostępność panelu konsultacji: etykieta dialogu, przeniesienie/zwrot fokusu, pułapka Tab/Shift+Tab i zamknięcie Escape,
- harmonogram tygodnia i strip,
- nawigacja 15 widoków (w tym prop),
- plan dnia: wiele niezależnych planów dla daty, wybór planu/rynku, poziomy wsparcia, oporu, płynności, POI i własny, zatwierdzenie, zapis w MySQL i odtworzenie po reloadzie; statusy realizacji zatwierdzonych planów, agregaty dla daty, wyczyszczenie oznaczenia, wyłączenie szkiców/globalnych, trwałość po reloadzie i brak utraty statusu przy autosave; natychmiastowe oznaczenie zachowuje niezapisany tekst, a kopia oznaczonego planu z wczoraj zaczyna bez statusu; zakładka „Globalnie” z kafelkami i trwałością planu bez daty,
- pulpit: rozwijana lista kont, podgląd salda tylko przy kompletnych danych, brak arbitralnych limitów przy wielu planach, skrót do „Konto i zasady”, edycja salda i etykieta „Kapitał startowy”,
- ręczna transakcja: konto określa brokera i jednostkę wejściową; `size` jest normalizowane do jednostek bazowych, a loty wymagają jawnego mnożnika kontraktu; SL wymagany, R:R elastyczne bez stałego minimum, checklista bez minimalnego progu zapisu; ręczny wybór punktów zachowuje się przy edycji,
- CSV: brak PnL bez danych do wyliczenia pomija wiersz; jawny PnL 0 zostaje break-even; brakujące MAE/MFE, opłaty, czas i checklista nie są zamieniane na zera ani wartości domyślne; eksport dziennika zachowuje MAE/MFE i nie zapisuje nieznanej opłaty jako zero,
- metryki: puste MAE/MFE nie tworzą punktu na wykresie ani średniej; koszt „Za wczesne wyjście” pomija niezmierzone MFE/R,
- zamknięcie tylko wynikiem: cena wyjścia pozostaje pusta, a R liczone jest z SL; pusty wynik jest traktowany jako transakcja otwarta, nie break-even,
- dziennik: nowy wpis widoczny od razu i niezależnie od konta wyłączonego ze statystyk; filtry po zapisie są czyszczone; broker pochodzi z wybranego konta; brak syntetycznego wykresu i kontrolek interwału w szczegółach (wykres playbooka pozostaje); live P&L, BE, częściowe TP, korekta i przeliczenie ręcznego zamkniętego wykonania z opcjonalnym łącznym `resultOverride`, read-only dla zewnętrznych ID/importowanych wykonań, usunięcie z wpisem do kosza,
- pulpit: puste saldo nie pokazuje fałszywej zmiany −100%,
- kalkulator: wybór portfela ustawia saldo/MM, gotowe R:R 1:1, 1:1,2, 1:1,5, 1:2, 1:3, 1:5 lub własne; SL/TP zgodne z wybranym stosunkiem; ręczna cena wejścia nie jest nadpisywana przez live ani przez auto SL/TP; tryb odwrotny „Zaproponuj wielkość kapitału”, uśrednianie wejść i wiele TP,
- statystyki: zakres ogólny pozostaje niezależny od kalendarza PnL przy zmianie obu zakresów; kalendarz PnL ma własny zakres 7/30 dni lub miesiąc i nawigację okresu; partiale są grupowane wg `partials[].at`, końcowa część wg `closedAt`, z fallbackiem do `trade.date`,
- statystyki: wyniki transakcji i R zapisane jako tekst (np. z API/MCP) są sumowane numerycznie — net, GP/GL, profit factor, expectation, equity, PnL dnia i statystyki setupu,
- akademia: karta live na pełną szerokość, „Dołącz” dopiero 15 min przed startem, własny czas przypomnienia, harmonogram tydzień/miesiąc z odświeżaniem i rozwinięciem wydarzeń cyklicznych, kategorie nagrań, fallback biblioteki i odtwarzanie z linku, postęp kursu (12 lekcji) i bootcampu (6 modułów), słownik z wyszukiwaniem, Alpha Testy z wynikiem, złote zasady; skrót pulpitu otwiera szczegóły, przypomnienia, rezerwację i akcję Dziennika konsultacji,
- filtry dziennika, szczegóły transakcji,
- nagłówek: brak technicznego statusu bazy, ukryty chip giełd bez połączeń, ticker z `/api/quotes`,
- tryb ochronny: deterministyczne 2 straty dnia i obejście limitu,
- kalkulator, statystyki, integracje MCP Mentora (adres, zakładki ChatGPT/Claude/Grok, kopiowanie, status pill zgodny z lokalną konfiguracją `/api/mcp` — bez twierdzeń o zdalnej dostępności, kontrolowany fallback na błąd API, allowlista Claude), akademia,
- prop: 9 szablonów, nowy challenge + 4 KPI,
- prop: walidacja formularza i XSS w nazwie,
- prop: alarm limitu + banner na pulpicie,
- prop: archiwum, empty state i przywrócenie,
- prop: zapis w MySQL, reload i usunięcie,
- kalendarz + makro-widget z API, nawigacja tygodni makro (poprzedni, bieżący i +1…+3) i powrót, check-in, profil,
- 8 odznak niezależnie (próg, podpowiedź, pusty stan, poziom z licznika),
- nowa transakcja i zamknięcie,
- przywrócenie stanu demo,
- wylogowanie i usunięcie prywatnego cache z `localStorage`.

## UI empty-state — 15 scenariuszy

`tests/ui-empty.e2e.mjs` przy poprawnym sync zwracającym `[]` sprawdza:

- pusty tytuł, prowadzący i ukryta karta live,
- brak karty konsultacji w Akademii i pełna szerokość karty live,
- dostępny skrót konsultacji na pulpicie bez nadchodzącego wydarzenia,
- ukrycie przeszłej lokalnej rezerwacji i brak fikcyjnych szczegółów wydarzenia w panelu; akcje pozostają dostępne,
- pusty strip live,
- ukryty link „Dołącz”,
- 7 dni tygodnia bez wydarzeń,
- `events.length === 0`,
- dostępny stan synchronizacji.

## PWA mobile — 20 scenariuszy

`tests/pwa.e2e.mjs` używa Playwright + systemowego Chrome w emulacji iPhone 14 (390×844, touch, iOS UA). Sprawdza:

- manifest: `display=standalone`, `start_url`, ikony 192/512/maskable,
- meta iOS i `apple-touch-icon`,
- rejestrację service workera i przejęcie kontroli nad stroną,
- `Page.getAppManifest` i `Page.getInstallabilityErrors` bez błędów,
- brak poziomego przewijania na pulpicie,
- brak przepełnienia we wszystkich 15 widokach przy 390 px i 1024 px: sprawdzane są prostokąty widocznych elementów i `scrollWidth`, z pominięciem celowych kontenerów `overflow-x:auto`,
- przycisk instalacji i stan powiadomień,
- zamknięcie transakcji → powiadomienie systemowe z deep linkiem `?qa=<id>`,
- deep link otwiera modal z prawdziwą transakcją,
- emocja + ocena + zdjęcie zapisane w ≤ 10 s (mierzone `AV.ui.qaLastSeconds`),
- stały przycisk zapisu na telefonie,
- push przez CDP `ServiceWorker.deliverPushMessage`,
- offline: powłoka aplikacji z cache service workera.

To emulacja Chromium. Instalacja na fizycznym iPhone (Safari → Do ekranu początkowego) i powiadomienia iOS wymagają testu ręcznego; patrz „Czego testy nie obejmują”.

## MCP 3.2 - OAuth, zapis, zgoda w rozmowie, kosz, panel

| Test | Zakres |
| --- | --- |
| `php tests/mcp-migration-upgrade.test.php` | CI-only symulacja schematu sprzed migracji 20261116, dodanie fingerprintów, kolacje case-sensitive i ponowne wykonanie migracji |
| `php tests/mcp.test.php` | gotowość z brakującymi metrykami urządzenia, wymagany SL, propozycje, case-sensitive klucze propozycji i decyzji, kod jednorazowy, zatwierdzenie bez kodu (`confirmed`), fingerprint idempotencji decyzji (akcja i payload, także replay legacy), zysk i break-even, konflikt rewizji, TTL, ochrona danych giełdowych, `target_missing`, izolacja, audyt |
| `php tests/mcp-tools.test.php` | silniki bez HTTP i DB: Metrics (null vs zero, break-even), puste MAE/MFE w API, dokładne dopasowanie rynku bez kolizji prefiksów, widoczność transakcji konta wyłączonego, zgodność z planem, ryzyko i tryb ochronny, raporty per grupa, kalkulator, PIT-38 (pomijanie wpływów bez daty/kursu i ujemnych, tekstowy carry), odznaki, screeny |
| `php tests/mcp-write.test.php` | rejestr 30 operacji zapisu (wszystkie bezpośrednie), 33 schematy wejścia z `idempotencyKey`, `confirmed` w `approve_proposal`, wymagany SL, ścisłe MAE/MFE, formuły check-inu i planu, walidacja setupów, kosza i przywracania, reguły ryzyka, prop, transakcje i screeny bez bazy |
| `node tests/mcp-tools.e2e.mjs` | dashboard pobiera metryki Oura tylko z `alphavault.health`; jawna zgoda health-only nie obiecuje dostępu do tradingu ani zapisu; brak metryk pozostaje `null`; `include=false`, break-even, fingerprint idempotencji; pełne cykle 33 narzędzi, pominięte MFE zachowuje pomiar, jawne `null` go czyści, propozycje, kosz i workflow |
| `node tests/mcp-login.e2e.mjs` | logowanie Discord na mocku: rola OK, brak roli, brak członkostwa, duplikat konta, `POST /oauth/authorize` z obcym `Origin` (303, bez `bad_origin`), decyzja zgody z obcego `Origin` i sfałszowany CSRF bez wydania kodu, `GET /oauth/connected` + `POST /oauth/connected/revoke` (`Origin: null` z poprawnym CSRF = 302 i unieważniony token, obcy `Origin` i zły CSRF = 403) |
| `node tests/mcp-ui.e2e.mjs` | panel: dwie propozycje, diff, opcjonalny kod do rozmowy, approve, reject, reload, trwałość i brak błędów JS |
| `node tests/prop-parity.mjs` | parytet `PropChallengeService.php` z `public/js/prop.js` na wspólnych fixtures |
| `php tests/mcp-user-service.php` | idempotentne dopasowanie konta wyłącznie po Discord ID |

Wymagania CI: `composer install`, rozszerzenia `openssl`, `sodium`, `json`, MySQL 8.4 i `tests/config.test.json`.
Testy MCP nie używają produkcyjnego Discorda ani giełd; logowanie Discord jest mockowane lokalnym serwerem HTTP.

## Dokumentacja — 16 scenariuszy

`tests/docs.test.mjs` sprawdza:

- względne linki w `README.md`, `PRD.md`, `DOCUMENTATION.md`, `AGENTS.md` i `docs/*.md`,
- względne linki w `public/docs`,
- kodowanie UTF-8 (brak mojibake),
- wymagane rozdziały PRD/DOCUMENTATION/AGENTS,
- wzajemne linki dokumentów głównych,
- indeks `docs/README.md`,
- synchronizację `public/docs` z źródłami,
- obecność wszystkich generowanych plików.

## Kodowanie — test CI

`tests/encoding.test.mjs` + `scripts/check-encoding.mjs` (`npm run test:encoding`) oraz `tests/encoding.test.php`:

- wykrywa niepoprawny UTF-8 i mojibake w kodzie, testach i dokumentacji,
- testuje naprawę UTF-8 → Windows-1250 → UTF-8 bez zmiany poprawnego tekstu i ASCII,
- `tests/api.e2e.mjs` wstawia uszkodzony stan do MySQL (`tests/seed-corrupt-state.php`) i sprawdza, że `GET /api/state` naprawia go i zapisuje z powrotem,
- blokuje regresje polskich znaków,
- jest uruchamiany w CI jako krok `Encoding guard`.

Naprawa istniejących plików: `node scripts/fix-mojibake.mjs --write <plik>`. Nie deklaruj naprawy kodowania bez zielonego runu CI.

## Live Discord

`.github/workflows/discord-sync-live-test.yml`:

- uruchamiany ręcznie (`workflow_dispatch`),
- tworzy tymczasowy MySQL 8.4,
- używa sekretów `DISCORD_*`,
- wykonuje dwie synchronizacje,
- wypisuje `LIVE_SYNC_EVENTS`, `LIVE_SYNC_UNIQUE`, `EVENT=` i `SYNC_STATE=`,
- kończy się błędem przy duplikatach.

## CI

`.github/workflows/ci.yml`:

- MySQL 8.4 service,
- PHP 8.3 + Node 24,
- dokumentacja (`sync-docs --check` + `tests/docs.test.mjs`),
- PHP lint,
- migracja (schema + `db/migrations/*.sql` + `ensureColumn`/`ensureIndex`),
- rola: decyzje login + cron na mocku Discord,
- MCP security,
- prop challenge,
- Discord DB/sync,
- makro DB/sync,
- przesuwanie dat fixture demo,
- UI tradingowe: wiele planów dnia, dziennik/checklista/SL, rozwijane konta i niezależne zakresy statystyk PnL,
- notowania,
- urządzenia (API + UI + konfiguracja),
- API e2e,
- izolacja użytkowników,
- integracje e2e + UI integracje,
- UI e2e (systemowy Chrome),
- podatki PIT-38,
- PWA e2e (emulacja iPhone 14, Chrome),
- empty-state na osobnym porcie,
- integracje giełd/MetaTrader/API oraz ich UI na mockach.

## Produkcja — 2026-11-12

Wykonane na `https://alphavault.pl`:

| Test | Wynik |
| --- | --- |
| `GET /` | 200, UI Alpha Vault |
| `GET /api/health` | `ok=true`, DB 11.8.6-MariaDB, wszystkie flagi true |
| `GET /docs/` | 200, indeks PRD/DOCUMENTATION/API |
| `GET /.app/config.json` | 403 |
| `GET /api/events` bez sesji | 401 |
| Realny sync | `sync.status=success`; liczba w `sync.eventsCount` (2026-11-12 21:49 UTC: 2) |
| Baza | rekordy `live` zgodne z Discordem, `sync.status=success` |
| Błąd 401 (zły token) | `sync.status=error`, wydarzenia zachowane, `last_success_at` bez zmian |
| LH Cron | 1 wpis `*/5`; `sync.lastSuccessAt` i `roleRecheck.lastSuccessAt` świeże (<10 min), `stale=false` |
| Monitoring | `monitor-production.yml` — health, sync, UI, docs, `.app` 403, co 15 min |
| Playwright (sesja testowa, read-only) | live + harmonogram widoczne, konsultacje puste, brak błędów JS |
| PWA manifest/SW/ikony | 200, `application/manifest+json`, ikony `/assets/pwa/*`, installability bez błędów |
| PWA mobile E2E | 20/20 w CI; fizyczny telefon — do potwierdzenia |

Sesja testowa została usunięta po weryfikacji. Nie pozostał żaden testowy użytkownik ani token.

## Czego testy nie obejmują

- prawdziwego logowania Discord (wymaga interakcji użytkownika),
- instalacji PWA na fizycznym telefonie i powiadomień iOS (wymaga urządzenia),
- realnego Stage (brak wydarzenia w API — tylko fixture),
- produkcyjnego SSH/rsync (sprawdzane przez dry run deployu),
- LH Cron (wymaga panelu LH),
- testów obciążeniowych.

Testy Discord wykonuj ręcznie na koncie z rolą Alpha, bez roli i spoza serwera. Nie deklaruj wyniku bez rzeczywistego udziału użytkownika.
﻿## Integracje giełd, MetaTrader i API

CI generuje `tests/config.integrations.json` z `config.test.json`, ustawia `INTEGRATIONS_KEY`, `METAAPI_TOKEN` i adresy `*_BASE` na lokalny mock, a następnie uruchamia:

```text
npm run test:integrations      # API + mocki OKX/BingX/WEEX/MetaApi/EA
npm run test:ui:integrations   # Playwright: formularze i dziennik
```

Oba kroki są częścią `.github/workflows/ci.yml`. Lokalne uruchomienie jest dozwolone tylko dla developera i nie jest dowodem w raporcie AV-engineer.

Zakres: OKX read-only i odrzucenie handlu, BingX `apiPermissions`, WEEX akceptacja klucza z handlem + rekonstrukcja fills, MetaApi `investorMode` i odrzucenie hasła z handlem, EA kod parowania + ingest + deduplikacja. UI weryfikuje wyłącznie formularz BingX, link rejestracyjny i licznik transakcji (bez zakładek MetaTrader i Import CSV). Brak PnL w źródle pomija transakcję zamiast tworzyć break-even; jawne zero PnL i zerowa opłata pozostają znane (WEEX break-even po opłatach, BingX wynik `0` przy nieznanych opłatach i czasie).

Regresje integralności: OKX importuje 501 rekordów w jednej rewizji, zapisuje `closedAt` ze źródła i UTC `importedAt`, a przekroczenie limitu stron lub pełna strona bez kursora kończy się jawnym błędem bez częściowego importu z żadnego źródła historii OKX. Wadliwy rekord wycofuje cały batch danego połączenia, stan, rewizję i markery deduplikacji. Testy integracji działają na odizolowanej bazie CI; reset startowy i końcowy musi zakończyć się kodem 0.

API i UI E2E zapamiętują pełny stan serwerowego fixture i odtwarzają go po teście, również w ścieżce obsługi błędu; odtworzenie zwiększa rewizję. UI weryfikuje przywrócenie przez `/api/state`. To sprzątanie dotyczy wyłącznie odizolowanego konta demo CI, nie produkcji.
