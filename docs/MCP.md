# AlphaVault MCP 3.2 — OAuth 2.1, 79 narzędzi, zapis bezpośredni i kosz

> Serwer MCP działa na `https://mcp.alphavault.pl` i udostępnia dane wyłącznie
> zalogowanego użytkownika. Zakres `alphavault.read` daje od razu wszystkie
> 79 narzędzi: 46 odczytu i 33 zapisu. Wszystkie 30 operacji zapisu działa
> bezpośrednio; usunięcia są miękkie (kosz + `restore_deleted_record`).
> 3 narzędzia obsługują opcjonalne propozycje z diffem (`propose_change`,
> `approve_proposal`, `reject_proposal`). Nie ma osobnego zakresu zapisu ani
> obowiązkowego kodu z panelu.

## 1. Architektura

```text
ChatGPT / klient MCP
  → https://mcp.alphavault.pl/mcp            (JSON-RPC 2.0, Streamable HTTP)
  → OAuth 2.1 + PKCE S256                    (DCR, code, refresh, revoke)
  → TokenValidator                            (subject = users.id, scope)
  → McpController                             (46 read + 33 write)
  → Tools (odczyt) / WriteTools (zapis)
  → WriteEngine                               (walidacja, formuły, CAS, audyt)
  → user_states.state (JSON)                  (jeden dokument na użytkownika)
  → mcp_proposals / mcp_idempotency / mcp_audit
```

Pliki:

| Warstwa | Plik |
| --- | --- |
| routing i rejestr | `app/Mcp/Http/McpController.php` |
| OAuth, discovery, health | `app/Mcp/Http/OAuthController.php`, `mcp/public/index.php` |
| odczyt | `app/Mcp/Tools/Tools.php`, `app/Mcp/Analytics/*`, `app/Mcp/Support/*` |
| zapis | `app/Mcp/Tools/WriteTools.php`, `app/Mcp/Write/WriteEngine.php`, `app/Mcp/Tools/WriteSchemas.php` |
| propozycje | `app/Mcp/Proposals/ProposalService.php` |
| testy | `tests/mcp-write.test.php`, `tests/mcp-migration-upgrade.test.php`, `tests/mcp-tools.e2e.mjs`, `tests/mcp.e2e.mjs` |

## 2. OAuth 2.1

- Dynamic Client Registration, Authorization Code + PKCE S256, refresh token,
  revoke; brak implicit i client credentials.
- `resource` = `https://mcp.alphavault.pl/mcp`; token jest ważny tylko dla tego zasobu.
- `GET /.well-known/oauth-protected-resource` i
  `GET /.well-known/oauth-authorization-server` opisują zasób i serwer.
- `GET /health` zwraca `mcp.tools`, `mcp.writesEnabled`, `ok` i status bazy.
- Ekran zgody pokazuje zakresy; zapis jest częścią `alphavault.read` i nie wymaga osobnego checkboxa.

### 2.1. Scope

| Scope | Znaczenie |
| --- | --- |
| `alphavault.read` | wszystkie 79 narzędzi: 46 odczytu i 33 zapisu, w tym lista i status propozycji |
| `alphavault.health` | dane zdrowotne (sen, HRV, urządzenia); bez niego pole `health` jest `null` |

Nie ma zakresu `alphavault.write`. Token z `alphavault.read` od razu widzi
i wywołuje narzędzia zapisu; nie trzeba ponownie łączyć klienta po wdrożeniu.

### 2.2. Feature flags

| Zmienna | Domyślnie | Znaczenie |
| --- | --- | --- |
| `MCP_ENABLED` | `true` na domenie MCP | wyłącza całą domenę MCP |
| `MCP_WRITES_ENABLED` | `false` | `true` dodaje 33 narzędzia zapisu (79 łącznie); `false` zostawia 46 odczytu |
| `MCP_PILOT_DISCORD_IDS` | puste | opcjonalna allowlista Discord ID; puste = każdy użytkownik z `alphavault.read`, niepuste = zapis tylko dla wskazanych kont |

`MCP_WRITES_ENABLED=true` bez allowlisty jest poprawną konfiguracją
produkcyjną. Allowlista służy wyłącznie do pilotażu.

## 3. Narzędzia odczytu (46)

| Grupa | Narzędzia |
| --- | --- |
| profil i pulpit | `get_profile`, `get_dashboard`, `get_badges_progress` |
| transakcje | `list_trades`, `get_trade`, `get_trade_context`, `get_trade_journal_context`, `get_trade_review`, `get_trade_screenshots`, `get_trade_patterns` |
| statystyki | `get_stats`, `get_performance_by_setup`, `get_performance_by_emotion`, `get_performance_by_session`, `get_performance_by_error`, `get_performance_by_grade`, `get_performance_by_market`, `get_performance_by_day_plan_compliance` |
| plan dnia | `get_day_plan`, `get_day_plan_history`, `get_day_plan_compliance`, `get_day_plan_context` |
| setup i playbook | `list_setups`, `get_setup_details`, `get_setup_performance` |
| ryzyko | `get_risk_rules`, `get_protection_status`, `get_risk_violations` |
| gotowość | `get_checkin_history`, `get_readiness_context`, `get_checkin_trading_correlation` |
| prop challenge | `get_prop_challenges`, `get_prop_challenge_context`, `get_prop_challenge_risk` |
| kapitał | `get_accounts`, `get_account_transactions`, `get_capital_summary` |
| makro i sync | `get_macro_calendar`, `get_macro_event_details`, `get_events`, `get_integration_sync_history`, `get_connection_status` |
| podatki i kalkulator | `get_tax_summary`, `get_calculator_result` |
| propozycje | `list_pending_proposals`, `get_proposal_status` |

Zasady odczytu:

- brak danych = `[]` albo `null`; bez tekstów zastępczych i domyślnych godzin,
- czas źródłowy UTC + ISO 8601, prezentacja w `Europe/Warsaw`,
- screeny zwracają metadane; obraz tylko po `includeImages=true` (limit 2, 200 KB),
- dane giełdowe i `raw_payload` nie są udostępniane w surowej postaci.

## 4. Narzędzia zapisu (33)

### 4.1. Zapis bezpośredni (12)

Wykonuje się od razu, w transakcji, i zwraca nowy `revision`.

| Narzędzie | Operacja |
| --- | --- |
| `create_trade` | `trade.create` |
| `update_trade` | `trade.update` (pola dziennika; dane giełdowe zablokowane) |
| `close_trade` | `trade.close` (wynik i R liczone serwerowo) |
| `create_trade_review` | `review.create` |
| `update_trade_review` | `review.update` |
| `create_setup` | `setup.create` |
| `update_setup` | `setup.update` |
| `create_day_plan` | `plan.create` (szkic, bez zatwierdzenia) |
| `update_day_plan` | `plan.update` |
| `create_checkin` | `checkin.create` (score z formuły panelu) |
| `update_checkin` | `checkin.update` |
| `add_trade_screenshot` | `screenshot.add` (PNG/JPEG/WebP data URL) |

### 4.2. Operacje wysokiego ryzyka (bezpośrednie)

Te operacje zapisują od razu, tak jak pozostałe. Usunięcia trafiają do kosza
(`state.trash[]`), więc są odwracalne przez `restore_deleted_record`. Jeśli chcesz
najpierw pokazać użytkownikowi diff bez zapisu, użyj `propose_change` z daną
operacją — wtedy `approve_proposal` wykona ją po `confirmed: true`.

| Narzędzie | Operacja |
| --- | --- |
| `delete_trade` | `trade.delete` |
| `delete_trade_review` | `review.delete` |
| `delete_setup` | `setup.delete` |
| `delete_day_plan` | `plan.delete` |
| `approve_day_plan` | `plan.approve` |
| `delete_checkin` | `checkin.delete` |
| `create_account`, `update_account`, `delete_account` | `account.*` |
| `create_account_transaction`, `update_account_transaction`, `delete_account_transaction` | `flow.*` |
| `update_risk_rules` | `risk.update` (tylko zaostrzanie) |
| `create_prop_challenge`, `update_prop_challenge`, `delete_prop_challenge` | `prop.*` |
| `delete_trade_screenshot` | `screenshot.delete` |
| `restore_deleted_record` | `restore.record` |

### 4.3. Propozycje i decyzje (3)

| Narzędzie | Działanie |
| --- | --- |
| `propose_change` | dowolna operacja zapisu jako propozycja `write_op` z podglądem diff |
| `approve_proposal` | wykonanie propozycji po `confirmed: true` w rozmowie; opcjonalny `confirmationCode` z panelu |
| `reject_proposal` | odrzucenie propozycji |

`propose_change` przyjmuje `operation` z listy 30 operacji i `args` zgodne z
danym narzędziem. Stosuj je, gdy chcesz pokazać diff bez tworzenia wpisu.

## 5. Kontrakt zapisu

Każde wywołanie zapisu przyjmuje:

| Pole | Wymagane | Znaczenie |
| --- | --- | --- |
| `idempotencyKey` | tak | 8-128 znaków; powtórka zwraca zapisaną odpowiedź z `duplicate: true` |
| `expectedRevision` | nie | optimistic lock na `user_states.revision`; niezgodność = `revision_conflict` (409) |

Odpowiedź bezpośrednia:

```json
{
  "ok": true,
  "applied": "direct",
  "operation": "trade.create",
  "target": "trade:manual_1a2b3c4d",
  "revision": 42,
  "revisionBefore": 41,
  "record": { "id": "manual_1a2b3c4d" },
  "changes": ["checklist", "dir", "entry"],
  "diff": [{ "field": "entry", "before": null, "after": "100" }],
  "note": "Zapis trwały potwierdzony w bazie."
}
```

Odpowiedź propozycji:

```json
{
  "ok": true,
  "applied": "proposal",
  "operation": "trade.delete",
  "status": "pending",
  "proposalId": "6f1c2a9e-...",
  "diffHash": "<64 hex>",
  "baseRevision": 42,
  "changes": ["deleted"],
  "diff": [{ "field": "deleted", "before": null, "after": true }],
  "preview": { "deleted": true, "tradeId": "manual_1a2b3c4d", "trashId": "tr_..." },
  "expiresAt": "2026-11-16T21:00:00Z"
}
```

Podgląd liczy ta sama funkcja co zapis (`WriteEngine::apply()`), więc diff nie
może rozjechać się z wykonaniem. `revision` rośnie tylko przy realnym zapisie.

## 6. Idempotencja, wersje i audyt

- `mcp_idempotency` przechowuje fingerprint `{op, args, expectedRevision}` i odpowiedź 24 h;
  zgodna powtórka nie zmienia stanu i zwraca `duplicate: true`. Klucze są porównywane
  case-sensitive; stare wpisy bez fingerprintu są fail-closed do wygaśnięcia.
- Ten sam `idempotencyKey` z inną treścią = `idempotency_conflict` (409), bez drugiej mutacji.
- Decyzja propozycji wiąże klucz z akcją, propozycją, `expectedDiffHash` i fingerprintem
  kodu potwierdzającego (bez zapisywania kodu). Dla starszych zatwierdzeń fingerprint jest
  odtwarzany tylko po zgodności statusu, diffu i — gdy użyto kodu — hasha kodu; stare decyzje,
  których treści nie da się zweryfikować, są odrzucane jako konflikt.
- `expectedRevision` porównywane jest w transakcji `SELECT ... FOR UPDATE`.
- Każdy zapis i decyzja trafia do `mcp_audit` (aktor, operacja, target, revision, zmiany).
- Propozycja ma `base_revision`; zatwierdzenie po zmianie stanu kończy się
  `revision_conflict` i wymaga nowej propozycji.

## 7. Kosz i przywracanie

- Usunięcia nie kasują danych fizycznie: rekord trafia do `state.trash[]` z
  identyfikatorem `tr_*` zwracanym jako `trashId`.
- `restore_deleted_record` przywraca wpis z kosza bezpośrednio; usunięcie i przywrócenie są audytowane.
- Konflikt przywracania (np. transakcja o tym ID już istnieje) = `restore_conflict`.
- Kosz obejmuje transakcje, recenzje, setupy, plany, check-iny, konta, wpływy
  kapitału, prop challenge i screeny.

## 8. Reguły ryzyka

`update_risk_rules` może wyłącznie zaostrzać zabezpieczenia:

- `maxRisk`, `maxLosses`, `maxDailyLoss` — tylko w dół,
- `block` — tylko dłuższy (`1h` → `4h` → `midnight`),
- `safetyEnabled`, `requireReason`, `pushLimit` — tylko włączanie,
- `rules`, `checklist` — tylko dopisywanie pozycji,
- `reason` — 10-500 znaków.

Poluzowanie limitu = `risk_not_tightened` (403). MCP nigdy nie zdejmuje blokady
po stratach i nie zmienia ustawień poza tymi polami.

## 9. Opcjonalna propozycja

Zwykły zapis nie tworzy propozycji. `propose_change` jest opcją, gdy chcesz
pokazać diff przed wykonaniem:

```text
Agent → propose_change(operation, args) → propozycja pending + diffHash + preview
Agent → pokazuje diff i pyta użytkownika wprost
Użytkownik → „tak” w rozmowie
Agent → approve_proposal(proposalId, expectedDiffHash, confirmed=true, idempotencyKey)
Serwer → sprawdza diffHash, TTL i base_revision → zapis + revision
```

- `confirmed: true` jest wymagane tylko dla `approve_proposal`; bez niego
  narzędzie zwraca `confirmation_required`. Agent ustawia je wyłącznie po
  jednoznacznym „tak” użytkownika w tej rozmowie.
- `confirmationCode` z panelu jest opcjonalny. Jeśli użytkownik go poda, serwer
  zweryfikuje kod; jeśli nie — wystarczy `confirmed: true`.
- Propozycja: TTL 30 minut; wygasła = `proposal_expired`.
- Panel AlphaVault pozwala zatwierdzić lub odrzucić propozycję i wygenerować kod.
- Odrzucenie: `reject_proposal` (status `rejected`, stan bez zmian).

## 10. Model danych

| Tabela | Rola |
| --- | --- |
| `user_states` | jeden dokument JSON na użytkownika; `revision` = wersja |
| `mcp_proposals` | propozycje: `kind` (`write_op` i typy historyczne), `target_ref`, `base_revision`, `diff_hash`, `after_json`, `status`, TTL, kod jednorazowy, case-sensitive klucz i fingerprint decyzji |
| `mcp_idempotency` | case-sensitive klucz, fingerprint payloadu i odpowiedź zapisu na 24 h |
| `mcp_audit` | ślad operacji agenta, użytkownika i systemu |

`write_op` przechowuje `{op, args}`; zatwierdzenie uruchamia tę samą operację
`WriteEngine`, więc wynik jest identyczny z podglądem. Typy historyczne
(`manual_trade`, `trade_update`, `day_plan`, `checkin`, `setup`, `prop_challenge`,
`account`, `capital_flow`, `risk_rules`, `trade_review`, `close_trade`) pozostają
obsługiwane dla starszych wierszy.

## 11. Ochrona danych giełdowych

- Transakcja zaimportowana z giełdy (`exchange_imports`) jest tylko do odczytu
  w polach wykonania: `entry`, `exit`, `size`, `sl`, `tp`, `result`, `r`, `fee`.
- Dozwolone zmiany: ocena, emocje, notatka, checklista, błędy, setup, sesja, plan.
- `close_trade` i `delete_trade` odmawiają dla transakcji giełdowej.
- Nowa transakcja przez MCP zawsze ma `source: "Ręcznie"`.
- MCP nie składa zleceń, nie zmienia kluczy API, nie usuwa wydarzeń Discord ani
  wpisów kalendarza makro, nie dotyka ustawień kont i haseł.

## 12. Kody błędów

| Kod | HTTP | Znaczenie |
| --- | --- | --- |
| `writes_disabled` | — | `MCP_WRITES_ENABLED=false`; użyj odczytu |
| `pilot_only` | — | konto poza allowlistą pilotażową |
| `auth_required` | — | brak konta AlphaVault dla tokenu |
| `revision_conflict` | 409 | stan zmienił się od odczytu |
| `idempotency_conflict` | 409 | klucz użyty z inną treścią |
| `bad_request` | 400 | niepoprawne argumenty |
| `not_found` / `*_missing` | 404 | brak rekordu |
| `field_not_editable` | 400 | pole spoza kontraktu MCP |
| `exchange_data_readonly` | 403 | dane wykonania transakcji giełdowej |
| `risk_not_tightened` | 403 | próba poluzowania zabezpieczeń |
| `confirmation_required` | 403 | brak `confirmed: true` albo aktywnego kodu |
| `invalid_confirmation` | 403 | kod nieprawidłowy albo zużyty |
| `diff_mismatch` | 409 | `expectedDiffHash` niezgodny z propozycją |
| `proposal_expired` / `proposal_closed` | 409 | propozycja wygasła albo już rozstrzygnięta |
| `state_too_large` | 413 | dokument przekracza limit `maxStateBytes` |
| `trash_missing` / `restore_conflict` | 404/409 | kosz: brak wpisu albo konflikt przywracania |

Komunikat narzędzia ma postać `[error_code] czytelny opis po polsku`.

## 13. Wdrożenie

Workflow `Deploy AlphaVault MCP` (`.github/workflows/deploy-mcp.yml`) uruchamia
`scripts/deploy-mcp.sh`, migrację, klucze OAuth i health check.

```powershell
gh workflow run deploy-mcp.yml --repo miloszspyrka-code/av --ref main `
  -f ref=main -f dry_run=false -f writes_enabled=true
```

- `writes_enabled` domyślnie `true`; `false` wyłącza 33 narzędzia zapisu (zostaje 46 odczytu).
- `pilot_discord_ids` (CSV) opcjonalnie ogranicza zapis do wybranych kont.
- Health check wymaga `tools=79` i `writesEnabled=true` przy włączonym zapisie oraz braku `alphavault.write` w metadanych OAuth.
- Wdrożenie nie dotyka `alphavault.pl` ani innych domen.

## 14. Testy

| Test | Zakres |
| --- | --- |
| `php tests/mcp-write.test.php` | rejestr 30 operacji, routing zgody, schematy 33 narzędzi (w tym `confirmed`), formuły i walidacja `apply()` bez DB |
| `php tests/mcp.test.php` | tokeny, PKCE, revoke, rate limit, nagłówki, zatwierdzenie kodem i bez kodu |
| `node tests/mcp.e2e.mjs` | OAuth, 79 narzędzi z `alphavault.read`, cykl create → update → close → delete → restore, idempotencja, revision, `confirmed`, pilot |
| `node tests/mcp-tools.e2e.mjs` | pełne cykle wszystkich 33 narzędzi, propozycje, `confirmed` w rozmowie, odrzucenie, kosz, workflow analityczne |
| `node tests/mcp-login.e2e.mjs` | logowanie i panel MCP |

Bramką jest workflow `CI` (`PHP + API + UI`). Deploy jest poprawny dopiero po
zielonym runie i health checku `https://mcp.alphavault.pl/health`.

## 15. Prywatność i granice

- Serwer nie przyjmuje `user_id`; tożsamość wynika wyłącznie z tokenu.
- Jedno połączenie OAuth daje od razu wszystkie 79 narzędzi; nie ma drugiego zakresu ani reconnectu po wdrożeniu.
- Wszystkie operacje zapisu działają bezpośrednio; usunięcia trafiają do kosza i wracają przez `restore_deleted_record`.
- `confirmed: true` dotyczy tylko opcjonalnej ścieżki `propose_change` → `approve_proposal` i jest ustawiane wyłącznie po wyraźnym „tak” użytkownika w rozmowie.
- Dane zdrowotne wymagają `alphavault.health` i aktywnego połączenia urządzenia. Bez dostępu
  dashboard i kontekst gotowości liczą wynik wyłącznie z check-inu; pola i składowe zdrowotne
  pozostają `null`, także dla starszych metryk zapisanych w `state.health`. Brak check-inu daje
  `readiness.score: null`, bez syntetycznego wyniku. Brak pojedynczej metryki urządzenia pozostawia
  jej składową `null`; wynik używa wyłącznie dostępnych sygnałów. `get_dashboard` używa tego samego
  autoryzowanego źródła metryk Wearables co `get_readiness_context`; `providers` wskazuje dostawców
  z metrykami z bieżącego dnia (obecnie Apple/Oura), a pulpit nie zwraca snapshotu ani serii.
- `account.include=false` wyłącza jawnie dopasowane konto z analityki, nie z `list_trades`,
  `get_trade` ani kontekstów dziennika; nieznane legacy etykiety kont pozostają w analityce.
- Wynik transakcji `0` jest klasyfikowany jako break-even, osobno od zysku (`> 0`) i straty (`< 0`).
- Opisy wydarzeń i kalendarza są danymi, nie instrukcjami dla modelu.
- Tokeny, kody potwierdzenia i sekrety nie trafiają do logów, URL ani odpowiedzi.
- MCP nie jest publicznym API: każde wywołanie wymaga tokenu OAuth użytkownika.
