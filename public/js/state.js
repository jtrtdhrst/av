/* Alpha Vault — stan, zapis, metryki */
(function () {
  'use strict';
  const AV = window.AV;
  AV.KEY = 'alphavault.state.v2';
  AV.LEGACY_KEY = AV.KEY;
  AV.LEGACY_DRAFT_KEY = 'alphavault.draft.v2';
  AV.LEGACY_WIPED_KEY = 'alphavault.wiped.v2';
  AV.KEY_PREFIX = 'alphavault.state.v3';
  AV.OFFLINE_KEY = AV.KEY_PREFIX + '.offline';
  AV.SESSION_KEY = 'alphavault.session.v2';
  AV.currentUserId = null;

  AV.state = null;
  AV._saveTimer = null;
  AV.api = {
    online: false,
    authenticated: false,
    ready: false,
    resolved: false,
    revision: 0,
    me: null,
    discordConfigured: false,
    demoAllowed: false,
    pushing: false,
    dirty: false,
    lastError: null,
    saveError: null,
    lastSavedAt: null,
    events: null,
    eventsLoaded: false,
    eventsSync: null,
    macroEvents: [],
    macroSync: null,
    macroLoaded: false,
    macroError: null,
    isAdmin: false,
    wearables: null,
    wearablesLoaded: false,
    wearablesError: null,
    integrationsConfigured: false,
    metaApiConfigured: false,
    mcp: null,
    mcpLoaded: false,
    mcpError: null
  };

  const scopedKey = (suffix, userId = AV.currentUserId) => {
    const id = Number(userId);
    return Number.isFinite(id) && id > 0 ? `${AV.KEY_PREFIX}.${id}.${suffix}` : null;
  };
  AV.stateKey = () => scopedKey('state') || (AV.api.resolved && !AV.api.online ? AV.OFFLINE_KEY : null);
  AV.draftKey = () => scopedKey('draft') || (AV.api.resolved && !AV.api.online ? AV.OFFLINE_KEY + '.draft' : null);
  AV.wipedKey = () => scopedKey('wiped') || (AV.api.resolved && !AV.api.online ? AV.OFFLINE_KEY + '.wiped' : null);

  AV.purgeUserCache = (userId) => {
    ['state', 'draft', 'wiped'].forEach((suffix) => AV.safeDel(scopedKey(suffix, userId)));
  };
  AV.purgeCurrentUserCache = () => {
    if (AV.currentUserId) AV.purgeUserCache(AV.currentUserId);
  };
  AV.purgeAllUserCaches = () => {
    AV.safeDel(AV.LEGACY_KEY);
    AV.safeDel(AV.LEGACY_DRAFT_KEY);
    AV.safeDel(AV.LEGACY_WIPED_KEY);
    AV.safeDel(AV.OFFLINE_KEY);
    AV.safeDel(AV.OFFLINE_KEY + '.draft');
    AV.safeDel(AV.OFFLINE_KEY + '.wiped');
    try {
      const keys = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.startsWith(AV.KEY_PREFIX + '.')) keys.push(key);
      }
      keys.forEach((key) => AV.safeDel(key));
    } catch {}
  };
  AV.purgeOtherUserCaches = (keepUserId) => {
    const keep = `${AV.KEY_PREFIX}.${Number(keepUserId)}.`;
    AV.safeDel(AV.LEGACY_KEY);
    AV.safeDel(AV.LEGACY_DRAFT_KEY);
    AV.safeDel(AV.LEGACY_WIPED_KEY);
    try {
      const keys = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.startsWith(AV.KEY_PREFIX + '.') && !key.startsWith(keep)) keys.push(key);
      }
      keys.forEach((key) => AV.safeDel(key));
    } catch {}
  };

  const deepMerge = (base, patch) => {
    if (Array.isArray(base)) return Array.isArray(patch) ? patch : base;
    if (base && typeof base === 'object') {
      const out = { ...base };
      Object.keys(patch || {}).forEach((k) => {
        out[k] = k in base ? deepMerge(base[k], patch[k]) : patch[k];
      });
      return out;
    }
    return patch === undefined ? base : patch;
  };

  AV.TAX_VERSION = 2;
  AV.normalizeTax = (raw, opts = {}) => {
    const src = raw && typeof raw === 'object' ? raw : {};
    const year = Number(src.year) >= 2000 ? Math.trunc(Number(src.year)) : new Date().getFullYear();
    let carryLoss = Math.max(0, Math.round(AV.pnum(src.carryLoss) * 100) / 100);
    if (opts.migrateDemoSeed && carryLoss === 380) carryLoss = 0;
    return { version: AV.TAX_VERSION, carryLoss, year };
  };

  AV.normalize = (raw, fallback, taxOpts) => {
    const demo = fallback || AV.buildDemo();
    const s = deepMerge(demo, raw || {});
    ['plans', 'trades', 'setups', 'accounts', 'flows', 'checkins', 'chat', 'overrides', 'recordings', 'trash'].forEach((k) => {
      if (!Array.isArray(s[k]) && k !== 'plans') s[k] = demo[k];
    });
    if (!Array.isArray(s.trash)) s.trash = [];
    const rawHasPlans = !!raw && typeof raw === 'object' && Object.prototype.hasOwnProperty.call(raw, 'plans');
    const plansInput = rawHasPlans ? raw.plans : s.plans;
    const planSource = plansInput && typeof plansInput === 'object' ? plansInput : {};
    const normalizedPlans = {};
    const planRecords = [];
    Object.entries(planSource).forEach(([rawKey, value]) => {
      const entries = Array.isArray(value) ? value : [value];
      entries.forEach((plan) => {
        if (!plan || typeof plan !== 'object' || Array.isArray(plan)) return;
        const keyDate = String(rawKey).match(/^\d{4}-\d{2}-\d{2}/)?.[0] || '';
        const planDate = String(plan.date || '');
        const date = planDate === 'global' ? 'global' : (/^\d{4}-\d{2}-\d{2}$/.test(planDate) ? planDate : keyDate);
        if (!date) return;
        const marketSlug = String(plan.market || 'market').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'market';
        const id = String(plan.id || ('legacy-' + date + '-' + marketSlug + '-' + (planRecords.length + 1)));
        const levels = Array.isArray(plan.levels) ? plan.levels.map((level) => {
          if (!level || typeof level !== 'object') return level;
          const type = String(level.type || '').trim().toLowerCase();
          return {
            ...level,
            price: String(level.price ?? level.value ?? ''),
            type: ['liquidity', 'płynność'].includes(type) ? 'Liquidity'
              : type === 'poi' ? 'POI'
                : ['resistance', 'opór', 'opor'].includes(type) ? 'Opór'
                    : ['support', 'wsparcie'].includes(type) ? 'Wsparcie'
                      : level.type || 'Wsparcie'
          };
        }) : [];
        const normalized = { ...plan, id, date, levels };
        planRecords.push({ rawKey, date, id, plan: normalized });
      });
    });
    const recordsByDate = new Map();
    planRecords.forEach((record) => {
      if (!recordsByDate.has(record.date)) recordsByDate.set(record.date, []);
      recordsByDate.get(record.date).push(record);
    });
    const usedPlanIds = new Set();
    recordsByDate.forEach((records, date) => {
      const primaryIndex = records.findIndex((record) => record.rawKey === date);
      if (primaryIndex > 0) records.unshift(records.splice(primaryIndex, 1)[0]);
      records.forEach((record, index) => {
        let id = record.id;
        if (usedPlanIds.has(id)) {
          const baseId = id;
          let suffix = 2;
          do { id = baseId + '-' + suffix++; } while (usedPlanIds.has(id));
        }
        let storageKey = index === 0 ? date : date + '#' + id;
        while (Object.prototype.hasOwnProperty.call(normalizedPlans, storageKey)) {
          const baseId = id;
          let suffix = 2;
          do { id = baseId + '-' + suffix++; } while (usedPlanIds.has(id));
          storageKey = date + '#' + id;
        }
        usedPlanIds.add(id);
        normalizedPlans[storageKey] = { ...record.plan, id, date };
      });
    });
    s.plans = normalizedPlans;
    if (!s.recordings || !Array.isArray(s.recordings)) s.recordings = [];
    if (!s.meta || typeof s.meta !== 'object') s.meta = {};
    if (!Array.isArray(s.meta.courseDone)) s.meta.courseDone = [];
    if (!Array.isArray(s.meta.bootcampDone)) s.meta.bootcampDone = [];
    if (!s.settings) s.settings = {};
    if (Array.isArray(s.settings.checklist)) {
      s.settings.checklist = s.settings.checklist.map((item) => item === 'R:R ≥ 2' ? 'R:R zgodne z planem' : item);
    }
    const mins = Number(s.settings.liveReminderMinutes);
    s.settings.liveReminderMinutes = [5, 15, 30, 60].includes(mins) ? mins : 15;
    if (!s.prop || typeof s.prop !== 'object') s.prop = { challenges: [], selectedId: null };
    if (!Array.isArray(s.prop.challenges)) s.prop.challenges = [];
    s.prop.challenges = s.prop.challenges
      .filter((c) => c && typeof c === 'object')
      .map((c) => (AV.prop ? AV.prop.normalize(c) : c));
    s.tax = AV.normalizeTax(s.tax, taxOpts);
    s.version = 2;
    return s;
  };
  AV.normalizeServer = (raw, opts = {}) => {
    const legacyTax = Number(raw?.tax?.version || 0) < AV.TAX_VERSION;
    const state = AV.normalize(raw, AV.buildEmpty(), { migrateDemoSeed: !opts.isDemo && legacyTax });
    return opts.isDemo ? state : AV.cleanLegacyDemoSeed(state);
  };

  AV.allPlans = (state = AV.state) => Object.entries(state?.plans || {}).flatMap(([key, value], index) => {
    const entries = Array.isArray(value) ? value : [value];
    return entries.filter((plan) => plan && typeof plan === 'object' && !Array.isArray(plan)).map((plan, planIndex) => {
      const date = String(plan.date || key).slice(0, 10);
      const marketSlug = String(plan.market || 'market').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'market';
      return { ...plan, date, id: String(plan.id || ('legacy-' + date + '-' + marketSlug + '-' + (index + planIndex + 1))) };
    });
  });
  AV.plansForDate = (state, date) => AV.allPlans(state).filter((plan) => plan.date === date);
  AV.planById = (state, id) => id == null ? null : AV.allPlans(state).find((plan) => plan.id === String(id)) || null;
  AV.storePlan = (state, plan) => {
    if (!state.plans || typeof state.plans !== 'object') state.plans = {};
    const date = String(plan.date || '');
    if (!plan.id) plan.id = AV.uid('plan');
    let key = Object.keys(state.plans).find((candidate) => state.plans[candidate]?.id === plan.id);
    if (!key) {
      key = Object.prototype.hasOwnProperty.call(state.plans, date) ? date + '#' + plan.id : date;
      while (Object.prototype.hasOwnProperty.call(state.plans, key) && state.plans[key]?.id !== plan.id) key = date + '#' + AV.uid('plan');
    }
    state.plans[key] = { ...plan, id: String(plan.id), date };
    return state.plans[key];
  };

  AV.load = () => {
    const key = AV.stateKey();
    if (!key) {
      AV.state = AV.buildEmpty();
      return AV.state;
    }
    const raw = AV.safeGet(key);
    if (raw) {
      try {
        AV.state = AV.normalize(JSON.parse(raw));
        return AV.state;
      } catch (e) {
        console.warn('Nie udało się odczytać stanu, tworzę nowy.', e);
      }
    }
    AV.state = AV.safeGet(AV.wipedKey()) ? AV.buildEmpty() : AV.normalize(AV.buildDemo());
    AV.saveNow();
    return AV.state;
  };

  AV.buildEmpty = () => {
    const s = AV.buildDemo();
    s.trades = [];
    s.plans = {};
    s.checkins = [];
    s.chat = [];
    s.flows = [];
    s.overrides = [];
    s.tax = { version: AV.TAX_VERSION, carryLoss: 0, year: new Date().getFullYear() };
    s.accounts = [{
      id: 'acc1', name: 'Konto główne', exchange: 'WEEX', type: 'Futures', currency: 'USDT',
      start: 0, date: AV.dateLabel(new Date()), lev: '10×', goal: '', balance: 0, include: true, primary: true
    }];
    s.setups = [];
    s.recordings = [];
    s.prop = { challenges: [], selectedId: null };
    s.health = null;
    s.user.name = 'Trader';
    s.user.email = '';
    s.user.joined = null;
    s.user.goal = '';
    s.user.markets = [];
    s.user.avatar = null;
    s.subscription = { plan: 'solo', until: null, expired: false, role: '', renewedAt: null };
    s.meta = {
      streakBase: 0,
      notified: {},
      liveReminder: false,
      liveNotified: {},
      consultation: null,
      consultationUsed: 0,
      courseProgress: 0,
      courseDone: [],
      bootcampDone: [],
      sessions: [],
      createdAt: Date.now()
    };
    s.settings.connections = {
      bingx: { connected: false },
      mt: { connected: false, server: '', login: '', masked: '', code: '', codeAt: null }
    };
    return s;
  };

  AV.cleanLegacyDemoSeed = (s) => {
    if (!s || !s.meta) return s;
    const demoIdentity = !!(s.user && (s.user.joined === '2026-03-01' || s.user.email === 'kasia@example.com'));
    if (s.user && s.user.joined === '2026-03-01') s.user.joined = null;
    if (s.user && s.user.email === 'kasia@example.com') s.user.email = '';
    if (demoIdentity && s.user.name === 'Kasia') s.user.name = 'Trader';
    if (demoIdentity && s.user.goal === '+6% bez łamania zasad') s.user.goal = '';
    if (s.meta.courseProgress === 64) s.meta.courseProgress = 0;
    if (s.meta.streakBase === 12) s.meta.streakBase = 0;
    if (Array.isArray(s.meta.sessions) && s.meta.sessions.some((x) => x && x.device === 'MacBook · Chrome')) s.meta.sessions = [];
    const c = s.settings && s.settings.connections;
    if (c) { delete c.weex; delete c.okx; }
    if (s.subscription && s.subscription.until === '2026-12-03') s.subscription.until = null;
    return s;
  };

  AV.saveNow = () => {
    if (!AV.state) return;
    const key = AV.stateKey();
    if (key) {
      try {
        AV.safeSet(key, JSON.stringify(AV.state));
      } catch (e) {
        console.warn('localStorage pełny — nie zapisano stanu.', e);
        if (!AV.api.online) AV.toast('Pamięć przeglądarki pełna. Usuń stare screeny, żeby zapisać zmiany.', 5000);
      }
    }
    if (AV.api.online && AV.api.authenticated && AV.api.ready) AV.schedulePush();
  };

  AV.save = () => {
    clearTimeout(AV._saveTimer);
    AV._saveTimer = setTimeout(AV.saveNow, 120);
  };

  /* ---------- API / MySQL ---------- */
  AV.apiFetch = async (path, opts = {}) => {
    const res = await fetch(path, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...opts,
      headers: { ...(opts.headers || {}) }
    });
    if (AV.api.authenticated && path.startsWith('/api/') && res.status === 401) {
      AV.showAccessGate('Sesja wygasła. Zaloguj się ponownie — dostęp wymaga aktywnej roli Alpha.');
    }
    return res;
  };

  AV.showAccessGate = (message) => {
    AV.api.authenticated = false;
    const gate = document.getElementById('roleGate');
    if (!gate) return;
    if (message) AV.setText('#roleGateMsg', message);
    document.getElementById('auth')?.classList.add('hide');
    document.getElementById('expired')?.classList.remove('on');
    gate.classList.add('on');
  };

  AV.checkAccess = async () => {
    if (!AV.api.online || !AV.api.authenticated) return false;
    try {
      const res = await AV.apiFetch('/api/me');
      if (res.status === 401) {
        AV.showAccessGate('Sesja wygasła. Zaloguj się ponownie — dostęp wymaga aktywnej roli Alpha.');
        return false;
      }
      if (!res.ok) return true;
      const me = await res.json();
      if (!me.authenticated) {
        AV.showAccessGate('Sesja wygasła. Zaloguj się ponownie — dostęp wymaga aktywnej roli Alpha.');
        return false;
      }
      if (me.roleCheckConfigured && !me.user?.isDemo && me.user?.subscription?.roleVerified === false) {
        AV.showAccessGate('Brak aktywnej roli Alpha na serwerze Alpha Moves. Rola jest sprawdzana co 5 minut.');
        return false;
      }
      AV.api.lastRoleCheckAt = Date.now();
      AV.applyServerUser(me.user);
      return true;
    } catch {
      // Błąd sieci nie odbiera dostępu osobie, która ma ważną sesję.
      return true;
    }
  };

  AV.applyServerUser = (user) => {
    if (!user) return;
    const u = AV.state.user;
    const name = user.globalName || user.username || '';
    if (!u.name || ['Trader', 'Kasia', 'Kasia (demo)'].includes(u.name)) {
      u.name = name === 'Kasia (demo)' ? 'Kasia' : (name || u.name);
    }
    if (!u.email) u.email = user.email || '';
    if (user.joinedAt) u.joined = user.joinedAt;
    u.discordId = user.id || null;
    u.discordAvatar = user.avatarUrl || null;
    u.isDemo = !!user.isDemo;
    const sub = user.subscription || {};
    AV.state.subscription.plan = sub.plan || AV.state.subscription.plan || 'full';
    AV.state.subscription.until = sub.accessUntil || null;
    AV.state.subscription.expired = !!sub.expired;
    AV.state.subscription.role = sub.roleVerified ? 'Alpha' : 'Alpha (bez weryfikacji roli)';
    AV.api.me = user;
  };

  AV.forceSessionReset = (message) => {
    AV.api.authenticated = false;
    AV.api.ready = false;
    AV.api.me = null;
    AV.currentUserId = null;
    AV.safeDel(AV.SESSION_KEY);
    try { sessionStorage.removeItem(AV.SESSION_KEY); } catch {}
    AV.purgeAllUserCaches();
    AV.state = AV.buildEmpty();
    if (AV.renderAll) AV.renderAll();
    const auth = document.getElementById('auth');
    if (auth) auth.classList.remove('hide');
    if (message && AV.toast) AV.toast(message, 6000);
  };

  AV.bootstrap = async () => {
    try {
      const meRes = await AV.apiFetch('/api/me');
      if (!meRes.ok) throw new Error('HTTP ' + meRes.status);
      const me = await meRes.json();
      AV.api.online = true;
      AV.api.authenticated = !!me.authenticated;
      AV.api.discordConfigured = !!me.discordConfigured;
      AV.api.demoAllowed = !!me.demoAllowed;
      AV.api.roleCheckConfigured = !!me.roleCheckConfigured;
      AV.api.roleRecheckConfigured = !!me.roleRecheckConfigured;
      AV.api.roleRecheckSeconds = Number(me.roleRecheckSeconds) || 300;
      AV.api.me = me.user || null;
      AV.api.isAdmin = !!me.isAdmin;
      AV.api.lastRoleCheckAt = Date.now();
      if (me.authenticated && me.roleCheckConfigured && !me.user?.isDemo && me.user?.subscription?.roleVerified === false) {
        AV.showAccessGate('Brak aktywnej roli Alpha na serwerze Alpha Moves. Rola jest sprawdzana przy logowaniu i co 5 minut.');
        AV.api.authenticated = false;
        AV.api.ready = true;
        return { authenticated: false, denied: true, me };
      }
      AV.api.resolved = true;
      if (!me.authenticated) {
        AV.currentUserId = null;
        AV.api.events = null;
        AV.api.eventsSync = null;
        AV.api.eventsLoaded = false;
        AV.api.macroEvents = [];
        AV.api.macroSync = null;
        AV.api.macroLoaded = false;
        AV.api.ready = true;
        AV.purgeAllUserCaches();
        AV.state = AV.buildEmpty();
        return { authenticated: false, me };
      }
      const userId = Number(me.user?.id);
      if (!Number.isFinite(userId) || userId <= 0) throw new Error('Brak identyfikatora użytkownika.');
      AV.purgeOtherUserCaches(userId);
      AV.currentUserId = userId;
      const stRes = await AV.apiFetch('/api/state');
      if (stRes.status === 401) {
        AV.forceSessionReset('Sesja wygasła. Zaloguj się ponownie.');
        AV.api.ready = true;
        AV.showAccessGate('Sesja wygasła. Zaloguj się ponownie — dostęp wymaga aktywnej roli Alpha.');
        return { authenticated: false, denied: true, me };
      }
      if (!stRes.ok) throw new Error('state HTTP ' + stRes.status);
      const payload = await stRes.json();
      if (payload.userId && Number(payload.userId) !== userId) {
        AV.forceSessionReset('Sesja w tej karcie należy do innego konta. Odśwież stronę.');
        AV.api.ready = true;
        return { authenticated: false, me };
      }
      const hadState = !!payload.state;
      const taxMigrated = hadState && Number(payload.state?.tax?.version || 0) < AV.TAX_VERSION;
      if (hadState) {
        AV.state = AV.normalizeServer(payload.state, { isDemo: !!me.user?.isDemo });
        AV.api.revision = payload.revision || 1;
      } else {
        AV.state = AV.normalizeServer(me.user?.isDemo ? AV.buildDemo() : AV.buildEmpty(), { isDemo: !!me.user?.isDemo });
        AV.api.revision = 0;
      }
      AV.applyServerUser(me.user);
      await AV.loadConnections();
      await AV.loadEvents();
      await AV.loadMacroEvents();
      await AV.loadWearables();
      AV.api.ready = true;
      if (!hadState || taxMigrated || AV.state.subscription.expired !== !!me.user?.subscription?.expired) {
        await AV.pushStateImmediate();
      }
      return { authenticated: true, me };
    } catch (err) {
      AV.api.online = false;
      AV.api.authenticated = false;
      AV.api.ready = false;
      AV.api.resolved = true;
      AV.currentUserId = null;
      AV.api.lastError = err.message;
      AV.state = AV.load();
      return { offline: true, error: err.message };
    }
  };

  AV.warsawDateKey = (date = new Date()) => new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Warsaw', year: 'numeric', month: '2-digit', day: '2-digit'
  }).format(date);

  AV.loadEvents = async () => {
    if (!AV.api.online || !AV.api.authenticated) {
      AV.api.events = null;
      AV.api.eventsLoaded = false;
      AV.api.eventsSync = null;
      return false;
    }
    try {
      const res = await AV.apiFetch('/api/events');
      if (!res.ok) return false;
      const data = await res.json();
      AV.api.events = data;
      AV.api.eventsLoaded = true;
      AV.api.eventsSync = data.sync || null;
      if (AV.renderAcademy) AV.renderAcademy();
      return true;
    } catch (err) {
      AV.api.lastError = err.message;
      return false;
    }
  };

  AV.loadMacroEvents = async () => {
    if (!AV.api.online || !AV.api.authenticated) {
      AV.api.macroEvents = [];
      AV.api.macroSync = null;
      AV.api.macroLoaded = false;
      return false;
    }
    try {
      const res = await AV.apiFetch('/api/macro-events');
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (!data || !Array.isArray(data.events)) throw new Error('invalid macro payload');
      AV.api.macroEvents = data.events.map((e) => ({
        id: String(e.id || ''),
        date: e.startAt,
        cur: e.currency || '',
        name: e.name || '',
        desc: e.description || '',
        imp: Number(e.impact) || 0,
        forecast: e.forecast || '',
        previous: e.previous || ''
      })).filter((e) => e.date && e.name);
      AV.api.macroSync = data.sync || null;
      AV.api.macroLoaded = true;
      AV.api.macroError = null;
      if (AV.renderCalendar) AV.renderCalendar();
      if (AV.renderPlanSide) AV.renderPlanSide();
      return true;
    } catch (err) {
      // Błąd API zachowuje ostatni dobry zestaw; sukces z [] czyści widok.
      AV.api.macroError = err.message;
      if (AV.renderCalendar) AV.renderCalendar();
      return false;
    }
  };

  AV.loadWearables = async () => {
    if (!AV.api.online || !AV.api.authenticated) {
      AV.api.wearables = null;
      AV.api.wearablesLoaded = false;
      return false;
    }
    try {
      const res = await AV.apiFetch('/api/wearables');
      if (res.status === 401) {
        AV.api.wearables = null;
        AV.api.wearablesLoaded = false;
        return false;
      }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      AV.api.wearables = await res.json();
      AV.api.wearablesLoaded = true;
      AV.api.wearablesError = null;
      if (AV.renderBody) AV.renderBody();
      return true;
    } catch (err) {
      AV.api.wearablesError = err.message;
      return false;
    }
  };

  AV.setEventCategory = async (id, category) => {
    const res = await AV.apiFetch('/api/admin/events/' + encodeURIComponent(id) + '/category', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ category })
    });
    if (!res.ok) return false;
    await AV.loadEvents();
    return true;
  };

  AV.nextLiveEvent = () => {    const data = AV.api.events || {};
    const list = (data.live || []).filter((e) => e.statusCode !== 3 && e.statusCode !== 4);
    const now = Date.now();
    const active = list.find((e) => {
      const start = new Date(e.startAt).getTime();
      const end = e.endAt ? new Date(e.endAt).getTime() : start + 3 * 3600 * 1000;
      return start <= now && now < end;
    });
    if (active) return active;
    return list
      .filter((e) => new Date(e.startAt).getTime() >= now)
      .sort((a, b) => new Date(a.startAt) - new Date(b.startAt))[0] || null;
  };

  AV.nextConsultationEvent = () => {
    const data = AV.api.events || {};
    return data.nextConsultation || null;
  };

  AV.eventTimeLabel = (event) => event ? (event.startLabel || '') : '';

  AV.schedulePush = () => {
    clearTimeout(AV._pushTimer);
    AV._pushTimer = setTimeout(() => AV.pushStateImmediate(), 300);
  };

  AV.waitForPushIdle = (timeoutMs = 5000) => new Promise((resolve) => {
    const started = Date.now();
    const tick = () => {
      if (!AV.api.pushing) return resolve(true);
      if (Date.now() - started > timeoutMs) return resolve(false);
      setTimeout(tick, 100);
    };
    tick();
  });

  AV.pushStateImmediate = async () => {
    if (!AV.api.online || !AV.api.authenticated || !AV.api.ready || !AV.state) return false;
    clearTimeout(AV._pushTimer);
    if (AV.api.pushing) { AV.api.dirty = true; return false; }
    AV.api.pushing = true;
    let succeeded = false;
    try {
      const res = await AV.apiFetch('/api/state', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ state: AV.state, baseRevision: AV.api.revision, ownerId: AV.currentUserId })
      });
      if (res.status === 409) {
        AV.api.dirty = false;
        const latest = await AV.apiFetch('/api/state');
        if (latest.ok) {
          const payload = await latest.json();
          if (payload.userId && Number(payload.userId) !== Number(AV.currentUserId)) {
            AV.forceSessionReset('Sesja w tej karcie należy do innego konta. Odśwież stronę.');
            return false;
          }
          // Conflict caused by a stale revision (another tab / background
          // sync): retry once with the fresh revision, keeping local changes.
          // This makes a just-executed user action (e.g. closing a trade)
          // survive a benign race instead of being silently reverted.
          if (typeof payload.revision === 'number') {
            AV.api.revision = payload.revision;
            const retry = await AV.apiFetch('/api/state', {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ state: AV.state, baseRevision: AV.api.revision, ownerId: AV.currentUserId })
            });
            if (retry.ok) {
              const retryPayload = await retry.json();
              if (typeof retryPayload.revision === 'number') AV.api.revision = retryPayload.revision;
              succeeded = true;
              return true;
            }
          }
          if (payload.state) {
            AV.state = AV.normalizeServer(payload.state, { isDemo: !!AV.api.me?.isDemo });
            AV.applyServerUser(AV.api.me);
            AV.api.revision = payload.revision;
            AV.saveNow();
            if (AV.renderAll) AV.renderAll();
            AV.toast('Dane zostały zmienione w innej karcie. Wczytano najnowszą wersję.', 4000);
          }
        }
        return false;
      }
      if (res.status === 401) {
        AV.api.authenticated = false;
        AV.currentUserId = null;
        AV.purgeAllUserCaches();
        AV.state = AV.buildEmpty();
        AV.showAccessGate('Sesja wygasła. Zaloguj się ponownie - dostęp wymaga aktywnej roli Alpha.');
        return false;
      }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const payload = await res.json();
      if (payload.userId && Number(payload.userId) !== Number(AV.currentUserId)) {
        AV.forceSessionReset('Sesja w tej karcie należy do innego konta. Odśwież stronę.');
        return false;
      }
      AV.api.revision = payload.revision;
      AV.api.lastSavedAt = Date.now();
      AV.api.lastError = null;
      AV.api.saveError = null;
      AV.updateEnvBadge();
      succeeded = true;
      return true;
    } catch (err) {
      AV.api.lastError = err.message;
      AV.api.saveError = err.message;
      console.warn('Nie udało się zapisać stanu na serwerze:', err.message);
      AV.updateEnvBadge();
      if (AV.toast && (!AV._saveErrorToastAt || Date.now() - AV._saveErrorToastAt > 15000)) {
        AV._saveErrorToastAt = Date.now();
        AV.toast('Nie zapisano w bazie: ' + err.message + '. Kolejna zmiana ponowi próbę.', 6000);
      }
      return false;
    } finally {
      AV.api.pushing = false;
      // Only reschedule when the last write actually landed. Rescheduling after
      // a failed/conflicted PUT starts another PUT with the same stale revision,
      // producing an endless 409 loop that can overwrite a just-closed trade.
      if (succeeded && AV.api.dirty) {
        AV.api.dirty = false;
        clearTimeout(AV._pushTimer);
        AV._pushTimer = setTimeout(() => AV.pushStateImmediate(), 250);
      }
    }
  };

  AV.flushState = () => {
    if (!AV.api.online || !AV.api.authenticated || !AV.api.ready || !AV.currentUserId) return;
    try {
      fetch('/api/state', {
        method: 'PUT',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ state: AV.state, baseRevision: AV.api.revision, ownerId: AV.currentUserId })
      }).catch(() => {});
    } catch {}
  };

  AV.updateEnvBadge = () => {
    const el = document.getElementById('envBadge');
    if (!el) return;
    if (AV.api.online && AV.api.authenticated) {
      el.hidden = !AV.api.saveError;
      el.textContent = el.hidden ? '' : 'ALPHA VAULT · ostatni zapis nie powiódł się';
    } else if (AV.api.online) {
      el.hidden = false;
      el.textContent = 'ALPHA VAULT · logowanie przez Discord';
    } else {
      el.hidden = false;
      el.textContent = 'TRYB OFFLINE · dane tylko w tej przeglądarce';
    }
  };

  AV.loadConnections = async () => {
    if (!AV.api.online || !AV.api.authenticated) return false;
    try {
      const res = await AV.apiFetch('/api/connections');
      if (!res.ok) return false;
      const data = await res.json();
      AV.api.integrationsConfigured = data.configured !== false;
      AV.api.metaApiConfigured = !!data.metaApiConfigured;
      const c = AV.connections();
      delete c.weex; delete c.okx;
      c.bingx = { connected: false };
      c.mt = { connected: false, platform: (c.mt && c.mt.platform) || 'mt5', pairCode: (c.mt && c.mt.pairCode) || '' };
      (data.connections || []).forEach((row) => {
        const key = (row.provider === 'mt4' || row.provider === 'mt5') ? 'mt' : row.provider;
        if (key === 'weex' || key === 'okx') return;
        c[key] = {
          connected: row.status === 'connected' || row.status === 'pending',
          pending: row.status === 'pending',
          id: row.id,
          provider: row.provider,
          label: row.label,
          lastSync: row.lastSyncAt,
          imported: row.importedCount,
          masked: row.keyMask,
          perms: row.permissions,
          status: row.status,
          error: row.lastError,
          platform: row.platform || ((c[key] && c[key].platform) || 'mt5'),
          server: row.serverRef || '',
          pairCode: (c[key] && c[key].pairCode) || ''
        };
      });
      if (AV.renderExchangeList) AV.renderExchangeList();
      return true;
    } catch (err) {
      AV.api.lastError = err.message;
      return false;
    }
  };

  AV.reloadStateFromServer = async () => {
    if (!AV.api.online || !AV.api.authenticated || !AV.currentUserId) return false;
    const res = await AV.apiFetch('/api/state');
    if (!res.ok) return false;
    const payload = await res.json();
    if (payload.userId && Number(payload.userId) !== Number(AV.currentUserId)) {
      AV.forceSessionReset('Sesja w tej karcie należy do innego konta. Odśwież stronę.');
      return false;
    }
    if (!payload.state) return false;
    AV.state = AV.normalizeServer(payload.state, { isDemo: !!AV.api.me?.isDemo });
    AV.api.revision = payload.revision || 1;
    const key = AV.stateKey();
    if (key) { try { AV.safeSet(key, JSON.stringify(AV.state)); } catch {} }
    if (AV.renderAll) AV.renderAll();
    return true;
  };

  AV.connectExchange = async (provider, fields) => {
    if (!AV.api.online || !AV.api.authenticated) {
      throw new Error('Połącz się z serwerem, żeby zapisać klucz. Tryb offline nie przyjmuje sekretów.');
    }
    const res = await AV.apiFetch('/api/connections', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ provider, fields })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Nie udało się połączyć (HTTP ' + res.status + ').');
    await AV.loadConnections();
    return data.connection;
  };

  AV.disconnectExchange = async (id) => {
    if (!id) throw new Error('Brak identyfikatora połączenia.');
    const res = await AV.apiFetch('/api/connections/' + encodeURIComponent(id), { method: 'DELETE' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Nie udało się odłączyć.');
    await AV.loadConnections();
    return true;
  };

  AV.syncConnections = async (provider) => {
    const res = await AV.apiFetch('/api/connections/sync', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(provider ? { provider } : {})
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Synchronizacja nie powiodła się.');
    await AV.reloadStateFromServer();
    await AV.loadConnections();
    const results = Array.isArray(data.connections) ? data.connections : [];
    const imported = results.reduce((sum, row) => sum + (Number(row.imported) || 0), 0);
    const failed = results.find((row) => row.error);
    if (failed && failed.message) AV.toast(failed.message, 7000);
    return { ...data, imported };
  };

  AV.pairMt = async (platform) => {
    if (!AV.api.online || !AV.api.authenticated) throw new Error('Połącz się z serwerem, żeby wygenerować kod.');
    const res = await AV.apiFetch('/api/connections/mt/pair', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ platform })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'Nie udało się wygenerować kodu parowania.');
    await AV.loadConnections();
    return data.code;
  };

  AV.serverDemoLogin = async () => {
    const res = await AV.apiFetch('/auth/demo', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ remember: AV.state?.settings?.remember !== false })
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'Nie udało się zalogować demo');
    }
    return res.json();
  };

  AV.serverLogout = async () => {
    try { await AV.apiFetch('/auth/logout', { method: 'POST' }); } catch {}
    AV.purgeAllUserCaches();
    AV.currentUserId = null;
    AV.api.events = null;
    AV.api.eventsSync = null;
    AV.api.eventsLoaded = false;
    AV.api.isAdmin = false;
    AV.api.integrationsConfigured = false;
    AV.api.metaApiConfigured = false;
    AV.api.authenticated = false;
    AV.api.me = null;
    AV.api.ready = false;
    AV.state = AV.buildEmpty();
    AV.logout();
  };

  AV.serverDeleteAccount = async () => {
    const res = await AV.apiFetch('/api/account', { method: 'DELETE' });
    if (!res.ok) throw new Error('Nie udało się usunąć konta');
    AV.purgeAllUserCaches();
    AV.currentUserId = null;
    AV.api.authenticated = false;
    AV.api.me = null;
    AV.api.ready = false;
    AV.state = AV.buildEmpty();
    AV.logout();
    return res.json();
  };

  AV.resetAll = (keepSession = false) => {
    AV.purgeCurrentUserCache();
    const wiped = AV.wipedKey();
    if (wiped) AV.safeSet(wiped, '1');
    if (!keepSession) {
      AV.safeDel(AV.SESSION_KEY);
      try { sessionStorage.removeItem(AV.SESSION_KEY); } catch {}
    }
    AV.state = AV.buildEmpty();
    AV.saveNow();
  };

  AV.resetDemo = () => {
    const wiped = AV.wipedKey();
    const draft = AV.draftKey();
    if (wiped) AV.safeDel(wiped);
    if (draft) AV.safeDel(draft);
    AV.state = AV.normalize(AV.buildDemo());
    AV.saveNow();
    if (AV.renderAll) AV.renderAll();
  };

  /* ---------- sesja ---------- */
  AV.hasLocalSession = () => {
    if (AV.safeGet(AV.SESSION_KEY)) return true;
    try { return !!sessionStorage.getItem(AV.SESSION_KEY); } catch { return false; }
  };
  AV.isLoggedIn = () => {
    if (AV.api.online) return !!AV.api.authenticated;
    if (AV.safeGet(AV.SESSION_KEY)) return true;
    try { return !!sessionStorage.getItem(AV.SESSION_KEY); } catch { return false; }
  };
  AV.login = (remember) => {
    const payload = JSON.stringify({ at: Date.now(), remember: !!remember });
    try { sessionStorage.removeItem(AV.SESSION_KEY); } catch {}
    AV.safeDel(AV.SESSION_KEY);
    if (remember) AV.safeSet(AV.SESSION_KEY, payload);
    else { try { sessionStorage.setItem(AV.SESSION_KEY, payload); } catch {} }
  };
  AV.logout = () => {
    AV.safeDel(AV.SESSION_KEY);
    try { sessionStorage.removeItem(AV.SESSION_KEY); } catch {}
    AV.purgeAllUserCaches();
    AV.currentUserId = null;
    AV.state = AV.buildEmpty();
  };

  /* ---------- kapitał / konta ---------- */
  AV.fxToUsdt = (cur) => cur === 'EUR' ? 1.08 : cur === 'PLN' ? 0.25 : 1;
  AV.totalCapital = (state, includeAll = false) => {
    return (Array.isArray(state?.accounts) ? state.accounts : [])
      .filter((a) => a && (includeAll || a.include))
      .reduce((sum, a) => sum + (Number(a.balance) || 0) * AV.fxToUsdt(a.currency), 0);
  };
  AV.startCapital = (state) => (Array.isArray(state?.accounts) ? state.accounts : [])
    .filter((a) => a && a.include)
    .reduce((sum, a) => sum + (Number(a.start) || 0) * AV.fxToUsdt(a.currency), 0);

  AV.includedAccounts = (state) => (Array.isArray(state?.accounts) ? state.accounts : [])
    .filter((a) => a && a.include).map((a) => a.name);
  AV.includedTrades = (state) => {
    const names = AV.includedAccounts(state);
    return (Array.isArray(state?.trades) ? state.trades : [])
      .filter((t) => t && (!t.account || names.includes(t.account)));
  };
  const REALIZED_EVENTS = Symbol('realizedEvents');
  const finiteStateNumber = (value) => {
    if (value == null || (typeof value === 'string' && value.trim() === '')) return null;
    try {
      const number = Number(value);
      return Number.isFinite(number) ? number : null;
    } catch {
      return null;
    }
  };
  const realizedDate = (value) => {
    if (value == null || value === '') return null;
    if (value instanceof Date) {
      return Number.isFinite(value.getTime())
        ? { key: AV.dateKey(value), at: value.toISOString(), timestamp: value.getTime() }
        : null;
    }
    const raw = String(value).trim();
    const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
    if (day) {
      const year = Number(day[1]), month = Number(day[2]), date = Number(day[3]);
      const check = new Date(Date.UTC(year, month - 1, date));
      if (check.getUTCFullYear() !== year || check.getUTCMonth() !== month - 1 || check.getUTCDate() !== date) return null;
      return { key: raw, at: raw, timestamp: Date.parse(raw) };
    }
    const parsed = new Date(raw);
    if (!Number.isFinite(parsed.getTime())) return null;
    return { key: AV.dateKey(parsed), at: raw, timestamp: parsed.getTime() };
  };
  const eventDate = (values) => {
    for (const value of values) {
      const date = realizedDate(value);
      if (date) return date;
    }
    return null;
  };
  const sumRealizedEvents = (events) => events.reduce((sum, event) => sum + event.result, 0);
  const eventsForTrade = (trade) => {
    if (trade && Array.isArray(trade[REALIZED_EVENTS])) return trade[REALIZED_EVENTS];
    return AV.tradeRealizedEvents(trade);
  };
  const realizedTradeProjection = (trade, events) => {
    const projection = { ...trade, result: sumRealizedEvents(events) };
    Object.defineProperty(projection, REALIZED_EVENTS, { value: events });
    return projection;
  };
  const realizedEventProjection = (trade, event) => {
    const dayOnly = /^\d{4}-\d{2}-\d{2}$/;
    const date = dayOnly.test(event.at || '')
      ? event.at + 'T12:00:00'
      : event.at || (event.dateKey ? event.dateKey + 'T12:00:00' : trade.date);
    const projection = {
      ...trade,
      result: event.result,
      date
    };
    Object.defineProperty(projection, REALIZED_EVENTS, { value: [event] });
    return projection;
  };
  const hasKnownTradeResult = (trade) => {
    if (trade?.result == null) return false;
    if (typeof trade.result === 'string' && trade.result.trim() === '') return false;
    return Number.isFinite(Number(trade.result));
  };
  AV.hasKnownTradeResult = hasKnownTradeResult;
  AV.tradeDirection = (direction) => {
    const value = String(direction ?? '').trim().toLowerCase();
    if (value === 'l' || value === 'long') return 'l';
    if (value === 's' || value === 'short') return 's';
    return null;
  };
  AV.isTradeClosed = (trade) => {
    if (!trade) return false;
    if (trade.result != null && !(typeof trade.result === 'string' &&
      ['', 'brak', '—', '-'].includes(trade.result.trim().toLowerCase()))) return true;
    if (typeof trade.closedAt !== 'string') return false;
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(Z|([+-])(\d{2}):(\d{2}))$/.exec(trade.closedAt);
    if (!match) return false;
    const [, yearText, monthText, dayText, hourText, minuteText, secondText, , , offsetHourText, offsetMinuteText] = match;
    const year = Number(yearText), month = Number(monthText), day = Number(dayText);
    const hour = Number(hourText), minute = Number(minuteText), second = Number(secondText);
    const offsetHour = Number(offsetHourText || 0), offsetMinute = Number(offsetMinuteText || 0);
    if (year < 1 || month < 1 || month > 12 || hour > 23 || minute > 59 || second > 59
      || offsetHour > 14 || offsetMinute > 59 || (offsetHour === 14 && offsetMinute !== 0)) return false;
    const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
    const daysInMonth = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    if (day < 1 || day > daysInMonth[month - 1]) return false;
    return Number.isFinite(Date.parse(trade.closedAt));
  };
  AV.tradeRealizedEvents = (trade) => {
    if (!trade || typeof trade !== 'object') return [];
    const events = [];
    const partials = Array.isArray(trade.partials) ? trade.partials : [];
    let partialTotal = 0;
    partials.forEach((partial) => {
      const result = finiteStateNumber(partial && partial.result);
      if (result == null) return;
      partialTotal += result;
      const date = eventDate([partial.at, trade.date]);
      events.push({
        date: date ? date.key : null, dateKey: date ? date.key : null,
        at: date ? date.at : null, timestamp: date ? date.timestamp : null,
        result, type: 'partial'
      });
    });
    if (AV.isTradeClosed(trade)) {
      const result = finiteStateNumber(trade.result);
      if (result != null) {
        const date = eventDate([trade.closedAt, trade.date]);
        events.push({
          date: date ? date.key : null, dateKey: date ? date.key : null,
          at: date ? date.at : null, timestamp: date ? date.timestamp : null,
          result: result - partialTotal, type: 'close'
        });
      }
    }
    return events.sort((a, b) => {
      const aTime = Number.isFinite(a.timestamp) ? a.timestamp : 0;
      const bTime = Number.isFinite(b.timestamp) ? b.timestamp : 0;
      return aTime - bTime;
    });
  };
  AV.realizedTrades = (state) => AV.includedTrades(state)
    .map((trade) => ({ trade, events: AV.tradeRealizedEvents(trade) }))
    .filter((item) => item.events.length > 0)
    .map((item) => realizedTradeProjection(item.trade, item.events));
  // Zachowuje historyczną nazwę API; obejmuje teraz także otwarte pozycje z częściowo zrealizowanym P&L.
  AV.closedTrades = (state) => AV.realizedTrades(state);
  AV.lifecycleClosedTrades = (state) => AV.includedTrades(state).filter((trade) => AV.isTradeClosed(trade));
  AV.openTrades = (state) => AV.includedTrades(state).filter((t) => !AV.isTradeClosed(t));
  AV.calculatedEquity = (state) => {
    const names = new Set(AV.includedAccounts(state));
    const tradePnl = AV.includedTrades(state).reduce((sum, trade) =>
      sum + sumRealizedEvents(AV.tradeRealizedEvents(trade)), 0);
    const flowNet = (Array.isArray(state?.flows) ? state.flows : []).reduce((sum, flow) => {
      if (!flow || flow.account == null || flow.account === '' || !names.has(flow.account)) return sum;
      const amount = finiteStateNumber(flow.amount);
      if (amount == null) return sum;
      if (flow.type === 'Wpłata') return sum + Math.abs(amount);
      if (flow.type === 'Wypłata') return sum - Math.abs(amount);
      return sum;
    }, 0);
    return AV.startCapital(state) + tradePnl + flowNet;
  };

  AV.rangeTrades = (state, days) => {
    if (!days || days === 'all') return AV.realizedTrades(state);
    const count = Number(days);
    if (!Number.isFinite(count) || count <= 0) return AV.realizedTrades(state);
    const from = AV.dateKey(AV.startOfDay(AV.addDays(new Date(), -count + 1)));
    return AV.includedTrades(state).flatMap((trade) => {
      const events = AV.tradeRealizedEvents(trade)
        .filter((event) => event.dateKey && event.dateKey >= from);
      return events.length ? [realizedTradeProjection(trade, events)] : [];
    });
  };

  /* ---------- statystyki ---------- */
  AV.computeStats = (inputTrades, state) => {
    const trades = (Array.isArray(inputTrades) ? inputTrades : [])
      .map((trade) => realizedTradeProjection(trade, eventsForTrade(trade)));
    const n = trades.length;
    const wins = trades.filter((t) => t.result > 0);
    const losses = trades.filter((t) => t.result < 0);
    const net = trades.reduce((s, t) => s + (Number(t.result) || 0), 0);
    const gp = wins.reduce((s, t) => s + AV.num(t.result), 0);
    const gl = Math.abs(losses.reduce((s, t) => s + AV.num(t.result), 0));
    const rs = trades.filter((t) => t.r != null);
    const expR = rs.length ? rs.reduce((s, t) => s + AV.num(t.r), 0) / rs.length : 0;
    const avgWin = wins.length ? gp / wins.length : 0;
    const avgLoss = losses.length ? -gl / losses.length : 0;
    const start = AV.startCapital(state) || 0;
    let eq = start, peak = start, maxDD = 0, maxDDpct = 0;
    const curve = [{ x: 0, eq: start, date: null }];
    const curveEvents = trades.flatMap((trade, tradeIndex) =>
      eventsForTrade(trade).map((event, eventIndex) => ({ event, tradeIndex, eventIndex })))
      .sort((a, b) => {
        const aTime = Number.isFinite(a.event.timestamp) ? a.event.timestamp : 0;
        const bTime = Number.isFinite(b.event.timestamp) ? b.event.timestamp : 0;
        return aTime - bTime || a.tradeIndex - b.tradeIndex || a.eventIndex - b.eventIndex;
      });
    curveEvents.forEach(({ event }, i) => {
      eq += event.result;
      peak = Math.max(peak, eq);
      const dd = eq - peak;
      if (dd < maxDD) maxDD = dd;
      if (peak > 0) maxDDpct = Math.min(maxDDpct, dd / peak * 100);
      curve.push({ x: i + 1, eq, date: event.date });
    });
    const byGrade = AV.GRADES.map((g) => {
      const arr = trades.filter((t) => t.grade === g);
      const w = arr.filter((t) => t.result > 0).length;
      return { grade: g, n: arr.length, wr: arr.length ? w / arr.length * 100 : 0, net: arr.reduce((s, t) => s + AV.num(t.result), 0) };
    });
    const group = (key) => {
      const map = {};
      trades.forEach((t) => {
        const k = t[key] || '—';
        map[k] = map[k] || { key: k, n: 0, net: 0, wins: 0 };
        map[k].n++;
        map[k].net += AV.num(t.result);
        if (t.result > 0) map[k].wins++;
      });
      return Object.values(map).sort((a, b) => b.net - a.net);
    };
    const checklistTrades = trades.filter((t) => t.checklist != null);
    const checklistTotal = (trade) => Number(trade.checkTotal) > 0 ? Number(trade.checkTotal) : 7;
    const full = checklistTrades.filter((t) => t.checklist >= checklistTotal(t));
    const part = checklistTrades.filter((t) => t.checklist < checklistTotal(t));
    const slTrades = trades.filter((t) => t.sl && t.sl !== 'brak');
    const planTrades = trades.filter((t) => AV.tradePlanMatch(state, t));
    const finiteValue = (value) => {
      if (value == null || (typeof value === 'string' && value.trim() === '')) return null;
      const number = Number(value);
      return Number.isFinite(number) ? number : null;
    };
    const optionalMetric = (value) => {
      const number = finiteValue(value);
      return number == null || number < 0 ? null : number;
    };
    const feeValues = trades.map((trade) => finiteValue(trade.fee));
    const fees = trades.length > 0 && feeValues.every((value) => value !== null)
      ? feeValues.reduce((sum, value) => sum + value, 0)
      : null;
    const errMap = {};
    let unpricedEarlyExits = 0;
    trades.forEach((t) => {
      (t.errors || []).forEach((e) => {
        const positiveEarlyExit = e === 'Za wczesne wyjście' && Number(t.result) > 0;
        const realizedR = finiteValue(t.r);
        const mfe = optionalMetric(t.mfe);
        if (positiveEarlyExit && (
          realizedR == null || !Number.isFinite(realizedR) || realizedR === 0 ||
          mfe == null || !Number.isFinite(mfe) || mfe < 0
        )) {
          unpricedEarlyExits++;
          return;
        }
        errMap[e] = errMap[e] || { key: e, n: 0, cost: 0 };
        errMap[e].n++;
        if (positiveEarlyExit) {
          const risk = Math.abs(Number(t.result) / realizedR);
          errMap[e].cost += Number(t.result) - mfe * risk;
        } else {
          errMap[e].cost += Number(t.result) || 0;
        }
      });
    });
    const errors = Object.values(errMap).sort((a, b) => a.cost - b.cost);
    const avg = (arr, f) => {
      const values = arr.map((trade) => finiteValue(f(trade))).filter((value) => value != null);
      return values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : null;
    };
    const measuredWins = wins.filter((trade) =>
      optionalMetric(trade.mae) != null && optionalMetric(trade.mfe) != null &&
      finiteValue(trade.r) != null && Number(trade.r) !== 0
    );
    return {
      n, wins: wins.length, losses: losses.length, winRate: n ? wins.length / n * 100 : 0,
      net, gp, gl, pf: gl ? gp / gl : gp ? 99 : 0, expR, avgWin, avgLoss,
      maxDD, maxDDpct, curve, byGrade, bySession: group('session'), byEmotion: group('emotion'),
      bySetup: group('setup'), errors, unpricedEarlyExits,
      withSL: n ? slTrades.length / n * 100 : 0,
      withSLCount: slTrades.length,
      planRate: n ? planTrades.length / n * 100 : 0,
      planCount: planTrades.length,
      fees,
      full: { n: full.length, wr: full.length ? full.filter((t) => t.result > 0).length / full.length * 100 : 0, net: full.reduce((s, t) => s + AV.num(t.result), 0) },
      part: { n: part.length, wr: part.length ? part.filter((t) => t.result > 0).length / part.length * 100 : 0, net: part.reduce((s, t) => s + AV.num(t.result), 0) },
      avgMaeWin: avg(measuredWins, (t) => optionalMetric(t.mae)),
      avgMfeWin: avg(measuredWins, (t) => optionalMetric(t.mfe)),
      avgExitR: avg(measuredWins, (t) => t.r), maeSampleCount: measuredWins.length,
      avgDurWin: avg(wins, (t) => t.durationMin), avgDurLoss: avg(losses, (t) => t.durationMin),
      shiftedSL: trades.filter((t) => (t.errors || []).includes('Przesunięty SL')).length,
      daily: AV.dailyMap(trades)
    };
  };
  AV.rangeStats = (state, days) => AV.computeStats(AV.rangeTrades(state, days), state);

  AV.dailyMap = (trades) => {
    const map = {};
    (Array.isArray(trades) ? trades : []).forEach((trade) => {
      eventsForTrade(trade).forEach((event) => {
        if (!event.dateKey) return;
        map[event.dateKey] = (map[event.dateKey] || 0) + event.result;
      });
    });
    return map;
  };

  AV.streak = (state) => {
    const days = new Set(AV.includedTrades(state).map((t) => AV.dateKey(new Date(t.date))));
    let d = AV.startOfDay(new Date()), n = 0;
    while (days.has(AV.dateKey(d)) && n < 400) { n++; d = AV.addDays(d, -1); }
    return n;
  };

  AV.todayTrades = (state) => {
    const todayKey = AV.dateKey(new Date());
    return AV.includedTrades(state).filter((trade) => {
      const date = realizedDate(trade.date);
      return date && date.key === todayKey;
    });
  };
  AV.todayStats = (state) => {
    const arr = AV.todayTrades(state);
    const todayKey = AV.dateKey(new Date());
    const closed = AV.includedTrades(state).flatMap((trade) => {
      return AV.tradeRealizedEvents(trade)
        .filter((event) => event.dateKey === todayKey)
        .map((event) => realizedEventProjection(trade, event));
    });
    const pnl = closed.reduce((sum, trade) => sum + AV.num(trade.result), 0);
    const losses = closed.filter((t) => t.result < 0).length;
    const risk = AV.openTrades(state).reduce((s, t) => {
      if (AV.isTradeClosed(t)) return s;
      if (t.result != null && t.r) return s + Math.abs(t.result / t.r);
      const entry = AV.pnum(t.entry), sl = AV.pnum(t.sl);
      const size = AV.pnum(t.size);
      return s + (sl ? Math.abs(entry - sl) * size : 0);
    }, 0);
    return { arr, closed, pnl, losses, count: arr.length, risk };
  };

  /* ---------- tryb ochronny ---------- */
  AV.lockInfo = (state) => {
    const today = AV.todayStats(state);
    const set = state.settings;
    const limit = Math.max(1, Number(set.maxLosses) || 2);
    const dailyLimit = Math.max(0.1, Number(set.maxDailyLoss) || 3);
    const capital = AV.totalCapital(state) || 1;
    const lossPct = Math.max(0, -Math.min(0, today.pnl)) / capital * 100;
    const triggered = set.safetyEnabled && (today.losses >= limit || lossPct >= dailyLimit);
    let until = null;
    if (triggered) {
      const lastLoss = today.closed.filter((t) => t.result < 0).sort((a, b) => new Date(b.date) - new Date(a.date))[0];
      const base = lastLoss ? new Date(lastLoss.date) : new Date();
      if (set.block === '1h') until = new Date(base.getTime() + 3600e3);
      else if (set.block === '4h') until = new Date(base.getTime() + 4 * 3600e3);
      else { until = AV.startOfDay(AV.addDays(new Date(), 1)); }
      if (until <= new Date()) until = AV.startOfDay(AV.addDays(new Date(), 1));
    }
    const override = (state.overrides || []).filter((o) => o.date === AV.dateKey(new Date())).sort((a, b) => b.at - a.at)[0];
    const overridden = !!(override && (!triggered || override.at >= (until ? until.getTime() - 24 * 3600e3 : 0)));
    return {
      active: triggered && !overridden,
      triggered, overridden, until, override,
      losses: today.losses, limit, lossPct, dailyLimit,
      reason: triggered ? (today.losses >= limit ? 'limit strat' : 'limit straty dziennej') : null
    };
  };

  AV.addOverride = (reason) => {
    AV.state.overrides = AV.state.overrides || [];
    AV.state.overrides.push({ date: AV.dateKey(new Date()), at: Date.now(), reason: reason || 'bez podania powodu' });
    AV.saveNow();
  };

  /* ---------- forma ---------- */
  AV.sleepLabel = (minutes) => {
    if (minutes == null || !Number.isFinite(Number(minutes))) return '';
    const total = Math.max(0, Math.round(Number(minutes)));
    return Math.floor(total / 60) + ' h ' + String(total % 60).padStart(2, '0') + ' min';
  };

  AV.readiness = (state) => {
    const check = (state.checkins || []).filter((c) => c.date === AV.dateKey(new Date())).sort((a, b) => b.score - a.score)[0];
    const moodMod = { 'Spokój': 10, 'Skupienie': 7, 'Pewność': 5, 'Nuda': -3, 'Zmęczenie': -8, 'Strach': -10, 'FOMO': -12, 'Złość': -14, 'Chciwość': -6 }[check?.mood] || 0;
    const checkScore = check ? Math.round(AV.clamp(50 + ((Number(check.energy) || 3) - 3) * 10 + moodMod, 0, 100)) : null;
    const w = AV.api.wearables;
    const components = w?.readiness?.components || {};
    const physiology = components.physiology != null ? Number(components.physiology) : null;
    const today = w?.today || null;
    let score = null;
    let source = 'none';
    if (physiology != null && checkScore != null) { score = Math.round(physiology * 0.7 + checkScore * 0.3); source = 'devices+checkin'; }
    else if (physiology != null) { score = Math.round(physiology); source = 'devices'; }
    else if (checkScore != null) { score = checkScore; source = 'checkin'; }

    let title = '', cls = '';
    if (score != null) {
      title = 'Świetna forma'; cls = 'win';
      if (score < 80) { title = 'Dobra forma'; cls = 'win'; }
      if (score < 65) { title = 'Ostrożnie dziś'; cls = 'warnc'; }
      if (score < 50) { title = 'Lepiej odpuścić'; cls = 'loss'; }
    }

    let desc = '';
    if (physiology != null && today) {
      const bits = [];
      if (today.sleepMinutes != null) bits.push('Sen ' + AV.sleepLabel(today.sleepMinutes));
      if (today.hrvMs != null) bits.push('HRV ' + Math.round(Number(today.hrvMs)) + ' ms');
      if (today.restingHr != null) bits.push('tętno ' + today.restingHr);
      if (bits.length) {
        desc = bits.join(', ') + '. ' + (score != null && score < 60 ? 'Połowa normalnej pozycji albo odpuść.' : 'Możesz grać normalnie, ale trzymaj się planu.');
      }
    } else if (checkScore != null) {
      desc = 'Gotowość liczona z check-inu: nastrój i energia. Podłącz urządzenie, żeby dodać sen, HRV i tętno.';
    }
    return { score, title, cls, desc, source, physiology, checkScore, check, today, components };
  };

  /* ---------- podatki ---------- */
  AV.taxYear = (state) => {
    const y = Number(state.tax && state.tax.year);
    return y >= 2000 ? Math.trunc(y) : new Date().getFullYear();
  };
  AV.isForexTrade = (trade, state) => {
    const id = String(trade.account || '');
    const matched = (state.accounts || []).filter((a) => a.id === id || a.name === id ||
      (a.name && id.toLowerCase().includes(String(a.name).toLowerCase())));
    const key = [trade.source, trade.account]
      .concat(matched.reduce((all, a) => all.concat([a.exchange, a.type, a.name]), []))
      .filter(Boolean).join(' ');
    return /(^|[^a-z0-9])(mt4|mt5|metatrader|forex|cfd)/i.test(key);
  };
  // Zdarzenie bez daty lub bez kursu waluty obcej nie może zasilać
  // zestawienia — poprzednio liczone było po kursie 1:1 w każdym roku.
  AV.taxFlowIssue = (f, year) => {
    const date = String((f && f.date) || '');
    if (!/^\d{4}-\d{2}-\d{2}/.test(date)) return 'bez daty';
    if (Number(date.slice(0, 4)) !== year) return null;
    if (!(AV.pnum(f.fiat) > 0)) return 'z nieprawidłową kwotą';
    if ((f.currency || 'PLN') !== 'PLN' && !(AV.pnum(f.fx) > 0)) return 'bez kursu NBP';
    return null;
  };
  AV.taxEvents = (state) => {
    const year = AV.taxYear(state);
    return (state.flows || [])
      .filter((f) => {
        const date = String(f.date || '');
        return /^\d{4}-\d{2}-\d{2}/.test(date) && Number(date.slice(0, 4)) === year && !AV.taxFlowIssue(f, year);
      })
      .map((f) => {
        const currency = f.currency || 'PLN';
        const fx = currency === 'PLN' ? 1 : AV.pnum(f.fx);
        const amount = AV.pnum(f.fiat);
        const kind = f.tax === 'income' || f.tax === 'cost' ? f.tax : (f.type === 'Wpłata' ? 'cost' : 'income');
        return {
          id: f.id, date: f.date,
          op: (f.type === 'Wpłata' ? 'Zakup USDT za ' : 'Sprzedaż USDT za ') + currency + (f.op && f.op.includes('P2P') ? ' (P2P)' : ''),
          kind, currency, amount, fx,
          pln: Math.round(amount * fx * 100) / 100
        };
      })
      .filter((e) => e.kind === 'income' || e.kind === 'cost')
      .sort((a, b) => String(a.date).localeCompare(String(b.date)));
  };
  AV.taxData = (state) => {
    const year = AV.taxYear(state);
    const ev = AV.taxEvents(state);
    const sum = (kind) => Math.round(ev.filter((e) => e.kind === kind).reduce((s, e) => s + e.pln, 0) * 100) / 100;
    const revenue = sum('income');
    const costs = sum('cost');
    const carry = Math.max(0, Math.round(AV.pnum(state.tax && state.tax.carryLoss) * 100) / 100);
    const income = Math.max(0, Math.round((revenue - costs - carry) * 100) / 100);
    const due = Math.round(income * 0.19 * 100) / 100;
    const carryForward = Math.max(0, Math.round((costs + carry - revenue) * 100) / 100);
    const invalidFlows = (state.flows || []).filter((f) => !!AV.taxFlowIssue(f, year)).length;
    const trades = (state.trades || []).filter((t) => String(t.date || '').slice(0, 4) === String(year));
    const forex = trades.filter((t) => AV.isForexTrade(t, state)).length;
    return { year, ev, revenue, costs, carry, income, due, carryForward, invalidFlows, neutral: trades.length - forex, forex, trades: trades.length };
  };

  /* ---------- mentor ---------- */
  AV.mentorData = (state) => {
    const closed = AV.includedTrades(state).flatMap((trade) =>
      AV.tradeRealizedEvents(trade).map((event) => realizedEventProjection(trade, event)));
    const today = AV.todayStats(state);
    const lock = AV.lockInfo(state);
    const todayKey = AV.dateKey(new Date());
    const todayPlans = AV.plansForDate(state, todayKey);
    const todayPlanTrades = today.arr;
    const planOK = todayPlans.length ? todayPlanTrades.filter((t) => AV.tradePlanMatch(state, t)).length : 0;
    const afterLoss = [];
    closed.slice().sort((a, b) => new Date(a.date) - new Date(b.date)).forEach((t, i, arr) => {
      if (i === 0) return;
      const prev = arr[i - 1];
      const gap = (new Date(t.date) - new Date(prev.date)) / 60000;
      if (prev.result < 0 && gap >= 0 && gap <= 30) afterLoss.push(t);
    });
    const fomo = closed.filter((t) => t.emotion === 'FOMO');
    const c = closed.filter((t) => t.grade === 'C');
    const a = closed.filter((t) => t.grade === 'A');
    const byPair = {};
    closed.forEach((t) => { byPair[t.pair] = (byPair[t.pair] || 0) + AV.num(t.result); });
    const pairSorted = Object.entries(byPair).sort((x, y) => x[1] - y[1]);
    const worstPair = pairSorted[0] || ['—', 0];
    const bestPair = pairSorted[pairSorted.length - 1] || ['—', 0];
    const hours = {};
    closed.forEach((t) => {
      const h = new Date(t.date).getHours();
      const bucket = h >= 21 || h < 5 ? 'po 21:00' : h < 9 ? 'przed 9:00' : h < 13 ? '9:00–13:00' : h < 18 ? '13:00–18:00' : '18:00–21:00';
      hours[bucket] = hours[bucket] || { n: 0, w: 0 };
      hours[bucket].n++;
      if (t.result > 0) hours[bucket].w++;
    });
    const worstHour = Object.entries(hours).sort((x, y) => (x[1].w / x[1].n) - (y[1].w / y[1].n))[0];
    const checklistTrades = closed.filter((t) => t.checklist != null && t.checklist !== '');
    const checklistTotal = (trade) => Number(trade.checkTotal) > 0 ? Number(trade.checkTotal) : 7;
    const full = checklistTrades.filter((t) => Number(t.checklist) >= checklistTotal(t));
    const part = checklistTrades.filter((t) => Number(t.checklist) < checklistTotal(t));
    const events = AV.buildEvents();
    const next = events.filter((e) => e.imp === 3 && new Date(e.date) > new Date()).sort((a, b) => new Date(a.date) - new Date(b.date))[0];
    const macroDays = new Set(events.filter((e) => e.imp === 3).map((e) => AV.dateKey(new Date(e.date))));
    const macroTrades = AV.includedTrades(state).flatMap((trade) => {
      const realized = AV.tradeRealizedEvents(trade)
        .filter((event) => event.dateKey && macroDays.has(event.dateKey));
      return realized.length ? [realizedTradeProjection(trade, realized)] : [];
    });
    const macroNet = macroTrades.reduce((s, t) => s + AV.num(t.result), 0);
    const r = AV.readiness(state);
    return {
      imie: state.user.name || 'Trader',
      straty: today.losses, limit: lock.limit, wynikDzis: AV.money(today.pnl, 2, ''),
      poStracie: afterLoss.filter((t) => t.result < 0).length + ' z ' + afterLoss.length,
      poStracieWynik: AV.money(afterLoss.reduce((s, t) => s + AV.num(t.result), 0), 2, ''),
      fomoWr: fomo.length ? Math.round(fomo.filter((t) => t.result > 0).length / fomo.length * 100) : 0,
      fomoN: fomo.length, fomoWynik: AV.money(fomo.reduce((s, t) => s + AV.num(t.result), 0), 2, ''),
      wr: closed.length ? Math.round(closed.filter((t) => t.result > 0).length / closed.length * 100) : 0,
      para: worstPair[0], paraWynik: AV.money(worstPair[1], 2, ''),
      dobraPara: bestPair[0], dobraWynik: AV.money(bestPair[1], 2, ''),
      godz: worstHour ? worstHour[0] : 'po 21:00', godzWr: worstHour ? Math.round(worstHour[1].w / worstHour[1].n * 100) : 0,
      cWynik: AV.money(c.reduce((s, t) => s + AV.num(t.result), 0), 2, ''), cN: c.length,
      aWr: a.length ? Math.round(a.filter((t) => t.result > 0).length / a.length * 100) : 0,
      planZgodne: planOK + ' z ' + todayPlanTrades.length,
      gotowosc: r.score == null ? '' : r.score,
      clFull: full.length ? Math.round(full.filter((t) => t.result > 0).length / full.length * 100) : 0,
      clNo: part.length ? Math.round(part.filter((t) => t.result > 0).length / part.length * 100) : 0,
      tr: today.count, event: next ? next.name : '',
      eventTime: next ? AV.timeFmt(next.date, state) : '—',
      eventWhen: next ? AV.eventStatus(next.date)[1] : 'do końca tygodnia',
      macroWynik: AV.money(macroNet, 2, ''), macroN: macroTrades.length,
      kapital: AV.fmt(AV.totalCapital(state), 2), ryzyko1: AV.fmt(AV.totalCapital(state) * (Number(state.settings.maxRisk) || 1) / 100, 2)
    };
  };

  AV.fill = (text, state, extra = {}) => {
    const d = { ...AV.mentorData(state), ...extra };
    return String(text).replace(/\{(\w+)\}/g, (_, k) => d[k] != null ? d[k] : '');
  };

  /* ---------- playbook ---------- */
  AV.setupStats = (state, name) => {
    const trades = AV.closedTrades(state).filter((t) => t.setup === name);
    const wins = trades.filter((t) => t.result > 0);
    const net = trades.reduce((s, t) => s + AV.num(t.result), 0);
    const avgR = trades.filter((t) => t.r != null).length
      ? trades.filter((t) => t.r != null).reduce((s, t) => s + AV.num(t.r), 0) / trades.filter((t) => t.r != null).length : 0;
    return { n: trades.length, wr: trades.length ? wins.length / trades.length * 100 : 0, net, avgR };
  };

  /* ---------- badge ---------- */
  const badgeDay = (t) => AV.dateKey(new Date(t.date));
  const badgeDayList = (values) => [...new Set(values.filter(Boolean))].sort();
  const badgeRuns = (keys) => {
    const runs = [];
    let start = null, prev = null, length = 0;
    badgeDayList(keys).forEach((k) => {
      const d = new Date(k + 'T12:00:00');
      if (prev && Math.round((d - prev) / 86400000) === 1) length++;
      else { start = k; length = 1; }
      runs.push({ start, end: k, length });
      prev = d;
    });
    return runs;
  };
  const badgeBest = (keys) => badgeRuns(keys).reduce((best, run) => Math.max(best, run.length), 0);
  const badgeFirst = (keys, target) => {
    const run = badgeRuns(keys).find((r) => r.length >= target);
    return run ? run.end : null;
  };
  const badgeBestFlagged = (keys, isGood) => {
    let best = 0, run = 0;
    badgeDayList(keys).forEach((k) => {
      if (isGood(k)) { run++; best = Math.max(best, run); }
      else run = 0;
    });
    return best;
  };
  const badgeFirstFlagged = (keys, isGood, target) => {
    let run = 0;
    for (const k of badgeDayList(keys)) {
      if (isGood(k)) {
        run++;
        if (run >= target) return k;
      } else run = 0;
    }
    return null;
  };
  const badgeHintDate = (key) => key ? AV.dateShort(new Date(key + 'T12:00:00')) : '';

  AV.badgeSummary = (list) => {
    const badges = Array.isArray(list) ? list : [];
    const done = badges.filter((b) => b.done).length;
    return { done, total: badges.length, level: Math.min(5, 1 + Math.floor(done / 2)) };
  };

  AV.badges = (state) => {
    const s = state || {};
    const trades = Array.isArray(s.trades) ? s.trades : [];
    const checkins = Array.isArray(s.checkins) ? s.checkins : [];
    const accounts = Array.isArray(s.accounts) ? s.accounts : [];
    const closed = AV.closedTrades({ ...s, trades, accounts });
    const plans = Object.values(s.plans || {}).filter((p) => p && typeof p === 'object');
    const approvedPlans = plans
      .filter((p) => p.approvedAt)
      .sort((a, b) => new Date(a.approvedAt) - new Date(b.approvedAt));
    const planDays = badgeDayList(approvedPlans.map((p) => p.date || badgeDay({ date: p.approvedAt })));
    const tradeDays = badgeDayList(closed.map(badgeDay));
    const journalDays = badgeDayList([
      ...trades.map(badgeDay),
      ...checkins.map((c) => c.date)
    ]);
    const slTrades = closed
      .filter((t) => t.sl != null && String(t.sl).trim() !== '' && String(t.sl).toLowerCase() !== 'brak')
      .sort((a, b) => new Date(a.date) - new Date(b.date));
    const aTrades = closed
      .filter((t) => t.grade === 'A')
      .sort((a, b) => new Date(a.date) - new Date(b.date));

    const bestJournal = badgeBest(journalDays);
    const firstJournal7 = badgeFirst(journalDays, 7);

    // Dni bez transakcji (np. weekend) nie przerywają serii; przerywa ją dzień
    // handlowy z FOMO. Liczymy tylko dni z transakcjami, nie check-iny.
    const fomoDays = new Set(closed.filter((t) => t.emotion === 'FOMO').map(badgeDay));
    const fomoClean = (k) => !fomoDays.has(k);
    const bestFomoFree = badgeBestFlagged(tradeDays, fomoClean);
    const firstFomo10 = badgeFirstFlagged(tradeDays, fomoClean, 10);

    // Analogicznie: 5 kolejnych dni z zatwierdzonym planem i czystym handlem.
    // Dni bez planu ani transakcji nie przerywają serii.
    const breakErrors = ['Bez SL', 'Przesunięty SL', 'Za duża pozycja', 'Handel przy danych', 'Wejście po stracie'];
    const activeDays = badgeDayList([...planDays, ...tradeDays]);
    const planDayClean = (k) => {
      if (!planDays.includes(k)) return false;
      return closed
        .filter((t) => badgeDay(t) === k)
        .every((t) => t.grade !== 'C' && AV.tradePlanMatch(s, t) && !(t.errors || []).some((e) => breakErrors.includes(e)));
    };
    const bestCleanWeek = badgeBestFlagged(activeDays, planDayClean);
    const firstCleanWeek = badgeFirstFlagged(activeDays, planDayClean, 5);

    const monthKey = (value) => {
      const d = new Date(value);
      return d.getFullYear() + '-' + AV.pad(d.getMonth() + 1);
    };
    const nowMonth = monthKey(new Date());
    const months = {};
    closed.forEach((t) => {
      const k = monthKey(t.date);
      if (!k || k === 'NaN-NaN' || k === nowMonth) return;
      if (!months[k]) months[k] = { net: 0, hasC: false, n: 0 };
      months[k].net += Number(t.result) || 0;
      months[k].n++;
      if (t.grade === 'C') months[k].hasC = true;
    });
    const monthKeys = Object.keys(months).sort();
    const lastMonth = monthKeys.length ? monthKeys[monthKeys.length - 1] : null;
    const lastMonthData = lastMonth ? months[lastMonth] : null;
    const monthDone = !!lastMonthData && lastMonthData.net > 0 && !lastMonthData.hasC;
    const monthLabel = lastMonth ? new Date(lastMonth + '-01T12:00:00').toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' }) : '';
    const monthProgress = lastMonthData
      ? (lastMonthData.net > 0 ? 50 : 0) + (lastMonthData.hasC ? 0 : 40) + 10
      : 0;

    return [
      {
        icon: 'plan', name: 'Pierwszy plan dnia',
        done: approvedPlans.length > 0,
        hint: approvedPlans.length ? badgeHintDate(planDays[0]) : '0 / 1',
        progress: approvedPlans.length ? 100 : 0
      },
      {
        icon: 'book', name: '7 dni z dziennikiem',
        done: bestJournal >= 7,
        hint: bestJournal >= 7 ? badgeHintDate(firstJournal7) : 'najdłużej: ' + bestJournal + ' dni',
        progress: AV.clamp(bestJournal / 7 * 100, 0, 100)
      },
      {
        icon: 'shield', name: '50 × ze stop lossem',
        done: slTrades.length >= 50,
        hint: slTrades.length >= 50 ? badgeHintDate(badgeDay(slTrades[49])) : slTrades.length + ' / 50',
        progress: AV.clamp(slTrades.length / 50 * 100, 0, 100)
      },
      {
        icon: 'check', name: 'Tydzień bez złamania zasad',
        done: bestCleanWeek >= 5,
        hint: bestCleanWeek >= 5 ? badgeHintDate(firstCleanWeek) : bestCleanWeek + ' / 5 dni',
        progress: AV.clamp(bestCleanWeek / 5 * 100, 0, 100)
      },
      {
        icon: 'plan', name: '30 planów dnia',
        done: approvedPlans.length >= 30,
        hint: approvedPlans.length >= 30 ? badgeHintDate(planDays[29]) : approvedPlans.length + ' / 30',
        progress: AV.clamp(approvedPlans.length / 30 * 100, 0, 100)
      },
      {
        icon: 'star', name: '100 transakcji A',
        done: aTrades.length >= 100,
        hint: aTrades.length >= 100 ? badgeHintDate(badgeDay(aTrades[99])) : aTrades.length + ' / 100',
        progress: AV.clamp(aTrades.length / 100 * 100, 0, 100)
      },
      {
        icon: 'pulse', name: '10 dni bez FOMO',
        done: bestFomoFree >= 10,
        hint: bestFomoFree >= 10 ? badgeHintDate(firstFomo10) : bestFomoFree + ' / 10',
        progress: AV.clamp(bestFomoFree / 10 * 100, 0, 100)
      },
      {
        icon: 'stats', name: 'Miesiąc na plusie bez C',
        done: monthDone,
        hint: monthDone ? monthLabel : 'w trakcie',
        progress: monthDone ? 100 : AV.clamp(monthProgress, 0, 100)
      }
    ];
  };

  /* ---------- plan dnia ---------- */
  AV.planTemplate = (dateKey) => ({
    id: null, date: dateKey, market: '', bias: '', why: '',
    levels: [{ type: 'Opór', price: '', note: '' }],
    ifMain: '', thenMain: '', ifAlt: '', thenAlt: '',
    risk: '', maxTrades: '', until: '', notDo: '',
    approvedAt: null
  });
  AV.marketMatchesPlan = (plan, pair) => {
    if (!plan) return false;
    const normalize = (value) => {
      const raw = String(value || '').trim().toUpperCase()
        .replace(/\.(?:R|RAW|PRO|ECN|CASH|SPOT|M)$/i, '')
        .replace(/[_-]/g, '/');
      const parts = raw.split('/').filter(Boolean);
      if (parts.length > 1) {
        if (parts[1] === 'SWAP' || parts[1] === 'PERP') return parts[0];
        return parts[0] + '/' + parts[1];
      }
      const flat = parts[0] || raw;
      for (const quote of ['USDT', 'USDC', 'BUSD', 'FDUSD', 'USD', 'EUR', 'GBP', 'JPY', 'PLN', 'BTC', 'ETH']) {
        if (flat.endsWith(quote) && flat.length > quote.length) {
          return flat.slice(0, -quote.length) + '/' + quote;
        }
      }
      return flat;
    };
    const normalizedPair = normalize(pair);
    if (!normalizedPair) return false;
    const pairBase = normalizedPair.split('/')[0];
    const markets = [
      ...(Array.isArray(plan.markets) ? plan.markets : []),
      plan.market
    ].map((market) => String(market || '').trim().toUpperCase()).filter(Boolean);
    return markets.some((market) => {
      const normalizedMarket = normalize(market);
      return normalizedMarket === normalizedPair ||
        (!normalizedMarket.includes('/') && normalizedMarket === pairBase);
    });
  };
  AV.planMatchesTrade = (plan, pair, dir) => {
    if (!plan || !plan.approvedAt || !AV.marketMatchesPlan(plan, pair)) return false;
    const direction = AV.tradeDirection(dir);
    if (plan.bias === 'long') return direction === 'l';
    if (plan.bias === 'short') return direction === 's';
    return false;
  };
  AV.tradePlanMatch = (state, trade) => {
    if (!trade) return false;
    const plans = AV.plansForDate(state, AV.dateKey(new Date(trade.date)));
    if (plans.length === 0) return false;
    if (plans.length === 1) return AV.planMatchesTrade(plans[0], trade.pair, trade.dir);
    const marketPlans = plans.filter((plan) => AV.marketMatchesPlan(plan, trade.pair));
    return marketPlans.length === 1 && AV.planMatchesTrade(marketPlans[0], trade.pair, trade.dir);
  };
  AV.planAdherence = (state) => {
    const days = [];
    let approved = 0;
    for (let i = 1; i <= 5; i++) {
      const d = AV.addDays(new Date(), -i);
      const p = AV.plansForDate(state, AV.dateKey(d));
      const ok = p.some((plan) => plan.approvedAt);
      if (ok) approved++;
      days.push({ d, ok });
    }
    return { approved, total: 5, days: days.reverse() };
  };
})();
