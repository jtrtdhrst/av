# PRD — Alpha Vault

| Pole | Wartość |
| --- | --- |
| Produkt | Alpha Vault — prywatna strefa tradera Alpha Moves |
| Domena | <https://alphavault.pl> |
| Wersja dokumentu | 3.3 |
| Data | 2026-11-17 |
| Właściciel produktu | Alpha Moves |
| Odbiorca | agent **AV-engineer**, zespół techniczny |
| Status | wymagania produktu; odnotowany stan produkcji znajduje się w §11 |

**Dokumenty powiązane**

- [DOCUMENTATION.md](DOCUMENTATION.md) — jak aplikacja działa, punkt po punkcie, wraz z pełną listą punktów styku.
- [AGENTS.md](AGENTS.md) — kontrakt pracy agenta AV-engineer, komendy, zakazy i Definition of Done.
- [docs/FUNKCJE.md](docs/FUNKCJE.md) — stan każdej funkcji (E2E / lokalnie / demo).
- [docs/API.md](docs/API.md) — kontrakt HTTP i przykłady odpowiedzi.
- [docs/ARCHITEKTURA.md](docs/ARCHITEKTURA.md) — warstwy i przepływy techniczne.
- [docs/BAZA-DANYCH.md](docs/BAZA-DANYCH.md) — schemat MySQL i backup.
- [docs/DISCORD.md](docs/DISCORD.md) — konfiguracja OAuth2, bota i roli.
- [docs/DEPLOY.md](docs/DEPLOY.md) — wdrożenie na LH.pl.
- [docs/TESTOWANIE.md](docs/TESTOWANIE.md) — testy i checklisty.

---

## 1. Streszczenie

Alpha Vault to aplikacja webowa dla społeczności traderów Alpha Moves. Łączy dziennik transakcji, plan dnia, statystyki, materiały edukacyjne oraz harmonogram live'ów i konsultacji 1:1. Dostęp jest zamknięty: użytkownik loguje się przez Discord i musi mieć właściwą rolę na serwerze Alpha Moves.

W wersji 3.0 backend został zmigrowany z Express/Node.js na PHP 8.3, a harmonogram Akademii jest synchronizowany z Discord Scheduled Events przez LH Cron. Discord jest źródłem prawdy o wydarzeniach; aplikacja przechowuje ich kopię w MySQL i prezentuje w strefie `Europe/Warsaw`.

## 2. Problem i cel

**Problem**

- Harmonogram live'ów i konsultacji był wpisywany ręcznie w kodzie lub prezentowany jako dane demonstracyjne.
- Zmiany w Discordzie nie trafiały do panelu; użytkownik widział nieaktualne godziny.
- Ręczna edycja powodowała literówki, duplikaty i niespójne strefy czasowe.
- Hosting współdzielony LH.pl nie pozwala na stały proces Node.js.

**Cel**

- Jedno źródło prawdy: Discord Scheduled Events.
- Automatyczna synchronizacja co 5 minut, bez otwierania panelu przez użytkownika.
- Poprawne godziny polskie, również przy zmianie czasu letniego/zimowego.
- Puste pola, gdy nie ma wydarzeń — bez danych zastępczych.
- Zachowanie dotychczasowych funkcji tradingowych i Akademii.

## 3. Użytkownicy i role

| Rola | Kim jest | Co robi |
| --- | --- | --- |
| Trader (`full`) | członek społeczności z rolą Alpha | panel tradingowy, live'y, konsultacje, materiały |
| Trader (`solo`) | plan bez Akademii | panel tradingowy; Akademia zablokowana |
| Administrator wydarzeń | Discord ID z `ADMIN_DISCORD_IDS` | przypisuje wydarzenia do `live` / `consultation` / `unassigned` |
| Bot Discord | aplikacja, nie człowiek | odczyt `GET /guilds/{id}/scheduled-events`; brak uprawnień do zarządzania |
| LH Cron | system | uruchamia `bin/sync-discord-events.php` co 5 minut |

## 4. Zakres

### 4.1. W zakresie

- logowanie Discord OAuth2 i weryfikacja roli,
- zachowanie istniejącego UI i funkcji tradingowych,
- synchronizacja Discord Scheduled Events do MySQL,
- klasyfikacja wydarzeń i ręczne przypisanie przez administratora,
- harmonogram live'ów i panel konsultacji 1:1 zasilane rzeczywistymi danymi,
- puste pola przy braku wydarzeń,
- strefa `Europe/Warsaw` i DST,
- LH Cron, blokada równoległych uruchomień, diagnostyka synchronizacji,
- testy jednostkowe, API, UI, empty-state i DST,
- dokumentacja spójna i wersjonowana.

### 4.2. Poza zakresem

- WordPress i inne strony na tym hostingu — nie modyfikować,
- Discord Gateway / WebSocket i stały proces Node.js na produkcji,
- zarządzanie wydarzeniami z panelu (bot read-only),
- płatności Stripe (modal zakupu jest demonstracyjny),
- wysyłka e-mail/SMS przypomnień poza aplikacją,
- generowanie kolejnych wystąpień wydarzeń cyklicznych (przechowujemy `recurrence_rule`, ale nie tworzymy fikcyjnych wystąpień),
- natywna aplikacja mobilna w App Store/Google Play; PWA jest w zakresie (FR-17).

## 5. Model dostępu i konta

- Logowanie wyłącznie Discord OAuth2 (`identify guilds.members.read`).
- Wymagane: członkostwo w serwerze `DISCORD_GUILD_ID` i rola `DISCORD_REQUIRED_ROLE_ID`.
- Rola jest sprawdzana przy logowaniu i cyklicznie co 5 minut; jej odebranie usuwa sesje i blokuje panel.
- Chwilowy błąd Discorda nie odbiera dostępu kontom już zweryfikowanym (fail open); brak roli i brak konfiguracji = brak dostępu (fail closed).
- Sesja: cookie `av_session` HttpOnly, SameSite=Lax, Secure na HTTPS, 30 dni przy „Nie wylogowuj mnie”.
- CSRF: kontrola `Origin` dla żądań mutujących.
- Rate limiting w MySQL dla logowania i operacji wrażliwych.
- Demo login (`POST /auth/demo`) tylko lokalnie, gdy `ALLOW_DEMO_LOGIN=true`; na produkcji wyłączony.

## 6. Wymagania funkcjonalne

### FR-01 — Logowanie Discord i rola

Użytkownik klika „Zaloguj przez Discord”, przechodzi OAuth2, a serwer sprawdza członkostwo i rolę. Brak roli lub członkostwa kończy się czytelnym komunikatem i brakiem sesji. Cron co 5 minut ponawia sprawdzenie aktywnych sesji; odebranie roli blokuje panel. Chwilowy błąd Discorda nie odbiera dostępu kontom już zweryfikowanym.

### FR-02 — Sesja, CSRF, rate limit

Sesja jest przechowywana jako hash w MySQL, nie w cookie. Mutacje wymagają poprawnego `Origin`. Limity chronią logowanie i zapis stanu.

### FR-03 — Trwały stan aplikacji

Cały stan użytkownika jest dokumentem JSON w `user_states`. Zapis jest optymistyczny: `revision` + `baseRevision`; konflikt zwraca 409 bez nadpisania danych. `localStorage` służy tylko jako fallback offline.

### FR-04 — Panel tradingowy bez regresji

Zachowane: pulpit, plan dnia, dziennik, playbook, nowa transakcja, kalkulator, prop challenge, statystyki, mentor AI (integracje MCP), podatki PIT-38, kalendarz makro, ciało i głowa, giełdy, ustawienia, eksport i usunięcie konta.

Każdy widok ma czytelny adres w hashu (`#/dziennik`, `#/akademia`, `#/podatki`, `#/konto`, …); stare krótkie adresy (`#/trans`, `#/akad`, `#/ust`) pozostają aliasami i są przepisywane na slug. Kontrakt: `DOCUMENTATION.md §11.1a`, test: `tests/router.unit.mjs`.

- Karta salda na pulpicie rozwija listę kont. Plan dnia pozwala utrzymywać wiele niezależnych planów dla tej samej daty, każdy z własnym rynkiem i poziomami płynności/POI oraz własnym poziomem użytkownika; dopasowanie transakcji nie wybiera arbitralnie, gdy kilka planów pasuje do tego samego rynku. Zakładka „Globalnie” przechowuje scenariusze bez daty i pokazuje je jako kafelki. Zatwierdzony plan dzienny można osobno oznaczyć jako zrealizowany lub niezrealizowany; podsumowanie daty liczy oba wyniki i zatwierdzone plany bez oznaczenia, pomijając szkice oraz plany globalne. Skopiowanie planu z wczoraj czyści jego status realizacji.
- Dziennik pozwala wybrać konto przy dodawaniu transakcji; broker i jednostka wielkości wynikają z tego konta. Pokazuje live P&L otwartych pozycji oraz obsługuje częściowe TP, BE i wcześniejsze zamknięcie. Szczegóły transakcji nie mają syntetycznego wykresu ani kontrolek interwału; wykres playbooka pozostaje. Usunięcie trafia do kosza stanu, dzięki czemu przechodzi walidację integralności.
- Kalkulator zachowuje ręcznie wpisaną cenę wejścia, oferuje tryb odwrotny „Zakładam SL → jaka kwota”, uśrednianie wielu wejść i wiele TP z łącznym zyskiem.
- Ręczna checklista ocenia transakcję, ale nie narzuca minimalnej liczby zaznaczeń; wybór punktów jest ręczny. Edycja zachowuje historyczny wynik checklisty, a gdy starszy wpis nie zapisał nazw zaznaczonych punktów, UI nie dopisuje zmyślonego wyboru. Transakcja wymaga SL; R:R dobiera się do setupu, bez stałego minimum. Po zapisie formularz otwiera dziennik i resetuje jego filtry. Dziennik pokazuje wszystkie wpisy niezależnie od tego, czy ich konto jest włączone do statystyk.
- Etykieta kapitału początkowego konta jest jawna („Kapitał startowy”). Zakres statystyk ogólnych pozostaje niezależny od zakresu kalendarza PnL.
- MAE/MFE, opłaty, czas trwania i checklista z importu pozostają nieznane (`null`), jeśli źródło ich nie dostarcza; puste pomiary nie są liczone jak zero. CSV nie tworzy wpisu bez PnL i bez danych do jego wyliczenia, ale zachowuje jawny PnL równy 0.
- Koszt błędu „Za wczesne wyjście” jest pomijany, gdy brakuje pomiaru MFE albo niezerowego R; zamknięcie transakcji przez MCP zachowuje wcześniejsze MFE, jeśli pole pominięto, a jawne `null` je czyści.
- Eksport dziennika zachowuje MAE/MFE i nie zamienia nieznanej opłaty ani pustej checklisty na zero lub wartość domyślną. Pusty wynik jest traktowany jako transakcja otwarta; zamknięcie tylko wynikiem nie fabrykuje ceny wyjścia.
- Pulpit rozdziela saldo bieżące wpisane dla kont od kapitału obliczonego. Kapitał obliczony to kapitał startowy kont włączonych do statystyk, powiększony o zrealizowane wyniki (w tym częściowe zamknięcia) i wpłaty, pomniejszony o wypłaty; brakujące saldo ręczne pozostaje puste.
- Jednostka wielkości pozycji w dzienniku wynika z konta: jednostki bazowe, kwota w walucie kwotowanej albo loty/kontrakty. Kwoty kwotowane i loty są przeliczane na jednostki bazowe do zapisu i obliczania ryzyka, a `trade.size` pozostaje kanoniczną wielkością w jednostkach bazowych; lot wymaga jawnego mnożnika kontraktu. Broker jest dziedziczony z wybranego konta.
- Kalendarz PnL i zakresy statystyk przypisują wynik do dat realizacji: częściowe zamknięcie używa `partials[].at`, a końcowa część `closedAt`; gdy brakuje czasu zdarzenia, używana jest data transakcji. Otwarte pozycje mogą więc wnosić do PnL wyłącznie zrealizowane części.
- Zamknięte ręczne wykonania można korygować i przeliczać. Jawny ręczny `resultOverride` zastępuje automatycznie przeliczony wynik łączny; ID wpisu i zewnętrzne identyfikatory importu pozostają niezmienne, a wykonania wpisów importowanych są tylko do odczytu.

### FR-05 — Harmonogram live'ów z Discorda

- źródło: `GET https://discord.com/api/v10/guilds/{guild.id}/scheduled-events?with_user_count=true`,
- grupowanie po lokalnej dacie `Europe/Warsaw`,
- polskie dni tygodnia, oznaczenie `DZIŚ`,
- sortowanie chronologiczne po rzeczywistym timestampie,
- wiele wydarzeń jednego dnia,
- godzina `HH:mm`, liczba zainteresowanych, jeśli dostępna,
- brak wpisanych na stałe godzin i tytułów.

### FR-06 — Dostęp do konsultacji 1:1

- skrót na pulpicie otwiera panel konsultacji; szczegóły „Najbliższa konsultacja” pokazują wyłącznie wydarzenie sklasyfikowane jako `consultation`,
- prowadzący tylko z wiarygodnego pola wydarzenia lub reguły (nie ze zgadywania),
- czas trwania tylko gdy istnieje `scheduled_end_time`,
- przy braku wydarzenia Discord można pokazać wyłącznie poprawną przyszłą rezerwację zapisaną lokalnie; data błędna lub przeszła pozostaje ukryta,
- brak wydarzenia Discord i brak przyszłej rezerwacji lokalnej = puste pola, bez fikcyjnego miesiąca,
- karta konsultacji nie zajmuje miejsca w Akademii; sąsiadująca karta live wykorzystuje pełną szerokość,
- rezerwacja, przypomnienia i rozliczenie wykorzystanych godzin pozostają dostępne z panelu i działają niezależnie od synchronizacji.

### FR-07 — Klasyfikacja i ręczne przypisanie

Priorytet:

1. ręczne przypisanie konkretnego Discord Event ID (`manual_category`),
2. jawne reguły z `config/discord_event_rules.json` (ID lub wzorzec nazwy/opisu),
3. reguły biznesowe aplikacji (konsultacja, 1:1, live, webinar, warsztat, akademia, analiza, rynek),
4. domyślnie `live`,
5. wydarzenie bez nazwy lub z nieznanym typem → `unassigned`.

Wydarzenia `unassigned` są widoczne na liście administracyjnej; nie są ukrywane.

### FR-08 — Puste pola

Po udanej synchronizacji z zerem wydarzeń:

- karta live jest ukryta; tytuł, prowadzący i godzina pozostają puste,
- skrót konsultacji pozostaje dostępny; bez wydarzenia Discord panel pokazuje szczegóły i miesiąc tylko dla poprawnej przyszłej rezerwacji lokalnej, a wygasłe lub błędne dane pomija,
- brak tekstów „Brak wydarzeń”, „Niedostępne”, „--”, „Wkrótce”,
- brak starych danych demo.

Po błędzie synchronizacji ostatni poprawny zestaw pozostaje w bazie i UI.

### FR-09 — Czas i DST

- źródłowe czasy przechowywane jako UTC + oryginalne ISO 8601,
- konwersja do `Europe/Warsaw` po stronie PHP,
- brak stałego UTC+1/UTC+2,
- poprawne przejścia DST (patrz AC-10).

### FR-10 — Wydarzenia cykliczne

`recurrence_rule` jest przechowywane bez uproszczeń. Front rozwija wystąpienia daily/weekly/monthly do 31 dni wprzód na potrzeby harmonogramu, z poszanowaniem interwału, liczby wystąpień i daty końca oraz deduplikacją po nazwie i czasie; wystąpienia obecne w odpowiedzi Discord mają pierwszeństwo. Nieobsługiwany wariant nie może psuć harmonogramu.

### FR-11 — Synchronizacja CRON

- skrypt `bin/sync-discord-events.php` uruchamiany co 5 minut,
- blokada równoległych uruchomień,
- transakcja MySQL,
- aktualizacja stanu dopiero po sukcesie,
- bezpieczne logi bez tokenu,
- brak publicznego endpointu uruchamiającego synchronizację.

### FR-12 — Odporność na błędy

Obsłużone: 401, 403, 404, 429 (z `Retry-After`), 5xx, timeout, zły JSON, brak wymaganych pól, błąd MySQL, przerwane połączenie. Przy błędzie: brak usuwania wydarzeń, brak aktualizacji `last_successful_sync`, zapis kodu i czasu błędu.

### FR-13 — Diagnostyka

Panel administratora i `/api/events` pokazują: ostatnią próbę, ostatni sukces, liczbę wydarzeń, liczbę aktualizacji, czas wykonania, status, ostatni błąd i znacznik nieaktualności.

### FR-14 — Bezpieczeństwo

- `DISCORD_BOT_TOKEN` tylko po stronie serwera,
- brak tokenu w przeglądarce, URL, logach i repo,
- `config.json` w ukrytym .app, deny + 600,
- bot bez Privileged Gateway Intents i bez zarządzania wydarzeniami,
- endpointy wydarzeń za istniejącą autoryzacją,
- surowe payloady nie są publiczne.

### FR-15 — Eksport i usunięcie konta

`GET /api/me/export` zwraca JSON; UI potrafi wygenerować archiwum z danymi użytkownika. `DELETE /api/account` usuwa konto i dane kaskadowo.

### FR-16 — Dokumentacja jako część produktu

Każda zmiana funkcjonalna aktualizuje właściwy dokument. Dokumenty wskazują na siebie i są weryfikowane automatycznie (`npm run test:docs`).

### FR-17 — Aplikacja na telefonie (PWA)

Panel jest instalowalny z przeglądarki bez App Store i Google Play (`manifest.webmanifest`, service worker, ikony 192/512/maskable). Po zamknięciu transakcji w panelu użytkownik dostaje powiadomienie z deep linkiem do szybkiego dodania, gdzie w ≤ 10 s zapisuje emocję, ocenę i zdjęcie wykresu. Powłoka aplikacji działa offline; `/api/` i `/auth/` nie są cache'owane. Powiadomienia o transakcjach zamkniętych na giełdzie przy zamkniętej aplikacji wymagają serwerowego workera giełdowego i Web Push — poza zakresem do czasu auto-importu.

### FR-18 — Integracje urządzeń i gotowość

Użytkownik łączy Oura Ring przez OAuth2, a Apple Watch przez jednorazowy kod parowania i ingest z iPhone. Alpha Vault pobiera sen, HRV, tętno spoczynkowe, Body Battery i wynik regeneracji, liczy gotowość oraz pokazuje, jak sen wpływa na wynik dnia. Tokeny są szyfrowane, dane o zdrowiu przetwarzane tylko za zgodą, a odłączenie urządzenia usuwa je z bazy. Check-in przed rynkiem wnosi 30% gotowości. Integracje Garmin i Whoop są wyłączone na razie (kod zachowany, brak wpisu w UI/API).

### FR-19 — Prop challenge

Panel prowadzi wiele challenge'ów prop firm równolegle i pilnuje ich limitów na podstawie dziennika.

- szablony popularnych firm (FTMO, FundedNext, FundingPips, Topstep, Apex, Breakout) oraz własne zasady,
- cel zysku, dzienny limit straty i maksymalne obsunięcie (static/trailing) liczone z zamkniętych transakcji wybranego konta,
- dni do końca i minimalna liczba dni handlowych,
- alerty przy 75/90/100% limitu, banner na pulpicie i powiadomienie (raz dziennie),
- osobne statystyki, krzywa kapitału i status dla każdego challenge'u,
- archiwum, duplikat i usuwanie konfiguracji bez wpływu na transakcje.

### FR-20 — Kalendarz makro z bazy

Kalendarz makro i widget „MAKRO · ten tydzień” korzystają z jednego, wspólnego zestawu publikacji zapisanego w MySQL i udostępnianego przez `GET /api/macro-events`.

- bieżący pełny tydzień z feedu ForexFactory; poprzedni pełny tydzień oraz pełne tygodnie +1, +2 i +3 z TradingView (granice poniedziałek–poniedziałek, koniec wyłączny), także przy cold-starcie pustej bazy,
- przy nakładaniu okien ForexFactory ma priorytet; rekordy TradingView poza oknem FF pozostają aktywne,
- API filtruje wydarzenia w granicach 14 dni wstecz i około 35 dni naprzód; rzeczywiste przyszłe pokrycie feedów kończy się na pełnym tygodniu TradingView +3. Nawigacja kalendarza obejmuje poprzedni tydzień oraz tygodnie bieżący i +1…+3,
- tylko publikacje ★★ i ★★★ (Low/Holiday pomijane), czasy w UTC + oryginalne ISO,
- UPSERT bez duplikatów i soft delete po braku w feedzie,
- błąd API/feedu nie czyści ostatniego dobrego zestawu i sygnalizuje stan częściowy lub stale; sukces z `[]` czyści jawne okno,
- synchronizacja przez 5-min wrapper LH Cron z throttle 15 min (`bin/sync-macro-events.php`, znacznik `.runtime/macro.stamp`) oraz ręcznie.

### FR-21 - MCP 3.2: kontekst, 79 narzędzi i zapis bezpośredni

MCP na `mcp.alphavault.pl` udostępnia agentom AI dane właściciela tokenu OAuth i bezpieczny mechanizm zmian.

- OAuth 2.1 + PKCE S256, scope `alphavault.read` i `alphavault.health`. Nie ma osobnego zakresu zapisu: przy `MCP_WRITES_ENABLED=true` jedno połączenie daje od razu 79 narzędzi; przy wyłączonym zapisie 46 narzędzi odczytu. Odmowa jedynego scope’u zdrowotnego kończy autoryzację bez wydania tokenu i nie rozszerza dostępu do `alphavault.read`.
- 46 narzędzi odczytu: profil, pulpit, transakcje, dziennik, statystyki i raporty, wydarzenia, plan dnia, playbook, makro, prop, check-iny, gotowość, ryzyko, konta, kapitał, podatki, połączenia, lista i status propozycji.
- 33 narzędzia zapisu: 30 operacji zapisuje bezpośrednio (transakcje, recenzje, setupy, plan dnia, check-in, screen, usunięcia, konta, wpłaty i wypłaty, reguły ryzyka, prop, zatwierdzenie planu, kosz) oraz 3 obsługujące opcjonalne propozycje (`propose_change`, `approve_proposal`, `reject_proposal`).
- Plany dnia mają stabilne `id` i mogą występować wielokrotnie pod jedną datą. `get_day_plan` zwraca wszystkie plany daty; `update_day_plan`, `approve_day_plan` i `delete_day_plan` wskazują plan przez `planId`, obowiązkowy, gdy data ma wiele planów. Poziomy planu zachowują typ, cenę (`price`/`value`) i notatkę w odczycie oraz zapisie, w tym płynność i POI.
- Każdy zapis ma `idempotencyKey` i opcjonalny `expectedRevision`; konflikt wersji nie nadpisuje danych.
- Fingerprint wiąże `idempotencyKey` z operacją, argumentami i rewizją; ponowne użycie dla innego payloadu zwraca `idempotency_conflict` (409), także dla decyzji propozycji. Klucze są case-sensitive.
- `account.include` ogranicza analitykę, nie odczyt list i szczegółów transakcji. Wynik 0 jest break-even, osobno od zysku i straty.
- Dane zdrowotne na pulpicie MCP wymagają zakresu `alphavault.health` i aktywnego połączenia urządzenia; bez niego wynik gotowości zależy wyłącznie od check-inu, a bez check-inu `score` jest `null`.
- Usunięcia są miękkie: rekord trafia do `state.trash[]` i wraca przez `restore_deleted_record`.
- `propose_change` pokazuje diff bez zapisu; `approve_proposal` wymaga `confirmed: true` i zgodnego `diffHash`; klient powinien wysłać flagę po jednoznacznej zgodzie użytkownika, ale serwer nie analizuje treści rozmowy. `reject_proposal` nie zmienia danych, a kod z panelu jest opcjonalny. Bezpośrednie operacje zapisu nie wymagają dodatkowego potwierdzenia.
- Idempotencja zapisu (24 h) i decyzji, TTL propozycji 30 min, `diffHash`, audyt, transakcja MySQL z `SELECT ... FOR UPDATE`.
- Dane giełdowe są niezmienne, także saldo i kapitał startowy konta; recenzja transakcji giełdowej trafia do osobnego obszaru.
- MCP może tylko zaostrzać reguły ryzyka; nie podnosi limitów i nie wyłącza zabezpieczeń. Nazwy pól z `get_risk_rules` są akceptowane w `update_risk_rules`.
- `MCP_WRITES_ENABLED=false` ukrywa 33 narzędzia zapisu (zostaje 46 odczytu) bez zmiany zakresów OAuth. `MCP_PILOT_DISCORD_IDS` opcjonalnie ogranicza zapis do wybranych kont; ekran zgody komunikuje oba warunki.

### FR-22 — Integralność zapisu transakcji, importu i propozycji

- API i MCP walidują przejście stanu przez wspólne `TradeRules`; importer waliduje każdy kanoniczny rekord tą samą regułą. Pole `source` podane przez klienta ani nowy wpis w koszu nie zwalniają z wymogu SL. Nowa transakcja ręczna wymaga prawidłowego SL, a zaufany importer może zachować brak SL, jeśli giełda go nie dostarcza.
- Istniejący ręczny wpis historyczny bez SL może zostać zamknięty tylko bez zmiany pól bazowych wykonania. Puste `result` normalizuje się do `null`; każdy znany wynik liczbowy, w tym `result=0`, oznacza zamknięcie. Brak `closedAt` pozostaje nieznany; system nie uzupełnia czasu wstecz.
- Walidacja odrzucona i semantyczny no-op nie zmieniają stanu ani rewizji; no-op zachowuje również `updatedAt`.
- Sync jednego połączenia giełdowego jest atomowy dla transakcji, stanu, rewizji i markerów deduplikacji. Batch przekraczający 500 rekordów jest obsługiwany, a błąd rekordu wycofuje cały batch. Osiągnięcie limitu stron albo pełna strona bez poprawnego kursora zgłasza błąd zamiast cichego ucięcia.
- Import zachowuje czas zamknięcia ze źródła i zapisuje `importedAt` w UTC.
- Propozycja MCP `write_op` utrwala kompletny snapshot i podgląd; zatwierdzenie stosuje go tylko przy zgodnej rewizji, bez ponownego wykonania operacji ani rebase. Payloady obrazów są redagowane w odpowiedziach zapisu, diffach i propozycjach; jawny odczyt screenów pozostaje opt-in.

### FR-23 — Synchronizacja i odczyt materiałów Akademii

- Backend cyklicznie pobiera materiały wyłącznie z zatwierdzonej, stałej allowlisty 13 kanałów. Najpierw wykonuje bezpośrednie `GET /channels/{id}` i sprawdza `guild_id`; pierwsze 11 źródeł wymaga zatwierdzonego `parent_id`, dwa dodatkowe nie zależą od kategorii. Nie enumeruje kanałów serwera ani nie wykonuje mutacji Discord.
- Backfill i audyt historii mają trwałe checkpointy, są idempotentne i izolują błędy per kanał. HTTP 200 z pustą historią nie potwierdza dostępu do treści. Dostęp do historii i uprzywilejowanej treści są przechowywane osobno; `ready` wymaga potwierdzenia treści i zakończenia backfillu we wszystkich źródłach.
- Błąd synchronizacji zachowuje ostatni dobry zestaw; usunięcia są miękkie i wynikają wyłącznie z kompletnego audytu, w którym potwierdzono odczyt treści.
- `GET /api/academy/materials` wymaga sesji, aktualnego potwierdzenia roli i entitlementu `full`; konta demo, solo oraz wygasłe są odrzucane. Endpoint oferuje filtrowanie i keyset pagination, nie wyzwala syncu i zwraca kanoniczne linki do wiadomości źródłowych bez surowych payloadów ani URL-i CDN/załączników.

## 7. Wymagania niefunkcjonalne

| Obszar | Wymaganie |
| --- | --- |
| Backend | PHP 8.3, `pdo_mysql`, `curl`, `mbstring`; brak stałego procesu Node na produkcji |
| Hosting | LH.pl shared hosting, MySQL/MariaDB, LH Cron |
| Czas odpowiedzi API | < 300 ms dla `/api/events` przy 3–50 wydarzeniach (bez zimnego startu) |
| Synchronizacja | co 5 minut; tolerancja opóźnienia do 15 minut; `stale=true` po 900 s |
| Idempotencja | ponowny sync nie tworzy duplikatów (`UNIQUE discord_event_id`) |
| Retencja | historia synchronizacji 30 dni; wydarzenia soft-delete bez fizycznego kasowania |
| Dostępność | awaria Discord nie blokuje panelu; UI korzysta z ostatniego dobrego stanu |
| Monitoring | workflow co 15 min sprawdza `/api/health`, flagi konfiguracji i `sync`; błąd = powiadomienie GitHub |
| Bezpieczeństwo | OWASP: sesje, CSRF, CSP, HSTS, rate limit, brak sekretów w kliencie |
| Rozmiar stanu | `MAX_STATE_BYTES` = 12 MiB |
| Przeglądarki | aktualne Chrome/Edge/Firefox/Safari; UI responsywne |

## 8. Kryteria akceptacji

| ID | Kryterium | Weryfikacja |
| --- | --- | --- |
| AC-01 | Discord API zwraca rzeczywiste wydarzenia | live test `discord-sync-live-test.yml` |
| AC-02 | PHP synchronizuje je do MySQL | test DB + `LIVE_SYNC_EVENTS` |
| AC-03 | Synchronizacja działa automatycznie przez LH Cron | wpis CRON + logi |
| AC-04 | Ponowne uruchomienia nie tworzą duplikatów | `LIVE_SYNC_UNIQUE == LIVE_SYNC_EVENTS` |
| AC-05 | Zmiany i usunięcia są obsługiwane | test UPSERT + soft delete |
| AC-06 | Panel pokazuje rzeczywiste wydarzenia | UI E2E |
| AC-07 | Live'y trafiają do harmonogramu, a konsultacje do panelu ze skrótu pulpitu | UI E2E + klasyfikacja |
| AC-08 | Puste dane = puste pola, bez ukrywania skrótu konsultacji | `ui-empty.e2e.mjs` 15 scenariuszy |
| AC-09 | Godziny poprawne w `Europe/Warsaw` | testy DST + UI |
| AC-10 | Przejście czasu letni/zimowy przetestowane | 4 przypadki DST |
| AC-11 | Token bota pozostaje bezpieczny | audyt sekretów i logów |
| AC-12 | Produkcyjne E2E przechodzi | health + events + login + sync na `alphavault.pl` |
| AC-13 | Dokumentacja spójna i aktualna | `npm run test:docs` |
| AC-14 | PWA jest instalowalna, a szybkie dodanie z powiadomienia zajmuje ≤ 10 s | `tests/pwa.e2e.mjs` 20/20 |
| AC-15 | OAuth2 + PKCE Oura kończy się zapisem metryk; Apple Watch ingestion działa; Garmin i Whoop niedostępne w UI/API | `npm run test:wearables` |
| AC-16 | Apple Health przyjmuje dane po kodzie parowania i tokenie urządzenia | `npm run test:wearables` |
| AC-17 | Gotowość łączy urządzenie z check-inem i czyści się po odłączeniu | test API + UI E2E |
| AC-18 | Tokeny dostawców nie są widoczne w API ani logach | test odpowiedzi API + audyt |
| AC-19 | Kalendarz makro i widget korzystają z DB oraz są odporne na błąd feedu | `npm run test:macro` (okna FF/TV, granice i soft delete) + API/UI E2E |
| AC-20 | Walidacja API/MCP/importu nie pozwala ominąć SL przez `source`; legacy close zachowuje nieznany `closedAt`, a każdy znany wynik liczbowy (w tym `0`) zamyka wpis | `tests/api.e2e.mjs`, `tests/mcp-write.test.php`, `tests/integrations.e2e.mjs` |
| AC-21 | No-op nie podnosi rewizji ani `updatedAt`; batch 501 rekordów jest atomowy, ma markery i źródłowy czas zamknięcia oraz UTC `importedAt`; limit stron i brak kursora nie prowadzą do częściowego importu | API + integracje E2E w CI |
| AC-22 | `write_op` stosuje zamrożony snapshot tylko przy zgodnej rewizji; obrazy nie wyciekają przez zapis, diff, preview ani replay | MCP unit/E2E w CI |
| AC-23 | Zatwierdzone plany dnia można oznaczyć jako zrealizowane/niezrealizowane, a podsumowanie daty po reloadzie zgadza się ze stanem API; szkice i plany globalne są wyłączone, kopia z wczoraj startuje bez statusu | UI E2E w CI |
| AC-24 | Synchronizacja Akademii ogranicza się do 13 zatwierdzonych źródeł, weryfikuje metadane przed historią, zachowuje checkpoint/ostatnie dobre dane i nie uznaje pustej treści za dostęp; chroniony GET zwraca stabilne strony bez payloadów i URL-i CDN | `tests/academy-materials.test.php` + `tests/api.e2e.mjs` w CI |

## 9. Metryki sukcesu

- 0 ręcznych wpisów wydarzeń w kodzie.
- 100% wydarzeń z Discorda widocznych w panelu w ciągu 5 minut.
- 0 duplikatów po dowolnej liczbie synchronizacji.
- 0 przypadków wyświetlenia nieaktualnej godziny po zmianie DST.
- 0 wycieków tokenu do frontendu, logów lub repo.
- 100% testów AC zielonych na produkcji.

## 10. Ograniczenia i ryzyka

| Ryzyko | Wpływ | Mitygacja |
| --- | --- | --- |
| Ryzyko blokady billingu GitHub Actions | brak deployu i CI | repo publiczne — standard runners free; monitoruj Billing; blokada = BLOCKER, bez lokalnego deployu |
| Brak danych Discord w środowisku lokalnym | nie da się testować OAuth lokalnie | sekrety w GitHub; weryfikacja OAuth tylko za zgodą użytkownika |
| LH Cron zatrzymany | brak automatycznej synchronizacji | monitoring logów i `stale=true` |
| Stage nie wystąpił w realnym API | brak potwierdzenia Stage | fixture + oznaczenie „niezweryfikowany” |
| Zmiana odpowiedzi Discord | utrata pól | walidacja i `raw_payload` |
| Współdzielony hosting | limity procesów/DB | brak długich procesów, sync krótki i blokowany |
| Przypadkowe modyfikacje WordPressa | awaria innych stron | deploy wyłącznie do `alphavault.pl` |

## 11. Status wdrożenia (2026-11-12)

| Element | Status |
| --- | --- |
| Kod PHP 8.3 | gotowy, w `main` |
| Synchronizacja Discord | gotowa, test realny 3/3 VOICE |
| Testy DB/sync | PASS |
| API / UI / empty-state | PASS |
| Prop challenge (frontend) | gotowy; ostatni pełny CI według `AGENTS.md` z 2026-11-12: `prop 27/27`, `API 23/23`, `UI 58/58` |
| Baza `serwer445119_alphavault` | istnieje, tabele utworzone |
| Deploy na `alphavault.pl` | **200** — wdrożony |
| App path | `~/public_html/alphavault.pl/.app` (open_basedir, deny) |
| GitHub Actions | działa (repo publiczne, standard runners free) |
| CI gate / branch protection | `PHP + API + UI` wymagany; PR, linear history, admins enforced |
| Repo / security | publiczne `miloszspyrka-code/av`; secret scanning + push protection + Dependabot |
| Auto-deploy | merge do `main` przy `DEPLOY_ENABLED=true`; env tylko z `main` |
| Prod sync | ostatni odnotowany health check (2026-11-12 21:49 UTC): `success`, 2 wydarzenia |
| Prod UI (read-only) | PASS — live, harmonogram, puste konsultacje |
| LH Cron | jeden wpis `*/5`; `sync` i `roleRecheck` świeże (<10 min), `stale=false` (stan z 2026-11-12) |
| Monitoring | `monitor-production.yml` — co 15 min |
| Stage | niezweryfikowany |
| OAuth Discord w przeglądarce | potwierdzony 2026-11-12: konto z rolą uzyskuje sesję, konto bez roli otrzymuje 403; konto spoza serwera pozostaje do weryfikacji |

## 12. Otwarte decyzje

1. Czy `config/discord_event_rules.json` ma być utrzymywany w repo, czy tylko na serwerze.
2. Czy pokazywać w panelu nadchodzące wystąpienia `recurrence_rule`, czy wyłącznie wydarzenia zwrócone przez Discord.
3. Czy dodać osobną kolumnę „kategoria” w admin UI, czy wystarczą przyciski live/konsultacja.
4. Czy historia `discord_sync_runs` ma być widoczna w panelu, czy tylko w bazie.

## 13. Powiązanie wymagań z dokumentacją

| Wymaganie | Dokument techniczny |
| --- | --- |
| FR-01, FR-02 | [DOCUMENTATION.md §4](DOCUMENTATION.md#4-uwierzytelnianie-sesje-i-csrf), [docs/API.md](docs/API.md) |
| FR-03 | [DOCUMENTATION.md §5](DOCUMENTATION.md#5-stan-aplikacji), [docs/BAZA-DANYCH.md](docs/BAZA-DANYCH.md) |
| FR-04 | [DOCUMENTATION.md §11.3a](DOCUMENTATION.md#113a-pulpit-plan-dnia-kalkulator-statystyki-i-makro), [docs/FUNKCJE.md](docs/FUNKCJE.md) |
| FR-05, FR-06, FR-07, FR-08 | [DOCUMENTATION.md §7](DOCUMENTATION.md#7-discord-scheduled-events--pełny-przepływ), [docs/DISCORD.md](docs/DISCORD.md) |
| FR-09, FR-10 | [DOCUMENTATION.md §8](DOCUMENTATION.md#8-czas-dst-i-recurrence) |
| FR-11, FR-12, FR-13 | [DOCUMENTATION.md §9](DOCUMENTATION.md#9-cron-i-niezawodność), [docs/DEPLOY.md](docs/DEPLOY.md) |
| FR-14 | [DOCUMENTATION.md §15](DOCUMENTATION.md#15-bezpieczeństwo) |
| FR-15 | [docs/API.md](docs/API.md) |
| FR-16 | [DOCUMENTATION.md §17](DOCUMENTATION.md#17-zarządzanie-dokumentacją), [AGENTS.md](AGENTS.md) |
| FR-19 | [docs/PROP.md](docs/PROP.md), [docs/FUNKCJE.md](docs/FUNKCJE.md) |
| FR-22 | [DOCUMENTATION.md §5](DOCUMENTATION.md#5-stan-aplikacji), [docs/API.md](docs/API.md), [docs/MCP.md](docs/MCP.md), [docs/TESTOWANIE.md](docs/TESTOWANIE.md) |
| FR-23 | [DOCUMENTATION.md §6.3](DOCUMENTATION.md#63-materiały-akademii), [docs/API.md](docs/API.md), [docs/DISCORD.md](docs/DISCORD.md), [docs/BAZA-DANYCH.md](docs/BAZA-DANYCH.md), [docs/TESTOWANIE.md](docs/TESTOWANIE.md) |
