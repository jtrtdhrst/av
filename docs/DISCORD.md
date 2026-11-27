# Discord OAuth2 — konfiguracja

Aplikacja używa **Authorization Code Grant** i dwóch scope'ów:

```text
identify guilds.members.read
```

**Logowanie** sprawdza rolę tokenem zalogowanego użytkownika (scope `guilds.members.read`). **Cykliczna weryfikacja co 5 minut** używa bota (`DISCORD_BOT_TOKEN`) i endpointu pojedynczego członka — bez połączenia Gateway. Materiały Akademii czyta osobna integracja REST ograniczona do stałej allowlisty i wymaga `Message Content` dla pól treści.

```text
GET https://discord.com/api/v10/users/@me/guilds/{DISCORD_GUILD_ID}/member
Authorization: Bearer <access_token>

GET https://discord.com/api/v10/guilds/{DISCORD_GUILD_ID}/members/{user_id}
Authorization: Bot <DISCORD_BOT_TOKEN>
```

Szczegóły przepływu: [DOCUMENTATION.md §4](../DOCUMENTATION.md#4-uwierzytelnianie-sesje-i-csrf) i [§7](../DOCUMENTATION.md#7-discord-scheduled-events--pełny-przepływ).

## 1. Discord Developer Portal

1. <https://discord.com/developers/applications> → New Application.
2. OAuth2 → Client ID → `DISCORD_CLIENT_ID`.
3. OAuth2 → Client Secret → `DISCORD_CLIENT_SECRET`.
4. OAuth2 → Redirects → dodaj dokładnie oba adresy:

   ```text
   https://alphavault.pl/auth/discord/callback
   https://mcp.alphavault.pl/oauth/discord/callback
   ```

   Drugi adres jest wymagany przez logowanie MCP. Bez niego Discord kończy logowanie błędem `invalid redirect_uri`, a `mcp.alphavault.pl` nie może utworzyć sesji.

5. Zapisz.

Stan redirect URI i sekretu klienta sprawdza workflow `Discord OAuth preflight` (`.github/workflows/discord-oauth-preflight.yml`): codziennie o 05:17 UTC i ręcznie przez `workflow_dispatch`. Workflow czyta `GET /applications/@me` i nie wypisuje sekretów. Czerwony run oznacza brak wpisu w Discord Developer Portal albo zły `DISCORD_CLIENT_SECRET`.

Nie dodawaj scope'ów OAuth `email`, `guilds`, `messages` ani uprawnień do wysyłania wiadomości. Odczyt materiałów korzysta z tokenu bota i REST, nie z OAuth `messages`.

## 2. Serwer i rola

- `DISCORD_GUILD_ID`: PPM na serwerze Alpha Moves → Copy Server ID.
- `DISCORD_REQUIRED_ROLE_ID`: Ustawienia serwera → Role → PPM na roli Alpha → Copy Role ID.
- Użytkownik musi być członkiem serwera i mieć tę rolę.

Backend **fail closed**: brak `DISCORD_GUILD_ID` lub `DISCORD_REQUIRED_ROLE_ID` oznacza odmowę dostępu, nie wejście bez sprawdzania.

## 3. Bot do wydarzeń i weryfikacji roli

Bot jest potrzebny do:

- `GET /guilds/{guild.id}/scheduled-events?with_user_count=true`,
- `GET /guilds/{guild.id}/members/{user_id}` — pojedynczy członek, bez intencji `GUILD_MEMBERS`.

1. Discord Developer Portal → Bot → Add Bot.
2. Skopiuj token → `DISCORD_BOT_TOKEN` (GitHub secret / ukryty `.app`).
3. Dla istniejącej synchronizacji wydarzeń/rechecku roli nie przyznawaj Manage Events ani Administratora.
4. Nie otwieraj połączenia Gateway. Jedynym zatwierdzonym wyjątkiem od braku uprzywilejowanych intencji jest `Message Content` potrzebne REST do zwracania treści wiadomości Akademii; włącz tylko ten przełącznik, jeśli wymaga go aplikacja.
5. Dodaj bota na serwer Alpha Moves.

Synchronizacja i recheck roli są uruchamiane przez LH Cron co 5 minut (`cron/sync-discord-events.sh`). Jeśli token jest nieprawidłowy, wydarzenia w panelu pozostają ostatnim poprawnym zestawem, a `discord_sync_state.last_status=error`; recheck zapisuje `role_check_status=error` i nie odbiera dostępu.

### 3a. Materiały Akademii — REST tylko do allowlisty

Właściciel zatwierdził read-only import dokładnie 13 kanałów serwera `1186734786388176916`. Nie wyszukuj ani nie pobieraj wiadomości z innych kanałów.

Pierwszych 11 kanałów musi należeć do kategorii `1186777064494338068`. Przed pobraniem historii sprawdź każdy z nich osobnym `GET /channels/{channel_id}` i wymagaj zgodnego `guild_id` oraz `parent_id`. Nie używaj `GET /guilds/{guild_id}/channels` ani nie rozszerzaj listy.

| ID kanału | Nazwa | Filtr |
| --- | --- | --- |
| `1499560912611446784` | Materiały Forex | Forex |
| `1499560977849384991` | Materiały Krypto | Krypto |
| `1186973732925874217` | Nagrania | Nagrania |
| `1186976565909794906` | Kalkulator | Narzędzia |
| `1186978545902309456` | Przydatne strony | Narzędzia |
| `1186979958187368468` | Dziennik tradingowy | Narzędzia |
| `1499561028646600878` | Bootcamp Forex | Forex |
| `1500905675960750151` | Bootcamp Krypto | Krypto |
| `1501626207207292968` | Nagrania Mental | Mental |
| `1542258755390152794` | Bonusowe wideo | Dodatkowe |
| `1548026950961074216` | Nagrania Akademii | Nagrania |

Te dwa wymagane kanały sprawdzaj niezależnie od ich kategorii:

| ID kanału | Nazwa | Filtr |
| --- | --- | --- |
| `1499558305888997568` | Trade Recaps | Trade Recaps |
| `1566742784596250624` | Poranne odprawy | Poranne odprawy |

Bot potrzebuje `View Channel` i `Read Message History` wyłącznie w powyższych kanałach. REST wiadomości wymaga włączenia uprzywilejowanego `Message Content` w Developer Portal, aby Discord zwracał `content`, embeds i attachments; sam przełącznik nie otwiera połączenia Gateway. Nie włączaj innych intentów, nie przyznawaj Administratora ani uprawnień do wysyłania. OAuth nadal używa wyłącznie `identify guilds.members.read`.

Synchronizacja korzysta z istniejącego LH Cron co 5 minut. Niezweryfikowana kategoria, brak dostępu lub brak potwierdzonego odczytu treści oznacza błąd kanału — nigdy sukces z pustą listą. Błąd jednego kanału nie może wyczyścić danych pozostałych. API materiałów wymaga zalogowanej sesji i pełnego dostępu do Akademii (`plan=full`); nie zwraca surowych payloadów ani adresów CDN/załączników. Link materiału jest budowany wyłącznie jako `https://discord.com/channels/{guild_id}/{channel_id}/{message_id}`.

Grupowanie wiadomości jest deterministyczne i nie używa AI. Jawna referencja Discord ma pierwszeństwo; w pozostałych przypadkach podpis nagrania można połączyć tylko z bezpośrednio poprzedzającym materiałem tego samego autora i kanału w tym samym dniu `Europe/Warsaw`. Przy niejawnym łączeniu opisu tekstowego z późniejszym podpisem oba wpisy muszą współdzielić wyróżniający identyfikator tematu zapisany wielkimi literami (np. ticker/skrócona nazwa) lub token alfanumeryczny; zwykłe słowa pisane małymi literami nie wystarczą. Bez dopasowania wpisy pozostają osobno. Opisujący wpis może zawierać wykres, jeśli zawiera objaśniający tekst. W `Trade Recaps` obowiązuje konfigurowalne okno, początkowo maksymalnie 30 minut. Niejednoznaczne lub niezależne wiadomości pozostają osobnymi materiałami. Tytułem jest pierwsza niepusta linia, a opisem wyłącznie pozostała treść. Po imporcie kolejnej strony, edycji lub usunięciu synchronizacja ponownie indeksuje cały dotknięty dzień, dlatego pary nie zależą od granic stron ani kolejności kolejnych synchronizacji.

### 3b. Cykliczna weryfikacja roli (co 5 min)

`bin/check-discord-roles.php` bierze aktywne sesje, których `users.role_checked_at` jest starsze niż `DISCORD_ROLE_RECHECK_SECONDS` (minimum 300 s), i sprawdza rolę botem.

| Wynik Discord | Akcja |
| --- | --- |
| rola obecna | `role_verified=1`, `role_check_status=ok`, sesja zostaje |
| brak roli / 404 | `role_verified=0`, `role_check_status=missing`, usunięcie wszystkich sesji, `access.role_revoked` |
| 429 po 3 próbach / 5xx / timeout | `role_check_status=error`, dostęp zostaje (fail open) |

`role_check_state` trzyma blokadę cronu i liczniki. Stan widać w `/api/health.roleRecheck`. Frontend co 5 minut sprawdza `/api/me`; 401 lub `roleVerified=false` pokazuje pełnoekranową bramkę z informacją o wymaganej roli i przyciskiem „Sprawdź ponownie”.

## 4. Sekrety

W GitHub Actions:

```text
DISCORD_CLIENT_ID
DISCORD_CLIENT_SECRET
DISCORD_REDIRECT_URI=https://alphavault.pl/auth/discord/callback
DISCORD_GUILD_ID
DISCORD_REQUIRED_ROLE_ID
DISCORD_BOT_TOKEN
ADMIN_DISCORD_IDS
```

Wartości nie są zapisywane w repo ani wysyłane do przeglądarki. `config.json` jest generowany na serwerze w ukrytym `.app`, zablokowanym przez `.htaccess`.

## 5. Przepływ

1. `GET /auth/discord?remember=1` tworzy `state` w `oauth_states` i cookie `av_oauth`.
2. Redirect do `https://discord.com/api/oauth2/authorize` ze scope'ami `identify guilds.members.read`.
3. `GET /auth/discord/callback` weryfikuje `state` (DB + cookie).
4. Wymiana `code` na token.
5. `GET /users/@me` → profil.
6. `GET /users/@me/guilds/{guild}/member` → role.
7. Brak członkostwa/roli → HTTP 403, bez sesji. Chwilowy błąd Discord → 503; konto już zweryfikowane dostaje dostęp z grace.
8. Sukces → `users` upsert, sesja w MySQL, cookie `av_session`, redirect `/#/pulpit`.
9. Cron co 5 min sprawdza aktywne sesje botem. Odebranie roli usuwa sesje; nadanie roli przywraca dostęp po ponownym logowaniu lub odświeżeniu.

## 6. Test ręczny

1. Otwórz `https://alphavault.pl`.
2. Kliknij **Zaloguj przez Discord**.
3. Zaloguj konto z rolą Alpha → powinien pojawić się pulpit.
4. Wyloguj się i sprawdź, że `/api/state` zwraca 401.
5. Zaloguj konto bez roli → powinien pojawić się ekran 403.
6. Zaloguj konto spoza serwera → powinien pojawić się ekran 403.
7. Będąc zalogowanym zdejmij rolę i odczekaj do 5 min. Panel powinien pokazać bramkę „Brak aktywnej roli Alpha”, a `/api/state` zwrócić 401.
8. Nadaj rolę ponownie, kliknij „Sprawdź ponownie” i zaloguj się; dostęp wraca.

Logowanie Discord wymaga interaktywnego udziału użytkownika. Automatycznie testujemy decyzje `RoleGuard` i cron na mocku Discord (`npm run test:roles`).

**Stan 2026-11-12:** potwierdzone na produkcji — konto z rolą Alpha dostało sesję (`login.discord`), konto bez roli dostało 403 (`login.denied`, powód: brak roli Alpha). Pozostaje test konta spoza serwera.

## 7. Typowe błędy

| Objaw | Przyczyna | Rozwiązanie |
| --- | --- | --- |
| `invalid_redirect_uri` | URL w panelu ≠ `DISCORD_REDIRECT_URI` | ustaw identyczny URL |
| `invalid_client` | zły Client Secret | reset secret i aktualizacja GitHub secret |
| 403 „Nie jesteś członkiem” | konto spoza serwera | dodaj konto do serwera |
| 403 „Brak roli Alpha” | brak wymaganej roli | nadaj rolę |
| 403 „Weryfikacja roli nie jest skonfigurowana” | brak Guild ID / Role ID | ustaw sekrety |
| 503 „Nie udało się sprawdzić roli” | Discord 429/5xx, konto nigdy niezweryfikowane | ponów logowanie za chwilę |
| `roleRecheck.status=error` | bot nie widzi członka lub token jest zły | sprawdź bota, `DISCORD_BOT_TOKEN`, uprawnienia |
| 400 „Sesja logowania wygasła” | >10 min lub zły state | odśwież stronę |
| brak cookie | inny origin/HTTPS | `APP_URL` musi być `https://alphavault.pl` |


## 8. Linki wydarzeń

Każde wydarzenie zwracane przez `/api/events` ma pole `url` w formacie:

```
https://discord.com/events/{guild_id}/{event_id}
```

`url` powstaje wyłącznie z numerycznych identyfikatorów Snowflake. Przycisk `Dołącz` w Akademii jest linkiem `<a target="_blank" rel="noopener">` do tego adresu — nie otwiera modala ani `discord.com/app`. Gdy wydarzenia brak, karta live i link są ukryte.
