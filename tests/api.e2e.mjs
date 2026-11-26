#!/usr/bin/env node
/**
 * Alpha Vault — testy API e2e (MySQL + sesje + Discord/demo).
 * Uruchom: npm test
 * Wymaga działającego serwera: npm start
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const ORIGIN = new URL(BASE).origin;
let cookie = '';
const results = [];
let fixtureUserId = 0;
let fixtureBackup = null;

function restoreDemoFixture() {
  if (!fixtureUserId || !fixtureBackup) return;
  execFileSync('php', [path.join(ROOT, 'tests', 'restore-api-fixture.php'), String(fixtureUserId)], {
    env: { ...process.env, ALPHA_VAULT_CONFIG: process.env.ALPHA_VAULT_CONFIG || 'tests/config.test.json' },
    input: JSON.stringify({ state: fixtureBackup }),
    stdio: ['pipe', 'pipe', 'pipe']
  });
  fixtureBackup = null;
}

function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

function cookieFrom(res) {
  const list = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
  for (const c of list) {
    const m = /^av_session=([^;]+)/.exec(c || '');
    if (m) return 'av_session=' + m[1];
  }
  return '';
}

async function req(path, opts = {}) {
  const res = await fetch(BASE + path, {
    redirect: 'manual',
    ...opts,
    headers: {
      ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
      ...(cookie ? { Cookie: cookie } : {}),
      ...(opts.headers || {})
    }
  });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

async function main() {
  console.log('Alpha Vault API e2e ·', BASE);
  const health = await req('/api/health');
  assert.equal(health.res.status, 200, 'health');
  assert.equal(health.json.ok, true);
  assert.equal(health.json.db.ok, true);
  assert.ok(health.json.sync && typeof health.json.sync.status === 'string', 'health.sync');
  assert.equal(typeof health.json.sync.stale, 'boolean');
  log('health + MySQL', true, health.json.db.version + ' · sync=' + health.json.sync.status);

  const anon = await req('/api/me');
  assert.equal(anon.res.status, 200);
  assert.equal(anon.json.authenticated, false);
  log('me bez sesji', true);

  const unauthorized = await req('/api/state');
  assert.equal(unauthorized.res.status, 401);
  log('state bez sesji = 401', true);

  const eventsAnon = await req('/api/events');
  assert.equal(eventsAnon.res.status, 401);
  log('events bez sesji = 401', true);

  const academyAnon = await req('/api/academy/materials');
  assert.equal(academyAnon.res.status, 401);
  assert.equal(academyAnon.json.error, 'unauthorized');
  const academyWrite = await req('/api/academy/materials', { method: 'POST', body: '{}' });
  assert.equal(academyWrite.res.status, 405);
  assert.equal(academyWrite.res.headers.get('allow'), 'GET');
  log('academy materials: anon = 401, metoda poza GET = 405', true);

  const macroAnon = await req('/api/macro-events');
  assert.equal(macroAnon.res.status, 401);
  log('macro-events bez sesji = 401', true);

  const demo = await req('/auth/demo', {
    method: 'POST',
    body: JSON.stringify({ remember: true }),
    headers: { Origin: ORIGIN }
  });
  if (demo.res.status === 403) {
    log('demo login wyłączony — pomijam resztę', true);
    return;
  }
  assert.equal(demo.res.status, 200, demo.text);
  cookie = cookieFrom(demo.res);
  assert.ok(cookie, 'brak cookie sesji');
  const setCookie = (demo.res.headers.getSetCookie ? demo.res.headers.getSetCookie() : []).join(';');
  assert.match(setCookie, /HttpOnly/i);
  assert.match(setCookie, /SameSite=Lax/i);
  log('demo login', true, 'cookie HttpOnly + SameSite=Lax');

  const discordStart = await req('/auth/discord?remember=1');
  if (anon.json.discordConfigured) {
    assert.equal(discordStart.res.status, 302);
    assert.match(discordStart.res.headers.get('location') || '', /discord\.com\/api\/oauth2\/authorize/);
    log('OAuth start → Discord', true);
  } else {
    assert.equal(discordStart.res.status, 503);
    log('OAuth start bez konfiguracji = 503', true);
  }
  const badCallback = await req('/auth/discord/callback?code=x&state=y');
  assert.equal(badCallback.res.status, 400);
  log('callback z złym state = 400', true);

  const me = await req('/api/me');
  assert.equal(me.json.authenticated, true);
  assert.equal(me.json.user.isDemo, true);
  log('me z sesją', true, me.json.user.username);
  const academyDemo = await req('/api/academy/materials');
  assert.equal(academyDemo.res.status, 403);
  assert.equal(academyDemo.json.error, 'forbidden');
  assert.equal(academyDemo.json.items, undefined, 'konto demo nie może otrzymać materiałów Akademii');
  log('academy materials: konto demo bez entitlementu = 403', true);
  fixtureUserId = Number(me.json.user.id);
  const baseline = await req('/api/state');
  assert.equal(baseline.res.status, 200, baseline.text);
  assert.ok(baseline.json.state, 'brak bazowego stanu demo do odtworzenia');
  fixtureBackup = JSON.parse(JSON.stringify(baseline.json.state));

  const events = await req('/api/events');
  assert.equal(events.res.status, 200, events.text);
  assert.ok(Array.isArray(events.json.events));
  assert.ok(events.json.sync && typeof events.json.sync.status === 'string');
  for (const event of events.json.events) {
    assert.match(event.url || '', /^https:\/\/discord\.com\/events\/\d{17,20}\/\d{17,20}$/);
  }
  log('events z sesją', true, events.json.events.length + ' wydarzeń · ' + events.json.events.filter((e) => e.url).length + ' z linkiem');

  const macro = await req('/api/macro-events');
  assert.equal(macro.res.status, 200, macro.text);
  assert.ok(Array.isArray(macro.json.events));
  assert.ok(macro.json.sync && typeof macro.json.sync.status === 'string');
  assert.equal(typeof macro.json.sync.stale, 'boolean');
  for (const event of macro.json.events) {
    assert.ok(event.id && event.name && event.currency, 'macro event shape');
    assert.match(event.startAt, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    assert.ok(event.impact === 2 || event.impact === 3, 'macro impact 2/3');
  }
  log('macro-events z sesją', true, macro.json.events.length + ' publikacji · sync=' + macro.json.sync.status);

  const adminEvents = await req('/api/admin/events');
  assert.equal(adminEvents.res.status, 403);
  log('admin events bez uprawnień = 403', true);

  const isLocal = /^http:\/\/(127\.0\.0\.1|localhost)(:|\/)/.test(BASE);
  if (isLocal) {
    try {
      execFileSync('php', [path.join(ROOT, 'tests', 'seed-corrupt-state.php'), String(me.json.user.id)], {
        env: { ...process.env, ALPHA_VAULT_CONFIG: process.env.ALPHA_VAULT_CONFIG || 'tests/config.test.json' },
        stdio: 'pipe'
      });
    } catch (err) {
      console.error('seed stdout:', err.stdout?.toString() || '');
      console.error('seed stderr:', err.stderr?.toString() || '');
      throw err;
    }
    log('seed uszkodzonego stanu (MySQL)', true);
  }

  const state = await req('/api/state');
  assert.equal(state.res.status, 200);
  assert.ok(state.json.state, 'brak stanu demo');
  assert.ok(Array.isArray(state.json.state.trades));
  assert.equal(typeof state.json.userId, 'number');
  if (isLocal) {
    assert.equal(
      state.json.state.chat[0].html,
      'Cześć {imie}. Widzę dziś 0 straty i gotowość 66/100, więc jestem w pogotowiu.'
    );
    assert.equal(state.json.state.user.name, 'Miłosz Spyrka');
    log('GET /api/state naprawia mojibake z MySQL', true);
  }
  log('GET /api/state', true, `rewizja ${state.json.revision}, transakcje ${state.json.state.trades.length}`);

  const originalState = JSON.parse(JSON.stringify(fixtureBackup));
  const propFixture = {
    id: 'prop_e2e_' + Date.now(),
    name: 'E2E Prop 10K',
    firm: 'FTMO',
    templateId: 'ftmo-2step',
    phase: 'Phase 1',
    account: 'WEEX',
    currency: 'USD',
    accountSize: 10000,
    startBalance: 10000,
    targetPct: 10,
    dailyLossPct: 5,
    maxLossPct: 10,
    drawdownType: 'static',
    dailyBasis: 'initial',
    minDays: 4,
    startDate: '2026-01-01',
    endDate: '2026-12-31',
    notes: 'fixture',
    archived: false,
    createdAt: new Date().toISOString()
  };
  originalState.prop = { challenges: [propFixture], selectedId: propFixture.id };
  const marker = 'e2e-' + Date.now();
  originalState.meta = originalState.meta || {};
  originalState.meta.e2e = marker;
  const put = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: originalState, baseRevision: state.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(put.res.status, 200, put.text);
  assert.ok(put.json.revision > state.json.revision);
  log('PUT /api/state', true, 'rewizja ' + put.json.revision);

  const saved = await req('/api/state');
  assert.equal(saved.json.state.meta.e2e, marker);
  assert.equal(saved.json.revision, put.json.revision);
  log('odczyt po zapisie (MySQL)', true);

  const savedProp = saved.json.state.prop?.challenges?.[0];
  assert.equal(savedProp?.id, propFixture.id);
  assert.equal(savedProp?.name, 'E2E Prop 10K');
  assert.equal(savedProp?.account, 'WEEX');
  assert.equal(savedProp?.targetPct, 10);
  assert.equal(savedProp?.drawdownType, 'static');
  assert.equal(savedProp?.startBalance, 10000);
  assert.equal(saved.json.state.prop.selectedId, propFixture.id);
  log('prop challenge zapisany w MySQL', true, savedProp.name);

  const noOpPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: saved.json.state, baseRevision: saved.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(noOpPut.res.status, 200, noOpPut.text);
  assert.equal(noOpPut.json.revision, saved.json.revision, 'no-op must preserve revision');
  assert.equal(noOpPut.json.updatedAt, saved.json.updatedAt, 'no-op must preserve updated_at');
  log('PUT /api/state no-op nie podnosi rewizji', true, String(noOpPut.json.revision));

  const aliasNoOpState = JSON.parse(JSON.stringify(saved.json.state));
  const aliasNoOpTrade = aliasNoOpState.trades.find((trade) => trade.dir === 'l' || trade.dir === 's');
  assert.ok(aliasNoOpTrade, 'demo state needs a trade direction for alias normalization');
  aliasNoOpTrade.dir = aliasNoOpTrade.dir === 's' ? 'short' : 'long';
  const aliasNoOpPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: aliasNoOpState, baseRevision: noOpPut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(aliasNoOpPut.res.status, 200, aliasNoOpPut.text);
  assert.equal(aliasNoOpPut.json.revision, noOpPut.json.revision, 'direction alias normalization is a no-op');
  log('PUT /api/state alias kierunku nie podnosi rewizji', true);
  const noTrashNoOpState = JSON.parse(JSON.stringify(aliasNoOpState));
  assert.ok(!Array.isArray(noTrashNoOpState.trash) || noTrashNoOpState.trash.length === 0, 'API fixture should start with an empty trash');
  delete noTrashNoOpState.trash;
  const noTrashNoOpPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: noTrashNoOpState, baseRevision: aliasNoOpPut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(noTrashNoOpPut.res.status, 200, noTrashNoOpPut.text);
  assert.equal(noTrashNoOpPut.json.revision, aliasNoOpPut.json.revision, 'omitted empty trash must preserve revision');

  const manualTradeWithoutStop = JSON.parse(JSON.stringify(saved.json.state));
  manualTradeWithoutStop.trades.push({
    id: 'e2e_missing_sl_' + Date.now(), date: '2026-11-17', pair: 'E2E/USDT', dir: 'l',
    entry: '100', exit: 'otwarta', sl: '', tp: '110', size: '1', result: null, source: 'Ręcznie'
  });
  const rejectedMissingStop = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: manualTradeWithoutStop, baseRevision: saved.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedMissingStop.res.status, 422, rejectedMissingStop.text);
  assert.equal(rejectedMissingStop.json.error, 'stop_loss_required');
  const spoofedImportWithoutStop = JSON.parse(JSON.stringify(saved.json.state));
  spoofedImportWithoutStop.trades.push({
    id: 'e2e_spoofed_import_' + Date.now(), date: '2026-11-17', pair: 'E2E/USDT', dir: 'l',
    entry: '100', exit: 'otwarta', sl: '', tp: '110', size: '1', result: null, source: 'CSV'
  });
  const rejectedSpoofedImport = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: spoofedImportWithoutStop, baseRevision: saved.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedSpoofedImport.res.status, 422, rejectedSpoofedImport.text);
  assert.equal(rejectedSpoofedImport.json.error, 'stop_loss_required');
  const spoofedTrashState = JSON.parse(JSON.stringify(saved.json.state));
  spoofedTrashState.trash = Array.isArray(spoofedTrashState.trash) ? spoofedTrashState.trash : [];
  spoofedTrashState.trash.push({
    id: 'tr_e2e_spoofed_' + Date.now(),
    kind: 'trade',
    record: {
      id: 'e2e_spoofed_trash_' + Date.now(), date: '2026-11-17', pair: 'E2E/USDT', dir: 'l',
      entry: '100', exit: 'otwarta', sl: '', tp: '110', size: '1', result: null, source: 'CSV'
    }
  });
  const rejectedSpoofedTrash = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: spoofedTrashState, baseRevision: saved.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedSpoofedTrash.res.status, 422, rejectedSpoofedTrash.text);
  assert.equal(rejectedSpoofedTrash.json.error, 'trade_trash_source_invalid');

  const invalidTradeDateState = JSON.parse(JSON.stringify(saved.json.state));
  invalidTradeDateState.trades.push({
    id: 'e2e_invalid_date_' + Date.now(), date: 'tomorrow', pair: 'E2E/USDT', dir: 'l',
    entry: '100', exit: 'otwarta', sl: '90', tp: '110', size: '1', result: null, source: 'Ręcznie'
  });
  const rejectedTradeDate = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: invalidTradeDateState, baseRevision: saved.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedTradeDate.res.status, 422, rejectedTradeDate.text);
  assert.equal(rejectedTradeDate.json.error, 'invalid_trade_date');
  const malformedClosedAtState = JSON.parse(JSON.stringify(saved.json.state));
  malformedClosedAtState.trades.push({
    id: 'e2e_invalid_closed_at_' + Date.now(), date: '2026-11-17', pair: 'E2E/USDT', dir: 'l',
    entry: '100', exit: '100', sl: '90', tp: '110', size: '1', result: 0,
    closedAt: '2026-11-17T10:00:00Z\n', source: 'Ręcznie'
  });
  const rejectedMalformedClosedAt = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: malformedClosedAtState, baseRevision: saved.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedMalformedClosedAt.res.status, 422, rejectedMalformedClosedAt.text);
  assert.equal(rejectedMalformedClosedAt.json.error, 'invalid_closed_at');
  const unchangedAfterNewTradeValidation = await req('/api/state');
  assert.equal(unchangedAfterNewTradeValidation.json.revision, saved.json.revision);
  assert.deepEqual(unchangedAfterNewTradeValidation.json.state, saved.json.state);
  log('PUT /api/state wymaga SL i poprawnej daty dla nowej transakcji', true);

  const tradeIntegrityState = JSON.parse(JSON.stringify(saved.json.state));
  const integrityTradeId = 'e2e_trade_rules_' + Date.now();
  const externalTradeId = 'e2e_external_trade_' + Date.now();
  const externalTradeIdValue = 'e2e-csv-' + Date.now();
  const partialTradeId = 'e2e_partial_recalc_' + Date.now();
  const overrideTradeId = 'e2e_result_override_' + Date.now();
  const malformedPartialTradeId = 'e2e_malformed_partial_' + Date.now();
  tradeIntegrityState.trades.push({
    id: integrityTradeId, date: '2026-11-17T10:00:00Z', pair: ' E2E/USDT ', dir: 'long',
    entry: '100,000000001', exit: 'otwarta', sl: '90', tp: '110', size: '1',
    result: '', r: null, source: 'Ręcznie', account: 'WEEX', fee: 0,
    mae: null, mfe: null, durationMin: 0
  });
  tradeIntegrityState.trades.push({
    id: partialTradeId, date: '2026-11-17T10:00:00Z', pair: 'E2EPART/USDT', dir: 'l',
    entry: '100', exit: '120', sl: '90', tp: '140', size: '2.5', initialSize: '10',
    partials: [
      { price: '110', pct: 50, result: 40, resultOverride: true, at: '2026-11-17T10:05:00Z' },
      { price: '120', pct: 50, result: 50, resultOverride: false, at: '2026-11-17T10:10:00Z' }
    ],
    partialResult: 90, resultOverride: null, result: 140, r: 1.4, risk: 100, riskPct: null,
    sizeUnit: 'contracts', sizeContractSize: 100, source: 'Ręcznie',
    closedAt: '2026-11-17T10:15:00Z'
  });
  tradeIntegrityState.trades.push({
    id: overrideTradeId, date: '2026-11-17T10:00:00Z', pair: 'E2EOVERRIDE/USDT', dir: 'l',
    entry: '100', exit: '110', sl: '90', tp: '120', size: '2', initialSize: '2',
    resultOverride: 777, result: 777, r: 38.85, source: 'Ręcznie',
    closedAt: '2026-11-17T10:20:00Z'
  });
  tradeIntegrityState.trades.push({
    id: malformedPartialTradeId, date: '2026-11-17T10:00:00Z', pair: 'E2EBADPART/USDT', dir: 'l',
    entry: '100', exit: '120', sl: '90', tp: '140', size: '5', initialSize: '10',
    partials: [{ price: '110', pct: 150, result: 50, resultOverride: false, at: '2026-11-17T10:05:00Z' }],
    partialResult: 50, result: 100, r: 1, source: 'Ręcznie',
    closedAt: '2026-11-17T10:15:00Z'
  });
  tradeIntegrityState.trades.push({
    id: externalTradeId, date: '2026-11-17T10:00:00Z', pair: 'E2EEXT/USDT', dir: 'l',
    entry: '200', exit: '205', sl: '190', tp: '220', size: '1',
    result: 5, r: 0.5, source: 'CSV', extId: externalTradeIdValue,
    initialSize: '2',
    partials: [{ price: '202', pct: 50, result: 2, resultOverride: false, at: '2026-11-17T10:05:00Z' }],
    partialResult: 2, resultOverride: null, sizeUnit: 'contracts', sizeContractSize: 1,
    importedAt: new Date().toISOString()
  });
  const zeroExternalTradeId = 'e2e_external_zero_id_' + Date.now();
  tradeIntegrityState.trades.push({
    id: zeroExternalTradeId, date: '2026-11-17T10:00:00Z', pair: 'E2EZERO/USDT', dir: 'l',
    entry: '300', exit: '310', sl: '290', tp: '330', size: '1',
    result: 10, r: 1, source: 'Ręcznie', extId: '0',
    initialSize: '2',
    partials: [{ price: '305', pct: 50, result: 5, resultOverride: false, at: '2026-11-17T10:05:00Z' }],
    partialResult: 5, resultOverride: null, sizeUnit: 'contracts', sizeContractSize: 1
  });
  const validTradePut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: tradeIntegrityState, baseRevision: noOpPut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(validTradePut.res.status, 200, validTradePut.text);
  const normalizedAliasState = await req('/api/state');
  const normalizedIntegrityTrade = normalizedAliasState.json.state.trades.find((trade) => trade.id === integrityTradeId);
  const normalizedPartialTrade = normalizedAliasState.json.state.trades.find((trade) => trade.id === partialTradeId);
  const normalizedExternalTrade = normalizedAliasState.json.state.trades.find((trade) => trade.id === externalTradeId);
  const normalizedZeroExternalTrade = normalizedAliasState.json.state.trades.find((trade) => trade.id === zeroExternalTradeId);
  assert.equal(normalizedIntegrityTrade.dir, 'l');
  assert.equal(normalizedIntegrityTrade.pair, 'E2E/USDT');
  assert.equal(normalizedIntegrityTrade.entry, '100.000000001');
  assert.equal(normalizedIntegrityTrade.result, null, 'blank result must stay open and be normalized to null');
  assert.equal(normalizedPartialTrade?.partials[0].resultOverride, true);
  assert.equal(normalizedPartialTrade?.partials[0].at, '2026-11-17T10:05:00Z');
  assert.equal(normalizedExternalTrade?.source, 'CSV');
  assert.equal(normalizedExternalTrade?.extId, externalTradeIdValue);
  assert.equal(normalizedZeroExternalTrade?.extId, '0');
  const staleIdenticalWrite = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: normalizedAliasState.json.state, baseRevision: noTrashNoOpPut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(staleIdenticalWrite.res.status, 409, staleIdenticalWrite.text);
  assert.equal(staleIdenticalWrite.json.error, 'revision_conflict');
  const objectTradeCollection = JSON.parse(JSON.stringify(normalizedAliasState.json.state));
  objectTradeCollection.trades = { [integrityTradeId]: normalizedIntegrityTrade };
  const rejectedObjectTradeCollection = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: objectTradeCollection, baseRevision: validTradePut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedObjectTradeCollection.res.status, 422, rejectedObjectTradeCollection.text);
  assert.equal(rejectedObjectTradeCollection.json.error, 'invalid_trade_list');

  for (const [field, value, expectedError] of [
    ['sl', '105', 'invalid_stop_loss'],
    ['tp', '95', 'invalid_take_profit']
  ]) {
    const invalidState = JSON.parse(JSON.stringify(tradeIntegrityState));
    invalidState.trades.find((trade) => trade.id === integrityTradeId)[field] = value;
    const invalidPut = await req('/api/state', {
      method: 'PUT',
      body: JSON.stringify({ state: invalidState, baseRevision: validTradePut.json.revision }),
      headers: { Origin: ORIGIN }
    });
    assert.equal(invalidPut.res.status, 422, invalidPut.text);
    assert.equal(invalidPut.json.error, expectedError);
  }
  const unchangedAfterRejectedWrites = await req('/api/state');
  const unchangedTrade = unchangedAfterRejectedWrites.json.state.trades.find((trade) => trade.id === integrityTradeId);
  assert.equal(unchangedAfterRejectedWrites.json.revision, validTradePut.json.revision);
  assert.equal(unchangedTrade.sl, '90');
  assert.equal(unchangedTrade.tp, '110');
  log('PUT /api/state odrzuca błędny SL/TP atomowo', true, unchangedTrade.id);

  const closedTradeState = JSON.parse(JSON.stringify(tradeIntegrityState));
  const closedFixture = closedTradeState.trades.find((trade) => trade.id === integrityTradeId);
  Object.assign(closedFixture, { exit: '105', result: 5, r: 0.5, closedAt: null });
  const closedTradePut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: closedTradeState, baseRevision: validTradePut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(closedTradePut.res.status, 200, closedTradePut.text);
  assert.match(closedTradePut.json.updatedAt, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  const changedClosedExecution = JSON.parse(JSON.stringify(closedTradeState));
  Object.assign(
    changedClosedExecution.trades.find((trade) => trade.id === integrityTradeId),
    { entry: '101', exit: '111', result: 999, r: 999 }
  );
  const correctedClosedExecution = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: changedClosedExecution, baseRevision: closedTradePut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(correctedClosedExecution.res.status, 200, correctedClosedExecution.text);
  assert.ok(correctedClosedExecution.json.revision > closedTradePut.json.revision);
  const correctedClosedState = await req('/api/state');
  const correctedManualTrade = correctedClosedState.json.state.trades.find((trade) => trade.id === integrityTradeId);
  assert.equal(correctedManualTrade.entry, '101');
  assert.equal(correctedManualTrade.exit, '111');
  assert.equal(correctedManualTrade.result, 10, 'result must be recalculated from the edited execution, not kept from the client');
  assert.equal(correctedManualTrade.risk, 11);
  assert.equal(correctedManualTrade.r, 0.91, 'R must be recalculated from base-unit risk');
  assert.equal(correctedManualTrade.closedAt, null);
  log('PUT /api/state przelicza wynik i R po korekcie zamkniętego wykonania ręcznego', true, integrityTradeId);

  const changedPartialExecution = JSON.parse(JSON.stringify(correctedClosedState.json.state));
  const partialTrade = changedPartialExecution.trades.find((trade) => trade.id === partialTradeId);
  Object.assign(partialTrade, {
    entry: '105',
    exit: '135',
    partialResult: 999,
    result: 999,
    r: 999,
    risk: 999
  });
  partialTrade.partials.forEach((partial) => { partial.result = 999; });
  const partialCorrectionPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: changedPartialExecution, baseRevision: correctedClosedState.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(partialCorrectionPut.res.status, 200, partialCorrectionPut.text);
  const partialCorrectionState = await req('/api/state');
  const correctedPartialTrade = partialCorrectionState.json.state.trades.find((trade) => trade.id === partialTradeId);
  assert.equal(correctedPartialTrade.result, 152.5, 'partial result plus final leg must be rebuilt from the edited execution');
  assert.equal(correctedPartialTrade.partialResult, 77.5, 'partial aggregate must be rebuilt from ordered legs');
  assert.equal(correctedPartialTrade.partials[0].result, 40, 'manual partial override must remain authoritative');
  assert.equal(correctedPartialTrade.partials[0].resultOverride, true);
  assert.equal(correctedPartialTrade.partials[1].result, 37.5);
  assert.equal(correctedPartialTrade.risk, 150, 'risk must use initial base-unit size, not remaining size or contract metadata');
  assert.equal(correctedPartialTrade.r, 1.02);
  assert.equal(correctedPartialTrade.size, '2.5');
  assert.equal(correctedPartialTrade.initialSize, '10');
  assert.equal(correctedPartialTrade.partials[0].at, '2026-11-17T10:05:00Z');
  assert.equal(correctedPartialTrade.partials[1].at, '2026-11-17T10:10:00Z');
  assert.equal(correctedPartialTrade.closedAt, '2026-11-17T10:15:00Z');
  log('PUT /api/state odtwarza wynik partials, zachowując override, kolejność i czasy UTC', true, partialTradeId);

  const changedOverrideExecution = JSON.parse(JSON.stringify(partialCorrectionState.json.state));
  const aggregateOverrideTrade = changedOverrideExecution.trades.find((trade) => trade.id === overrideTradeId);
  Object.assign(aggregateOverrideTrade, { entry: '101', exit: '120', result: 999, r: 999, risk: 999 });
  const aggregateOverridePut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: changedOverrideExecution, baseRevision: partialCorrectionState.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(aggregateOverridePut.res.status, 200, aggregateOverridePut.text);
  const aggregateOverrideState = await req('/api/state');
  const preservedAggregateOverride = aggregateOverrideState.json.state.trades.find((trade) => trade.id === overrideTradeId);
  assert.equal(preservedAggregateOverride.resultOverride, 777);
  assert.equal(preservedAggregateOverride.result, 777);
  assert.equal(preservedAggregateOverride.risk, 22);
  assert.equal(preservedAggregateOverride.r, 35.32);
  assert.equal(preservedAggregateOverride.closedAt, '2026-11-17T10:20:00Z');
  log('PUT /api/state zachowuje ręczny wynik i przelicza R agregatu', true, overrideTradeId);

  const changedMalformedPartial = JSON.parse(JSON.stringify(aggregateOverrideState.json.state));
  changedMalformedPartial.trades.find((trade) => trade.id === malformedPartialTradeId).entry = '101';
  const malformedPartialPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: changedMalformedPartial, baseRevision: aggregateOverrideState.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(malformedPartialPut.res.status, 409, malformedPartialPut.text);
  assert.equal(malformedPartialPut.json.error, 'closed_trade_metrics_unsupported');
  const unchangedMalformedPartial = await req('/api/state');
  assert.equal(unchangedMalformedPartial.json.revision, aggregateOverrideState.json.revision);
  assert.equal(
    unchangedMalformedPartial.json.state.trades.find((trade) => trade.id === malformedPartialTradeId).entry,
    '100'
  );
  log('PUT /api/state odrzuca ręczne przeliczenie niebezpiecznych legacy partials', true, malformedPartialTradeId);

  const changedExternalExecution = JSON.parse(JSON.stringify(unchangedMalformedPartial.json.state));
  changedExternalExecution.trades.find((trade) => trade.id === externalTradeId).entry = '201';
  const rejectedExternalExecution = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: changedExternalExecution, baseRevision: unchangedMalformedPartial.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.notEqual(rejectedExternalExecution.res.status, 200, rejectedExternalExecution.text);
  assert.ok(
    ['exchange_data_readonly', 'closed_trade_execution_readonly'].includes(rejectedExternalExecution.json?.error),
    rejectedExternalExecution.text
  );
  const unchangedExternalExecution = await req('/api/state');
  const unchangedExternalTrade = unchangedExternalExecution.json.state.trades.find((trade) => trade.id === externalTradeId);
  const unchangedManualTrade = unchangedExternalExecution.json.state.trades.find((trade) => trade.id === integrityTradeId);
  assert.equal(unchangedExternalExecution.json.revision, unchangedMalformedPartial.json.revision);
  assert.equal(unchangedExternalTrade.entry, '200');
  assert.equal(unchangedManualTrade.entry, '101');
  log('PUT /api/state nadal blokuje wykonanie zamkniętej transakcji importowanej', true, externalTradeId);

  const changedExternalMetadata = JSON.parse(JSON.stringify(unchangedExternalExecution.json.state));
  const changedZeroExternalTrade = changedExternalMetadata.trades.find((trade) => trade.id === zeroExternalTradeId);
  Object.assign(changedZeroExternalTrade, {
    initialSize: '3',
    partials: [{ price: '306', pct: 50, result: 6, resultOverride: true, at: '2026-11-17T10:06:00Z' }],
    partialResult: 6,
    resultOverride: 123,
    sizeUnit: 'lots',
    sizeContractSize: 0.1
  });
  const rejectedExternalMetadata = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: changedExternalMetadata, baseRevision: unchangedExternalExecution.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.notEqual(rejectedExternalMetadata.res.status, 200, rejectedExternalMetadata.text);
  assert.ok(
    ['exchange_data_readonly', 'closed_trade_execution_readonly'].includes(rejectedExternalMetadata.json?.error),
    rejectedExternalMetadata.text
  );
  const unchangedExternalMetadata = await req('/api/state');
  const unchangedZeroExternalTrade = unchangedExternalMetadata.json.state.trades.find((trade) => trade.id === zeroExternalTradeId);
  assert.equal(unchangedExternalMetadata.json.revision, unchangedExternalExecution.json.revision);
  assert.deepEqual(unchangedZeroExternalTrade, unchangedExternalExecution.json.state.trades.find((trade) => trade.id === zeroExternalTradeId));
  log('PUT /api/state atomowo blokuje metadane wykonania transakcji z extId "0"', true, zeroExternalTradeId);

  const invalidClosedState = JSON.parse(JSON.stringify(unchangedExternalExecution.json.state));
  invalidClosedState.trades.reverse();
  const invalidClosedTrade = invalidClosedState.trades.find((trade) => trade.id === integrityTradeId);
  invalidClosedTrade.id = integrityTradeId + '_changed';
  invalidClosedTrade.entry = '102';
  const invalidClosedPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: invalidClosedState, baseRevision: aggregateOverrideState.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(invalidClosedPut.res.status, 409, invalidClosedPut.text);
  assert.equal(invalidClosedPut.json.error, 'trade_id_immutable');
  const preservedHistoricalClose = await req('/api/state');
  const historicalTrade = preservedHistoricalClose.json.state.trades.find((trade) => trade.id === integrityTradeId);
  assert.equal(historicalTrade.entry, '101');
  assert.equal(historicalTrade.exit, '111');
  assert.equal(historicalTrade.result, 10);
  assert.equal(historicalTrade.closedAt, null);
  assert.equal(preservedHistoricalClose.json.revision, aggregateOverrideState.json.revision);
  const annotatedClosedState = JSON.parse(JSON.stringify(preservedHistoricalClose.json.state));
  const annotatedTrade = annotatedClosedState.trades.find((trade) => trade.id === integrityTradeId);
  annotatedTrade.note = 'E2E: historyczne zamknięcie, czas nieznany';
  const annotatedClosedPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: annotatedClosedState, baseRevision: aggregateOverrideState.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(annotatedClosedPut.res.status, 200, annotatedClosedPut.text);
  const afterAnnotation = await req('/api/state');
  const annotatedStoredTrade = afterAnnotation.json.state.trades.find((trade) => trade.id === integrityTradeId);
  assert.equal(annotatedStoredTrade.entry, '101');
  assert.equal(annotatedStoredTrade.exit, '111');
  assert.equal(annotatedStoredTrade.result, 10);
  assert.equal(annotatedStoredTrade.closedAt, null);
  assert.equal(annotatedStoredTrade.note, 'E2E: historyczne zamknięcie, czas nieznany');
  log('PUT /api/state zachowuje korektę wykonania i recenzję bez backfillu czasu', true, integrityTradeId);

  const stateBeforeSoftDelete = await req('/api/state');
  const softDeletedState = JSON.parse(JSON.stringify(stateBeforeSoftDelete.json.state));
  const tradeToSoftDelete = softDeletedState.trades.find((trade) => trade.id === integrityTradeId);
  softDeletedState.trades = softDeletedState.trades.filter((trade) => trade.id !== integrityTradeId);
  softDeletedState.trash = Array.isArray(softDeletedState.trash) ? softDeletedState.trash : [];
  softDeletedState.trash.push({
    id: 'e2e_trash_' + integrityTradeId,
    kind: 'trade',
    label: 'E2E fixture',
    record: tradeToSoftDelete,
    deletedAt: new Date().toISOString()
  });
  const missingTrashIdState = JSON.parse(JSON.stringify(stateBeforeSoftDelete.json.state));
  const missingIdTrade = missingTrashIdState.trades.find((trade) => trade.id === integrityTradeId);
  missingTrashIdState.trades = missingTrashIdState.trades.filter((trade) => trade.id !== integrityTradeId);
  missingTrashIdState.trash = Array.isArray(missingTrashIdState.trash) ? missingTrashIdState.trash : [];
  missingTrashIdState.trash.push({ kind: 'trade', label: 'missing id fixture', record: missingIdTrade });
  const rejectedMissingTrashId = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: missingTrashIdState, baseRevision: stateBeforeSoftDelete.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedMissingTrashId.res.status, 422, rejectedMissingTrashId.text);
  assert.equal(rejectedMissingTrashId.json.error, 'missing_trash_id');
  const softDeletePut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: softDeletedState, baseRevision: stateBeforeSoftDelete.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(softDeletePut.res.status, 200, softDeletePut.text);
  log('PUT /api/state usuwa transakcję wyłącznie z kopią w koszu', true);

  const tamperedTrashState = JSON.parse(JSON.stringify(softDeletedState));
  tamperedTrashState.trash.find((entry) => entry.id === 'e2e_trash_' + integrityTradeId).record.result = 50;
  const rejectedTrashTamper = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: tamperedTrashState, baseRevision: softDeletePut.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(rejectedTrashTamper.res.status, 409, rejectedTrashTamper.text);
  assert.equal(rejectedTrashTamper.json.error, 'trade_trash_immutable');

  const restoreTradeState = JSON.parse(JSON.stringify(softDeletedState));
  const fixtureTrashId = 'e2e_trash_' + integrityTradeId;
  const fixtureTrashIndex = restoreTradeState.trash.findIndex((entry) => entry.id === fixtureTrashId);
  assert.notEqual(fixtureTrashIndex, -1, 'soft-deleted fixture must be present in trash');
  const restoredFixture = restoreTradeState.trash[fixtureTrashIndex].record;
  restoreTradeState.trash.splice(fixtureTrashIndex, 1);
  restoredFixture.dir = restoredFixture.dir === 's' ? 'short' : 'long';
  restoreTradeState.trades.push(restoredFixture);
  const wwwOrigin = ORIGIN.replace(/^(https?:\/\/)(?!www\.)/, '$1www.');
  const wwwPut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: restoreTradeState, baseRevision: softDeletePut.json.revision }),
    headers: { Origin: wwwOrigin }
  });
  assert.equal(wwwPut.res.status, 200, wwwPut.text);
  const restoredStateResponse = await req('/api/state');
  const restoredApiTrade = restoredStateResponse.json.state.trades.find((trade) => trade.id === integrityTradeId);
  assert.equal(restoredApiTrade?.dir, 'l', 'restore normalizes a valid legacy direction alias');
  assert.ok(wwwPut.json.revision > saved.json.revision);
  log('restore z kosza + Origin www.* = 200', true, wwwOrigin);

  const conflict = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: originalState, baseRevision: state.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(conflict.res.status, 409);
  log('konflikt rewizji = 409', true);

  const badOrigin = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: originalState, baseRevision: wwwPut.json.revision }),
    headers: { Origin: 'https://evil.example' }
  });
  assert.equal(badOrigin.res.status, 403);
  log('CSRF / złe Origin = 403', true);

  const index = await req('/');
  assert.equal(index.res.status, 200);
  assert.ok(index.text.includes('Alpha Vault'));
  assert.match(index.text, /src="js\/actions\.js\?v=\d+"/, 'JS z wersją cache-busting');
  log('GET / (UI)', true);

  const docs = await req('/docs/API.md');
  assert.equal(docs.res.status, 200);
  log('GET /docs/API.md', true);

  const exportRes = await req('/api/me/export');
  assert.equal(exportRes.res.status, 200);
  assert.match(exportRes.res.headers.get('content-disposition') || '', /attachment/);
  assert.ok(exportRes.json.state);
  log('GET /api/me/export', true);

  // Legacy manual records without SL may be closed only when execution fields
  // remain unchanged. Seed this pre-existing record through the isolated CI
  // fixture helper; the public API must not allow creating it without an SL.
  const legacyState = JSON.parse(JSON.stringify(fixtureBackup));
  const legacyTradeId = 'e2e_legacy_without_sl_' + Date.now();
  legacyState.trades.push({
    id: legacyTradeId, date: '2025-01-01T10:00:00Z', pair: 'LEGACY/USDT', dir: 'l',
    entry: '100', exit: 'otwarta', sl: 'brak', tp: '110', size: '1',
    result: null, r: null, source: 'Ręcznie'
  });
  execFileSync('php', [path.join(ROOT, 'tests', 'restore-api-fixture.php'), String(fixtureUserId)], {
    env: { ...process.env, ALPHA_VAULT_CONFIG: process.env.ALPHA_VAULT_CONFIG || 'tests/config.test.json' },
    input: JSON.stringify({ state: legacyState }),
    stdio: ['pipe', 'pipe', 'pipe']
  });
  const legacyBefore = await req('/api/state');
  const legacyClosedState = JSON.parse(JSON.stringify(legacyBefore.json.state));
  const legacyTrade = legacyClosedState.trades.find((row) => row.id === legacyTradeId);
  Object.assign(legacyTrade, { exit: '', result: 0, r: null, closedAt: null });
  const legacyClosePut = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: legacyClosedState, baseRevision: legacyBefore.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(legacyClosePut.res.status, 200, legacyClosePut.text);
  const legacyAfter = await req('/api/state');
  const persistedLegacy = legacyAfter.json.state.trades.find((row) => row.id === legacyTradeId);
  assert.equal(persistedLegacy.result, 0);
  assert.equal(persistedLegacy.closedAt, null, 'legacy unknown close time must remain unknown');
  log('PUT /api/state pozwala zamknąć historyczny wpis bez SL bez backfillu czasu', true);

  // Restore the isolated demo fixture directly; production API correctly
  // forbids physically erasing the test trade from its soft-delete history.
  try {
    restoreDemoFixture();
  } catch (err) {
    console.error('restore stdout:', err.stdout?.toString() || '');
    console.error('restore stderr:', err.stderr?.toString() || '');
    throw err;
  }
  log('przywrócenie izolowanego stanu demo po E2E', true, 'user=' + me.json.user.id);

  const logout = await req('/auth/logout', { method: 'POST', headers: { Origin: ORIGIN }, body: '{}' });
  assert.equal(logout.res.status, 200);
  cookie = '';
  const after = await req('/api/me');
  assert.equal(after.json.authenticated, false);
  const afterState = await req('/api/state');
  assert.equal(afterState.res.status, 401);
  log('logout + unieważnienie sesji', true);

  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  if (failed.length) process.exitCode = 1;
}

main().catch((err) => {
  console.error('\nFAIL  test przerwany:', err.message);
  try {
    restoreDemoFixture();
  } catch (restoreError) {
    console.error('FAIL  nie udało się odtworzyć fixture:', restoreError.message);
    console.error('restore stdout:', restoreError.stdout?.toString() || '');
    console.error('restore stderr:', restoreError.stderr?.toString() || '');
  }
  process.exitCode = 1;
});
