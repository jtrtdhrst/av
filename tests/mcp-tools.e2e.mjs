#!/usr/bin/env node
/**
 * AlphaVault MCP — E2E nowych narzędzi (38) i workflow.
 *
 * Wymaga MySQL z tests/config.test.json, seedu tests/seed-mcp.php i PHP 8.3.
 * Każde narzędzie ma test poprawnego wywołania, brak danych, nieistniejący
 * identyfikator, walidację parametrów, izolację użytkownika i, dla propozycji,
 * pełny cykl życia (pending → diff → brak zapisu → approve/reject/expire).
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP = process.env.PHP_BIN || 'php';
const PHP_ARGS = (process.env.PHP_ARGS || '').split(/\s+/).filter(Boolean);
const CONFIG = process.env.ALPHA_VAULT_CONFIG || path.join(ROOT, 'tests', 'config.test.json');
const PORT = Number(process.env.MCP_TOOLS_TEST_PORT || 4177);
const BASE = `http://127.0.0.1:${PORT}`;
const SESSIONS_FILE = path.join(ROOT, 'tests', 'mcp-test-sessions.json');
const PATCH_FILE = path.join(ROOT, 'tests', 'mcp-tools-patch.json');
const LOCAL_ENV = {
  APP_URL: BASE,
  SESSION_COOKIE: 'mcp_session',
  DISCORD_REDIRECT_URI: BASE + '/oauth/discord/callback',
  MCP_ENABLED: 'true',
  MCP_ISSUER: BASE,
  MCP_RESOURCE: BASE + '/mcp',
  MCP_ALLOWED_REDIRECT_HOSTS: 'chatgpt.com,localhost,127.0.0.1',
  MCP_PRIVATE_KEY_PATH: path.join(ROOT, 'tests', 'mcp-keys', 'oauth-private.pem'),
  MCP_PUBLIC_KEY_PATH: path.join(ROOT, 'tests', 'mcp-keys', 'oauth-public.pem'),
  MCP_ENCRYPTION_KEY_PATH: path.join(ROOT, 'tests', 'mcp-keys', 'oauth-encryption.key'),
  MCP_LOG_FILE: path.join(ROOT, 'tests', 'mcp.log'),
};

const dayKey = (offset = 0) =>
  new Date(Date.now() + offset * 86400000).toLocaleDateString('sv-SE', { timeZone: 'Europe/Warsaw' });
const TODAY = dayKey(0);
const YESTERDAY = dayKey(-1);
const WEEK_AGO = dayKey(-7);

function runPhp(script, args = [], allowFailure = false) {
  const result = spawnSync(PHP, [...PHP_ARGS, script, ...args], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG, ...LOCAL_ENV },
    encoding: 'utf8',
  });
  if (result.status !== 0 && !allowFailure) {
    throw new Error(`${path.basename(script)} failed:\n${result.stdout}\n${result.stderr}`);
  }
  return result.stdout.trim();
}

async function waitForHealth(timeoutMs = 20000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    try {
      const res = await fetch(BASE + '/health');
      if (res.ok) return;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('MCP server nie odpowiedział na /health.');
}

function b64url(buf) {
  return Buffer.from(buf).toString('base64url');
}

function pkce() {
  const verifier = b64url(crypto.randomBytes(48));
  return { verifier, challenge: b64url(crypto.createHash('sha256').update(verifier).digest()) };
}

async function req(pathname, opts = {}) {
  const res = await fetch(BASE + pathname, { redirect: 'manual', ...opts });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

async function register(name) {
  const r = await req('/oauth/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ client_name: name, redirect_uris: ['https://chatgpt.com/connector_platform_oauth_redirect'], token_endpoint_auth_method: 'none' }),
  });
  assert.equal(r.res.status, 201, r.text);
  return r.json;
}

function parseConsent(html) {
  const consent = /name="consent_id" value="([^"]+)"/.exec(html);
  const csrf = /name="csrf" value="([^"]+)"/.exec(html);
  assert.ok(consent && csrf, 'brak pól consent');
  return { consentId: consent[1], csrf: csrf[1] };
}

async function authorize(client, session, scope, grants = {}) {
  const { verifier, challenge } = pkce();
  const state = 'state-' + crypto.randomBytes(8).toString('hex');
  const q = new URLSearchParams({
    response_type: 'code', client_id: client.client_id, redirect_uri: client.redirect_uris[0],
    scope, state, code_challenge: challenge, code_challenge_method: 'S256', resource: BASE + '/mcp',
  });
  const page = await req('/oauth/authorize?' + q.toString(), { headers: { Cookie: 'mcp_session=' + session } });
  assert.equal(page.res.status, 200, page.text);
  if (grants.write) assert.match(page.text, /name="grant_write"/);
  if (grants.health) assert.match(page.text, /name="grant_health"/);
  const { consentId, csrf } = parseConsent(page.text);
  const body = new URLSearchParams({ consent_id: consentId, csrf, decision: 'allow' });
  if (grants.write) body.set('grant_write', '1');
  if (grants.health) body.set('grant_health', '1');
  const posted = await req('/oauth/authorize', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'mcp_session=' + session },
    body: body.toString(),
  });
  assert.equal(posted.res.status, 302, posted.text);
  const loc = new URL(posted.res.headers.get('location'));
  assert.equal(loc.searchParams.get('state'), state);
  const code = loc.searchParams.get('code');
  assert.ok(code, 'brak code');
  return { code, verifier, redirectUri: client.redirect_uris[0] };
}

async function token(client, auth) {
  const body = new URLSearchParams({
    grant_type: 'authorization_code', client_id: client.client_id, redirect_uri: auth.redirectUri,
    code: auth.code, code_verifier: auth.verifier, resource: BASE + '/mcp',
  });
  const r = await req('/oauth/token', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
  assert.equal(r.res.status, 200, r.text);
  return r.json;
}

let rpcId = 1;
async function rpc(tokenValue, method, params = {}) {
  return req('/mcp', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      ...(tokenValue ? { Authorization: 'Bearer ' + tokenValue } : {}),
    },
    body: JSON.stringify({ jsonrpc: '2.0', id: rpcId++, method, params }),
  });
}

async function call(tokenValue, name, args = {}) {
  const r = await rpc(tokenValue, 'tools/call', { name, arguments: args });
  assert.equal(r.res.status, 200, `${name}: ${r.text}`);
  assert.ok(r.json?.result, `${name}: brak result ${r.text}`);
  assert.equal(r.json.result.isError ?? false, false, `${name}: ${r.json.result.content?.[0]?.text || r.text}`);
  return r.json.result.structuredContent;
}

async function expectToolError(tokenValue, name, args, pattern) {
  const r = await rpc(tokenValue, 'tools/call', { name, arguments: args });
  const message = r.json?.result?.content?.[0]?.text || r.json?.error?.message || r.text;
  assert.ok(r.json?.error?.code === -32602 || r.json?.result?.isError === true, `${name}: oczekiwano błędu, jest ${r.text}`);
  if (pattern) assert.match(String(message), pattern, `${name}: ${message}`);
  return message;
}

function stateOf(userId) {
  return JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['state', String(userId)]));
}

function patchState(userId, patch) {
  fs.writeFileSync(PATCH_FILE, JSON.stringify(patch), 'utf8');
  return JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['patch', String(userId), PATCH_FILE]));
}

const idem = (prefix) => `${prefix}-${crypto.randomBytes(8).toString('hex')}`;

async function main() {
  const sessions = JSON.parse(fs.readFileSync(SESSIONS_FILE, 'utf8'));
  const a = sessions['mcp-e2e-a'];
  const b = sessions['mcp-e2e-b'];
  const client = await register('MCP Tools E2E');
  const authA = await authorize(client, a.session, 'alphavault.read alphavault.health alphavault.write', { write: true, health: true });
  const tokA = await token(client, authA);
  assert.deepEqual(
    String(JSON.parse(Buffer.from(tokA.access_token.split('.')[1], 'base64url').toString('utf8')).scope).split(' ').sort(),
    ['alphavault.health', 'alphavault.read', 'alphavault.write']
  );
  const at = tokA.access_token;

  // =================================================================
  // 1. Plan dnia
  // =================================================================
  const history = await call(at, 'get_day_plan_history', { limit: 100 });
  assert.ok(history.total >= 4, JSON.stringify(history).slice(0, 300));
  assert.equal(history.items[0].date, TODAY, 'historia ma być od najnowszego dnia');
  const todayPlan = history.items.find((row) => row.date === TODAY);
  assert.equal(todayPlan.status, 'draft');
  assert.deepEqual(todayPlan.markets, ['BTC']);
  assert.equal(todayPlan.limits.maxTrades, 3);
  assert.equal(history.items.find((row) => row.date === YESTERDAY).status, 'approved');

  const compliance = await call(at, 'get_day_plan_compliance', { from: WEEK_AGO, to: TODAY, limit: 100 });
  assert.ok(compliance.summary.tradesAnalyzed > 0, JSON.stringify(compliance.summary));
  assert.ok(compliance.summary.alignedTrades >= 1, 'brak transakcji zgodnej z planem');
  assert.ok(compliance.summary.rulesViolated.length > 0);
  assert.ok(compliance.summary.compliancePct !== null);
  const dayCompliance = await call(at, 'get_day_plan_compliance', { date: TODAY });
  assert.equal(dayCompliance.days.length, 1);
  assert.equal(dayCompliance.days[0].planStatus, 'draft');
  assert.equal(dayCompliance.summary.alignedTrades, 0);
  assert.ok(dayCompliance.summary.undeterminedTrades >= 1, 'plan w szkicu ma dawać „nie da się ustalić”');
  assert.equal(JSON.stringify(dayCompliance).includes('"aligned":['), false, 'surowa transakcja w odpowiedzi');

  const dayContext = await call(at, 'get_day_plan_context', { date: TODAY });
  assert.equal(dayContext.date, TODAY);
  assert.equal(dayContext.plan.status, 'draft');
  assert.ok(dayContext.trades.length >= 4);
  assert.equal(dayContext.limits.maxTrades, 3);
  assert.equal(dayContext.limits.tradesOverLimit, 1);
  assert.ok(Array.isArray(dayContext.macro));
  assert.equal(dayContext.protection.active, true);
  assert.ok(dayContext.checkin);
  assert.ok(dayContext.riskRules);

  const contextDefault = await call(at, 'get_day_plan_context', {});
  assert.equal(contextDefault.date, TODAY);
  await expectToolError(at, 'get_day_plan_history', { from: '2026-13-40' }, /YYYY-MM-DD/);
  await expectToolError(at, 'get_day_plan_compliance', { from: TODAY, to: WEEK_AGO }, /późniejs/);
  await expectToolError(at, 'get_day_plan_history', { limit: 500 });

  // =================================================================
  // 2. Ryzyko i tryb ochronny
  // =================================================================
  const rules = await call(at, 'get_risk_rules', {});
  assert.equal(rules.maxRiskPercentPerTrade, 1);
  assert.equal(rules.maxConsecutiveLossesPerDay, 2);
  assert.equal(rules.safetySwitch.enabled, true);
  assert.ok(rules.rules.length >= 3);
  assert.ok(rules.checklist.length >= 5);

  const protection = await call(at, 'get_protection_status', {});
  assert.equal(protection.triggered, true);
  assert.equal(protection.active, true);
  assert.equal(protection.reason, 'limit strat');
  assert.equal(protection.losses >= 2, true);
  assert.ok(protection.blockedUntil);
  assert.equal(protection.overridden, false);
  assert.deepEqual(protection.overridesToday, []);

  // Obejście blokady z uzasadnieniem: stan zmieniany helperem, nie przez MCP.
  patchState(a.userId, { overrides: [{ date: TODAY, at: Date.now(), reason: 'Test obejścia blokady' }] });
  const protectionOverride = await call(at, 'get_protection_status', {});
  assert.equal(protectionOverride.overridden, true);
  assert.equal(protectionOverride.active, false);
  assert.equal(protectionOverride.overrideReason, 'Test obejścia blokady');
  patchState(a.userId, { overrides: [] });

  const violations = await call(at, 'get_risk_violations', { limit: 200 });
  assert.ok(violations.total > 0);
  const violationRules = violations.byRule.map((row) => row.rule);
  assert.ok(violationRules.includes('missing_stop_loss'), JSON.stringify(violationRules));
  assert.ok(violationRules.includes('risk_per_trade'), JSON.stringify(violationRules));
  assert.ok(violationRules.includes('traded_after_lock'), JSON.stringify(violationRules));
  assert.ok(violations.items.every((row) => row.date && row.rule && row.expected !== undefined && row.actual !== undefined && row.severity));
  assert.ok(violations.severityCounts.critical >= 1);
  const violationPage1 = await call(at, 'get_risk_violations', { limit: 2, offset: 0 });
  assert.equal(violationPage1.items.length, 2);
  assert.equal(violationPage1.nextOffset, 2);
  await expectToolError(at, 'get_risk_violations', { limit: 500 });

  // =================================================================
  // 3. Dziennik: recenzja, screeny, kontekst
  // =================================================================
  const journal = await call(at, 'get_trade_journal_context', { tradeId: 'fx_aligned_short' });
  assert.equal(journal.found, true);
  assert.equal(journal.reviewAvailable, true);
  assert.equal(journal.setup.name, 'Odrzucenie oporu');
  assert.equal(journal.plan.status, 'approved');
  assert.equal(journal.compliance.aligned, true, JSON.stringify(journal.compliance));
  assert.equal(journal.screenshots.available, true);
  assert.equal(journal.account.name, 'WEEX');
  assert.ok(journal.risk.maxRiskPercentPerTrade === 1);
  assert.ok(journal.checklist.total === 7);

  const review = await call(at, 'get_trade_review', { tradeId: 'fx_aligned_short' });
  assert.equal(review.reviewAvailable, true);
  assert.equal(review.decisionQuality.grade, 'A');
  assert.equal(review.recommendations.source, 'review');
  assert.equal(review.recommendations.items.length, 1);
  assert.equal(review.planCompliance.aligned, true);

  const reviewMissing = await call(at, 'get_trade_review', { tradeId: 'fx_no_plan' });
  assert.equal(reviewMissing.found, true);
  assert.equal(reviewMissing.reviewAvailable, false);
  assert.equal(reviewMissing.reason, 'review_not_created');
  const reviewUnknown = await call(at, 'get_trade_review', { tradeId: 'nie-ma-takiej' });
  assert.equal(reviewUnknown.found, false);

  const screens = await call(at, 'get_trade_screenshots', { tradeId: 'fx_aligned_short' });
  assert.equal(screens.found, true);
  assert.equal(screens.screenshots.count, 2);
  assert.equal(screens.screenshots.images.length, 0);
  assert.equal(screens.screenshots.items[0].mimeType, 'image/png');
  assert.equal(screens.screenshots.items[0].dimensions.width, 1);
  assert.match(screens.screenshots.items[0].sha256, /^[a-f0-9]{64}$/);
  const screensImages = await call(at, 'get_trade_screenshots', { tradeId: 'fx_aligned_short', includeImages: true, maxImages: 2 });
  assert.equal(screensImages.screenshots.images.length, 2);
  assert.ok(screensImages.screenshots.images[0].data.startsWith('iVBOR'));
  const screensEmpty = await call(at, 'get_trade_screenshots', { tradeId: 'fx_no_plan' });
  assert.equal(screensEmpty.screenshots.available, false);
  const screensUnknown = await call(at, 'get_trade_screenshots', { tradeId: 'nie-ma-takiej' });
  assert.equal(screensUnknown.found, false);
  await expectToolError(at, 'get_trade_screenshots', { tradeId: 'fx_aligned_short', kind: 'srodek' });

  // =================================================================
  // 4. Playbook
  // =================================================================
  const setupDetails = await call(at, 'get_setup_details', { setupId: 'Retest wybicia' });
  assert.equal(setupDetails.found, true);
  assert.ok(setupDetails.setup.rules.length >= 2);
  assert.equal(setupDetails.setup.minRR, 2);
  assert.equal(setupDetails.setup.examples.length, 1);
  assert.ok(setupDetails.performance.sampleSize >= 3);
  const setupUnknown = await call(at, 'get_setup_details', { setupId: 'Nie ma takiego' });
  assert.equal(setupUnknown.found, false);
  assert.ok(setupUnknown.available.length >= 5);

  const setupPerf = await call(at, 'get_setup_performance', { setupId: 'Retest wybicia' });
  assert.equal(setupPerf.found, true);
  assert.equal(setupPerf.metrics.hasData, true);
  assert.equal(setupPerf.byGrade.length, 3);
  assert.ok(setupPerf.topErrors.length >= 1);
  assert.ok(setupPerf.bySession.length >= 1);
  assert.ok(setupPerf.byPair.length >= 1);
  const setupPerfUnknown = await call(at, 'get_setup_performance', { setupId: 'Nie ma takiego' });
  assert.equal(setupPerfUnknown.found, false);
  assert.equal(setupPerfUnknown.hasData, false);
  await expectToolError(at, 'get_setup_performance', { setupId: 'Retest wybicia', from: 'zły' });

  // =================================================================
  // 5. Gotowość i korelacje
  // =================================================================
  const readiness = await call(at, 'get_readiness_context', {});
  assert.equal(readiness.date, TODAY);
  assert.equal(readiness.checkin.energy, 2);
  assert.equal(readiness.checkin.score, 45);
  assert.equal(readiness.healthAccess, 'no_device');
  assert.equal(readiness.health, null, 'bez urządzenia nie ma danych zdrowotnych');
  assert.equal(readiness.readiness.score, 45);
  assert.equal(readiness.readiness.source, 'checkin');
  const readinessYesterday = await call(at, 'get_readiness_context', { date: YESTERDAY });
  assert.equal(readinessYesterday.readiness.score, 78);
  await expectToolError(at, 'get_readiness_context', { date: '2026-02-30' }, /YYYY-MM-DD/);

  const correlation = await call(at, 'get_checkin_trading_correlation', { minSample: 3 });
  assert.ok(correlation.days >= 3, JSON.stringify(correlation).slice(0, 300));
  assert.ok(correlation.byEnergy.length >= 2);
  assert.ok(correlation.byMood.length >= 2);
  assert.equal(correlation.byReadiness.length, 4);
  assert.ok(correlation.limitations.length >= 3);
  assert.equal(correlation.healthAccess, 'no_device');
  assert.ok(correlation.correlations.energyVsDailyNet === null || typeof correlation.correlations.energyVsDailyNet.r === 'number');
  await expectToolError(at, 'get_checkin_trading_correlation', { minSample: 0 });

  // =================================================================
  // 6. Prop challenge
  // =================================================================
  const propContext = await call(at, 'get_prop_challenge_context', {});
  assert.equal(propContext.found, true);
  assert.ok(propContext.context.firm);
  assert.ok(propContext.metrics.status);
  assert.ok(propContext.context.drawdownType);
  const propRisk = await call(at, 'get_prop_challenge_risk', {});
  assert.equal(propRisk.found, true);
  assert.ok(propRisk.budget.remainingDaily >= 0);
  assert.ok(propRisk.budget.remainingTotal >= 0);
  assert.ok(propRisk.budget.safetyMarginPct >= 0);
  assert.ok(Array.isArray(propRisk.violations));
  const propUnknown = await call(at, 'get_prop_challenge_context', { challengeId: 'prop_nie_ma' });
  assert.equal(propUnknown.found, false);
  assert.equal(propUnknown.reason, 'challenge_not_found');
  assert.ok(propUnknown.available.length >= 3);
  const propRiskUnknown = await call(at, 'get_prop_challenge_risk', { challengeId: 'prop_nie_ma' });
  assert.equal(propRiskUnknown.found, false);

  // =================================================================
  // 7. Analityka
  // =================================================================
  const analytics = {};
  for (const tool of [
    'get_performance_by_setup', 'get_performance_by_emotion', 'get_performance_by_session',
    'get_performance_by_error', 'get_performance_by_grade', 'get_performance_by_market',
    'get_performance_by_day_plan_compliance',
  ]) {
    analytics[tool] = await call(at, tool, { minSample: 3 });
    const row = analytics[tool];
    assert.equal(row.hasData, true, `${tool}: brak danych`);
    assert.ok(row.sampleSize > 0, `${tool}: sampleSize`);
    assert.ok(row.range.from && row.range.to, `${tool}: zakres dat`);
    assert.equal(row.currency, 'USDT');
  }
  assert.ok(analytics.get_performance_by_setup.items.length >= 2);
  assert.ok(analytics.get_performance_by_emotion.items.length >= 2);
  assert.ok(analytics.get_performance_by_session.items.length >= 1);
  assert.ok(analytics.get_performance_by_session.byHour.length >= 2);
  assert.ok(analytics.get_performance_by_error.items.length >= 2);
  assert.equal(analytics.get_performance_by_grade.items.length, 3);
  assert.ok(analytics.get_performance_by_grade.gradeQualityIndex >= 0);
  assert.ok(analytics.get_performance_by_market.byClass.length >= 1);
  assert.ok(analytics.get_performance_by_day_plan_compliance.sampleSize >= 1);
  assert.ok(analytics.get_performance_by_day_plan_compliance.rulesViolated.length >= 1);

  // Spójność z get_stats: suma klas rynku = wynik netto z get_stats.
  const stats = await call(at, 'get_stats', { period: 'all' });
  assert.equal(stats.hasData, true);
  const classNet = analytics.get_performance_by_market.byClass.reduce((sum, row) => sum + (row.net || 0), 0);
  assert.ok(Math.abs(classNet - stats.net) < 0.05, `net klas ${classNet} != stats ${stats.net}`);
  const setupSum = analytics.get_performance_by_setup.items.reduce((sum, row) => sum + row.sampleSize, 0);
  assert.equal(setupSum, analytics.get_performance_by_setup.sampleSize, 'grupy setupów gubią transakcje');
  const gradeSum = analytics.get_performance_by_grade.items.reduce((sum, row) => sum + row.sampleSize, 0) + analytics.get_performance_by_grade.ungraded.sampleSize;
  assert.equal(gradeSum, stats.trades, 'oceny gubią transakcje');

  const patterns = await call(at, 'get_trade_patterns', { minSample: 3 });
  assert.equal(patterns.hasData, true);
  assert.ok(patterns.items.length >= 2, JSON.stringify(patterns.items.map((p) => p.id)));
  for (const row of patterns.items) {
    assert.ok(row.sampleSize > 0);
    assert.ok(['low', 'medium', 'high'].includes(row.confidence));
    assert.equal(row.causality, 'association_only');
    assert.ok(row.evidence);
  }
  const patternsEmpty = await call(at, 'get_trade_patterns', { from: '2001-01-01', to: '2001-01-02' });
  assert.equal(patternsEmpty.hasData, false);
  assert.deepEqual(patternsEmpty.items, []);

  // =================================================================
  // 8. Kalkulator, kapitał, podatki
  // =================================================================
  const calc = await call(at, 'get_calculator_result', {
    balance: 10000, riskPercent: 1, direction: 'long', entry: 100, stopLoss: 95, takeProfit: 120, leverage: 10,
  });
  assert.equal(calc.maxLoss, 100);
  assert.equal(calc.stopDistancePct, 5);
  assert.equal(calc.positionValue, 2000);
  assert.equal(calc.marginRequired, 200);
  assert.equal(calc.potentialProfit, 400);
  assert.equal(calc.riskReward, 4);
  assert.equal(calc.breakevenWinRatePct, 20);
  assert.equal(calc.positionSize, 20);

  const calcFx = await call(at, 'get_calculator_result', {
    mode: 'fx', instrument: 'EUR/USD', balance: 10000, riskPercent: 1, direction: 'long',
    entry: 1.1, stopLoss: 1.095, takeProfit: 1.12, leverage: 30,
  });
  assert.equal(calcFx.positionSizeUnit, 'lots');
  assert.equal(calcFx.stopDistance, 50);
  assert.equal(calcFx.pipValue, 10);
  assert.equal(calcFx.potentialProfit, 400);
  assert.equal(calcFx.riskReward, 4);

  const calcShort = await call(at, 'get_calculator_result', {
    balance: 5000, riskPercent: 2, direction: 'short', entry: 100, stopLoss: 105,
  });
  assert.equal(calcShort.maxLoss, 100);
  assert.equal(calcShort.takeProfit, null);
  assert.equal(calcShort.potentialProfit, null);
  await expectToolError(at, 'get_calculator_result', { balance: 1000, riskPercent: 1, direction: 'long', entry: 100, stopLoss: 105 }, /poniżej wejścia/);
  await expectToolError(at, 'get_calculator_result', { mode: 'fx', instrument: 'XYZ/ABC', balance: 1000, riskPercent: 1, direction: 'long', entry: 1, stopLoss: 0.9 }, /Nieznany instrument/);
  await expectToolError(at, 'get_calculator_result', { balance: 0, riskPercent: 1, direction: 'long', entry: 1, stopLoss: 0.9 });

  const transactions = await call(at, 'get_account_transactions', { limit: 100 });
  assert.ok(transactions.total >= 4, JSON.stringify(transactions.totals));
  assert.ok(transactions.totals.deposits > 0);
  assert.ok(transactions.items.every((row) => row.type && row.valuePln !== undefined));
  const deposits = await call(at, 'get_account_transactions', { type: 'deposit', limit: 100 });
  assert.ok(deposits.items.every((row) => row.type === 'deposit'));
  await expectToolError(at, 'get_account_transactions', { type: 'przelew' });

  const tax = await call(at, 'get_tax_summary', { year: 2026 });
  assert.equal(tax.year, 2026);
  assert.ok(tax.pit38.revenue >= 0);
  assert.ok(tax.pit38.costs >= 0);
  assert.ok(tax.pit38.taxDue19Pct >= 0);
  assert.ok(tax.flows.count >= 4);
  assert.ok(tax.flows.currencies.length >= 1);
  assert.match(tax.disclaimer, /pomocnicze/i);
  const taxDefault = await call(at, 'get_tax_summary', {});
  assert.equal(taxDefault.year, 2026);
  await expectToolError(at, 'get_tax_summary', { year: 1999 });
  await expectToolError(at, 'get_tax_summary', { year: 'dwadzieścia' });

  // =================================================================
  // 9. Makro, synchronizacje, odznaki
  // =================================================================
  const macroList = await call(at, 'get_macro_calendar', { from: '2020-01-01', to: '2100-01-01', limit: 5 });
  assert.ok(macroList.items.length >= 1);
  assert.equal(macroList.returnedCount, macroList.items.length);
  assert.ok(macroList.matchedCount >= macroList.returnedCount);
  assert.ok(macroList.storedCount >= macroList.matchedCount);
  const macroDetails = await call(at, 'get_macro_event_details', { eventId: macroList.items[0].id });
  assert.equal(macroDetails.found, true);
  assert.equal(macroDetails.event.actual, null);
  assert.equal(macroDetails.event.actualAvailable, false);
  assert.ok(['scheduled', 'past_without_result'].includes(macroDetails.event.publicationStatus));
  const macroMissing = await call(at, 'get_macro_event_details', { eventId: '999999999' });
  assert.equal(macroMissing.found, false);
  const macroByExternal = await call(at, 'get_macro_event_details', { eventId: macroList.items[0].externalId });
  assert.equal(macroByExternal.found, true);

  const sync = await call(at, 'get_integration_sync_history', { limit: 10 });
  assert.ok(Array.isArray(sync.items));
  assert.ok('syncState' in sync);
  assert.equal(JSON.stringify(sync).toLowerCase().includes('credentials'), false);
  await expectToolError(at, 'get_integration_sync_history', { provider: 'zły provider!' });

  const badges = await call(at, 'get_badges_progress', {});
  assert.equal(badges.items.length, 10);
  assert.equal(badges.summary.maxLevels, 30);
  const firstTrade = badges.items.find((row) => row.id === 'first_trade');
  assert.equal(firstTrade.status, 'earned');
  assert.equal(firstTrade.level >= 1, true);
  assert.ok(firstTrade.earnedAt, 'data zdobycia pierwszej odznaki');
  const streak = badges.items.find((row) => row.id === 'win_streak');
  assert.ok(['earned', 'in_progress', 'not_started'].includes(streak.status));
  assert.equal(streak.tiers.length, 3);

  // =================================================================
  // 10. Izolacja użytkowników
  // =================================================================
  const clientB = await register('MCP Tools E2E B');
  const authB = await authorize(clientB, b.session, 'alphavault.read');
  const tokB = await token(clientB, authB);
  const atB = tokB.access_token;
  patchState(b.userId, { trades: [], plans: {}, checkins: [], setups: [], prop: { challenges: [], selectedId: null }, tradeReviews: {} });
  assert.equal((await call(atB, 'get_trade_journal_context', { tradeId: 'fx_aligned_short' })).found, false);
  assert.equal((await call(atB, 'get_trade_review', { tradeId: 'fx_aligned_short' })).found, false);
  assert.equal((await call(atB, 'get_trade_screenshots', { tradeId: 'fx_aligned_short' })).found, false);
  assert.equal((await call(atB, 'get_day_plan_context', { date: TODAY })).plan, null);
  assert.equal((await call(atB, 'get_prop_challenge_context', {})).found, false);
  const badgesB = await call(atB, 'get_badges_progress', {});
  assert.equal(badgesB.sampleSize, 0, 'B nie ma własnych transakcji');
  assert.equal(badgesB.items.find((row) => row.id === 'journal').value, 0);
  const statusForeign = await call(atB, 'get_proposal_status', { proposalId: crypto.randomUUID() });
  assert.equal(statusForeign.found, false);
  assert.equal(statusForeign.status, null);

  // =================================================================
  // 11. Propozycje: cykl życia każdego nowego typu
  // =================================================================
  const stateBefore = stateOf(a.userId).state;
  const issueCode = (proposalId) => JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['code', proposalId, String(a.userId)]));
  const approveWith = async (proposal, key) => {
    const issued = issueCode(proposal.proposalId);
    return call(at, 'approve_proposal', {
      proposalId: proposal.proposalId,
      expectedDiffHash: proposal.diffHash,
      confirmationCode: issued.code,
      idempotencyKey: key,
    });
  };
  const approve = (proposal) => approveWith(proposal, idem('approve'));

  const tradeProposal = await call(at, 'create_trade_proposal', {
    pair: 'E2E/USDT', dir: 'long', entry: 100, size: 2, exit: 110, sl: 95, tp: 120, fee: 1,
    setup: 'Retest wybicia', grade: 'B', checklist: 5, checkTotal: 7, note: 'E2E wpis',
    date: YESTERDAY, time: '12:30', account: 'WEEX', idempotencyKey: idem('trade-create'),
  });
  assert.equal(tradeProposal.status, 'pending');
  assert.match(tradeProposal.diffHash, /^[a-f0-9]{64}$/);
  assert.ok(tradeProposal.changes.includes('pair'));
  assert.ok(tradeProposal.expiresAt);
  assert.equal(stateOf(a.userId).state.trades.length, stateBefore.trades.length, 'propozycja zmieniła stan');

  const tradeApproveKey = idem('approve-trade');
  const tradeApproved = await approveWith(tradeProposal, tradeApproveKey);
  assert.equal(tradeApproved.status, 'approved');
  const replay = await call(at, 'approve_proposal', {
    proposalId: tradeProposal.proposalId,
    expectedDiffHash: tradeProposal.diffHash,
    confirmationCode: 'ZZZZ-ZZZZ',
    idempotencyKey: tradeApproveKey,
  });
  assert.equal(replay.duplicate, true, 'idempotentny replay nie zwrócił duplicate');
  const afterTrade = stateOf(a.userId).state;
  assert.equal(afterTrade.trades.length, stateBefore.trades.length + 1);
  const newTrade = afterTrade.trades.find((row) => row.pair === 'E2E/USDT');
  assert.ok(newTrade, 'brak nowej transakcji');
  assert.equal(newTrade.result, 20);
  assert.equal(newTrade.r, 2);
  assert.equal(newTrade.source, 'Ręcznie');
  assert.equal(newTrade.fee, 1);

  const tradeStatus = await call(at, 'get_proposal_status', { proposalId: tradeProposal.proposalId });
  assert.equal(tradeStatus.status, 'approved');
  assert.equal(tradeStatus.before, null, 'nowa transakcja nie ma stanu przed');
  assert.ok(tradeStatus.after);

  const updateProposal = await call(at, 'update_trade_proposal', {
    tradeId: newTrade.id,
    changes: { note: 'Poprawiona notatka', grade: 'A', setup: 'Pullback w trendzie' },
    idempotencyKey: idem('trade-update'),
  });
  for (const field of ['grade', 'note', 'setup']) {
    assert.ok(updateProposal.changes.includes(field), `${field} nie ma w diffie: ${updateProposal.changes}`);
  }
  assert.equal(stateOf(a.userId).state.trades.find((row) => row.id === newTrade.id).note, 'E2E wpis', 'propozycja zmieniła stan przed zgodą');
  await approve(updateProposal);
  const updatedTrade = stateOf(a.userId).state.trades.find((row) => row.id === newTrade.id);
  assert.equal(updatedTrade.note, 'Poprawiona notatka');
  assert.equal(updatedTrade.grade, 'A');
  assert.equal(updatedTrade.setup, 'Pullback w trendzie');

  await expectToolError(at, 'update_trade_proposal', {
    tradeId: 'ex1', changes: { entry: 1 }, idempotencyKey: idem('trade-update-ex'),
  }, /giełd/i);
  await expectToolError(at, 'update_trade_proposal', {
    tradeId: 'nie-ma-takiej', changes: { note: 'x' }, idempotencyKey: idem('trade-update-missing'),
  }, /nie istnieje/i);

  const setupName = 'E2E Setup ' + crypto.randomBytes(3).toString('hex');
  const setupProposal = await call(at, 'create_setup_proposal', {
    name: setupName, entryRules: ['Zasada A', 'Zasada B'], description: 'Opis E2E',
    timeframes: 'H1', confirmation: 'Zamknięcie H1', invalidation: 'Powrót pod poziom',
    stopLoss: 'Pod knotem', minRR: 2.5, exitRules: ['TP na oporze'], examples: ['BTC retest'],
    grade: 'A', idempotencyKey: idem('setup-create'),
  });
  assert.equal(setupProposal.status, 'pending');
  await approve(setupProposal);
  const createdSetup = stateOf(a.userId).state.setups.find((row) => row.name === setupName);
  assert.ok(createdSetup);
  assert.equal(createdSetup.minRR, 2.5);
  assert.equal(createdSetup.exitRules.length, 1);

  const setupUpdate = await call(at, 'update_setup_proposal', {
    setupId: createdSetup.id,
    changes: { rules: ['Zasada A', 'Zasada B', 'Zasada C'], minRR: 3 },
    idempotencyKey: idem('setup-update'),
  });
  assert.ok(setupUpdate.changes.includes('rules'));
  await approve(setupUpdate);
  const updatedSetup = stateOf(a.userId).state.setups.find((row) => row.id === createdSetup.id);
  assert.equal(updatedSetup.rules.length, 3);
  assert.equal(updatedSetup.minRR, 3);

  const planProposal = await call(at, 'create_day_plan_proposal', {
    date: TODAY, markets: ['BTC', 'ETH'], bias: 'long', reason: 'E2E plan',
    levels: [{ type: 'Wsparcie', price: '68000', note: 'retest' }],
    scenarioA: { condition: 'retest 68k', action: 'long' },
    scenarioB: { condition: 'zamknięcie pod 67.5k', action: 'stop' },
    riskPercent: 0.5, maxTrades: 2, until: '20:00', rulesNotToDo: ['Bez altcoinów', 'Bez wejść po 20:00'],
    idempotencyKey: idem('plan-create'),
  });
  assert.equal(planProposal.status, 'pending');
  await approve(planProposal);
  const plan = stateOf(a.userId).state.plans[TODAY];
  assert.deepEqual(plan.markets, ['BTC', 'ETH']);
  assert.equal(plan.market, 'BTC');
  assert.equal(plan.risk, '0.5%');
  assert.equal(plan.maxTrades, '2');
  assert.equal(plan.ifMain, 'retest 68k');
  assert.equal(plan.notDo, "Bez altcoinów\nBez wejść po 20:00");
  assert.equal(plan.approvedAt, null, 'MCP nie zatwierdza planu');

  const checkinProposal = await call(at, 'create_checkin_proposal', {
    mood: 'Skupienie', energy: 5, note: 'E2E check-in', date: YESTERDAY, idempotencyKey: idem('checkin-create'),
  });
  assert.equal(checkinProposal.status, 'pending');
  await approve(checkinProposal);
  const yesterdayCheckin = stateOf(a.userId).state.checkins.find((row) => row.date === YESTERDAY);
  assert.equal(yesterdayCheckin.mood, 'Skupienie');
  assert.equal(yesterdayCheckin.energy, 5);
  assert.equal(yesterdayCheckin.score, 77);

  const riskProposal = await call(at, 'create_risk_rules_proposal', {
    changes: { maxRisk: 0.5, maxLosses: 1 }, reason: 'Zaostrzenie zasad po serii strat',
    idempotencyKey: idem('risk-rules'),
  });
  assert.ok(riskProposal.changes.includes('maxRisk'));
  await approve(riskProposal);
  const tightened = stateOf(a.userId).state.settings;
  assert.equal(tightened.maxRisk, 0.5);
  assert.equal(tightened.maxLosses, 1);
  assert.equal(tightened.safetyEnabled, true);

  await expectToolError(at, 'create_risk_rules_proposal', {
    changes: { maxRisk: 5 }, reason: 'Chcę większe ryzyko', idempotencyKey: idem('risk-rules-weak'),
  }, /zmniejszać/i);
  await expectToolError(at, 'create_risk_rules_proposal', {
    changes: { safetyEnabled: false }, reason: 'Wyłączam wyłącznik', idempotencyKey: idem('risk-rules-off'),
  }, /wyłącznik/i);
  await expectToolError(at, 'create_risk_rules_proposal', {
    changes: { maxRisk: 0.4 }, reason: 'krótko', idempotencyKey: idem('risk-rules-short'),
  });

  const propProposal = await call(at, 'create_prop_challenge_proposal', {
    templateId: 'ftmo-1step', name: 'E2E Prop', account: 'WEEX', accountSize: 10000,
    startBalance: 10000, startDate: TODAY, endDate: dayKey(30), idempotencyKey: idem('prop-create'),
  });
  assert.equal(propProposal.status, 'pending');
  await approve(propProposal);
  const createdChallenge = stateOf(a.userId).state.prop.challenges.find((row) => row.name === 'E2E Prop');
  assert.ok(createdChallenge);
  assert.equal(createdChallenge.accountSize, 10000);

  const propUpdate = await call(at, 'update_prop_challenge_proposal', {
    challengeId: createdChallenge.id, changes: { name: 'E2E Prop v2', maxLossPct: 8 },
    idempotencyKey: idem('prop-update'),
  });
  assert.ok(propUpdate.changes.includes('name'));
  await approve(propUpdate);
  const updatedChallenge = stateOf(a.userId).state.prop.challenges.find((row) => row.id === createdChallenge.id);
  assert.equal(updatedChallenge.name, 'E2E Prop v2');
  assert.equal(updatedChallenge.maxLossPct, 8);
  await expectToolError(at, 'update_prop_challenge_proposal', {
    challengeId: createdChallenge.id, changes: { nieznane: 1 }, idempotencyKey: idem('prop-update-bad'),
  }, /unexpected additional properties|Nieznane pole/i);

  const rejectedProposal = await call(at, 'create_day_plan_proposal', {
    date: YESTERDAY, markets: ['ETH'], bias: 'short', reason: 'Plan do odrzucenia',
    idempotencyKey: idem('plan-reject'),
  });
  const rejected = await call(at, 'reject_proposal', {
    proposalId: rejectedProposal.proposalId, idempotencyKey: idem('plan-reject-decision'),
  });
  assert.equal(rejected.status, 'rejected');
  assert.equal((await call(at, 'get_proposal_status', { proposalId: rejectedProposal.proposalId })).status, 'rejected');

  const expiring = await call(at, 'create_day_plan_proposal', {
    date: TODAY, markets: ['SOL'], bias: 'long', reason: 'Plan wygasający',
    idempotencyKey: idem('plan-expire'),
  });
  runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['expire', expiring.proposalId]);
  assert.equal((await call(at, 'get_proposal_status', { proposalId: expiring.proposalId })).status, 'expired');

  const sameKey = idem('idem-repeat');
  const first = await call(at, 'create_setup_proposal', {
    name: 'E2E Repeat', entryRules: ['Zasada A1', 'Zasada B1'], idempotencyKey: sameKey,
  });
  const second = await call(at, 'create_setup_proposal', {
    name: 'E2E Repeat', entryRules: ['Zasada A1', 'Zasada B1'], idempotencyKey: sameKey,
  });
  assert.equal(second.proposalId, first.proposalId, 'idempotencja nie zwróciła tej samej propozycji');
  await expectToolError(at, 'create_setup_proposal', {
    name: 'E2E Repeat', entryRules: ['Zasada A1', 'Zasada B2'], idempotencyKey: sameKey,
  }, /idempotencyKey|inną treścią/i);

  // =================================================================
  // 12. Workflow E2E
  // =================================================================
  // WF1 — analiza transakcji.
  const wf1Context = await call(at, 'get_trade_journal_context', { tradeId: 'fx_aligned_short' });
  const wf1Setup = await call(at, 'get_setup_performance', { setupId: wf1Context.setup.name });
  const wf1Plan = await call(at, 'get_day_plan_compliance', { date: YESTERDAY });
  const wf1Review = await call(at, 'get_trade_review', { tradeId: 'fx_aligned_short' });
  const wf1Proposal = await call(at, 'update_trade_proposal', {
    tradeId: 'fx_aligned_short', changes: { note: 'WF1: analiza po sesji' }, idempotencyKey: idem('wf1'),
  });
  assert.equal(wf1Context.compliance.aligned, true);
  assert.ok(wf1Setup.metrics.hasData);
  assert.equal(wf1Plan.summary.alignedTrades >= 1, true);
  assert.equal(wf1Review.reviewAvailable, true);
  assert.equal(wf1Proposal.status, 'pending');
  await call(at, 'reject_proposal', { proposalId: wf1Proposal.proposalId, idempotencyKey: idem('wf1-reject') });

  // WF2 — przygotowanie sesji.
  const wf2Plan = await call(at, 'get_day_plan_context', { date: TODAY });
  const wf2Ready = await call(at, 'get_readiness_context', {});
  const wf2Macro = await call(at, 'get_macro_event_details', { eventId: macroList.items[0].id });
  const wf2Rules = await call(at, 'get_risk_rules', {});
  const wf2Protection = await call(at, 'get_protection_status', {});
  const wf2Proposal = await call(at, 'create_day_plan_proposal', {
    date: TODAY, markets: ['BTC'], bias: 'long', reason: 'WF2 szkic sesji',
    riskPercent: 0.5, maxTrades: 2, idempotencyKey: idem('wf2'),
  });
  assert.ok(wf2Plan.trades.length >= 1);
  assert.ok(wf2Ready.readiness.score !== null);
  assert.equal(wf2Macro.found, true);
  assert.ok(wf2Rules.maxRiskPercentPerTrade > 0);
  assert.equal(wf2Protection.triggered, true);
  assert.equal(wf2Proposal.status, 'pending');
  await call(at, 'reject_proposal', { proposalId: wf2Proposal.proposalId, idempotencyKey: idem('wf2-reject') });

  // WF3 — psychologia.
  const wf3Corr = await call(at, 'get_checkin_trading_correlation', { minSample: 3 });
  const wf3Emotion = await call(at, 'get_performance_by_emotion', { minSample: 3 });
  const wf3Error = await call(at, 'get_performance_by_error', { minSample: 3 });
  const wf3Patterns = await call(at, 'get_trade_patterns', { minSample: 3 });
  assert.ok(wf3Corr.days >= 3);
  assert.ok(wf3Emotion.items.length >= 2);
  assert.ok(wf3Error.items.length >= 2);
  assert.ok(wf3Patterns.items.length >= 2);

  // WF4 — prop challenge.
  const wf4Context = await call(at, 'get_prop_challenge_context', {});
  const wf4Risk = await call(at, 'get_prop_challenge_risk', {});
  const wf4Violations = await call(at, 'get_risk_violations', { limit: 50 });
  const wf4Compliance = await call(at, 'get_performance_by_day_plan_compliance', { minSample: 3 });
  assert.equal(wf4Context.found, true);
  assert.equal(wf4Risk.found, true);
  assert.ok(wf4Violations.total > 0);
  assert.ok(wf4Compliance.sampleSize > 0);

  // WF5 — kalkulator i kapitał.
  const wf5Accounts = await call(at, 'get_accounts', {});
  const wf5Capital = await call(at, 'get_capital_summary', {});
  const wf5Tx = await call(at, 'get_account_transactions', { limit: 5 });
  const wf5Calc = await call(at, 'get_calculator_result', {
    balance: wf5Capital.capital, riskPercent: 1, direction: 'long', entry: 100, stopLoss: 98, takeProfit: 106, leverage: 5,
  });
  assert.ok(wf5Accounts.total >= 3);  assert.ok(wf5Capital.capital > 0);
  assert.ok(wf5Tx.items.length >= 1);
  assert.ok(wf5Calc.maxLoss > 0);

  // WF6 — pełna analityka spójna z get_stats.
  const wf6Stats = await call(at, 'get_stats', { period: 'all' });
  const wf6Market = await call(at, 'get_performance_by_market', { minSample: 3 });
  const wf6Setup = await call(at, 'get_performance_by_setup', { minSample: 3 });
  const wf6Grade = await call(at, 'get_performance_by_grade', { minSample: 3 });
  assert.ok(Math.abs(wf6Market.byClass.reduce((sum, row) => sum + (row.net || 0), 0) - wf6Stats.net) < 0.05);
  assert.equal(wf6Setup.sampleSize, wf6Stats.trades);
  assert.equal(
    wf6Grade.items.reduce((sum, row) => sum + row.sampleSize, 0) + wf6Grade.ungraded.sampleSize,
    wf6Stats.trades
  );

  console.log('MCP tools E2E PASS');
}

let server = null;
let serverLog = '';
async function startServer() {
  server = spawn(
    PHP,
    [...PHP_ARGS, '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'mcp', 'public'), path.join(ROOT, 'mcp', 'public', 'index.php')],
    {
      cwd: ROOT,
      env: {
        ...process.env,
        ALPHA_VAULT_CONFIG: CONFIG,
        ...LOCAL_ENV,
        MCP_WRITES_ENABLED: 'true',
        MCP_PILOT_DISCORD_IDS: 'mcp-e2e-a',
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    }
  );
  server.stdout.on('data', (chunk) => { serverLog += chunk.toString(); });
  server.stderr.on('data', (chunk) => { serverLog += chunk.toString(); });
  await waitForHealth();
}

async function stopServer() {
  if (!server) return;
  server.kill('SIGTERM');
  server = null;
  await new Promise((resolve) => setTimeout(resolve, 400));
}

runPhp(path.join(ROOT, 'tests', 'seed-mcp.php'));
runPhp(path.join(ROOT, 'tests', 'mcp-user-service.php'));

try {
  await startServer();
  await main();
} catch (err) {
  console.error(serverLog);
  throw err;
} finally {
  await stopServer();
  if (fs.existsSync(PATCH_FILE)) fs.unlinkSync(PATCH_FILE);
  runPhp(path.join(ROOT, 'tests', 'cleanup-mcp.php'));
}
