#!/usr/bin/env node
/**
 * Alpha Vault — testy UI e2e (Playwright + systemowy Chrome).
 * Uruchom: npm run test:ui
 * Wymaga serwera: npm start
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const candidates = [
  process.env.CHROME_PATH,
  path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser'
].filter(Boolean);
const executablePath = candidates.find((p) => fs.existsSync(p));
if (!executablePath) {
  console.error('Brak Chrome/Edge. Ustaw CHROME_PATH.');
  process.exit(2);
}

const results = [];
const consoleErrors = [];
const pageErrors = [];
function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
page.on('pageerror', (err) => pageErrors.push(err.message));

// Deterministyczne notowania: test nie zależy od sieci ani od dostawcy.
const QUOTE_STUB = {
  quotes: [
    { symbol: 'BTC', price: 71411.5, changePercent: 1.2 },
    { symbol: 'ETH', price: 3407.25, changePercent: -0.7 },
    { symbol: 'SOL', price: 81.48, changePercent: -2.1 },
    { symbol: 'NAS100', price: 21426, changePercent: 0.4 },
    { symbol: 'US500', price: 6192, changePercent: 0.2 },
    { symbol: 'XAUUSD', price: 3881.6, changePercent: 0.7 },
    { symbol: 'EUR/USD', price: 1.1057, changePercent: -0.3 },
    { symbol: 'OIL', price: 71.78, changePercent: 1.1 },
    { symbol: 'BTC/USDT', price: 71411.5, changePercent: 1.2 },
    { symbol: 'SOL/USDT', price: 81.48, changePercent: -2.1 }
  ],
  updatedAt: '2026-11-12T12:00:00Z',
  stale: false
};
let quoteRequests = 0;
await context.route('**/api/quotes**', (route) => {
  quoteRequests++;
  return route.fulfill({
    status: 200,
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify(QUOTE_STUB)
  });
});

try {
  await page.goto('/', { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);
  log('UI start', await page.locator('#auth').isVisible(), 'ekran logowania');

  await page.click('#enter');
  await page.waitForSelector('#demoEnter');
  log('modal logowania', await page.locator('#demoEnter').isVisible(), 'Discord niepodłączony → demo');

  await page.click('#demoEnter');
  await page.waitForFunction(() => document.querySelector('#auth')?.classList.contains('hide'), null, { timeout: 15000 });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated);
  log('logowanie demo + sesja', await page.locator('#auth').evaluate((el) => el.classList.contains('hide')), 'cookie + sesja');
  log('start: jedno zapytanie o notowania', quoteRequests <= 1, quoteRequests + ' requestów');

  // linki prawne w modalu Akademii oraz na ekranach wygaśnięcia i braku roli
  await page.evaluate(() => document.querySelector('[data-doc="ak"]').click());
  await page.waitForSelector('#docM.on');
  const docLinks = await page.evaluate(() => ({
    privacy: !!document.querySelector('#docB a[href="/polityka-prywatnosci"]'),
    terms: !!document.querySelector('#docB a[href="/regulamin"]'),
    contact: !!document.querySelector('#docB a[href^="mailto:kontakt@alphamoves.pl"]')
  }));
  log('modal Akademii: linki prawne', docLinks.privacy && docLinks.terms && docLinks.contact, JSON.stringify(docLinks));
  await page.evaluate(() => document.querySelector('#docM [data-close]').click());
  const gateLinks = await page.evaluate(() => ({
    expired: !!document.querySelector('#expired a[href="/polityka-prywatnosci"]') && !!document.querySelector('#expired a[href="/regulamin"]'),
    role: !!document.querySelector('#roleGate a[href="/polityka-prywatnosci"]') && !!document.querySelector('#roleGate a[href="/regulamin"]')
  }));
  log('ekrany wygaśnięcia i roli: linki prawne', gateLinks.expired && gateLinks.role, JSON.stringify(gateLinks));

  const policy = await page.evaluate(async () => {
    const html = await (await fetch('/polityka-prywatnosci')).text();
    return {
      mcp: html.includes('13.2. Zewnętrzni asystenci przez MCP'),
      provider: html.includes('OpenAI (ChatGPT), Anthropic (Claude), xAI (Grok)'),
      writes: html.includes('zapis zależy od konfiguracji serwera') &&
        html.includes('Nie ma osobnego zakresu OAuth dla zapisu') &&
        html.includes('Usunięcia trafiają do kosza'),
      healthScope: html.includes('Dane fizjologiczne z urządzenia') &&
        html.includes('health.physiologyScore') && html.includes('alphavault.health') &&
        html.includes('bez niego pole <code>health</code> jest <code>null</code>'),
      checkinReadiness: html.includes('readiness.score</code> może być dostępny również bez zakresu urządzeń') &&
        html.includes('check-in i jego ocena należą do danych wpisanych w panelu'),
      staleReadOnlyClaim: html.includes('działa w trybie tylko do odczytu') || html.includes('zapis jest wyłączony po stronie serwera'),
      revoke: html.includes('oauth/connected')
    };
  });
  log('polityka prywatności: zakres MCP odczytu/zapisu i wycofanie zgody',
    policy.mcp && policy.provider && policy.writes && policy.healthScope && policy.checkinReadiness &&
      !policy.staleReadOnlyClaim && policy.revoke, JSON.stringify(policy));

  const rows = await page.locator('#tbody tr').count();
  assert.equal(rows, 47);
  log('dziennik z bazy', rows === 47, rows + ' transakcji');

  await page.click('.navg button[data-v="akad"]');
  await page.waitForTimeout(600);
  const liveTitle = (await page.locator('#liveTitle').innerText()).trim();
  const liveWho = (await page.locator('#liveWho').innerText()).trim();
  const nextConsult = (await page.locator('#nextConsult').innerText()).trim();
  const acadText = (await page.locator('#acad').innerText()).trim();
  const liveTop = (await page.locator('#liveTop').innerText()).trim();
  const liveHref = await page.locator('#liveJoin').getAttribute('href');
  const liveCardText = await page.locator('#liveCard').innerText();
  log('Discord live z API', liveTitle.includes('AT - Marcin') && liveWho.includes('Marcin'), liveTitle + ' / ' + liveWho);
  log('Discord live link', /^https:\/\/discord\.com\/events\/\d{17,20}\/\d{17,20}$/.test(liveHref || ''), liveHref || 'brak');
  log('brak countdownu Start za', !liveCardText.includes('Start za'), liveCardText.replace(/\s+/g, ' ').slice(0, 80));
  log('Discord konsultacja z API', nextConsult.length > 0, nextConsult);
  log('Discord harmonogram tygodnia', acadText.includes('AT - Marcin'), acadText.slice(0, 120));
  log('Discord strip live', liveTop.length > 0, liveTop);

  // nawigacja po wszystkich widokach
  const views = ['plan', 'trans', 'play', 'nowa', 'kalk', 'prop', 'stat', 'mentor', 'akad', 'tax', 'kal', 'cialo', 'gieldy', 'ust', 'pulpit'];
  for (const v of views) {
    await page.click(`.navg button[data-v="${v}"]`);
    const on = await page.locator('.view.on').getAttribute('id');
    assert.equal(on, v, 'widok ' + v);
  }
  log('nawigacja wszystkich widoków', true, views.length + ' widoków');

  const aliases = await page.evaluate(() => {
    const out = {};
    ['plan3', 'dziennik3', 'playbook3', 'akad3', 'stat3'].forEach((slug) => {
      location.hash = '#/' + slug;
      out[slug] = AV.viewFromHash();
    });
    location.hash = '#/pulpit';
    return out;
  });
  log('stare trasy *3 działają', aliases.plan3 === 'plan' && aliases.dziennik3 === 'trans' && aliases.playbook3 === 'play' && aliases.akad3 === 'akad' && aliases.stat3 === 'stat', JSON.stringify(aliases));

  // zaufanie: brak deklaracji 2FA/sesji/kodu API, uczciwe copy integracji
  await page.click('.navg button[data-v="ust"]');
  const trust = await page.evaluate(() => {
    const ust = document.querySelector('#ust')?.innerText || '';
    return {
      twofa: ust.includes('2FA na Discordzie'),
      sessions: ust.includes('Aktywne sesje') || !!document.querySelector('#outAll'),
      apiCode: !!document.querySelector('[data-setting="apiCode"]'),
      deviceSession: ust.includes('Sesja na tym urządzeniu'),
      simExp: !!document.querySelector('#simExp'),
      simSolo: !!document.querySelector('#simSolo')
    };
  });
  log('konto: brak fałszywego 2FA/sesji/kodu API', !trust.twofa && !trust.sessions && !trust.apiCode && trust.deviceSession && !trust.simExp && !trust.simSolo, JSON.stringify(trust));
  await page.click('.navg button[data-v="gieldy"]');
  const exCopy = await page.evaluate(() => document.querySelector('#gieldy')?.innerText || '');
  log('giełdy: klucz tylko do odczytu', exCopy.includes('tylko odczyt') && !exCopy.includes('dowolne (odczyt wystarcza)'), exCopy.slice(0, 120));
  const landingCopy = await page.evaluate(() => document.body.innerText);
  log('landing: bez dowolnych uprawnień', !landingCopy.includes('dowolnymi uprawnieniami'), 'ok');
  await page.click('.navg button[data-v="trans"]');
  const journalCopy = await page.evaluate(() => document.querySelector('#trans')?.innerText || '');
  log('dziennik: import do 25 minut', journalCopy.includes('do 25 minut') && !journalCopy.includes('co 30 minut'), 'ok');
  await page.click('.navg button[data-v="mentor"]');
  await page.waitForFunction(() => {
    const state = document.querySelector('.mcp-server-status')?.dataset.state;
    return ['read', 'write', 'unknown'].includes(state || '');
  });
  const mentorCopy = await page.evaluate(() => document.querySelector('#mentor')?.innerText || '');
  const mcpStatus = await page.evaluate(async () => {
    const pill = document.querySelector('.mcp-server-status');
    const response = await fetch('/api/mcp', { credentials: 'same-origin' });
    const data = response.ok ? await response.json() : null;
    const expectedState = !data ? 'unknown' : data.writesEnabled === true ? 'write' : 'read';
    const expectedLabel = {
      write: 'MCP · ZAPIS',
      read: 'MCP · TYLKO ODCZYT',
      unknown: 'MCP · KONFIGURACJA NIEDOSTĘPNA'
    }[expectedState];
    return {
      actualState: pill?.dataset.state || '',
      expectedState,
      actualLabel: pill?.innerText.trim() || '',
      expectedLabel
    };
  });
  log('MCP: konfiguracja panelu zgodna z GET /api/mcp',
    mcpStatus.actualState === mcpStatus.expectedState && mcpStatus.actualLabel === mcpStatus.expectedLabel,
    JSON.stringify(mcpStatus));
  log('MCP: konfiguracja bez fałszywego statusu serwera',
    /MCP · (TYLKO ODCZYT|ZAPIS|KONFIGURACJA NIEDOSTĘPNA)/.test(mentorCopy) &&
      mentorCopy.includes('Nie sprawdza zdalnej dostępności ani konfiguracji serwera') &&
      mentorCopy.includes('mcp.alphavault.pl') &&
      mentorCopy.includes('czy konto jest objęte allowlistą pilota') &&
      !mentorCopy.includes('SERWER MCP GOTOWY'),
    mentorCopy.match(/MCP · [^\n]+/)?.[0] || 'brak statusu');
  const mcpFallback = await page.evaluate(async () => {
    const AV = window.AV;
    const originalApiFetch = AV.apiFetch;
    try {
      AV.apiFetch = async (path, ...args) => {
        if (path === '/api/mcp') throw new Error('kontrolowany błąd testowy');
        return originalApiFetch(path, ...args);
      };
      await AV.loadMcpStatus();
      const pill = document.querySelector('.mcp-server-status');
      return { state: pill?.dataset.state || '', label: pill?.innerText.trim() || '' };
    } finally {
      AV.apiFetch = originalApiFetch;
    }
  });
  log('MCP: kontrolowany błąd API pokazuje neutralny fallback',
    mcpFallback.state === 'unknown' && mcpFallback.label === 'MCP · KONFIGURACJA NIEDOSTĘPNA',
    JSON.stringify(mcpFallback));
  const navLabel = await page.locator('.navg button[data-v="mentor"]').innerText();
  log('sidebar: Integracje AI', navLabel.includes('Integracje AI') && !navLabel.includes('Mentor AI'), navLabel);

  // statystyki: zero transakcji nie może pokazywać fikcyjnych liczb
  const emptyStats = await page.evaluate(() => {
    const backup = AV.state.trades;
    AV.state.trades = [];
    AV.renderStats();
    const out = {
      pf: document.querySelector('#stPf')?.textContent.trim() || '',
      wr: document.querySelector('#stWr')?.textContent.trim() || '',
      plan: document.querySelector('#stPlan')?.textContent.trim() || '',
      fees: document.querySelector('#stFees')?.textContent.trim() || '',
      checklist: document.querySelector('#checklistStats')?.textContent || '',
      insight: document.querySelector('#gradeInsight')?.textContent || ''
    };
    AV.state.trades = backup;
    AV.renderStats();
    return out;
  });
  log('statystyki: puste dane bez fikcji', emptyStats.pf === '—' && emptyStats.wr === '—' && emptyStats.plan === '—' && emptyStats.fees === '' && emptyStats.checklist.includes('Brak danych') && !emptyStats.checklist.includes('7/7') && !emptyStats.insight.includes('Tak trzymaj'), JSON.stringify(emptyStats));
  const historicalChecklistLabels = await page.evaluate(() => {
    const originalChecklist = AV.state.settings.checklist;
    AV.state.settings.checklist = ['Punkt A', 'Punkt B'];
    AV.renderChecklistStats({
      n: 2,
      full: { n: 1, net: 100, wr: 100 },
      part: { n: 1, net: -50, wr: 0 },
      withSL: 50,
      withSLCount: 1
    });
    const text = document.querySelector('#checklistStats')?.textContent || '';
    AV.state.settings.checklist = originalChecklist;
    AV.renderStats();
    return text;
  });
  log(
    'statystyki: etykiety checklisty nie zależą od bieżącej konfiguracji',
    historicalChecklistLabels.includes('Wszystkie zaznaczone punkty')
      && historicalChecklistLabels.includes('Część zaznaczonych punktów')
      && !historicalChecklistLabels.includes('2/2')
      && !historicalChecklistLabels.includes('≤1/2'),
    historicalChecklistLabels
  );

  const fresh = await page.evaluate(() => {
    const s = AV.buildEmpty();
    const p = { approvedAt: 1, market: 'ETH', bias: 'short' };
    const ambiguousState = {
      plans: {
        '2026-01-01#long': { id: 'long', date: '2026-01-01', market: 'ETH', bias: 'long', approvedAt: 1 },
        '2026-01-01#short': { id: 'short', date: '2026-01-01', market: 'ETH', bias: 'short', approvedAt: 1 }
      }
    };
    return {
      match: AV.planMatchesTrade(p, 'ETH/USDT', 's'),
      wrongPair: AV.planMatchesTrade(p, 'BTC/USDT', 's'),
      wrongDir: AV.planMatchesTrade(p, 'ETH/USDT', 'l'),
      draft: AV.planMatchesTrade({ ...p, approvedAt: null }, 'ETH/USDT', 's'),
      compactPair: AV.planMatchesTrade({ ...p, market: 'ETH/USDT' }, 'ETHUSDT', 's'),
      ambiguous: AV.tradePlanMatch(ambiguousState, { date: '2026-01-01T12:00:00Z', pair: 'ETH/USDT', dir: 'l' }),
      joined: s.user.joined,
      progress: s.meta.courseProgress,
      sessions: s.meta.sessions.length,
      weex: s.settings.connections.weex.connected,
      plan: s.subscription.plan
    };
  });
  log('plan: zgodność rynku i kierunku bez arbitrażu', fresh.match && !fresh.wrongPair && !fresh.wrongDir && !fresh.draft && fresh.compactPair && !fresh.ambiguous, JSON.stringify(fresh));
  log('świeże konto: bez demo-seedu', fresh.joined === null && fresh.progress === 0 && fresh.sessions === 0 && fresh.weex === false, JSON.stringify(fresh));

  // Mentor AI: integracje MCP
  await page.click('.navg button[data-v="mentor"]');
  await page.waitForSelector('.mcp-endpoint-card');
  assert.equal((await page.locator('#mcpUrl').innerText()).trim(), 'https://mcp.alphavault.pl/mcp');
  assert.equal(await page.locator('#mcpTabs button[aria-selected="true"]').getAttribute('data-mcp-client'), 'chatgpt');
  assert.match(await page.locator('#mcpSetupTitle').innerText(), /ChatGPT/);
  assert.equal(await page.locator('#msgs').count(), 0);
  await page.click('#mcpTabs button[data-mcp-client="claude"]');
  assert.match(await page.locator('#mcpSetupTitle').innerText(), /Claude/);
  assert.match(await page.locator('#mcpSteps').innerText(), /niestandardowy konektor/);
  await page.locator('#mcpTabs button[data-mcp-client="claude"]').focus();
  await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('#mcpTabs button[aria-selected="true"]').getAttribute('data-mcp-client'), 'grok');
  await page.click('#mcpCopy');
  await page.waitForTimeout(150);
  assert.match(await page.locator('#toast').innerText(), /skopiowany/i);
  assert.equal(await page.locator('.mcp-permission-card').isVisible(), true);
  log('Mentor AI: integracje MCP + zakładki + kopiowanie', true);

  // backup stanu po stronie klienta (do przywrócenia po testach zapisu)
  const backup = await page.evaluate(() => JSON.stringify(AV.state));

  // plan: chipy rynku/biasu, poziomy, zatwierdzenie i trwały zapis w MySQL
  await page.click('.navg button[data-v="plan"]');
  const beforeLevels = await page.locator('#lvls .lvl').count();
  await page.click('#addLvl');
  const afterAdd = await page.locator('#lvls .lvl').count();
  const levelRow = page.locator('#lvls .lvl').last();
  await levelRow.locator('select').selectOption({ label: 'Płynność' });
  await levelRow.locator('input').first().fill('70 123');
  await levelRow.locator('input').nth(1).fill('E2E poziom');
  await page.click('#addLvl');
  const poiRow = page.locator('#lvls .lvl').last();
  await poiRow.locator('select').selectOption({ label: 'POI' });
  await poiRow.locator('input').first().fill('70 124');
  await poiRow.locator('input').nth(1).fill('E2E POI');
  await page.click('#planMarkets .chip[data-m="ETH"]');
  await page.click('#planBias .chip[data-b="short"]');
  const chips = await page.evaluate(() => ({
    market: document.querySelector('#planMarkets .chip.on')?.dataset.m,
    bias: document.querySelector('#planBias .chip.on')?.dataset.b
  }));
  const why = 'E2E: plan dnia ' + Date.now();
  await page.fill('#planWhy', why);
  await page.click('#savePlan');
  await page.waitForFunction(async () => {
    const res = await fetch('/api/state', { cache: 'no-store' });
    if (!res.ok) return false;
    const data = await res.json();
    return !!data.state?.plans?.[AV.dateKey(new Date())]?.approvedAt;
  }, null, { timeout: 15000 });
  const approved = await page.evaluate(() => {
    const p = AV.planById(AV.state, AV.planId);
    return { id: p?.id, ok: !!p?.approvedAt, market: p?.market, bias: p?.bias, levels: p?.levels || [], why: p?.why };
  });
  log('plan dnia: chipy rynku i biasu', chips.market === 'ETH' && chips.bias === 'short', JSON.stringify(chips));
  log('plan dnia: poziomy + zatwierdzenie', afterAdd === beforeLevels + 1 && approved.ok && approved.levels.length >= 2, JSON.stringify(approved));
  log('plan dnia: poziomy Liquidity (Płynność) i POI', approved.levels.some((l) => l.type === 'Liquidity' && l.price === '70 123') && approved.levels.some((l) => l.type === 'POI' && l.price === '70 124'), JSON.stringify(approved.levels));
  const adherence = await page.evaluate(() => document.querySelector('#adherenceHint')?.textContent || '');
  log('plan: bez fikcyjnych statystyk', !adherence.includes('31,40') && !adherence.includes('42,10'), adherence);
  const navBadges = await page.evaluate(() => {
    const p = document.querySelector('.navg button[data-v="plan"] .badge');
    const a = document.querySelector('.navg button[data-v="akad"] .badge');
    const live = AV.liveInfo ? AV.liveInfo().liveNow : false;
    return { plan: p?.textContent.trim(), planVisible: p ? getComputedStyle(p).display !== 'none' : false, acad: a?.textContent.trim(), acadVisible: a ? getComputedStyle(a).display !== 'none' : false, live };
  });
  log('badge planu zależy od zatwierdzenia', navBadges.plan === '✓' && navBadges.planVisible, JSON.stringify(navBadges));
  log('badge LIVE tylko podczas live', navBadges.acadVisible === navBadges.live && (navBadges.live ? navBadges.acad === 'LIVE' : navBadges.acad === ''), JSON.stringify(navBadges));

  // trwałość: przeładowanie czyta plan z MySQL
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated, null, { timeout: 15000 });
  await page.click('.navg button[data-v="plan"]');
  const planReload = await page.evaluate(() => {
    const p = AV.planById(AV.state, AV.planId);
    return {
      id: p?.id,
      date: p?.date,
      market: document.querySelector('#planMarkets .chip.on')?.dataset.m,
      bias: document.querySelector('#planBias .chip.on')?.dataset.b,
      why: document.querySelector('#planWhy')?.value,
      levels: Array.from(document.querySelectorAll('#lvls .lvl')).map((row) => ({
        type: row.querySelector('select').value,
        price: row.querySelectorAll('input')[0].value
      })),
      approved: !!p?.approvedAt
    };
  });
  log('plan dnia: zapis w bazie po reloadzie', planReload.market === 'ETH' && planReload.bias === 'short' && planReload.why === why && planReload.levels.length >= 2 && planReload.approved, JSON.stringify(planReload));
   log('plan dnia: typy poziomów po reloadzie', planReload.levels.some((l) => l.type === 'Liquidity' && l.price === '70 123') && planReload.levels.some((l) => l.type === 'POI' && l.price === '70 124'), JSON.stringify(planReload.levels));

  // dwa niezależne plany tego samego dnia i rynku pozostają osobnymi rekordami po reloadzie
  const firstPlanId = planReload.id;
  const sameMarketBefore = await page.evaluate(() => AV.plansForDate(AV.state, AV.planDate).filter((p) => p.market === 'ETH').length);
  const secondWhy = 'E2E: drugi plan ETH ' + Date.now();
  await page.click('#addPlan');
  const secondPlanId = await page.evaluate(() => AV.planId);
  await page.selectOption('#planMarketSelect', 'ETH');
  await page.click('#planBias [data-b="long"]');
  await page.fill('#planWhy', secondWhy);
  await page.click('#addLvl');
  const secondLevel = page.locator('#lvls .lvl').last();
  await secondLevel.locator('select').selectOption({ label: 'POI' });
  await secondLevel.locator('input').first().fill('70 456');
  await secondLevel.locator('input').nth(1).fill('E2E drugi plan');
  await page.click('#savePlan');
  await page.waitForFunction(async ({ id, why: expectedWhy }) => {
    const res = await fetch('/api/state', { cache: 'no-store' });
    if (!res.ok) return false;
    const data = await res.json();
    return Object.values(data.state?.plans || {}).some((p) => p?.id === id && p.why === expectedWhy && p.approvedAt);
  }, { id: secondPlanId, why: secondWhy }, { timeout: 15000 });
   const sameMarketAfter = await page.evaluate(() => AV.plansForDate(AV.state, AV.planDate)
     .filter((p) => p.market === 'ETH')
     .map((p) => ({ id: p.id, date: p.date, bias: p.bias, why: p.why })));
   log('plan dnia: ten sam dzień i rynek mają niezależne plany', !!firstPlanId && !!secondPlanId && firstPlanId !== secondPlanId && sameMarketAfter.length === sameMarketBefore + 1 && sameMarketAfter.some((p) => p.id === secondPlanId && p.why === secondWhy), JSON.stringify(sameMarketAfter));
   const ambiguousPlanDashboard = await page.evaluate(() => {
     AV.showView('pulpit');
     return {
       plans: AV.plansForDate(AV.state, AV.dateKey(new Date())).length,
       trades: document.querySelector('#dayTrades')?.textContent.trim() || '',
       tradesBar: getComputedStyle(document.querySelector('#tradesBar').parentElement).display,
       risk: document.querySelector('#dayRisk')?.textContent.trim() || '',
       riskBar: getComputedStyle(document.querySelector('#riskBar').parentElement).display,
       tag: document.querySelector('#dashPlanTag')?.textContent.trim() || ''
     };
   });
   log('pulpit: wiele planów nie wybiera arbitralnie limitów',
     ambiguousPlanDashboard.plans > 1 && !ambiguousPlanDashboard.trades.includes('/') &&
       ambiguousPlanDashboard.tradesBar === 'none' && !ambiguousPlanDashboard.risk.includes('/') &&
       ambiguousPlanDashboard.riskBar === 'none' && ambiguousPlanDashboard.tag === 'wiele planów',
     JSON.stringify(ambiguousPlanDashboard));

   await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated, null, { timeout: 15000 });
  await page.click('.navg button[data-v="plan"]');
  await page.waitForFunction((ids) => ids.every((id) => Array.from(document.querySelectorAll('#planSelect option')).some((o) => o.value === id)), [firstPlanId, secondPlanId], { timeout: 15000 });
  await page.selectOption('#planSelect', firstPlanId);
  const firstPlanAfterReload = await page.evaluate(() => {
    const p = AV.planById(AV.state, AV.planId);
    return { id: p?.id, date: p?.date, market: p?.market, bias: p?.bias, why: p?.why, levels: p?.levels || [] };
  });
  await page.selectOption('#planSelect', secondPlanId);
  const secondPlanAfterReload = await page.evaluate(() => {
    const p = AV.planById(AV.state, AV.planId);
    return { id: p?.id, date: p?.date, market: p?.market, bias: p?.bias, why: p?.why, levels: p?.levels || [] };
  });
  log('plan dnia: dwa plany tego samego rynku niezależne po reloadzie',
    firstPlanAfterReload.id === firstPlanId && secondPlanAfterReload.id === secondPlanId &&
      firstPlanAfterReload.date === secondPlanAfterReload.date && firstPlanAfterReload.market === 'ETH' &&
      secondPlanAfterReload.market === 'ETH' && firstPlanAfterReload.why === why && secondPlanAfterReload.why === secondWhy &&
      firstPlanAfterReload.bias !== secondPlanAfterReload.bias &&
       firstPlanAfterReload.levels.some((l) => l.type === 'Liquidity') && firstPlanAfterReload.levels.some((l) => l.type === 'POI') &&
      secondPlanAfterReload.levels.some((l) => l.type === 'POI'),
    JSON.stringify({ firstPlanAfterReload, secondPlanAfterReload }));

  // plan: edycja przez select rynku (działa też na telefonie) i własny rynek
  await page.selectOption('#planMarketSelect', 'SOL');
  await page.click('#planBias [data-b="long"]');
  await page.waitForTimeout(300);
  const planSelect = await page.evaluate(() => {
    const p = AV.planById(AV.state, AV.planId) || {};
    return { market: p.market, bias: p.bias, select: document.querySelector('#planMarketSelect').value, chip: document.querySelector('#planMarkets .chip.on')?.dataset.m };
  });
  log('plan: select rynku i bias', planSelect.market === 'SOL' && planSelect.bias === 'long' && planSelect.select === 'SOL' && planSelect.chip === 'SOL', JSON.stringify(planSelect));

  await page.selectOption('#planMarketSelect', '__other');
  await page.fill('#planMarketCustom', 'EURUSD');
  await page.waitForTimeout(900);
  const planCustom = await page.evaluate(() => ({
    market: (AV.planById(AV.state, AV.planId) || {}).market,
    visible: getComputedStyle(document.querySelector('#planMarketCustom')).display !== 'none'
  }));
  log('plan: własny rynek spoza listy', planCustom.market === 'EURUSD' && planCustom.visible, JSON.stringify(planCustom));
  await page.selectOption('#planMarketSelect', 'ETH');
  await page.waitForTimeout(200);

  // dziennik: filtry
  await page.click('.navg button[data-v="trans"]');
  await page.click('#fRes [data-f="win"]');
  const winRows = await page.locator('#tbody tr').count();
  await page.click('#fRes [data-f="open"]');
  const openRows = await page.locator('#tbody tr').count();
  await page.click('#fRes [data-f="all"]');
  await page.click('#fGrade [data-f="A"]');
  const aRows = await page.locator('#tbody tr').count();
  await page.click('#fGrade [data-f="all"]');
  log('filtry dziennika', winRows === 24 && openRows === 1 && aRows === 21, `${winRows}/${openRows}/${aRows}`);

  // szczegóły transakcji
  await page.locator('#tbody tr').first().click();
  await page.waitForSelector('#tm.on');
  log('szczegóły transakcji', await page.locator('#tmBody').innerText().then((t) => t.includes('SOL/USDT')));
  await page.locator('#tm #mc').click();

  // kalkulator
  await page.click('.navg button[data-v="kalk"]');
  await page.waitForFunction(() => document.querySelector('#rMain')?.textContent !== '—', null, { timeout: 15000 });
  const calc = await page.locator('#rMain').innerText();
  const levWarn = await page.locator('#levWarn').isVisible();
  log('kalkulator: ostrzeżenie o dźwigni', levWarn, levWarn ? 'widoczne' : 'brak');
  await page.click('#calcMode [data-m="fx"]');
  const calcFx = await page.locator('#rMainL').innerText();
  log('kalkulator krypto + FX', calc !== '—' && calcFx.includes('MT4'), calc + ' / ' + calcFx);

  // cena live z API: bez etykiety demo i bez technicznych etykiet w nagłówku
  await page.click('#calcMode [data-m="crypto"]');
  await page.selectOption('#cInst', { label: 'SOL/USDT' });
  await page.waitForFunction(() => document.querySelector('#cPrice b')?.textContent === '81,48', null, { timeout: 15000 });
  const liveCalc = await page.evaluate(() => ({
    shown: document.querySelector('#cPrice b').textContent,
    label: document.querySelector('#cPrice').parentElement.querySelector('.label').textContent,
    envHidden: document.querySelector('#envBadge').hidden,
    envText: document.querySelector('#envBadge').textContent,
    syncHidden: document.querySelector('#syncBadge').hidden,
    syncText: document.querySelector('#syncBadge').textContent,
    ticker: document.querySelector('#ticker').textContent
  }));
  assert.equal(liveCalc.shown, '81,48');
  assert.ok(!/demo/i.test(liveCalc.label), 'etykieta bez demo: ' + liveCalc.label);
  assert.ok(liveCalc.envHidden && !/mysql|połączono/i.test(liveCalc.envText), 'pasek bez statusu bazy: ' + liveCalc.envText);
  assert.ok(liveCalc.syncHidden && !/brak połączeń/i.test(liveCalc.syncText), 'chip giełd ukryty bez połączeń: ' + liveCalc.syncText);
  assert.ok(liveCalc.ticker.includes('BTC') && liveCalc.ticker.includes('NAS100'), 'ticker z API: ' + liveCalc.ticker);
  log('cena live z API + czysty nagłówek', true, 'SOL/USDT ' + liveCalc.shown + ' · 8 notowań w tickerze');

  // kalkulator: portfel → saldo i MM z planu
  await page.selectOption('#cPortfolio', 'acc1');
  const calcPortfolio = await page.evaluate(() => {
    const acc = AV.state.accounts.find((a) => a.id === 'acc1');
    return {
      bal: document.querySelector('#cBal').value,
      expected: String(acc.balance),
      risk: document.querySelector('#cRisk').value,
      lev: document.querySelector('#cLev').value,
      mm: document.querySelector('#cMM').textContent,
      account: AV.calc.accountId
    };
  });
  log('kalkulator: portfel ustawia saldo i MM', calcPortfolio.bal === calcPortfolio.expected && calcPortfolio.risk === '1' && calcPortfolio.lev === '10' && calcPortfolio.mm.includes('1%') && calcPortfolio.account === 'acc1', JSON.stringify(calcPortfolio));

  // kalkulator: wybór R:R i własna wartość
  await page.click('#cDir [data-d="long"]');
  for (const presetValue of ['1.2', '1.5']) {
    await page.selectOption('#cRR', presetValue);
    await page.click('#cAuto');
    const presetRatio = await page.evaluate(() => {
      const en = AV.pnum(document.querySelector('#cEn').value);
      const sl = AV.pnum(document.querySelector('#cSl').value);
      const tp = AV.pnum(document.querySelector('#cTp').value);
      return {
        selected: document.querySelector('#cRR').value,
        en, sl, tp,
        ratio: Math.abs(tp - en) / Math.abs(en - sl),
        label: document.querySelector('#cAuto').textContent.trim()
      };
    });
    const expectedLabel = '1 : ' + presetValue.replace('.', ',');
    log('kalkulator: gotowe R:R ' + presetValue,
      presetRatio.selected === presetValue && presetRatio.sl > 0 && presetRatio.sl < presetRatio.en &&
        presetRatio.tp > presetRatio.en && Math.abs(presetRatio.ratio - Number(presetValue)) < 0.15 &&
        presetRatio.label.includes(expectedLabel),
      JSON.stringify(presetRatio));
  }
  await page.selectOption('#cRR', 'custom');
  await page.fill('#cRRCustom', '4');
  await page.waitForTimeout(500);
  const rrLabel = await page.locator('#cAuto').innerText();
  await page.click('#cAuto');
  const rrRatio = await page.evaluate(() => {
    const en = AV.pnum(document.querySelector('#cEn').value);
    const sl = AV.pnum(document.querySelector('#cSl').value);
    const tp = AV.pnum(document.querySelector('#cTp').value);
    return { en, sl, tp, ratio: Math.abs(tp - en) / Math.abs(en - sl) };
  });
  log('kalkulator: własne R:R 4 i ustawiony SL', /1 : 4/.test(rrLabel) && rrRatio.sl > 0 && rrRatio.sl < rrRatio.en && rrRatio.tp > rrRatio.en && Math.abs(rrRatio.ratio - 4) < 0.2, JSON.stringify({ label: rrLabel, ...rrRatio }));
  await page.selectOption('#cRR', '3');

  // statystyki
  await page.click('.navg button[data-v="stat"]');
  const pnlRangeBeforeStatsChange = await page.locator('#pnlCalSeg button.on').getAttribute('data-pc');
  const st90 = await page.locator('#stResult').innerText();
  await page.click('#statSeg [data-r="7"]');
  const st7 = await page.locator('#stResult').innerText();
  const pnlRangeAfterStatsChange = await page.locator('#pnlCalSeg button.on').getAttribute('data-pc');
  await page.click('#statSeg [data-r="90"]');
  log('statystyki: zakresy', st90 !== st7, st90 + ' → ' + st7);
  log('statystyki: zakres ogólny nie zmienia kalendarza PnL',
    pnlRangeBeforeStatsChange === pnlRangeAfterStatsChange,
    `${pnlRangeBeforeStatsChange} → ${pnlRangeAfterStatsChange}`);

  // statystyki: kalendarz PnL ma własny zakres i nawigację
  const pnl7 = await page.evaluate(() => {
    document.querySelector('#pnlCalSeg [data-pc="7"]').click();
    return { cells: document.querySelectorAll('#pnlCal i').length, label: document.querySelector('#pnlCalLabel').textContent };
  });
  const pnlMonth = await page.evaluate(() => {
    document.querySelector('#pnlCalSeg [data-pc="month"]').click();
    return { cells: document.querySelectorAll('#pnlCal i').length, label: document.querySelector('#pnlCalLabel').textContent };
  });
  const pnlPrev = await page.evaluate(() => {
    document.querySelector('#stat [data-pcnav="-1"]').click();
    return document.querySelector('#pnlCalLabel').textContent;
  });
  const statsRangeAfterPnlChange = await page.locator('#statSeg button.on').getAttribute('data-r');
  log('statystyki: kalendarz PnL zmienia okres', pnl7.cells !== pnlMonth.cells && pnl7.label !== pnlMonth.label && pnlPrev !== pnlMonth.label, `${pnl7.cells} (${pnl7.label}) → ${pnlMonth.cells} (${pnlMonth.label}) → ${pnlPrev}`);
  log('statystyki: kalendarz PnL nie zmienia zakresu ogólnego',
    statsRangeAfterPnlChange === '90',
    statsRangeAfterPnlChange || '');
  await page.evaluate(() => document.querySelector('#stat [data-pcnav="today"]').click());

  // prop challenge: szablony, create, walidacja, XSS, alert, archiwum, reload, delete
  const dayKeyLocal = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const dStart = new Date(); dStart.setDate(dStart.getDate() - 20);
  const dEnd = new Date(); dEnd.setDate(dEnd.getDate() + 10);
  await page.click('.navg button[data-v="prop"]');
  assert.equal(await page.locator('.view.on').getAttribute('id'), 'prop');
  await page.click('#prop .head [data-prop-templates]');
  await page.waitForSelector('#gModal.on');
  const templateCount = await page.locator('#gModal [data-prop-template]').count();
  log('prop: katalog szablonów', templateCount >= 8, templateCount + ' szablonów');
  await page.evaluate(() => AV.closeModal());

  await page.click('#prop .head [data-prop-new]');
  await page.waitForSelector('#peSave');
  await page.selectOption('#peTemplate', 'ftmo-2step');
  await page.fill('#peName', 'E2E Prop 10K');
  await page.selectOption('#peAccount', 'WEEX');
  await page.fill('#peSize', '10000');
  await page.fill('#peStartBalance', '10000');
  await page.fill('#peTarget', '10');
  await page.fill('#peDaily', '5');
  await page.fill('#peMax', '10');
  await page.fill('#peStart', dayKeyLocal(dStart));
  await page.fill('#peEnd', dayKeyLocal(dEnd));
  await page.click('#peSave');
  await page.waitForTimeout(350);
  const createdProp = await page.evaluate(() => AV.state.prop.challenges.find((c) => c.name === 'E2E Prop 10K'));
  assert.ok(createdProp, 'challenge nie zapisany');
  assert.equal(createdProp.account, 'WEEX');
  assert.equal(createdProp.targetPct, 10);
  const kpiCount = await page.locator('#propKpis .card').count();
  const propText = await page.locator('#prop').innerText();
  log('prop: nowy challenge + 4 KPI', kpiCount === 4 && !propText.includes('WKRÓTCE'), kpiCount + ' KPI');

  // walidacja: pusta nazwa nie zapisuje
  await page.click('[data-prop-act="edit"]');
  await page.waitForSelector('#peSave');
  await page.fill('#peName', '');
  await page.click('#peSave');
  const validationVisible = await page.locator('#peError').isVisible();
  assert.ok(validationVisible, 'brak komunikatu walidacji');
  await page.fill('#peName', 'E2E Prop Edited');
  await page.click('#peSave');
  await page.waitForTimeout(300);
  log('prop: walidacja formularza', validationVisible, 'pusta nazwa zablokowana');

  // XSS w nazwie: literal w stanie, zero wykonania
  await page.click('[data-prop-act="edit"]');
  await page.fill('#peName', '<img src=x onerror="window.__xss=1">');
  await page.click('#peSave');
  await page.waitForTimeout(250);
  const xssState = await page.evaluate(() => ({
    literal: AV.state.prop.challenges.some((c) => c.name === '<img src=x onerror="window.__xss=1">'),
    fired: window.__xss === 1,
    injected: document.querySelectorAll('#prop img[src="x"]').length
  }));
  assert.ok(xssState.literal && !xssState.fired && xssState.injected === 0);
  await page.click('[data-prop-act="edit"]');
  await page.fill('#peName', 'E2E Prop Edited');
  await page.click('#peSave');
  await page.waitForTimeout(250);
  log('prop: XSS w nazwie', true, 'escaped');

  // alarm: tymczasowa strata ponad dzienny limit + banner na pulpicie
  await page.evaluate(() => {
    const c = AV.state.prop.challenges.find((x) => x.name === 'E2E Prop Edited');
    c.dailyLossPct = 0.01;
    AV.state.trades.push({ id: 'e2e_prop_tmp', date: new Date().toISOString(), result: -100, r: -1, account: 'WEEX', pair: 'E2E/USDT' });
    AV.propSetSelected(c.id);
    AV.renderProp();
    AV.renderPropDashboard();
  });
  await page.waitForTimeout(120);
  const alarmText = await page.locator('#propAlerts').innerText();
  const dashState = await page.evaluate(() => ({
    display: document.querySelector('#propDash')?.style.display,
    title: document.querySelector('#propDashTitle')?.textContent,
    desc: document.querySelector('#propDashDesc')?.textContent
  }));
  assert.ok(/dzienny limit/i.test(alarmText), 'brak alarmu dziennego limitu');
  assert.notEqual(dashState.display, 'none', 'banner prop nie został pokazany');
  log('prop: alarm limitu + pulpit', true, dashState.title + ' / ' + dashState.desc);
  await page.evaluate(() => {
    AV.state.trades = AV.state.trades.filter((t) => t.id !== 'e2e_prop_tmp');
    const c = AV.state.prop.challenges.find((x) => x.name === 'E2E Prop Edited');
    c.dailyLossPct = 5;
    AV.renderProp();
    AV.renderPropDashboard();
    AV.saveNow();
  });

  // archiwum: ukrycie, podgląd, przywrócenie
  await page.click('[data-prop-act="archive"]');
  await page.waitForTimeout(150);
  const archived = await page.evaluate(() => AV.state.prop.challenges.find((c) => c.name === 'E2E Prop Edited')?.archived);
  assert.equal(archived, true);
  await page.waitForTimeout(400);
  await page.locator('#propArchToggle').scrollIntoViewIfNeeded();
  await page.click('#propArchToggle');
  await page.waitForTimeout(150);
  const archivedOption = await page.locator('#propSelect option').allTextContents();
  assert.ok(archivedOption.some((t) => t.includes('E2E Prop Edited') && t.includes('archiwum')));
  await page.selectOption('#propSelect', { label: 'E2E Prop Edited · archiwum' });
  await page.click('[data-prop-act="archive"]');
  await page.waitForTimeout(120);
  const restored = await page.evaluate(() => AV.state.prop.challenges.find((c) => c.name === 'E2E Prop Edited')?.archived);
  assert.equal(restored, false);
  await page.waitForTimeout(200);
  await page.locator('#propArchToggle').scrollIntoViewIfNeeded();
  await page.click('#propArchToggle');
  log('prop: archiwum + przywrócenie', true);

  // empty state po zarchiwizowaniu wszystkiego (i powrót)
  const archivedBefore = await page.evaluate(() => AV.state.prop.challenges.map((c) => c.archived));
  await page.evaluate(() => {
    AV.state.prop.challenges.forEach((c) => { c.archived = true; });
    AV._propShowArchived = false;
    AV.renderProp();
  });
  const emptyVisible = await page.locator('#propEmpty').isVisible();
  assert.ok(emptyVisible, 'brak empty state');
  const archiveOnEmpty = await page.locator('#propArchToggle').isVisible();
  assert.ok(archiveOnEmpty, 'brak dostępu do archiwum w empty state');
  await page.click('#propArchToggle');
  await page.waitForTimeout(100);
  const archivedView = await page.locator('#propBody').isVisible();
  assert.ok(archivedView, 'archiwum nie otwiera się z empty state');
  await page.evaluate((before) => {
    AV.state.prop.challenges.forEach((c, i) => { c.archived = before[i]; });
    AV._propShowArchived = false;
    AV.renderProp();
  }, archivedBefore);
  log('prop: empty state + archiwum', emptyVisible && archiveOnEmpty && archivedView);

  // trwałość: zapis do MySQL i reload
  await page.waitForTimeout(900);
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);
  await page.waitForFunction(() => window.AV?.api?.authenticated);
  const persisted = await page.evaluate(() => AV.state.prop.challenges.some((c) => c.name === 'E2E Prop Edited' && !c.archived));
  assert.ok(persisted, 'challenge nie przeżył reloadu');
  log('prop: przeżył reload (MySQL)', persisted);

  // usunięcie challenge'u przez UI
  await page.click('.navg button[data-v="prop"]');
  await page.selectOption('#propSelect', { label: 'E2E Prop Edited' });
  await page.click('[data-prop-act="delete"]');
  await page.waitForSelector('#gModal.on');
  await page.click('#gConfirmOk');
  await page.waitForTimeout(200);
  const deleted = await page.evaluate(() => !AV.state.prop.challenges.some((c) => c.name === 'E2E Prop Edited'));
  assert.ok(deleted, 'challenge nie usunięty');
  log('prop: usuwanie challenge', deleted);

  // mentor: karta MCP nadal dostępna po zapisach
  await page.click('.navg button[data-v="mentor"]');
  await page.waitForSelector('.mcp-card');
  assert.equal((await page.locator('#mcpUrl').innerText()).trim(), 'https://mcp.alphavault.pl/mcp');
  assert.equal(await page.locator('#msgs').count(), 0);
  log('mentor: karta MCP po zapisach', true);

  // krok mentora prowadzi do planu dnia
  await page.evaluate(() => { AV.renderDashMentor({ closed: [] }); AV.showView('pulpit'); });
  await page.click('#dashMentor .step');
  const planFromStep = await page.evaluate(() => ({ on: document.querySelector('#plan').classList.contains('on'), hash: location.hash }));
  log('mentor: krok plan dnia → zakładka', planFromStep.on && planFromStep.hash === '#/plan', JSON.stringify(planFromStep));

  // pulpit: podgląd salda portfela i skrót do ustawień balansu
  await page.evaluate(() => AV.showView('pulpit'));
  await page.evaluate(() => { document.querySelector('#dashBal').open = false; });
  const dashBalClosed = await page.locator('#dashBal').evaluate((el) => !el.open);
  await page.click('#dashBal summary');
  const dashAccounts = await page.evaluate(() => ({
    open: document.querySelector('#dashBal').open,
    rows: document.querySelectorAll('#dashAccountList .dash-account').length,
    expected: AV.state.accounts.length,
    view: AV.currentView
  }));
  log('pulpit: rozwijany podgląd kont', dashBalClosed && dashAccounts.open && dashAccounts.rows > 0 && dashAccounts.rows === dashAccounts.expected && dashAccounts.view === 'pulpit', JSON.stringify(dashAccounts));
  const dashBal = await page.evaluate(() => ({
    val: (document.querySelector('#dashBalVal')?.textContent || '').replace(/\s+/g, ' ').trim(),
    sub: document.querySelector('#dashBalSub')?.textContent || ''
  }));
  log('pulpit: podgląd salda portfela', dashBal.val.length > 0 && dashBal.sub.includes('główne'), JSON.stringify(dashBal));
  const emptyBalanceGrowth = await page.evaluate(() => {
    const account = AV.state.accounts.find((item) => item.include);
    const beforeBalance = account?.balance;
    const beforeStart = account?.start;
    if (account) account.balance = '';
    AV.renderDashBalance();
    const row = Array.from(document.querySelectorAll('#dashAccountList .dash-account'))
      .find((item) => item.querySelector('b')?.textContent.trim() === account?.name);
    const result = {
      balance: row?.querySelector('.dash-account-values b')?.textContent.trim() || '',
      details: row?.querySelector('.dash-account-values small')?.textContent || '',
      total: document.querySelector('#dashBalVal')?.textContent.trim() || '',
      summary: document.querySelector('#dashBalSub')?.textContent || ''
    };
    if (account) {
      account.balance = beforeBalance;
      account.start = '';
      AV.renderDashBalance();
      result.missingStartSummary = document.querySelector('#dashBalSub')?.textContent || '';
      account.start = beforeStart;
    }
    AV.renderDashBalance();
    return result;
  });
  log('pulpit: puste saldo nie pokazuje fałszywego −100%',
    emptyBalanceGrowth.balance === '' && !emptyBalanceGrowth.details.includes('100%') &&
      emptyBalanceGrowth.total === '' && !emptyBalanceGrowth.summary.includes('od startu') &&
      !(emptyBalanceGrowth.missingStartSummary || '').includes('od startu'),
    JSON.stringify(emptyBalanceGrowth));

  await page.click('#dashBal [data-go="ust"]');
  const balGo = await page.evaluate(() => ({ view: AV.currentView, hash: location.hash }));
  log('pulpit: saldo prowadzi do Konto i zasady', balGo.view === 'ust' && balGo.hash === '#/konto', JSON.stringify(balGo));
  const startCapitalHeader = ((await page.locator('#ust .accounts-table th.acc-start').textContent()) || '').trim();
  log('konto: nagłówek Kapitał startowy', startCapitalHeader === 'Kapitał startowy', startCapitalHeader);

  const balEdit = await page.evaluate(async () => {
    const input = document.querySelector('[data-acc-balance="acc1"]');
    if (!input) return { ok: false };
    input.value = '2000';
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 300));
    return { value: AV.state.accounts.find((a) => a.id === 'acc1').balance, total: AV.totalCapital(AV.state) };
  });
  log('konto: edycja salda zapisuje portfel', balEdit.value === 2000 && balEdit.total >= 2000, JSON.stringify(balEdit));

  // akademia
  await page.click('.navg button[data-v="akad"]');
  const liveJoinState = await page.evaluate(() => ({
    href: document.querySelector('#liveJoin')?.getAttribute('href') || '',
    hidden: getComputedStyle(document.querySelector('#liveJoin')).display === 'none',
    note: document.querySelector('#liveJoinNote')?.textContent || ''
  }));
  log('akademia: „Dołącz” dopiero 15 min przed startem', liveJoinState.hidden && /15 minut przed startem/.test(liveJoinState.note), JSON.stringify(liveJoinState).slice(0, 180));

  const recording = await page.evaluate(() => {
    const rec = AV.state.recordings[0];
    AV.openRecording(rec.id);
    const modal = document.querySelector('.modal.on');
    const out = {
      hasInput: !!modal?.querySelector('#recUrlInput'),
      fake: (modal?.innerText || '').includes('Odtwarzanie w wersji produkcyjnej')
    };
    AV.closeModal();
    return out;
  });
  log('nagrania: brak linku → pole na link, bez fałszywego playera', recording.hasInput && !recording.fake, JSON.stringify(recording));

  await page.click('#recCats [data-rcat="Poranna odprawa"]');
  const recFiltered = await page.locator('#recList .rec').count();
  await page.click('#recList .rec');
  await page.fill('#recUrlInput', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
  await page.click('#recUrlSave');
  await page.waitForTimeout(250);
  const recPlay = await page.evaluate(() => ({
    iframe: !!document.querySelector('.modal.on iframe'),
    url: (AV.state.recordings.find((r) => r.id === 'rec1') || {}).url || ''
  }));
  await page.evaluate(() => AV.closeModal());
  log('nagrania: kategorie i odtwarzanie z linku', recFiltered === 1 && recPlay.iframe && /youtube/.test(recPlay.url), `${recFiltered} w kategorii, iframe=${recPlay.iframe}`);

  const acadSchedule = await page.evaluate(() => {
    document.querySelector('#acadSeg [data-s="month"]').click();
    const month = { cells: document.querySelectorAll('#acad .mcell').length, range: document.querySelector('#acadRange').textContent, cls: document.querySelector('#acad').className };
    document.querySelector('#acadSeg [data-s="week"]').click();
    const week = { days: document.querySelectorAll('#acad .aday').length, range: document.querySelector('#acadRange').textContent };
    return { month, week };
  });
  log('akademia: harmonogram tydzień/miesiąc', acadSchedule.month.cells >= 28 && acadSchedule.month.cls === 'mcal' && acadSchedule.week.days === 7, JSON.stringify(acadSchedule));
  const acadNext = await page.evaluate(() => {
    const before = AV.ui.acadOffset;
    document.querySelector('#akad [data-aw="1"]').click();
    const next = AV.ui.acadOffset;
    document.querySelector('#akad [data-aw="-1"]').click();
    return { before, next, restored: AV.ui.acadOffset, label: document.querySelector('#akad [data-aw="1"]').textContent.trim() };
  });
  log('akademia: klik Następny → przesuwa i przywraca tydzień', acadNext.next === acadNext.before + 1 && acadNext.restored === acadNext.before && acadNext.label === 'Następny →', JSON.stringify(acadNext));

  const remCustom = await page.evaluate(() => {
    const sel = document.querySelector('#liveRemMin');
    sel.value = '30';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    return { value: AV.state.settings.liveReminderMinutes, label: document.querySelector('#liveRem').textContent };
  });
  log('akademia: własny czas przypomnienia', remCustom.value === 30 && /30 min/.test(remCustom.label), JSON.stringify(remCustom));

  const materials = await page.evaluate(() => {
    AV.openGlossary();
    const total = document.querySelectorAll('#glossList .kv').length;
    const search = document.querySelector('#glossSearch');
    search.value = 'staking';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    const filtered = document.querySelectorAll('#glossList .kv').length;
    AV.closeModal();
    AV.openAlphaTest();
    AV.ALPHA_TEST.forEach((x, i) => {
      const el = document.querySelector('input[name="at' + i + '"][value="' + x.ok + '"]');
      if (el) el.checked = true;
    });
    document.querySelector('#alphaTestSubmit').click();
    const score = AV.state.meta.alphaTestScore;
    const result = document.querySelector('#alphaTestResult').textContent;
    AV.closeModal();
    AV.openGoldenRules();
    const rules = document.querySelectorAll('.modal.on .row').length;
    AV.closeModal();
    return { total, filtered, score, result, rules, glossary: AV.GLOSSARY.length };
  });
  log('akademia: słownik z wyszukiwaniem', materials.total === materials.glossary && materials.filtered === 1, `${materials.total} pojęć, filtr=${materials.filtered}`);
  log('akademia: Alpha Testy zapisują wynik', materials.score === 6 && /6 \/ 6/.test(materials.result), JSON.stringify({ score: materials.score, result: materials.result }));
  log('akademia: złote zasady bezpieczeństwa', materials.rules === 8, materials.rules + ' zasad');

  const emptyJournal = await page.evaluate(() => {
    const backup = AV.state.trades;
    AV.state.trades = [];
    AV.renderJournal();
    const text = document.querySelector('#jEmpty')?.textContent || '';
    AV.state.trades = backup;
    AV.renderJournal();
    return text;
  });
  log('dziennik: pusty stan z CTA', emptyJournal.includes('Dodaj transakcję') && emptyJournal.includes('Giełdy i API') && !emptyJournal.includes('spełniających filtry'), emptyJournal);

  // kalendarz
  await page.click('.navg button[data-v="kal"]');
  const calWeek0 = await page.locator('#calRange').innerText();
  const ev0 = await page.locator('#evFull .ev').count();
  const nextWeekLabel = (await page.locator('#calSeg [data-w="1"]').innerText()).trim();
  await page.click('#calSeg [data-w="1"]');
  const ev1 = await page.locator('#evFull .ev').count();
  await page.click('#calSeg [data-w="2"]');
  const ev2 = await page.locator('#evFull .ev').count();
  const calWeek2 = await page.locator('#calRange').innerText();
  await page.click('#calSeg [data-w="3"]');
  const calWeek3 = await page.locator('#calRange').innerText();
  const selectedWeek3 = (await page.locator('#calSeg [data-w="3"]').getAttribute('class') || '').includes('on');
  await page.click('#calSeg [data-w="0"]');
  const calBack = await page.locator('#calRange').innerText();
  const widget = await page.locator('#mwList .mev').count();
  log('kalendarz: strzałka przy Następny', nextWeekLabel === 'Następny →', nextWeekLabel);
  log('kalendarz: tygodnie', ev1 > 0, `${ev0} → ${ev1}`);
  log('kalendarz: kolejne tygodnie i powrót', ev2 > 0 && calWeek2 !== calWeek0 && calBack === calWeek0, `${calWeek0} → ${calWeek2} → ${calBack}`);
  log('kalendarz: tydzień +3', selectedWeek3 && calWeek3 !== calWeek2 && calBack === calWeek0, `${calWeek2} → ${calWeek3} → ${calBack}`);
  log('makro: widget z API', widget > 0, `${widget} wydarzeń`);
  const macroSyncNotice = await page.evaluate(async () => {
    const previousSync = AV.api.macroSync;
    const previousError = AV.api.macroError;
    const previousApiFetch = AV.apiFetch;
    const previousEvents = JSON.stringify(AV.api.macroEvents);
    AV.api.macroSync = { status: 'partial', stale: true };
    AV.api.macroError = null;
    AV.renderCalendar();
    const partial = document.querySelector('#macroSyncNotice')?.textContent || '';
    AV.apiFetch = async () => ({ ok: false, status: 503 });
    await AV.loadMacroEvents();
    const apiFailure = document.querySelector('#macroSyncNotice')?.textContent || '';
    const retainedEvents = JSON.stringify(AV.api.macroEvents) === previousEvents;
    AV.api.macroError = null;
    AV.api.macroSync = { status: 'error', stale: false, lastError: 'ForexFactory failed' };
    AV.renderCalendar();
    const freshError = document.querySelector('#macroSyncNotice')?.textContent || '';
    AV.api.macroSync = previousSync;
    AV.api.macroError = previousError;
    AV.apiFetch = previousApiFetch;
    AV.renderCalendar();
    return {
      partial, apiFailure, freshError, retainedEvents,
      eventCount: document.querySelectorAll('#evFull .ev').length,
      noticeSpan: document.querySelector('#macroSyncNotice')?.style.gridColumn || ''
    };
  });
  log(
    'makro: sygnał partial/API bez czyszczenia ostatnich wydarzeń',
    macroSyncNotice.partial.includes('częściowa') &&
      macroSyncNotice.apiFailure.includes('ostatnie dobre wydarzenia') &&
      macroSyncNotice.freshError.includes('nie powiodła się') &&
      macroSyncNotice.retainedEvents &&
      macroSyncNotice.noticeSpan === '1 / -1' &&
      macroSyncNotice.eventCount === ev0,
    JSON.stringify(macroSyncNotice)
  );

  // ciało: check-in
  await page.click('.navg button[data-v="cialo"]');
  await page.locator('#checkMood .chip', { hasText: 'Spokój' }).click();
  await page.locator('#checkEnergy .chip', { hasText: '5' }).click();
  await page.click('#saveCheck');
  const savedCheck = await page.evaluate(() => {
    const key = AV.dateKey(new Date());
    const c = (AV.state.checkins || []).find((x) => x.date === key);
    return c ? { mood: c.mood, energy: c.energy, score: c.score } : null;
  });
  log('check-in: zapis', !!savedCheck && savedCheck.mood === 'Spokój' && savedCheck.energy === 5, JSON.stringify(savedCheck));

  // ustawienia: profil
  await page.click('.navg button[data-v="ust"]');
  await page.fill('#nameIn', 'E2E Tester');
  await page.waitForTimeout(400);
  const sideName = await page.locator('#sideName').innerText();
  log('profil: zapis imienia', sideName === 'E2E Tester', sideName);
  const planDates = await page.evaluate(() => {
    const prev = AV.state.subscription.until;
    const expected = new Date(prev).toLocaleDateString('pl-PL');
    const read = () => ({
      side: document.querySelector('#sidePlan').textContent.trim(),
      row: document.querySelector('#planUntilRow').style.display,
      until: document.querySelector('#planValidUntil').textContent.trim()
    });
    AV.state.subscription.until = null;
    AV.renderUser();
    const none = read();
    AV.state.subscription.until = prev;
    AV.renderUser();
    return { none, restored: read(), expected };
  });
  log('profil: bez daty bez adnotacji', planDates.none.side === 'Alpha + Akademia' && planDates.none.row === 'none', JSON.stringify(planDates.none));
  log('profil: data z API w miejscu daty', planDates.restored.until === planDates.expected && planDates.restored.side.endsWith(planDates.expected), planDates.restored.until + ' = ' + planDates.expected);
  const removedFields = await page.evaluate(() => ({
    motto: !!document.querySelector('#mottoIn'),
    style: !!document.querySelector('#styleChips'),
    greeting: !!document.querySelector('[data-setting="greeting"]'),
    gender: !!document.querySelector('#gender'),
    greet: !!document.querySelector('#greet'),
    badge: document.querySelector('#profileBadge').textContent.trim()
  }));
  log('personalizacja: usunięte pola', !removedFields.motto && !removedFields.style && !removedFields.greeting && !removedFields.gender && !removedFields.greet && removedFields.badge === 'Krypto', JSON.stringify(removedFields));
  const h1 = await page.locator('#pulpit .head h1').innerText();
  log('pulpit: nazwa bez powitania', h1.trim() === 'E2E Tester.', h1);

  // nowa transakcja: obejście limitu + zapis + zamknięcie
  await page.click('.navg button[data-v="trans"]');
  await page.click('#fRes [data-f="win"]');
  await page.click('#fGrade [data-f="A"]');
  const filterNeedle = 'E2E filtr przed zapisem ' + Date.now();
  await page.fill('#jSearch', filterNeedle);
  await page.waitForFunction((query) => AV.ui.jSearch === query && AV.filteredTrades().length === 0, filterNeedle, { timeout: 5000 });
  const filteredBeforeSave = await page.evaluate(() => ({
    result: AV.ui.jFilter,
    grade: AV.ui.jGrade,
    search: AV.ui.jSearch,
    rows: document.querySelectorAll('#tbody tr').length
  }));
  log('dziennik: poprzednie filtry przed nowym wpisem', filteredBeforeSave.result === 'win' && filteredBeforeSave.grade === 'A' && filteredBeforeSave.search === filterNeedle && filteredBeforeSave.rows === 0, JSON.stringify(filteredBeforeSave));
  await page.click('.navg button[data-v="nowa"]');
  // Fixture demo ma stałą datę; wymuś 2 straty dziś, żeby tryb ochronny był deterministyczny.
  await page.evaluate(() => {
    const today = AV.dateKey(new Date());
    const mk = (id) => ({
      id, date: new Date().toISOString(), pair: 'E2E/LOCK', dir: 'l', entry: '100', exit: '99', sl: '98', tp: '104',
      size: '1', result: -10, r: -1, grade: 'B', checklist: 6, checkTotal: 7, errors: [], emotion: 'Spokój',
      note: '', mentor: '', setup: 'E2E', session: 'Londyn', mae: 0, mfe: 0, durationMin: 20, fee: 0,
      plan: true, source: 'E2E', screens: {}
    });
    AV.state.settings.safetyEnabled = true;
    AV.state.trades = AV.state.trades
      .filter((t) => AV.dateKey(new Date(t.date)) !== today)
      .concat([mk('e2e_lock_a'), mk('e2e_lock_b')]);
    AV.renderLock();
  });
  await page.evaluate(() => document.getElementById('pastTrade').click());
  const emptyForm = await page.evaluate(() => ({
    pair: document.querySelector('#ntPair')?.value || '',
    entry: document.querySelector('#ntEntry')?.value || '',
    size: document.querySelector('#ntSize')?.value || '',
    sl: document.querySelector('#ntSl')?.value || '',
    tp: document.querySelector('#ntTp')?.value || '',
    note: document.querySelector('#ntNote')?.value || '',
    checklist: document.querySelector('#clN')?.textContent.trim() || '',
    grades: document.querySelectorAll('.gp.on').length,
    risk: document.querySelector('#ntRisk')?.textContent || ''
  }));
  log('nowa transakcja: formularz pusty', !emptyForm.pair && !emptyForm.entry && !emptyForm.size && !emptyForm.sl && !emptyForm.tp && !emptyForm.note, JSON.stringify(emptyForm));
  log('nowa transakcja: checklista 0 i brak oceny', emptyForm.checklist === '0/7' && emptyForm.grades === 0, emptyForm.checklist + ' / ' + emptyForm.grades);
  log('nowa transakcja: limit i ryzyko osobno', emptyForm.risk.includes('Limit') && emptyForm.risk.includes('Ryzyko'), emptyForm.risk.slice(0, 100));

  await page.evaluate(() => document.querySelector('#clList [data-cl="SL przed wejściem"]')?.click());
  const manualSlChecklist = await page.evaluate(() => {
    const checkedAfterClick = AV.ui.ntChecks['SL przed wejściem'] === true;
    AV.renderChecklist();
    return {
      checkedAfterClick,
      checkedAfterRerender: AV.ui.ntChecks['SL przed wejściem'] === true,
      checkedInList: document.querySelector('#clList [data-cl="SL przed wejściem"]')?.classList.contains('on') || false,
      hasAutoLabel: !!document.querySelector('#clList [data-cl="SL przed wejściem"] .auto')
    };
  });
  log(
    'nowa transakcja: punkt SL checklisty jest dobrowolny i ręcznie edytowalny',
    manualSlChecklist.checkedAfterClick && manualSlChecklist.checkedAfterRerender &&
      manualSlChecklist.checkedInList && !manualSlChecklist.hasAutoLabel,
    JSON.stringify(manualSlChecklist)
  );
  await page.evaluate(() => document.querySelector('#clList [data-cl="SL przed wejściem"]')?.click());

  const tradeNote = 'E2E regression: niska relacja RR i pusta checklista ' + Date.now();
  await page.evaluate(async () => {
    // The deferred PUT from #pastTrade (state.js schedulePush, 300 ms) would
    // otherwise land between the mutations below and the assertions, reloading
    // AV.state from the server (state.js 409 handler) and discarding them.
    await AV.waitForPushIdle();
    clearTimeout(AV._pushTimer);
    AV.state.settings.checklist = ['Poziom z planu', 'Spokojna głowa'];
    AV.ui.ntChecks = {};
    AV.renderChecklist();
  });
  await page.evaluate(() => document.querySelector('#ntDir [data-d="l"]')?.click());
  await page.fill('#ntPair', 'ETH/USDT');
  await page.waitForTimeout(250);
  const initiallyChecked = await page.evaluate(() =>
    !!document.querySelector('#clList [data-cl="Poziom z planu"]')?.classList.contains('on'));
  if (!initiallyChecked) await page.evaluate(() => document.querySelector('#clList [data-cl="Poziom z planu"]')?.click());
  await page.evaluate(() => document.querySelector('#clList [data-cl="Poziom z planu"]')?.click());
  await page.fill('#ntNote', tradeNote);
  await page.waitForTimeout(250);
  const manualPlanLevel = await page.evaluate(() => ({
      matches: AV.tradePlanMatch(AV.state, { date: new Date().toISOString(), pair: 'ETH/USDT', dir: 'l' }),
    checked: document.querySelector('#clList [data-cl="Poziom z planu"]')?.classList.contains('on') || false,
    count: document.querySelector('#clN')?.textContent.trim(),
    warning: document.querySelector('#clWarn')?.textContent.trim() || '',
    checkedCount: Object.values(AV.ui.ntChecks || {}).filter(Boolean).length,
    total: Object.keys(AV.ui.ntChecks || {}).length
  }));
    log('nowa transakcja: niejednoznaczne plany nie tworzą zgodności', !manualPlanLevel.matches && !manualPlanLevel.checked && manualPlanLevel.count === '0/2' && manualPlanLevel.checkedCount === 0, JSON.stringify(manualPlanLevel));
   log('nowa transakcja: checklista dobrowolna przy zerze', manualPlanLevel.warning === 'Checklista jest dobrowolna; zapis nie wymaga minimum. Zaznaczono 0 z 2 punktów.', manualPlanLevel.warning);
   await page.evaluate(() => document.querySelector('#clList [data-cl="Spokojna głowa"]')?.click());

  // Keep the note value stable across the re-render triggered by field edits.
  await page.evaluate((note) => {
    const noteEl = document.getElementById('ntNote');
    if (noteEl) noteEl.value = note;
  }, tradeNote);
  const beforeTrades = await page.evaluate(() => AV.state.trades.length);
  await page.fill('#ntEntry', '100');
  await page.fill('#ntTp', '100.5');
  await page.fill('#ntSize', '1');
  await page.click('#saveTrade');
  const missingSl = await page.evaluate((before) => ({
    unchanged: AV.state.trades.length === before,
    message: document.querySelector('#toast')?.textContent || ''
  }), beforeTrades);
  log('nowa transakcja: SL wymagany', missingSl.unchanged && /stop loss/i.test(missingSl.message), JSON.stringify(missingSl));

  await page.fill('#ntSl', '99');
  await page.click('#saveTrade');
  await page.evaluate(() => clearTimeout(AV._pushTimer));
  const afterTrades = await page.evaluate(() => AV.state.trades.length);
  const addedId = await page.evaluate((note) => AV.state.trades.find((t) => t.note === note)?.id, tradeNote);
  const journalImmediate = await page.evaluate((id) => {
    const row = Array.from(document.querySelectorAll('#tbody tr')).find((tr) => tr.dataset.id === id);
    return {
      view: AV.currentView,
      visible: !!row,
      row: row?.innerText || '',
      result: AV.ui.jFilter,
      grade: AV.ui.jGrade,
      search: AV.ui.jSearch,
      searchInput: document.querySelector('#jSearch')?.value || ''
    };
  }, addedId);
  log('nowa transakcja: natychmiast widoczna w dzienniku po filtrach',
    afterTrades === beforeTrades + 1 && journalImmediate.view === 'trans' && journalImmediate.visible &&
      journalImmediate.row.includes('ETH/USDT') && journalImmediate.result === 'all' &&
      journalImmediate.grade === 'all' && journalImmediate.search === '' && journalImmediate.searchInput === '',
    JSON.stringify({ afterTrades, beforeTrades, journalImmediate }));
  await page.waitForTimeout(500);
  log('nowa transakcja: zapis', afterTrades === beforeTrades + 1, beforeTrades + ' → ' + afterTrades);
  const addedTrade = await page.evaluate((id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    return t ? {
      risk: t.risk, riskPct: t.riskPct, mae: t.mae, mfe: t.mfe, sl: t.sl, checklist: t.checklist,
      checkTotal: t.checkTotal, checklistItems: t.checklistItems || [], plan: t.plan, account: t.account,
      rr: Math.abs(AV.pnum(t.tp) - AV.pnum(t.entry)) / Math.abs(AV.pnum(t.entry) - AV.pnum(t.sl))
    } : null;
  }, addedId);
  log('nowa transakcja: ryzyko zapisane, MAE/MFE puste', !!addedTrade && addedTrade.risk === 1 && addedTrade.mae === null && addedTrade.mfe === null, JSON.stringify(addedTrade));
  const missingMetricDisplay = await page.evaluate((id) => {
    AV.openTrade(id);
    const metric = (label) => {
      const card = Array.from(document.querySelectorAll('#tmBody .card.flat'))
        .find((item) => item.querySelector('.lbl')?.textContent.trim() === label);
      return card?.querySelector('.mono')?.textContent.trim() ?? null;
    };
    const values = { mae: metric('MAE'), mfe: metric('MFE') };
    document.querySelector('#mc')?.click();
    return values;
  }, addedId);
  log('transakcja: puste MAE/MFE nie wyglądają jak 0.0R',
    missingMetricDisplay.mae === '' && missingMetricDisplay.mfe === '',
    JSON.stringify(missingMetricDisplay));
  const earlyExitStats = await page.evaluate(() => {
    const el = document.querySelector('#errCost');
    const originalHtml = el?.innerHTML;
    const now = new Date().toISOString();
    const stats = AV.computeStats([
      { date: now, result: 10, r: 1, mfe: null, errors: ['Za wczesne wyjście'], grade: 'B', checklist: 1, checkTotal: 2 },
      { date: now, result: 10, r: 1, mfe: 2, errors: ['Za wczesne wyjście'], grade: 'B', checklist: 1, checkTotal: 2 }
    ], AV.state);
    AV.renderErrorCost(stats);
    const rendered = el?.textContent || '';
    if (el && originalHtml !== undefined) el.innerHTML = originalHtml;
    const metricStats = AV.computeStats([
      { date: now, result: 10, r: 1, mae: '', mfe: ' ', errors: [], checklist: null, checkTotal: null },
      { date: now, result: 20, r: 2, mae: 0, mfe: 3, errors: [], checklist: null, checkTotal: null }
    ], AV.state);
    return {
      unpriced: stats.unpricedEarlyExits, errors: stats.errors, rendered,
      measuredAverage: [metricStats.avgMaeWin, metricStats.avgMfeWin, metricStats.avgExitR, metricStats.maeSampleCount]
    };
  });
  log('statystyki: brak MFE nie jest liczony jako zero przy wczesnym wyjściu',
    earlyExitStats.unpriced === 1 && earlyExitStats.errors.length === 1 &&
      earlyExitStats.errors[0].n === 1 && Math.abs(earlyExitStats.errors[0].cost + 10) < 0.01 &&
      earlyExitStats.rendered.includes('bez pomiaru MFE lub R') &&
      JSON.stringify(earlyExitStats.measuredAverage) === JSON.stringify([0, 3, 2, 1]),
    JSON.stringify(earlyExitStats));
  const csvMetricImports = await page.evaluate(() => {
    const unknown = AV.mapCsvTrades([
      ['symbol', 'date', 'pnl', 'fee'],
      ['BTCUSDT', '2026-11-01T12:00:00Z', ' ', '']
    ]);
    const measured = AV.mapCsvTrades([
      ['symbol', 'side', 'date', 'pnl', 'fee', 'mae', 'mfe'],
      ['BTCUSDT', 'buy', '2026-11-01T12:00:00Z', '0', '0', '0', '1,25']
    ])[0];
    const derived = AV.mapCsvTrades([
      ['symbol', 'side', 'date', 'entry', 'exit', 'qty', 'fee'],
      ['ETHUSDT', 'buy', '2026-11-01T12:00:00Z', '100', '101', '2', '']
    ])[0];
    const unknownDirection = AV.mapCsvTrades([
      ['symbol', 'date', 'entry', 'exit', 'qty'],
      ['ETHUSDT', '2026-11-01T12:00:00Z', '100', '101', '2']
    ]);
    const invalidDates = AV.mapCsvTrades([
      ['symbol', 'side', 'date', 'pnl'],
      ['ETHUSDT', 'buy', '2026-02-30T12:00:00Z', '5'],
      ['ETHUSDT', 'buy', '31.02.2026', '5']
    ]);
    return {
      unknownRows: unknown.length,
      measured: [measured.result, measured.mae, measured.mfe, measured.durationMin, measured.fee, measured.checklist, measured.grade],
      derived: [derived.result, derived.mae, derived.mfe, derived.durationMin, derived.fee],
      unknownDirectionRows: unknownDirection.length,
      invalidDateRows: invalidDates.length
    };
  });
  log('CSV: brak pomiarów zostaje pusty, dostępne MAE/MFE i PnL 0 są zachowane',
    csvMetricImports.unknownRows === 0 &&
      JSON.stringify(csvMetricImports.measured) === JSON.stringify([0, 0, 1.25, null, 0, null, null]) &&
      JSON.stringify(csvMetricImports.derived) === JSON.stringify([2, null, null, null, null]) &&
      csvMetricImports.unknownDirectionRows === 0 && csvMetricImports.invalidDateRows === 0,
    JSON.stringify(csvMetricImports));
   log('nowa transakcja: elastyczne RR i brak minimum checklisty',
     !!addedTrade && addedTrade.sl !== 'brak' && addedTrade.checklist === 1 && addedTrade.checkTotal === 2 &&
       JSON.stringify(addedTrade.checklistItems) === JSON.stringify(['Spokojna głowa']) &&
        !addedTrade.plan && Math.abs(addedTrade.rr - 0.5) < 0.01,
     JSON.stringify(addedTrade));
   await page.evaluate((id) => AV.editTrade(id), addedId);
   const editedChecklist = await page.evaluate(() => ({
     selected: document.querySelector('#clList [data-cl="Spokojna głowa"]')?.classList.contains('on') || false,
     planLevel: document.querySelector('#clList [data-cl="Poziom z planu"]')?.classList.contains('on') || false,
     saved: AV.state.trades.find((t) => t.note === document.querySelector('#ntNote')?.value)?.checklistItems || []
   }));
    log('nowa transakcja: edycja zachowuje ręczny wybór checklisty',
      editedChecklist.selected && !editedChecklist.planLevel && editedChecklist.saved.includes('Spokojna głowa'),
      JSON.stringify(editedChecklist));
    await page.click('#cancelEdit');
    const legacyChecklist = await page.evaluate(async () => {
      const id = 'ui-legacy-checklist-' + Date.now();
      const archivedId = id + '-archived-point';
      const draftKey = AV.draftKey();
      const priorDraft = AV.safeGet(draftKey);
      const priorChecklist = AV.state.settings.checklist;
      const originalSaveNow = AV.saveNow;
      const originalRenderAll = AV.renderAll;
       const trade = {
        id, date: '2025-04-03T12:00:00.000Z', pair: 'LEGACY/USDT', dir: 'l',
        entry: '100', sl: '99', tp: '102', size: '1', exit: 'otwarta',
        result: null, r: null, grade: 'B', checklist: 6, checkTotal: 10,
        note: 'Historyczna transakcja', setup: 'Bez setupu', emotion: 'Spokój',
        errors: [], risk: 1, riskPct: 0.01, plan: false, mae: null, mfe: null,
         durationMin: 0, fee: 0, source: 'Ręcznie', account: 'Konto główne', screens: {}
       };
      const noTotalTrade = { ...trade, id: id + '-no-total', pair: 'LEGACY/DEFAULT/USDT' };
      const archivedPointTrade = {
        ...trade, id: archivedId, pair: 'LEGACY/ARCHIVE/USDT', checklist: 1, checkTotal: 2,
        checklistItems: ['Usunięta zasada']
      };
       delete noTotalTrade.checkTotal;
      AV.saveNow = () => {};
      AV.renderAll = () => {};
      AV.safeSet(draftKey, JSON.stringify({
        pair: 'DRAFT/SHOULD-NOT-LEAK', note: 'Nie nadpisuj szkicu',
        entry: '999', size: '9', sl: '900', tp: '1200'
      }));
        AV.state.trades.push(trade, noTotalTrade, archivedPointTrade);
      AV.state.settings.checklist = ['Aktualna zasada', 'Poziom z planu'];
      const result = {};
      result.id = id;
      try {
        AV.editTrade(id);
         result.open = {
          editId: AV.ui.editId,
          pair: document.querySelector('#ntPair')?.value,
          entry: document.querySelector('#ntEntry')?.value,
          size: document.querySelector('#ntSize')?.value,
          sl: document.querySelector('#ntSl')?.value,
          tp: document.querySelector('#ntTp')?.value,
          note: document.querySelector('#ntNote')?.value,
          direction: document.querySelector('#ntDir .chip.on')?.dataset.d,
          grade: document.querySelector('#gp .gp.on')?.dataset.g || '',
          summary: document.querySelector('#clN')?.textContent.trim(),
          selected: document.querySelectorAll('#clList .cl.on').length,
           warning: document.querySelector('#clWarn')?.textContent || ''
         };
         AV.editTrade(noTotalTrade.id);
         result.noTotalSummary = document.querySelector('#clN')?.textContent.trim() || '';
         AV.editTrade(id);
        const noteInput = document.querySelector('#ntNote');
        noteInput.value = 'Historia: edytowano notatkę';
        noteInput.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 250));
        result.inputGrade = document.querySelector('#gp .gp.on')?.dataset.g || '';
        AV.saveTrade();
        const afterUntouchedSave = AV.state.trades.find((row) => row.id === id);
        result.untouchedSave = {
          note: afterUntouchedSave?.note,
          grade: afterUntouchedSave?.grade,
          checklist: afterUntouchedSave?.checklist,
          checkTotal: afterUntouchedSave?.checkTotal,
          hasItems: Object.prototype.hasOwnProperty.call(afterUntouchedSave || {}, 'checklistItems'),
          draft: JSON.parse(AV.safeGet(draftKey) || '{}')
        };
        AV.editTrade(id);
        document.querySelector('#clList [data-cl="Poziom z planu"]')?.click();
        result.manualSelection = {
          count: document.querySelector('#clN')?.textContent.trim(),
          selected: Array.from(document.querySelectorAll('#clList .cl.on')).map((row) => row.dataset.cl)
        };
        AV.saveTrade();
        const afterManualSave = AV.state.trades.find((row) => row.id === id);
        result.savedSelection = {
          checklist: afterManualSave?.checklist,
          checkTotal: afterManualSave?.checkTotal,
          items: afterManualSave?.checklistItems
        };
        AV.editTrade(id);
        result.reopenedSelection = Array.from(document.querySelectorAll('#clList .cl.on')).map((row) => row.dataset.cl);
        const selectionNote = document.querySelector('#ntNote');
        selectionNote.value = 'Historia: zachowana checklista';
        selectionNote.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 250));
        AV.saveTrade();
        const afterUntouchedSelectionSave = AV.state.trades.find((row) => row.id === id);
         result.preservedSelectionAfterUntouchedSave = {
          checklist: afterUntouchedSelectionSave?.checklist,
          checkTotal: afterUntouchedSelectionSave?.checkTotal,
           items: afterUntouchedSelectionSave?.checklistItems
         };
         AV.state.settings.checklist = ['Aktualna zasada'];
         AV.editTrade(archivedId);
         result.changedConfiguration = {
           labels: Array.from(document.querySelectorAll('#clList [data-cl]')).map((row) => row.dataset.cl),
           selected: Array.from(document.querySelectorAll('#clList .cl.on')).map((row) => row.dataset.cl)
         };
         document.querySelector('#clList [data-cl="Usunięta zasada"]')?.click();
         document.querySelector('#clList [data-cl="Aktualna zasada"]')?.click();
         AV.saveTrade();
         const afterConfigEdit = AV.state.trades.find((row) => row.id === archivedId);
         result.changedConfiguration.saved = {
           items: afterConfigEdit?.checklistItems,
           checklist: afterConfigEdit?.checklist,
           checkTotal: afterConfigEdit?.checkTotal
         };
       } finally {
          AV.state.trades = AV.state.trades.filter((row) => row.id !== id && row.id !== noTotalTrade.id && row.id !== archivedId);
         AV.state.settings.checklist = priorChecklist;
         AV.ui.editId = null;
         AV.ui.ntChecks = {};
         AV.ui.ntChecklistHistory = [];
        AV.ui.ntChecklistTouched = false;
        AV.ui.ntScreens = {};
        AV.saveNow = originalSaveNow;
        AV.renderAll = originalRenderAll;
        if (priorDraft === null) AV.safeDel(draftKey);
        else AV.safeSet(draftKey, priorDraft);
        originalRenderAll();
      }
      return result;
    });
    log(
      'historia checklisty: legacy zachowuje pola, 6/10 i szkic bez fikcyjnych punktów',
      legacyChecklist.open?.editId === legacyChecklist.id &&
        legacyChecklist.open.pair === 'LEGACY/USDT' && legacyChecklist.open.entry === '100' &&
        legacyChecklist.open.size === '1' && legacyChecklist.open.sl === '99' &&
        legacyChecklist.open.tp === '102' && legacyChecklist.open.direction === 'l' &&
        legacyChecklist.open.grade === 'B' && legacyChecklist.inputGrade === 'B' &&
        legacyChecklist.open.note === 'Historyczna transakcja' && legacyChecklist.open.summary === '6/10' &&
        legacyChecklist.noTotalSummary === '6/7' &&
        legacyChecklist.open.selected === 0 && legacyChecklist.open.warning.includes('nie zapisał konkretnych zaznaczeń') &&
        legacyChecklist.untouchedSave.grade === 'B' &&
        legacyChecklist.untouchedSave.checklist === 6 && legacyChecklist.untouchedSave.checkTotal === 10 &&
        !legacyChecklist.untouchedSave.hasItems && legacyChecklist.untouchedSave.draft.pair === 'DRAFT/SHOULD-NOT-LEAK',
      JSON.stringify(legacyChecklist)
    );
    log(
      'historia checklisty: jawny wybór zastępuje nieznane punkty i wraca po ponownej edycji',
      legacyChecklist.manualSelection.count === '1/2' &&
        JSON.stringify(legacyChecklist.manualSelection.selected) === JSON.stringify(['Poziom z planu']) &&
        legacyChecklist.savedSelection.checklist === 1 && legacyChecklist.savedSelection.checkTotal === 2 &&
        JSON.stringify(legacyChecklist.savedSelection.items) === JSON.stringify(['Poziom z planu']) &&
        JSON.stringify(legacyChecklist.reopenedSelection) === JSON.stringify(['Poziom z planu']) &&
        legacyChecklist.preservedSelectionAfterUntouchedSave.checklist === 1 &&
        legacyChecklist.preservedSelectionAfterUntouchedSave.checkTotal === 2 &&
        JSON.stringify(legacyChecklist.preservedSelectionAfterUntouchedSave.items) === JSON.stringify(['Poziom z planu']),
      JSON.stringify(legacyChecklist)
    );
    log(
      'historia checklisty: po zmianie konfiguracji stary zaznaczony punkt pozostaje widoczny i edytowalny',
      JSON.stringify(legacyChecklist.changedConfiguration?.labels) === JSON.stringify(['Aktualna zasada', 'Usunięta zasada']) &&
        JSON.stringify(legacyChecklist.changedConfiguration?.selected) === JSON.stringify(['Usunięta zasada']) &&
        JSON.stringify(legacyChecklist.changedConfiguration?.saved?.items) === JSON.stringify(['Aktualna zasada']) &&
        legacyChecklist.changedConfiguration?.saved?.checklist === 1 &&
        legacyChecklist.changedConfiguration?.saved?.checkTotal === 2,
      JSON.stringify(legacyChecklist.changedConfiguration)
    );
   const tradeAccount = await page.evaluate((note) => {
    const trade = AV.state.trades.find((t) => t.note === note);
    const account = AV.state.accounts.find((a) => a.name === trade?.account);
    return { id: account?.id || '', name: account?.name || '', include: !!account?.include };
  }, tradeNote);
  if (tradeAccount.id) {
    await page.click('.navg button[data-v="ust"]');
    if (tradeAccount.include) await page.evaluate((id) => document.querySelector(`#accBody [data-acc-inc="${id}"]`)?.click(), tradeAccount.id);
     const excluded = await page.evaluate((name) => {
      const account = AV.state.accounts.find((a) => a.name === name);
      return { include: !!account?.include, account: account?.name };
    }, tradeAccount.name);
    await page.click('.navg button[data-v="trans"]');
    const excludedJournal = await page.locator(`#tbody tr[data-id="${addedId}"]`).count();
    const tradeExcludedFromStats = await page.evaluate((note) => {
      const trade = AV.state.trades.find((t) => t.note === note);
      return !!trade && !AV.includedTrades(AV.state).includes(trade);
    }, tradeNote);
    log('dziennik: wpis widoczny przy koncie poza statystykami',
      !excluded.include && tradeExcludedFromStats && excludedJournal === 1,
      JSON.stringify({ account: tradeAccount.name, include: excluded.include, tradeExcludedFromStats, rows: excludedJournal }));
    await page.click('.navg button[data-v="stat"]');
    const visibleAccountStats = await page.evaluate(() => ({
      visible: document.querySelector('#stResult')?.textContent.trim() || '',
      expected: AV.dispMoney(AV.computeStats(AV.rangeTrades(AV.state, AV.ui.statRange), AV.state).net, 2)
    }));
    log('statystyki: widoczny wynik odzwierciedla filtr konta',
      visibleAccountStats.visible === visibleAccountStats.expected,
      JSON.stringify(visibleAccountStats));
    await page.click('.navg button[data-v="trans"]');
    const journalCsv = await page.evaluate((note) => {
      const originalDownload = AV.download;
      let captured = null;
      try {
        AV.download = (filename, content) => { captured = { filename, content }; };
        AV.exportTradesCsv();
        const content = String(captured?.content || '');
        return {
          filename: captured?.filename || '',
          includesExcludedTrade: content.includes(note),
          header: content.split(/\r?\n/)[0] || ''
        };
      } finally {
        AV.download = originalDownload;
      }
    }, tradeNote);
    log('dziennik CSV: eksportuje transakcję spoza statystyk i pomiary MAE/MFE',
      journalCsv.filename === 'alpha-vault-transakcje.csv' && journalCsv.includesExcludedTrade
      && journalCsv.header.includes('MAE') && journalCsv.header.includes('MFE'),
      JSON.stringify(journalCsv));
    const excludedEmptyState = await page.evaluate((note) => {
      const trades = AV.state.trades;
      const filter = AV.ui.jFilter;
      AV.state.trades = trades.filter((t) => t.note === note);
      AV.ui.jFilter = 'win';
      AV.renderJournal();
      const text = document.querySelector('#jEmpty')?.textContent || '';
      AV.state.trades = trades;
      AV.ui.jFilter = filter;
      AV.renderJournal();
      return text;
    }, tradeNote);
    log('dziennik: wpisy poza statystykami nie fałszują pustego stanu',
      excludedEmptyState.includes('Brak transakcji spełniających filtry') && !excludedEmptyState.includes('Dodaj transakcję'),
      excludedEmptyState);
    if (tradeAccount.include) {
      await page.click('.navg button[data-v="ust"]');
      await page.waitForSelector(`#accBody [data-acc-inc="${tradeAccount.id}"]`, { timeout: 10000 });
      const restoredAccount = await page.evaluate((id) => {
        const selector = `#accBody [data-acc-inc="${id}"]`;
        const accountId = id;
        // Toggle until the account is included again (idempotent restore).
        for (let attempt = 0; attempt < 3; attempt++) {
          const el = document.querySelector(selector);
          if (!el) break;
          const current = AV.state.accounts.find((a) => String(a.id) === String(accountId));
          if (current?.include) break;
          el.click();
        }
        return AV.state.accounts.find((a) => String(a.id) === String(accountId))?.include;
      }, tradeAccount.id);
      await page.click('.navg button[data-v="trans"]');
      log('konto: przywrócono przełącznik statystyk po regresji', restoredAccount === true, tradeAccount.name);
    }
  } else {
    log('dziennik: wpis widoczny przy koncie poza statystykami', false, 'nie znaleziono konta przypisanego do testowej transakcji');
  }
  // In-memory manipulation tests may have reloaded state; re-discover the
  // test trade by its unique note right before closing it.
  const closeId = await page.evaluate((note) =>
    AV.state.trades.find((t) => t.note === note && !AV.isTradeClosed(t))?.id || '', tradeNote);
  if (closeId) {
    await page.evaluate(async () => {
      clearTimeout(AV._pushTimer);
      await AV.waitForPushIdle();
    });
    await page.evaluate((id) => AV.closeTradeModal(id), closeId);
    await page.waitForSelector('#ctExit', { timeout: 10000 });
    await page.fill('#ctExit', '105');
    const hasResultField = await page.locator('#ctResult').count();
    if (hasResultField) await page.fill('#ctResult', '5');
    await page.evaluate(() => document.getElementById('ctSave')?.click());
    const closed = await page.evaluate((id) => AV.state.trades.find((t) => t.id === id)?.result, closeId);
    log('zamknięcie pozycji', closed === 5, String(closed));
  }
  const resultOnlyId = await page.evaluate(() => {
    const id = 'ui-result-only-' + Date.now();
    AV.state.trades.push({
      id, date: new Date().toISOString(), pair: 'RO/USDT', dir: 'l',
      entry: '100', sl: '95', tp: '120', size: '2', exit: 'otwarta',
      result: null, r: null, mae: null, mfe: null, durationMin: null, fee: null,
      source: 'Ręcznie', account: AV.state.accounts.find((a) => a.include)?.name || 'Konto główne',
      grade: '', checklist: null, checkTotal: null, errors: [], emotion: '', setup: '', note: '',
      mentor: '', session: '', plan: false, screens: {}
    });
    AV.saveNow();
    // Cancel the deferred PUT: it would fire after the close's own save with a
    // stale baseRevision and trigger a 409 reload that reverts the close.
    clearTimeout(AV._pushTimer);
    return id;
  });
  await page.evaluate((id) => AV.closeTradeModal(id), resultOnlyId);
  await page.fill('#ctExit', '');
  await page.fill('#ctResult', '20');
  await page.evaluate(() => document.getElementById('ctSave')?.click());
  const resultOnly = await page.evaluate((id) => {
    const trade = AV.state.trades.find((t) => t.id === id);
    return { exit: trade?.exit, result: trade?.result, r: trade?.r };
  }, resultOnlyId);
  log('zamknięcie tylko wynikiem: exit pozostaje pusty, R liczone z SL',
    resultOnly.exit === '' && resultOnly.result === 20 && resultOnly.r === 2,
    JSON.stringify(resultOnly));
  const emptyResultState = await page.evaluate(() => {
    const state = {
      ...AV.state,
      trades: [{ id: 'ui-empty-result', date: new Date().toISOString(), account: AV.state.accounts.find((a) => a.include)?.name, result: '' }]
    };
    return { open: AV.openTrades(state).length, closed: AV.closedTrades(state).length };
  });
  log('dziennik: pusty wynik jest otwarty, nie break-even',
    emptyResultState.open === 1 && emptyResultState.closed === 0,
    JSON.stringify(emptyResultState));

  await page.evaluate(() => {
    AV.ui.editId = null;
    AV.showView('nowa');
    AV.renderNewTradeForm();
  });
  await page.fill('#ntPair', 'E2E-BE/USDT');
  await page.fill('#ntEntry', '100');
  await page.fill('#ntSl', '99');
  await page.fill('#ntTp', '103');
  await page.fill('#ntSize', '1');
  await page.click('#saveTrade');
  await page.waitForTimeout(500);
  const breakEvenId = await page.evaluate(() => AV.state.trades.find((t) => t.pair === 'E2E-BE/USDT')?.id);
  if (breakEvenId) {
    await page.evaluate((id) => AV.closeTradeModal(id), breakEvenId);
    await page.fill('#ctExit', '100');
    await page.evaluate(() => document.getElementById('ctSave')?.click());
  }
  const breakEvenUi = await page.evaluate((id) => {
    const trade = AV.state.trades.find((t) => t.id === id);
    AV.renderDashMentor({ closed: trade ? [trade] : [] });
    return {
      result: trade?.result,
      mentor: trade?.mentor,
      dashTitle: document.querySelector('#dashMentor h3')?.textContent,
    };
  }, breakEvenId);
  log('break-even: komentarz i dashboard nie nazywają wyniku zyskiem', !!breakEvenId
    && breakEvenUi.result === 0
    && breakEvenUi.mentor.includes('zero (break-even)')
    && !breakEvenUi.mentor.includes('zyskiem')
    && breakEvenUi.dashTitle.includes('Wynik na zero'), JSON.stringify(breakEvenUi));

  // odznaki: każda z 8 reguł niezależnie + dynamiczny licznik i poziom
  const badges = await page.evaluate(() => {
    const base = () => ({ accounts: [{ name: 'A', include: true }], trades: [], plans: {}, checkins: [] });
    const iso = (offset) => new Date(AV.addDays(new Date(), offset)).toISOString();
    const key = (offset) => AV.dateKey(AV.addDays(new Date(), offset));
    const trade = (offset, extra = {}) => ({ date: iso(offset), pair: 'BTC/USDT', dir: 'l', result: 10, sl: '100', grade: 'A', emotion: 'Spokój', plan: true, errors: [], ...extra });
    const plan = (state, offset, approved = true) => {
      const k = key(offset);
      state.plans[k] = { date: k, market: 'BTC', bias: 'long', approvedAt: approved ? new Date(AV.addDays(new Date(), offset)).setHours(8, 0, 0, 0) : null };
    };
    const badge = (state, index) => AV.badges(state)[index];
    const out = {};
    const empty = base();
    out.count = AV.badges(empty).length;
    out.empty = AV.badges(empty).every((b) => !b.done && b.progress === 0);
    out.emptySummary = AV.badgeSummary(AV.badges(empty));

    let s = base(); plan(s, 0); out.plan = badge(s, 0);
    s = base(); for (let i = 0; i < 7; i++) s.trades.push(trade(-i)); out.seven = badge(s, 1);
    s = base(); for (let i = 0; i < 50; i++) s.trades.push(trade(0)); out.sl50 = badge(s, 2);
    s = base(); for (let i = 0; i < 49; i++) s.trades.push(trade(0)); out.sl49 = badge(s, 2);
    s = base(); for (let i = 0; i < 5; i++) { plan(s, -i); s.trades.push(trade(-i)); } out.week = badge(s, 3);
    s = base(); for (let i = 0; i < 30; i++) plan(s, -i); out.plans30 = badge(s, 4);
    s = base(); for (let i = 0; i < 100; i++) s.trades.push(trade(0)); out.a100 = badge(s, 5);
    s = base(); for (let i = 0; i < 10; i++) s.trades.push(trade(-i)); out.fomo10 = badge(s, 6);
    s = base(); for (let i = 0; i < 10; i++) s.trades.push(trade(-i, { emotion: i === 5 ? 'FOMO' : 'Spokój' })); out.fomoBreak = badge(s, 6);
    s = base(); for (let i = 0; i < 10; i++) s.trades.push(trade(-i * 2)); out.fomoGaps = badge(s, 6);
    s = base(); for (let i = 0; i < 5; i++) { plan(s, -i * 2); s.trades.push(trade(-i * 2)); } out.weekGaps = badge(s, 3);

    const prev = new Date(new Date().getFullYear(), new Date().getMonth() - 1, 12, 12, 0, 0).toISOString();
    s = base(); s.trades.push({ ...trade(0), date: prev, result: 100 }); out.month = badge(s, 7);
    s = base(); s.trades.push({ ...trade(0), date: prev, result: 100, grade: 'C' }); out.monthC = badge(s, 7);

    s = base();
    plan(s, 0);
    for (let i = 0; i < 10; i++) s.trades.push(trade(-i, { grade: 'B', result: 0 }));
    for (let i = 0; i < 50; i++) s.trades.push(trade(0, { grade: 'B', result: 0 }));
    out.summary4 = AV.badgeSummary(AV.badges(s));
    out.live = AV.badgeSummary(AV.badges(AV.state));
    return out;
  });
  log('odznaka 1: pierwszy plan dnia', badges.plan.done && badges.plan.progress === 100, badges.plan.hint);
  log('odznaka 2: 7 dni z dziennikiem', badges.seven.done && badges.seven.progress === 100, badges.seven.hint);
  log('odznaka 3: 50 × ze stop lossem', badges.sl50.done && !badges.sl49.done && badges.sl49.hint === '49 / 50', badges.sl49.hint);
  log('odznaka 4: tydzień bez złamania zasad', badges.week.done && badges.week.progress === 100, badges.week.hint);
  log('odznaka 4: przerwy w kalendarzu nie przerywają serii', badges.weekGaps.done && badges.weekGaps.progress === 100, badges.weekGaps.hint);
  log('odznaka 5: 30 planów dnia', badges.plans30.done && badges.plans30.progress === 100, badges.plans30.hint);
  log('odznaka 6: 100 transakcji A', badges.a100.done && badges.a100.progress === 100, badges.a100.hint);
  log('odznaka 7: 10 dni bez FOMO', badges.fomo10.done && !badges.fomoBreak.done && badges.fomoBreak.hint === '5 / 10', badges.fomoBreak.hint);
  log('odznaka 7: przerwy w kalendarzu nie przerywają serii', badges.fomoGaps.done && badges.fomoGaps.progress === 100, badges.fomoGaps.hint);
  log('odznaka 8: miesiąc na plusie bez C', badges.month.done && !badges.monthC.done, badges.month.hint);
  log('odznaki: puste dane bez fałszywych postępów', badges.count === 8 && badges.empty && badges.emptySummary.done === 0 && badges.emptySummary.level === 1, JSON.stringify(badges.emptySummary));
  log('odznaki: 4 z 8 daje poziom 3', badges.summary4.done === 4 && badges.summary4.level === 3, JSON.stringify(badges.summary4));
  const summaryText = await page.locator('#badgeSummary').innerText();
  const levelText = await page.locator('#levelBadge').innerText();
  log('odznaki: dynamiczny licznik i poziom', summaryText === `${badges.live.done} z ${badges.live.total} · poziom ${badges.live.level}` && levelText === 'poziom ' + badges.live.level, summaryText + ' / ' + levelText);

  // przywróć stan demo i poczekaj na zapis do MySQL
  await page.evaluate((raw) => {
    AV.state = AV.normalizeServer(JSON.parse(raw));
    AV.saveNow();
  }, backup);
  await page.waitForTimeout(700);
  const restoredCount = await page.evaluate(() => AV.state.trades.length);
  log('przywrócenie stanu demo', restoredCount === 47, restoredCount);

  // bramka roli: pełnoekranowa blokada panelu
  await page.evaluate(() => window.AV.showAccessGate('Brak aktywnej roli Alpha.'));
  const gateVisible = await page.locator('#roleGate').isVisible();
  const gateZ = await page.locator('#roleGate').evaluate((el) => Number(getComputedStyle(el).zIndex));
  log('bramka roli blokuje panel', gateVisible && gateZ >= 150, 'z-index ' + gateZ);
  await page.evaluate(() => document.getElementById('roleGate').classList.remove('on'));

  // wylogowanie
  const leakMarker = 'E2E-LEAK-' + Date.now();
  await page.evaluate((marker) => { AV.state.e2eLeakMarker = marker; AV.saveNow(); }, leakMarker);
  await page.waitForTimeout(400);
  await page.evaluate(() => document.querySelector('#logout').click());
  await page.waitForURL(BASE + '/', { timeout: 10000 }).catch(() => {});
  await page.waitForFunction(() => window.AV?.api?.authenticated === false, null, { timeout: 10000 });
  const cacheAfterLogout = await page.evaluate((marker) => ({
    body: document.body.innerText.includes(marker),
    state: JSON.stringify(window.AV?.state || {}).includes(marker),
    keys: Object.keys(localStorage).filter((key) => key.startsWith('alphavault.'))
  }), leakMarker);
  log('wylogowanie', await page.locator('#auth').isVisible());
  log('wylogowanie czyści prywatny cache', !cacheAfterLogout.body && !cacheAfterLogout.state
    && !cacheAfterLogout.keys.some((key) => key.startsWith('alphavault.state.v2') || key.startsWith('alphavault.state.v3')),
    JSON.stringify(cacheAfterLogout.keys));
} catch (err) {
  console.error('\nFAIL  test przerwany:', err.message);
  results.push({ name: 'test przerwany', ok: false, info: err.message });
  process.exitCode = 1;
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} PASS`);
if (consoleErrors.length) console.log('Console errors:', consoleErrors.slice(0, 10));
if (pageErrors.length) console.log('Page errors:', pageErrors.slice(0, 10));
if (failed.length) process.exitCode = 1;
