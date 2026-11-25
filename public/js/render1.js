/* Alpha Vault — renderowanie widoków (część 1) */
(function () {
  'use strict';
  const AV = window.AV, $ = AV.$, $$ = AV.$$;
  AV.ui = AV.ui || {};

  AV.ic = (n, s = '') => '<svg class="i" style="' + s + '"><use href="#i-' + n + '"/></svg>';
  AV.setText = (sel, txt) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el) el.textContent = txt; };
  AV.setHTML = (sel, html) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el) el.innerHTML = html; };
  AV.setVal = (sel, val) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el && el.value !== val) el.value = val; };
  AV.show = (sel, on) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el) el.style.display = on ? '' : 'none'; };
  AV.stars = (n) => '<span class="stars">' + '★'.repeat(n) + '<i>' + '★'.repeat(3 - n) + '</i></span>';
  AV.grade = (g, cls = '') => {
    const value = String(g == null ? '' : g).trim();
    return value ? '<span class="grade ' + AV.attr(value) + ' ' + cls + '">' + AV.esc(value) + '</span>' : '';
  };

  AV.curRate = { USDT: 1, USD: 1, PLN: 4, EUR: 0.92 };
  AV.curLabel = () => AV.state.user.currency || 'USDT';
  AV.disp = (n) => (Number(n) || 0) * (AV.curRate[AV.curLabel()] || 1);
  AV.dispMoney = (n, d = 2) => AV.money(AV.disp(n), d, AV.curLabel());

  AV.applyAccent = () => {
    const a = AV.state.user.accent || { a: '217,178,95', b: '#f1d48e' };
    document.documentElement.style.setProperty('--a', a.a);
    document.documentElement.style.setProperty('--alpha2', a.b);
  };

  AV.renderUser = () => {
    const s = AV.state, u = s.user;
    AV.applyAccent();
    AV.setText('#sideName', u.name);
    AV.setText('#profileName', u.name);
    AV.setText('#profileEmail', u.email || '—');
    const joined = u.joined ? new Date(String(u.joined).replace(' ', 'T')) : null;
    AV.setText('#profileMeta', (joined && !Number.isNaN(joined.getTime()) ? 'Trader Alpha od ' + joined.toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' }) + ' · ' : '') + AV.streak(s) + ' dni');
    AV.setText('#goalTxt', u.goal || '—');
    AV.setText('#streakBadge', AV.streak(s) + ' dni');
    const market = (u.markets && u.markets[0]) || '';
    AV.setHTML('#profileBadge', market ? '<b>' + AV.esc(market) + '</b>' : '');
    AV.show('#profileBadge', Boolean(market));
    const planLabel = s.subscription.plan === 'full' ? 'Alpha + Akademia' : 'Alpha Vault';
    const planUntil = s.subscription.until ? new Date(s.subscription.until).toLocaleDateString('pl-PL') : '';
    AV.setText('#sidePlan', planUntil ? planLabel + ' · do ' + planUntil : planLabel);
    AV.setText('#planName', (s.subscription.plan === 'full' ? 'Akademia Alpha + Vault · 168 $ / mies.' : 'Alpha Vault · 49 $ / mies.'));
    AV.setText('#planValidUntil', planUntil);
    AV.show('#planUntilRow', Boolean(planUntil));
    AV.setText('#planRole', (u.email || AV.api.me?.username || AV.api.me?.globalName || '—') + ' · rola ' + (s.subscription.role || '—') + (s.subscription.role ? ' ✓' : ''));
    AV.setText('#roleCheck', 'dziś, ' + new Date().toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }));

    const big = AV.$('#pulpit .head h1 .serif');
    if (big) big.textContent = u.name + '.';

    $$('.av').forEach((av) => {
      const avatar = u.avatar || u.discordAvatar;
      if (avatar) {
        av.style.backgroundImage = 'url(' + avatar + ')';
        av.classList.add('img');
        av.textContent = '';
      } else {
        av.style.backgroundImage = '';
        av.classList.remove('img');
        av.textContent = (u.name || 'A').slice(0, 1).toUpperCase();
      }
    });
    const coursePct = typeof AV.coursePct === 'function' ? AV.coursePct() : (s.meta.courseProgress || 0);
    AV.setText('#courseTop', coursePct + '%');
    AV.setText('#courseTxt', coursePct + '% ukończone');
    if (AV.$('#courseBar')) AV.$('#courseBar').style.width = coursePct + '%';
    AV.setText('#consultTop', AV.shortConsult(s));
  };

  AV.renderNavBadges = () => {
    const s = AV.state;
    const plans = AV.plansForDate(s, AV.dateKey(new Date()));
    const planBadge = $('.navg button[data-v="plan"] .badge');
    if (planBadge) {
      const approved = plans.some((plan) => plan.approvedAt);
      planBadge.textContent = approved ? '✓' : '';
      planBadge.className = 'badge g';
      planBadge.style.display = approved ? '' : 'none';
    }
    const acadBadge = $('.navg button[data-v="akad"] .badge');
    if (acadBadge) {
      const live = AV.liveInfo ? AV.liveInfo().liveNow : false;
      acadBadge.textContent = live ? 'LIVE' : '';
      acadBadge.className = 'badge';
      acadBadge.style.display = live ? '' : 'none';
    }
  };

  AV.shortConsult = (s) => {
    const c = s.meta.consultation || {};
    const d = c.date ? new Date(c.date + 'T' + (c.time || '18:00') + ':00') : null;
    return d ? d.toLocaleDateString('pl-PL', { weekday: 'short', day: '2-digit', month: '2-digit' }) + ', ' + (c.time || '18:00') : '—';
  };

  /* ---------- tryb ochronny ---------- */
  AV.renderLock = () => {
    const s = AV.state, L = AV.lockInfo(s);
    $('.lkb') && $$('.lkb').forEach((el) => {
      if (el.id === 'lockWrap' || el.id === 'lockBar' || el.id === 'lockCard') return;
      if (el.classList.contains('lk') || el.classList.contains('badge')) el.style.display = L.active ? '' : 'none';
    });
    const bar = $('#lockBar');
    if (bar) bar.style.display = L.active ? '' : 'none';
    AV.setText('#lockTxt', L.active
      ? 'Masz ' + L.losses + ' ' + AV.plural(L.losses, 'stratę', 'straty', 'strat') + ' dziś (limit ' + L.limit + '). Nowe pozycje są zablokowane do ' + (L.until ? L.until.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : 'północy') + '.'
      : '');
    $$('.cdt').forEach((e) => {
      if (L.active && L.until) {
        const sec = Math.max(0, Math.floor((L.until - new Date()) / 1000));
        const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), x = sec % 60;
        e.textContent = [h, m, x].map((v) => String(v).padStart(2, '0')).join(':');
      } else e.textContent = '--:--:--';
    });
    const editing = !!AV.ui.editId && !!AV.state.trades.find((t) => t.id === AV.ui.editId);
    const blocked = L.active && !editing;
    const wrap = $('#lockWrap');
    if (wrap) {
      wrap.classList.toggle('locked', blocked);
      const card = $('#lockCard');
      if (card) card.style.display = blocked ? '' : 'none';
      const form = wrap.querySelector('.formwrap');
      if (form) form.style.display = blocked ? 'none' : '';
    }
    const navBadge = $('.navg button[data-v="nowa"] .badge');
    if (navBadge) navBadge.style.display = L.active ? '' : 'none';
    const chip = $('.chipst.lk');
    if (chip) chip.style.display = L.active ? '' : 'none';
    if (blocked) {
      const card = $('#lockCard');
      if (card) {
        const p = card.querySelector('p');
        if (p) p.textContent = 'Masz ' + L.losses + ' ' + AV.plural(L.losses, 'stratę', 'straty', 'strat') + ' z ' + L.limit + ' dozwolonych. Ta zasada chroni Cię przed sobą w złym dniu. Nowe pozycje odblokują się o ' + (L.until ? L.until.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : 'północy') + '.';
      }
    }
  };

  /* ---------- pulpit ---------- */
  AV.renderDashboard = () => {
    const s = AV.state, today = AV.todayStats(s), stats = AV.computeStats(AV.rangeTrades(s, 90), s);
    AV.setText('#dayPnl', AV.dispMoney(today.pnl, 2).replace(/^[+−]/, (m) => m) + ' ');
    const pnlEl = $('#dayPnl');
    if (pnlEl) {
      pnlEl.innerHTML = (today.pnl >= 0 ? '+' : '−') + AV.fmt(Math.abs(AV.disp(today.pnl)), 2) + ' <span class="mut" style="font-size:14px;font-weight:500">' + AV.curLabel() + '</span>';
      pnlEl.className = 'val ' + (today.pnl >= 0 ? 'win' : 'loss');
    }
    const todayPlans = AV.plansForDate(s, AV.dateKey(new Date()));
    const plan = todayPlans.find((item) => item.approvedAt) || todayPlans[0] || {};
    const planLimitsAmbiguous = todayPlans.length > 1;
    const lossLimit = Math.max(1, Number(s.settings.maxLosses) || 2);
    AV.setText('#dayLosses', today.losses + ' / ' + lossLimit);
    AV.$('#lossBar').style.width = AV.clamp(today.losses / lossLimit * 100, 0, 100) + '%';
    const tradesBar = AV.$('#tradesBar');
    AV.setText('#dayTrades', planLimitsAmbiguous ? String(today.count) : today.count + ' / ' + (plan.maxTrades || 5));
    tradesBar.style.width = planLimitsAmbiguous ? '0%' : AV.clamp(today.count / (Number(plan.maxTrades) || 5) * 100, 0, 100) + '%';
    tradesBar.parentElement.style.display = planLimitsAmbiguous ? 'none' : '';
    const riskPct = today.risk / (AV.totalCapital(s) || 1) * 100;
    const riskBar = AV.$('#riskBar');
    AV.setText('#dayRisk', AV.fmt(riskPct, 1) + '%' + (planLimitsAmbiguous ? '' : ' / ' + (plan.risk || '3%')));
    riskBar.style.width = planLimitsAmbiguous ? '0%' : AV.clamp(riskPct / (AV.pnum(plan.risk) || 3) * 100, 0, 100) + '%';
    riskBar.parentElement.style.display = planLimitsAmbiguous ? 'none' : '';

    const planCards = (todayPlans.length ? todayPlans : [plan]).map((item) => {
      const levels = (item.levels || []).filter((l) => l.price);
      const sup = levels.find((l) => l.type === 'Wsparcie');
      const res = levels.find((l) => l.type === 'Opór');
      const liquidity = levels.find((l) => l.type === 'Liquidity' || l.type === 'Płynność');
      const poi = levels.find((l) => l.type === 'POI');
      const levelSummary = [
        res ? AV.esc(res.price) + ' opór' : '',
        sup ? AV.esc(sup.price) + ' wsparcie' : '',
        liquidity ? AV.esc(liquidity.price) + ' płynność' : '',
        poi ? AV.esc(poi.price) + ' POI' : ''
      ].filter(Boolean).join(' · ');
      return '<div style="font-weight:800;font-size:18px;margin-top:2px">' + AV.esc(item.market || '') + ' · <span class="' + (item.bias === 'short' ? 'loss' : item.bias === 'none' ? 'dim' : 'win') + '">' + AV.esc(item.bias || '') + '</span></div>' +
        '<div class="mono dim" style="font-size:12px;margin-top:8px">' + levelSummary + '</div>';
    }).join('<hr style="border:0;border-top:1px solid var(--line);margin:10px 0">');
    AV.setHTML('#dashPlan', '<div class="mono" style="font-size:12px;color:var(--dim)">BIAS</div>' + planCards);
    AV.setText('#dashPlanTag', todayPlans.length > 1 ? 'wiele planów'
      : plan.approvedAt ? new Date(plan.approvedAt).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })
        : todayPlans.length === 1 ? 'szkic' : '');
    AV.setText('#dashAdherence', todayPlans.some((item) => item.approvedAt) && today.arr.length ? today.arr.filter((t) => AV.tradePlanMatch(s, t)).length + ' z ' + today.arr.length : '—');

    AV.setText('#dash30', (stats.net >= 0 ? '+' : '−') + AV.fmt(Math.abs(stats.net / (AV.startCapital(s) || 2000) * 100), 1) + '%');
    AV.setText('#dashResult', AV.dispMoney(stats.net, 2));
    AV.setText('#dashWr', Math.round(stats.winRate) + '%');
    AV.setText('#dashGradeA', Math.round((stats.byGrade.find((g) => g.grade === 'A')?.n || 0) / (stats.n || 1) * 100) + '%');
    AV.renderSpark(stats.curve);
    AV.renderDashMentor(today);
    AV.renderDashBalance();
  };

  AV.renderDashBalance = () => {
    const s = AV.state;
    const accounts = Array.isArray(s.accounts) ? s.accounts : [];
    const total = AV.totalCapital(s);
    const startTotal = AV.startCapital(s);
    const includedAccounts = accounts.filter((account) => account.include);
    const numberOrNull = (value) => {
      if (value == null || (typeof value === 'string' && value.trim() === '')) return null;
      const numeric = Number(value);
      return Number.isFinite(numeric) ? numeric : null;
    };
    const balancesComplete = includedAccounts.length > 0 &&
      includedAccounts.every((account) => numberOrNull(account.balance) !== null);
    const startsComplete = includedAccounts.length > 0 &&
      includedAccounts.every((account) => numberOrNull(account.start) !== null);
    const primary = accounts.find((a) => a.primary) || accounts[0] || null;
    const cur = AV.curLabel();
    AV.setHTML('#dashBalVal', balancesComplete
      ? AV.fmt(total, 2) + ' <span class="mut" style="font-size:12px;font-weight:600">' + AV.esc(cur) + '</span>'
      : '');
    const equity = typeof AV.calculatedEquity === 'function' ? AV.calculatedEquity(s) : null;
    AV.setHTML('#dashEquityVal', equity != null && startsComplete && Number.isFinite(Number(equity))
      ? AV.fmt(Number(equity), 2) + ' <span class="mut" style="font-size:12px;font-weight:600">USDT</span>'
      : '');
    const pct = balancesComplete && startsComplete && startTotal > 0 ? (total - startTotal) / startTotal * 100 : null;
    let sub = '';
    if (accounts.length) {
      sub = accounts.length + ' ' + AV.plural(accounts.length, 'konto', 'konta', 'kont') +
        (primary ? ' · główne: ' + primary.name : '') +
        (pct === null ? '' : ' · saldo ręczne ' + AV.signed(pct, 1, '%') + ' od startu');
    }
    AV.setText('#dashBalSub', sub);
    AV.setHTML('#dashAccountList', accounts.map((account) => {
      const balanceValue = numberOrNull(account.balance);
      const balance = balanceValue === null ? '' : AV.fmt(balanceValue, 2) + ' ' + AV.esc(account.currency || '');
      const startValue = numberOrNull(account.start);
      const start = startValue === null ? '' : AV.fmt(startValue, 2) + ' ' + AV.esc(account.currency || '');
      const growth = startValue !== null && startValue > 0 &&
        balanceValue !== null && balanceValue >= 0
        ? AV.signed((balanceValue - startValue) / startValue * 100, 1, '%')
        : '';
      const details = [start ? 'start ' + start : '', growth].filter(Boolean).join(' · ');
      return '<div class="dash-account"><div><b>' + AV.esc(account.name || '') + '</b><small>' + AV.esc(account.exchange || account.type || '') + (account.include ? '' : ' · poza statystykami') + '</small></div><div class="dash-account-values"><b class="mono">' + balance + '</b><small>' + details + '</small></div></div>';
    }).join(''));
  };

  AV.renderSpark = (curve) => {
    const line = $('#dashSparkLine'), fill = $('#dashSparkFill');
    if (!line || !fill) return;
    const pts = curve.length > 1 ? curve : [{ x: 0, eq: 2000 }, { x: 1, eq: 2000 }];
    const vals = pts.map((p) => p.eq);
    const mn = Math.min(...vals), mx = Math.max(...vals), span = mx - mn || 1;
    const xy = pts.map((p, i) => [i / (pts.length - 1) * 300, 80 - (p.eq - mn) / span * 60]);
    const d = 'M' + xy.map(([x, y]) => x.toFixed(1) + ',' + y.toFixed(1)).join(' L');
    line.setAttribute('d', d);
    fill.setAttribute('d', d + ' L300,90 L0,90Z');
  };

  AV.renderDashMentor = (today) => {
    const box = $('#dashMentor');
    if (!box) return;
    const arr = today.closed.slice().sort((a, b) => new Date(b.date) - new Date(a.date));
    if (!arr.length) {
      AV.setHTML(box, '<div class="sech" style="margin:0"><span class="tag praise">Mentor · spokój</span><span class="link" data-go="mentor">Wszystkie uwagi ' + AV.ic('arrow', 'width:14px;height:14px') + '</span></div><h3>Dziś jeszcze nie ma transakcji</h3><p>Dobry moment, żeby najpierw zrobić plan i check-in. Mentor oceni dzień, kiedy pojawią się transakcje.</p><div class="step" data-go="plan" style="cursor:pointer"><b>Krok:</b> plan dnia przed pierwszą transakcją. <span class="link" style="margin-left:6px">Uzupełnij plan ' + AV.ic('arrow', 'width:14px;height:14px') + '</span></div>');
      return;
    }
    const last = arr[0];
    const revenge = arr.some((t) => (t.errors || []).includes('Wejście po stracie'));
    const title = revenge
      ? 'To wygląda na odgrywanie się'
      : last.result > 0
        ? 'Dobra robota, trzymaj proces'
        : last.result === 0
          ? 'Wynik na zero — trzymaj proces'
          : 'Strata mieści się w planie?';
    const text = revenge
      ? 'Widać wejście po stracie i większą pozycję. Rynek nie wie o stracie. Zamknij platformę na 30 minut.'
      : last.result > 0
        ? 'Ostatnia transakcja: ' + AV.esc(last.pair) + ' ' + AV.money(last.result, 2, '') + '. Oceniasz decyzję, nie wynik — trzymaj checklistę.'
        : last.result === 0
          ? 'Ostatnia transakcja: ' + AV.esc(last.pair) + ' zamknięta na zero. Oceń jakość decyzji i zgodność z planem.'
          : 'Ostatnia transakcja: ' + AV.esc(last.pair) + ' ' + AV.money(last.result, 2, '') + '. Sprawdź, czy SL był ustawiony przed wejściem, i nie odrabiaj straty.';
    const mentorTagClass = revenge || last.result < 0 ? 'alert' : last.result === 0 ? 'info' : 'praise';
    const mentorTag = revenge ? 'alarm' : last.result === 0 ? 'break-even' : 'uwaga';
    AV.setHTML(box, '<div class="sech" style="margin:0"><span class="tag ' + mentorTagClass + '">Mentor · ' + mentorTag + '</span><span class="link" data-go="mentor">Wszystkie uwagi ' + AV.ic('arrow', 'width:14px;height:14px') + '</span></div>' +
      '<h3>' + title + '</h3><p>' + text + '</p><div class="step"><b>Krok:</b> ' + (revenge ? '30 minut przerwy od wykresu.' : 'zapisz emocję i ocenę w dzienniku.') + '</div>');
  };

  /* ---------- plan dnia ---------- */
  AV.planDate = AV.planDate || AV.dateKey(new Date());
  AV.planId = AV.planId || null;
  AV.PLAN_MARKETS = ['BTC', 'ETH', 'SOL', 'XRP', 'BNB', 'DOGE', 'LINK', 'AVAX', 'EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCHF', 'USDCAD', 'NZDUSD', 'EURGBP', 'NAS100', 'US500', 'US30', 'GER40', 'UK100', 'JPN225', 'XAUUSD', 'XAGUSD', 'OIL'];
  AV.syncPlanMarket = (p) => {
    const sel = $('#planMarketSelect');
    const input = $('#planMarketCustom');
    if (!sel) return;
    if (!sel.dataset.built) {
      sel.innerHTML = '<option value="">— wybierz rynek —</option>' +
        AV.PLAN_MARKETS.map((m) => '<option value="' + AV.attr(m) + '">' + AV.esc(m) + '</option>').join('') +
        '<option value="__other">Inny rynek…</option>';
      sel.dataset.built = '1';
    }
    const market = String((p && p.market) || '');
    const known = AV.PLAN_MARKETS.includes(market);
    sel.value = !market ? '' : (known ? market : '__other');
    if (input) {
      input.style.display = sel.value === '__other' ? '' : 'none';
      if (!known && market && document.activeElement !== input) input.value = market;
    }
    $$('#planMarkets .chip').forEach((c) => c.classList.toggle('on', c.dataset.m === market));
  };
  AV.renderPlan = () => {
    const s = AV.state;
    const key = AV.planDate;
    if (!s.plans || typeof s.plans !== 'object') s.plans = {};
    const plans = AV.plansForDate(s, key);
    let p = AV.planById(s, AV.planId);
    if (!p || p.date !== key) p = plans[0] || AV.planTemplate(key);
    AV.planId = p.id || null;
    const picker = $('#planSelect');
    if (picker) {
      picker.innerHTML = plans.map((item, index) => {
        const title = item.market ? item.market + ' · plan ' + (index + 1) : 'Nowy plan · ' + (index + 1);
        return '<option value="' + AV.attr(item.id) + '">' + AV.esc(title) + (item.approvedAt ? ' · zatwierdzony' : ' · szkic') + '</option>';
      }).join('');
      if (!plans.length) picker.innerHTML = '<option value="">Nowy plan</option>';
      picker.value = AV.planId || '';
      picker.disabled = plans.length < 2;
    }
    AV.$$('#planSeg button').forEach((b) => b.classList.toggle('on', b.dataset.day === 'global' ? key === 'global' : b.dataset.day === 'hist' ? !!AV.ui.planHistOpen : b.dataset.day === '0' ? key === AV.dateKey(new Date()) : key === AV.dateKey(AV.addDays(new Date(), -1))));
    // Nie nadpisuj formularza, w którym użytkownik właśnie pisze (np. render po notowaniach).
    const editor = $('#planEditor');
    const active = document.activeElement;
    const editing = !!(editor && active && active !== document.body && editor.contains(active) &&
      !['planSelect', 'addPlan'].includes(active.id));
    if (!editing) {
      AV.syncPlanMarket(p);
      $$('#planBias .chip').forEach((c) => c.classList.toggle('on', c.dataset.b === p.bias));
      AV.setVal('#planWhy', p.why || '');
      AV.setVal('#planIfMain', p.ifMain || '');
      AV.setVal('#planThenMain', p.thenMain || '');
      AV.setVal('#planIfAlt', p.ifAlt || '');
      AV.setVal('#planThenAlt', p.thenAlt || '');
      AV.setVal('#planRisk', p.risk || '');
      AV.setVal('#planMaxTrades', p.maxTrades || '');
      AV.setVal('#planUntil', p.until || '');
      AV.setVal('#planNotDo', p.notDo || '');
      $('#lvls').innerHTML = (p.levels || []).map((l) => AV.levelRow(l)).join('') || '<div class="dim" style="padding:8px 0">Brak poziomów. Dodaj pierwszy.</div>';
    }
    AV.renderPlanSide();
    AV.renderPlanGlobal();
    AV.renderPlanHistory();
    AV.renderAdherence();
    const title = $('#planListTitle');
    if (title) title.textContent = key === 'global' ? 'Plany globalne' : 'Plany dla tej daty';
    if (AV.renderNavBadges) AV.renderNavBadges();
  };

  AV.PLAN_LEVEL_TYPES = ['Opór', 'Wsparcie', 'Liquidity', 'Płynność', 'POI'];
  AV.levelRow = (l = {}) => {
    const known = AV.PLAN_LEVEL_TYPES.includes(l.type);
    const custom = !!l.type && !known;
    return '<div class="lvl' + (custom ? ' has-custom' : '') + '"><select class="input" aria-label="Typ poziomu">' +
      [['Opór', 'Opór'], ['Wsparcie', 'Wsparcie'], ['Liquidity', 'Płynność'], ['POI', 'POI'], ['__custom', 'Własny…']].map(([value, label]) => '<option value="' + value + '"' + (l.type === value || value === 'Liquidity' && l.type === 'Płynność' || value === '__custom' && custom ? ' selected' : '') + '>' + label + '</option>').join('') +
      '</select><input class="input lvl-type" value="' + AV.attr(custom ? l.type : '') + '" placeholder="własny poziom" style="' + (custom ? '' : 'display:none') + '"><input class="input mono" value="' + AV.attr(l.price || '') + '" placeholder="cena"><input class="input" value="' + AV.attr(l.note || '') + '" placeholder="opis"><span class="x">✕</span></div>';
  };

  AV.readPlanForm = (opts = {}) => {
    const levels = $$('#lvls .lvl').map((row) => {
      const select = row.querySelector('select');
      const custom = row.querySelector('.lvl-type');
      const type = select.value === '__custom' ? ((custom && custom.value.trim()) || 'Własny') : select.value;
      return {
        type,
        price: row.querySelectorAll('input.mono')[0].value.trim(),
        note: row.querySelectorAll('input:not(.mono):not(.lvl-type)')[0].value.trim()
      };
    });
    const sel = $('#planMarketSelect');
    const selVal = sel ? sel.value : '';
    const market = selVal === '__other'
      ? ($('#planMarketCustom')?.value || '').trim()
      : (selVal || $('#planMarkets .chip.on')?.dataset.m || '');
    return {
      id: AV.planId || AV.uid('plan'),
      date: AV.planDate,
      market: market,
      bias: $('#planBias .chip.on')?.dataset.b || '',
      why: $('#planWhy').value.trim(),
      levels: opts.pruneEmptyLevels ? levels.filter((l) => l.price || l.note) : levels,
      ifMain: $('#planIfMain').value.trim(), thenMain: $('#planThenMain').value.trim(),
      ifAlt: $('#planIfAlt').value.trim(), thenAlt: $('#planThenAlt').value.trim(),
      risk: $('#planRisk').value.trim(), maxTrades: $('#planMaxTrades').value.trim(),
      until: $('#planUntil').value.trim(), notDo: $('#planNotDo').value.trim(),
      approvedAt: (AV.planById(AV.state, AV.planId) || {}).approvedAt || null
    };
  };

  AV.renderPlanGlobal = () => {
    const card = $('#planGlobalCard');
    if (!card) return;
    const active = AV.planDate === 'global';
    card.style.display = active ? '' : 'none';
    if (!active) return;
    const plans = AV.plansForDate(AV.state, 'global');
    AV.setText('#planGlobalCount', plans.length + ' ' + AV.plural(plans.length, 'plan', 'plany', 'planów'));
    AV.setHTML('#planGlobalGrid', plans.length ? plans.map((p) => {
      const bias = p.bias === 'long' ? '<span class="win">long</span>' : p.bias === 'short' ? '<span class="loss">short</span>' : p.bias === 'none' ? '<span class="dim">nie gram</span>' : '<span class="al">neutralnie</span>';
      const levels = (p.levels || []).slice(0, 4).map((l) => '<div class="kv"><span>' + AV.esc(l.type || '') + (l.note ? ' · ' + AV.esc(l.note) : '') + '</span><b class="mono">' + AV.esc(l.price || '') + '</b></div>').join('');
      return '<div class="card flat" data-plan-open="global" data-plan-id="' + AV.attr(p.id) + '" style="cursor:pointer;padding:14px;' + (p.id === AV.planId ? 'border-color:var(--alpha)' : '') + '">' +
        '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><b style="font-size:16px">' + AV.esc(p.market || 'Nowy plan') + '</b>' + bias +
        '<span class="tag ' + (p.approvedAt ? 'praise' : 'info') + '" style="margin-left:auto">' + (p.approvedAt ? 'zatwierdzony' : 'szkic') + '</span></div>' +
        (levels ? '<div style="margin-top:8px">' + levels + '</div>' : '') +
        (p.ifMain ? '<div class="hint" style="margin-top:8px"><b>Główny:</b> ' + AV.esc(p.ifMain) + (p.thenMain ? ' → ' + AV.esc(p.thenMain) : '') + '</div>' : '') +
        (p.ifAlt ? '<div class="hint" style="margin-top:4px"><b>Awaryjny:</b> ' + AV.esc(p.ifAlt) + (p.thenAlt ? ' → ' + AV.esc(p.thenAlt) : '') + '</div>' : '') +
        '<div class="hint" style="margin-top:8px">ryzyko ' + AV.esc(p.risk || '—') + ' · max ' + AV.esc(p.maxTrades || '—') + ' transakcji' + (p.until ? ' · do ' + AV.esc(p.until) : '') + '</div></div>';
    }).join('') : '<div class="dim" style="padding:8px 0">Brak planów globalnych. Dodaj pierwszy po lewej stronie.</div>');
  };

  AV.renderPlanSide = () => {
    if (!AV.state.plans || typeof AV.state.plans !== 'object') AV.state.plans = {};
    const p = AV.planById(AV.state, AV.planId) || AV.readPlanForm();
    const isGlobal = AV.planDate === 'global';
    const day = new Date(AV.planDate + 'T12:00:00');
    AV.setText('#planSideDay', isGlobal ? 'Plan globalny · długoterminowy' : 'Twój plan · ' + day.toLocaleDateString('pl-PL', { weekday: 'long' }));
    const tag = $('#planSideTag');
    if (tag) {
      tag.textContent = p.approvedAt ? 'zatwierdzony ' + new Date(p.approvedAt).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : 'szkic';
      tag.className = 'tag ' + (p.approvedAt ? 'praise' : 'info');
    }
    const biasTxt = p.bias === 'long' ? '<span class="win">long</span>' : p.bias === 'short' ? '<span class="loss">short</span>' : p.bias === 'none' ? '<span class="dim">nie gram</span>' : '<span class="al">neutralnie</span>';
    AV.setHTML('#planSideTitle', AV.esc(p.market || '—') + ' · ' + biasTxt);
    const lv = p.levels || [];
    const sup = lv.find((l) => l.type === 'Wsparcie');
    const res = lv.find((l) => l.type === 'Opór');
    const liquidity = lv.find((l) => l.type === 'Liquidity' || l.type === 'Płynność' || l.type === 'liquidity');
    const poi = lv.find((l) => l.type === 'POI' || l.type === 'poi');
    AV.setText('#planSideSub', [res && res.price ? res.price + ' opór' : '', sup && sup.price ? sup.price + ' wsparcie' : '', liquidity && liquidity.price ? liquidity.price + ' płynność' : '', poi && poi.price ? poi.price + ' POI' : ''].filter(Boolean).join(' · '));
    const todayEv = AV.buildEvents().filter((e) => e.imp === 3 && AV.dateKey(new Date(e.date)) === AV.dateKey(new Date())).sort((a, b) => new Date(a.date) - new Date(b.date))[0];
    const tomorrowEv = AV.buildEvents().filter((e) => e.imp === 3 && AV.dateKey(new Date(e.date)) === AV.dateKey(AV.addDays(new Date(), 1)))[0];
    AV.setHTML('#planSideBody',
      lv.slice(0, 4).map((l) => '<div class="kv"><span>' + AV.esc(l.type === 'Liquidity' || l.type === 'Płynność' ? 'Płynność' : l.type) + (l.note ? ' · ' + AV.esc(l.note) : '') + '</span><b class="mono ' + (l.type === 'Wsparcie' && l.note && /unieważ/i.test(l.note) ? 'loss' : '') + '">' + AV.esc(l.price || '') + '</b></div>').join('') +
      '<div class="kv"><span>Makro dziś</span><b>' + (todayEv ? AV.esc(todayEv.name) + ' ' + AV.timeFmt(todayEv.date, AV.state) : '') + '</b></div>' +
      '<div class="kv"><span>Makro jutro</span><b class="al">' + (tomorrowEv ? AV.esc(tomorrowEv.name) + ' ' + AV.timeFmt(tomorrowEv.date, AV.state) : '') + '</b></div>' +
      '<div class="kv"><span>Limity</span><b>ryzyko ' + AV.esc(p.risk || '—') + ' · max ' + AV.esc(p.maxTrades || '—') + ' transakcji</b></div>');
  };

  AV.renderPlanHistory = () => {
    const card = $('#planHistoryCard');
    if (!card) return;
    const showHist = AV.ui.planHistOpen || (AV.planDate !== 'global' && AV.planDate !== AV.dateKey(new Date()) && AV.planDate !== AV.dateKey(AV.addDays(new Date(), -1)));
    card.style.display = showHist ? '' : 'none';
    if (!showHist) return;
    const plans = AV.allPlans(AV.state).filter((p) => p.date !== 'global').sort((a, b) => b.date.localeCompare(a.date) || String(a.market).localeCompare(String(b.market)) || String(a.id).localeCompare(String(b.id)));
    AV.setHTML('#planHistory', plans.length ? plans.map((p) => '<div class="kv" style="cursor:pointer" data-plan-open="' + AV.attr(p.date) + '" data-plan-id="' + AV.attr(p.id) + '"><span>' + new Date(p.date + 'T12:00:00').toLocaleDateString('pl-PL', { weekday: 'long', day: '2-digit', month: '2-digit' }) + ' · ' + AV.esc(p.market || '') + ' ' + AV.esc(p.bias || '') + '</span><b class="' + (p.approvedAt ? 'win' : 'dim') + '">' + (p.approvedAt ? '✓ zatwierdzony' : 'szkic') + '</b></div>').join('') : '<div class="dim">Brak zapisanych planów.</div>');
  };

  AV.renderAdherence = () => {
    const a = AV.planAdherence(AV.state);
    AV.setText('#adherenceVal', a.approved + ' z ' + a.total + ' dni');
    const closed = AV.lifecycleClosedTrades(AV.state);
    const buckets = { ok: { n: 0, sum: 0 }, no: { n: 0, sum: 0 } };
    a.days.forEach((d) => {
      const key = AV.dateKey(d.d);
      const dayTrades = closed.filter((t) => AV.dateKey(t.date) === key && t.result != null);
      if (!dayTrades.length) return;
      const bucket = d.ok ? buckets.ok : buckets.no;
      bucket.n += dayTrades.length;
      bucket.sum += dayTrades.reduce((sum, t) => sum + (Number(t.result) || 0), 0);
    });
    const avg = (b) => (b.n ? AV.signed(b.sum / b.n, 2, ' USDT') : '');
    const parts = [];
    if (buckets.ok.n) parts.push('w dni zgodne z planem średnio ' + avg(buckets.ok));
    if (buckets.no.n) parts.push('w pozostałe ' + avg(buckets.no));
    AV.setText('#adherenceHint', parts.join(' · '));
    AV.setHTML('#adherenceHist', a.days.map((d) => '<i style="background:var(--' + (d.ok ? 'winS' : 'lossS') + ');color:var(--' + (d.ok ? 'win' : 'loss') + ')">' + d.d.toLocaleDateString('pl-PL', { weekday: 'short' }).replace('.', '') + '</i>').join(''));
  };

  /* ---------- dziennik ---------- */
  AV.ui.jFilter = 'all';
  AV.ui.jGrade = 'all';
  AV.ui.jSearch = '';
  AV.filteredTrades = () => {
    let arr = (Array.isArray(AV.state.trades) ? AV.state.trades : []).slice().sort((a, b) => new Date(b.date) - new Date(a.date));
    if (AV.ui.jFilter === 'win') arr = arr.filter((t) => t.result > 0);
    if (AV.ui.jFilter === 'loss') arr = arr.filter((t) => t.result < 0);
    if (AV.ui.jFilter === 'open') arr = arr.filter((t) => !AV.isTradeClosed(t));
    if (AV.ui.jGrade !== 'all') arr = arr.filter((t) => t.grade === AV.ui.jGrade);
    if (AV.ui.jSearch) {
      const q = AV.ui.jSearch.toLowerCase();
      arr = arr.filter((t) => [t.pair, t.emotion, t.setup, t.note, (t.errors || []).join(' ')].join(' ').toLowerCase().includes(q));
    }
    return arr;
  };

  AV.tradeLivePnl = (t) => {
    if (!t || !AV.tradeDirection(t.dir)) return null;
    const entry = AV.pnum(t.entry), size = AV.pnum(t.size);
    if (!(entry > 0) || !(size > 0)) return null;
    const key = String(t.pair || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    let price = null;
    const iq = AV.instrumentQuote(t.pair);
    if (iq) price = iq.price;
    if (price == null && Array.isArray(AV.tickerData)) {
      const hit = AV.tickerData.find((row) => String(row[0] || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase() === key);
      if (hit) price = Number(hit[1]);
    }
    if (!Number.isFinite(price) || price <= 0) return null;
    const sign = AV.tradeDirection(t.dir) === 's' ? -1 : 1;
    return { price, value: (price - entry) * size * sign, pct: (price - entry) / entry * 100 * sign };
  };

  AV.tradeAccount = (state, trade) => (state.accounts || []).find((account) => account.name === trade?.account) || null;
  AV.sizeUnitLabel = (unit) => unit === 'quote' ? 'Kwota kwotowana' : unit === 'lots' ? 'Loty / kontrakty' : 'Jednostki bazowe';
  AV.tradeSizeConfig = (trade, account) => {
    const allowed = ['units', 'quote', 'lots'];
    const unit = allowed.includes(trade?.sizeUnit) ? trade.sizeUnit
      : trade ? 'units'
        : (allowed.includes(account?.sizeUnit) ? account.sizeUnit : 'units');
    const tradeContract = Number(trade?.sizeContractSize);
    const accountContract = Number(account?.contractSize);
    const contractSize = tradeContract > 0 ? tradeContract : accountContract > 0 ? accountContract : null;
    return { unit, contractSize };
  };
  AV.baseTradeSize = (amount, entry, config) => {
    const size = Number(amount), price = Number(entry);
    if (!(size > 0) || !Number.isFinite(size)) return null;
    if (config?.unit === 'quote') return price > 0 ? size / price : null;
    if (config?.unit === 'lots') return config.contractSize > 0 ? size * config.contractSize : null;
    return size;
  };
  AV.displayTradeSize = (size, entry, config) => {
    const base = Number(size), price = Number(entry);
    if (!(base > 0) || !Number.isFinite(base)) return '';
    if (config?.unit === 'quote') return price > 0 ? base * price : '';
    if (config?.unit === 'lots') return config.contractSize > 0 ? base / config.contractSize : '';
    return base;
  };
  AV.renderTradeSizeUnit = () => {
    const editTrade = AV.state.trades.find((trade) => trade.id === AV.ui.editId) || null;
    const account = AV.state.accounts.find((item) => item.name === ($('#ntAccount')?.value || '')) || null;
    const config = AV.tradeSizeConfig(editTrade, account);
    AV.setText('#ntSizeLabel', 'Wielkość · ' + AV.sizeUnitLabel(config.unit).toLowerCase());
    AV.setText('#ntSizeHint', config.unit === 'lots'
      ? (config.contractSize > 0 ? '1 lot/kontrakt = ' + AV.fmt(config.contractSize, 4) + ' jednostek bazowych.' : 'Ustaw mnożnik kontraktu w konfiguracji konta przed zapisem.')
      : config.unit === 'quote' ? 'Podana kwota jest dzielona przez cenę wejścia; transakcja zapisuje jednostki bazowe.' : 'Wartość pozycji w jednostkach instrumentu bazowego.');
    const input = $('#ntSize');
    if (input) input.placeholder = config.unit === 'quote' ? 'kwota w walucie kwotowanej' : config.unit === 'lots' ? 'liczba lotów / kontraktów' : 'liczba jednostek bazowych';
    return { account, config, editTrade };
  };

  AV.renderJournal = () => {
    const arr = AV.filteredTrades();
    AV.setHTML('#tbody', arr.map((t) => {
      const open = !AV.isTradeClosed(t);
      const live = open ? AV.tradeLivePnl(t) : null;
      const checklist = t.checklist == null || t.checklist === '' ? null : Number(t.checklist);
      const totalValue = Number(t.checkTotal);
      const checkTotal = Number.isFinite(totalValue) && totalValue > 0 ? totalValue : 7;
      const checklistClass = checklist == null ? '' : (checklist === checkTotal ? 'win' : checklist >= Math.ceil(checkTotal * 0.7) ? 'warnc' : 'loss');
      const resultCell = open
        ? (live
          ? '<span class="' + (live.value >= 0 ? 'win' : 'loss') + '">' + AV.signed(live.value, 2, '') + '</span> <span class="dim" style="font-size:11px">' + AV.signed(live.pct, 2, '%') + '</span>'
          : '<span class="al">Otwarta</span>')
        : (t.result == null ? '' : AV.signed(t.result, 2, ''));
      return '<tr data-id="' + t.id + '"><td class="mono dim">' + AV.dateTimeShort(t.date) + '</td><td><b>' + AV.esc(t.pair) + '</b>' + (t.sl === 'brak' || t.sl === '' ? '<span class="nosl">BEZ SL</span>' : '') + '</td>' +
        '<td><span class="pill ' + (AV.tradeDirection(t.dir) || 'l') + '">' + (AV.tradeDirection(t.dir) === 's' ? 'SHORT' : 'LONG') + '</span></td>' +
        '<td>' + AV.grade(t.grade) + '</td><td class="mono ' + checklistClass + '">' + (checklist == null ? '' : checklist + '/' + checkTotal) + '</td>' +
        '<td class="mono">' + AV.esc(t.entry) + '</td><td class="mono ' + (open ? 'al' : '') + '">' + AV.esc(t.exit) + '</td>' +
        '<td class="mono ' + (t.r > 0 ? 'win' : t.r < 0 ? 'loss' : 'dim') + '">' + (t.r == null ? '–' : AV.signed(t.r, 1, 'R')) + '</td>' +
        '<td class="mut">' + AV.esc(t.emotion) + '</td><td>' + ((t.errors || []).length ? '<span class="ecount" title="' + AV.attr(t.errors.join(', ')) + '">' + t.errors.length + '</span>' : '<span class="dim">–</span>') + '</td>' +
        '<td class="mono ' + (open ? (live ? (live.value >= 0 ? 'win' : 'loss') : 'al') : (t.result > 0 ? 'win' : t.result < 0 ? 'loss' : '')) + '" style="text-align:right;font-weight:800">' + resultCell + '</td>' +
        '<td style="text-align:right">' + (open ? '<span class="link" data-trade-manage="' + t.id + '" title="Zarządzaj pozycją">⋯</span>' : '') + '</td></tr>';
    }).join(''));
    AV.show('#jEmpty', !arr.length);
    if (!arr.length) {
      const empty = $('#jEmpty');
      if (empty) {
        if ((Array.isArray(AV.state.trades) ? AV.state.trades.length : 0) > 0) {
          empty.textContent = 'Brak transakcji spełniających filtry.';
        } else {
          empty.innerHTML = '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap"><button class="btn p sm" type="button" data-journal-add>Dodaj transakcję</button><button class="btn g sm" type="button" data-journal-exchanges>Giełdy i API</button></div>';
        }
      }
    }
    AV.$$('#fRes button').forEach((b) => b.classList.toggle('on', b.dataset.f === AV.ui.jFilter));
    AV.$$('#fGrade button').forEach((b) => b.classList.toggle('on', b.dataset.f === AV.ui.jGrade));
    const count = $('.navg button[data-v="trans"] .badge');
    if (count) count.textContent = Array.isArray(AV.state.trades) ? AV.state.trades.length : 0;
  };

  /* ---------- wykresy ---------- */
  AV.chart = (seed, dir, after, label) => {
    let s = seed;
    const r = () => (s = (s * 9301 + 49297) % 233280) / 233280;
    let p = 100, h = '';
    const n = 34, w = 600 / n, cut = after ? n : 22, lv = [];
    for (let i = 0; i < n; i++) {
      const dr = i > 22 ? (dir === 'up' ? 1 : -1) * 1.1 : (i < 12 ? 0.25 : -0.15);
      const o = p, c = o + dr + (r() - 0.5) * 3, hi = Math.max(o, c) + r() * 1.6, lo = Math.min(o, c) - r() * 1.6;
      p = c; lv.push([o, c, hi, lo]);
    }
    const all = lv.flat(), mx = Math.max(...all), mn = Math.min(...all), y = (v) => 16 + (mx - v) / (mx - mn) * 196;
    lv.forEach(([o, c, hi, lo], i) => {
      if (i >= cut) return;
      const x = i * w + w / 2, col = c >= o ? '#4fcf8a' : '#ff5d62';
      h += '<line x1="' + x + '" x2="' + x + '" y1="' + y(hi) + '" y2="' + y(lo) + '" stroke="' + col + '" stroke-width="1.4"/><rect x="' + (x - w * 0.32) + '" y="' + Math.min(y(o), y(c)) + '" width="' + (w * 0.64) + '" height="' + Math.max(2, Math.abs(y(o) - y(c))) + '" fill="' + col + '" rx="1.5"/>';
    });
    const ey = y(lv[22][0]), dv = dir === 'up' ? 1 : -1;
    const line = (yy, c, t) => '<line x1="0" x2="600" y1="' + yy + '" y2="' + yy + '" stroke="' + c + '" stroke-dasharray="4 5" opacity=".85"/><text x="10" y="' + (yy - 6) + '" fill="' + c + '" font-size="10.5" font-family="JetBrains Mono" letter-spacing="1">' + t + '</text>';
    h += line(ey, getComputedStyle(document.body).getPropertyValue('--alpha2'), 'ENTRY') + line(ey + 34 * dv, '#ff5d62', 'SL') + line(ey - 60 * dv, '#4fcf8a', 'TP');
    if (!after) h += '<rect x="' + (22 * w) + '" y="0" width="' + (600 - 22 * w) + '" height="230" fill="rgba(var(--a),.035)"/>';
    return '<div class="shot"><span class="cap">' + AV.esc(label) + '</span><svg viewBox="0 0 600 230" width="100%" height="230" style="display:block">' + h + '</svg></div>';
  };

  /* ---------- szczegóły transakcji ---------- */
  AV.openTrade = (id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    if (!t) return;
    const box = (l, v, c = '') => '<div class="card flat" style="padding:14px"><div class="lbl">' + l + '</div><div class="mono ' + c + '" style="font-weight:700;margin-top:6px">' + v + '</div></div>';
    const drop = (label, icon, key) => {
      const data = t.screens && t.screens[key];
      return '<label class="drop ' + (data ? 'has' : '') + '" data-drop data-shot="' + key + '" style="min-height:180px"><input type="file" accept="image/*" hidden><span class="x">Usuń ✕</span>' + (data ? '' : '<div class="ic">' + AV.ic(icon) + '</div><b>' + label + '</b><span>kliknij, przeciągnij albo Ctrl+V</span>') + '</label>';
    };
    const open = !AV.isTradeClosed(t);
    const live = open ? AV.tradeLivePnl(t) : null;
    const tradeAccount = AV.tradeAccount(AV.state, t);
    const sizeConfig = AV.tradeSizeConfig(t, tradeAccount);
    const displayedSize = AV.displayTradeSize(open ? t.size : (t.initialSize || t.size), t.entry, sizeConfig);
    const sizeUnit = AV.sizeUnitLabel(sizeConfig.unit).toLowerCase();
    const liveHead = open
      ? (live
        ? '<span class="mono ' + (live.value >= 0 ? 'win' : 'loss') + '" style="margin-left:auto;text-align:right;font-weight:800"><span style="font-size:28px">' + AV.signed(live.value, 2, ' USDT') + '</span><span style="display:block;font-size:12px;opacity:.8">' + AV.signed(live.pct, 2, '%') + ' · live</span></span>'
        : '<span class="mono al" style="margin-left:auto;font-size:28px;font-weight:800">otwarta</span>')
      : '<span class="mono ' + (t.result > 0 ? 'win' : t.result < 0 ? 'loss' : 'al') + '" style="margin-left:auto;font-size:28px;font-weight:800">' + (t.result == null ? '' : AV.signed(t.result, 2, ' USDT')) + '</span>';
    AV.ui.openTradeId = id;
    const checklist = t.checklist == null || t.checklist === '' ? null : Number(t.checklist);
    const totalValue = Number(t.checkTotal);
    const checkTotal = Number.isFinite(totalValue) && totalValue > 0 ? totalValue : 7;
    const checklistClass = checklist == null ? '' : (checklist === checkTotal ? 'win' : checklist >= Math.ceil(checkTotal * 0.7) ? 'warnc' : 'loss');
    const metricValue = (value) => value == null || (typeof value === 'string' && value.trim() === '') ? '' : AV.fmt(value, 1) + 'R';
    AV.setHTML('#tmBody', '<span class="mclose" id="mc">' + AV.ic('x', 'width:16px;height:16px') + '</span><div class="eyebrow">Transakcja · ' + AV.dateTimeShort(t.date) + ' · ' + AV.esc(t.source || 'WEEX') + '</div>' +
      '<div style="display:flex;align-items:center;gap:12px;margin-top:8px;flex-wrap:wrap"><h1 style="font-size:32px;margin:0">' + AV.esc(t.pair) + '</h1><span class="pill ' + (AV.tradeDirection(t.dir) || 'l') + '">' + (AV.tradeDirection(t.dir) === 's' ? 'SHORT' : 'LONG') + '</span>' + AV.grade(t.grade) + (t.sl === 'brak' ? '<span class="nosl" style="margin:0">BEZ SL</span>' : '') + liveHead + '</div>' +
      '<div class="grid" style="grid-template-columns:repeat(4,1fr);margin-top:20px;gap:10px">' + box('Wejście', AV.esc(t.entry)) + box('Wyjście', AV.esc(t.exit)) + box('Wielkość · ' + sizeUnit, displayedSize === '' ? '' : AV.fmt(displayedSize, 4)) + box('Stop loss', AV.esc(t.sl), t.sl === 'brak' ? 'loss' : '') + box('Take profit', AV.esc(t.tp)) + box('MAE', metricValue(t.mae), 'loss') + box('MFE', metricValue(t.mfe), 'win') + '</div>' +
      ((t.partials || []).length ? '<div class="card flat" style="margin-top:12px;padding:14px"><div class="lbl">Zrealizowane części pozycji</div>' + t.partials.map((p) => '<div style="display:flex;justify-content:space-between;gap:10px;margin-top:8px;font-size:13px"><span class="mono">' + AV.esc(p.price) + ' · ' + AV.fmt(p.pct, 0) + '%</span><b class="mono ' + (p.result >= 0 ? 'win' : 'loss') + '">' + AV.signed(p.result, 2, ' USDT') + '</b></div>').join('') + (t.be ? '<div class="hint" style="margin-top:10px">SL przeniesiony na wejście (BE).</div>' : '') + '</div>' : '') +
      '<div class="sech" style="margin:18px 0 10px"><h3>Twoje screeny</h3><span class="dim" style="font-size:12.5px">opcjonalnie, jeśli chcesz zapisać swój własny widok</span></div>' +
      '<div class="grid g2">' + (t.screens && t.screens.before ? '<label class="drop has" data-drop data-shot="before"><input type="file" accept="image/*" hidden><span class="x">Usuń ✕</span><img src="' + t.screens.before + '" alt="Screen przed wejściem"></label>' : drop('Dodaj screen PRZED wejściem', 'img', 'before')) + (t.screens && t.screens.after ? '<label class="drop has" data-drop data-shot="after"><input type="file" accept="image/*" hidden><span class="x">Usuń ✕</span><img src="' + t.screens.after + '" alt="Screen po wyjściu"></label>' : drop('Dodaj screen PO wyjściu', 'flag', 'after')) + '</div>' +
       ((t.errors || []).length ? '<div class="card flat" style="margin-top:16px;padding:16px"><div class="lbl">Błędy w tej transakcji</div><div style="margin-top:8px">' + t.errors.map((e) => '<span class="etag">' + AV.esc(e) + '</span>').join('') + '</div></div>' : '') +
       '<div class="grid" style="grid-template-columns:1fr 1fr 1.2fr;margin-top:16px"><div class="card flat"><div class="lbl">Twoja notatka</div><p style="margin-top:8px;line-height:1.55;font-size:14px">' + AV.esc(t.note) + '</p><div class="chips" style="margin-top:12px">' + (t.emotion ? '<span class="chip on">' + AV.esc(t.emotion) + '</span>' : '') + (t.setup ? '<span class="chip">' + AV.esc(t.setup) + '</span>' : '') + '</div></div>' +
       '<div class="card flat"><div class="lbl">Checklista</div>' + (checklist == null ? '<div class="val"></div>' : '<div class="val ' + checklistClass + '">' + checklist + ' / ' + checkTotal + '</div><div class="bar"><i style="width:' + checklist / checkTotal * 100 + '%;background:' + (checklistClass === 'win' ? 'var(--win)' : checklistClass === 'warnc' ? 'var(--warn)' : 'var(--loss)') + '"></i></div>') + '</div>' +
      '<div class="card hl"><div class="lbl">α Mentor o tej transakcji</div><p style="margin-top:8px;line-height:1.55;font-size:14px">' + AV.esc(t.mentor) + '</p></div></div>' +
       '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap">' + (open ? '<button class="btn g" data-trade-close="' + t.id + '">Zamknij / częściowo</button><button class="btn g" data-trade-sl="' + t.id + '">Ustaw / zmień SL</button><button class="btn g" data-trade-be="' + t.id + '">Przenieś SL na BE</button>' : '') + '<button class="btn g" data-trade-edit="' + t.id + '">Edytuj</button><button class="btn w" data-trade-del="' + t.id + '">Usuń transakcję</button></div>');
    $('#tm').classList.add('on');
    AV.bindDrops($('#tmBody'), (data, key) => {
      if (!t.screens) t.screens = {};
      if (data) t.screens[key] = data; else delete t.screens[key];
      AV.saveNow();
      AV.openTrade(t.id);
      AV.toast(data ? 'Screen zapisany' : 'Screen usunięty');
    });
  };

  /* ---------- playbook ---------- */
  AV.renderPlaybook = () => {
    const s = AV.state;
    $('#pbList').innerHTML = s.setups.map((x) => {
      const st = AV.setupStats(s, x.name);
      const net = st.net;
      const up = net >= 0;
      return '<div class="card setup ' + (x.bad ? 'bad' : '') + '"><div class="top2"><div><div class="lbl">' + AV.esc(x.tf) + '</div><h3 style="margin-top:6px">' + AV.esc(x.name) + '</h3></div>' + AV.grade(x.grade) + '</div>' +
        '<div style="margin-top:12px;border-radius:12px;overflow:hidden;border:1px solid var(--line)">' + AV.chart(x.seed || 17, up ? 'up' : 'down', true, 'NAJLEPSZY PRZYKŁAD').replace('height="230"', 'height="120"') + '</div>' +
        '<ul>' + (x.rules || []).map((q) => '<li>' + AV.esc(q) + '</li>').join('') + '</ul>' +
        '<div class="st3"><div><div class="lbl">Transakcje</div><b>' + st.n + '</b></div><div><div class="lbl">Skuteczność</div><b class="' + (st.wr >= 50 ? 'win' : 'loss') + '">' + Math.round(st.wr) + '%</b></div><div><div class="lbl">Śr. wynik</div><b class="' + (up ? 'win' : 'loss') + '">' + AV.signed(st.avgR, 1, 'R') + '</b></div></div>' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:8px;flex-wrap:wrap"><b class="mono ' + (up ? 'win' : 'loss') + '">' + AV.dispMoney(net, 2) + '</b>' +
        (x.bad ? '<span class="tag alert">nie graj tego</span>' : '<span class="link" style="font-size:12.5px" data-setup-trades="' + AV.attr(x.name) + '">Transakcje →</span>') +
        '<span class="link" style="font-size:12px" data-setup-edit="' + x.id + '">Edytuj</span></div></div>';
    }).join('') + '<div class="card" style="border-style:dashed;display:grid;place-items:center;text-align:center;cursor:pointer;min-height:300px" id="addSetup2"><div><div style="width:48px;height:48px;border-radius:14px;margin:0 auto 12px;display:grid;place-items:center;background:var(--alphaS);color:var(--alpha)">' + AV.ic('plus') + '</div><b>Dodaj setup</b><div class="hint">nazwa, zasady, interwał, przykład</div></div></div>';
  };

  AV.openSetupForm = (id) => {
    const s = AV.state;
    const x = id ? s.setups.find((y) => y.id === id) : null;
    const body = AV.openModal('<div class="eyebrow">Playbook</div><h1 style="font-size:28px;margin-top:6px">' + (x ? 'Edytuj setup' : 'Nowy setup') + '</h1>' +
      '<div class="grid g2" style="margin-top:16px"><div><span class="label">Nazwa</span><input class="input" id="suName" value="' + AV.attr(x?.name || '') + '" placeholder="np. Retest wybicia"></div><div><span class="label">Interwał</span><input class="input" id="suTf" value="' + AV.attr(x?.tf || 'M15 · H1') + '" placeholder="M15 · H1"></div></div>' +
      '<span class="label">Ocena jakości</span><div class="chips sel" id="suGrade"><span class="chip ' + (!x || x.grade === 'A' ? 'on' : '') + '" data-g="A">A</span><span class="chip ' + (x?.grade === 'B' ? 'on' : '') + '" data-g="B">B</span><span class="chip ' + (x?.grade === 'C' ? 'on' : '') + '" data-g="C">C</span></div>' +
      '<span class="label">3 zasady wejścia</span>' +
      [0, 1, 2].map((i) => '<input class="input" id="suRule' + i + '" value="' + AV.attr(x?.rules?.[i] || '') + '" placeholder="Zasada ' + (i + 1) + '" style="margin-bottom:8px">').join('') +
      '<label class="check" style="margin-top:10px"><span class="box" id="suBadBox"></span><span>Oznacz jako setup, którego nie grasz</span></label>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="suSave">Zapisz setup</button></div>', { maxWidth: '620px' });
    let bad = !!x?.bad;
    const box = $('#suBadBox', body);
    box.classList.toggle('on', bad);
    box.parentElement.onclick = () => { bad = !bad; box.classList.toggle('on', bad); };
    $('#suGrade', body).onclick = (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#suGrade .chip', body).forEach((z) => z.classList.toggle('on', z === c));
    };
    $('#suSave', body).onclick = () => {
      const name = $('#suName', body).value.trim();
      const rules = [0, 1, 2].map((i) => $('#suRule' + i, body).value.trim()).filter(Boolean);
      if (!name) { AV.toast('Podaj nazwę setupu'); return; }
      if (rules.length < 2) { AV.toast('Dodaj co najmniej 2 zasady'); return; }
      const grade = $('#suGrade .chip.on', body)?.dataset.g || 'B';
      const payload = { name, tf: $('#suTf', body).value.trim() || '—', grade, rules, bad, seed: Math.floor(Math.random() * 200) + 1 };
      if (x) Object.assign(x, payload);
      else s.setups.push({ id: AV.uid('pb'), ...payload });
      AV.saveNow(); AV.closeModal(); AV.renderPlaybook(); AV.toast(x ? 'Setup zapisany' : 'Setup dodany');
      if (x) AV.renderAll();
    };
  };

  /* ---------- checklista formularza ---------- */
  AV.ui.ntGradeManual = false;
  AV.checklistPoints = (state = AV.state, historical = []) => {
    const configured = Array.isArray(state?.settings?.checklist) ? state.settings.checklist : [];
    const defaults = ['Trend wyższego TF', 'Poziom z planu', 'Potwierdzenie', 'SL przed wejściem', 'R:R zgodne z planem', 'Brak makro 15 min', 'Spokojna głowa'];
    const points = (configured.length ? configured : defaults).slice(0, 8);
    for (const item of Array.isArray(historical) ? historical : []) {
      const label = String(item ?? '');
      if (label.trim() && !points.includes(label) && points.length < 20) points.push(label);
    }
    return points;
  };
  AV.renderChecklist = () => {
    const s = AV.state;
    const editing = AV.ui.editId ? s.trades.find((trade) => trade.id === AV.ui.editId) : null;
    const historicalItems = Array.isArray(editing?.checklistItems) ? editing.checklistItems : [];
    const points = AV.checklistPoints(s, historicalItems);
    AV.ui.ntChecks = AV.ui.ntChecks || {};
    points.forEach((p) => {
      if (AV.ui.ntChecks[p] == null) AV.ui.ntChecks[p] = false;
    });
    const preserveHistory = !!editing && !AV.ui.ntChecklistTouched;
    const historicalCount = editing?.checklist == null || editing.checklist === ''
      ? null
      : (Number.isFinite(Number(editing.checklist)) ? Number(editing.checklist) : null);
    const historicalTotal = Number(editing?.checkTotal) > 0 ? Number(editing.checkTotal) : 7;
    const historyUnknown = preserveHistory && (!Array.isArray(editing?.checklistItems) || historicalCount === null);
    const n = preserveHistory && historicalCount !== null
      ? historicalCount
      : points.filter((p) => AV.ui.ntChecks[p]).length;
    const total = preserveHistory && historicalTotal !== null ? historicalTotal : points.length;
    const col = n === total ? 'var(--win)' : n >= Math.ceil(total * 0.7) ? 'var(--warn)' : 'var(--loss)';
    const ring = $('#clRing');
    if (ring) {
      ring.style.setProperty('--p', n / Math.max(1, total) * 100);
      ring.style.setProperty('--c', col);
      ring.style.visibility = historyUnknown ? 'hidden' : 'visible';
    }
    AV.setText('#clN', historyUnknown && historicalCount === null
      ? ''
      : historyUnknown && historicalTotal === null
        ? String(historicalCount)
        : n + '/' + total);
    if ($('#clN')) $('#clN').style.color = col;
    const w = $('#clWarn');
    if (w) {
      w.style.display = 'block';
      w.className = 'warnbox y';
      w.textContent = historyUnknown
        ? 'Wpis historyczny nie zapisał konkretnych zaznaczeń' +
          (historicalCount !== null && historicalTotal !== null ? ' (wynik ' + historicalCount + '/' + historicalTotal + ')' : '') +
          '. Zapis bez zmiany checklisty zachowa wynik; zaznacz punkt, aby zapisać nowy wybór.'
        : preserveHistory
          ? 'Edycja zachowuje historyczny wynik checklisty. Zaznacz punkt, aby zapisać nowy wybór.'
          : 'Checklista jest dobrowolna; zapis nie wymaga minimum. Zaznaczono ' + n + ' z ' + total + ' punktów.';
    }
    AV.setHTML('#clList', points.map((p) => {
      const on = !!AV.ui.ntChecks[p];
      return '<div class="cl ' + (on ? 'on' : '') + '" data-cl="' + AV.attr(p) + '"><span class="box ' + (on ? 'on' : '') + '">' + AV.ic('check') + '</span><div class="t">' + AV.esc(p) + '<small>' + AV.esc(AV.checklistHint(p)) + '</small></div></div>';
    }).join(''));
    const g = n === total ? 'A' : n >= Math.ceil(total * 0.7) ? 'B' : 'C';
    const sug = $('#sugg');
    if (sug) sug.textContent = n === 0 && !AV.ui.ntGradeManual ? 'SUGEROWANA: —' : 'SUGEROWANA: ' + g;
    if (!AV.ui.ntGradeManual) {
      if (n === 0) $$('.gp').forEach((x) => x.classList.remove('on'));
      else $$('.gp').forEach((x) => x.classList.toggle('on', x.dataset.g === g));
    }
  };

  AV.checklistHint = (p) => {
    const m = {
      'Trend wyższego TF': 'D1 / H4', 'Poziom z planu': 'cena z planu dnia', 'Potwierdzenie': 'zamknięcie świecy albo retest',
      'SL przed wejściem': 'stop loss ustawiony', 'R:R zgodne z planem': 'oceń relację do tego setupu', 'R:R ≥ 2': 'oceń relację do tego setupu', 'Brak makro 15 min': 'sprawdź kalendarz',
      'Spokojna głowa': 'check-in i gotowość'
    };
    return m[p] || 'Twój punkt';
  };

  AV.autoChecklist = (points) => {
    const en = AV.pnum($('#ntEntry')?.value), sl = AV.pnum($('#ntSl')?.value), tp = AV.pnum($('#ntTp')?.value);
    const dir = $('#ntDir .chip.on')?.dataset.d || 'l';
    if (points.includes('SL przed wejściem')) AV.ui.ntChecks['SL przed wejściem'] = !!sl && (AV.tradeDirection(dir) === 's' ? sl > en : sl < en);
    if (points.includes('R:R ≥ 2')) {
      const risk = Math.abs(en - sl), reward = Math.abs(tp - en);
      AV.ui.ntChecks['R:R ≥ 2'] = !!(risk && reward && reward / risk >= 2);
    }
    if (points.includes('Poziom z planu')) {
      const p = (AV.state.plans || {})[AV.dateKey(new Date())];
      const near = (p?.levels || []).some((l) => {
        const x = AV.pnum(l.price);
        return x && en && Math.abs(x - en) / en < 0.003;
      });
      AV.ui.ntChecks['Poziom z planu'] = near;
    }
  };

  AV.renderNTMeta = () => {
    const en = AV.pnum($('#ntEntry')?.value), sl = AV.pnum($('#ntSl')?.value), tp = AV.pnum($('#ntTp')?.value);
    const size = AV.pnum($('#ntSize')?.value);
    const unit = AV.renderTradeSizeUnit();
    const baseSize = AV.baseTradeSize(size, en, unit.config);
    const cap = AV.totalCapital(AV.state);
    const limitPct = Number(AV.state.settings.maxRisk) || 1;
    const limitAmt = cap > 0 ? cap * limitPct / 100 : null;
    const riskAmt = en && sl && baseSize ? Math.abs(en - sl) * baseSize : null;
    const riskPct = riskAmt != null && cap > 0 ? riskAmt / cap * 100 : null;
    const slPct = en && sl ? Math.abs(en - sl) / en : 0;
    const rr = slPct && tp ? Math.abs(tp - en) / Math.abs(en - sl) : 0;
    const over = riskPct != null && riskPct > limitPct + 1e-9;
    const el = $('#ntRisk');
    if (el) el.innerHTML = 'Limit: <b>' + (limitAmt == null ? '—' : AV.fmt(limitAmt, 2) + ' ' + AV.curLabel() + ' (' + AV.fmt(limitPct, 2) + '%)') + '</b>' +
      ' · Ryzyko: <b class="' + (over ? 'loss' : 'win') + '">' + (riskAmt == null ? '—' : AV.fmt(riskAmt, 2) + ' ' + AV.curLabel() + ' (' + (riskPct == null ? '—' : AV.fmt(riskPct, 2) + '%') + ')') + '</b>' +
      ' · R:R <b>' + (rr ? '1 : ' + AV.fmt(rr, 1) : '—') + '</b> · <span class="link" data-go="kalk" style="font-size:13.5px">Kalkulator Alpha</span>';
  };

  AV.renderTradeSelectors = () => {
    const acc = $('#ntAccount');
    if (acc) {
      const current = acc.value;
      acc.innerHTML = (AV.state.accounts || []).map((a) => '<option value="' + AV.attr(a.name) + '">' + AV.esc(a.name) + ' · ' + AV.esc(a.currency || 'USDT') + '</option>').join('');
      const fallback = (AV.state.accounts || []).find((a) => a.include)?.name || (AV.state.accounts || [])[0]?.name || '';
      acc.value = (AV.state.accounts || []).some((a) => a.name === current) ? current : fallback;
      acc.disabled = (AV.state.accounts || []).length === 0;
    }
    AV.renderTradeSizeUnit();
  };

  AV.renderNewTradeForm = () => {
    const d = AV.getDraft();
    AV.renderTradeSelectors();
    const executionLocked = !!AV.ui.editId && !!AV.ui.ntExecutionLocked;
    AV.setVal('#ntPair', d.pair || '');
    AV.setVal('#ntEntry', d.entry || '');
    AV.setVal('#ntSize', d.size || '');
    AV.setVal('#ntSl', d.sl || '');
    AV.setVal('#ntTp', d.tp || '');
    ['#ntPair', '#ntEntry', '#ntSize', '#ntSl', '#ntTp'].forEach((selector) => {
      const input = $(selector);
      if (input) input.disabled = executionLocked;
    });
    const direction = $('#ntDir');
    direction?.classList.toggle('disabled', executionLocked);
    if (direction) {
      direction.setAttribute('aria-disabled', executionLocked ? 'true' : 'false');
      direction.style.pointerEvents = executionLocked ? 'none' : '';
    }
    AV.setVal('#ntNote', d.note || '');
    $$('#ntDir .chip').forEach((c) => {
      c.classList.toggle('on', c.dataset.d === (AV.tradeDirection(d.dir) || 'l'));
      c.setAttribute('aria-disabled', executionLocked ? 'true' : 'false');
    });
    $$('#ntSetup .chip').forEach((c) => c.classList.toggle('on', c.dataset.s === (d.setup || 'Bez setupu')));
    $$('#ntEmotion .chip').forEach((c) => c.classList.toggle('on', c.textContent === (d.emotion || 'Spokój')));
    $$('#errChips .chip.err').forEach((c) => c.classList.toggle('on', (d.errors || []).includes(c.textContent)));
    AV.ui.ntChecks = d.checks || {};
    AV.ui.ntGradeManual = !!d.gradeManual;
    $$('.gp').forEach((g) => g.classList.toggle('on', d.grade ? g.dataset.g === d.grade : false));
    AV.renderChecklist();
    AV.renderNTMeta();
    AV.renderDrops(d.screens || {});
    AV.show('#cancelEdit', !!AV.ui.editId);
    AV.setText('#saveTrade', AV.ui.editId ? 'Zapisz zmiany' : 'Zapisz transakcję');
  };

  AV.getDraft = () => {
    try {
      const d = JSON.parse(AV.safeGet(AV.draftKey()) || '{}');
      const demoDraft = d && d.pair === 'BTC/USDT' && d.entry === '69 120' && d.size === '0,01' &&
        d.sl === '68 500' && d.tp === '70 800' && String(d.note || '').startsWith('Retest 68 500 z planu');
      if (demoDraft) { AV.safeDel(AV.draftKey()); return {}; }
      return d;
    } catch { return {}; }
  };
  AV.saveDraft = () => {
    if (AV.ui.editId) return;
    const d = {
      pair: $('#ntPair')?.value, entry: $('#ntEntry')?.value, size: $('#ntSize')?.value,
      sl: $('#ntSl')?.value, tp: $('#ntTp')?.value, note: $('#ntNote')?.value,
      dir: $('#ntDir .chip.on')?.dataset.d || 'l', setup: $('#ntSetup .chip.on')?.dataset.s || 'Bez setupu',
      emotion: $('#ntEmotion .chip.on')?.textContent || 'Spokój',
      errors: $$('#errChips .chip.err.on').map((c) => c.textContent),
      checks: AV.ui.ntChecks || {}, grade: $('.gp.on')?.dataset.g || '',
      gradeManual: AV.ui.ntGradeManual, screens: AV.ui.ntScreens || {}
    };
    AV.safeSet(AV.draftKey(), JSON.stringify(d));
  };

  AV.renderDrops = (screens) => {
    AV.ui.ntScreens = screens || {};
    $$('#nowa [data-drop]').forEach((d, i) => {
      const key = i === 0 ? 'before' : 'after';
      const img = d.querySelector('img');
      if (img) img.remove();
      d.classList.remove('has');
      if (AV.ui.ntScreens[key]) AV.showImage(d, AV.ui.ntScreens[key]);
    });
  };

  /* ---------- wybór planu ---------- */
  AV.setPlan = (solo, silent) => {
    const s = AV.state;
    s.subscription.plan = solo ? 'solo' : 'full';
    AV.saveNow();
    AV.show('#akUp', solo);
    AV.show('#akFull', !solo);
    $$('.strip').forEach((e) => e.style.display = solo ? 'none' : 'flex');
    if (AV.renderNavBadges) AV.renderNavBadges();
    AV.renderUser();
    if (!silent) AV.toast(solo ? 'Podgląd: plan Alpha Vault bez Akademii' : 'Plan Akademia Alpha + Vault');
  };
})();
