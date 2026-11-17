#!/usr/bin/env node
/**
 * Alpha Vault — integracje E2E: WEEX, OKX, BingX, MetaApi, EA.
 *
 * Uruchamia lokalny mock dostawców + serwer PHP z tests/config.integrations.json.
 * Wymaga MySQL z tests/config.test.json i uruchomionego `php app/db/migrate.php`.
 *
 * Uruchom: npm run test:integrations
 */
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const APP_PORT = Number(process.env.INTEGRATIONS_APP_PORT || 4175);
const MOCK_PORT = Number(process.env.INTEGRATIONS_MOCK_PORT || 4191);
const BASE = `http://127.0.0.1:${APP_PORT}`;
const CONFIG = path.join(ROOT, 'tests', 'config.integrations.json');
const php = process.env.PHP_BINARY
  || (fs.existsSync('C:\\Users\\milos\\.local\\php83\\php.exe')
    ? 'C:\\Users\\milos\\.local\\php83\\php.exe'
    : 'php');

if (!fs.existsSync(CONFIG)) {
  console.error('Brak tests/config.integrations.json — skopiuj tests/config.test.json i dodaj INTEGRATIONS_KEY, METAAPI_TOKEN, *_BASE.');
  process.exit(2);
}

const children = [];
const results = [];
function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  — ' + info : ''}`);
}

function start(cmd, args, env = {}) {
  const child = spawn(cmd, args, {
    cwd: ROOT,
    env: { ...process.env, ...env },
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true
  });
  let output = '';
  child.stdout.on('data', (d) => { output += d.toString(); });
  child.stderr.on('data', (d) => { output += d.toString(); });
  child.getOutput = () => output;
  children.push(child);
  return child;
}

async function waitFor(url, timeoutMs = 15000) {
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    try {
      const res = await fetch(url);
      if (res.status < 500) return true;
    } catch {}
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error('Nie wstał: ' + url);
}

function stopAll() {
  for (const child of children) {
    try {
      if (process.platform === 'win32') {
        spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
      } else {
        child.kill('SIGTERM');
      }
    } catch {}
  }
}

let cookie = '';
function cookieFrom(res) {
  const list = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
  for (const c of list) {
    const m = /^av_session=([^;]+)/.exec(c || '');
    if (m) return 'av_session=' + m[1];
  }
  return '';
}

async function req(pathname, opts = {}) {
  const res = await fetch(BASE + pathname, {
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

async function connect(provider, fields) {
  return req('/api/connections', {
    method: 'POST',
    headers: { Origin: BASE },
    body: JSON.stringify({ provider, fields })
  });
}

async function sync(id) {
  return req('/api/connections/' + id + '/sync', {
    method: 'POST',
    headers: { Origin: BASE },
    body: '{}'
  });
}

async function stateTrades() {
  const r = await req('/api/state');
  assert.equal(r.res.status, 200, r.text);
  return r.json.state.trades || [];
}

function importMarkerExists(userId, externalId) {
  const result = spawnSync(php, ['tests/integrations-db.php', String(userId), externalId], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8',
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout).exists === true;
}

try {
  const mock = start(php, ['-S', `127.0.0.1:${MOCK_PORT}`, 'tests/fixtures/mock-providers.php']);
  const app = start(php, ['-S', `127.0.0.1:${APP_PORT}`, '-t', 'public', 'public/router.php'], {
    ALPHA_VAULT_CONFIG: CONFIG,
    APP_URL: BASE
  });
  await waitFor(`http://127.0.0.1:${MOCK_PORT}/okx/api/v5/time`);
  await waitFor(BASE + '/api/health');

  const reset = spawnSync(php, ['tests/reset-integrations.php'], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8'
  });
  assert.equal(reset.status, 0, reset.stderr || reset.stdout);
  log('reset danych integracji', true);

  const health = await req('/api/health');
  assert.equal(health.res.status, 200, health.text);
  assert.equal(health.json.integrations.configured, true);
  assert.equal(health.json.integrations.metaApiConfigured, true);
  log('health: integracje skonfigurowane', true);

  const demo = await req('/auth/demo', { method: 'POST', headers: { Origin: BASE }, body: JSON.stringify({ remember: true }) });
  assert.equal(demo.res.status, 200, demo.text);
  cookie = cookieFrom(demo.res);
  assert.ok(cookie, 'brak cookie sesji');
  const seeded = await req('/api/state');
  assert.equal(seeded.res.status, 200, seeded.text);
  assert.ok(seeded.json.state, 'brak seedu stanu demo');
  log('logowanie demo', true);

  const initial = await req('/api/connections');
  assert.equal(initial.res.status, 200, initial.text);
  assert.equal(initial.json.connections.length, 0);
  log('start: brak połączeń', true);

  // ---------- OKX ----------
  const okx = await connect('okx', { apiKey: 'okx-read', secretKey: 'okx-secret', passphrase: 'p' });
  assert.equal(okx.res.status, 201, okx.text);
  assert.equal(okx.json.connection.status, 'connected');
  assert.equal(okx.json.connection.permissions, 'read-only');
  assert.ok(okx.json.connection.keyMask.includes('••••'));
  assert.ok(!okx.text.includes('okx-secret'));
  const okxId = okx.json.connection.id;
  log('OKX: klucz read-only zaakceptowany', true, okx.json.connection.keyMask);

  const okxSync = await sync(okxId);
  assert.equal(okxSync.res.status, 200, okxSync.text);
  assert.equal(okxSync.json.result.imported, 2);
  let trades = await stateTrades();
  const btc = trades.find((t) => t.extId === 'okx:pos:111');
  const eth = trades.find((t) => t.extId === 'okx:pos:222');
  assert.ok(btc && btc.pair === 'BTC/USDT' && btc.result === 99.4 && btc.mae === null && btc.mfe === null
    && btc.checklist === null && btc.checkTotal === null && btc.closedAt === btc.date
    && Number.isFinite(Date.parse(btc.importedAt))
    && Date.parse(btc.importedAt) <= Date.now()
    && Date.parse(btc.importedAt) > Date.now() - 60000, JSON.stringify(btc));
  assert.ok(eth && eth.pair === 'ETH/USDT' && eth.result === 49.8 && eth.mae === null && eth.mfe === null
    && eth.checklist === null && eth.checkTotal === null, JSON.stringify(eth));
  assert.equal(trades.some((t) => t.extId === 'okx:pos:333'), false, 'brak realizedPnl nie może utworzyć break-even');
  log('OKX: sync importuje 2 transakcje', true, 'BTC 99.4 / ETH 49.8');

  const okxDedupe = await sync(okxId);
  assert.equal(okxDedupe.json.result.imported, 0);
  assert.equal(okxDedupe.json.result.skipped, 2);
  log('OKX: deduplikacja', true, '0 nowych');

  const atomicFixture = await connect('okx', {
    apiKey: 'okx-atomic-invalid', secretKey: 'okx-secret', passphrase: 'p',
  });
  assert.equal(atomicFixture.res.status, 201, atomicFixture.text);
  const atomicBefore = await req('/api/state');
  assert.equal(atomicBefore.res.status, 200, atomicBefore.text);
  const atomicGoodId = 'okx:pos:atomic-good';
  assert.equal(importMarkerExists(atomicBefore.json.userId, atomicGoodId), false);
  const atomicResult = await sync(atomicFixture.json.connection.id);
  assert.equal(atomicResult.res.status, 422, atomicResult.text);
  assert.equal(atomicResult.json.error, 'invalid_trade', atomicResult.text);
  const atomicAfter = await req('/api/state');
  assert.equal(atomicAfter.res.status, 200, atomicAfter.text);
  assert.equal(atomicAfter.json.revision, atomicBefore.json.revision, 'błędny batch nie może podnosić rewizji');
  assert.deepEqual(atomicAfter.json.state, atomicBefore.json.state, 'błędny batch nie może zmienić stanu');
  assert.equal(atomicAfter.json.state.trades.some((trade) => trade.extId === atomicGoodId), false);
  assert.equal(importMarkerExists(atomicBefore.json.userId, atomicGoodId), false, 'marker pierwszego importu musi wycofać transakcja');
  log('OKX: błędny import wycofuje cały batch, stan, revision i marker');

  const bulkFixture = await connect('okx', {
    apiKey: 'okx-over-500', secretKey: 'okx-secret', passphrase: 'p',
  });
  assert.equal(bulkFixture.res.status, 201, bulkFixture.text);
  const bulkBefore = await req('/api/state');
  assert.equal(bulkBefore.res.status, 200, bulkBefore.text);
  const bulkSync = await sync(bulkFixture.json.connection.id);
  assert.equal(bulkSync.res.status, 200, bulkSync.text);
  assert.equal(bulkSync.json.result.imported, 501, bulkSync.text);
  const bulkAfter = await req('/api/state');
  assert.equal(bulkAfter.res.status, 200, bulkAfter.text);
  assert.equal(bulkAfter.json.revision, bulkBefore.json.revision + 1);
  const bulkTrades = bulkAfter.json.state.trades.filter((trade) => String(trade.extId || '').startsWith('okx:pos:bulk-'));
  assert.equal(bulkTrades.length, 501);
  assert.equal(bulkTrades[0].closedAt, bulkTrades[0].date);
  assert.ok(Number.isFinite(Date.parse(bulkTrades[0].importedAt)));
  assert.equal(importMarkerExists(bulkBefore.json.userId, 'okx:pos:bulk-0000'), true);
  assert.equal(importMarkerExists(bulkBefore.json.userId, 'okx:pos:bulk-0500'), true);
  log('OKX: import ponad 500 rekordów, markery i timestampy', true, String(bulkTrades.length));

  const overflowFixture = await connect('okx', {
    apiKey: 'okx-over-page-limit', secretKey: 'okx-secret', passphrase: 'p',
  });
  assert.equal(overflowFixture.res.status, 201, overflowFixture.text);
  const overflowBefore = await req('/api/state');
  const overflowSync = await sync(overflowFixture.json.connection.id);
  assert.equal(overflowSync.res.status, 502, overflowSync.text);
  assert.equal(overflowSync.json.error, 'history_page_limit', overflowSync.text);
  const overflowAfter = await req('/api/state');
  assert.equal(overflowAfter.json.revision, overflowBefore.json.revision);
  assert.deepEqual(overflowAfter.json.state, overflowBefore.json.state);
  assert.equal(importMarkerExists(overflowBefore.json.userId, 'okx:pos:overflow-0000'), false);
  log('OKX: historia przekraczająca limit stron zgłasza błąd zamiast cichego ucięcia', true);

  for (const [apiKey, marker] of [
    ['okx-missing-position-cursor', 'okx:pos:position-page1-000'],
    ['okx-missing-spot-cursor', 'prefix:okx:SOL-USDT:spot-closed-open:'],
  ]) {
    const cursorFixture = await connect('okx', { apiKey, secretKey: 'okx-secret', passphrase: 'p' });
    assert.equal(cursorFixture.res.status, 201, cursorFixture.text);
    const cursorBefore = await req('/api/state');
    const cursorSync = await sync(cursorFixture.json.connection.id);
    assert.equal(cursorSync.res.status, 502, cursorSync.text);
    assert.equal(cursorSync.json.error, 'history_cursor_missing', cursorSync.text);
    const cursorAfter = await req('/api/state');
    assert.equal(cursorAfter.json.revision, cursorBefore.json.revision);
    assert.deepEqual(cursorAfter.json.state, cursorBefore.json.state);
    assert.equal(importMarkerExists(cursorBefore.json.userId, marker), false);
  }
  log('OKX: pełna strona bez kursora pozycji lub spot zgłasza błąd bez częściowego importu', true);

  const okxTrade = await connect('okx', { apiKey: 'okx-trade', secretKey: 'okx-secret', passphrase: 'p' });
  assert.equal(okxTrade.res.status, 422, okxTrade.text);
  assert.equal(okxTrade.json.error, 'read_only_required');
  log('OKX: klucz z handlem odrzucony', true);

  // ---------- BingX ----------
  const bingx = await connect('bingx', { apiKey: 'bingx-read', secretKey: 'bingx-secret' });
  assert.equal(bingx.res.status, 201, bingx.text);
  const bingxSync = await sync(bingx.json.connection.id);
  assert.equal(bingxSync.res.status, 200, bingxSync.text);
  assert.equal(bingxSync.json.result.imported, 2);
  trades = await stateTrades();
  const bingxTrade = trades.find((t) => t.extId === 'bingx:pos:333');
  assert.ok(bingxTrade && bingxTrade.pair === 'BTC/USDT' && bingxTrade.result === 49.5 && bingxTrade.mae === null && bingxTrade.mfe === null
    && bingxTrade.checklist === null && bingxTrade.checkTotal === null, JSON.stringify(bingxTrade));
  const bingxUnknownMeasures = trades.find((t) => t.extId === 'bingx:pos:444');
  assert.ok(bingxUnknownMeasures && bingxUnknownMeasures.result === 0
    && bingxUnknownMeasures.fee === null && bingxUnknownMeasures.durationMin === null, JSON.stringify(bingxUnknownMeasures));
  assert.equal(trades.some((t) => t.extId === 'bingx:pos:555'), false, 'brak znanego PnL nie może utworzyć break-even');
  log('BingX: sync importuje zamkniętą pozycję', true);

  const bingxTradeKey = await connect('bingx', { apiKey: 'bingx-trade', secretKey: 'bingx-secret' });
  assert.equal(bingxTradeKey.res.status, 422, bingxTradeKey.text);
  assert.equal(bingxTradeKey.json.error, 'read_only_required');
  log('BingX: klucz z handlem odrzucony', true);

  // ---------- WEEX ----------
  const weex = await connect('weex', { apiKey: 'weex-read', secretKey: 'weex-secret', passphrase: 'p' });
  assert.equal(weex.res.status, 201, weex.text);
  const weexSync = await sync(weex.json.connection.id);
  assert.equal(weexSync.res.status, 200, weexSync.text);
  assert.equal(weexSync.json.result.imported, 2);
  trades = await stateTrades();
  const weexTrade = trades.find((t) => String(t.extId || '').startsWith('weex:BTCUSDT:501:'));
  assert.ok(weexTrade && weexTrade.pair === 'BTC/USDT' && weexTrade.result === 9.8, JSON.stringify(weexTrade));
  assert.equal(weexTrade.entry, '100');
  assert.equal(weexTrade.exit, '110');
  const weexBreakEven = trades.find((t) => String(t.extId || '').startsWith('weex:ETHUSDT:503:'));
  assert.ok(weexBreakEven && weexBreakEven.result === -0.2 && weexBreakEven.fee === 0.2, JSON.stringify(weexBreakEven));
  log('WEEX: rekonstrukcja transakcji z fills', true, 'BTC 9.8 / ETH break-even po opłatach');

  const weexTradeKey = await connect('weex', { apiKey: 'weex-trade', secretKey: 'weex-secret', passphrase: 'p' });
  assert.equal(weexTradeKey.res.status, 201, weexTradeKey.text);
  assert.equal(weexTradeKey.json.connection.permissions, 'any');
  log('WEEX: klucz z handlem zaakceptowany', true, weexTradeKey.json.connection.keyMask);

  // ---------- MetaTrader / MetaApi ----------
  // The new OKX regression fixtures add several connects; clear the shared
  // per-IP rate-limit bucket so later connects are not falsely throttled.
  const rateReset = spawnSync(php, ['tests/reset-integrations.php', '--rate-only'], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8'
  });
  assert.equal(rateReset.status, 0, rateReset.stderr || rateReset.stdout);
  const mt = await connect('mt5', { platform: 'mt5', server: 'Mock-Server', login: '81234567', password: 'investor', type: 'Live' });
  assert.equal(mt.res.status, 201, mt.text);
  const mtSync = await sync(mt.json.connection.id);
  assert.equal(mtSync.res.status, 200, mtSync.text);
  assert.equal(mtSync.json.result.imported, 1);
  trades = await stateTrades();
  const mtTrade = trades.find((t) => t.extId === 'mt:mt-pos-1');
  assert.ok(mtTrade && mtTrade.pair === 'XAU/USD' && mtTrade.result === 99.5, JSON.stringify(mtTrade));
  log('MetaTrader: hasło inwestora + sync', true);

  const mtMaster = await connect('mt5', { platform: 'mt5', server: 'Mock-Server', login: '81234567', password: 'master', type: 'Live' });
  assert.equal(mtMaster.res.status, 422, mtMaster.text);
  assert.equal(mtMaster.json.error, 'investor_password_required');
  log('MetaTrader: hasło z handlem odrzucone', true);

  // ---------- EA ----------
  const pair = await req('/api/connections/mt/pair', {
    method: 'POST',
    headers: { Origin: BASE },
    body: JSON.stringify({ platform: 'mt5' })
  });
  assert.equal(pair.res.status, 201, pair.text);
  assert.match(pair.json.code, /^AV-[A-Z0-9]{4}-[A-Z0-9]{4}$/);
  const code = pair.json.code;
  const afterPair = await req('/api/connections');
  assert.ok(afterPair.json.connections.some((c) => c.id === mt.json.connection.id), 'parowanie EA usunęło MetaApi');
  const pending = afterPair.json.connections.find((c) => c.id === pair.json.connectionId);
  assert.ok(pending && pending.accountRef === 'pending', 'kod parowania nie powinien ujawniać hasha');
  const eaSync = await req('/api/connections/' + pair.json.connectionId + '/sync', { method: 'POST', headers: { Origin: BASE } });
  assert.equal(eaSync.res.status, 422, eaSync.text);
  assert.equal(eaSync.json.error, 'ea_push_only');
  log('EA: parowanie nie usuwa MetaApi i nie ujawnia hasha', true);

  const nowSec = Math.floor(Date.now() / 1000);
  const ea = await req('/api/mt/ea', {
    method: 'POST',
    body: JSON.stringify({ code, trades: [{
      ticket: 'EA1', symbol: 'EURUSD', side: 'buy',
      openTime: nowSec - 3600, closeTime: nowSec, lots: 0.5,
      openPrice: 1.1, closePrice: 1.105, profit: 250, commission: -2, swap: -1
    }] })
  });
  assert.equal(ea.res.status, 200, ea.text);
  assert.equal(ea.json.imported, 1);
  trades = await stateTrades();
  const eaTrade = trades.find((t) => t.extId === 'ea:EA1');
  assert.ok(eaTrade && eaTrade.pair === 'EUR/USD' && eaTrade.result === 247, JSON.stringify(eaTrade));
  log('EA: kod parowania + ingest', true, code);

  const eaAgain = await req('/api/mt/ea', {
    method: 'POST',
    body: JSON.stringify({ code, trades: [{ ticket: 'EA1', symbol: 'EURUSD', side: 'buy', closeTime: nowSec, lots: 0.5, openPrice: 1.1, closePrice: 1.105, profit: 250, commission: -2, swap: -1 }] })
  });
  assert.equal(eaAgain.json.imported, 0);
  log('EA: deduplikacja po ticket', true);

  const eaBad = await req('/api/mt/ea', { method: 'POST', body: JSON.stringify({ code: 'AV-0000-0000', trades: [] }) });
  assert.equal(eaBad.res.status, 401);
  log('EA: zły kod = 401', true);

  // ---------- podsumowanie ----------
  const list = await req('/api/connections');
  assert.equal(list.res.status, 200);
  assert.equal(list.json.connections.length, 5);
  assert.ok(!/okx-secret|weex-secret|bingx-secret|investorMode|"password"/.test(list.text), 'wyciek sekretów w API');
  const okxConn = list.json.connections.find((c) => c.provider === 'okx');
  assert.ok(okxConn && okxConn.keyMask.includes('••••'));
  assert.ok(okxConn.lastSyncAt);
  log('API: brak sekretów, maski i statusy', true);

  const del = await req('/api/connections/' + okxId, { method: 'DELETE', headers: { Origin: BASE } });
  assert.equal(del.res.status, 200, del.text);
  const afterDelete = await req('/api/connections');
  assert.equal(afterDelete.json.connections.filter((c) => c.provider === 'okx').length, 0);
  log('OKX: odłączenie usuwa połączenie', true);

  const failed = results.filter((r) => !r.ok).length;
  console.log(`\nIntegracje E2E: ${results.length - failed}/${results.length} PASS`);
  if (failed) process.exitCode = 1;
} catch (err) {
  console.error('FAIL  integracje E2E:', err?.stack || err);
  process.exitCode = 1;
} finally {
  try {
    const cleanup = spawnSync(php, ['tests/reset-integrations.php'], {
      cwd: ROOT,
      env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
      encoding: 'utf8'
    });
    if (cleanup.status !== 0) {
      console.error('FAIL  cleanup integracji:', cleanup.stderr || cleanup.stdout || 'kod ' + cleanup.status);
      process.exitCode = 1;
    }
  } catch (err) {
    console.error('FAIL  cleanup integracji:', err?.message || err);
    process.exitCode = 1;
  }
  stopAll();
}
