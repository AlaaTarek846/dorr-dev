/*
 * Wallet app for the design preview — one self-contained full-screen "app" that
 * opens over the phone frame (home balance, top-up, transfer, statement, PIN).
 * Unlike the rest of this preview it talks to the REAL backend: the same
 * /api/mobile/v1/wallet endpoints the Android app uses.
 *
 * Loaded after app.js; relies on its globals ($, $$, escapeHtml, apiHeaders,
 * authToken). Entry points: window.openWalletApp(view), window.walletOnLogin/Logout.
 *
 * Money is an integer in minor units end to end. It is only turned into text by
 * fmt() with integer maths (no floats), and typed amounts are parsed with a
 * strict regex that rejects a 3rd decimal instead of silently rounding.
 */
(() => {
  'use strict';

  const API = '/api/mobile/v1/wallet';
  const PIN_CODES = ['wallet_pin_invalid', 'wallet_pin_locked', 'wallet_pin_required', 'wallet_pin_not_set'];
  const NETWORK_MSG = 'تعذّر الوصول إلى الخادم. تحقق من الاتصال وحاول مرة أخرى.';
  const NO_AUTH_MSG = 'سجّل الدخول برقم حقيقي لاستخدام المحفظة (أنت الآن في وضع العرض بدون اتصال).';

  const state = { balance: null, shown: 0, hide: localStorage.getItem('wa_hide') === '1' };

  // ===================================================================== icons

  const ICON_PATHS = {
    plus: '<path d="M12 5v14M5 12h14"/>',
    send: '<path d="M7 17L17 7M8 7h9v9"/>',
    'arrow-in': '<path d="M17 7L7 17M16 17H7V8"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7M3 4v4h4M12 8v5l3 2"/>',
    lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3M12 15v2"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off': '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path d="M4 4l16 16"/>',
    wallet: '<rect x="3" y="7" width="18" height="12" rx="2.4"/><path d="M3 11h18M16 14.5h2.4M6.5 7V5.8A1.8 1.8 0 0 1 8.3 4h8.2"/>',
    gift: '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8M12 8c-2-4-6-4-6-1.5S10 8 12 8zM12 8c2-4 6-4 6-1.5S14 8 12 8z"/>',
    receipt: '<path d="M5 3h14v18l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21z"/><path d="M8 8h8M8 12h8"/>',
    bank: '<path d="M3 10l9-6 9 6M5 10v8M9 10v8M15 10v8M19 10v8M3 20h18"/>',
    card: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>',
    phone: '<path d="M7 3.5h2.2l1.2 3-1.7 1a11 11 0 0 0 5.8 5.8l1-1.7 3 1.2V15a2 2 0 0 1-2.2 2A13.5 13.5 0 0 1 5 7.7 2 2 0 0 1 7 3.5z"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
    chevron: '<path d="M9 6l6 6-6 6"/>',
    back: '<path d="M15 6l-6 6 6 6"/>',
    backspace: '<path d="M21 5H9l-6 7 6 7h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1zM12 9l5 6M17 9l-5 6"/>',
    refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>',
    sparkle: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l.8 2.2 2.2.8-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>',
    shield: '<path d="M12 2l8 3v6c0 5-3.4 8.7-8 11-4.6-2.3-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/>',
    alert: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
    undo: '<path d="M9 14L4 9l5-5M4 9h10a6 6 0 0 1 0 12h-3"/>',
    bag: '<path d="M6 8h12l1 12H5zM9 8V6a3 3 0 0 1 6 0v2"/>',
    sliders: '<path d="M4 7h10M18 7h2M4 17h2M10 17h10M14 5v4M8 15v4"/>',
    flask: '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4A2 2 0 0 0 19 18l-5-9V3M7.5 15h9"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
    pause: '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
    external: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    list: '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
    qr: '<path d="M4 9V6a2 2 0 0 1 2-2h3M15 4h3a2 2 0 0 1 2 2v3M20 15v3a2 2 0 0 1-2 2h-3M9 20H6a2 2 0 0 1-2-2v-3"/><rect x="8" y="8" width="3" height="3" rx=".5"/><rect x="13" y="8" width="3" height="3" rx=".5"/><rect x="8" y="13" width="3" height="3" rx=".5"/><path d="M13.5 13.5h2.5v2.5"/>',
    camera: '<path d="M4 8h3l1.6-2.4h6.8L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.6"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="1.7"/><path d="M21 16l-5-5-8 9"/>',
    download: '<path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14"/>',
    share: '<circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="M8.2 10.8l7.6-4M8.2 13.2l7.6 4"/>',
  };

  function injectSprite() {
    if ($('#wa-sprite')) return;
    document.body.insertAdjacentHTML('beforeend',
      `<svg id="wa-sprite" xmlns="http://www.w3.org/2000/svg" style="display:none">${Object.entries(ICON_PATHS)
        .map(([name, path]) => `<symbol id="wa-${name}" viewBox="0 0 24 24">${path}</symbol>`).join('')}</svg>`);
  }

  const icon = (name, cls = '') => `<svg class="wa-i ${cls}" viewBox="0 0 24 24" aria-hidden="true"><use href="#wa-${name}"/></svg>`;

  // What each ledger type looks like in lists (icon + tint).
  const TX_META = {
    topup: ['plus', 'green'], topup_fee: ['receipt', 'amber'], topup_bonus: ['gift', 'pink'],
    transfer_out: ['send', 'red'], transfer_in: ['arrow-in', 'green'], withdrawal: ['bank', 'blue'],
    refund: ['undo', 'blue'], penalty: ['alert', 'red'], service_payment: ['bag', 'red'],
    service_earning: ['bag', 'green'], manual_adjustment: ['sliders', 'gray'], reversal: ['undo', 'gray'],
  };
  const txMeta = (type) => TX_META[type] || ['wallet', 'gray'];

  // ==================================================================== helpers

  function uuid() {
    // crypto.randomUUID needs a secure context; dorr.test is plain http.
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  }

  function fmt(minor) {
    const abs = Math.abs(minor);
    const whole = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${minor < 0 ? '-' : ''}${whole}.${String(abs % 100).padStart(2, '0')}`;
  }

  function parseAmount(text) {
    const t = text.trim().replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(t)) return null;
    const [whole, frac = ''] = t.split('.');
    const minor = Number(whole) * 100 + Number(frac.padEnd(2, '0'));
    return minor > 0 && Number.isSafeInteger(minor) ? minor : null;
  }

  async function api(path, { method = 'GET', body, pin, idem } = {}) {
    if (!authToken) return { status: 0, noAuth: true, json: null };
    const headers = { ...apiHeaders, Authorization: `Bearer ${authToken}` };
    if (body) headers['Content-Type'] = 'application/json';
    if (pin) headers['X-Wallet-Pin'] = pin;
    if (idem) headers['Idempotency-Key'] = idem;
    try {
      const res = await fetch(API + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
      return { status: res.status, json: await res.json().catch(() => null) };
    } catch {
      return { status: 0, json: null };
    }
  }

  const ok = (r) => r.status >= 200 && r.status < 300;

  function errMsg(r) {
    if (r.noAuth) return NO_AUTH_MSG;
    if (r.status === 0) return NETWORK_MSG;
    const first = r.json?.errors ? Object.values(r.json.errors)[0]?.[0] : null;
    return first || r.json?.message || 'حدث خطأ. حاول مرة أخرى.';
  }

  const cur = () => escapeHtml(state.balance?.currency_code || '');
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const area = document.createElement('textarea');
      area.value = text;
      area.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(area);
      area.select();
      document.execCommand('copy');
      area.remove();
    }
  }
  const timeOf = (iso) => { try { return new Date(iso).toLocaleTimeString('ar', { hour: '2-digit', minute: '2-digit' }); } catch { return ''; } };

  function dayLabel(iso) {
    const d = new Date(iso);
    const start = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const diff = Math.round((start(new Date()) - start(d)) / 86400000);
    if (diff === 0) return 'اليوم';
    if (diff === 1) return 'أمس';
    try { return d.toLocaleDateString('ar', { day: 'numeric', month: 'long', year: 'numeric' }); } catch { return iso; }
  }

  function countUp(el, from, to, ms = 800) {
    if (!el) return;
    if (state.hide) { el.textContent = '******'; return; }
    if (from === to || matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = fmt(to); return; }
    const t0 = performance.now();
    const tick = (now) => {
      const p = Math.min(1, (now - t0) / ms);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(Math.round(from + (to - from) * eased));
      if (p < 1 && el.isConnected) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  // ====================================================================== shell

  let root; let stage; let sheetBd; let sheetEl; let layer; let toastEl; let toastTimer; let sheetDispose;
  const stack = [];

  function ensureShell() {
    if (root) return;
    injectSprite();
    root = document.createElement('div');
    root.className = 'wa';
    root.innerHTML = `
      <div class="wa-stage" style="position:absolute;inset:0"></div>
      <div class="wa-layer"></div>
      <div class="wa-sheet-bd"><div class="wa-sheet"></div></div>
      <div class="wa-toast"></div>`;
    $('.screen').appendChild(root);
    stage = $('.wa-stage', root);
    layer = $('.wa-layer', root);
    sheetBd = $('.wa-sheet-bd', root);
    sheetEl = $('.wa-sheet', root);
    toastEl = $('.wa-toast', root);
    sheetBd.addEventListener('click', (e) => { if (e.target === sheetBd) closeSheet(); });
  }

  function toast(message, ic = 'check') {
    toastEl.innerHTML = `${icon(ic, 'sm')}<span>${escapeHtml(message)}</span>`;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2200);
  }

  function push(build) {
    const page = document.createElement('section');
    page.className = 'wa-page';
    const entry = { el: page };
    Object.assign(entry, build(page) || {});
    stack[stack.length - 1]?.el.classList.add('under');
    stage.appendChild(page);
    stack.push(entry);
  }

  function pop() {
    if (stack.length <= 1) return closeApp();
    const cur = stack.pop();
    cur.dispose?.();
    cur.el.classList.add('leaving');
    setTimeout(() => cur.el.remove(), 260);
    const prev = stack[stack.length - 1];
    prev.el.classList.remove('under');
    prev.el.classList.add('back-in');
    setTimeout(() => prev.el.classList.remove('back-in'), 320);
    prev.onShow?.();
  }

  function pageShell(page, { title, right = '' }) {
    page.innerHTML = `
      <header class="wa-head">
        <button type="button" class="wa-circle-btn" data-back aria-label="رجوع">${icon('back', 'flip')}</button>
        <h1 class="wa-title">${title}</h1>${right}
      </header>
      <div class="wa-scroll" data-body></div>`;
    $('[data-back]', page).addEventListener('click', pop);
    return $('[data-body]', page);
  }

  // The wallet is locked every time it is entered: leaving it (back arrow, any
  // bottom-bar tab, logout) forgets the unlock, so coming back asks for the PIN again.
  let unlocked = false;

  function openApp(view = 'home') {
    ensureShell();
    stack.splice(0).forEach((e) => { e.dispose?.(); e.el.remove(); });
    root.classList.add('open');
    // PIN settings verifies the current PIN itself, so it doesn't need the gate first.
    if (view === 'pin') {
      push(buildPin);
      return;
    }
    if (!unlocked) {
      push((page) => buildGate(page, view));
      return;
    }
    showView(view);
  }

  function showView(view) {
    push(buildHome);
    if (view === 'topup') startTopup();
    if (view === 'transfer') push(buildTransfer);
  }

  function closeApp() {
    unlocked = false;
    closeSheet(true);
    layer.classList.remove('open');
    root.classList.remove('open');
    setTimeout(() => { if (!root.classList.contains('open')) stack.splice(0).forEach((e) => { e.dispose?.(); e.el.remove(); }); }, 400);
  }

  // ================================================================== bottom sheet

  function openSheet(html, mount) {
    closeSheet(true);
    sheetEl.innerHTML = `<div class="wa-grab"></div>${html}`;
    sheetDispose = mount?.(sheetEl) || null;
    requestAnimationFrame(() => sheetBd.classList.add('open'));
  }

  function closeSheet(immediate = false) {
    sheetBd.classList.remove('open');
    const dispose = sheetDispose;
    sheetDispose = null;
    dispose?.();
    if (immediate) sheetEl.innerHTML = '';
    else setTimeout(() => { if (!sheetBd.classList.contains('open')) sheetEl.innerHTML = ''; }, 400);
  }

  // ==================================================================== PIN pad

  function pinPadMarkup({ ic = 'lock', tone = 'red', title, sub = '' }) {
    const keys = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) => `<button type="button" class="wa-key" data-k="${n}">${n}</button>`).join('');
    return `
      <div class="wa-pin">
        <div class="wa-ico tone-${tone}">${icon(ic, 'lg')}</div>
        <h3 data-pt>${title}</h3>
        <p class="sub" data-ps>${sub}</p>
        <div class="wa-pin-dots" data-dots dir="ltr"><i></i><i></i><i></i><i></i></div>
        <div class="wa-pin-err" data-perr></div>
        <div class="wa-keypad">${keys}<span class="wa-key blank"></span>
          <button type="button" class="wa-key" data-k="0">0</button>
          <button type="button" class="wa-key fn" data-k="del" aria-label="حذف">${icon('backspace')}</button>
        </div>
      </div>`;
  }

  /*
   * Behaviour for the pad above. `onComplete(pin)` runs when 4 digits are in and
   * returns undefined (all good, caller moves on), { error } (shake + clear) or
   * { reset: true } (clear, e.g. moving to the next step).
   */
  function mountPinPad(host, onComplete) {
    const pad = $('.wa-pin', host);
    const dots = $$('[data-dots] i', pad);
    const dotsEl = $('[data-dots]', pad);
    const errEl = $('[data-perr]', pad);
    let value = '';
    let busy = false;

    const paint = () => dots.forEach((d, i) => d.classList.toggle('on', i < value.length));

    async function complete() {
      busy = true;
      pad.classList.add('busy');
      const res = (await onComplete(value)) || {};
      busy = false;
      pad.classList.remove('busy');
      if (res.error) {
        errEl.textContent = res.error;
        value = ''; // typing can start again straight away; the dots keep shaking meanwhile
        dotsEl.classList.add('bad', 'shake');
        navigator.vibrate?.(120);
        await sleep(450);
        dotsEl.classList.remove('bad', 'shake');
      } else if (res.reset) {
        value = '';
      }
      paint();
    }

    function press(key) {
      if (busy) return;
      errEl.textContent = '';
      if (key === 'del') value = value.slice(0, -1);
      else if (value.length < 4) value += key;
      paint();
      if (value.length === 4) complete();
    }

    const onClick = (e) => { const k = e.target.closest('[data-k]')?.dataset.k; if (k !== undefined) press(k); };
    const onKey = (e) => {
      if (/^\d$/.test(e.key)) press(e.key);
      else if (e.key === 'Backspace') press('del');
    };
    pad.addEventListener('click', onClick);
    document.addEventListener('keydown', onKey);

    return {
      setText(title, sub) { $('[data-pt]', pad).textContent = title; $('[data-ps]', pad).textContent = sub || ''; },
      dispose() { document.removeEventListener('keydown', onKey); },
    };
  }

  /*
   * One PIN prompt for every protected action, as a bottom sheet. Creation is
   * lazy: with no PIN yet, the enter + confirm steps replace the verify step
   * right here. `onSubmit(pin)` runs the action and returns
   * {type:'done'} | {type:'retry', message} | {type:'close', message}.
   */
  function openPinSheet({ hasPin, onSubmit, onClose, subtitle = 'لتأكيد العملية' }) {
    let step = hasPin ? 'enter' : 'create';
    let first = '';
    let pad;

    openSheet(pinPadMarkup({
      title: hasPin ? 'أدخل الرقم السري' : 'أنشئ رقمك السري',
      sub: hasPin ? subtitle : 'يحمي هذا الرقم كل عملية دفع من محفظتك',
    }), (sheet) => {
      pad = mountPinPad(sheet, async (pin) => {
        if (step === 'create') {
          first = pin;
          step = 'confirm';
          pad.setText('أكّد الرقم السري', 'أعد إدخال نفس الأرقام');
          return { reset: true };
        }
        if (step === 'confirm') {
          if (pin !== first) {
            step = 'create';
            pad.setText('أنشئ رقمك السري', 'يحمي هذا الرقم كل عملية دفع من محفظتك');
            return { error: 'الرقمان غير متطابقين. حاول من جديد.' };
          }
          const created = await api('/pin', { method: 'POST', body: { pin, pin_confirmation: pin } });
          if (!ok(created)) {
            step = 'create';
            pad.setText('أنشئ رقمك السري', '');
            return { error: errMsg(created) };
          }
          step = 'enter';
        }
        const outcome = await onSubmit(pin);
        if (outcome.type === 'retry') return { error: outcome.message };
        closeSheet();
        if (outcome.type === 'close') onClose?.(outcome.message);
        return {};
      });
      return () => pad?.dispose();
    });
  }

  // ===================================================================== sheets

  function openExplain() {
    const row = (ic, tone, title, text) => `
      <div class="wa-explain"><span class="wa-ico tone-${tone}">${icon(ic)}</span><div><h4>${title}</h4><p>${text}</p></div></div>`;
    openSheet(`
      <div class="wa-sheet-head"><span class="wa-ico tone-red">${icon('wallet', 'lg')}</span><h3>ما هي أنواع الرصيد؟</h3></div>
      ${row('bank', 'green', 'قابل للسحب', 'الأموال التي شحنتها بنفسك. يمكنك استخدامها في الخدمات أو سحبها إلى حسابك.')}
      ${row('gift', 'pink', 'للخدمات فقط', 'الهدايا والتحويلات التي استلمتها. تستخدمها في الخدمات، لكن لا يمكن سحبها.')}
      ${row('pause', 'amber', 'محجوز', 'مبلغ محجوز مؤقتاً لطلب قيد التنفيذ، ويعود متاحاً إن لم يُنفَّذ.')}
      <button type="button" class="wa-btn" style="margin-top:8px" data-close>فهمت</button>`,
    (sheet) => { $('[data-close]', sheet).addEventListener('click', () => closeSheet()); });
  }

  function openTxSheet(tx) {
    const [ic, tone] = txMeta(tx.type);
    const credit = tx.direction === 'credit';
    const spend = tx.bucket === 'spend_only';
    const kv = (k, v) => `<div class="wa-kv"><span>${k}</span><b>${v}</b></div>`;
    openSheet(`
      <div class="wa-sheet-head">
        <span class="wa-ico tone-${tone}">${icon(ic, 'lg')}</span>
        <h3>${escapeHtml(tx.type_label)}</h3>
        <p dir="ltr" style="font-size:26px;font-weight:800;color:${credit ? '#16A34A' : '#DC2626'};margin-top:6px">${credit ? '+' : '−'} ${fmt(tx.amount_minor)} ${cur()}</p>
      </div>
      <div style="margin-top:14px">
        ${kv('التاريخ', `${dayLabel(tx.created_at)} · ${timeOf(tx.created_at)}`)}
        ${kv('نوع الرصيد', spend ? 'للخدمات فقط' : 'قابل للسحب')}
        ${kv('الرصيد بعد العملية', `<span dir="ltr">${fmt(tx.balance_after_minor)} ${cur()}</span>`)}
        ${tx.counterparty ? kv(credit ? 'من' : 'إلى', `${escapeHtml(tx.counterparty.name || '')} <span dir="ltr">${escapeHtml(tx.counterparty.phone || '')}</span>`) : ''}
        ${tx.note && tx.note !== tx.type_label ? kv('ملاحظة', escapeHtml(tx.note)) : ''}
        ${kv('رقم العملية', `<span dir="ltr" style="font-size:11px;color:#9CA3AF">${escapeHtml(String(tx.uuid).slice(0, 8))}</span>`)}
      </div>
      ${spend && credit ? `<div class="wa-note" style="margin-top:12px">${icon('info', 'sm')}<span>هذا المبلغ يُستخدم في الخدمات فقط ولا يمكن سحبه.</span></div>` : ''}
      <button type="button" class="wa-btn ghost" style="margin-top:14px" data-close>إغلاق</button>`,
    (sheet) => { $('[data-close]', sheet).addEventListener('click', () => closeSheet()); });
  }

  // ================================================================ transactions

  function txRowMarkup(tx, withDay = false) {
    const [ic, tone] = txMeta(tx.type);
    const credit = tx.direction === 'credit';
    const party = tx.counterparty ? escapeHtml(tx.counterparty.name || tx.counterparty.phone || '') : '';
    const sub = party || (tx.note && tx.note !== tx.type_label ? escapeHtml(tx.note) : (withDay ? timeOf(tx.created_at) : `${dayLabel(tx.created_at)} · ${timeOf(tx.created_at)}`));
    const badge = tx.bucket === 'spend_only' ? '<em class="wa-badge">للخدمات فقط</em>' : '';
    return `
      <button type="button" class="wa-tx" data-tx="${escapeHtml(tx.uuid)}">
        <span class="wa-ico tone-${tone}">${icon(ic)}</span>
        <span class="wa-tx-main"><b>${escapeHtml(tx.type_label)} ${badge}</b><small>${sub}</small></span>
        <span class="wa-tx-amt ${tx.direction}"><span dir="ltr">${credit ? '+' : '−'} ${fmt(tx.amount_minor)}</span>${withDay ? `<small>${timeOf(tx.created_at)}</small>` : `<small>${cur()}</small>`}</span>
      </button>`;
  }

  const skeletonRows = (n) => Array.from({ length: n }, () =>
    '<div class="wa-sk-row"><div class="wa-sk"></div><div style="flex:1"><div class="wa-sk" style="height:12px;width:55%"></div><div class="wa-sk" style="height:10px;width:35%;margin-top:8px"></div></div><div class="wa-sk" style="height:14px;width:60px"></div></div>').join('');

  function bindTxRows(host, rows) {
    $$('[data-tx]', host).forEach((btn) => btn.addEventListener('click', () => {
      const tx = rows.find((r) => r.uuid === btn.dataset.tx);
      if (tx) openTxSheet(tx);
    }));
  }

  async function refreshBalance() {
    window.dorrRefreshNotifications?.();
    const r = await api('');
    if (ok(r)) {
      state.balance = r.json.data;
      paintCard();
    }
    return r;
  }

  // The little balance card on the Account tab.
  let cardShown = 0;
  function paintCard() {
    const b = state.balance;
    const total = b ? b.total_minor : 0;
    // The Account-tab card and the Home-tab card show the same number; count up once for both.
    [$('#wallet-card-amount'), $('#home-wallet-amount')].forEach((el) => countUp(el, cardShown, total, 700));
    cardShown = total;
    if (b) {
      const c = b.currency_symbol || b.currency_code || '';
      $('#wallet-card-currency').textContent = c;
      $('#home-wallet-cur').textContent = c;
    }
  }

  // ======================================================================== home

  function heroMarkup(b) {
    const c = escapeHtml(b.currency_code || '');
    const part = (ic, label, minor) => `<button type="button" class="wa-part" data-explain><small>${icon(ic, 'sm')}${label}</small><strong dir="ltr">${state.hide ? '****' : fmt(minor)}</strong></button>`;
    return `
      <div class="wa-hero">
        <div class="wa-shine"></div>
        <div class="wa-hero-top">
          <span class="wa-chip-glass">${icon('globe', 'sm')}${escapeHtml(b.country_code || '')} · ${c}</span>
          <span class="wa-chip-glass">${icon('wallet', 'sm')}محفظتي</span>
        </div>
        <div class="wa-hero-label">إجمالي الرصيد</div>
        <div class="wa-hero-amount" dir="ltr"><b data-amt>${state.hide ? '******' : fmt(state.shown)}</b><span>${c}</span></div>
        <div class="wa-hero-parts">
          ${part('bank', 'قابل للسحب', b.withdrawable_minor)}
          ${part('gift', 'للخدمات فقط', b.spend_only_minor)}
        </div>
        ${b.held_minor > 0 ? `<div class="wa-held">${icon('pause', 'sm')}<span>محجوز مؤقتاً: <b dir="ltr">${state.hide ? '****' : fmt(b.held_minor)} ${c}</b></span></div>` : ''}
      </div>`;
  }

  function buildHome(page) {
    const body = pageShell(page, {
      title: 'المحفظة',
      right: `<button type="button" class="wa-circle-btn" data-eye aria-label="إخفاء الرصيد">${icon(state.hide ? 'eye-off' : 'eye')}</button>
              <button type="button" class="wa-circle-btn" data-refresh aria-label="تحديث">${icon('refresh')}</button>`,
    });
    const action = (ic, tone, label, key, i) => `<button type="button" class="wa-action wa-rise" style="--i:${i}" data-go="${key}"><span class="wa-ico tone-${tone}">${icon(ic)}</span>${label}</button>`;

    body.innerHTML = `
      <div class="wa-rise" data-hero><div class="wa-sk" style="height:196px;border-radius:28px"></div></div>
      <div data-num></div>
      <div class="wa-actions">
        ${action('plus', 'red', 'شحن', 'topup', 1)}
        ${action('send', 'blue', 'تحويل', 'transfer', 2)}
        ${action('history', 'amber', 'الكشف', 'history', 3)}
        ${action('lock', 'gray', 'الرقم السري', 'pin', 4)}
      </div>
      <div data-others></div>
      <div class="wa-sec wa-rise" style="--i:5"><h3>آخر الحركات</h3><button type="button" class="wa-link" data-go="history">عرض الكل ${icon('chevron', 'sm flip')}</button></div>
      <div class="wa-card wa-list wa-rise" style="--i:6" data-recent>${skeletonRows(3)}</div>`;

    const heroHost = $('[data-hero]', body);
    const recent = $('[data-recent]', body);
    const eye = $('[data-eye]', page);
    const refreshBtn = $('[data-refresh]', page);

    function paintHero() {
      const b = state.balance;
      if (!b) return;
      heroHost.innerHTML = heroMarkup(b);
      $$('[data-explain]', heroHost).forEach((el) => el.addEventListener('click', openExplain));
      countUp($('[data-amt]', heroHost), state.shown, b.total_minor);
      state.shown = b.total_minor;

      // The number others use to send money to *this* wallet (one number per country wallet).
      const numHost = $('[data-num]', body);
      numHost.innerHTML = b.wallet_number ? `
        <div class="wa-mynum wa-rise" style="--i:1">
          <span class="wa-ico tone-blue">${icon('wallet')}</span>
          <div class="wa-mynum-txt"><small>رقم محفظتك في ${escapeHtml(countryName(b.country_code))} — شاركه ليحوّل لك الآخرون</small><b dir="ltr">${escapeHtml(b.wallet_number_formatted || b.wallet_number)}</b></div>
          <button type="button" class="wa-circle-btn" data-myqr aria-label="رمز QR">${icon('qr')}</button>
          <button type="button" class="wa-circle-btn" data-copy aria-label="نسخ">${icon('copy')}</button>
        </div>` : '';
      $('[data-myqr]', numHost)?.addEventListener('click', () => push(buildMyQr));
      $('[data-copy]', numHost)?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        await copyText(b.wallet_number_formatted || b.wallet_number);
        btn.classList.add('copied');
        btn.innerHTML = icon('check');
        toast('تم نسخ رقم المحفظة', 'copy');
        setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = icon('copy'); }, 1600);
      });

      const others = $('[data-others]', body);
      others.innerHTML = b.other_wallets?.length ? `
        <div class="wa-card wa-rise" style="--i:4;padding:14px 16px;margin-top:8px">
          <div style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:14px">${icon('globe', 'sm')}محافظ في دول أخرى</div>
          ${b.other_wallets.map((w) => `<div class="wa-kv"><span>${escapeHtml(w.country_code || '')}</span><b dir="ltr">${fmt(w.total_minor)} ${escapeHtml(w.currency_code || '')}</b></div>`).join('')}
          <div class="wa-q-hint">لا يمكن استخدام هذه الأرصدة في دولتك الحالية.</div>
        </div>` : '';
    }

    async function load() {
      refreshBtn.classList.add('spinning');
      const [bal, txs] = await Promise.all([refreshBalance(), api('/transactions?per_page=5')]);
      refreshBtn.classList.remove('spinning');
      if (!page.isConnected) return;

      if (!ok(bal)) {
        heroHost.innerHTML = `<div class="wa-card wa-empty"><span class="wa-ico tone-gray">${icon('alert', 'lg')}</span><h4>تعذّر تحميل المحفظة</h4><p>${escapeHtml(errMsg(bal))}</p><button type="button" class="wa-btn ghost" data-retry style="max-width:200px;margin:auto">${icon('refresh', 'sm')}حاول مرة أخرى</button></div>`;
        $('[data-retry]', heroHost)?.addEventListener('click', load);
        recent.innerHTML = '';
        return;
      }
      paintHero();

      const rows = ok(txs) ? txs.json.data : [];
      if (!rows.length) {
        recent.classList.remove('wa-list');
        recent.innerHTML = `<div class="wa-empty"><span class="wa-ico tone-red">${icon('sparkle', 'lg')}</span><h4>لا توجد حركات بعد</h4><p>ابدأ بشحن محفظتك وستظهر عملياتك هنا.</p><button type="button" class="wa-btn" data-go="topup" style="max-width:220px;margin:auto">${icon('plus', 'sm')}اشحن الآن</button></div>`;
      } else {
        recent.classList.add('wa-list');
        recent.innerHTML = rows.map((t) => txRowMarkup(t)).join('');
        bindTxRows(recent, rows);
      }
      $$('[data-go]', recent).forEach((el) => el.addEventListener('click', () => go(el.dataset.go)));
    }

    function go(key) {
      if (key === 'topup') startTopup();
      else if (key === 'transfer') push(buildTransfer);
      else if (key === 'history') push(buildHistory);
      else if (key === 'pin') push(buildPin);
    }

    $$('[data-go]', body).forEach((el) => el.addEventListener('click', () => go(el.dataset.go)));
    eye.addEventListener('click', () => {
      state.hide = !state.hide;
      localStorage.setItem('wa_hide', state.hide ? '1' : '0');
      eye.innerHTML = icon(state.hide ? 'eye-off' : 'eye');
      paintHero();
    });
    refreshBtn.addEventListener('click', load);

    const entry = { onShow: load };
    load();
    return entry;
  }

  // ====================================================================== top-up

  function startTopup() {
    push(buildTopup);
  }

  const QUICK = [50, 100, 200, 500];

  function methodIcon(gateway) {
    return { myfatoorah: ['card', 'blue'], arb: ['bank', 'green'], urpay: ['phone', 'pink'], sandbox: ['flask', 'amber'] }[gateway] || ['card', 'gray'];
  }

  function buildTopup(page) {
    const tp = {
      methods: null, methodId: null, amountText: '', quote: null, seq: 0, timer: null,
      idem: uuid(), pin: null, payment: null, poll: null,
    };
    const body = pageShell(page, { title: 'شحن المحفظة' });
    body.classList.add('has-cta');
    body.innerHTML = `
      <div class="wa-card wa-amount wa-rise" style="--i:0">
        <label for="tp-amount">كم تريد أن تشحن؟</label>
        <div class="wa-amount-row" dir="ltr">
          <input id="tp-amount" type="text" inputmode="decimal" placeholder="0.00" autocomplete="off">
          <span class="wa-amount-cur">${cur()}</span>
        </div>
        <div class="wa-chips">${QUICK.map((v) => `<button type="button" class="wa-chip" data-q="${v}">${v}</button>`).join('')}</div>
        <div class="wa-err" data-amt-err hidden>${icon('info', 'sm')}<span>أدخل مبلغاً بحد أقصى منزلتين عشريتين</span></div>
      </div>
      <div class="wa-sec wa-rise" style="--i:1"><h3>طريقة الدفع</h3></div>
      <div data-methods class="wa-rise" style="--i:2">${skeletonRows(2)}</div>
      <div data-quote></div>
      <div class="wa-err" data-error hidden></div>`;

    const cta = document.createElement('div');
    cta.className = 'wa-cta';
    cta.innerHTML = `<button type="button" class="wa-btn" data-pay disabled>${icon('lock', 'sm')}<span>متابعة</span></button><small>${icon('shield', 'sm')}مدفوعات مشفّرة وآمنة</small>`;
    page.appendChild(cta);

    const input = $('#tp-amount', body);
    const pay = $('[data-pay]', cta);

    const setError = (m) => { const el = $('[data-error]', body); if (!el) return; el.innerHTML = m ? `${icon('alert', 'sm')}<span>${escapeHtml(m)}</span>` : ''; el.hidden = !m; };

    function paintPay() {
      const amount = parseAmount(tp.amountText);
      pay.disabled = !tp.quote;
      $('span', pay).innerHTML = amount !== null && tp.quote ? `ادفع <bdi>${fmt(amount)} ${cur()}</bdi>` : 'متابعة';
    }

    function paintMethods() {
      const box = $('[data-methods]', body);
      if (!tp.methods?.length) {
        box.innerHTML = `<div class="wa-empty wa-card"><span class="wa-ico tone-gray">${icon('card', 'lg')}</span><h4>لا توجد طرق دفع</h4><p>لا توجد طرق دفع متاحة في دولتك حالياً.</p></div>`;
        return;
      }
      box.innerHTML = tp.methods.map((m) => {
        const [ic, tone] = methodIcon(m.gateway);
        return `
          <button type="button" class="wa-method${m.id === tp.methodId ? ' on' : ''}${m.coming_soon ? ' soon' : ''}" data-m="${m.id}">
            <span class="wa-ico tone-${tone}">${icon(ic)}</span>
            <span class="wa-method-txt"><b>${escapeHtml(m.name || m.code)}</b><small>${m.coming_soon ? 'سيتم تفعيل هذه الطريقة قريباً' : m.gateway === 'sandbox' ? 'بوابة تجريبية — لا يتم خصم أموال حقيقية' : 'دفع إلكتروني آمن'}</small></span>
            ${m.coming_soon ? '<span class="wa-soon">قريباً</span>' : `<span class="wa-check">${icon('check')}</span>`}
          </button>`;
      }).join('');
      $$('[data-m]', box).forEach((btn) => btn.addEventListener('click', () => {
        // A listed gateway with no credentials yet: visible, but not selectable.
        if (tp.methods.find((m) => m.id === Number(btn.dataset.m))?.coming_soon) { toast('طريقة الدفع هذه ستتوفر قريباً', 'clock'); return; }
        tp.methodId = Number(btn.dataset.m);
        paintMethods();
        scheduleQuote();
      }));
    }

    function paintQuote(quote, error) {
      const box = $('[data-quote]', body);
      paintPay();
      if (error) { box.innerHTML = `<div class="wa-err">${icon('alert', 'sm')}<span>${escapeHtml(error)}</span></div>`; return; }
      if (!quote) { box.innerHTML = ''; return; }
      const line = (ic, label, value, cls = '') => `<div class="wa-q-row ${cls}"><span>${icon(ic, 'sm')}${label}</span><b dir="ltr">${value} ${cur()}</b></div>`;
      box.innerHTML = `
        <div class="wa-sec"><h3>ملخص العملية</h3></div>
        <div class="wa-card wa-quote">
          ${line('card', 'ستدفع', fmt(quote.paid_amount_minor))}
          ${quote.fee_minor > 0 ? line('receipt', 'رسوم الخدمة', `− ${fmt(quote.fee_minor)}`) : ''}
          ${quote.bonus_minor > 0 ? line('gift', 'هدية الشحن', `+ ${fmt(quote.bonus_minor)}`) : ''}
          ${quote.bonus_minor > 0 ? `<p class="wa-q-hint">${icon('sparkle', 'sm')} الهدية تُستخدم في الخدمات ولا يمكن سحبها.</p>` : ''}
          ${line('wallet', 'يُضاف إلى محفظتك', fmt(quote.total_credited_minor), 'total')}
        </div>`;
    }

    function scheduleQuote() {
      clearTimeout(tp.timer);
      tp.quote = null;
      const amount = parseAmount(tp.amountText);
      const err = $('[data-amt-err]', body);
      err.hidden = tp.amountText === '' || amount !== null;
      paintQuote(null);
      if (amount === null || tp.methodId === null) return;
      const seq = ++tp.seq;
      tp.timer = setTimeout(async () => {
        const r = await api('/topups/quote', { method: 'POST', body: { payment_method_id: tp.methodId, amount_minor: amount } });
        if (seq !== tp.seq || !body.isConnected || tp.payment) return; // superseded
        if (ok(r)) { tp.quote = r.json.data; paintQuote(tp.quote); } else { paintQuote(null, errMsg(r)); }
      }, 350);
    }

    input.addEventListener('input', () => {
      input.value = input.value.replace(/[^\d.,]/g, '');
      tp.amountText = input.value;
      $$('.wa-chip', body).forEach((c) => c.classList.toggle('on', Number(c.dataset.q) * 100 === parseAmount(tp.amountText)));
      scheduleQuote();
    });
    $$('.wa-chip', body).forEach((chip) => chip.addEventListener('click', () => {
      input.value = chip.dataset.q;
      input.dispatchEvent(new Event('input'));
      input.focus();
    }));

    pay.addEventListener('click', async () => {
      setError('');
      pay.disabled = true;
      const pinStatus = await api('/pin');
      paintPay();
      if (!ok(pinStatus)) return setError(errMsg(pinStatus));
      openPinSheet({
        hasPin: pinStatus.json.data.has_pin,
        subtitle: 'لتأكيد عملية الشحن',
        onSubmit: submitTopup,
        onClose: setError,
      });
    });

    async function submitTopup(pin) {
      const amount = parseAmount(tp.amountText);
      const r = await api('/topups', {
        method: 'POST', pin, idem: tp.idem,
        body: { payment_method_id: tp.methodId, amount_minor: amount },
      });
      if (ok(r)) {
        tp.idem = uuid();
        tp.pin = pin; // kept in memory for the OTP step only; dropped with the page
        tp.payment = r.json.data;
        tp.gateway = tp.methods?.find((m) => m.id === tp.methodId)?.gateway;
        showStage();
        if (tp.payment.redirect_url) openGateway();
        startPolling();
        return { type: 'done' };
      }
      // No HTTP answer at all: keep the idempotency key so a retry can't double-charge.
      if (r.status === 0) return { type: 'retry', message: errMsg(r) };
      tp.idem = uuid();
      if (PIN_CODES.includes(r.json?.error_code)) return { type: 'retry', message: errMsg(r) };
      return { type: 'close', message: errMsg(r) };
    }

    // ---- after the hand-off: waiting / OTP / paid / failed
    function confettiMarkup() {
      const colors = ['#E50914', '#F59E0B', '#16A34A', '#2563EB', '#DB2777', '#FFFFFF'];
      return `<div class="wa-confetti">${Array.from({ length: 26 }, () => {
        const x = Math.round((Math.random() - 0.5) * 300);
        return `<i style="--x:${x}px;--r:${Math.round(Math.random() * 720 - 360)}deg;--d:${(Math.random() * 0.35).toFixed(2)}s;--c:${colors[Math.floor(Math.random() * colors.length)]}"></i>`;
      }).join('')}</div>`;
    }

    const seal = (bad) => `
      <div class="wa-seal${bad ? ' bad' : ''}">
        <svg viewBox="0 0 104 104"><circle class="ring" cx="52" cy="52" r="46"/><circle class="disc" cx="52" cy="52" r="46"/>
          <path class="mark" d="${bad ? 'M38 38l28 28M66 38L38 66' : 'M34 54l13 13 24-26'}"/></svg>
      </div>`;

    function stageMarkup() {
      const p = tp.payment;
      if (p.status === 'paid') {
        const credited = p.amount_minor + p.bonus_minor - p.fee_minor;
        return `
          ${confettiMarkup()}
          ${seal(false)}
          <h2>تم شحن محفظتك!</h2>
          <div class="amount-big" dir="ltr">+ ${fmt(credited)} <small>${cur()}</small></div>
          <div class="wa-card wa-recap">
            <div class="wa-q-row"><span>${icon('card', 'sm')}المبلغ المدفوع</span><b dir="ltr">${fmt(p.amount_minor)} ${cur()}</b></div>
            ${p.fee_minor > 0 ? `<div class="wa-q-row"><span>${icon('receipt', 'sm')}الرسوم</span><b dir="ltr">− ${fmt(p.fee_minor)} ${cur()}</b></div>` : ''}
            ${p.bonus_minor > 0 ? `<div class="wa-q-row"><span>${icon('gift', 'sm')}هدية (للخدمات فقط)</span><b dir="ltr">+ ${fmt(p.bonus_minor)} ${cur()}</b></div>` : ''}
            <div class="wa-q-row"><span>${icon('wallet', 'sm')}رصيدك الآن</span><b dir="ltr" data-newbal>${state.balance ? fmt(state.balance.total_minor) : '…'} ${cur()}</b></div>
          </div>
          <button type="button" class="wa-btn" data-done>تم</button>
          <button type="button" class="wa-btn quiet" data-statement>عرض كشف الحساب</button>`;
      }
      if (p.status === 'pending' && p.requires_otp) {
        return `
          <div class="wa-pulse"><i></i><i></i><i></i><span class="core">${icon('phone')}</span></div>
          <h2>أدخل رمز التحقق</h2>
          <p>أرسلنا رمزاً إلى هاتفك لتأكيد هذه العملية.</p>
          <input class="wa-otp" data-otp type="text" inputmode="numeric" maxlength="8" dir="ltr" placeholder="••••••" autocomplete="one-time-code">
          <div class="wa-err" data-error hidden></div>
          <button type="button" class="wa-btn" data-otp-ok disabled>تأكيد الدفع</button>
          <button type="button" class="wa-btn quiet" data-cancel>إلغاء</button>`;
      }
      if (p.status === 'pending') {
        return `
          <div class="wa-pulse"><i></i><i></i><i></i><span class="core">${icon(tp.gateway === 'sandbox' ? 'flask' : 'card')}</span></div>
          <h2>بانتظار إتمام الدفع<span class="wa-dots-loading"><i></i><i></i><i></i></span></h2>
          <p>أكمل الدفع في صفحة البوابة. ستتحدث هذه الصفحة تلقائياً بمجرد التأكيد.</p>
          ${p.redirect_url ? `<button type="button" class="wa-btn ghost" data-reopen>${icon('external', 'sm')}افتح صفحة الدفع</button>` : ''}
          <button type="button" class="wa-btn quiet" data-cancel>إلغاء</button>`;
      }
      return `
        ${seal(true)}
        <h2>لم تكتمل العملية</h2>
        <p>لم يُضف أي مبلغ إلى محفظتك. يمكنك المحاولة مرة أخرى.</p>
        <button type="button" class="wa-btn" data-retry>${icon('refresh', 'sm')}حاول مرة أخرى</button>
        <button type="button" class="wa-btn quiet" data-close-page>رجوع للمحفظة</button>`;
    }

    function showStage() {
      body.classList.remove('has-cta');
      body.classList.add('fill');
      cta.remove();
      body.innerHTML = `<div class="wa-status">${stageMarkup()}</div>`;
      bindStage();
    }

    function openGateway() {
      const url = tp.payment.redirect_url;
      if (tp.gateway === 'sandbox') {
        // The fake bank runs inside the phone, like an in-app browser.
        layer.innerHTML = `
          <div class="wa-layer-head"><span>${icon('lock', 'sm')}الدفع الآمن</span><button type="button" class="wa-circle-btn" data-layer-close aria-label="إغلاق">${icon('x')}</button></div>
          <iframe title="payment" src="${escapeHtml(url)}"></iframe>`;
        $('[data-layer-close]', layer).addEventListener('click', closeLayer);
        requestAnimationFrame(() => layer.classList.add('open'));
      } else {
        window.open(url, '_blank', 'noopener');
      }
    }

    function closeLayer() {
      layer.classList.remove('open');
      setTimeout(() => { if (!layer.classList.contains('open')) layer.innerHTML = ''; }, 400);
    }

    function bindStage() {
      $('[data-done]', body)?.addEventListener('click', () => { stopPolling(); pop(); });
      $('[data-statement]', body)?.addEventListener('click', () => { stopPolling(); pop(); push(buildHistory); });
      $('[data-retry]', body)?.addEventListener('click', () => { closeLayer(); replace(); });
      $('[data-close-page]', body)?.addEventListener('click', () => { stopPolling(); pop(); });
      $('[data-cancel]', body)?.addEventListener('click', () => { stopPolling(); closeLayer(); replace(); });
      $('[data-reopen]', body)?.addEventListener('click', openGateway);

      const otp = $('[data-otp]', body);
      const ok2 = $('[data-otp-ok]', body);
      if (otp) {
        otp.addEventListener('input', () => { otp.value = otp.value.replace(/\D/g, ''); ok2.disabled = otp.value.length < 4; });
        ok2.addEventListener('click', async () => {
          ok2.disabled = true;
          const r = await api(`/topups/${tp.payment.uuid}/confirm`, { method: 'POST', body: { otp: otp.value }, pin: tp.pin });
          if (ok(r)) { tp.payment = r.json.data; if (tp.payment.status === 'paid') await refreshBalance(); showStage(); } else { setError(errMsg(r)); ok2.disabled = false; }
        });
      }
    }

    // "Try again" = a brand new attempt on a fresh page.
    function replace() {
      stopPolling();
      const idx = stack.findIndex((e) => e.el === page);
      if (idx < 0) return;
      stack.pop();
      page.remove();
      entry.dispose?.();
      const prev = stack[stack.length - 1];
      prev.el.classList.remove('under');
      startTopup();
    }

    function stopPolling() { clearInterval(tp.poll); tp.poll = null; }

    function startPolling() {
      stopPolling();
      let ticks = 0;
      tp.poll = setInterval(async () => {
        const p = tp.payment;
        if (!p || p.status !== 'pending' || ++ticks > 90) return stopPolling();
        const r = await api(`/topups/${p.uuid}`);
        if (!ok(r) || !tp.payment) return;
        tp.payment = r.json.data;
        if (tp.payment.status !== 'pending') {
          stopPolling();
          closeLayer();
          if (tp.payment.status === 'paid') await refreshBalance();
          if (body.isConnected) showStage();
        }
      }, 2000);
    }

    // Load the methods, then focus the amount.
    (async () => {
      const r = await api('/payment-methods');
      if (!body.isConnected) return;
      if (!ok(r)) { $('[data-methods]', body).innerHTML = ''; return setError(errMsg(r)); }
      tp.methods = r.json.data;
      tp.methodId ??= tp.methods.find((m) => !m.coming_soon)?.id ?? null;
      paintMethods();
      scheduleQuote();
      setTimeout(() => input.focus(), 350);
    })();

    const entry = { dispose() { clearTimeout(tp.timer); stopPolling(); } };
    return entry;
  }

  // ==================================================================== QR codes
  /**
   * "My QR" shows the wallet's own number as a QR code; "Scan" reads someone else's and
   * goes to the *same* confirmation screen as typing their number (the server decides
   * what a scanned code means — POST /transfers/lookup with mode "qr"). A code holds
   * public facts only (`dorr://wallet/SA/12345678901`), so it is safe to show or share:
   * scanning it can never take money, only start a transfer *to* this wallet.
   */

  function qrSvg(text) {
    const q = window.qrcode(0, 'M');
    q.addData(text);
    q.make();
    const n = q.getModuleCount();
    const margin = 2;
    let cells = '';
    for (let r = 0; r < n; r++) {
      for (let c = 0; c < n; c++) {
        if (q.isDark(r, c)) cells += `<rect x="${c + margin}" y="${r + margin}" width="1.02" height="1.02"/>`;
      }
    }
    const size = n + margin * 2;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges"><rect width="${size}" height="${size}" fill="#fff"/><g fill="#111928">${cells}</g></svg>`;
  }

  /** SVG → PNG file the person can keep or print. */
  function savePng(svg, filename) {
    const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    const img = new Image();
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 1024;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, 1024, 1024);
      ctx.drawImage(img, 0, 0, 1024, 1024);
      URL.revokeObjectURL(url);
      const a = document.createElement('a');
      a.href = canvas.toDataURL('image/png');
      a.download = filename;
      a.click();
    };
    img.src = url;
  }

  function buildMyQr(page) {
    const b = state.balance || {};
    const body = pageShell(page, { title: 'رمز QR الخاص بي' });
    const number = b.wallet_number_formatted || groupNumber(b.wallet_number || '');
    const svg = b.qr_payload && window.qrcode ? qrSvg(b.qr_payload) : '';

    body.innerHTML = `
      <div class="wa-card wa-myqr wa-rise" style="--i:0">
        <span class="wa-verified">${icon('wallet', 'sm')}محفظتك في ${escapeHtml(countryName(b.country_code))}</span>
        <div class="wa-qrbox">${svg ? `<img alt="QR" src="data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}">` : `<p class="wa-hint">تعذّر إنشاء الرمز.</p>`}<i class="c tl"></i><i class="c tr"></i><i class="c bl"></i><i class="c br"></i></div>
        <div class="wa-myqr-num" dir="ltr">${escapeHtml(number)}</div>
        <div class="wa-rc-tags">
          <span class="wa-chip-glass dark">${icon('globe', 'sm')}${escapeHtml(countryName(b.country_code))}</span>
          <span class="wa-chip-glass dark">${icon('wallet', 'sm')}${cur()}</span>
        </div>
        <p class="wa-hint c">اطلب من الشخص الآخر أن يفتح <b>تحويل ← مسح رمز QR</b> ويوجّه الكاميرا نحو هذا الرمز.</p>
      </div>

      <div class="wa-qr-actions wa-rise" style="--i:1">
        <button type="button" class="wa-btn ghost" data-copy>${icon('copy', 'sm')}<span>نسخ الرقم</span></button>
        <button type="button" class="wa-btn ghost" data-save${svg ? '' : ' disabled'}>${icon('download', 'sm')}<span>حفظ الصورة</span></button>
        ${navigator.share ? `<button type="button" class="wa-btn ghost" data-share>${icon('share', 'sm')}<span>مشاركة</span></button>` : ''}
      </div>

      <div class="wa-note wa-rise" style="--i:2">${icon('shield', 'sm')}<span>الرمز آمن للمشاركة: من يمسحه يستطيع فقط <b>التحويل إليك</b>، ولا يصل إلى رصيدك أو بياناتك.</span></div>`;

    $('[data-copy]', body).addEventListener('click', async () => {
      await copyText(number);
      toast('تم نسخ رقم المحفظة', 'copy');
    });
    $('[data-save]', body).addEventListener('click', () => { if (svg) savePng(svg, `dorr-wallet-${b.wallet_number}.png`); });
    $('[data-share]', body)?.addEventListener('click', () => {
      navigator.share({ title: 'محفظتي', text: `حوّل إلى محفظتي: ${number}` }).catch(() => {});
    });
  }

  function buildScanner(page) {
    const sc = { stream: null, timer: null, busy: false, closed: false };
    const body = pageShell(page, { title: 'مسح رمز QR' });
    const secure = !!(navigator.mediaDevices?.getUserMedia && window.isSecureContext);

    body.innerHTML = `
      <div class="wa-scan wa-rise" style="--i:0" data-frame>
        <video data-video playsinline muted></video>
        <div class="wa-scan-idle" data-idle>${icon('qr', 'lg')}<p>${secure ? 'جاري تشغيل الكاميرا…' : 'التقط صورة لرمز QR أو اخترها من المعرض'}</p></div>
        <i class="c tl"></i><i class="c tr"></i><i class="c bl"></i><i class="c br"></i>
        <span class="wa-scan-line"></span>
      </div>
      <p class="wa-scan-msg" data-msg>وجّه الكاميرا نحو رمز QR الخاص بمحفظة المستلم.</p>
      <div class="wa-err" data-error hidden></div>
      <div class="wa-qr-actions wa-rise" style="--i:1">
        <button type="button" class="wa-btn" data-cam>${icon('camera', 'sm')}<span>التقاط صورة للرمز</span></button>
        <button type="button" class="wa-btn ghost" data-pick>${icon('image', 'sm')}<span>اختيار من المعرض</span></button>
      </div>
      <input type="file" accept="image/*" capture="environment" hidden data-file-cam>
      <input type="file" accept="image/*" hidden data-file-pick>`;

    const frame = $('[data-frame]', body);
    const video = $('[data-video]', body);
    const idle = $('[data-idle]', body);
    const msg = $('[data-msg]', body);
    const errBox = $('[data-error]', body);
    const setError = (m) => { errBox.innerHTML = m ? `${icon('alert', 'sm')}<span>${escapeHtml(m)}</span>` : ''; errBox.hidden = !m; };

    function stopCamera() {
      clearInterval(sc.timer);
      sc.timer = null;
      sc.stream?.getTracks().forEach((t) => t.stop());
      sc.stream = null;
    }

    async function handleText(text) {
      if (sc.busy || sc.closed) return;
      sc.busy = true;
      stopCamera();
      setError('');
      frame.classList.add('checking');
      msg.textContent = 'جاري التحقق من الرمز…';
      const r = await api('/transfers/lookup', { method: 'POST', body: { mode: 'qr', qr: text } });
      if (sc.closed) return;
      frame.classList.remove('checking');
      if (ok(r)) {
        pop();
        push((p) => buildTransferConfirm(p, r.json.data));
        return;
      }
      sc.busy = false;
      msg.textContent = 'جرّب رمزاً آخر أو أعد المحاولة.';
      setError(r.json?.errors?.qr?.[0] || errMsg(r));
      if (secure) startCamera();
    }

    function scanFrame() {
      if (sc.busy || video.readyState < 2 || !video.videoWidth) return;
      const scale = Math.min(1, 640 / video.videoWidth);
      const w = Math.round(video.videoWidth * scale);
      const h = Math.round(video.videoHeight * scale);
      const canvas = sc.canvas || (sc.canvas = document.createElement('canvas'));
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(video, 0, 0, w, h);
      const code = window.jsQR?.(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' });
      if (code?.data) handleText(code.data);
    }

    async function startCamera() {
      if (!secure) return;
      try {
        sc.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        if (sc.closed) { stopCamera(); return; }
        video.srcObject = sc.stream;
        await video.play();
        frame.classList.add('live');
        idle.hidden = true;
        sc.timer = setInterval(scanFrame, 180);
      } catch {
        idle.hidden = false;
        $('p', idle).textContent = 'تعذّر تشغيل الكاميرا. التقط صورة للرمز أو اخترها من المعرض.';
      }
    }

    /** A photo of a QR code (camera app or gallery) → decode it exactly like a live frame. */
    function decodeFile(file) {
      if (!file) return;
      setError('');
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        const scale = Math.min(1, 1000 / Math.max(img.width, img.height));
        const w = Math.round(img.width * scale);
        const h = Math.round(img.height * scale);
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0, w, h);
        URL.revokeObjectURL(url);
        const code = window.jsQR?.(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'attemptBoth' });
        if (code?.data) handleText(code.data);
        else setError('لم نجد رمز QR في هذه الصورة. تأكد أن الرمز ظاهر بوضوح وحاول مرة أخرى.');
      };
      img.onerror = () => setError('تعذّر فتح الصورة.');
      img.src = url;
    }

    const cam = $('[data-file-cam]', body);
    const pick = $('[data-file-pick]', body);
    $('[data-cam]', body).addEventListener('click', () => cam.click());
    $('[data-pick]', body).addEventListener('click', () => pick.click());
    [cam, pick].forEach((input) => input.addEventListener('change', () => { decodeFile(input.files[0]); input.value = ''; }));

    startCamera();
    return { dispose() { sc.closed = true; stopCamera(); } };
  }

  // ==================================================================== transfer

  /*
   * Two screens, on purpose: (1) find the recipient — by phone number or by wallet
   * number — and (2) confirm *who* it is (masked name + the number that was typed)
   * and enter the amount. Money can only move from screen 2, using the token the
   * server issued at the lookup, so what is on screen is provably who gets paid.
   *
   * Transfers stay inside the wallet's own country: a Saudi wallet pays Saudi
   * numbers/wallets only, and the phone format below is the country's own.
   */

  const COUNTRY_NAMES = { SA: 'السعودية', EG: 'مصر', AE: 'الإمارات', KW: 'الكويت', QA: 'قطر', BH: 'البحرين', OM: 'عُمان', JO: 'الأردن' };
  const countryName = (code) => COUNTRY_NAMES[code] || code || '';

  // Same Luhn check as the server, so a mistyped wallet number is caught before any request.
  function walletNumberOk(digits) {
    if (!/^\d{11}$/.test(digits)) return false;
    let sum = 0;
    [...digits.slice(0, -1)].reverse().forEach((ch, i) => {
      let n = Number(ch);
      if (i % 2 === 0) { n *= 2; if (n > 9) n -= 9; }
      sum += n;
    });
    return (10 - (sum % 10)) % 10 === Number(digits.slice(-1));
  }

  const groupNumber = (digits) => [digits.slice(0, 3), digits.slice(3, 7), digits.slice(7, 11)].filter(Boolean).join(' ');

  const toLatinDigits = (text) => text.replace(/[٠-٩]/g, (ch) => String(ch.charCodeAt(0) - 1632)).replace(/[۰-۹]/g, (ch) => String(ch.charCodeAt(0) - 1776));

  /** What was typed/pasted → the national number, accepting "05…", "+9665…" and Arabic digits. */
  function nationalPhone(raw) {
    const b = state.balance || {};
    let d = toLatinDigits(raw).replace(/\D/g, '');
    const dial = String(b.dial_code || '').replace('+', '');
    const len = b.phone_length;
    if (len && dial && d.length === dial.length + len && d.startsWith(dial)) d = d.slice(dial.length);
    if (len && d.length === len + 1 && d.startsWith('0')) d = d.slice(1);
    return d;
  }

  /** 'empty' | 'typing' | 'ok' | 'bad' for the phone field, by the country's own rules. */
  function phoneState(d) {
    const b = state.balance || {};
    const len = b.phone_length;
    const prefix = b.phone_starts_with || '';
    if (!d) return 'empty';
    if (prefix && d.length >= prefix.length && !d.startsWith(prefix)) return 'bad';
    if (len && d.length > len) return 'bad';
    if (len && d.length < len) return 'typing';
    return 'ok';
  }

  function phoneHint() {
    const b = state.balance || {};
    const parts = [];
    if (b.phone_length) parts.push(`${b.phone_length} أرقام`);
    if (b.phone_starts_with) parts.push(`تبدأ بـ ${b.phone_starts_with}`);
    return parts.length ? `رقم جوال ${countryName(b.country_code)}: ${parts.join(' ')}` : `رقم جوال ${countryName(b.country_code)}`;
  }

  function buildTransfer(page) {
    const b = state.balance || {};
    const tr = { mode: 'phone', phone: '', wallet: '', busy: false };
    const body = pageShell(page, { title: 'تحويل' });
    body.classList.add('has-cta');
    const dial = escapeHtml(b.dial_code || '');
    const country = escapeHtml(countryName(b.country_code));

    body.innerHTML = `
      <div class="wa-tr-banner wa-rise" style="--i:0">
        <span class="wa-ico tone-blue">${icon('globe')}</span>
        <div><b>التحويل داخل ${country} فقط</b><small>محفظتك بعملة ${cur()}، لذلك تُرسل الأموال إلى أرقام ومحافظ ${country} فقط.</small></div>
      </div>

      <button type="button" class="wa-scan-cta wa-rise" style="--i:1" data-scan>
        <span class="wa-ico tone-red">${icon('qr')}</span>
        <span class="wa-scan-cta-txt"><b>امسح رمز QR</b><small>أسرع طريقة — وجّه الكاميرا نحو رمز محفظة المستلم</small></span>
        ${icon('chevron', 'sm flip')}
      </button>

      <div class="wa-or wa-rise" style="--i:1"><span>أو أدخل البيانات يدوياً</span></div>

      <div class="wa-seg wa-rise" style="--i:1" data-mode="phone" data-seg>
        <span class="wa-seg-ind"></span>
        <button type="button" class="on" data-m="phone">${icon('phone', 'sm')}برقم الهاتف</button>
        <button type="button" data-m="wallet">${icon('wallet', 'sm')}برقم المحفظة</button>
      </div>

      <div class="wa-card wa-field wa-rise" style="--i:2" data-pane="phone">
        <label for="tr-phone">رقم هاتف المستلم</label>
        <div class="wa-input" data-box>
          <span class="wa-cc" dir="ltr">${icon('phone', 'sm')}${dial}</span>
          <input id="tr-phone" type="tel" inputmode="numeric" dir="ltr" autocomplete="off" placeholder="${b.phone_starts_with ? escapeHtml(b.phone_starts_with) + 'X'.repeat(Math.max(0, (b.phone_length || 9) - b.phone_starts_with.length)) : '5XXXXXXXX'}">
          <span class="wa-state" data-state></span>
        </div>
        <p class="wa-hint" data-hint>${escapeHtml(phoneHint())}</p>
      </div>

      <div class="wa-card wa-field wa-rise" style="--i:2" data-pane="wallet" hidden>
        <label for="tr-wallet">رقم المحفظة</label>
        <div class="wa-input" data-box>
          <span class="wa-cc">${icon('wallet', 'sm')}</span>
          <input id="tr-wallet" type="text" inputmode="numeric" dir="ltr" autocomplete="off" placeholder="000 0000 0000">
          <span class="wa-state" data-state></span>
        </div>
        <p class="wa-hint" data-hint>رقم المحفظة 11 رقماً، يجده المستلم في شاشة محفظته. يصل المبلغ لمحفظته في ${country} تحديداً.</p>
      </div>

      <div class="wa-err" data-error hidden></div>`;

    const cta = document.createElement('div');
    cta.className = 'wa-cta';
    cta.innerHTML = `<button type="button" class="wa-btn" data-next disabled><span>التالي</span>${icon('chevron', 'sm flip')}</button><small>${icon('shield', 'sm')}ستراجع اسم المستلم قبل الإرسال</small>`;
    page.appendChild(cta);

    const seg = $('[data-seg]', body);
    $('[data-scan]', body).addEventListener('click', () => push(buildScanner));
    const next = $('[data-next]', cta);
    const errBox = $('[data-error]', body);
    const inputs = { phone: $('#tr-phone', body), wallet: $('#tr-wallet', body) };
    const setError = (m) => { errBox.innerHTML = m ? `${icon('alert', 'sm')}<span>${escapeHtml(m)}</span>` : ''; errBox.hidden = !m; };

    function paintField(mode, status, hint) {
      const pane = $(`[data-pane="${mode}"]`, body);
      const box = $('[data-box]', pane);
      box.classList.toggle('ok', status === 'ok');
      box.classList.toggle('bad', status === 'bad');
      $('[data-state]', pane).innerHTML = status === 'ok' ? icon('check', 'sm') : status === 'bad' ? icon('x', 'sm') : '';
      if (hint !== undefined) {
        const el = $('[data-hint]', pane);
        el.textContent = hint;
        el.classList.toggle('bad', status === 'bad');
      }
    }

    function refresh() {
      setError('');
      if (tr.mode === 'phone') {
        const d = nationalPhone(inputs.phone.value);
        const st = phoneState(d);
        tr.phone = d;
        paintField('phone', st, st === 'bad' ? `${phoneHint()} — الرقم المُدخل غير مطابق.` : phoneHint());
        next.disabled = st !== 'ok';
      } else {
        const digits = toLatinDigits(inputs.wallet.value).replace(/\D/g, '').slice(0, 11);
        tr.wallet = digits;
        const st = digits.length < 11 ? (digits ? 'typing' : 'empty') : (walletNumberOk(digits) ? 'ok' : 'bad');
        paintField('wallet', st, st === 'bad' ? 'رقم المحفظة غير صحيح. راجع الأرقام وحاول مرة أخرى.' : undefined);
        next.disabled = st !== 'ok';
      }
    }

    inputs.phone.addEventListener('input', () => {
      // Show what was understood: pasted "+966 5x…" becomes the national number.
      const d = nationalPhone(inputs.phone.value);
      if (d !== toLatinDigits(inputs.phone.value).replace(/\D/g, '')) inputs.phone.value = d;
      refresh();
    });
    inputs.wallet.addEventListener('input', () => {
      const digits = toLatinDigits(inputs.wallet.value).replace(/\D/g, '').slice(0, 11);
      inputs.wallet.value = groupNumber(digits);
      refresh();
    });

    $$('[data-m]', seg).forEach((btn) => btn.addEventListener('click', () => {
      tr.mode = btn.dataset.m;
      seg.dataset.mode = tr.mode;
      $$('[data-m]', seg).forEach((x) => x.classList.toggle('on', x === btn));
      $$('[data-pane]', body).forEach((pane) => { pane.hidden = pane.dataset.pane !== tr.mode; });
      inputs[tr.mode].focus();
      refresh();
    }));

    next.addEventListener('click', async () => {
      if (tr.busy) return;
      tr.busy = true;
      next.disabled = true;
      $('span', next).textContent = 'جاري البحث…';
      const r = await api('/transfers/lookup', {
        method: 'POST',
        body: tr.mode === 'phone' ? { mode: 'phone', phone: tr.phone } : { mode: 'wallet', wallet_number: tr.wallet },
      });
      tr.busy = false;
      $('span', next).textContent = 'التالي';
      next.disabled = false;
      if (ok(r)) return push((p) => buildTransferConfirm(p, r.json.data));
      const fieldErr = r.json?.errors?.phone?.[0] || r.json?.errors?.wallet_number?.[0];
      setError(fieldErr || errMsg(r));
    });

    setTimeout(() => inputs.phone.focus(), 400);
  }

  function buildTransferConfirm(page, who) {
    const b = state.balance || {};
    const tc = { amountText: '', idem: uuid(), done: null };
    const body = pageShell(page, { title: 'تأكيد التحويل' });
    body.classList.add('has-cta');
    const initial = escapeHtml((who.name_masked || '*').trim().charAt(0));
    const via = who.via === 'phone'
      ? `<div class="wa-rc-line">${icon('phone', 'sm')}<span dir="ltr">${escapeHtml(who.phone)}</span></div>`
      : `<div class="wa-rc-line">${icon('wallet', 'sm')}<span dir="ltr">${escapeHtml(groupNumber(who.wallet_number || ''))}</span></div>`;

    body.innerHTML = `
      <div class="wa-card wa-recipient wa-rise" style="--i:0">
        <span class="wa-verified">${icon('shield', 'sm')}تحقق من المستلم قبل الإرسال</span>
        <div class="wa-avatar"><b>${initial}</b><i></i></div>
        <h2 class="wa-rc-name">${escapeHtml(who.name_masked)}</h2>
        ${via}
        <div class="wa-rc-tags">
          <span class="wa-chip-glass dark">${icon('globe', 'sm')}${escapeHtml(countryName(who.country_code))}</span>
          <span class="wa-chip-glass dark">${icon('wallet', 'sm')}${escapeHtml(who.currency_code || '')}</span>
        </div>
      </div>

      <div class="wa-card wa-amount wa-rise" style="--i:1">
        <label for="tr-amount">كم تريد أن تحوّل؟</label>
        <div class="wa-amount-row" dir="ltr">
          <input id="tr-amount" type="text" inputmode="decimal" placeholder="0.00" autocomplete="off">
          <span class="wa-amount-cur">${cur()}</span>
        </div>
        <div class="wa-avail">${icon('wallet', 'sm')}المتاح لديك: <b dir="ltr">${fmt(b.withdrawable_minor + b.spend_only_minor - b.held_minor)} ${cur()}</b></div>
        <div class="wa-err" data-amt-err hidden>${icon('info', 'sm')}<span>أدخل مبلغاً بحد أقصى منزلتين عشريتين</span></div>
      </div>

      <div class="wa-note wa-rise" style="--i:2">${icon('info', 'sm')}<span>المبلغ المُحوَّل يصل للمستلم <b>للخدمات فقط</b> — يستخدمه في الخدمات لكن لا يستطيع سحبه.</span></div>
      <div class="wa-err" data-error hidden></div>`;

    const cta = document.createElement('div');
    cta.className = 'wa-cta';
    cta.innerHTML = `<button type="button" class="wa-btn" data-send disabled>${icon('send', 'sm')}<span>إرسال</span></button><small>${icon('shield', 'sm')}محمي بالرقم السري</small>`;
    page.appendChild(cta);

    const amount = $('#tr-amount', body);
    const send = $('[data-send]', cta);
    const setError = (m) => { const el = $('[data-error]', body); if (!el) return; el.innerHTML = m ? `${icon('alert', 'sm')}<span>${escapeHtml(m)}</span>` : ''; el.hidden = !m; };

    amount.addEventListener('input', () => {
      amount.value = amount.value.replace(/[^\d.,]/g, '');
      tc.amountText = amount.value;
      const minor = parseAmount(tc.amountText);
      $('[data-amt-err]', body).hidden = tc.amountText === '' || minor !== null;
      send.disabled = minor === null;
      $('span', send).innerHTML = minor !== null ? `إرسال <bdi>${fmt(minor)} ${cur()}</bdi>` : 'إرسال';
    });

    send.addEventListener('click', async () => {
      setError('');
      const pinStatus = await api('/pin');
      if (!ok(pinStatus)) return setError(errMsg(pinStatus));
      openPinSheet({
        hasPin: pinStatus.json.data.has_pin,
        subtitle: 'لتأكيد التحويل',
        onClose: setError,
        onSubmit: async (pin) => {
          const r = await api('/transfers', {
            method: 'POST', pin, idem: tc.idem,
            body: { recipient_token: who.recipient_token, amount_minor: parseAmount(tc.amountText) },
          });
          if (ok(r)) {
            tc.idem = uuid();
            tc.done = r.json.data;
            refreshBalance();
            showDone();
            return { type: 'done' };
          }
          // No HTTP answer: keep the key so a retry can't send twice.
          if (r.status === 0) return { type: 'retry', message: errMsg(r) };
          tc.idem = uuid();
          if (PIN_CODES.includes(r.json?.error_code)) return { type: 'retry', message: errMsg(r) };
          if (r.json?.error_code === 'transfer_recipient_expired') {
            toast('انتهت صلاحية التحقق، ابحث عن المستلم مرة أخرى', 'alert');
            setTimeout(pop, 300);
            return { type: 'done' };
          }
          return { type: 'close', message: errMsg(r) };
        },
      });
    });

    function showDone() {
      const tx = tc.done;
      body.classList.remove('has-cta');
      body.classList.add('fill');
      cta.remove();
      body.innerHTML = `
        <div class="wa-status">
          <div class="wa-confetti">${Array.from({ length: 22 }, () => `<i style="--x:${Math.round((Math.random() - 0.5) * 280)}px;--r:${Math.round(Math.random() * 600 - 300)}deg;--d:${(Math.random() * 0.3).toFixed(2)}s;--c:${['#E50914', '#2563EB', '#F59E0B', '#16A34A'][Math.floor(Math.random() * 4)]}"></i>`).join('')}</div>
          <div class="wa-seal"><svg viewBox="0 0 104 104"><circle class="ring" cx="52" cy="52" r="46"/><circle class="disc" cx="52" cy="52" r="46"/><path class="mark" d="M34 54l13 13 24-26"/></svg></div>
          <h2>تم التحويل</h2>
          <div class="amount-big" dir="ltr">${fmt(tx.amount_minor)} <small>${cur()}</small></div>
          <p>إلى <b>${escapeHtml(who.name_masked)}</b></p>
          <button type="button" class="wa-btn" data-done>تم</button>
        </div>`;
      // Back to the wallet home: this confirm page and the recipient page both go.
      $('[data-done]', body).addEventListener('click', () => { pop(); pop(); });
    }

    setTimeout(() => amount.focus(), 400);
  }

  // ==================================================================== history

  const FILTERS = [['all', 'الكل', 'list'], ['credit', 'وارد', 'arrow-in'], ['debit', 'صادر', 'send'], ['spend_only', 'للخدمات فقط', 'gift']];

  function buildHistory(page) {
    const hs = { filter: 'all', rows: [], page: 1, more: false, loading: false, error: null, seq: 0 };
    const body = pageShell(page, { title: 'كشف الحساب' });
    body.style.padding = '0';
    body.innerHTML = `
      <div class="wa-filters">${FILTERS.map(([key, label, ic]) => `<button type="button" class="wa-chip${key === 'all' ? ' on' : ''}" data-f="${key}">${icon(ic, 'sm')}${label}</button>`).join('')}</div>
      <div style="padding:0 16px 28px"><div data-list>${skeletonRows(5)}</div><button type="button" class="wa-btn ghost" data-more hidden style="margin-top:12px">عرض المزيد</button></div>`;
    const list = $('[data-list]', body);
    const more = $('[data-more]', body);

    function query(pageNo) {
      const q = new URLSearchParams({ per_page: '15', page: String(pageNo) });
      if (hs.filter === 'credit' || hs.filter === 'debit') q.set('direction', hs.filter);
      if (hs.filter === 'spend_only') q.set('bucket', 'spend_only');
      return `/transactions?${q}`;
    }

    function paint() {
      if (hs.error) { list.innerHTML = `<div class="wa-err">${icon('alert', 'sm')}<span>${escapeHtml(hs.error)}</span></div>`; more.hidden = true; return; }
      if (!hs.rows.length && !hs.loading) {
        list.innerHTML = `<div class="wa-empty wa-card"><span class="wa-ico tone-gray">${icon('history', 'lg')}</span><h4>لا توجد حركات</h4><p>ستظهر هنا كل عملية تجريها على محفظتك.</p></div>`;
        more.hidden = true;
        return;
      }
      let lastDay = '';
      let html = '';
      let open = false;
      hs.rows.forEach((tx) => {
        const day = dayLabel(tx.created_at);
        if (day !== lastDay) {
          if (open) html += '</div>';
          html += `<div class="wa-day">${day}</div><div class="wa-card wa-list">`;
          open = true;
          lastDay = day;
        }
        html += txRowMarkup(tx, true);
      });
      if (open) html += '</div>';
      list.innerHTML = html;
      bindTxRows(list, hs.rows);
      more.hidden = !hs.more;
    }

    async function load(reset) {
      if (reset) Object.assign(hs, { rows: [], page: 1, more: false, error: null });
      const seq = ++hs.seq;
      hs.loading = true;
      if (reset) list.innerHTML = skeletonRows(5);
      more.disabled = true;
      const r = await api(query(hs.page));
      if (seq !== hs.seq || !body.isConnected) return; // filter changed meanwhile
      hs.loading = false;
      more.disabled = false;
      if (!ok(r)) hs.error = errMsg(r);
      else { hs.rows = hs.rows.concat(r.json.data); hs.more = Boolean(r.json.pagination?.has_more_pages); }
      paint();
    }

    $$('[data-f]', body).forEach((chip) => chip.addEventListener('click', () => {
      hs.filter = chip.dataset.f;
      $$('[data-f]', body).forEach((c) => c.classList.toggle('on', c === chip));
      load(true);
    }));
    more.addEventListener('click', () => { hs.page += 1; load(false); });

    (async () => { if (!state.balance) await refreshBalance(); load(true); })();
  }

  // ==================================================================== PIN gate

  /*
   * First thing shown when the wallet is entered. With a PIN: enter it. Without
   * one: create it (enter + confirm) right here — no detour. Either way the wallet
   * opens only after this succeeds.
   */
  function buildGate(page, view) {
    const body = pageShell(page, { title: 'المحفظة' });
    body.classList.add('fill');
    body.innerHTML = '<div class="wa-pinpage"><div class="wa-sk" style="height:300px;border-radius:24px"></div></div>';
    let pad;

    function unlock() {
      unlocked = true;
      // Swap the gate for the real pages without an animation of its own.
      const gate = stack.pop();
      gate.dispose?.();
      gate.el.remove();
      showView(view);
    }

    async function start() {
      body.innerHTML = '<div class="wa-pinpage"><div class="wa-sk" style="height:300px;border-radius:24px"></div></div>';
      const status = await api('/pin');
      if (!body.isConnected) return;
      if (!ok(status)) {
        body.innerHTML = `<div class="wa-status"><span class="wa-ico tone-gray" style="width:74px;height:74px;border-radius:26px">${icon('alert', 'lg')}</span><h2>تعذّر التحميل</h2><p>${escapeHtml(errMsg(status))}</p><button type="button" class="wa-btn ghost" data-retry>${icon('refresh', 'sm')}حاول مرة أخرى</button></div>`;
        $('[data-retry]', body).addEventListener('click', start);
        return;
      }

      const hasPin = status.json.data.has_pin;
      let step = hasPin ? 'enter' : 'create';
      let first = '';
      const texts = {
        enter: ['أدخل الرقم السري', 'لفتح محفظتك'],
        create: ['أنشئ الرقم السري لمحفظتك', '4 أرقام تحمي كل عملية دفع'],
        confirm: ['أكّد الرقم السري', 'أعد إدخال نفس الأرقام'],
      };

      body.innerHTML = `<div class="wa-pinpage wa-rise">${pinPadMarkup({ ic: 'lock', tone: 'red', title: texts[step][0], sub: texts[step][1] })}</div>`;
      pad = mountPinPad(body, async (pin) => {
        if (step === 'enter') {
          const r = await api('/pin/verify', { method: 'POST', pin });
          if (!ok(r)) return { error: errMsg(r) };
          unlock();
          return {};
        }
        if (step === 'create') {
          first = pin;
          step = 'confirm';
          pad.setText(...texts.confirm);
          return { reset: true };
        }
        if (pin !== first) {
          step = 'create';
          pad.setText(...texts.create);
          return { error: 'الرقمان غير متطابقين. حاول من جديد.' };
        }
        const created = await api('/pin', { method: 'POST', body: { pin, pin_confirmation: pin } });
        if (!ok(created)) {
          step = 'create';
          pad.setText(...texts.create);
          return { error: errMsg(created) };
        }
        unlock();
        return {};
      });
    }

    start();
    return { dispose() { pad?.dispose(); } };
  }

  // ================================================================= PIN settings

  function buildPin(page) {
    const body = pageShell(page, { title: 'الرقم السري للمحفظة' });
    body.classList.add('fill');
    body.innerHTML = '<div class="wa-pinpage"><div class="wa-sk" style="height:300px;border-radius:24px"></div></div>';
    let pad;

    (async () => {
      const status = await api('/pin');
      if (!body.isConnected) return;
      if (!ok(status)) {
        body.innerHTML = `<div class="wa-status"><span class="wa-ico tone-gray" style="width:74px;height:74px;border-radius:26px">${icon('alert', 'lg')}</span><h2>تعذّر التحميل</h2><p>${escapeHtml(errMsg(status))}</p></div>`;
        return;
      }
      const changing = status.json.data.has_pin;
      const steps = changing ? ['current', 'new', 'confirm'] : ['new', 'confirm'];
      const texts = {
        current: ['أدخل رقمك السري الحالي', 'للتحقق من هويتك'],
        new: [changing ? 'اختر رقماً سرياً جديداً' : 'أنشئ رقمك السري', '4 أرقام تحمي كل عملية دفع'],
        confirm: ['أكّد الرقم السري', 'أعد إدخال نفس الأرقام'],
      };
      let i = 0;
      const values = {};

      body.innerHTML = `<div class="wa-pinpage wa-rise">${pinPadMarkup({ ic: 'shield', tone: 'red', title: texts[steps[0]][0], sub: texts[steps[0]][1] })}</div>`;
      pad = mountPinPad(body, async (pin) => {
        const step = steps[i];
        values[step] = pin;
        if (step !== 'confirm') {
          i += 1;
          pad.setText(...texts[steps[i]]);
          return { reset: true };
        }
        if (values.new !== values.confirm) {
          i = steps.indexOf('new');
          pad.setText(...texts.new);
          return { error: 'الرقمان غير متطابقين. حاول من جديد.' };
        }
        const r = changing
          ? await api('/pin', { method: 'PUT', body: { current_pin: values.current, pin: values.new, pin_confirmation: values.confirm } })
          : await api('/pin', { method: 'POST', body: { pin: values.new, pin_confirmation: values.confirm } });
        if (!ok(r)) {
          // A wrong current PIN sends the user back to the start; anything else retries the last step.
          i = 0;
          pad.setText(...texts[steps[0]]);
          return { error: errMsg(r) };
        }
        body.innerHTML = `
          <div class="wa-status">
            <div class="wa-seal"><svg viewBox="0 0 104 104"><circle class="ring" cx="52" cy="52" r="46"/><circle class="disc" cx="52" cy="52" r="46"/><path class="mark" d="M34 54l13 13 24-26"/></svg></div>
            <h2>تم حفظ الرقم السري</h2>
            <p>سيُطلب منك عند كل عملية دفع أو تحويل.</p>
            <button type="button" class="wa-btn" data-done>تم</button>
          </div>`;
        $('[data-done]', body).addEventListener('click', pop);
        return {};
      });
    })();

    return { dispose() { pad?.dispose(); } };
  }

  // ==================================================================== wiring

  function open(view) {
    if (!authToken) { ensureShell(); }
    openApp(view);
  }

  // The bottom bar stays visible under the wallet: tapping any tab leaves the wallet (and locks it).
  $$('.tab-btn, .fab-btn').forEach((b) => b.addEventListener('click', () => { if (root?.classList.contains('open')) closeApp(); }));

  window.openWalletApp = open;
  window.walletOnLogin = () => { state.balance = null; state.shown = 0; cardShown = 0; paintCard(); refreshBalance(); };
  window.walletOnLogout = () => { state.balance = null; state.shown = 0; paintCard(); if (root) closeApp(); };

  $('#wallet-add-btn').addEventListener('click', (event) => { event.stopPropagation(); open('topup'); });
  $('#wallet-send-btn').addEventListener('click', (event) => { event.stopPropagation(); open('transfer'); });
  $('#home-wallet-card').addEventListener('click', () => open('home'));
  $('.wallet-card').addEventListener('click', () => open('home'));
  $('#home-wallet-btn').addEventListener('click', () => open('home'));
  $$('.tab-btn[data-tab="account"], .tab-btn[data-tab="home"]').forEach((b) => b.addEventListener('click', refreshBalance));
})();
