/* Alpha Vault — interakcje i inicjalizacja */
(function () {
  'use strict';
  const AV = window.AV, $ = AV.$, $$ = AV.$$;

  /* ---------- routing ---------- */
  AV.views = ['pulpit', 'plan', 'trans', 'play', 'nowa', 'kalk', 'prop', 'akad', 'stat', 'tax', 'mentor', 'kal', 'cialo', 'gieldy', 'ust'];
  /* Czytelne adresy podstron: id widoku -> slug w URL (bez znaków diakrytycznych). */
  AV.viewSlugs = {
    pulpit: 'pulpit',
    plan: 'plan',
    trans: 'dziennik',
    play: 'playbook',
    nowa: 'nowa-transakcja',
    kalk: 'kalkulator',
    prop: 'prop-challenge',
    akad: 'akademia',
    stat: 'statystyki',
    tax: 'podatki',
    mentor: 'mentor-ai',
    kal: 'kalendarz',
    cialo: 'cialo-i-glowa',
    gieldy: 'gieldy',
    ust: 'konto'
  };
  /* Slug -> id widoku. Stare krótkie adresy (#/akad) nadal działają. */
  AV.slugViews = {};
  AV.views.forEach((id) => { AV.slugViews[AV.viewSlugs[id] || id] = id; AV.slugViews[id] = id; });
  ['plan3', 'dziennik3', 'playbook3', 'akad3', 'stat3', 'kalk3'].forEach((legacy) => {
    const base = legacy.replace(/3$/, '');
    const id = AV.slugViews[base];
    if (id) AV.slugViews[legacy] = id;
  });
  AV.viewSlug = (v) => AV.viewSlugs[v] || v;
  AV.currentView = 'pulpit';
  AV.showView = (v) => {
    if (!AV.views.includes(v)) v = 'pulpit';
    AV.currentView = v;
    $$('.view').forEach((s) => s.classList.toggle('on', s.id === v));
    $$('.navg button').forEach((b) => b.classList.toggle('on', b.dataset.v === v));
    $('#mw')?.classList.remove('open');
    document.body?.classList.remove('nav-open');
    $('#navToggle')?.setAttribute('aria-expanded', 'false');
    window.scrollTo(0, 0);
    try { history.replaceState(null, '', '#/' + AV.viewSlug(v)); } catch {}
    if (v === 'trans') AV.renderJournal();
    if (v === 'stat') AV.renderStats();
    if (v === 'prop' && AV.renderProp) AV.renderProp();
    if (v === 'plan') { AV.renderPlan(); }
    if (v === 'ust') AV.renderSettings();
    if (v === 'kal') AV.renderCalendar();
    if (v === 'cialo') AV.renderBody();
    if (v === 'mentor') { AV.renderMentor(); AV.loadMcpStatus(); }
    if (v === 'gieldy') { AV.renderExchangeList(); AV.renderEx(AV.exKey); }
    if (v === 'nowa') { AV.renderNewTradeForm(); AV.renderLock(); }
    if (v === 'kalk') AV.loadInstrumentQuotes();
    if (v === 'akad') { if (AV.renderAcademy) AV.renderAcademy(); if (AV.loadEvents) AV.loadEvents(); }
  };

  /* ---------- ticker ---------- */
  AV.tickerData = [];
  AV.renderTicker = () => {
    const t = AV.tickerData;
    AV.setHTML('#ticker', t.map(([s, p, c]) => '<span><b>' + s + '</b>' + AV.fmt(p, p < 10 ? 4 : p < 500 ? 2 : 0) + (c === null || c === undefined ? '' : ' <span class="' + (c >= 0 ? 'win' : 'loss') + '">' + (c >= 0 ? '+' : '\u2212') + AV.fmt(Math.abs(c), 1) + '%</span>') + '</span>').join(''));
  };
  AV.loadQuotes = async () => {
    if (AV._quotesPending) return AV._quotesPending;
    AV._quotesPending = (async () => {
      try {
        const res = await AV.apiFetch('/api/quotes');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (!Array.isArray(data.quotes)) throw new Error('bad_quotes');
        AV.tickerData = data.quotes
          .filter((q) => q && typeof q.symbol === 'string' && Number.isFinite(Number(q.price)))
          .map((q) => [q.symbol, Number(q.price), q.changePercent === null || q.changePercent === undefined ? null : Number(q.changePercent)]);
        AV.renderTicker();
        return true;
      } catch (err) {
        AV.api.lastError = err.message;
        return false;
      } finally {
        AV._quotesPending = null;
      }
    })();
    return AV._quotesPending;
  };

  AV.loadMcpStatus = async () => {
    const el = document.querySelector('.mcp-server-status');
    if (!el) return;
    try {
      const res = await AV.apiFetch('/api/mcp');
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      const writesEnabled = data.writesEnabled === true;
      const label = writesEnabled ? 'MCP · ZAPIS' : 'MCP · TYLKO ODCZYT';
      el.innerHTML = '<span class="mcp-dot"></span>' + label;
      el.dataset.state = writesEnabled ? 'write' : 'read';
    } catch {
      el.innerHTML = '<span class="mcp-dot"></span>MCP · KONFIGURACJA NIEDOSTĘPNA';
      el.dataset.state = 'unknown';
    }
  };

  /* ---------- notowania instrumentów (kalkulator) ---------- */
  AV.instrumentQuotes = {};
  AV.quoteKey = (label) => String(label || '').split(/[\u2013\u2014\u00b7]/)[0].trim().replace(/\s+/g, '').toUpperCase();
  AV.instrumentQuote = (label) => {
    const q = AV.instrumentQuotes[AV.quoteKey(label)];
    return q && Number.isFinite(Number(q.price)) ? q : null;
  };
  AV.loadInstrumentQuotes = async () => {
    const list = (AV.INST && AV.INST[AV.calc.mode]) || [];
    const symbols = [...new Set(list.map((x) => AV.quoteKey(x[0])).filter(Boolean))];
    if (!symbols.length) return false;
    try {
      const res = await AV.apiFetch('/api/quotes?symbols=' + encodeURIComponent(symbols.join(',')));
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (!Array.isArray(data.quotes)) throw new Error('bad_quotes');
      data.quotes.forEach((q) => {
        if (q && typeof q.symbol === 'string' && Number.isFinite(Number(q.price))) {
          AV.instrumentQuotes[q.symbol] = {
            price: Number(q.price),
            changePercent: q.changePercent === null || q.changePercent === undefined ? null : Number(q.changePercent)
          };
        }
      });
      AV.setPrice();
      AV.autoLv();
      return true;
    } catch (err) {
      AV.setPrice();
      AV.autoLv();
      return false;
    }
  };

  /* ---------- countdown ---------- */
  AV.tickCountdowns = () => {
    const L = AV.lockInfo(AV.state);
    $$('.cdt').forEach((e) => {
      if (L.active && L.until) {
        const sec = Math.max(0, Math.floor((L.until - new Date()) / 1000));
        e.textContent = [sec / 3600 | 0, (sec % 3600) / 60 | 0, sec % 60].map((x) => String(x).padStart(2, '0')).join(':');
      } else e.textContent = '--:--:--';
    });
  };

  AV.macroNotify = () => {
    const s = AV.state;
    if (!s.settings.macroBefore) return;
    const events = AV.buildEvents().filter((e) => e.imp === 3);
    const now = Date.now();
    events.forEach((e) => {
      const mins = (new Date(e.date) - now) / 60000;
      if (mins > 25 && mins <= 30) {
        const key = e.id + ':30';
        if (s.meta.notified[key]) return;
        s.meta.notified[key] = true;
        AV.save();
        AV.toast('Za 30 min: ' + e.name + ' (' + AV.timeFmt(e.date, s) + ')', 5000);
        AV.pushNotification('Makro za 30 min', e.name + ' · ' + AV.timeFmt(e.date, s));
      }
    });
  };

  AV.pushNotification = (title, body) => {
    try {
      if ('Notification' in window && Notification.permission === 'granted') {
        new Notification(title, { body, icon: undefined });
      }
    } catch {}
  };

  AV.ensurePermission = () => {
    try {
      if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    } catch {}
  };

  /* ---------- szybkie dodanie z telefonu ---------- */
  AV.pickQuickTrade = (tradeId) => {
    const trades = (AV.state.trades || []).slice()
      .sort((a, b) => new Date(b.date) - new Date(a.date));
    if (tradeId) {
      const exact = trades.find((t) => t.id === tradeId);
      if (exact) return exact;
    }
    return trades.find((t) => AV.isTradeClosed(t) && (!t.emotion || !t.grade))
      || trades.find((t) => AV.isTradeClosed(t))
      || trades[0]
      || null;
  };

  AV.qaSyncSave = () => {
    const emotion = $('#qaEmotion .chip.on');
    const grade = $('#qaG .on');
    const ready = !!(emotion && grade);
    ['#qaSave', '#qaSaveFloat'].forEach((selector) => {
      const button = $(selector);
      if (button) {
        button.disabled = !ready;
        button.textContent = ready ? 'Zapisz' : 'Wybierz emocję i ocenę';
      }
    });
    const hint = $('#qaHint');
    if (hint) hint.textContent = ready ? 'Gotowe. Zapis zajmie chwilę.' : 'Wybierz emocję i ocenę.';
  };

  AV.openQuickAdd = (tradeId) => {
    const trade = AV.pickQuickTrade(tradeId);
    if (!trade) { AV.toast('Brak transakcji do uzupełnienia'); return false; }

    AV.ui.qaTradeId = trade.id;
    AV.ui.qaOpenedAt = performance.now();

    AV.setText('#qaPair', trade.pair || '');
    const direction = $('#qaDir');
    if (direction) {
      const tradeDir = AV.tradeDirection(trade.dir);
      direction.textContent = tradeDir === 's' ? 'SHORT' : 'LONG';
      direction.className = 'pill ' + (tradeDir || 'l');
    }
    const result = $('#qaResult');
    if (result) {
      result.textContent = trade.result == null ? '' : AV.signed(trade.result, 2);
      result.className = trade.result == null ? 'mut mono' : (trade.result > 0 ? 'win mono' : 'loss mono');
    }
    AV.setText('#qaMeta', [trade.source || '', AV.dateTimeShort(trade.date), AV.isTradeClosed(trade) ? 'zamknięta' : 'pozycja otwarta'].filter(Boolean).join(' · '));
    AV.setText('#qaPushText', !AV.isTradeClosed(trade)
      ? 'Pozycja otwarta: ' + trade.pair + '. Uzupełnij po zamknięciu.'
      : 'Zamknięta: ' + trade.pair + ' ' + (AV.tradeDirection(trade.dir) === 's' ? 'short' : 'long') + (trade.result == null ? '' : ' ' + AV.signed(trade.result, 2)) + '. Dodaj emocję i ocenę.');

    $$('#qaEmotion .chip').forEach((chip) => chip.classList.toggle('on', !!trade.emotion && chip.textContent === trade.emotion));
    $$('#qaError .chip').forEach((chip) => chip.classList.toggle('on', chip.textContent === 'Brak'
      ? !(trade.errors && trade.errors.length)
      : (trade.errors || []).includes(chip.textContent)));
    $$('#qaG div').forEach((grade) => grade.classList.toggle('on', !!trade.grade && grade.textContent.trim() === trade.grade));

    const photo = $('#qaPhotoPreview');
    const source = trade.screens && trade.screens.after;
    if (photo) {
      photo.hidden = !source;
      if (source) photo.src = source;
    }
    AV.setText('#qaPhoto', source ? '📷 Zmień zdjęcie wykresu' : '📷 Zdjęcie wykresu');

    AV.qaSyncSave();
    const modal = $('#qa');
    if (modal) modal.classList.add('on');
    return true;
  };

  AV.quickAddFromUrl = () => {
    let tradeId = null;
    try {
      const params = new URLSearchParams(location.search);
      if (!params.has('qa')) return false;
      const value = params.get('qa');
      if (value && value !== '1') tradeId = value;
    } catch {
      return false;
    }
    const opened = AV.openQuickAdd(tradeId);
    if (opened && history.replaceState) history.replaceState(null, '', location.pathname + location.hash);
    return opened;
  };

  /* ---------- auth ---------- */
  AV.localEnterApp = () => {
    if (AV._entering) return;
    AV._entering = true;
    const steps = $$('#asteps .astep');
    $('#asteps').classList.add('on');
    $('#enter').style.opacity = 0.6;
    steps.forEach((x, i) => setTimeout(() => x.classList.add('ok'), 350 + i * 450));
    setTimeout(() => {
      AV.login(AV.state.settings.remember);
      $('#auth').classList.add('hide');
      $('#enter').style.opacity = 1;
      $('#asteps').classList.remove('on');
      steps.forEach((x) => x.classList.remove('ok'));
      AV._entering = false;
      AV.renderAll();
      AV.showView(AV.viewFromHash());
      AV.quickAddFromUrl();
      AV.toast('Witaj z powrotem, ' + AV.state.user.name);
      setTimeout(() => AV.proactive(), 3200);
    }, 350 + steps.length * 450 + 250);
  };

  AV.openDemoLogin = () => {
    const body = AV.openModal('<div class="eyebrow">Logowanie</div><h1 style="font-size:26px;margin-top:6px">Discord nie jest jeszcze podłączony</h1>' +
      '<p class="mut" style="margin-top:10px;line-height:1.6">Aplikacja działa na MySQL i jest gotowa na OAuth Discorda. Administrator musi uzupełnić <code>DISCORD_CLIENT_ID</code>, <code>DISCORD_CLIENT_SECRET</code> i <code>DISCORD_REDIRECT_URI</code> w pliku <code>.env</code>.</p>' +
      '<p class="mut" style="margin-top:10px;line-height:1.6">Do tego czasu możesz wejść kontem demo. Wszystkie dane demo zapisują się w bazie MySQL.</p>' +
      '<div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap"><button class="btn p" id="demoEnter">Wejdź jako Kasia (demo)</button><a class="btn g" href="/docs/API.md" target="_blank">Dokumentacja logowania</a><button class="btn g" data-gclose>Anuluj</button></div>', { maxWidth: '560px' });
    const btn = $('#demoEnter', body);
    if (!AV.api.demoAllowed) {
      btn.disabled = true;
      btn.textContent = 'Demo wyłączone';
    } else {
      btn.onclick = async () => {
        btn.disabled = true;
        btn.textContent = 'Łączę z bazą…';
        try {
          await AV.serverDemoLogin();
          $('#asteps').classList.add('on');
          $$('#asteps .astep').forEach((x, i) => setTimeout(() => x.classList.add('ok'), 200 + i * 250));
          setTimeout(() => { location.href = '/'; }, 900);
        } catch (err) {
          btn.disabled = false; btn.textContent = 'Wejdź jako Kasia (demo)';
          AV.toast(err.message || 'Błąd logowania demo');
        }
      };
    }
  };

  AV.enterApp = () => {
    if (AV.api.online) {
      if (AV.api.discordConfigured) {
        location.href = '/auth/discord?remember=' + (AV.state.settings.remember ? '1' : '0');
        return;
      }
      AV.openDemoLogin();
      return;
    }
    AV.localEnterApp();
  };

  AV.proactive = () => {
    if (!AV.lockInfo(AV.state).active) return;
    if (AV._proShown) return;
    AV._proShown = true;
    const txt = AV.PROACTIVE[Math.floor(Math.random() * AV.PROACTIVE.length)];
    AV.setHTML('#proTxt', AV.fill(txt, AV.state));
    $('#pro').classList.add('on');
  };

  /* ---------- plan ---------- */
  AV.PLAN_AUTOSAVE_MS = 700;
  AV.planStore = () => {
    if (!AV.state.plans || typeof AV.state.plans !== 'object') AV.state.plans = {};
    return AV.state.plans;
  };
  AV.savePlanDraft = () => {
    if (!AV.state) return null;
    const p = AV.readPlanForm();
    AV.planId = p.id;
    AV.storePlan(AV.state, p);
    AV.save();
    AV.renderPlanSide();
    AV.renderPlanGlobal();
    AV.renderPlanHistory();
    if (AV.renderNavBadges) AV.renderNavBadges();
    return p;
  };
  AV.selectPlanChip = (selector, chip) => {
    const group = $(selector);
    if (!group || !chip) return null;
    $$(selector + ' .chip').forEach((c) => c.classList.toggle('on', c === chip));
    if (selector === '#planMarkets') {
      const sel = $('#planMarketSelect');
      if (sel) sel.value = chip.dataset.m;
      const input = $('#planMarketCustom');
      if (input) { input.style.display = 'none'; input.value = ''; }
    }
    return AV.savePlanDraft();
  };
  AV.commitPlan = async (p, message) => {
    if (!AV.state) return false;
    if (!p.id) p.id = AV.planId || AV.uid('plan');
    AV.planId = p.id;
    AV.storePlan(AV.state, p);
    AV.saveNow();
    AV.renderPlan();
    AV.renderPlanSide();
    if (AV.api.pushing) await AV.waitForPushIdle();
    let saved = await AV.pushStateImmediate();
    if (!saved && AV.api.pushing) {
      await AV.waitForPushIdle();
      saved = await AV.pushStateImmediate();
    }
    if (saved) {
      AV.toast(message + ' Zapisano w bazie.');
      return true;
    }
    AV.toast('Nie zapisano planu w bazie: ' + (AV.api.saveError || 'brak potwierdzenia serwera') + '.', 7000);
    return false;
  };

  AV.bindPlan = () => {
    $('#planSeg')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.day === 'hist') {
        AV.ui.planHistOpen = !AV.ui.planHistOpen;
        AV.renderPlanHistory();
        if (AV.ui.planHistOpen) $('#planHistoryCard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        AV.ui.planHistOpen = false;
        AV.planDate = b.dataset.day === 'global' ? 'global' : b.dataset.day === '0' ? AV.dateKey(new Date()) : AV.dateKey(AV.addDays(new Date(), -1));
        AV.planId = null;
        AV.renderPlan();
      }
    });
    $('#planSelect')?.addEventListener('change', (e) => {
      AV.planId = e.target.value || null;
      AV.renderPlan();
    });
    $('#addPlan')?.addEventListener('click', () => {
      const plan = AV.planTemplate(AV.planDate);
      plan.id = AV.uid('plan');
      AV.storePlan(AV.state, plan);
      AV.planId = plan.id;
      AV.ui.planHistOpen = false;
      AV.saveNow();
      AV.renderPlan();
      AV.renderDashboard();
      AV.toast('Dodano plan. Wybierz rynek i uzupełnij szczegóły.');
    });
    $('#planMarketSelect')?.addEventListener('change', (e) => {
      const v = e.target.value;
      const input = $('#planMarketCustom');
      if (input) {
        input.style.display = v === '__other' ? '' : 'none';
        if (v === '__other') { input.value = ''; input.focus(); }
      }
      if (v !== '__other') {
        $$('#planMarkets .chip').forEach((c) => c.classList.toggle('on', c.dataset.m === v));
        AV.savePlanDraft();
        AV.renderPlanSide();
      }
      e.stopPropagation();
    });
    $('#planMarkets')?.addEventListener('click', (e) => {
      const chip = e.target.closest('.chip');
      if (!chip) return;
      AV.selectPlanChip('#planMarkets', chip);
      AV.renderPlanSide();
    });
    $('#planBias')?.addEventListener('click', (e) => {
      const chip = e.target.closest('.chip');
      if (!chip) return;
      AV.selectPlanChip('#planBias', chip);
      AV.renderPlanSide();
    });
    $('#addLvl')?.addEventListener('click', () => {
      $('#lvls').insertAdjacentHTML('beforeend', AV.levelRow({ type: 'Wsparcie' }));
      const rows = $$('#lvls .lvl');
      rows[rows.length - 1]?.querySelector('input.mono')?.focus();
      AV.savePlanDraft();
    });
    $('#lvls')?.addEventListener('click', (e) => {
      if (e.target.classList.contains('x')) { e.target.parentNode.remove(); AV.savePlanDraft(); }
    });
    $('#lvls')?.addEventListener('change', (e) => {
      const sel = e.target.closest('select');
      if (!sel) return;
      const custom = sel.closest('.lvl')?.querySelector('.lvl-type');
      if (custom) {
        const own = sel.value === '__custom';
        custom.style.display = own ? '' : 'none';
        custom.closest('.lvl')?.classList.toggle('has-custom', own);
        if (own) custom.focus();
      }
      AV.savePlanDraft();
    });
    $('#planEditor')?.addEventListener('input', AV.debounce(() => { AV.savePlanDraft(); }, AV.PLAN_AUTOSAVE_MS));
    $('#planEditor')?.addEventListener('change', () => AV.savePlanDraft());
    $('#savePlan')?.addEventListener('click', async () => {
      const p = AV.readPlanForm({ pruneEmptyLevels: true });
      if (!p.market || !p.bias || !p.why || !p.levels.length) { AV.toast('Uzupełnij rynek, kierunek, uzasadnienie i co najmniej jeden poziom'); return; }
      p.approvedAt = Date.now();
      await AV.commitPlan(p, 'Plan zatwierdzony.');
      AV.renderPlan(); AV.renderDashboard(); AV.renderSettings();
    });
    $('#copyPlan')?.addEventListener('click', async () => {
      if (AV.planDate === 'global') { AV.toast('Plan globalny nie jest przypisany do dnia — skopiuj go z listy planów dnia.'); return; }
      const yesterday = AV.dateKey(AV.addDays(new Date(AV.planDate + 'T12:00:00'), -1));
      const current = AV.planById(AV.state, AV.planId);
      const y = AV.plansForDate(AV.state, yesterday).find((item) => item.market === current?.market) || AV.plansForDate(AV.state, yesterday)[0];
      if (!y) { AV.toast('Brak planu z poprzedniego dnia'); return; }
      const copy = AV.clone(y);
      copy.id = AV.uid('plan');
      copy.date = AV.planDate; copy.approvedAt = null;
      await AV.commitPlan(copy, 'Skopiowano plan z poprzedniego dnia.');
      AV.renderPlan();
    });
    document.addEventListener('click', (e) => {
      const el = e.target.closest('[data-plan-open]');
      if (!el) return;
      AV.planDate = el.dataset.planOpen;
      AV.planId = el.dataset.planId || null;
      AV.ui.planHistOpen = false;
      AV.renderPlan();
      $('#planEditor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  };

  /* ---------- dziennik ---------- */
  AV.bindJournal = () => {
    $('#jEmpty')?.addEventListener('click', (e) => {
      if (e.target.closest('[data-journal-add]')) AV.showView('nowa');
      else if (e.target.closest('[data-journal-exchanges]')) AV.showView('gieldy');
    });
    $('#fRes')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      AV.ui.jFilter = b.dataset.f; AV.renderJournal();
    });
    $('#fGrade')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      AV.ui.jGrade = b.dataset.f; AV.renderJournal();
    });
    $('#jSearch')?.addEventListener('input', AV.debounce((e) => {
      AV.ui.jSearch = e.target.value.trim(); AV.renderJournal();
    }, 150));
    $('#csvTrades')?.addEventListener('click', () => AV.exportTradesCsv());
    $('#tbody')?.addEventListener('click', (e) => {
      const tr = e.target.closest('tr[data-id]');
      if (tr) AV.openTrade(tr.dataset.id);
    });
    $('#tm')?.addEventListener('click', (e) => {
      if (e.target.id === 'tm' || e.target.closest('#mc')) $('#tm').classList.remove('on');
    });
    document.addEventListener('click', (e) => {
      const ed = e.target.closest('[data-trade-edit]');
      if (ed) { e.preventDefault(); AV.editTrade(ed.dataset.tradeEdit); return; }
      const del = e.target.closest('[data-trade-del]');
      if (del) {
        e.preventDefault();
        const t = AV.state.trades.find((x) => x.id === del.dataset.tradeDel);
        AV.confirm({
          title: 'Usunąć transakcję?', text: (t ? t.pair + ' · ' + AV.dateTimeShort(t.date) : '') + '. Tej operacji nie da się cofnąć.',
          confirmLabel: 'Usuń', danger: true, onConfirm: () => AV.deleteTrade(del.dataset.tradeDel)
        });
      }
      const be = e.target.closest('[data-trade-be]');
      if (be) { e.preventDefault(); AV.breakevenTrade(be.dataset.tradeBe); return; }
      const sl = e.target.closest('[data-trade-sl]');
      if (sl) { e.preventDefault(); AV.setTradeStopLoss(sl.dataset.tradeSl); return; }
      const cl = e.target.closest('[data-trade-close]');
      if (cl) { e.preventDefault(); AV.closeTradeModal(cl.dataset.tradeClose); }
    });
  };

  AV.deleteTrade = async (id) => {
    const trade = AV.state.trades.find((x) => x.id === id);
    if (!trade) return false;
    const beforeTrades = AV.state.trades.slice();
    const beforeTrash = Array.isArray(AV.state.trash) ? AV.state.trash.slice() : null;
    AV.state.trades = AV.state.trades.filter((x) => x.id !== id);
    if (!Array.isArray(AV.state.trash)) AV.state.trash = [];
    AV.state.trash.push({
      id: AV.uid('trash'),
      kind: 'trade',
      label: trade.pair + ' · ' + AV.dateTimeShort(trade.date),
      record: JSON.parse(JSON.stringify(trade)),
      deletedAt: new Date().toISOString()
    });
    AV.saveNow();
    clearTimeout(AV._pushTimer);
    if (AV.api.pushing) await AV.waitForPushIdle();
    let saved = await AV.pushStateImmediate();
    if (!saved && AV.api.pushing) {
      await AV.waitForPushIdle();
      saved = await AV.pushStateImmediate();
    }
    if (!saved && AV.api.online && AV.api.authenticated) {
      AV.state.trades = beforeTrades;
      if (beforeTrash) AV.state.trash = beforeTrash; else delete AV.state.trash;
      AV.saveNow();
      AV.renderAll();
      AV.toast('Nie udało się usunąć transakcji — zapis na serwerze nie powiódł się. Spróbuj ponownie.', 5000);
      return false;
    }
    $('#tm').classList.remove('on');
    AV.renderAll();
    AV.toast(saved ? 'Transakcja usunięta' : 'Transakcja usunięta lokalnie. Synchronizacja nastąpi po powrocie połączenia.', saved ? 3500 : 5000);
    return true;
  };

  AV.breakevenTrade = async (id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    if (!t || AV.isTradeClosed(t)) return;
    if (String(t.sl).trim() === String(t.entry).trim()) { AV.toast('SL już jest na poziomie wejścia'); return; }
    t.sl = String(t.entry);
    t.be = true;
    t.mentor = 'SL przeniesiony na wejście. Od tego momentu pozycja nie może przynieść straty.';
    AV.saveNow();
    clearTimeout(AV._pushTimer);
    if (AV.api.pushing) await AV.waitForPushIdle();
    let saved = await AV.pushStateImmediate();
    if (!saved && AV.api.pushing) {
      await AV.waitForPushIdle();
      saved = await AV.pushStateImmediate();
    }
    AV.openTrade(id);
    AV.renderAll();
    AV.toast(saved ? 'SL przeniesiony na BE' : 'SL przeniesiony lokalnie. Synchronizacja po powrocie połączenia.', saved ? 3000 : 5000);
  };

  AV.setTradeStopLoss = async (id) => {
    const trade = AV.state.trades.find((row) => row.id === id);
    if (!trade || AV.isTradeClosed(trade)) return;
    const body = AV.openModal(
      '<div class="eyebrow">Zarządzanie pozycją</div><h1 style="font-size:26px;margin-top:6px">Ustaw / zmień SL</h1>' +
      '<p class="mut" style="margin-top:8px;font-size:13.5px">' + AV.esc(trade.pair) + ' · ' + (AV.tradeDirection(trade.dir) === 's' ? 'SHORT' : 'LONG') + '</p>' +
      '<span class="label">Cena stop loss</span><input class="input mono" id="tradeSlValue" inputmode="decimal" value="' + AV.attr(trade.sl === 'brak' ? '' : trade.sl || '') + '" placeholder="Cena SL">' +
      '<div class="hint">SL musi pozostać po właściwej stronie ceny wejścia.</div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="tradeSlSave">Zapisz SL</button></div>',
      { maxWidth: '460px' }
    );
    $('#tradeSlValue', body)?.focus();
    $('#tradeSlSave', body).onclick = async () => {
      const sl = AV.pnum($('#tradeSlValue', body).value);
      const entry = AV.pnum(trade.entry);
      const long = AV.tradeDirection(trade.dir) !== 's';
      if (!(sl > 0) || !(entry > 0)) { AV.toast('Podaj dodatnią cenę SL'); return; }
      if (long ? sl > entry : sl < entry) { AV.toast('Stop loss jest po złej stronie wejścia'); return; }
      if (sl === AV.pnum(trade.sl)) { AV.closeModal(); return; }
      if (!(await AV.waitForPushIdle())) { AV.toast('Trwa inny zapis. Spróbuj ponownie za chwilę.'); return; }
      const old = JSON.parse(JSON.stringify(trade));
      trade.sl = AV.fmt(sl, AV.dec(entry));
      trade.be = sl === entry;
      const size = AV.pnum(trade.size);
      const risk = size > 0 ? Math.abs(entry - sl) * size : null;
      trade.risk = risk == null ? null : Math.round(risk * 100) / 100;
      const capital = AV.totalCapital(AV.state);
      trade.riskPct = risk != null && capital > 0 ? Math.round(risk / capital * 10000) / 100 : null;
      AV.saveNow();
      clearTimeout(AV._pushTimer);
      let saved = await AV.pushStateImmediate();
      if (!saved && AV.api.pushing) {
        await AV.waitForPushIdle();
        saved = await AV.pushStateImmediate();
      }
      if (!saved && AV.api.online && AV.api.authenticated) {
        Object.assign(trade, old);
        AV.saveNow();
        AV.renderAll();
        AV.toast('Nie udało się zapisać SL — poprzednia wartość została przywrócona.', 5000);
        return;
      }
      AV.closeModal();
      AV.openTrade(id);
      AV.renderAll();
      AV.toast(saved ? 'Stop loss zapisany' : 'Stop loss zapisany lokalnie; synchronizacja nastąpi po powrocie połączenia.', 4500);
    };
  };

  AV.editTrade = (id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    if (!t) return;
    AV.ui.editId = id;
    const source = String(t.source ?? '').trim().toLowerCase();
    const manualSources = ['', 'manual', 'panel', 'ręcznie', 'recznie'];
    const hasExternalIdentifier = (value) => value != null && value !== false && String(value).trim() !== '';
    AV.ui.ntExecutionLocked = !manualSources.includes(source)
      || ['extId', 'externalId', 'exchangeId'].some((field) => hasExternalIdentifier(t[field]));
    AV.ui.ntChecklistTouched = false;
    AV.ui.ntGradeManual = true;
    AV.ui.ntChecks = {};
    AV.ui.ntScreens = AV.clone(t.screens || {});
    const checkedItems = Array.isArray(t.checklistItems)
      ? t.checklistItems
      : [];
    AV.ui.ntChecklistHistory = checkedItems;
    const points = AV.checklistPoints(AV.state, checkedItems);
    AV.showView('nowa');
    AV.renderNewTradeForm();
    AV.setVal('#ntPair', t.pair);
    AV.setVal('#ntEntry', t.entry);
    AV.setVal('#ntSl', t.sl);
    AV.setVal('#ntTp', t.tp);
    AV.show('#ntExitWrap', AV.isTradeClosed(t));
    AV.setVal('#ntExit', AV.isTradeClosed(t) ? (t.exit === 'otwarta' ? '' : t.exit || '') : '');
    AV.setVal('#ntNote', t.note);
    const accSel = $('#ntAccount');
    if (accSel) {
      if (!Array.from(accSel.options).some((o) => o.value === t.account)) {
        accSel.insertAdjacentHTML('beforeend', '<option value="' + AV.attr(t.account || '') + '">' + AV.esc(t.account || '') + '</option>');
      }
      accSel.value = t.account || accSel.value;
    }
    const sizeConfig = AV.renderTradeSizeUnit();
    const sourceSize = AV.isTradeClosed(t) ? (AV.pnum(t.initialSize) > 0 ? t.initialSize : t.size) : t.size;
    AV.setVal('#ntSize', AV.displayTradeSize(sourceSize, t.entry, sizeConfig.config));
    AV.show('#ntResultOverrideWrap', AV.isTradeClosed(t) && !AV.ui.ntExecutionLocked);
    AV.setVal('#ntResultOverride', t.resultOverride == null ? '' : String(t.resultOverride));
    $$('#ntDir .chip').forEach((c) => c.classList.toggle('on', c.dataset.d === AV.tradeDirection(t.dir)));
    ['#ntPair', '#ntEntry', '#ntSize', '#ntSl', '#ntTp'].forEach((selector) => {
      const input = $(selector);
      if (input) input.disabled = AV.ui.ntExecutionLocked;
    });
    const exitInput = $('#ntExit');
    if (exitInput) exitInput.disabled = AV.ui.ntExecutionLocked;
    const direction = $('#ntDir');
    direction?.classList.toggle('disabled', AV.ui.ntExecutionLocked);
    if (direction) {
      direction.setAttribute('aria-disabled', AV.ui.ntExecutionLocked ? 'true' : 'false');
      direction.style.pointerEvents = AV.ui.ntExecutionLocked ? 'none' : '';
    }
    $$('#ntSetup .chip').forEach((c) => c.classList.toggle('on', c.dataset.s === t.setup));
    $$('#ntEmotion .chip').forEach((c) => c.classList.toggle('on', c.textContent === t.emotion));
    $$('#errChips .chip.err').forEach((c) => c.classList.toggle('on', (t.errors || []).includes(c.textContent)));
    $$('.gp').forEach((g) => g.classList.toggle('on', g.dataset.g === t.grade));
    points.forEach((p) => AV.ui.ntChecks[p] = checkedItems.includes(p));
    $('#tm').classList.remove('on');
    AV.renderNTMeta();
    AV.renderChecklist();
    AV.renderDrops(t.screens || {});
    $$('.gp').forEach((g) => g.classList.toggle('on', g.dataset.g === t.grade));
    AV.ui.ntGradeManual = true;
    AV.show('#cancelEdit', true);
    AV.setText('#saveTrade', 'Zapisz zmiany');
    AV.toast(AV.isTradeClosed(t) && AV.ui.ntExecutionLocked
      ? 'Importowana transakcja: pola wykonania są tylko do odczytu.'
      : AV.isTradeClosed(t)
        ? 'Możesz poprawić wykonanie; wynik przeliczy się automatycznie. Wpisz wynik ręczny tylko jako override.'
      : 'Edytujesz transakcję z ' + AV.dateTimeShort(t.date));
    // Późna odpowiedź pushu (np. 409 i przeładowanie stanu) może przełączyć widok.
    // Formularz edycji ma pozostać widoczny bez ponownego nadpisywania pól.
    AV.currentView = 'nowa';
    $$('.view').forEach((section) => section.classList.toggle('on', section.id === 'nowa'));
    $$('.navg button').forEach((button) => button.classList.toggle('on', button.dataset.v === 'nowa'));
  };

  AV.closeTradeModal = (id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    if (!t || AV.isTradeClosed(t)) return;
    let saving = false;
    const body = AV.openModal('<div class="eyebrow">Zamknięcie pozycji</div><h1 style="font-size:26px;margin-top:6px">' + AV.esc(t.pair) + ' · ' + (AV.tradeDirection(t.dir) === 's' ? 'SHORT' : 'LONG') + '</h1>' +
      '<p class="mut" style="margin-top:8px;font-size:13.5px">Wpisz cenę wyjścia. Wynik policzę z wielkości pozycji. Możesz też podać wynik ręcznie. Część pozycji możesz zamknąć, wybierając procent.</p>' +
      '<span class="label">Zamknij część pozycji</span><div class="chips sel" id="ctPct"><span class="chip" data-pct="25">25%</span><span class="chip" data-pct="50">50%</span><span class="chip" data-pct="75">75%</span><span class="chip on" data-pct="100">100%</span></div>' +
      '<div class="grid g2" style="margin-top:14px"><div><span class="label">Cena wyjścia</span><input class="input mono" id="ctExit" value="' + AV.attr(t.exit === 'otwarta' ? t.entry : t.exit) + '"></div><div><span class="label" id="ctResultLabel">Wynik USDT (opcjonalnie)</span><input class="input mono" id="ctResult" placeholder="np. 65,20"></div></div>' +
      '<label class="check" style="margin-top:10px"><span class="box" id="ctBeBox"></span><span>Przenieś SL na wejście (BE)</span></label>' +
      '<div class="hint" id="ctHint" style="margin-top:10px">Wejście ' + AV.esc(t.entry) + ' · wielkość ' + AV.esc(t.size) + '</div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="ctSave">Zamknij pozycję</button></div>', { maxWidth: '520px' });
    const pctValue = () => Number($('#ctPct .chip.on', body)?.dataset.pct || 100);
    const updateHint = () => {
      const exit = AV.pnum($('#ctExit', body).value), entry = AV.pnum(t.entry), size = AV.pnum(t.size), pct = pctValue();
      const calc = (exit - entry) * size * (pct / 100) * (AV.tradeDirection(t.dir) === 's' ? -1 : 1);
      const remaining = size * (1 - pct / 100);
      AV.setText('#ctResultLabel', pct >= 100 ? 'Wynik całej pozycji · USDT (opcjonalnie)' : 'Wynik zamykanej części · USDT (opcjonalnie)');
      AV.setText('#ctHint', 'Szacowany wynik: ' + (Number.isFinite(calc) ? AV.signed(calc, 2, ' USDT') : '—') + ' · zamykasz ' + pct + '% (' + AV.fmt(size * pct / 100, 4) + ')' + (pct < 100 ? ' · zostaje ' + AV.fmt(remaining, 4) : '') + ' · SL ' + t.sl + ' · TP ' + t.tp);
    };
    $('#ctPct', body).addEventListener('click', (e) => {
      const chip = e.target.closest('.chip'); if (!chip) return;
      $$('#ctPct .chip', body).forEach((c) => c.classList.toggle('on', c === chip));
      updateHint();
    });
    let moveBe = false;
    const beBox = $('#ctBeBox', body);
    beBox.parentElement.addEventListener('click', () => { moveBe = !moveBe; beBox.classList.toggle('on', moveBe); });
    $('#ctExit', body).addEventListener('input', updateHint);
    updateHint();
    $('#ctSave', body).onclick = async () => {
      if (saving) return;
      const exitText = $('#ctExit', body).value.trim();
      const resultText = $('#ctResult', body).value.trim();
      const pct = Math.min(100, Math.max(1, pctValue()));
      const entry = AV.pnum(t.entry), fullSize = AV.pnum(t.size);
      let exit = null;
      if (exitText) {
        exit = AV.pnum(exitText);
        if (!Number.isFinite(exit) || exit <= 0) { AV.toast('Cena wyjścia musi być liczbą dodatnią'); return; }
      }
      let result = resultText ? AV.pnum(resultText) : null;
      if (result == null) {
        if (exit == null) { AV.toast('Podaj cenę wyjścia albo wynik'); return; }
        if (!(entry > 0) || !(fullSize > 0)) { AV.toast('Brak ceny wejścia lub wielkości — podaj wynik ręcznie'); return; }
        result = (exit - entry) * fullSize * (pct / 100) * (AV.tradeDirection(t.dir) === 's' ? -1 : 1);
      }
      if (!Number.isFinite(result)) { AV.toast('Podaj cenę wyjścia albo wynik'); return; }
      if (!(await AV.waitForPushIdle())) {
        AV.toast('Trwa inny zapis. Spróbuj ponownie za chwilę.');
        return;
      }
      saving = true;
      const saveButton = $('#ctSave', body);
      saveButton.disabled = true;
      saveButton.textContent = 'Zapisywanie…';
      const originalState = AV.state;
      const originalTrade = JSON.parse(JSON.stringify(t));
      const slText = String(t.sl ?? '').trim();
      const initialSize = AV.pnum(t.initialSize) > 0 ? AV.pnum(t.initialSize) : fullSize;
      const risk = slText !== '' && slText !== 'brak' && slText !== '—'
        ? Math.abs(entry - AV.pnum(slText)) * initialSize
        : null;
      const closedAtMs = Date.now();
      const round2 = (value) => Math.round(value * 100) / 100;
      const fullClose = pct >= 100;
      if (fullClose) {
        t.initialSize = AV.fmt(initialSize, 8);
        t.exit = exit == null ? '' : AV.fmt(exit, AV.dec(exit));
        t.resultOverride = resultText ? round2(result) : null;
        t.result = resultText ? round2(result) : round2((AV.pnum(t.partialResult) || 0) + result);
        t.r = risk != null && risk > 0 ? Math.round(t.result / risk * 100) / 100 : null;
        t.closedAt = new Date(closedAtMs).toISOString();
        t.durationMin = Math.max(1, Math.round((closedAtMs - new Date(t.date)) / 60000));
        t.mentor = t.result > 0
          ? 'Pozycja zamknięta z zyskiem. Sprawdź, czy wyszedłeś zgodnie z planem, a nie z emocji.'
          : (t.result < 0
            ? 'Pozycja zamknięta na minusie. Sprawdź, czy SL był ustawiony przed wejściem.'
            : 'Pozycja zamknięta na zero (break-even). Oceń, czy wyjście było zgodne z planem.');
        if (moveBe) t.be = true;
      } else {
        t.initialSize = AV.fmt(initialSize, 8);
        t.partials = Array.isArray(t.partials) ? t.partials : [];
        t.partials.push({ price: exit == null ? '' : AV.fmt(exit, AV.dec(exit)), pct, result: round2(result), resultOverride: !!resultText, at: new Date(closedAtMs).toISOString() });
        t.partialResult = round2((AV.pnum(t.partialResult) || 0) + result);
        t.size = String(Math.round(Math.max(0, fullSize * (1 - pct / 100)) * 1e8) / 1e8);
        if (moveBe) { t.sl = String(t.entry); t.be = true; }
        t.mentor = 'Zamknięto ' + pct + '% pozycji. Pozostała część nadal jest otwarta — prowadź ją według planu.';
      }
      AV.saveNow();
      clearTimeout(AV._pushTimer);
      if (AV.api.pushing) await AV.waitForPushIdle();
      let saved = await AV.pushStateImmediate();
      if (!saved && AV.api.pushing) {
        await AV.waitForPushIdle();
        saved = await AV.pushStateImmediate();
      }
      if (!saved && AV.api.online && AV.api.authenticated) {
        if (AV.state === originalState) {
          const currentIndex = AV.state.trades.findIndex((row) => row.id === id);
          if (currentIndex !== -1) AV.state.trades[currentIndex] = originalTrade;
          AV.saveNow();
          clearTimeout(AV._pushTimer);
          AV.renderAll();
          saving = false;
          if (saveButton.isConnected) {
            saveButton.disabled = false;
            saveButton.textContent = 'Zamknij pozycję';
          }
          AV.toast('Pozycja nie została zamknięta — zapis na serwerze nie powiódł się. Spróbuj ponownie.', 5000);
          return;
        }
        AV.closeModal();
        AV.toast('Pozycja nie została zamknięta. Wczytano stan z serwera; otwórz aktualny wpis ponownie.', 5000);
        return;
      }
      AV.closeModal();
      $('#tm').classList.remove('on');
      AV.renderAll();
      AV.toast(saved
        ? (fullClose ? 'Pozycja zamknięta: ' + AV.signed(t.result, 2, ' USDT') : 'Zamknięto ' + pct + '% pozycji: ' + AV.signed(result, 2, ' USDT'))
        : 'Zmiana zapisana lokalnie. Synchronizacja nastąpi po powrocie połączenia.', 5000);
      if (saved && fullClose && AV.pwa && AV.pwa.notifyTradeClosed) AV.pwa.notifyTradeClosed(t);
    };
  };

  /* ---------- playbook ---------- */
  AV.bindPlaybook = () => {
    $('#addSetup')?.addEventListener('click', () => AV.openSetupForm());
    document.addEventListener('click', (e) => {
      if (e.target.closest('#addSetup2')) { AV.openSetupForm(); return; }
      const ed = e.target.closest('[data-setup-edit]');
      if (ed) { AV.openSetupForm(ed.dataset.setupEdit); return; }
      const tr = e.target.closest('[data-setup-trades]');
      if (tr) {
        AV.ui.jSearch = tr.dataset.setupTrades; AV.ui.jFilter = 'all'; AV.ui.jGrade = 'all';
        $('#jSearch').value = AV.ui.jSearch;
        AV.renderJournal(); AV.showView('trans');
      }
    });
  };

  /* ---------- nowa transakcja ---------- */
  AV.bindNewTrade = () => {
    const inputs = ['#ntPair', '#ntEntry', '#ntSize', '#ntSl', '#ntTp', '#ntNote'];
    inputs.forEach((sel) => $(sel)?.addEventListener('input', AV.debounce(() => {
      AV.renderNTMeta(); AV.renderChecklist(); AV.saveDraft();
    }, 180)));
    $('#ntAccount')?.addEventListener('change', () => {
      AV.renderTradeSizeUnit();
      AV.renderNTMeta();
      AV.saveDraft();
    });
    $('#ntDir')?.addEventListener('click', (e) => {
      if (AV.ui.ntExecutionLocked) return;
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#ntDir .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.renderChecklist(); AV.renderNTMeta(); AV.saveDraft();
    });
    $('#ntSetup')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#ntSetup .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.saveDraft();
    });
    $('#ntEmotion')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#ntEmotion .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.saveDraft();
    });
    $('#clList')?.addEventListener('click', (e) => {
      const row = e.target.closest('[data-cl]'); if (!row) return;
      const p = row.dataset.cl;
      AV.ui.ntChecklistTouched = true;
      AV.ui.ntChecks[p] = !AV.ui.ntChecks[p];
      AV.renderChecklist(); AV.saveDraft();
    });
    $('#gp')?.addEventListener('click', (e) => {
      const g = e.target.closest('.gp'); if (!g) return;
      AV.ui.ntGradeManual = true;
      $$('.gp').forEach((x) => x.classList.toggle('on', x === g));
      AV.saveDraft();
    });
    $('#errChips')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      if (c.textContent === '+ własny') {
        AV.promptText('Własny błąd', 'Nazwa błędu', (name) => {
          if (!name) return;
          const span = document.createElement('span');
          span.className = 'chip err on';
          span.textContent = name;
          c.before(span);
          AV.saveDraft();
        });
        return;
      }
      c.classList.toggle('on');
      AV.saveDraft();
    });
    $('#saveTrade')?.addEventListener('click', () => AV.saveTrade());
    $('#cancelEdit')?.addEventListener('click', () => {
      const editing = !!AV.ui.editId;
      AV.ui.editId = null; AV.ui.ntExecutionLocked = false; AV.ui.ntScreens = {}; AV.ui.ntChecks = {}; AV.ui.ntChecklistHistory = []; AV.ui.ntChecklistTouched = false; AV.ui.ntGradeManual = false;
      if (!editing) AV.safeDel(AV.draftKey());
      AV.renderNewTradeForm(); AV.showView('trans'); AV.toast('Edycja anulowana');
    });
    $('#pastTrade')?.addEventListener('click', () => {
      AV.addOverride('Wpisuję wcześniejszą transakcję');
      AV.renderLock(); AV.renderNewTradeForm();
      AV.toast('Odblokowane do wpisania wcześniejszej transakcji');
    });
    $('#override')?.addEventListener('click', () => { $('#ovBox').style.display = 'block'; });
    $('#ovGo')?.addEventListener('click', () => {
      const ta = $('#ovBox textarea');
      const reason = ta.value.trim();
      if (AV.state.settings.requireReason && !reason) { AV.toast('Napisz powód — mentor go zobaczy'); ta.focus(); return; }
      AV.addOverride(reason || 'bez podania powodu');
      AV.renderLock(); AV.renderNewTradeForm();
      AV.toast('Powód zapisany. Mentor go zobaczy.');
    });
    $$('#nowa [data-drop]').forEach((d) => {
      // bindDrops robi to centralnie; tu tylko klawiaturowe otwarcie
      d.setAttribute('tabindex', '0');
      d.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') d.querySelector('input')?.click(); });
    });
    AV.bindDrops($('#nowa'));
  };

  AV.saveTrade = () => {
    const s = AV.state;
    const editingTrade = AV.ui.editId && s.trades.find((trade) => trade.id === AV.ui.editId);
    const closedEdit = !!(editingTrade && AV.isTradeClosed(editingTrade));
    const executionLocked = !!editingTrade && !!AV.ui.ntExecutionLocked;
    if (closedEdit && executionLocked) {
      const checks = Object.values(AV.ui.ntChecks || {}).filter(Boolean).length;
      const total = Object.keys(AV.ui.ntChecks || {}).length || (s.settings.checklist || []).length || 7;
      const grade = $('.gp.on')?.dataset.g || (checks === total ? 'A' : checks >= Math.ceil(total * 0.7) ? 'B' : 'C');
      const errors = $$('#errChips .chip.err.on').map((c) => c.textContent);
      const emotion = $('#ntEmotion .chip.on')?.textContent || 'Spokój';
      const setup = $('#ntSetup .chip.on')?.dataset.s || 'Bez setupu';
      const note = $('#ntNote').value.trim();
      const mentor = grade === 'A'
        ? 'Wzorowo: plan, pełna checklista, SL, spokojna głowa. Tak wyglądają Twoje najlepsze transakcje.'
        : grade === 'B'
          ? 'Setup z planu, drobne odstępstwa. Trzymaj się checklisty do końca.'
          : (errors.length ? 'Ocena C i ' + errors.length + ' ' + AV.plural(errors.length, 'błąd', 'błędy', 'błędów') + '. To nie pech, to decyzja. Wróć do planu.' : 'Ocena C: brak setupu albo pośpiech. Odczekaj i wróć z planem.');
      Object.assign(editingTrade, {
        grade, checklist: checks, checkTotal: total, errors, emotion, setup, note, mentor,
        screens: AV.ui.ntScreens || editingTrade.screens || {}
      });
      AV.ui.editId = null; AV.ui.ntScreens = {}; AV.saveNow(); AV.safeDel(AV.draftKey());
      AV.renderAll(); AV.showView('trans'); AV.toast('Recenzja zapisana; pola wykonania pozostały bez zmian');
      return;
    }
    if (!editingTrade && AV.lockInfo(s).active) { AV.toast('Tryb ochronny: odblokuj dziennik, żeby zapisać transakcję'); return; }
    const existingTrade = editingTrade || null;
    const selectedAccountName = $('#ntAccount')?.value || existingTrade?.account || '';
    const account = s.accounts.find((item) => item.name === selectedAccountName) || AV.tradeAccount(s, existingTrade);
    const sizeConfig = AV.tradeSizeConfig(existingTrade, account);
    const pair = executionLocked ? String(existingTrade.pair ?? '') : $('#ntPair').value.trim().toUpperCase();
    const entry = executionLocked ? null : AV.pnum($('#ntEntry').value);
    const sl = executionLocked ? null : AV.pnum($('#ntSl').value);
    const tp = executionLocked ? null : AV.pnum($('#ntTp').value);
    const displayedSize = executionLocked ? null : AV.pnum($('#ntSize').value);
    const size = executionLocked ? null : AV.baseTradeSize(displayedSize, entry, sizeConfig);
    const existingStop = String(existingTrade?.sl ?? '').trim();
    const existingHasNoStop = !!existingTrade && (existingStop === '' || existingStop === 'brak' || existingStop === '—');
    const dir = executionLocked ? existingTrade.dir : ($('#ntDir .chip.on')?.dataset.d || 'l');
    if (!executionLocked) {
      if (!pair) { AV.toast('Podaj parę'); return; }
      if (!(entry > 0)) { AV.toast('Podaj cenę wejścia'); return; }
      if (!(displayedSize > 0)) { AV.toast('Podaj wielkość pozycji'); return; }
      if (!(size > 0)) {
        AV.toast(sizeConfig.unit === 'lots' ? 'Ustaw dodatni mnożnik lota/kontraktu na koncie' : 'Nie można przeliczyć wielkości pozycji');
        return;
      }
      if (!sl && !existingHasNoStop) { AV.toast('Podaj stop loss'); return; }
      if (sl && (dir === 'l' ? sl > entry : sl < entry)) { AV.toast('Stop loss jest po złej stronie wejścia'); return; }
      if (tp && (dir === 'l' ? tp <= entry : tp >= entry)) { AV.toast('Take profit jest po złej stronie wejścia'); return; }
      if (closedEdit && !(AV.pnum($('#ntExit')?.value) > 0)) { AV.toast('Podaj dodatnią cenę wyjścia'); return; }
    }
    const checklistPoints = AV.checklistPoints(s, AV.ui.editId ? AV.ui.ntChecklistHistory : []);
    const checklistItems = checklistPoints.filter((point) => AV.ui.ntChecks?.[point]);
    const checks = checklistItems.length;
    const total = checklistPoints.length;
    const grade = $('.gp.on')?.dataset.g || (existingTrade ? (existingTrade.grade || '') : (checks === total ? 'A' : checks >= Math.ceil(total * 0.7) ? 'B' : 'C'));
    const errors = $$('#errChips .chip.err.on').map((c) => c.textContent);
    const emotion = $('#ntEmotion .chip.on')?.textContent || (existingTrade ? (existingTrade.emotion || '') : 'Spokój');
    const setup = $('#ntSetup .chip.on')?.dataset.s || (existingTrade ? (existingTrade.setup || '') : 'Bez setupu');
    const note = $('#ntNote').value.trim();
    const now = new Date();
    const tradeDate = existingTrade?.date || now.toISOString();
    const planAligned = existingTrade ? !!existingTrade.plan : AV.tradePlanMatch(s, { date: tradeDate, pair, dir });
    const risk = executionLocked ? null : (sl ? Math.abs(entry - sl) * size : null);
    const cap = AV.totalCapital(s);
    const maxRiskPct = Number(s.settings.maxRisk) || 1;
    const riskPct = risk != null && risk > 0 && cap > 0 ? risk / cap * 100 : null;
    const accountName = executionLocked
      ? (existingTrade.account || '')
      : (account?.name || selectedAccountName || s.accounts.find((item) => item.include)?.name || s.accounts[0]?.name || 'Konto główne');
    const broker = executionLocked
      ? (existingTrade.broker || existingTrade.source || '')
      : (account?.exchange || existingTrade?.broker || existingTrade?.source || '');
    const formattedSize = size == null ? existingTrade?.size : AV.fmt(size, 8);
    let closedFields = {};
    if (closedEdit && !executionLocked) {
      const exit = AV.pnum($('#ntExit').value);
      const partials = (Array.isArray(existingTrade.partials) ? existingTrade.partials : []).map((partial) => ({ ...partial }));
      const originalSize = size;
      let remaining = originalSize;
      let partialResult = 0;
      const sign = dir === 's' ? -1 : 1;
      const round2 = (value) => Math.round(value * 100) / 100;
      for (const partial of partials) {
        const pct = AV.pnum(partial?.pct);
        if (!Number.isFinite(pct) || pct <= 0 || pct >= 100) {
          AV.toast('Częściowe zamknięcie ma nieprawidłowy procent. Zapis nie został wykonany.');
          return;
        }
        const legSize = remaining * pct / 100;
        const closePrice = AV.pnum(partial.price);
        const priorResult = AV.pnum(partial.result);
        if (partial.resultOverride != null && typeof partial.resultOverride !== 'boolean') {
          AV.toast('Override częściowego wyniku ma nieprawidłowy format.');
          return;
        }
        let legResult;
        if (partial.resultOverride === true) {
          if (!Number.isFinite(priorResult)) {
            AV.toast('Brakuje ręcznego wyniku częściowego zamknięcia.');
            return;
          }
          legResult = priorResult;
        } else if (Number.isFinite(closePrice) && closePrice > 0) {
          legResult = (closePrice - entry) * legSize * sign;
        } else if (Number.isFinite(priorResult)) {
          // Older records may contain a manual leg result without provenance.
          partial.resultOverride = true;
          legResult = priorResult;
        } else {
          AV.toast('Brakuje poprawnej ceny częściowego zamknięcia.');
          return;
        }
        if (!Number.isFinite(legResult)) {
          AV.toast('Wynik częściowego zamknięcia przekracza zakres liczbowy.');
          return;
        }
        partial.result = round2(legResult);
        partialResult += partial.result;
        remaining -= legSize;
        if (!Number.isFinite(partialResult) || !Number.isFinite(remaining) || remaining <= 0) {
          AV.toast('Częściowe zamknięcia nie pozostawiają poprawnej wielkości pozycji.');
          return;
        }
      }
      const finalLegResult = (exit - entry) * remaining * sign;
      if (!Number.isFinite(finalLegResult)) {
        AV.toast('Wynik końcowej części transakcji przekracza zakres liczbowy.');
        return;
      }
      const overrideText = $('#ntResultOverride')?.value.trim() || '';
      const totalOverride = overrideText === '' ? null : AV.pnum(overrideText);
      if (overrideText !== '' && !Number.isFinite(totalOverride)) { AV.toast('Wynik override musi być liczbą'); return; }
      const totalResult = totalOverride == null ? partialResult + finalLegResult : totalOverride;
      const initialRisk = sl ? Math.abs(entry - sl) * originalSize : null;
      const remainingSize = partials.length ? remaining : originalSize;
      closedFields = {
        exit: AV.fmt(exit, AV.dec(exit)),
        initialSize: AV.fmt(originalSize, 8),
        size: AV.fmt(remainingSize, 8),
        partials,
        partialResult: partials.length ? round2(partialResult) : null,
        resultOverride: totalOverride == null ? null : round2(totalOverride),
        result: round2(totalResult),
        r: initialRisk != null && initialRisk > 0 ? round2(totalResult / initialRisk) : null
      };
    }
    const payload = {
      pair, dir,
      entry: executionLocked ? existingTrade.entry : AV.fmt(entry, AV.dec(entry)),
      sl: executionLocked ? existingTrade.sl : (sl ? AV.fmt(sl, AV.dec(entry)) : (existingHasNoStop ? existingStop : 'brak')),
      tp: executionLocked ? existingTrade.tp : (tp ? AV.fmt(tp, AV.dec(entry)) : '—'),
      size: executionLocked ? existingTrade.size : formattedSize,
      grade, checklist: checks, checklistItems, checkTotal: total, errors, emotion, setup, note,
      ...closedFields,
      ...(executionLocked ? {} : {
        risk: risk == null ? null : Math.round(risk * 100) / 100,
        riskPct: riskPct == null ? null : Math.round(riskPct * 100) / 100,
        sizeUnit: sizeConfig.unit,
        sizeContractSize: sizeConfig.unit === 'lots' ? sizeConfig.contractSize : null
      }),
      account: accountName,
      broker,
      plan: planAligned,
      mentor: grade === 'A' ? 'Wzorowo: plan, pełna checklista, SL, spokojna głowa. Tak wyglądają Twoje najlepsze transakcje.' : grade === 'B' ? 'Setup z planu, drobne odstępstwa. Trzymaj się checklisty do końca.' : (errors.length ? 'Ocena C i ' + errors.length + ' ' + AV.plural(errors.length, 'błąd', 'błędy', 'błędów') + '. To nie pech, to decyzja. Wróć do planu.' : 'Ocena C: brak setupu albo pośpiech. Odczekaj i wróć z planem.')
    };
    if (!closedEdit && existingTrade && !executionLocked && (
      existingTrade.entry !== payload.entry || existingTrade.sl !== payload.sl ||
      existingTrade.size !== payload.size || existingTrade.dir !== payload.dir
    )) {
      const updatedRisk = sl ? Math.abs(entry - sl) * size : null;
      const previousResult = closedEdit ? Number(payload.result) : Number(existingTrade.result);
      payload.r = existingTrade.result != null && Number.isFinite(previousResult) && updatedRisk != null && updatedRisk > 0
        ? Math.round(previousResult / updatedRisk * 100) / 100
        : null;
    }
    if (AV.ui.editId && !AV.ui.ntChecklistTouched) {
      const priorItems = Array.isArray(AV.ui.ntChecklistHistory) ? AV.ui.ntChecklistHistory : [];
      const checklistChanged = checklistItems.length !== priorItems.length
        || checklistItems.some((p) => !priorItems.includes(p));
      if (!checklistChanged) {
        delete payload.checklist;
        delete payload.checklistItems;
        delete payload.checkTotal;
      }
    }
    const persist = () => {
      if (AV.ui.editId) {
        const t = s.trades.find((x) => x.id === AV.ui.editId);
        if (t) Object.assign(t, payload, { screens: AV.ui.ntScreens || t.screens || {} });
        AV.toast('Zmiany zapisane');
      } else {
        const session = now.getHours() < 8 ? 'Azja' : now.getHours() < 13 ? 'Londyn' : now.getHours() < 22 ? 'Nowy Jork' : 'Po godzinach';
        s.trades.push({
          id: AV.uid('manual'), date: tradeDate, exit: 'otwarta', result: null, r: null,
          mae: null, mfe: null, durationMin: 0, fee: 0, source: 'Ręcznie', account: s.accounts.find((a) => a.include)?.name || s.accounts[0]?.name || 'Konto główne',
          session, screens: AV.ui.ntScreens || {}, ...payload
        });
        AV.toast('Transakcja zapisana');
      }
      const editing = !!AV.ui.editId;
      AV.ui.editId = null; AV.ui.ntExecutionLocked = false; AV.ui.ntScreens = {}; AV.ui.ntChecklistHistory = []; AV.ui.ntChecklistTouched = false; AV.saveNow();
      if (!editing) AV.safeDel(AV.draftKey());
      AV.ui.jFilter = 'all'; AV.ui.jGrade = 'all'; AV.ui.jSearch = '';
      if ($('#jSearch')) $('#jSearch').value = '';
      AV.renderAll(); AV.showView('trans');
    };
    if (!executionLocked && riskPct != null && riskPct > maxRiskPct + 1e-9) {
      AV.confirm({
        title: 'Ryzyko powyżej limitu',
        text: 'Ta pozycja ryzykuje ' + AV.fmt(risk, 2) + ' ' + AV.curLabel() + ' (' + AV.fmt(riskPct, 2) + '% kapitału), a limit wynosi ' + AV.fmt(maxRiskPct, 2) + '%. Zapisać mimo to?',
        confirmLabel: 'Zapisz mimo limitu', danger: true, onConfirm: persist
      });
      return;
    }
    persist();
  };

  AV.bindDrops = (root, onChange) => {
    root.querySelectorAll('[data-drop]').forEach((d, idx) => {
      if (d._bound) return;
      d._bound = true;
      const inp = d.querySelector('input');
      const key = d.dataset.shot || (root.id === 'nowa' ? (idx === 0 ? 'before' : 'after') : 'before');
      const set = async (file) => {
        try {
          const data = await AV.readImage(file);
          AV.showImage(d, data);
          if (onChange) onChange(data, key);
          else {
            AV.ui.ntScreens = AV.ui.ntScreens || {};
            AV.ui.ntScreens[key] = data;
            AV.saveDraft();
            AV.toast('Screen dodany');
          }
        } catch (err) { AV.toast(err.message || 'Nie udało się dodać obrazu'); }
      };
      inp.onchange = () => set(inp.files[0]);
      d.ondragover = (e) => { e.preventDefault(); d.classList.add('over'); };
      d.ondragleave = () => d.classList.remove('over');
      d.ondrop = (e) => { e.preventDefault(); d.classList.remove('over'); set(e.dataTransfer.files[0]); };
      d.querySelector('.x').onclick = (e) => {
        e.preventDefault(); e.stopPropagation();
        d.querySelector('img')?.remove(); d.classList.remove('has'); inp.value = '';
        if (onChange) onChange(null, key);
        else { AV.ui.ntScreens = AV.ui.ntScreens || {}; delete AV.ui.ntScreens[key]; AV.saveDraft(); }
        AV.toast('Screen usunięty');
      };
      d.onclick = (e) => {
        if (e.target.closest('.x') || e.target === inp) return;
        inp.click();
      };
    });
  };

  document.addEventListener('paste', (e) => {
    if (AV.currentView !== 'nowa' || !$('#nowa')?.classList.contains('on')) return;
    if (AV.lockInfo(AV.state).active) return;
    const f = [...(e.clipboardData?.files || [])][0];
    if (!f) return;
    AV.readImage(f).then((data) => {
      AV.ui.ntScreens = AV.ui.ntScreens || {};
      const key = !AV.ui.ntScreens.before ? 'before' : 'after';
      AV.ui.ntScreens[key] = data;
      AV.renderDrops(AV.ui.ntScreens); AV.saveDraft();
      AV.toast('Screen wklejony');
    }).catch((err) => AV.toast(err.message));
  });

  /* ---------- kalkulator ---------- */
  AV.calc = { mode: 'crypto', dir: 'long', accountId: '', rrMode: '3', rrCustom: '' };
  AV.calcRR = () => {
    if (AV.calc.rrMode === 'custom') {
      const n = AV.pnum(AV.calc.rrCustom);
      return n > 0 ? n : 3;
    }
    return Number(AV.calc.rrMode) || 3;
  };
  AV.calcRRLabel = () => {
    const rr = AV.calcRR();
    return '1 : ' + (Number.isInteger(rr) ? rr : AV.fmt(rr, 1));
  };
  AV.syncCalcRRUI = () => {
    const sel = $('#cRR');
    if (sel) sel.value = AV.calc.rrMode;
    const wrap = $('#cRRCustomWrap');
    if (wrap) wrap.style.display = AV.calc.rrMode === 'custom' ? '' : 'none';
    const btn = $('#cAuto');
    if (btn) btn.textContent = 'Zaproponuj SL i TP (' + AV.calcRRLabel() + ')';
  };
  AV.renderCalcPortfolios = () => {
    const sel = $('#cPortfolio');
    if (!sel) return;
    const accounts = (AV.state && Array.isArray(AV.state.accounts)) ? AV.state.accounts : [];
    sel.innerHTML = '<option value="">Bez portfela (ręcznie)</option>' + accounts.map((a) =>
      '<option value="' + AV.attr(a.id) + '">' + AV.esc(a.name) + ' · ' + AV.fmt(a.balance, 2) + ' ' + AV.esc(a.currency || 'USDT') + '</option>').join('');
    sel.value = accounts.some((a) => a.id === AV.calc.accountId) ? AV.calc.accountId : '';
    AV.updateCalcMM();
  };
  AV.updateCalcMM = () => {
    const el = $('#cMM');
    if (!el) return;
    const s = AV.state || {};
    const risk = Number((s.settings || {}).maxRisk) || 1;
    const acc = (s.accounts || []).find((a) => a.id === AV.calc.accountId);
    el.textContent = risk + '% kapitału' + (acc ? ' · ' + acc.name : ' · bez portfela');
    el.title = 'Limit ryzyka z sekcji Konto i zasady. Możesz go zmienić ręcznie w polu ryzyka.';
  };
  AV.applyCalcPortfolio = () => {
    const s = AV.state || {};
    const acc = (s.accounts || []).find((a) => a.id === AV.calc.accountId);
    if (acc) {
      const bal = Number(acc.balance) || Number(acc.start) || 0;
      AV.setVal('#cBal', String(bal));
      const risk = Number((s.settings || {}).maxRisk) || 1;
      AV.setVal('#cRisk', String(risk));
      const lev = parseInt(String(acc.lev || '').replace(/[^0-9]/g, ''), 10);
      if (lev > 0) AV.setVal('#cLev', String(lev));
    }
    AV.updateCalcMM();
    AV.calcResult();
    AV.saveCalcState();
  };
  AV.saveCalcState = () => {
    if (!AV.state) return;
    AV.state.calc = {
      mode: AV.calc.mode, dir: AV.calc.dir, accountId: AV.calc.accountId,
      rrMode: AV.calc.rrMode, rrCustom: AV.calc.rrCustom,
      inst: +($('#cInst')?.value || 0),
      bal: $('#cBal')?.value || '', lev: $('#cLev')?.value || '', risk: $('#cRisk')?.value || '',
      en: $('#cEn')?.value || '', sl: $('#cSl')?.value || '', tp: $('#cTp')?.value || ''
    };
    AV.save();
  };
  AV.restoreCalc = () => {
    const c = (AV.state && AV.state.calc) || {};
    AV.calc.mode = c.mode === 'fx' ? 'fx' : 'crypto';
    AV.calc.dir = c.dir === 'short' ? 'short' : 'long';
    AV.calc.accountId = c.accountId || '';
    AV.calc.rrMode = ['1', '1.2', '1.5', '2', '3', '5', 'custom'].includes(String(c.rrMode)) ? String(c.rrMode) : '3';
    AV.calc.rrCustom = c.rrCustom || '';
    $$('#calcMode button').forEach((x) => x.classList.toggle('on', x.dataset.m === AV.calc.mode));
    $$('#cDir .chip').forEach((x) => x.classList.toggle('on', x.dataset.d === AV.calc.dir));
    AV.fillInst(false);
    const instSel = $('#cInst');
    if (instSel && Number.isInteger(c.inst) && instSel.options[c.inst]) instSel.value = String(c.inst);
    AV.setPrice();
    if (typeof c.bal === 'string' && c.bal) AV.setVal('#cBal', c.bal);
    if (typeof c.lev === 'string' && c.lev) AV.setVal('#cLev', c.lev);
    if (typeof c.risk === 'string' && c.risk) AV.setVal('#cRisk', c.risk);
    if (typeof c.en === 'string' && c.en) AV.setVal('#cEn', c.en);
    if (typeof c.sl === 'string' && c.sl) AV.setVal('#cSl', c.sl);
    if (typeof c.tp === 'string' && c.tp) AV.setVal('#cTp', c.tp);
    if (!AV.pnum($('#cEn')?.value)) AV.autoLv();
    AV.syncCalcRRUI();
    AV.renderCalcPortfolios();
    AV.calcResult();
  };
  AV.fillInst = (withQuotes = true) => {
    const list = AV.INST[AV.calc.mode];
    AV.setHTML('#cInst', list.map((x, i) => '<option value="' + i + '">' + AV.esc(x[0]) + '</option>').join(''));
    AV.show('#levWrap', AV.calc.mode === 'crypto');
    AV.selectInstrument(withQuotes);
  };
  AV.selectInstrument = (withQuotes = true) => {
    AV.setPrice(true);
    AV.autoLv();
    if (withQuotes) AV.loadInstrumentQuotes();
  };
  AV.setPrice = (resetEntry = false) => {
    const x = AV.INST[AV.calc.mode][+$('#cInst').value || 0];
    if (!x) return;
    const q = AV.instrumentQuote(x[0]);
    const badge = q
      ? '<span class="win" style="font-size:11px;display:flex;align-items:center;gap:6px"><span class="pulse" style="width:6px;height:6px"></span>live</span>'
      : '<span class="dim" style="font-size:11px">brak notowań</span>';
    AV.setHTML('#cPrice', '<b>' + (q ? AV.fmt(q.price, AV.dec(q.price)) : '—') + '</b>' + badge);
    if (resetEntry && q) AV.setVal('#cEn', '');
    AV.calcResult();
  };
  AV.autoLv = (force = false) => {
    const x = AV.INST[AV.calc.mode][+$('#cInst').value || 0];
    if (!x) return;
    const q = AV.instrumentQuote(x[0]);
    if (!q) { AV.calcResult(); return; }
    const p = q.price, k = AV.calc.mode === 'crypto' ? 0.012 : x[2] === 'fx' ? 0.004 : 0.008;
    const s = AV.calc.dir === 'long' ? 1 : -1;
    const rr = AV.calcRR();
    const typedEntry = AV.pnum($('#cEn')?.value);
    const entry = typedEntry > 0 ? typedEntry : p;
    if (!(typedEntry > 0)) AV.setVal('#cEn', AV.fmt(entry, AV.dec(entry)));
    if (force || !AV.pnum($('#cSl')?.value)) AV.setVal('#cSl', AV.fmt(entry * (1 - s * k), AV.dec(entry)));
    if (force || !AV.pnum($('#cTp')?.value)) AV.setVal('#cTp', AV.fmt(entry * (1 + s * k * rr), AV.dec(entry)));
    AV.calcResult();
  };
  AV.pipVal = (x, pr) => {
    if (x[4] === 'jpy') return 1000 / pr;
    if (x[4] === 'quote') return 10 / pr;
    if (x[4] === 'gbp') return 12.955;
    if (x[2] === 'fx') return 10;
    return x[4];
  };
  AV.stepsHTML = (a) => a.map((s, i) => '<li><span class="n">' + (i + 1) + '</span><span><b>' + s[0] + '</b> · ' + s[1] + ': ' + s[2] + '</span></li>').join('');
  AV.calcResult = () => {
    const bal = AV.pnum($('#cBal').value), risk = AV.pnum($('#cRisk').value), lev = Math.max(1, AV.pnum($('#cLev').value));
    const en = AV.pnum($('#cEn').value), sl = AV.pnum($('#cSl').value), tp = AV.pnum($('#cTp').value);
    const fxMode = AV.calc.mode !== 'crypto';
    AV.setText('#rMainL', fxMode ? 'Wielkość pozycji (MT4 / MT5)' : 'Za tę kwotę wejdź w trade');
    AV.setText('#rNotL', fxMode ? 'Odległość SL' : 'Wartość pozycji');
    const w = $('#cWarn');
    if (w) w.style.display = 'none';
    $('#cWarn2').style.display = risk > 5 ? 'block' : 'none';
    $('#rBar').style.left = Math.min(98, risk * 10) + '%';
    const set = (a, b, c, d, e, f) => {
      AV.setText('#rMain', a); AV.setText('#rLoss', b); AV.setText('#rWin', c);
      AV.setText('#rNot', d); AV.setText('#rRR', e); AV.setText('#rRRh', f || '');
    };
    if (!bal || !risk || !en || !sl) {
      set('—', '—', '—', '—', '—');
      AV.setHTML('#rSteps', '<li>Wpisz saldo, ryzyko, wejście i stop loss.</li>');
      return;
    }
    const bad = AV.calc.dir === 'long' ? (sl >= en || (tp && tp <= en)) : (sl <= en || (tp && tp >= en));
    if (bad) {
      if (w) {
        w.textContent = AV.calc.dir === 'long' ? 'Przy LONG stop loss musi być PONIŻEJ wejścia, a take profit POWYŻEJ.' : 'Przy SHORT stop loss musi być POWYŻEJ wejścia, a take profit PONIŻEJ.';
        w.style.display = 'block';
      }
      set('—', '—', '—', '—', '—');
      AV.setHTML('#rSteps', '');
      return;
    }
    const ml = bal * risk / 100, slP = Math.abs(en - sl) / en, tpP = tp ? Math.abs(tp - en) / en : 0;
    const rr = tpP / slP;
    const breakeven = rr > 0 ? Math.round(100 / (1 + rr)) : null;
    const rrh = breakeven == null ? '' : breakeven <= 50 ? 'wystarczy ~' + breakeven + '% skuteczności' : 'poniżej 1:1, przemyśl cel';
    if (AV.calc.mode === 'crypto') {
      const quantity = ml / Math.abs(en - sl), no = quantity * en, mg = no / lev, pf = quantity * Math.abs(tp - en);
      const instrument = AV.INST.crypto[+$('#cInst').value || 0]?.[0] || 'BTCUSDT';
      const baseUnit = String(instrument).split('/')[0].replace(/(?:USDT|USDC|BUSD|USD)$/i, '') || 'units';
      AV.setText('#rMainL', 'Wielkość pozycji (' + baseUnit + ')');
      AV.setText('#rMainH', 'Notional ' + AV.fmt(no, 2) + ' $ · depozyt ' + AV.fmt(mg, 2) + ' $ przy dźwigni ' + lev + '× · ryzyko ' + AV.fmt(ml, 2) + ' $');
      AV.setText('#rNotL', 'Wartość pozycji (notional)');
      set(AV.fmt(quantity, 6) + ' ' + baseUnit, '−' + AV.fmt(ml) + ' $', tp ? '+' + AV.fmt(pf) + ' $' : '—', AV.fmt(no) + ' $', tp ? '1 : ' + AV.fmt(rr, 2) : '—', rrh);
      AV.setHTML('#rSteps', AV.stepsHTML([
        ['Max strata', 'saldo × ryzyko', '<b>' + AV.fmt(ml) + ' $</b>'],
        ['Odległość SL', '|wejście − SL| ÷ wejście', '<b>' + AV.fmt(slP * 100, 2) + '%</b>'],
        ['Ilość bazowa', 'max strata ÷ |wejście − SL|', '<b>' + AV.fmt(quantity, 6) + ' ' + baseUnit + '</b>'],
        ['Wartość pozycji', 'ilość bazowa × wejście', '<b>' + AV.fmt(no) + ' $</b>'],
        ['Depozyt', 'wartość pozycji ÷ dźwignia', '<b>' + AV.fmt(mg) + ' $</b>'],
        ['Zysk przy TP', 'wartość × TP%', tp ? '<b>' + AV.fmt(pf) + ' $</b>' : '—']
      ]));
    } else {
      const x = AV.INST[AV.calc.mode][+$('#cInst').value || 0];
      const pip = x[3], sp = Math.abs(en - sl) / pip, tpp = tp ? Math.abs(tp - en) / pip : 0;
      const pv = AV.pipVal(x, en), lots = ml / (sp * pv), pf = lots * tpp * pv;
      const u = x[2] === 'ind' ? 'pkt' : 'pips';
      AV.setText('#rMainL', 'Wielkość pozycji (MT4 / MT5)');
      AV.setText('#rMainH', '1 lot · ' + AV.fmt(pv, 2) + ' $ za ' + (u === 'pkt' ? 'punkt' : 'pip') + ' · sprawdź specyfikację u brokera');
      AV.setText('#rNotL', 'Odległość SL');
      set(AV.fmt(lots, 2) + ' lota', '−' + AV.fmt(ml) + ' $', tp ? '+' + AV.fmt(pf) + ' $' : '—', AV.fmt(sp, 1) + ' ' + u, tp ? '1 : ' + AV.fmt(rr, 2) : '—', rrh);
      AV.setHTML('#rSteps', AV.stepsHTML([
        ['Max strata', 'saldo × ryzyko', '<b>' + AV.fmt(ml) + ' $</b>'],
        ['SL w ' + u, '|wejście − SL| ÷ ' + pip, '<b>' + AV.fmt(sp, 1) + '</b>'],
        ['Wartość ' + (u === 'pkt' ? 'punktu' : 'pipsa'), 'na 1 lot', '<b>' + AV.fmt(pv, 2) + ' $</b>'],
        ['Loty', 'max strata ÷ (SL × wartość)', '<b>' + AV.fmt(lots, 2) + '</b>'],
        ['Zysk przy TP', 'loty × TP × wartość', tp ? '<b>' + AV.fmt(pf) + ' $</b>' : '—']
      ]));
    }
  };
  AV.addAvgRow = (price = '', pct = '') => {
    const host = $('#avgRows');
    if (!host) return;
    host.insertAdjacentHTML('beforeend', '<div class="avg-row" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.8fr) auto 28px;gap:8px;margin-top:8px"><input class="input mono avg-price" inputmode="decimal" placeholder="Cena" value="' + AV.attr(price) + '"><input class="input mono avg-pct" inputmode="decimal" placeholder="% kapitału" value="' + AV.attr(pct) + '"><span class="dim mono avg-weight" style="align-self:center;font-size:12px">—</span><span class="x" data-avg-del style="cursor:pointer;color:var(--dim);text-align:center">✕</span></div>');
    AV.calcAverage();
  };
  AV.calcAverage = () => {
    const rows = AV.$$('#avgRows .avg-row');
    let weight = 0, sum = 0;
    rows.forEach((row) => {
      const price = AV.pnum(row.querySelector('.avg-price')?.value);
      const pct = AV.pnum(row.querySelector('.avg-pct')?.value);
      const out = row.querySelector('.avg-weight');
      if (price > 0 && pct > 0) {
        weight += pct;
        sum += price * pct;
        if (out) out.textContent = AV.fmt(price * pct / 100, AV.dec(price));
      } else if (out) out.textContent = '—';
    });
    const avg = weight > 0 ? sum / weight : null;
    AV.setText('#avgOut', avg
      ? 'Średnia cena wejścia: ' + AV.fmt(avg, AV.dec(avg)) + ' · łącznie ' + AV.fmt(weight, 2) + '% kapitału'
      : 'Dodaj ceny wejść i udział kapitału, żeby policzyć średnią.');
    AV.ui.avgEntry = avg;
    AV.calcTargets();
    return avg;
  };
  AV.addTpRow = (price = '', pct = '') => {
    const host = $('#tpRows');
    if (!host) return;
    host.insertAdjacentHTML('beforeend', '<div class="tp-row" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.8fr) 28px;gap:8px;margin-top:8px"><input class="input mono tp-price" inputmode="decimal" placeholder="Cena TP" value="' + AV.attr(price) + '"><input class="input mono tp-pct" inputmode="decimal" placeholder="% pozycji" value="' + AV.attr(pct) + '"><span class="x" data-tp-del style="cursor:pointer;color:var(--dim);text-align:center">✕</span></div>');
    AV.calcTargets();
  };
  AV.calcTargets = () => {
    const rows = AV.$$('#tpRows .tp-row');
    let weight = 0, move = 0;
    rows.forEach((row) => {
      const tp = AV.pnum(row.querySelector('.tp-price')?.value);
      const pct = AV.pnum(row.querySelector('.tp-pct')?.value);
      const entry = AV.pnum($('#cEn')?.value) || AV.ui.avgEntry;
      if (tp > 0 && pct > 0 && entry > 0) {
        const sign = AV.calc.dir === 'long' ? 1 : -1;
        weight += pct;
        move += ((tp - entry) / entry * sign) * pct;
      }
    });
    if (!(weight > 0)) {
      AV.setText('#tpOut', 'Dodaj poziomy TP i procent pozycji, żeby policzyć łączny zysk.');
      return;
    }
    const weightedMove = move / weight;
    const entry = AV.pnum($('#cEn')?.value) || AV.ui.avgEntry;
    const sl = AV.pnum($('#cSl')?.value);
    const bal = AV.pnum($('#cBal')?.value), risk = AV.pnum($('#cRisk')?.value);
    const slP = entry > 0 && sl > 0 ? Math.abs(entry - sl) / entry : 0;
    const rr = slP > 0 ? weightedMove / slP : null;
    const notional = slP > 0 && bal > 0 && risk > 0 ? (bal * risk / 100) / slP : null;
    const profit = notional != null ? notional * weightedMove * Math.min(weight, 100) / 100 : null;
    AV.setText('#tpOut', 'Łączny zysk przy TP: ' + (profit != null ? AV.signed(profit, 2, ' $') : '—') + ' · ważone R:R ' + (rr != null ? '1 : ' + AV.fmt(rr, 2) : '—') + ' · pokrycie ' + AV.fmt(weight, 2) + '% pozycji');
  };
  AV.suggestSize = () => {
    const bal = AV.pnum($('#cBal')?.value), risk = AV.pnum($('#cRisk')?.value);
    const en = AV.pnum($('#cEn')?.value), sl = AV.pnum($('#cSl')?.value), lev = Math.max(1, AV.pnum($('#cLev')?.value));
    if (!(bal > 0) || !(risk > 0) || !(en > 0) || !(sl > 0)) { AV.toast('Podaj saldo, ryzyko, cenę wejścia i stop loss'); return; }
    if (AV.calc.dir === 'long' ? sl >= en : sl <= en) { AV.toast('Stop loss jest po złej stronie wejścia'); return; }
    AV.calcResult();
    const ml = bal * risk / 100, slP = Math.abs(en - sl) / en;
    if (AV.calc.mode === 'crypto') {
      const quantity = ml / Math.abs(en - sl);
      const notional = quantity * en, margin = notional / lev;
      const instrument = AV.INST.crypto[+$('#cInst').value || 0]?.[0] || 'BTCUSDT';
      const baseUnit = String(instrument).split('/')[0].replace(/(?:USDT|USDC|BUSD|USD)$/i, '') || 'units';
      AV.setText('#rMainL', 'Proponowana wielkość pozycji (' + baseUnit + ')');
      AV.setText('#rMain', AV.fmt(quantity, 6) + ' ' + baseUnit);
      AV.setText('#rMainH', 'Notional ' + AV.fmt(notional, 2) + ' $ · depozyt ' + AV.fmt(margin, 2) + ' $ przy dźwigni ' + lev + '× · ryzyko ' + AV.fmt(ml, 2) + ' $');
      AV.toast('Wielkość pozycji: ' + AV.fmt(quantity, 6) + ' ' + baseUnit + ', ryzyko ' + AV.fmt(ml, 2) + ' $');
    } else {
      const x = AV.INST[AV.calc.mode][+$('#cInst').value || 0];
      const pip = x[3], sp = Math.abs(en - sl) / pip, pv = AV.pipVal(x, en), lots = ml / (sp * pv);
      AV.setText('#rMainL', 'Proponowana wielkość pozycji');
      AV.setText('#rMain', AV.fmt(lots, 2) + ' lota');
      AV.setText('#rMainH', 'SL ' + AV.fmt(sp, 1) + ' pipsa · ryzyko ' + AV.fmt(ml, 2) + ' $');
      AV.toast('Wchodzisz ' + AV.fmt(lots, 2) + ' lota, ryzykując ' + AV.fmt(ml, 2) + ' $');
    }
    $('#rMain')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  AV.bindCalc = () => {
    $$('#calcMode button').forEach((b) => b.addEventListener('click', () => {
      AV.calc.mode = b.dataset.m;
      $$('#calcMode button').forEach((x) => x.classList.toggle('on', x === b));
      AV.fillInst();
      AV.saveCalcState();
    }));
    $('#cInst')?.addEventListener('change', () => { AV.selectInstrument(); AV.saveCalcState(); });
    $$('#cDir .chip').forEach((c) => c.addEventListener('click', () => {
      AV.calc.dir = c.dataset.d;
      $$('#cDir .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.autoLv(true);
      AV.calcTargets();
      AV.saveCalcState();
    }));
    $$('.qk').forEach((q) => q.querySelectorAll('.chip').forEach((c) => c.onclick = () => {
      $('#' + q.dataset.for).value = c.dataset.v;
      q.querySelectorAll('.chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.calcResult();
      AV.saveCalcState();
    }));
    const persist = AV.debounce(() => AV.saveCalcState(), 400);
    ['#cBal', '#cLev', '#cRisk', '#cEn', '#cSl', '#cTp'].forEach((i) => $(i)?.addEventListener('input', () => { AV.calcResult(); AV.calcTargets(); persist(); }));
    $('#cAuto')?.addEventListener('click', () => { AV.autoLv(true); AV.calcTargets(); AV.saveCalcState(); });
    $('#cSize')?.addEventListener('click', () => { AV.suggestSize(); AV.calcTargets(); });
    $('#addAvg')?.addEventListener('click', () => { AV.addAvgRow(); AV.saveCalcState(); });
    $('#addTp')?.addEventListener('click', () => { AV.addTpRow(); });
    $('#avgRows')?.addEventListener('input', () => { AV.calcAverage(); persist(); });
    $('#avgRows')?.addEventListener('click', (e) => {
      if (e.target.closest('[data-avg-del]')) { e.target.closest('.avg-row')?.remove(); AV.calcAverage(); persist(); }
    });
    $('#tpRows')?.addEventListener('input', () => AV.calcTargets());
    $('#tpRows')?.addEventListener('click', (e) => {
      if (e.target.closest('[data-tp-del]')) { e.target.closest('.tp-row')?.remove(); AV.calcTargets(); }
    });
    $('#useAvg')?.addEventListener('click', () => {
      const avg = AV.calcAverage();
      if (!(avg > 0)) { AV.toast('Dodaj co najmniej jedno wejście z ceną i udziałem'); return; }
      AV.setVal('#cEn', AV.fmt(avg, AV.dec(avg)));
      AV.calcResult(); AV.calcTargets(); AV.saveCalcState();
      AV.toast('Średnia cena wejścia ustawiona');
    });
    $('#cPortfolio')?.addEventListener('change', (e) => {
      AV.calc.accountId = e.target.value;
      AV.applyCalcPortfolio();
    });
    $('#cRR')?.addEventListener('change', (e) => {
      AV.calc.rrMode = e.target.value;
      AV.syncCalcRRUI();
      if (AV.calc.rrMode === 'custom') $('#cRRCustom')?.focus();
      AV.saveCalcState();
    });
    $('#cRRCustom')?.addEventListener('input', AV.debounce(() => {
      AV.calc.rrCustom = $('#cRRCustom').value;
      AV.syncCalcRRUI();
      AV.saveCalcState();
    }, 250));
    $('#cReset')?.addEventListener('click', () => {
      AV.calc.accountId = ''; AV.calc.rrMode = '3'; AV.calc.rrCustom = '';
      if (AV.state) delete AV.state.calc;
      AV.setVal('#cBal', '1000'); AV.setVal('#cLev', '50'); AV.setVal('#cRisk', '1');
      AV.setVal('#cEn', ''); AV.setVal('#cSl', ''); AV.setVal('#cTp', '');
      $$('.qk .chip').forEach((x) => x.classList.remove('on'));
      AV.syncCalcRRUI();
      AV.renderCalcPortfolios();
      AV.autoLv();
      if (AV.state) AV.saveNow();
    });
    AV.restoreCalc();
  };

  /* ---------- mentor ---------- */
  AV._bags = [];
  AV.pickAnswer = (i) => {
    if (!AV._bags[i] || !AV._bags[i].length) AV._bags[i] = [...Array(AV.MENTOR[i].a.length).keys()].sort(() => Math.random() - 0.5);
    return AV._bags[i].pop();
  };
  AV.ensureChat = () => {
    if (AV.state.chat.length) return;
    AV.state.chat.push({ role: 'a', html: AV.fill('Cześć {imie}. Widzę dziś {straty} straty i gotowość {gotowosc}/100, więc jestem w pogotowiu. Wybierz poniżej, o czym chcesz pogadać.', AV.state), at: Date.now() });
    AV.saveNow();
  };
  AV.renderQ = () => {
    AV.setHTML('#mChips', AV.MENTOR.map((x, i) => '<span class="chip" data-q="' + i + '">' + AV.esc(x.q) + '</span>').join(''));
  };
  AV.ask = (i) => {
    if (AV._thinking) return;
    const q = AV.MENTOR[i]; if (!q) return;
    AV._thinking = true;
    AV.state.chat.push({ role: 'u', html: AV.esc(q.q), at: Date.now() });
    AV.renderMentor();
    const msgs = $('#msgs');
    msgs.insertAdjacentHTML('beforeend', '<div class="msg a" id="typ"><div class="who">Î± MENTOR</div><span class="typing"><i></i><i></i><i></i></span></div>');
    msgs.scrollTop = 1e6;
    $('#mChips').style.pointerEvents = 'none';
    setTimeout(() => {
      const idx = AV.pickAnswer(i);
      AV.state.chat.push({ role: 'a', html: AV.fill(q.a[idx], AV.state), at: Date.now() });
      AV.chatTrim();
      AV.saveNow();
      AV.renderMentor();
      AV._thinking = false;
      $('#mChips').style.pointerEvents = '';
    }, 950);
  };
  AV.chatTrim = () => {
    if (AV.state.chat.length > 60) AV.state.chat = AV.state.chat.slice(-60);
  };

  AV.bindMentor = () => {
    const tabs = $$('#mcpTabs button');
    $('#mcpTabs')?.addEventListener('click', (e) => {
      const b = e.target.closest('button[data-mcp-client]');
      if (!b) return;
      AV.mcpClient = b.dataset.mcpClient;
      AV.renderMcpGuide();
    });
    $('#mcpTabs')?.addEventListener('keydown', (e) => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
      const current = tabs.findIndex((b) => b.getAttribute('aria-selected') === 'true');
      if (current < 0) return;
      e.preventDefault();
      let next = current;
      if (e.key === 'ArrowRight') next = (current + 1) % tabs.length;
      if (e.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
      if (e.key === 'Home') next = 0;
      if (e.key === 'End') next = tabs.length - 1;
      AV.mcpClient = tabs[next].dataset.mcpClient;
      AV.renderMcpGuide();
      tabs[next].focus();
    });
    $('#mcpCopy')?.addEventListener('click', async () => {
      const url = ($('#mcpUrl')?.textContent || '').trim();
      if (!url) return;
      let copied = true;
      try {
        await navigator.clipboard.writeText(url);
      } catch {
        copied = false;
      }
      if (!copied) {
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch {}
        ta.remove();
      }
      const btn = $('#mcpCopy');
      if (btn) {
        btn.classList.add('copied');
        AV.setText('#mcpCopyLabel', 'Skopiowano');
        setTimeout(() => {
          btn.classList.remove('copied');
          AV.setText('#mcpCopyLabel', 'Kopiuj adres');
        }, 1800);
      }
      AV.toast(copied ? 'Adres serwera MCP skopiowany' : 'Skopiuj adres ręcznie');
    });
    $('#mcpBack')?.addEventListener('click', (e) => {
      e.preventDefault();
      $('#mcpTabs')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    $('#proClose')?.addEventListener('click', () => $('#pro').classList.remove('on'));
    $('#proGo')?.addEventListener('click', () => { $('#pro').classList.remove('on'); AV.showView('mentor'); });
  };

  /* ---------- akademia ---------- */
  AV.bindAcademy = () => {
    $('#liveRem')?.addEventListener('click', () => {
      AV.state.meta.liveReminder = !AV.state.meta.liveReminder;
      AV.saveNow();
      AV.ensurePermission();
      AV.renderAcademy();
      const m = AV.state.settings.liveReminderMinutes || 15;
      AV.toast(AV.state.meta.liveReminder ? 'Przypomnę ' + m + ' min przed live' : 'Przypomnienie wyłączone');
    });
    $('#liveRemMin')?.addEventListener('change', (e) => {
      const v = Number(e.target.value);
      AV.state.settings.liveReminderMinutes = [5, 15, 30, 60].includes(v) ? v : 15;
      AV.saveNow();
      AV.renderAcademy();
      AV.toast('Przypomnienie: ' + AV.state.settings.liveReminderMinutes + ' min przed live');
    });
    $('#acadSeg')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      const next = b.dataset.s === 'month' ? 'month' : 'week';
      if (next !== AV.ui.acadScale) AV.ui.acadOffset = 0;
      AV.ui.acadScale = next;
      AV.renderAcademy();
    });
    $$('#akad [data-aw]').forEach((b) => b.addEventListener('click', () => {
      const v = b.dataset.aw;
      if (v === 'today') AV.ui.acadOffset = 0;
      else AV.ui.acadOffset = (AV.ui.acadOffset || 0) + (Number(v) || 0);
      AV.ui.acadOffset = Math.max(-12, Math.min(24, AV.ui.acadOffset));
      AV.renderAcademy();
    }));
    $('#recCats')?.addEventListener('click', (e) => {
      const b = e.target.closest('[data-rcat]'); if (!b) return;
      AV.ui.recCat = b.dataset.rcat;
      AV.renderAcademy();
    });
    $('#glossaryOpen')?.addEventListener('click', AV.openGlossary);
    $('#alphaTestsOpen')?.addEventListener('click', AV.openAlphaTest);
    $('#goldenRulesOpen')?.addEventListener('click', AV.openGoldenRules);
    $('#bookBtn')?.addEventListener('click', () => {
      const options = [];
      let d = AV.addDays(new Date(), 1);
      while (options.length < 6) {
        if (d.getDay() !== 0 && d.getDay() !== 6) options.push(new Date(d));
        d = AV.addDays(d, 1);
      }
      const body = AV.openModal('<div class="eyebrow">Konsultacje 1:1</div><h1 style="font-size:26px;margin-top:6px">Zarezerwuj kolejną</h1><p class="mut" style="margin-top:8px;font-size:13.5px">Wybierz termin. Rezerwacja jest lokalna — w wersji produkcyjnej potwierdzi się w kalendarzu.</p><div class="grid" style="margin-top:14px;gap:8px" id="bookSlots">' +
        options.map((x, i) => '<button class="btn g" data-slot="' + i + '" style="justify-content:flex-start">' + x.toLocaleDateString('pl-PL', { weekday: 'long', day: '2-digit', month: '2-digit' }) + ' · 18:00</button>').join('') + '</div>', { maxWidth: '520px' });
      $$('#bookSlots [data-slot]', body).forEach((b) => b.onclick = () => {
        const d2 = options[+b.dataset.slot];
        AV.state.meta.consultation = { date: AV.dateKey(d2), time: '18:00', who: 'Marcin' };
        AV.state.meta.consultationUsed = Math.min(3, (AV.state.meta.consultationUsed || 0) + 1);
        AV.saveNow(); AV.closeModal(); AV.renderAcademy(); AV.renderUser();
        AV.toast('Zarezerwowano: ' + d2.toLocaleDateString('pl-PL', { weekday: 'long', day: '2-digit', month: '2-digit' }) + ', 18:00');
      });
    });
    $('#shareTrade')?.addEventListener('click', () => { AV.showView('trans'); AV.toast('Kliknij transakcję, którą chcesz omówić'); });
    document.querySelector('#adminEvents')?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-event-category]');
      if (!btn) return;
      const ok = await AV.setEventCategory(btn.dataset.eventId, btn.dataset.eventCategory);
      AV.toast(ok ? 'Kategoria wydarzenia zapisana' : 'Nie udało się zapisać kategorii');
    });
    $('#akBack')?.addEventListener('click', () => AV.setPlan(false));
    $('#recordsAll')?.addEventListener('click', () => AV.openRecordings());
    $('#courseOpen')?.addEventListener('click', () => AV.openCourse());
    $('#bootcampOpen')?.addEventListener('click', () => AV.openBootcamp());
    document.addEventListener('change', (e) => {
      const course = e.target.closest('[data-course-mod]');
      if (course) {
        const idx = Number(course.dataset.courseMod);
        const current = new Set(AV.state.meta.courseDone || []);
        if (course.checked) current.add(idx); else current.delete(idx);
        AV.state.meta.courseDone = Array.from(current).sort((a, b) => a - b);
        AV.state.meta.courseProgress = Math.round(current.size / Math.max(1, (AV.COURSE_MODULES || []).length) * 100);
        AV.saveNow();
        AV.renderAcademy();
        AV.setText('#courseModalPct', AV.coursePct() + '%');
        return;
      }
      const bootcamp = e.target.closest('[data-bootcamp-mod]');
      if (bootcamp) {
        const idx = Number(bootcamp.dataset.bootcampMod);
        const current = new Set(AV.state.meta.bootcampDone || []);
        if (bootcamp.checked) current.add(idx); else current.delete(idx);
        AV.state.meta.bootcampDone = Array.from(current).sort((a, b) => a - b);
        AV.saveNow();
        AV.renderAcademy();
        AV.setText('#bootcampModalDone', AV.bootcampDoneCount() + ' z ' + (AV.BOOTCAMP_MODULES || []).length);
      }
    });
    document.addEventListener('click', (e) => {
      const rec = e.target.closest('[data-recording]');
      if (rec) AV.openRecording(rec.dataset.recording);
    });
  };

  AV.recordingList = () => {
    const saved = Array.isArray(AV.state?.recordings) ? AV.state.recordings : [];
    return saved.length ? saved : (AV.RECORDINGS || []);
  };

  AV.youtubeId = (url) => {
    const m = String(url || '').match(/(?:youtube\.com\/(?:watch\?.*v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{6,})/);
    return m ? m[1] : '';
  };
  AV.openRecording = (key) => {
    const list = AV.recordingList();
    const rec = list.find((r) => r.id === key) || list.find((r) => r.title === key) || null;
    const title = rec ? rec.title : String(key || 'Nagranie');
    const url = rec ? String(rec.url || '') : '';
    const yt = AV.youtubeId(url);
    const meta = rec ? [rec.category, rec.meta].filter(Boolean).join(' · ') : '';
    let content;
    if (yt) {
      content = '<div style="position:relative;padding-top:56.25%;border-radius:16px;overflow:hidden;margin-top:16px;background:#000"><iframe src="https://www.youtube.com/embed/' + AV.attr(yt) + '" title="' + AV.attr(title) + '" style="position:absolute;inset:0;width:100%;height:100%;border:0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>' +
        '<a class="btn g sm" style="margin-top:12px;display:inline-flex" href="' + AV.attr(url) + '" target="_blank" rel="noopener">Otwórz w YouTube ↗</a>';
    } else if (url) {
      content = '<div class="ticket" style="margin-top:16px"><div class="lbl">Link do nagrania</div><div class="mono" style="margin-top:6px;overflow-wrap:anywhere">' + AV.esc(url) + '</div></div>' +
        '<a class="btn p" style="margin-top:14px;display:inline-flex" href="' + AV.attr(url) + '" target="_blank" rel="noopener">Odtwórz nagranie ↗</a>';
    } else {
      content = '<div class="ticket" style="margin-top:16px"><div class="lbl">Link do nagrania</div><p class="mut" style="font-size:13.5px;margin-top:6px;line-height:1.55">Wklej link do nagrania (YouTube, Discord albo inny host). Zapisze się w Twoim panelu i będzie gotowy do odtworzenia.</p>' +
        '<input class="input" id="recUrlInput" placeholder="https://…" style="margin-top:10px">' +
        '<button class="btn p sm" id="recUrlSave" style="margin-top:10px">Zapisz link</button></div>';
    }
    const body = AV.openModal('<div class="eyebrow">Nagranie</div><h1 style="font-size:26px;margin-top:6px">' + AV.esc(title) + '</h1>' +
      (meta ? '<div class="mut" style="font-size:13px;margin-top:4px">' + AV.esc(meta) + '</div>' : '') + content +
      '<div style="display:flex;gap:10px;margin-top:18px"><button class="btn g" data-gclose>Zamknij</button></div>', { maxWidth: '720px' });
    const save = body && body.querySelector('#recUrlSave');
    if (save) save.onclick = () => {
      const input = body.querySelector('#recUrlInput');
      const value = (input ? input.value : '').trim();
      if (!/^https?:\/\//i.test(value)) { AV.toast('Podaj pełny link zaczynający się od http(s)://'); return; }
      if (!Array.isArray(AV.state.recordings) || !AV.state.recordings.length) {
        AV.state.recordings = AV.recordingList().map((r) => Object.assign({}, r));
      }
      let target = AV.state.recordings.find((r) => r && r.id === (rec && rec.id));
      if (!target) {
        target = { id: 'rec' + Date.now().toString(36), title, category: '', meta: '', url: '' };
        AV.state.recordings.push(target);
      }
      target.url = value;
      AV.saveNow();
      AV.closeModal();
      AV.renderAcademy();
      AV.openRecording(target.id);
      AV.toast('Link do nagrania zapisany');
    };
  };
  AV.openRecordings = () => {
    const list = AV.recordingList();
    AV.openModal('<div class="eyebrow">Biblioteka</div><h1 style="font-size:26px;margin-top:6px">Wszystkie nagrania</h1><div class="grid" style="margin-top:16px;gap:8px">' +
      (list.length ? list.map((r) => '<div class="card flat" style="padding:14px;cursor:pointer" data-recording="' + AV.attr(r.id || r.title) + '"><b>' + AV.esc(r.title) + '</b><div class="hint">' +
        AV.esc([r.category, r.meta].filter(Boolean).join(' · ')) + '</div></div>').join('') : '<div class="dim">Nagrania pojawią się tutaj po pierwszym live.</div>') +
      '</div><div style="display:flex;gap:10px;margin-top:18px"><button class="btn g" data-gclose>Zamknij</button></div>', { maxWidth: '620px' });
  };

  AV.openCourse = () => {
    const modules = AV.COURSE_MODULES || [];
    const done = new Set(AV.state?.meta?.courseDone || []);
    const body = AV.openModal('<div class="eyebrow">Materiały</div><h1 style="font-size:26px;margin-top:6px">Kurs krypto od A do Z</h1>' +
      '<p class="mut" style="font-size:13.5px;margin-top:6px">Oznacz ukończone lekcje. Postęp zapisuje się w Twoim profilu. <b id="courseModalPct">' + AV.coursePct() + '%</b></p>' +
      '<div style="max-height:56vh;overflow-y:auto;margin-top:12px">' + modules.map((m, i) =>
        '<label class="check" style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)">' +
        '<input type="checkbox" data-course-mod="' + i + '" style="accent-color:var(--alpha);margin-top:3px"' + (done.has(i) ? ' checked' : '') + '>' +
        '<span style="display:grid;gap:2px"><b>' + AV.esc(m.title) + '</b><span class="dim" style="font-size:12px">' + AV.esc(m.chapter) + '</span></span></label>').join('') +
      '</div><div style="display:flex;gap:10px;margin-top:16px"><button class="btn g" data-gclose>Zamknij</button></div>', { maxWidth: '620px' });
  };

  AV.openBootcamp = () => {
    const modules = AV.BOOTCAMP_MODULES || [];
    const done = new Set(AV.state?.meta?.bootcampDone || []);
    const body = AV.openModal('<div class="eyebrow">Materiały</div><h1 style="font-size:26px;margin-top:6px">Bootcamp</h1>' +
      '<p class="mut" style="font-size:13.5px;margin-top:6px">Zaznacz ukończone moduły. Licznik pokaże realny postęp. <b id="bootcampModalDone">' + AV.bootcampDoneCount() + ' z ' + modules.length + '</b></p>' +
      '<div style="margin-top:12px">' + modules.map((m, i) =>
        '<label class="check" style="display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)">' +
        '<input type="checkbox" data-bootcamp-mod="' + i + '" style="accent-color:var(--alpha)"' + (done.has(i) ? ' checked' : '') + '>' +
        '<b>' + AV.esc(m.title) + '</b></label>').join('') +
      '</div><div style="display:flex;gap:10px;margin-top:16px"><button class="btn g" data-gclose>Zamknij</button></div>', { maxWidth: '560px' });
  };

  AV.openGlossary = () => {
    const total = AV.GLOSSARY.length;
    const body = AV.openModal('<div class="eyebrow">Materiały</div><h1 style="font-size:26px;margin-top:6px">Słownik krypto</h1>' +
      '<p class="mut" style="font-size:13.5px;margin-top:6px">' + total + ' ' + AV.plural(total, 'pojęcie', 'pojęcia', 'pojęć') + ' z krótkim wyjaśnieniem.</p>' +
      '<input class="input" id="glossSearch" placeholder="Szukaj pojęcia" style="margin-top:12px" aria-label="Szukaj pojęcia">' +
      '<div id="glossList" style="max-height:52vh;overflow-y:auto;margin-top:10px"></div>' +
      '<div style="display:flex;gap:10px;margin-top:16px"><button class="btn g" data-gclose>Zamknij</button></div>', { maxWidth: '720px' });
    const render = (q) => {
      const needle = String(q || '').trim().toLowerCase();
      const items = AV.GLOSSARY.filter((x) => !needle || x[0].toLowerCase().includes(needle) || x[1].toLowerCase().includes(needle));
      AV.setHTML('#glossList', items.map((x) => '<div class="kv" style="align-items:flex-start"><b style="min-width:130px">' + AV.esc(x[0]) + '</b><span style="text-align:left;line-height:1.5">' + AV.esc(x[1]) + '</span></div>').join(''));
    };
    render('');
    const input = body && body.querySelector('#glossSearch');
    if (input) input.addEventListener('input', () => render(input.value));
  };

  AV.openAlphaTest = () => {
    const qs = AV.ALPHA_TEST;
    const body = AV.openModal('<div class="eyebrow">Alpha Testy</div><h1 style="font-size:26px;margin-top:6px">Test Tradera · Mini Gry</h1>' +
      '<p class="mut" style="font-size:13.5px;margin-top:6px">' + qs.length + ' pytań z zarządzania kapitałem i bezpieczeństwa.</p>' +
      '<div id="alphaTestForm" style="margin-top:12px">' + qs.map((x, i) =>
        '<div class="card flat" style="padding:14px;margin-top:10px"><b>' + (i + 1) + '. ' + AV.esc(x.q) + '</b>' +
        '<div style="display:grid;gap:6px;margin-top:10px">' + x.a.map((a, j) =>
          '<label class="check" style="margin:0"><input type="radio" name="at' + i + '" value="' + j + '" style="accent-color:var(--alpha)"> ' + AV.esc(a) + '</label>').join('') +
        '</div></div>').join('') + '</div>' +
      '<button class="btn p" id="alphaTestSubmit" style="margin-top:14px">Sprawdź wynik</button>' +
      '<div id="alphaTestResult" style="margin-top:12px"></div>' +
      '<div style="display:flex;gap:10px;margin-top:16px"><button class="btn g" data-gclose>Zamknij</button></div>', { maxWidth: '720px' });
    const submit = body && body.querySelector('#alphaTestSubmit');
    if (!submit) return;
    submit.onclick = () => {
      let score = 0;
      qs.forEach((x, i) => {
        const picked = body.querySelector('input[name="at' + i + '"]:checked');
        if (picked && Number(picked.value) === x.ok) score++;
      });
      AV.state.meta.alphaTestScore = score;
      AV.state.meta.alphaTestAt = Date.now();
      AV.saveNow();
      AV.setHTML('#alphaTestResult', '<div class="ticket"><div class="lbl">Wynik</div><div class="val ' + (score === qs.length ? 'win' : 'warnc') + '">' + score + ' / ' + qs.length + '</div><p class="mut" style="font-size:13px;margin-top:6px">' +
        (score === qs.length ? 'Komplet. Zasady masz opanowane.' : 'Wróć do słownika i złotych zasad, potem powtórz test.') + '</p></div>');
      AV.renderAcademy();
    };
  };

  AV.openGoldenRules = () => {
    AV.openModal('<div class="eyebrow">Materiały</div><h1 style="font-size:26px;margin-top:6px">Złote zasady bezpieczeństwa</h1>' +
      '<div style="margin-top:14px">' + AV.GOLDEN_RULES.map((r, i) =>
        '<div class="row" style="align-items:flex-start"><div><b>' + (i + 1) + '. ' + AV.esc(r[0]) + '</b><span style="line-height:1.5">' + AV.esc(r[1]) + '</span></div></div>').join('') + '</div>' +
      '<div style="display:flex;gap:10px;margin-top:16px"><button class="btn g" data-gclose>Zamknij</button></div>', { maxWidth: '720px' });
  };

  /* ---------- podatki ---------- */
  AV.bindTax = () => {
    $('#taxCsv')?.addEventListener('click', () => AV.exportTaxCsv());
    $('#taxPdf')?.addEventListener('click', () => AV.exportTaxPdf());
    $('#taxLossEdit')?.addEventListener('click', () => AV.editTaxCarry());
  };

  AV.editTaxCarry = () => {
    const t = AV.taxData(AV.state);
    AV.promptText('Nadwyżka kosztów z ' + (t.year - 1), 'Kwota w zł (wpisz 0, jeśli brak)', (value) => {
      const carryLoss = Math.max(0, AV.pnum(value));
      AV.state.tax = AV.normalizeTax({ ...AV.state.tax, carryLoss }, { migrateDemoSeed: false });
      AV.saveNow();
      AV.renderTax();
      AV.toast('Nadwyżka kosztów z ' + (t.year - 1) + ': ' + AV.fmt(carryLoss, 2) + ' zł');
    });
    const input = document.querySelector('#pmInput');
    if (input) input.value = t.carry ? String(t.carry).replace('.', ',') : '';
  };

  /* ---------- kalendarz ---------- */
  AV.bindCalendar = () => {
    $('#calSeg')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      AV.ui.weekOffset = Math.max(-1, Math.min(3, Number(b.dataset.w) || 0));
      AV.renderCalendar();
    });
    $('#mwPill')?.addEventListener('click', (e) => { e.stopPropagation(); $('#mw').classList.toggle('open'); });
    document.addEventListener('click', (e) => { if (!e.target.closest('#mw')) $('#mw').classList.remove('open'); });
  };

  /* ---------- ciało ---------- */
  AV.connectWearable = async (provider) => {
    if (!AV.api.online || !AV.api.authenticated) {
      AV.toast('Połącz urządzenie po zalogowaniu do Alpha Vault.');
      return;
    }
    if (provider === 'apple') {
      await AV.openApplePair();
      return;
    }
    location.href = '/auth/wearables/' + encodeURIComponent(provider) + '/start';
  };

  AV.disconnectWearable = (provider) => {
    const label = AV.api.wearables?.devices?.[provider]?.label || provider;
    AV.confirm({
      eyebrow: 'Zgoda na dane zdrowotne',
      title: 'Odłączyć ' + label + '?',
      text: 'Odłączenie usuwa z Alpha Vault dane o zdrowiu z tego urządzenia i wycofuje zgodę na ich przetwarzanie. Możesz połączyć je ponownie w każdej chwili.',
      confirmLabel: 'Odłącz i usuń dane',
      danger: true,
      onConfirm: async () => {
        try {
          const res = await AV.apiFetch('/api/wearables/' + encodeURIComponent(provider), { method: 'DELETE' });
          if (!res.ok) throw new Error('HTTP ' + res.status);
          await AV.loadWearables();
          AV.renderBody();
          AV.toast('Urządzenie odłączone. Dane zostały usunięte.');
        } catch (err) {
          AV.toast('Nie udało się odłączyć urządzenia.');
        }
      }
    });
  };

  AV.syncWearable = async (provider) => {
    if (!AV.api.authenticated) return;
    try {
      const res = await AV.apiFetch('/api/wearables/' + encodeURIComponent(provider) + '/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
      await AV.loadWearables();
      AV.renderBody();
      AV.toast('Zsynchronizowano ' + (AV.api.wearables?.devices?.[provider]?.label || provider) + '.');
    } catch (err) {
      AV.toast('Synchronizacja nie powiodła się: ' + err.message);
    }
  };

  AV.openApplePair = async () => {
    try {
      const res = await AV.apiFetch('/api/wearables/apple/pair', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
      const expires = new Date(data.expiresAt).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Warsaw' });
      const body = AV.openModal(
        '<div class="eyebrow">Apple Watch · Zdrowie</div><h1 style="font-size:26px;margin-top:6px">Kod parowania</h1>' +
        '<p class="mut" style="margin-top:10px;line-height:1.6">Otwórz aplikację Alpha Vault na iPhone, wejdź w Ustawienia → Urządzenia i wpisz kod. Kod jest jednorazowy i wygasa o ' + AV.esc(expires) + '.</p>' +
        '<div class="mono" style="font-size:30px;font-weight:700;letter-spacing:.12em;text-align:center;margin:22px 0;padding:18px;border:1px dashed rgba(var(--a),.45);border-radius:16px;background:rgba(var(--a),.06)">' + AV.esc(data.code) + '</div>' +
        '<p class="dim" style="font-size:12.5px;line-height:1.5">Dane trafiają do Alpha Vault tylko z Twojego iPhone’a i tylko do liczenia gotowości. Po sparowaniu telefon zapisze token urządzenia; nie pokazujemy go w panelu.</p>' +
        '<div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end"><button class="btn g" data-gclose>Zamknij</button><button class="btn p" id="applePairCheck">Sprawdź połączenie</button></div>',
        { maxWidth: '520px' }
      );
      const check = async () => {
        await AV.loadWearables();
        AV.renderBody();
        if (AV.api.wearables?.devices?.apple?.connected) {
          AV.closeModal();
          AV.toast('Apple Watch połączony.');
          return true;
        }
        return false;
      };
      AV.$('#applePairCheck', body)?.addEventListener('click', check);
      let tries = 0;
      const timer = setInterval(async () => {
        tries++;
        if (tries > 60 || AV.api.wearables?.devices?.apple?.connected) { clearInterval(timer); return; }
        await check();
      }, 5000);
    } catch (err) {
      AV.toast('Nie udało się utworzyć kodu parowania: ' + err.message);
    }
  };

  AV.bindBody = () => {
    $('#devList')?.addEventListener('click', (e) => {
      const b = e.target.closest('[data-dev]'); if (!b) return;
      const row = b.closest('[data-provider]'), key = row?.dataset.provider;
      if (!key) return;
      if (b.dataset.mode === 'disconnect') AV.disconnectWearable(key);
      else AV.connectWearable(key);
    });
    $('#devList')?.addEventListener('click', (e) => {
      const b = e.target.closest('[data-sync]'); if (!b) return;
      const key = b.closest('[data-provider]')?.dataset.provider;
      if (key) AV.syncWearable(key);
    });
    $('#checkMood')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#checkMood .chip').forEach((x) => x.classList.toggle('on', x === c));
    });
    $('#checkEnergy')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#checkEnergy .chip').forEach((x) => x.classList.toggle('on', x === c));
    });
    $('#saveCheck')?.addEventListener('click', () => {
      const moodEl = $('#checkMood .chip.on');
      const energyEl = $('#checkEnergy .chip.on');
      if (!moodEl || !energyEl) {
        AV.toast('Wybierz nastrój i poziom energii.');
        return;
      }
      const mood = moodEl.textContent;
      const energy = Number(energyEl.textContent);
      const note = $('#checkNote').value.trim();
      const moodMod = { 'Spokój': 10, 'Skupienie': 7, 'Pewność': 5, 'Nuda': -3, 'Zmęczenie': -8, 'Strach': -10, 'FOMO': -12, 'Złość': -14, 'Chciwość': -6 }[mood] || 0;
      const score = Math.round(AV.clamp(50 + (energy - 3) * 10 + moodMod, 0, 100));
      const key = AV.dateKey(new Date());
      AV.state.checkins = AV.state.checkins.filter((c) => c.date !== key);
      AV.state.checkins.push({ date: key, mood, energy, note, score });
      AV.saveNow(); AV.renderBody(); AV.renderDashboard(); AV.renderMentor();
      AV.toast('Check-in zapisany. Mentor go uwzględni.');
    });
  };

  /* ---------- giełdy ---------- */
  AV.bindExchanges = () => {
    $$('.exl button').forEach((b) => b.addEventListener('click', () => AV.renderEx(b.dataset.ex)));
    $('#syncNow')?.addEventListener('click', async () => {
      const connected = !!AV.connections().bingx.connected;
      if (!connected) { AV.toast('Połącz najpierw BingX'); return; }
      const btn = $('#syncNow');
      const old = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.textContent = 'Synchronizuję…'; }
      try {
        const data = await AV.syncConnections();
        AV.renderExchangeList();
        if (AV.renderEx) AV.renderEx(AV.exKey);
        AV.toast(data.imported > 0
          ? 'Pobrano ' + data.imported + ' ' + AV.plural(data.imported, 'transakcję', 'transakcje', 'transakcji') + ' z BingX'
          : 'Synchronizacja gotowa · brak nowych transakcji', 5000);
      } catch (err) {
        AV.toast(err.message || 'Synchronizacja nie powiodła się.', 7000);
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = old; }
      }
    });
  };


  /* ---------- ustawienia ---------- */
  AV.bindSettings = () => {
    $('#nameIn')?.addEventListener('input', AV.debounce((e) => {
      AV.state.user.name = e.target.value.trim() || 'Trader';
      AV.save(); AV.renderUser(); AV.renderDashboard(); AV.renderMentor();
    }, 250));
    $('#goalIn')?.addEventListener('input', AV.debounce((e) => {
      AV.state.user.goal = e.target.value; AV.save(); AV.setText('#goalTxt', e.target.value || '—');
    }, 250));
    $('#currencyIn')?.addEventListener('change', (e) => {
      AV.state.user.currency = e.target.value; AV.saveNow(); AV.renderDashboard(); AV.renderStats(); AV.renderJournal(); AV.renderNewTradeForm();
      AV.toast('Waluta wyników: ' + e.target.value);
    });
    $('#tzIn')?.addEventListener('change', (e) => { AV.state.user.timezone = e.target.value; AV.saveNow(); AV.renderCalendar(); AV.renderAcademy(); AV.renderMentor(); AV.toast('Strefa czasowa: ' + e.target.value); });
    $('#hoursIn')?.addEventListener('change', (e) => { AV.state.user.hour12 = e.target.value.startsWith('12'); AV.saveNow(); AV.renderCalendar(); AV.renderAcademy(); AV.toast('Format godzin zapisany'); });
    $('#marketChips')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      c.classList.toggle('on');
      AV.state.user.markets = $$('#marketChips .chip.on').map((x) => x.textContent);
      AV.saveNow(); AV.renderUser();
    });
    $('#accents')?.addEventListener('click', (e) => {
      const w = e.target.closest('.sw'); if (!w) return;
      $$('#accents .sw').forEach((x) => x.classList.toggle('on', x === w));
      AV.state.user.accent = { a: w.dataset.a, b: w.dataset.b };
      AV.saveNow(); AV.applyAccent(); AV.renderDashboard(); AV.renderStats(); AV.renderMentor();
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', '#080707');
      AV.toast('Kolor akcentu zmieniony');
    });
    $$('[data-setting]').forEach((el) => el.onclick = () => AV.toggleSetting(el));
    document.addEventListener('click', (e) => {
      const t = e.target.closest('[data-setting]');
      if (t && !t._h) { /* binding przez delegation dla dynamicznych */ }
    });
    $('#maxLosses')?.addEventListener('change', (e) => { AV.state.settings.maxLosses = Math.max(1, AV.pnum(e.target.value) || 2); AV.saveNow(); AV.renderLock(); AV.renderDashboard(); AV.toast('Limit strat: ' + AV.state.settings.maxLosses); });
    $('#maxDailyLoss')?.addEventListener('change', (e) => { AV.state.settings.maxDailyLoss = Math.max(0.1, AV.pnum(e.target.value) || 3); AV.saveNow(); AV.renderLock(); AV.renderDashboard(); AV.toast('Limit straty dziennej zapisany'); });
    $('#blockChips')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#blockChips .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.state.settings.block = c.dataset.block; AV.saveNow(); AV.renderLock();
      AV.toast('Blokada: ' + c.textContent);
    });
    $('#maxRisk')?.addEventListener('change', (e) => { AV.state.settings.maxRisk = Math.max(0.1, AV.pnum(e.target.value) || 1); AV.saveNow(); AV.renderNewTradeForm(); AV.renderNewTradeForm(); AV.toast('Max ryzyko: ' + AV.state.settings.maxRisk + '%'); });
    $('#saveRules')?.addEventListener('click', () => {
      const rules = ['#rule1', '#rule2', '#rule3'].map((x) => $(x).value.trim());
      if (rules.some((r) => !r)) { AV.toast('Uzupełnij wszystkie 3 zasady'); return; }
      AV.state.settings.rules = rules;
      AV.state.settings.checklist = $$('#rulesChecklist .chip.on').map((c) => c.dataset.c).filter(Boolean);
      AV.state.settings.maxRisk = Math.max(0.1, AV.pnum($('#maxRisk').value) || 1);
      AV.saveNow(); AV.renderChecklist(); AV.toast('Zasady zapisane');
    });
    $('#rulesChecklist')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      c.classList.toggle('on');
      AV.state.settings.checklist = $$('#rulesChecklist .chip.on').map((x) => x.dataset.c).filter(Boolean);
      AV.saveNow(); AV.renderChecklist();
    });
    // własny punkt checklisty
    const add = document.createElement('span');
    add.className = 'chip'; add.textContent = '+ własny'; add.dataset.addRule = '1';
    $('#rulesChecklist')?.appendChild(add);
    add.onclick = () => AV.promptText('Nowy punkt checklisty', 'Nazwa punktu', (name) => {
      if (!name) return;
      const s = AV.state;
      if (!s.settings.checklist.includes(name)) s.settings.checklist.push(name);
      const chip = document.createElement('span');
      chip.className = 'chip on'; chip.dataset.c = name; chip.textContent = name;
      $('#rulesChecklist').insertBefore(chip, add);
      AV.saveNow(); AV.renderChecklist(); AV.toast('Punkt dodany do checklisty');
    });
    $('#addAcc')?.addEventListener('click', () => { const f = $('#accForm'); f.style.display = f.style.display === 'none' ? 'block' : 'none'; });
    $('#saveAcc')?.addEventListener('click', () => {
      const name = $('#accName').value.trim();
      const start = AV.pnum($('#accStart').value);
      if (!name) { AV.toast('Podaj nazwę konta'); return; }
      const sizeUnit = $('#accSizeUnit')?.value || 'units';
      const contractSize = AV.pnum($('#accContractSize')?.value);
      if (sizeUnit === 'lots' && !(contractSize > 0)) {
        AV.toast('Dla lotów/kontraktów podaj dodatnią liczbę jednostek bazowych na lot');
        $('#accContractSize')?.focus();
        return;
      }
      AV.state.accounts.push({
        id: AV.uid('acc'), name, exchange: $('#accExchange').value, type: $('#accType').value,
        currency: $('#accCurrency').value, start, sizeUnit, contractSize: sizeUnit === 'lots' ? contractSize : null,
        date: $('#accDate').value.trim() || AV.dateLabel(new Date()),
        lev: $('#accLev').value.trim() || '1×', goal: $('#accGoal').value.trim(), balance: start, include: true, primary: false
      });
      AV.saveNow(); $('#accForm').style.display = 'none';
      ['#accName', '#accStart', '#accDate', '#accLev', '#accGoal', '#accContractSize'].forEach((x) => $(x).value = '');
      AV.setVal('#accSizeUnit', 'units'); AV.show('#accContractWrap', false);
      AV.renderAccounts(); AV.renderSettings(); AV.renderDashboard(); AV.renderCalcPortfolios(); AV.toast('Konto dodane');
    });
    $('#accBody')?.addEventListener('change', (e) => {
      const startEl = e.target.closest('[data-acc-start]');
      const balEl = e.target.closest('[data-acc-balance]');
      const unitEl = e.target.closest('[data-acc-unit]');
      const contractEl = e.target.closest('[data-acc-contract]');
      const el = startEl || balEl || unitEl || contractEl;
      if (!el) return;
      const id = startEl ? startEl.dataset.accStart
        : balEl ? balEl.dataset.accBalance
          : unitEl ? unitEl.dataset.accUnit : contractEl.dataset.accContract;
      const acc = AV.state.accounts.find((x) => x.id === id);
      if (!acc) return;
      const val = AV.pnum(el.value);
      if (startEl) acc.start = Math.max(0, val);
      else if (balEl) acc.balance = Math.max(0, val);
      else if (unitEl) {
        if (el.value === 'lots' && !(AV.pnum(acc.contractSize) > 0)) {
          acc.sizeUnit = 'lots';
          AV.renderAccounts();
          AV.toast('Wpisz jednostki bazowe na lot/kontrakt, aby używać tej jednostki');
          Array.from(AV.$$('#accBody [data-acc-contract]')).find((input) => input.dataset.accContract === id)?.focus();
        } else {
          acc.sizeUnit = el.value;
        }
      } else {
        if (!(val > 0)) { AV.toast('Mnożnik kontraktu musi być dodatni'); AV.renderAccounts(); return; }
        acc.contractSize = val;
      }
      AV.saveNow();
      AV.renderAccounts();
      AV.renderNewTradeForm();
      AV.renderSettings();
      AV.renderDashboard();
      AV.renderTax();
      AV.renderCalcPortfolios();
      AV.toast(startEl || balEl ? 'Saldo konta zapisane' : 'Jednostka pozycji zapisana');
    });
    $('#accBody')?.addEventListener('click', (e) => {
      const del = e.target.closest('[data-acc-del]');
      if (del) {
        AV.confirm({ title: 'Usunąć konto?', text: 'Transakcje przypisane do tego konta zostaną w dzienniku, ale przestaną wchodzić do statystyk.', confirmLabel: 'Usuń', danger: true, onConfirm: () => {
          AV.state.accounts = AV.state.accounts.filter((a) => a.id !== del.dataset.accDel);
          AV.saveNow(); AV.renderAll(); AV.toast('Konto usunięte');
        } });
        return;
      }
      const tog = e.target.closest('[data-acc-inc]');
      if (tog) {
        const a = AV.state.accounts.find((x) => x.id === tog.dataset.accInc);
        if (!a) return;
        a.include = !a.include; AV.saveNow(); AV.renderAccounts(); AV.renderDashboard(); AV.renderStats(); AV.renderTax(); AV.toast(a.include ? 'Konto w statystykach' : 'Konto poza statystykami');
      }
    });
    $('#addFlow')?.addEventListener('click', () => AV.flowModal());
    $('#flowBody')?.addEventListener('click', (e) => {
      const del = e.target.closest('[data-flow-del]');
      if (!del) return;
      const f = AV.state.flows.find((x) => x.id === del.dataset.flowDel);
      if (!f) return;
      AV.confirm({ title: 'Usunąć wpłatę/wypłatę?', text: 'Usunięcie zmieni saldo konta i zestawienie PIT-38.', confirmLabel: 'Usuń', danger: true, onConfirm: () => {
        AV.state.flows = AV.state.flows.filter((x) => x.id !== f.id);
        const acc = AV.state.accounts.find((a) => a.name === f.account);
        if (acc) acc.balance = AV.num(acc.balance) + (f.type === 'Wpłata' ? -AV.num(f.amount) : AV.num(f.amount));
        AV.saveNow(); AV.renderFlows(); AV.renderAccounts(); AV.renderTax(); AV.renderSettings(); AV.toast('Usunięto');
      } });
    });
    $('#dlAll')?.addEventListener('click', () => AV.downloadAll());
    $('#downloadDataExpired')?.addEventListener('click', () => AV.downloadAll());
    $('#deleteAcc')?.addEventListener('click', () => {
      const demo = !!AV.api.me?.isDemo;
      AV.confirm({
        title: 'Usunąć konto i wszystkie dane?',
        html: 'To trwale usunie dziennik, screeny, plany, klucze API i dane z zegarka. Tej operacji nie da się cofnąć.' + (demo ? ' <b>Konto demo po ponownym logowaniu zostanie odtworzone z danymi przykładowymi.</b>' : ''),
        confirmLabel: 'Usuń wszystko', danger: true, input: 'USUŃ', inputLabel: 'Wpisz USUŃ, aby potwierdzić',
        onConfirm: async () => {
          if (AV.api.online) {
            try {
              await AV.serverDeleteAccount();
              location.href = '/';
            } catch (err) { AV.toast(err.message || 'Nie udało się usunąć konta'); }
            return;
          }
          AV.resetAll(); AV.logout(); AV.toast('Konto usunięte'); setTimeout(() => location.reload(), 600);
        }
      });
    });
    $('#expBack')?.addEventListener('click', () => { AV.state.subscription.expired = false; AV.saveNow(); AV.renderExpired(); AV.toast('Wrócono do panelu'); });
  };

  AV.toggleSetting = (el) => {
    const k = el.dataset.setting;
    el.classList.toggle('on');
    const on = el.classList.contains('on');
    AV.state.settings[k] = on;
    if (k === 'macroBefore' || k === 'pushLimit') { if (on) AV.ensurePermission(); }
    AV.saveNow();
    if (k === 'remember') AV.toast(on ? 'Zostaniesz zalogowana na tym urządzeniu' : 'Sesja wygaśnie po zamknięciu karty');
    if (k === 'macroTwo') AV.renderCalendar();
    if (k === 'safetyEnabled') AV.renderLock();
    if (k === 'requireReason') AV.toast('Ustawienie zapisane');
  };

  /* ---------- wpłaty ---------- */
  AV.flowModal = () => {
    const accounts = AV.state.accounts;
    const body = AV.openModal('<div class="eyebrow">Konta i kapitał</div><h1 style="font-size:26px;margin-top:6px">Dodaj wpłatę lub wypłatę</h1>' +
      '<div class="grid g2" style="margin-top:14px"><div><span class="label">Typ</span><select class="input" id="flType"><option>Wpłata</option><option>Wypłata</option></select></div><div><span class="label">Konto</span><select class="input" id="flAcc">' + accounts.map((a) => '<option value="' + AV.attr(a.name) + '">' + AV.esc(a.name) + '</option>').join('') + '</select></div>' +
      '<div><span class="label">Data</span><input class="input mono" id="flDate" value="' + AV.dateKey(new Date()) + '"></div><div><span class="label">Kwota USDT</span><input class="input mono" id="flAmount" placeholder="0,00"></div>' +
      '<div><span class="label">Waluta fiat</span><select class="input" id="flCur"><option>PLN</option><option>EUR</option><option>USD</option></select></div><div><span class="label">Kwota fiat</span><input class="input mono" id="flFiat" placeholder="0,00"></div>' +
      '<div><span class="label">Kurs NBP</span><input class="input mono" id="flFx" value="1"></div><div><span class="label">Opis</span><input class="input" id="flOp" value="P2P"></div></div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="flSave">Zapisz</button></div>', { maxWidth: '620px' });
    $('#flSave', body).onclick = () => {
      const type = $('#flType', body).value, acc = $('#flAcc', body).value;
      const amount = AV.pnum($('#flAmount', body).value);
      const fiat = AV.pnum($('#flFiat', body).value);
      const currency = $('#flCur', body).value;
      const fx = currency === 'PLN' ? 1 : AV.pnum($('#flFx', body).value);
      if (!(amount > 0) || !(fiat > 0)) { AV.toast('Podaj dodatnią kwotę USDT i kwotę fiat'); return; }
      if (currency !== 'PLN' && !(fx > 1)) { AV.toast('Podaj kurs NBP z dnia roboczego przed transakcją'); return; }
      const date = $('#flDate', body).value || AV.dateKey(new Date());
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { AV.toast('Podaj datę w formacie RRRR-MM-DD'); return; }
      const flow = {
        id: AV.uid('flow'), date, account: acc,
        type, op: $('#flOp', body).value + (type === 'Wpłata' ? ' · zakup USDT' : ' · sprzedaż USDT'),
        amount, currency, fiat, fx, tax: type === 'Wpłata' ? 'cost' : 'income'
      };
      AV.state.flows.push(flow);
      const a = AV.state.accounts.find((x) => x.name === acc);
      if (a) a.balance = AV.num(a.balance) + (type === 'Wpłata' ? amount : -amount);
      AV.saveNow(); AV.closeModal(); AV.renderFlows(); AV.renderAccounts(); AV.renderTax(); AV.renderSettings(); AV.renderDashboard();
      if (Number(String(date).slice(0, 4)) !== AV.taxYear(AV.state)) {
        AV.toast(type + ' zapisana poza rokiem ' + AV.taxYear(AV.state) + ' — nie wejdzie do zestawienia PIT-38', 5000);
      } else {
        AV.toast(type + ' zapisana');
      }
    };
  };

  /* ---------- eksport ---------- */
  AV.exportTradesCsv = () => {
    const rows = [['Data', 'Para', 'Kierunek', 'Ocena', 'Checklista', 'Błędy', 'Emocja', 'Setup', 'Wejście', 'Wyjście', 'SL', 'TP', 'Wielkość', 'R', 'MAE', 'MFE', 'Wynik USDT', 'Opłata', 'Plan', 'Notatka']];
    AV.state.trades.slice().sort((a, b) => new Date(a.date) - new Date(b.date)).forEach((t) => rows.push([
      new Date(t.date).toLocaleString('pl-PL'), t.pair, AV.tradeDirection(t.dir) === 's' ? 'SHORT' : 'LONG', t.grade,
      t.checklist == null ? '' : t.checklist + '/' + (Number(t.checkTotal) || 7), (t.errors || []).join(' | '), t.emotion, t.setup, t.entry, t.exit, t.sl, t.tp, t.size,
      t.r == null ? '' : t.r, t.mae == null ? '' : t.mae, t.mfe == null ? '' : t.mfe,
      t.result == null ? '' : t.result, t.fee == null ? '' : t.fee, t.plan ? 'tak' : 'nie', t.note
    ]));
    AV.download('alpha-vault-transakcje.csv', AV.toCsv(rows));
    AV.toast('Eksport CSV gotowy');
  };

  // Separatorem w CSV jest „;” — kwoty z kropką dziesiętną i spacjami
  // tysięcy Excel interpretował jako tekst. Ujednolicam format obu eksportów.
  AV.taxCsvMoney = (v) => AV.fmt(v, 2).replace(/[\s\u00a0]/g, '');
  AV.taxCsvRows = (t) => {
    const money = AV.taxCsvMoney;
    const rows = [['Data', 'Operacja', 'Rodzaj', 'Waluta', 'Kwota', 'Kurs NBP', 'W PLN']];
    t.ev.forEach((e) => rows.push([new Date(e.date).toLocaleDateString('pl-PL'), e.op,
      e.kind === 'cost' ? 'koszt' : 'przychód', e.currency, money(e.amount),
      e.fx === 1 ? '1' : AV.fmt(e.fx, 4).replace(/[\s\u00a0]/g, ''), money(e.pln)]));
    rows.push([]);
    rows.push(['Przychód', '', '', '', '', '', money(t.revenue)]);
    rows.push(['Koszty', '', '', '', '', '', money(t.costs)]);
    rows.push(['Nadwyżka kosztów z ' + (t.year - 1), '', '', '', '', '', money(t.carry)]);
    rows.push(['Dochód', '', '', '', '', '', money(t.income)]);
    rows.push(['Podatek 19%', '', '', '', '', '', money(t.due)]);
    rows.push(['Do przeniesienia na ' + (t.year + 1), '', '', '', '', '', money(t.carryForward)]);
    rows.push(['Neutralne podatkowo (transakcje)', '', '', '', '', '', t.neutral]);
    rows.push(['Forex i CFD MT4/MT5 (transakcje)', '', '', '', '', '', t.forex]);
    return rows;
  };
  AV.exportTaxCsv = () => {
    const t = AV.taxData(AV.state);
    AV.download('alpha-vault-PIT-38-' + t.year + '.csv', AV.toCsv(AV.taxCsvRows(t)));
    AV.toast('CSV podatkowy gotowy');
  };

  AV.taxPrintHtml = () => {
    const t = AV.taxData(AV.state), s = AV.state;
    const rows = t.ev.map((e) => '<tr><td>' + new Date(e.date).toLocaleDateString('pl-PL') + '</td><td>' + AV.esc(e.op) + '</td><td>' + (e.kind === 'cost' ? 'koszt' : 'przychód') + '</td><td>' + AV.fmt(e.amount, 2) + ' ' + e.currency + '</td><td>' + (e.fx === 1 ? '–' : AV.fmt(e.fx, 4)) + '</td><td>' + AV.fmt(e.pln, 2) + ' zł</td></tr>').join('');
    const summary = [
      ['Przychód', t.revenue],
      ['Koszty', t.costs],
      ['Nadwyżka kosztów z ' + (t.year - 1), t.carry],
      ['Dochód', t.income],
      ['Podatek 19%', t.due],
      ['Do przeniesienia na ' + (t.year + 1), t.carryForward]
    ].map(([label, value]) => '<div><span>' + label + '</span><b>' + AV.fmt(value, 2) + ' zł</b></div>').join('');
    const neutral = t.neutral + ' ' + AV.plural(t.neutral, 'transakcja', 'transakcje', 'transakcji');
    const forex = t.forex + ' ' + AV.plural(t.forex, 'transakcja', 'transakcje', 'transakcji');
    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>PIT-38 · Alpha Vault</title><style>body{font-family:Arial,sans-serif;color:#111;margin:32px;font-size:13px}h1{font-size:22px}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left}th{background:#f4f1ec}.sum{margin-top:20px;width:320px}.sum div{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee}.sum b{font-size:16px}.note{color:#666;font-size:11px;margin-top:24px;line-height:1.5}</style></head><body><h1>Zestawienie do PIT-38 · ' + t.year + '</h1><p>' + AV.esc(s.user.name) + ' · wygenerowano ' + AV.nowStamp() + '</p><table><thead><tr><th>Data</th><th>Operacja</th><th>Rodzaj</th><th>Kwota</th><th>Kurs</th><th>W PLN</th></tr></thead><tbody>' + rows + '</tbody></table><div class="sum">' + summary + '</div><p class="note">Neutralne podatkowo: ' + neutral + '. Forex i CFD (MT4/MT5): ' + forex + '.</p><p class="note">Zestawienie pomocnicze, nie porada podatkowa. Przed złożeniem PIT-38 zweryfikuj dane z księgową lub doradcą podatkowym. Alpha Vault · dane wyłącznie użytkownika.</p><script>window.print()<\/script></body></html>';
  };

  AV.exportTaxPdf = () => {
    const year = AV.taxYear(AV.state);
    const w = window.open('', '_blank');
    if (!w) { AV.download('alpha-vault-PIT-38-' + year + '.html', AV.taxPrintHtml(), 'text/html;charset=utf-8'); AV.toast('Popup zablokowany — pobrano plik do wydruku'); return; }
    w.document.open(); w.document.write(AV.taxPrintHtml()); w.document.close();
    AV.toast('Otwieram wydruk — wybierz „Zapisz jako PDF”');
  };

  AV.downloadAll = () => {
    const s = AV.state;
    const files = [];
    files.push({ name: 'dane.json', data: JSON.stringify(s, null, 2) });
    files.push({ name: 'transakcje.csv', data: AV.toCsv([['Data', 'Para', 'Kierunek', 'Ocena', 'Wynik', 'R', 'Emocja', 'Setup', 'Notatka']].concat(s.trades.map((t) => [new Date(t.date).toLocaleString('pl-PL'), t.pair, AV.tradeDirection(t.dir) === 's' ? 's' : 'l', t.grade, t.result == null ? '' : t.result, t.r == null ? '' : t.r, t.emotion, t.setup, t.note]))) });
    files.push({ name: 'podatki.csv', data: AV.toCsv(AV.taxCsvRows(AV.taxData(s))) });
    files.push({ name: 'plany.json', data: JSON.stringify(s.plans, null, 2) });
    files.push({ name: 'README.txt', data: 'Alpha Vault — eksport danych\nWygenerowano: ' + AV.nowStamp() + '\n\nZawartość:\n- dane.json: pełny stan aplikacji (profil, transakcje, plany, ustawienia, połączenia)\n- transakcje.csv: dziennik transakcji\n- podatki.csv: zdarzenia podatkowe\n- plany.json: plany dnia\n- screeny/: screeny wykresów (jeśli dodane)\n\nDane są prywatne. Klucze API są zapisane w postaci zamaskowanej.\n' });
    s.trades.forEach((t) => {
      ['before', 'after'].forEach((k) => {
        if (t.screens && t.screens[k]) {
          const bytes = AV.bytesFromDataUrl(t.screens[k]);
          if (bytes) files.push({ name: 'screeny/' + t.id + '-' + k + '.jpg', data: bytes });
        }
      });
    });
    if (s.user.avatar) {
      const bytes = AV.bytesFromDataUrl(s.user.avatar);
      if (bytes) files.push({ name: 'profil/avatar.jpg', data: bytes });
    }
    const blob = AV.zip(files);
    AV.download('alpha-vault-dane-' + AV.dateKey(new Date()) + '.zip', blob);
    AV.toast('Paczka ZIP z danymi gotowa (' + files.length + ' plików)');
  };

  /* ---------- dokumenty / zakup / QA ---------- */
  AV.bindDocs = () => {
    document.addEventListener('click', (e) => {
      const d = e.target.closest('[data-doc]');
      if (!d) return;
      e.preventDefault();
      const tpl = $('#t-' + d.dataset.doc);
      if (!tpl) return;
      AV.setHTML('#docB', '<span class="mclose" data-close="docM">' + AV.ic('x', 'width:16px;height:16px') + '</span>' + tpl.innerHTML);
      $('#docM').classList.add('on'); $('#docM').scrollTop = 0;
    });
    $('#docM')?.addEventListener('click', (e) => { if (e.target.id === 'docM' || e.target.closest('[data-close]')) $('#docM').classList.remove('on'); });
    $('#buyM')?.addEventListener('click', (e) => { if (e.target.id === 'buyM') $('#buyM').classList.remove('on'); });
    $('#qa')?.addEventListener('click', (e) => { if (e.target.id === 'qa') $('#qa').classList.remove('on'); });
    document.addEventListener('click', (e) => {
      const buy = e.target.closest('[data-buy]');
      if (buy) {
        AV.buyPlan = buy.dataset.buy;
        AV.setText('#buyT', AV.buyPlan === 'full' ? 'Akademia Alpha + Vault' : 'Alpha Vault');
        AV.setText('#buyP', AV.buyPlan === 'full' ? '168 $ / miesiąc' : '49 $ / miesiąc');
        $$('#buyM [data-ck]').forEach((c) => c.classList.remove('on'));
        $('#buyGo').disabled = true;
        $('#buyM').classList.add('on');
        return;
      }
      const ck = e.target.closest('#buyM [data-ck]');
      if (ck) { ck.classList.toggle('on'); $('#buyGo').disabled = $$('#buyM [data-ck]').some((x) => !x.classList.contains('on')); return; }
      const qaOpen = e.target.closest('[data-qa]');
      if (qaOpen) { AV.openQuickAdd(); return; }
      const close = e.target.closest('[data-close]');
      if (close) $('#' + close.dataset.close)?.classList.remove('on');
    });
    $('#buyGo')?.addEventListener('click', () => {
      if ($('#buyGo').disabled) return;
      const plan = AV.buyPlan || 'full';
      AV.openModal('<div class="eyebrow">Płatność · tryb demo</div><h1 style="font-size:26px;margin-top:6px">Stripe nie jest podłączony</h1><p class="mut" style="margin-top:10px;line-height:1.6">W tym podglądzie nie pobieramy prawdziwej płatności. Możesz aktywować plan demonstracyjnie, żeby sprawdzić cały panel.</p><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:22px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="activateDemo">Aktywuj plan demo</button></div>', { maxWidth: '520px' });
      $('#activateDemo').onclick = () => {
        AV.state.subscription.plan = plan;
        AV.state.subscription.expired = false;
        AV.state.subscription.until = AV.dateKey(AV.addDays(new Date(), 30));
        AV.state.subscription.renewedAt = Date.now();
        AV.saveNow(); $('#buyM').classList.remove('on'); AV.closeModal();
        AV.setPlan(plan === 'solo', true); AV.renderAll();
        AV.toast('Plan demo aktywowany: ' + (plan === 'full' ? 'Akademia Alpha + Vault' : 'Alpha Vault'));
      };
    });
    const qaRoot = $('#qa');
    qaRoot?.addEventListener('click', (e) => {
      const chip = e.target.closest('#qaEmotion .chip, #qaError .chip');
      if (chip) {
        const group = chip.parentElement;
        group.querySelectorAll('.chip').forEach((x) => x.classList.toggle('on', x === chip));
        AV.qaSyncSave();
        return;
      }
      const grade = e.target.closest('#qaG div');
      if (grade) {
        $$('#qaG div').forEach((x) => x.classList.toggle('on', x === grade));
        AV.qaSyncSave();
      }
    });
    AV.qaSave = () => {
      const t = AV.state.trades.find((x) => x.id === AV.ui.qaTradeId) || AV.pickQuickTrade();
      if (!t) { AV.toast('Brak transakcji do uzupełnienia'); return; }
      const emotion = $('#qaEmotion .chip.on')?.textContent;
      const grade = $('#qaG .on')?.textContent.trim();
      if (!emotion || !grade) { AV.toast('Wybierz emocję i ocenę'); return; }
      t.emotion = emotion;
      t.grade = grade;
      const err = $('#qaError .chip.on')?.textContent;
      t.errors = t.errors || [];
      if (err && err !== 'Brak' && !t.errors.includes(err)) t.errors.push(err);
      if (err === 'Brak') t.errors = t.errors.filter((x) => x !== 'Brak');
      AV.saveNow();
      $('#qa').classList.remove('on');
      AV.renderAll();
      AV.ui.qaLastSeconds = Math.round((performance.now() - (AV.ui.qaOpenedAt || performance.now())) / 100) / 10;
      AV.toast('Zapisane w ' + String(AV.ui.qaLastSeconds).replace('.', ',') + ' s');
    };
    $('#qaSave')?.addEventListener('click', AV.qaSave);
    $('#qaSaveFloat')?.addEventListener('click', AV.qaSave);
    $('#qaPhoto')?.addEventListener('click', () => $('#qaPhotoInput')?.click());
    $('#qaPhotoInput')?.addEventListener('change', async (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      try {
        const data = await AV.readImage(file);
        const t = AV.state.trades.find((x) => x.id === AV.ui.qaTradeId) || AV.pickQuickTrade();
        if (!t) { AV.toast('Brak transakcji'); return; }
        t.screens = t.screens || {};
        t.screens.after = data;
        AV.saveNow();
        const preview = $('#qaPhotoPreview');
        if (preview) { preview.src = data; preview.hidden = false; }
        AV.setText('#qaPhoto', '📷 Zmień zdjęcie wykresu');
        AV.toast('Zdjęcie przypisane do transakcji');
      } catch (error) {
        AV.toast(error.message);
      }
      e.target.value = '';
    });
  };

  /* ---------- prompt ---------- */
  AV.promptText = (title, label, cb) => {
    const body = AV.openModal('<div class="eyebrow">Alpha Vault</div><h1 style="font-size:24px;margin-top:6px">' + AV.esc(title) + '</h1><span class="label">' + AV.esc(label) + '</span><input class="input" id="pmInput" autocomplete="off"><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="pmOk">Zapisz</button></div>', { maxWidth: '460px' });
    setTimeout(() => $('#pmInput', body).focus(), 50);
    const ok = () => { const v = $('#pmInput', body).value.trim(); AV.closeModal(); cb(v); };
    $('#pmOk', body).onclick = ok;
    $('#pmInput', body).addEventListener('keydown', (e) => { if (e.key === 'Enter') ok(); });
  };

  /* ---------- wyceny / licznik ---------- */
  AV.bindMisc = () => {
    const toggle = $('#navToggle');
    toggle?.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = document.body.classList.toggle('nav-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', (e) => {
      if (!document.body.classList.contains('nav-open')) return;
      if (e.target.closest('#sideNav') || e.target.closest('#navToggle')) return;
      document.body.classList.remove('nav-open');
      toggle?.setAttribute('aria-expanded', 'false');
    });
    $('#qaG') && $$('#qaG div').forEach((d) => d.onclick = () => $$('#qaG div').forEach((x) => x.classList.toggle('on', x === d)));
    $$('#qa .sel').forEach((g) => g.querySelectorAll('.chip').forEach((c) => c.onclick = () => {
      g.querySelectorAll('.chip').forEach((x) => x.classList.remove('on')); c.classList.add('on');
    }));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        $$('.modal').forEach((m) => m.classList.remove('on'));
        $('#mw')?.classList.remove('open');
        $('#pro')?.classList.remove('on');
        document.body.classList.remove('nav-open');
        $('#navToggle')?.setAttribute('aria-expanded', 'false');
      }
    });
    $('#mwAll')?.addEventListener('click', () => { AV.showView('kal'); $('#mw').classList.remove('open'); });
    $$('.navg button').forEach((b) => b.onclick = () => AV.showView(b.dataset.v));
    document.addEventListener('click', (e) => {
      const g = e.target.closest('[data-go]');
      if (g) AV.showView(g.dataset.go);
    });
    $('#remember')?.addEventListener('click', () => {
      const on = $('#remember').classList.toggle('on');
      AV.state.settings.remember = on; AV.saveNow();
    });
    $('#enter')?.addEventListener('click', AV.enterApp);
    $('#logout')?.addEventListener('click', async () => {
      if (AV.api.online) { await AV.serverLogout(); location.href = '/'; return; }
      AV.logout(); $('#auth').classList.remove('hide'); AV.toast('Wylogowano');
    });
    window.addEventListener('hashchange', () => { if (AV.isLoggedIn()) AV.showView(AV.viewFromHash()); });
    // akordeony w modalu QA
    $$('#qaG div').forEach((d) => d.onclick = () => $$('#qaG div').forEach((x) => x.classList.toggle('on', x === d)));
  };

  AV.viewFromHash = () => {
    const raw = (location.hash || '').replace(/^#\/?/, '').split('?')[0].replace(/\/+$/, '').trim().toLowerCase();
    return AV.slugViews[raw] || 'pulpit';
  };

  /* ---------- init ---------- */
  AV.init = async () => {
    AV.state = AV.load();
    const boot = await AV.bootstrap();
    AV.state = AV.state || AV.load();
    const hadLocalSession = AV.hasLocalSession();
    if (boot.authenticated) $('#auth').classList.add('hide');
    else if (boot.denied) $('#auth').classList.add('hide');
    else if (boot.offline && AV.isLoggedIn()) $('#auth').classList.add('hide');
    else if (AV.api.online && hadLocalSession) {
      $('#auth').classList.add('hide');
      AV.showAccessGate('Sesja wygasła. Zaloguj się ponownie — dostęp wymaga aktywnej roli Alpha.');
    } else $('#auth').classList.remove('hide');
    $('#roleCheck')?.addEventListener('click', async () => {
      const btn = $('#roleCheck');
      if (btn) btn.disabled = true;
      try {
        const res = await AV.apiFetch('/api/me');
        if (res.ok) {
          const me = await res.json();
          if (me.authenticated && (me.user?.isDemo || !me.roleCheckConfigured || me.user?.subscription?.roleVerified)) {
            location.reload();
            return;
          }
        }
        AV.setText('#roleGateMsg', res.status === 401
          ? 'Sesja wygasła. Zaloguj się ponownie przyciskiem powyżej.'
          : 'Nadal brak aktywnej roli Alpha. Sprawdź rolę na Discordzie i spróbuj ponownie za chwilę.');
      } catch {
        AV.setText('#roleGateMsg', 'Brak połączenia z serwerem. Spróbuj ponownie za chwilę.');
      } finally {
        if (btn) btn.disabled = false;
      }
    });
    $('#roleLogout')?.addEventListener('click', async () => {
      try { await AV.serverLogout(); } catch {}
      AV.logout();
      location.href = '/';
    });
    AV.setPlan(AV.state.subscription.plan === 'solo', true);
    AV.renderAll();
    AV.bindMisc(); AV.bindPlan(); AV.bindJournal(); AV.bindPlaybook(); AV.bindNewTrade();
    AV.bindCalc(); AV.bindMentor(); AV.bindAcademy(); AV.bindTax(); AV.bindCalendar();
    AV.bindBody(); AV.bindExchanges(); AV.bindSettings(); AV.bindDocs(); AV.bindStats(); AV.bindMcp();
    if (AV.bindProp) AV.bindProp();
    AV.renderTicker(); AV.tickCountdowns();
    if (AV.api.authenticated) { AV.loadQuotes(); AV.loadMcpStatus(); }
    AV.updateEnvBadge();
    const params = new URLSearchParams(location.search);
    const connected = params.get('connected');
    if (connected && AV.api.authenticated) {
      const label = AV.api.wearables?.devices?.[connected]?.label || connected;
      AV.toast(params.get('sync') === 'error' ? label + ' połączony, ale pierwsza synchronizacja się nie udała.' : label + ' połączony.');
      AV.showView('cialo');
    }
    setInterval(AV.tickCountdowns, 1000);
    setInterval(AV.loadQuotes, 60000);
    setInterval(() => { if (AV.currentView === 'kalk') AV.loadInstrumentQuotes(); }, 60000);
    setInterval(() => { if (AV.isLoggedIn()) { AV.renderCalendar(); AV.macroNotify(); if (AV.propNotify) AV.propNotify(); } }, 60000);
    let acadDay = AV.warsawDateKey(new Date());
    setInterval(() => {
      if (!AV.isLoggedIn()) return;
      const day = AV.warsawDateKey(new Date());
      if (day !== acadDay) { acadDay = day; AV.renderAcademy(); }
      if (AV.currentView === 'akad') AV.loadEvents();
    }, 60000);
    setInterval(() => { if (AV.isLoggedIn() && AV.currentView !== 'akad') AV.loadEvents(); }, 300000);
    setInterval(() => { if (AV.isLoggedIn()) AV.loadMacroEvents(); }, 300000);
    setInterval(() => { if (AV.isLoggedIn()) AV.loadWearables(); }, 300000);
    const roleCheckEvery = Math.max(300, Number(AV.api.roleRecheckSeconds) || 300) * 1000 + 15000;
    setInterval(() => { if (AV.api.online && AV.api.authenticated) AV.checkAccess(); }, roleCheckEvery);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') { AV.loadQuotes(); if (AV.currentView === 'kalk') AV.loadInstrumentQuotes(); }
      if (document.visibilityState === 'visible' && AV.isLoggedIn()) {
        AV.loadEvents(); AV.loadMacroEvents(); AV.loadWearables();
        if (Date.now() - (AV.api.lastRoleCheckAt || 0) > roleCheckEvery) AV.checkAccess();
      }
    });
    setTimeout(() => { AV.macroNotify(); AV.checkLiveReminder(); if (AV.propNotify) AV.propNotify(); }, 4000);
    AV.showView(AV.viewFromHash());
    if (AV.state.subscription.expired) AV.renderExpired();
    AV.ready = true;
    window.AV = AV;
    if (AV.isLoggedIn()) AV.quickAddFromUrl();
  };

  window.addEventListener('pagehide', () => AV.flushState());
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') AV.flushState(); });

  AV.checkLiveReminder = () => {
    if (!AV.state.meta.liveReminder) return;
    const info = AV.liveInfo();
    if (!info.live || info.liveNow) return;
    const mins = [5, 15, 30, 60].includes(Number(AV.state.settings.liveReminderMinutes)) ? Number(AV.state.settings.liveReminderMinutes) : 15;
    const win = mins * 60;
    const key = String(info.live.id || info.live.startAt || info.live.name);
    const notified = AV.state.meta.liveNotified;
    const already = notified && typeof notified === 'object' ? !!notified[key] : !!notified;
    if (info.sec <= win && info.sec > 0 && !already) {
      if (!notified || typeof notified !== 'object') AV.state.meta.liveNotified = {};
      AV.state.meta.liveNotified[key] = Date.now();
      AV.save();
      AV.toast('Za ' + mins + ' min live: ' + info.live.name, 5000);
      AV.pushNotification('Live za ' + mins + ' min', info.live.name);
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', AV.init);
  else AV.init();
})();
