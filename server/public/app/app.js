/* Tiempo web interface. No build step: runs on any PHP hosting. Talks to /api. */
(() => {
/* The site may live in a folder of a shared hosting (https://domain.com/timesheet/app/): the API is next to /app/, wherever it is. */
const BASE = location.pathname.replace(/\/app(\/.*)?$/, '');
const $app = document.getElementById('app');
const S = { token: sessionStorage.getItem('tt') || localStorage.getItem('tt'), me: null, login: null, flash: null, weekDate: today(), cache: {},
  lang: localStorage.getItem('lang') || ((navigator.language || 'en').toLowerCase().startsWith('es') ? 'es' : 'en') };
const LANG = () => (S.me && S.me.locale) || S.lang;
/* Translation: the screens are written in English; i18n-es.js holds the Spanish. T() translates one text; localizeHtml() the text nodes and
   placeholder/title attributes of a whole screen. Texts that are not in the dictionary (names, numbers) are left as they are. */
function T(s) {
  if (LANG() !== 'es' || !window.I18N_ES) return s;
  const d = window.I18N_ES;
  if (Object.prototype.hasOwnProperty.call(d.exact, s)) return d.exact[s];
  for (const [re, rep] of d.patterns) if (re.test(s)) return s.replace(re, rep);
  return s;
}
const unesc = s => String(s).replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
function localizeHtml(html) {
  if (LANG() !== 'es' || !window.I18N_ES) return html;
  return html.replace(/>([^<>]+)</g, (m, txt) => {
    const core = txt.trim(); if (!core) return m;
    const tr = T(unesc(core));
    if (tr === unesc(core)) { if (/[A-Za-z]{3}/.test(core)) (window.__missing ??= new Set()).add(core); return m; }
    return '>' + txt.match(/^\s*/)[0] + esc(tr) + txt.match(/\s*$/)[0] + '<';
  }).replace(/\b(placeholder|title|aria-label)="([^"]*)"/g, (m, a, v) => a + '="' + esc(T(unesc(v))) + '"');
}
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = n => '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const hrs = n => Number(n || 0).toFixed(2);
const h1 = n => { const v = Number(n || 0); return Number.isInteger(v) ? String(v) : String(Math.round(v * 100) / 100); };

function today() { const d = new Date(); return fmt(d); }
function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function parse(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); }
function addDays(s, n) { const d = parse(s); d.setDate(d.getDate() + n); return fmt(d); }
/* The week starts on the day the company chose in Rules (0 = Sunday, 1 = Monday) and lasts 7 days. */
const weekStartDow = () => (Number(S.me?.rules?.week_starts_on) === 1 ? 1 : 0);
function weekEnd(s) { const d = parse(s); const endDow = (weekStartDow() + 6) % 7; d.setDate(d.getDate() + ((endDow - d.getDay() + 7) % 7)); return fmt(d); }
const DOWS = { en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], es: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] };
const dow = i => DOWS[LANG() === 'es' ? 'es' : 'en'][i];
const LOC = () => (LANG() === 'es' ? 'es-US' : 'en-US');
const dayLabel = s => dow(parse(s).getDay()) + ' ' + s.slice(5);
const nice = s => parse(s).toLocaleDateString(LOC(), { month: 'short', day: 'numeric', year: 'numeric' });
const longDay = s => parse(s).toLocaleDateString(LOC(), { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
const STATUS = { draft: 'Draft', submitted: 'Submitted', approved: 'Approved', returned: 'Returned' };
/* Status pills in tables, like the mockup: gray, amber, green, red, blue. */
const pill = (text, color = 'gray') => `<span class="bd ${color}">${esc(text)}</span>`;
const accountPill = s => ({ pending: pill('Invited', 'amber'), active: pill('Active', 'green'), disabled: pill('Disabled', 'red') }[s] || pill('None'));
const activePill = on => (on ? pill('Active', 'green') : pill('Inactive'));

async function api(path, o = {}) {
  const headers = { Accept: 'application/json', 'X-Locale': LANG() };
  if (S.token && !o.anon) headers.Authorization = 'Bearer ' + S.token;
  let body;
  if (o.body instanceof FormData) body = o.body;
  else if (o.body !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(o.body); }
  const r = await fetch(BASE + '/api' + path, { method: o.method || 'GET', headers, body });
  if (o.blob) { if (!r.ok) throw new Error('The file could not be downloaded'); return r; }
  const data = await r.json().catch(() => ({}));
  if (r.status === 401 && S.token && !o.anon) { logout('Your session expired. Sign in again.'); throw new Error('Session expired'); }
  if (!r.ok) {
    const first = data.errors ? Object.values(data.errors).flat()[0] : null;
    const e = new Error(first || data.message || 'Error ' + r.status); e.status = r.status; e.errors = data.errors; e.code = data.code; e.data = data; e.needs_code = !!data.needs_code; throw e;
  }
  return data;
}
/* Table filters. What you see filtered on screen is exactly what gets exported. */
const fold = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const matchQ = (q, fields) => { const hay = fold(fields.join(' ')); return fold(q).split(/\s+/).filter(Boolean).every(w => hay.includes(w)); };
const FL = view => ((S.cache.f ??= {})[view] ??= {});
const activeF = view => Object.fromEntries(Object.entries(FL(view)).filter(([, v]) => v !== '' && v != null));
const flag = v => (v === '1' ? true : v === '0' ? false : null);
const sameFlag = (v, actual) => flag(v) === null || Boolean(actual) === flag(v);
const YN = (a, b) => [['', 'All'], ['1', a], ['0', b]];
function filterBar(view, defs) {
  const F = FL(view);
  const ctl = d => d.type === 'select'
    ? `<select data-filter="${view}" name="${d.k}">${d.opts.map(([v, l]) => `<option value="${esc(v)}" ${String(F[d.k] ?? '') === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`
    : `<input data-filter="${view}" name="${d.k}" type="${d.type || 'text'}" value="${esc(F[d.k] ?? '')}" placeholder="${esc(d.ph || '')}">`;
  const on = Object.keys(activeF(view)).length;
  return `<div class="fbar">${defs.map(d => `<div><label>${d.label}</label>${ctl(d)}</div>`).join('')}
    <button class="btn ghost sm" type="button" data-act="f-clear" data-view="${view}" ${on ? '' : 'disabled'}>Clear filters</button></div>`;
}
/** Buttons to export any table to CSV, Excel or PDF (Excel and PDF carry the company logo and colors). */
function exportBar(ds, q = {}) {
  const j = esc(JSON.stringify(q));
  return `<span class="xbar" title="Export this table">${[['csv', 'CSV'], ['xlsx', 'Excel'], ['pdf', 'PDF']].map(([f, l]) => `<button class="btn ghost sm" type="button" data-act="tx" data-ds="${ds}" data-fmt="${f}" data-q="${j}">${l}</button>`).join('')}</span>`;
}
function flash(text, kind = 'ok') { S.flash = { text, kind }; }
/* Where the session lives depends on the rule "Remember for (days)": 0 = only while the browser stays open (sessionStorage), otherwise localStorage. */
function keepSession(days) {
  try {
    if (Number(days) === 0) { sessionStorage.setItem('tt', S.token); localStorage.removeItem('tt'); localStorage.removeItem('dt'); }
    else { localStorage.setItem('tt', S.token); sessionStorage.removeItem('tt'); if (S.cache.newDt) localStorage.setItem('dt', S.cache.newDt); }
    S.cache.newDt = null;
  } catch { /* storage blocked */ }
}
function logout(msg) {
  S.token = null; S.me = null; S.cache.newDt = null;
  try { localStorage.removeItem('tt'); sessionStorage.removeItem('tt'); } catch { /* storage blocked */ }
  S.cache.sessionMsg = typeof msg === 'string' ? msg : null;
  location.hash = ''; render();
}
function applyBrand(b) {
  if (!b) return;
  const r = document.documentElement.style;
  if (b.color_primary) r.setProperty('--primary', b.color_primary);
  if (b.color_accent) r.setProperty('--accent', b.color_accent);
  if (b.name) document.title = b.name;
}
function formObj(form) {
  const o = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled) continue;
    if (el.type === 'checkbox') { if (el.dataset.multi) { (o[el.name] ??= []); if (el.checked) o[el.name].push(Number(el.value)); } else o[el.name] = el.checked; }
    else if (el.type === 'file') { if (el.files[0]) o[el.name] = el.files[0]; }
    else if (el.type === 'number') o[el.name] = el.value === '' ? null : Number(el.value);
    else o[el.name] = el.value === '' ? null : el.value;
  }
  return o;
}
async function download(path, opts, fallbackName) {
  const r = await api(path, { ...opts, blob: true });
  const cd = r.headers.get('Content-Disposition') || '';
  const name = (cd.match(/filename="?([^";]+)"?/) || [])[1] || fallbackName;
  const url = URL.createObjectURL(await r.blob());
  const a = document.createElement('a'); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
  return { rows: r.headers.get('X-Export-Rows') };
}

/* ---------- shell: header, tabs, page ---------- */
const ROUTES = {
  week: { t: 'Timesheet', f: weekView },
  weeks: { t: 'All weeks', f: weeksView },
  approvals: { t: 'Approvals', owner: true, f: reviewScreen },
  invoices: { t: 'Invoices', f: invoicesView },
  reports: { t: 'Hours report', f: reportsView },
  clientsheet: { t: 'Client timesheet', owner: true, f: clientSheetView },
  export: { t: 'Export', f: exportView },
  people: { t: 'People', owner: true, f: peopleScreen },
  approvers: { t: 'Approvers', owner: true, f: approversView },
  customers: { t: 'Clients', owner: true, f: customersView },
  projects: { t: 'Projects', owner: true, f: projectsView },
  rules: { t: 'Rules', owner: true, f: rulesView },
  brand: { t: 'Branding', owner: true, f: brandView },
  integrations: { t: 'Integration', owner: true, f: integrationsView },
  account: { t: 'My account', f: accountView },
};
/* Top tabs: related screens share one tab and show a second row of pills inside the page. */
const GROUPS = [
  { t: 'Timesheet', r: ['week', 'weeks'] },
  { t: 'Approvals', r: ['approvals'] },
  { t: 'Invoices', r: ['invoices'] },
  { t: 'Reports', r: ['reports', 'clientsheet', 'export'] },
  { t: 'People & projects', r: ['people', 'approvers', 'customers', 'projects'] },
  { t: 'Settings', r: ['rules', 'brand', 'integrations', 'account'] },
];
const route = () => (location.hash.replace('#/', '') || 'week').split('?')[0];
const isAp = () => S.me?.role === 'approver';
/* The approver (an outside person with an account) only has Approvals, plus My account to change the password. */
const isIn = () => !!S.me?.internal_approver && S.me.role !== 'owner' && !isAp();   // an employee who approves hours internally
const canSee = k => ROUTES[k] && (isAp() ? k === 'approvals' || k === 'account' : !ROUTES[k].owner || S.me.role === 'owner' || (k === 'approvals' && isIn()));
const home = () => (isAp() ? 'approvals' : 'week');
const roleLabel = u => (u.role === 'approver' ? 'Approver' : u.role === 'owner' ? 'Owner' : u.worker_type === 'employee' ? 'Employee' : 'Consultant');
const initials = n => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
let RID = 0, LAST = null;

function shell(name, body, withFlash) {
  const b = S.me.brand || { name: 'Tiempo' }; const solo = !!b.logo_url && b.show_name === false;
  const groups = GROUPS.map(g => ({ ...g, vis: g.r.filter(canSee) })).filter(g => g.vis.length).map(g => (g.vis.length === 1 && g.vis[0] === 'account' ? { ...g, t: 'My account' } : g));
  const cur = groups.find(g => g.vis.includes(name));
  const fl = '';   // messages are floating toasts now (see toast()), they no longer push the page around
  if (withFlash && S.flash) { const f = S.flash; S.flash = null; setTimeout(() => toast(f.text, f.kind), 0); }
  const sub = cur && cur.vis.length > 1 ? `<div class="subtabs">${cur.vis.map(k => `<a href="#/${k}" class="${k === name ? 'on' : ''}">${ROUTES[k].t}</a>`).join('')}</div>` : '';
  return `<header class="hdr"><div class="brand ${solo ? 'solo' : ''}">${b.logo_url ? `<img src="${esc(b.logo_url)}" alt="${esc(b.name)}">${solo ? '' : '<span class="sep"></span>'}` : ''}${solo ? '' : `<div><b>${esc(b.name)}</b><small>Timesheets &amp; invoices</small></div>`}</div>
    <span class="spacer"></span><div class="who"><span class="av">${esc(initials(S.me.name))}</span><div><b>${esc(S.me.name)}</b><small>${roleLabel(S.me)}</small></div></div>
    <button class="btn ghost sm" data-act="lang" data-to="${LANG() === 'es' ? 'en' : 'es'}" title="Language">${LANG() === 'es' ? 'English' : 'Español'}</button>
    <button class="btn ghost sm" data-act="logout">Sign out</button></header>
    <div class="stripe"></div>
    <nav class="tabs">${groups.map(g => `<a href="#/${g.vis[0]}" class="${g === cur ? 'on' : ''}">${g.t}</a>`).join('')}</nav>
    <main>${sub}${fl}${body}</main>`;
}
const setApp = html => { $app.innerHTML = localizeHtml(html); afterRender(); };

async function render() {
  const sp = location.hash.match(/^#\/set-password\/([^?\/]+)/);
  if (sp) { RID++; LAST = null; return setPasswordView(decodeURIComponent(sp[1])); }
  if (!S.token) return loginView();
  const rid = ++RID;
  if (!$app.firstChild) $app.innerHTML = '<div class="loading">' + T('Loading…') + '</div>';   // never a blank page while the first data arrives
  try {
    if (!S.me) { S.me = await api('/me'); S.lang = S.me.locale; localStorage.setItem('lang', S.lang); keepSession(S.me.remember_days); }
    if (rid !== RID) return;
    applyBrand(S.me.brand);
    const name = canSee(route()) ? route() : home();
    // The page frame appears right away; the data fills in when it arrives.
    if (name !== LAST || !$app.querySelector('main')) setApp(shell(name, '<div class="loading">Loading…</div>', false));
    LAST = name;
    const body = await ROUTES[name].f();
    if (rid !== RID) return;
    setApp(shell(name, body, true));
  } catch (e) {
    if (S.token && rid === RID) { setApp(`<main><div class="msg err">${esc(e.message)}</div></main>`); }
  }
}

/* ---------- password field: ONE component for every password input (eye button, Caps Lock notice, strength bar) ----------
   The eye only shows what the person typed in this field; a saved password never reaches the browser. */
const EYE = '<svg class="on" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
  + '<svg class="off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.7 8.4 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.4 4.5-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
function pwField(name, label, o = {}) {
  const id = 'pw-' + name;
  return `<div class="pwf"><label for="${id}">${label}</label><div class="pw"><input id="${id}" name="${name}" type="password" ${o.optional ? '' : 'required'} ${o.min ? `minlength="${o.min}"` : ''} autocomplete="${o.auto || 'current-password'}" ${o.ph ? `placeholder="${o.ph}"` : ''} ${o.strength ? 'data-strength="1"' : ''} spellcheck="false" autocapitalize="none" autocorrect="off">
    <button class="eye" type="button" data-act="pw-eye" aria-label="Show password" title="Show password" aria-pressed="false">${EYE}</button></div>
    <small class="caps" role="status" hidden>Caps Lock is on</small>${o.strength ? '<div class="strength" data-level="0"><span class="bar"><i></i></span><small>Minimum 10 characters</small></div>' : ''}</div>`;
}
function pwStrength(v) {
  if (!v) return [0, 'Minimum 10 characters'];
  if (v.length < 10) return [1, 'Too short'];
  const k = (/[a-z]/.test(v) && /[A-Z]/.test(v) ? 1 : 0) + (/\d/.test(v) ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0) + (v.length >= 14 ? 1 : 0);
  return k >= 3 ? [4, 'Strong'] : k >= 2 ? [3, 'Good'] : [2, 'Fair'];
}

/* ---------- sign in ---------- */
const authBand = b => (b ? (b.logo_url && b.show_name === false ? `<div class="loginband solo"><span class="logo"><img src="${esc(b.logo_url)}" alt="${esc(b.name)}"></span></div>` : `<div class="loginband">${b.logo_url ? `<span class="logo"><img src="${esc(b.logo_url)}" alt="${esc(b.name)}"></span>` : `<b>${esc(b.name)}</b>`}<small>Timesheets &amp; invoices</small></div>`) : '<div class="stripe"></div>');
const langBtn = () => `<p style="text-align:center;margin:14px 0 0"><button class="btn ghost sm" type="button" data-act="lang" data-to="${LANG() === 'es' ? 'en' : 'es'}">${LANG() === 'es' ? 'English' : 'Español'}</button></p>`;
const resendLeft = () => Math.max(0, Math.ceil(((S.login?.resendAt || 0) - Date.now()) / 1000));
async function loadPub() {
  if (S.cache.pub === undefined) {
    try { const r = await fetch(BASE + '/api/public-config'); if (!r.ok) return {}; S.cache.pub = await r.json(); } catch { return {}; }
    if (S.cache.pub.locale && !localStorage.getItem('lang')) S.lang = S.cache.pub.locale;
  }
  return S.cache.pub;
}
function loginError(e) {
  const m = String(e.message || '');
  if (e.status === 429 || e.code === 'locked') return 'Too many failed attempts. This account is locked for a while. Try again later or call your administrator.';
  if (e.code === 'turnstile' || e.errors?.turnstile_token || /turnstile|captcha|security check/i.test(m)) return 'The security check was not completed. Complete it and try again.';
  if (e.status === 401 || e.code === 'invalid_credentials') return 'The email or password is not correct.';
  return m || 'Could not sign in. Try again.';
}
/* Sign in. If the server has Cloudflare Turnstile keys, the anti-bot challenge is shown first. An approver then types a 6-digit code sent by email. */
async function loginView(err, info) {
  LAST = null;
  if (!err && !info && S.cache.sessionMsg) { err = S.cache.sessionMsg; S.cache.sessionMsg = null; }
  const pub = await loadPub(); const brand = pub.brand || null;
  applyBrand(brand);
  const key = pub.turnstile_site_key; const L = S.login;
  const msg = (err ? `<div class="msg err" role="alert">${esc(T(err))}</div>` : '') + (info ? `<div class="msg ok" role="status">${esc(info)}</div>` : '');
  const eyebrow = brand ? '' : '<div class="eyebrow">Tiempo · Timesheets &amp; invoices</div>';
  let inner;
  if (L && L.step === 'code') {
    const n = Number(L.remember_days || 0);
    inner = `<h1>Check your email</h1><p class="sub">Type the 6-digit code we sent to ${esc(L.email_hint)}</p>${msg}
      <form data-form="login-code"><p><label for="login-code">6-digit code</label><input id="login-code" name="code" required inputmode="numeric" pattern="[0-9]{6}" title="6 digits" maxlength="6" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" style="font-size:22px;letter-spacing:.3em;text-align:center"></p>
      ${n > 0 ? `<p><label class="chk"><input type="checkbox" name="remember"> ${n === 1 ? 'Remember this device for 1 day' : `Remember this device for ${n} days`}</label></p>` : ''}
      <button class="btn pri" type="submit">Verify and sign in</button></form>
      <p class="row" style="justify-content:center;margin:14px 0 0"><button class="btn ghost sm" type="button" id="resend" data-act="code-resend" disabled>Resend code</button><button class="btn ghost sm" type="button" data-act="login-back">Use a different account</button></p>
      <p class="muted" style="margin:14px 0 0">The code is valid for a few minutes. If it does not arrive, check your spam folder.</p>`;
  } else {
    inner = `${eyebrow}<h1>Sign in</h1><p class="sub">Use the email address your invitation was sent to.</p>${msg}
      <form data-form="login"><p><label for="login-email">Email</label><input id="login-email" name="email" type="email" required autocomplete="username" placeholder="name@company.com" value="${esc(S.cache.loginEmail || '')}"></p>
      <div style="margin:0 0 14px">${pwField('password', 'Password', { auto: 'current-password', ph: 'Your password' })}</div>
      ${key ? '<div id="ts-box" style="margin:8px 0"></div>' : ''}
      <button class="btn pri" type="submit">Continue</button></form>
      <p class="muted" style="margin:14px 0 0">Access is by invitation only. If you forgot your password, call or write to your administrator and they will reset it for you.</p>`;
  }
  setApp(`<div class="login-wrap">${authBand(brand)}<div class="card login">${inner}${langBtn()}</div></div>`);
  if (L && L.step === 'code') {
    document.getElementById('login-code')?.focus();
    clearInterval(S.cache.rt);
    const tick = () => {
      const b = document.getElementById('resend'); if (!b) return clearInterval(S.cache.rt);
      const left = resendLeft(); b.disabled = left > 0; b.textContent = T(left > 0 ? `Resend code (${left} s)` : 'Resend code');
    };
    S.cache.rt = setInterval(tick, 1000); tick();
  } else {
    if (key) mountTurnstile(key);
    document.getElementById(S.cache.loginEmail ? 'pw-password' : 'login-email')?.focus();
  }
}
/* A message above the sign-in form without redrawing it (the typed email, password and code stay). */
function loginInline(f, text) {
  let b = f.parentElement.querySelector('.msg.err.inline');
  if (!b) { b = document.createElement('div'); b.className = 'msg err inline'; b.setAttribute('role', 'alert'); f.parentElement.insertBefore(b, f); }
  b.textContent = T(text); const i = f.querySelector('input:not([type=hidden])'); if (i && !f.querySelector('input:focus')) i.focus();
}
function finishLogin(r) {
  S.token = r.token; S.cache.newDt = r.device_token || null;
  try { sessionStorage.setItem('tt', r.token); } catch { /* storage blocked */ }   // moved to localStorage after /me if the rule says to remember
  S.login = null; S.me = null; clearInterval(S.cache.rt);
  location.hash = '#/' + (r.user?.role === 'approver' ? 'approvals' : 'week'); render();
}

/* Create a password: opened without a session from the link in an invitation or reset email. */
async function setPasswordView(token, err) {
  let sp = S.cache.sp;
  if (!sp || sp.token !== token) {
    S.cache.sp = sp = { token };
    sp.load = api('/password/setup?token=' + encodeURIComponent(token), { anon: true }).then(i => { sp.info = i; }, e => { sp.bad = e.status >= 400 && e.status < 500 ? 'expired' : 'network'; });
  }
  await sp.load;
  if (S.cache.sp !== sp) return;
  const pub = sp.info ? null : await loadPub();
  const brand = sp.info?.brand || pub?.brand || null; applyBrand(brand);
  const toLogin = '<button class="btn pri" type="button" data-act="sp-login">Go to sign in</button>';
  let inner;
  if (sp.done) inner = `<h1>Password saved</h1><div class="msg ok" role="status">Your password is ready. You can sign in now.</div>${toLogin}`;
  else if (sp.bad) inner = `<h1>This link no longer works</h1><div class="msg err" role="alert">${sp.bad === 'network' ? 'We could not reach the server. Try again in a moment.' : 'This link has expired or was already used. Ask your administrator for a new one.'}</div>${toLogin}`;
  else {
    const i = sp.info;
    inner = `<h1>${i.purpose === 'reset' ? 'Create a new password' : 'Create your password'}</h1><p class="sub">${i.name ? `Welcome, ${esc(i.name)}` : 'Welcome'}</p>
      ${err ? `<div class="msg err" role="alert">${esc(err)}</div>` : ''}
      <form data-form="set-password"><p><label for="sp-email">Email</label><input id="sp-email" type="email" value="${esc(i.email)}" readonly autocomplete="username" aria-readonly="true"></p>
      <div style="margin:0 0 14px">${pwField('password', 'New password', { auto: 'new-password', min: 10, strength: true })}</div>
      <p class="muted" style="margin:-4px 0 14px">A short phrase that only you know works well.</p>
      <button class="btn pri" type="submit">Save password</button></form>`;
  }
  setApp(`<div class="login-wrap">${authBand(brand)}<div class="card login">${inner}${langBtn()}</div></div>`);
  document.getElementById('pw-password')?.focus();
}

/* --- My account: change the password (the current one is required) and the language --- */
async function accountView() {
  const m = S.me;
  return `<h1>My account</h1><p class="sub">Your sign-in details, language and password.</p>
    <div class="card"><div class="facts"><div><label>Name</label><b>${esc(m.name)}</b></div>${m.email ? `<div><label>Email</label><b>${esc(m.email)}</b></div>` : ''}<div><label>Role</label><b>${roleLabel(m)}</b></div>
      <div style="min-width:180px"><label for="lang-pick">Language</label><select id="lang-pick" data-act-change="lang-pick"><option value="en" ${LANG() === 'en' ? 'selected' : ''}>English</option><option value="es" ${LANG() === 'es' ? 'selected' : ''}>Español</option></select></div></div></div>
    <div class="card" style="max-width:560px"><h2>Change password</h2>
      <p class="muted" style="margin-top:0">Type your current password and then the new one. If you do not remember the current one, call your administrator and they will reset it. After the change, your other sessions are signed out; this one stays open.</p>
      <form data-form="change-pw">
      <div style="margin:0 0 14px">${pwField('current_password', 'Current password', { auto: 'current-password' })}</div>
      <div style="margin:0 0 14px">${pwField('password', 'New password', { auto: 'new-password', min: 10, strength: true })}</div>
      <button class="btn pri" type="submit">Change password</button></form></div>`;
}

/* --- Approvals for an approver with an account: Pending / Approved / Returned / All; the list on the left, the timesheet on the right --- */
const APR_VIEWS = [['pending', 'Pending'], ['approved', 'Approved'], ['returned', 'Returned'], ['all', 'All']];
const saveDraft = id => { S.cache.apDraft = { id, comment: document.getElementById('ap-comment')?.value || '', code: document.getElementById('ap-code')?.value || '' }; };
async function approverView() {
  const st = S.cache.apStatus || 'pending';
  const all = await api('/approver/sheets?status=' + st);
  const F = FL('apr');
  const rows = all.filter(s => matchQ(F.q, [s.user?.name]));
  let sel = rows.find(s => s.id === S.cache.apSel) || rows[0];
  let detail = '';
  if (sel) {
    const d = await api('/approver/sheets/' + sel.id);
    const start = d.period_starting || sel.period_starting || sel.week_starting || d.week_starting;
    const days = Array.from({ length: wkSpan(start, d.period_ending || sel.period_ending) }, (_, i) => addDays(start, i));
    const svcs = new Map(); for (const e of d.entries || []) { if (!svcs.has(e.project_id)) svcs.set(e.project_id, new Set()); svcs.get(e.project_id).add(e.service_id); }
    const lines = new Map();
    for (const e of d.entries || []) { const multi = (svcs.get(e.project_id)?.size || 0) > 1; const k = e.project_id + '|' + (multi ? e.service_id : ''); if (!lines.has(k)) lines.set(k, { p: e.project, s: multi ? e.service : null, by: {} }); const r = lines.get(k); r.by[e.work_date] = (r.by[e.work_date] || 0) + e.hours; }
    const dr = S.cache.apDraft && S.cache.apDraft.id === sel.id ? S.cache.apDraft : { comment: '', code: '' };
    const open = sel.status === 'submitted';
    const needs = open && (d.needs_code || sel.needs_code || (S.cache.apCode || {})[sel.id]);
    const color = { submitted: 'amber', approved: 'green', returned: 'red' }[sel.status] || 'gray';
    const label = sel.status === 'submitted' ? 'Pending' : STATUS[sel.status] || sel.status;
    const ret = sel.status === 'returned' && (d.return_comment || sel.return_comment) ? `<div class="msg err" style="margin-top:10px"><b>Returned for correction:</b> ${esc(d.return_comment || sel.return_comment)}</div>` : '';
    const acts = open ? `<label for="ap-comment">Comment (required when returning)</label><textarea id="ap-comment" rows="3" placeholder="Example: Thursday should be OT, please fix and resubmit.">${esc(dr.comment)}</textarea>
      ${needs ? `<div class="msg warn" style="margin-top:12px">This decision needs a 6-digit code. We send it to your email.</div>
        <div class="row" style="align-items:end"><button class="btn ghost" type="button" data-act="apr-code" data-id="${sel.id}">Send me a code</button>
        <div style="max-width:200px"><label for="ap-code">6-digit code</label><input id="ap-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" value="${esc(dr.code)}" style="letter-spacing:.2em"></div></div>` : ''}
      <p class="row" style="margin-bottom:0"><button class="btn" data-act="apr-approve" data-id="${sel.id}" data-v="${sel.version}">Approve</button>
      <button class="btn danger" data-act="apr-return" data-id="${sel.id}" data-v="${sel.version}">Return for correction</button></p>`
      : `<p class="muted" style="margin:10px 0 0">${sel.decided_at ? `Decided on ${esc(String(sel.decided_at).slice(0, 10))}.` : ''}</p>`;
    detail = `<div class="card"><div class="row"><div><h2 style="margin:0">${esc(sel.user?.name)}</h2><span class="muted">week ending ${nice(sel.week_ending)}${sel.is_part ? ` · ${T('Part')} ${sel.part} (${nice(sel.period_starting)}${sel.period_starting === sel.period_ending ? '' : ' – ' + nice(sel.period_ending)})` : ''}</span></div><span class="spacer"></span>${pill(label, color)}${sel.late ? ' ' + pill('Late', 'red') : ''}</div>
      ${wkParts(d.internal || d.external ? d : sel, 'in')}${(sel.warnings || []).map(w => `<div class="msg warn" style="margin-top:10px">${esc(w.message)}</div>`).join('')}${ret}
      <div class="scroll"><table class="daygrid" style="margin-top:10px"><tr><th>Project</th>${days.map(x => `<th>${dow(parse(x).getDay())}</th>`).join('')}</tr>
      ${[...lines.values()].map(r => `<tr><td>${esc(r.p?.code)} · ${esc(r.p?.name)}${r.s ? ' · ' + esc(r.s.name) : ''}</td>${days.map(x => r.by[x] ? `<td>${h1(r.by[x])}</td>` : '<td class="zero">·</td>').join('')}</tr>`).join('')}</table></div>
      <p class="row" style="margin:10px 0"><b>Total hours</b><span class="spacer"></span><b>${h1(sel.total_hours)}</b></p>
      ${(d.entries || []).some(e => e.note) ? `<p class="muted" style="white-space:pre-wrap">${esc(d.entries.filter(e => e.note).map(e => dayLabel(e.work_date) + ': ' + e.note).join('\n'))}</p>` : ''}${acts}</div>`;
  }
  const pills = APR_VIEWS.map(([k, l]) => `<button type="button" class="${k === st ? 'on' : ''}" data-act="apr-status" data-s="${k}">${l}${k === st ? ` (${all.length})` : ''}</button>`).join('');
  const dateOf = s => esc(String((s.status === 'submitted' ? s.submitted_at : s.decided_at || s.submitted_at) || '').slice(0, 10));
  return `<h1>Approvals</h1><p class="sub">Approving replaces the manager signature. Timesheets submitted after the deadline are flagged as late.</p>
    <div class="twocol"><div class="card"><div class="pills" style="margin-bottom:12px">${pills}</div>
    ${filterBar('apr', [{ k: 'q', label: 'Search person', ph: 'Name' }])}
    ${rows.length ? `<table><tr><th>Person</th><th>Week ending</th><th class="r">Hours</th><th>${st === 'pending' ? 'Submitted' : 'Date'}</th>${st === 'all' ? '<th>Status</th>' : ''}</tr>
    ${rows.map(s => `<tr class="pick ${sel && s.id === sel.id ? 'sel' : ''}" data-act="apr-pick" data-id="${s.id}"><td><b>${esc(s.user?.name)}</b> ${s.late ? '<span class="badge returned">Late</span>' : ''}</td>
    <td>${wkWeekCell(s)}</td><td class="r">${hrs(s.total_hours)}</td><td>${dateOf(s)}</td>${st === 'all' ? `<td>${pill(s.status === 'submitted' ? 'Pending' : STATUS[s.status] || s.status, { submitted: 'amber', approved: 'green', returned: 'red' }[s.status] || 'gray')}</td>` : ''}</tr>`).join('')}</table>`
    : `<p class="muted">${all.length ? 'No timesheet matches the filter.' : st === 'pending' ? 'Nothing is waiting for approval.' : 'There are no timesheets here yet.'}</p>`}</div>${detail}</div>`;
}
function mountTurnstile(key) {
  const draw = () => { const box = document.getElementById('ts-box'); if (box && window.turnstile) { box.innerHTML = ''; S.cache.tsWidget = window.turnstile.render(box, { sitekey: key }); } };
  if (window.turnstile) return draw();
  if (document.getElementById('ts-script')) return;
  const s = document.createElement('script');
  s.id = 'ts-script'; s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'; s.async = true; s.onload = draw;
  document.head.appendChild(s);
}

/* --- Timesheet (weekly time record): the screen lives in the "week screen" block further down (WEB-HOJA) --- */


/* --- All weeks: every week of the person, including the ones not filled in yet --- */
async function weeksView() {
  const mine = await api('/sheets');
  const cur = weekEnd(today());
  const back = Number(S.me.rules?.weeks_back ?? 8);
  const byEnd = weekAgg(mine);
  const first = S.me.member_since ? weekEnd(S.me.member_since) : null;
  const ends = new Set([...Array.from({ length: back + 1 }, (_, i) => addDays(cur, -7 * i)).filter(w => !first || w >= first), ...mine.map(s => s.week_ending)]);
  const rows = [...ends].sort().reverse().map(end => {
    const s = byEnd[end];
    const st = s ? s.status : 'none';
    const color = { draft: 'gray', submitted: 'blue', approved: 'green', returned: 'red', none: 'amber' }[st];
    const label = st === 'none' ? 'Not submitted' : stageLabel(s);
    const open = ['draft', 'returned'].includes(st) ? ['Continue', 'pri'] : st === 'none' ? ['Fill in', 'pri'] : ['View', 'ghost'];
    return `<tr><td><b>${nice(end)}</b></td><td class="r">${s && s.total_hours ? h1(s.total_hours) : '—'}</td><td>${pill(label, color)}${s?.late ? ' ' + pill('Late', 'red') : ''}</td>
      <td>${esc(s?.approved_by || '—')}</td><td>${esc(s?.invoice_number || '—')}</td>
      <td class="r"><button class="btn ${open[1]} sm" data-act="open-week" data-end="${end}">${open[0]}</button></td></tr>`;
  }).join('');
  return `<h1>All weeks</h1><p class="sub">Every week is listed, including the ones you have not filled in yet. Open any of them to fill it in or review it.</p>
    <div class="card"><table><tr><th>Week ending</th><th class="r">Hours</th><th>Status</th><th>Approved by</th><th>Invoice</th><th class="r">Action</th></tr>${rows}</table></div>`;
}

/* --- Hours report --- */
async function reportsView() {
  const q = S.cache.rep || { from: addDays(today(), -30), to: today(), group: 'project' };
  S.cache.rep = q;
  const projs = await api('/projects');
  const r = await api(`/reports/summary?from=${q.from}&to=${q.to}&group=${q.group}${q.project_id ? '&project_id=' + q.project_id : ''}`);
  return `<h1>Hours report</h1><p class="sub">Hours by project, day, week or client for any period.</p>
    <div class="card"><form data-form="report" class="fbar" style="margin-top:0">
    <div><label>From</label><input type="date" name="from" value="${q.from}"></div><div><label>To</label><input type="date" name="to" value="${q.to}"></div>
    <div><label>Group by</label><select name="group">${[['project', 'Project'], ['day', 'Day'], ['week', 'Week'], ['customer', 'Client']].map(([v, t]) => `<option value="${v}" ${q.group === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
    <div><label>Project</label><select name="project_id"><option value="">All</option>${projs.map(p => `<option value="${p.id}" ${Number(q.project_id) === p.id ? 'selected' : ''}>${esc(p.code)} · ${esc(p.name)}</option>`).join('')}</select></div>
    <button class="btn pri" type="submit">Show</button></form></div>
    <div class="card"><div style="text-align:right">${exportBar('report', { from: q.from, to: q.to, group: q.group, ...(q.project_id ? { project_id: q.project_id } : {}) })}</div>
    <table style="margin-top:6px"><tr><th></th><th class="r">Hours</th><th class="r">Billable</th><th class="r">Non-billable</th></tr>
    ${r.rows.map(x => `<tr><td>${esc(x.label)}</td><td class="r">${hrs(x.hours)}</td><td class="r">${hrs(x.billable_hours)}</td><td class="r">${hrs(x.non_billable_hours)}</td></tr>`).join('')}
    <tr><td><b>Total</b></td><td class="r"><b>${hrs(r.total_hours)}</b></td><td class="r"><b>${hrs(r.billable_hours)}</b></td><td></td></tr></table></div>`;
}

/* --- Approvals (owner): list on the left, the selected timesheet on the right --- */
async function approvalsView() {
  const [all, people, allProjects] = await Promise.all([api('/sheets-pending'), api('/users'), api('/projects')]);
  S.cache.allProjects = allProjects;
  const F = FL('pending');
  const rows = all.filter(s => matchQ(F.q, [s.user?.name]) && (!F.user_id || s.user_id === Number(F.user_id)) && (flag(F.late) !== true || s.late));
  let sel = rows.find(s => s.id === S.cache.apSel) || rows[0];
  let detail = '';
  if (sel) {
    const d = await api('/sheets/' + sel.id);
    const days = Array.from({ length: wkSpan(d.period_starting || d.week_starting, d.period_ending) }, (_, i) => addDays(d.period_starting || d.week_starting, i));
    const lines = new Map();
    const multi = id => (S.cache.allProjects?.find(p => p.id === id)?.services || []).length > 1;
    for (const e of d.entries) { const k = e.project_id + '|' + (multi(e.project_id) ? e.service_id : ''); if (!lines.has(k)) lines.set(k, { p: e.project, s: multi(e.project_id) ? e.service : null, by: {} }); const r = lines.get(k); r.by[e.work_date] = (r.by[e.work_date] || 0) + e.hours; }
    detail = `<div class="card"><div class="row"><div><h2 style="margin:0">${esc(sel.user?.name)}</h2><span class="muted">week ending ${nice(sel.week_ending)}${sel.is_part ? ` · ${T('Part')} ${sel.part} (${nice(sel.period_starting)}${sel.period_starting === sel.period_ending ? '' : ' – ' + nice(sel.period_ending)})` : ''}</span></div><span class="spacer"></span><span class="badge submitted">Pending</span>${sel.late ? ' <span class="badge returned">Late</span>' : ''}</div>
      ${sel.warnings.map(w => `<div class="msg warn" style="margin-top:10px">${esc(w.message)}</div>`).join('')}
      <div class="scroll"><table class="daygrid" style="margin-top:10px"><tr><th>Project</th>${days.map(x => `<th>${dow(parse(x).getDay())}</th>`).join('')}</tr>
      ${[...lines.values()].map(r => `<tr><td>${esc(r.p?.code)} · ${esc(r.p?.name)}${r.s ? ' · ' + esc(r.s.name) : ''}</td>${days.map(x => r.by[x] ? `<td>${h1(r.by[x])}</td>` : '<td class="zero">·</td>').join('')}</tr>`).join('')}</table></div>
      <p class="row" style="margin:10px 0"><b>Total hours</b><span class="spacer"></span><b>${h1(sel.total_hours)}</b></p>
      ${d.entries.some(e => e.note) ? `<p class="muted" style="white-space:pre-wrap">${esc(d.entries.filter(e => e.note).map(e => dayLabel(e.work_date) + ': ' + e.note).join('\n'))}</p>` : ''}
      <label>Comment (required when returning)</label><textarea id="ap-comment" rows="3" placeholder="Example: Thursday should be OT, please fix and resubmit."></textarea>
      <p class="row" style="margin-bottom:0"><button class="btn" data-act="approve" data-id="${sel.id}" data-v="${sel.version}">Approve</button>
      <button class="btn danger" data-act="return" data-id="${sel.id}" data-v="${sel.version}">Return for correction</button></p></div>`;
  }
  return `<h1>Approvals</h1><p class="sub">Approving replaces the manager signature. Timesheets submitted after the deadline are flagged as late.</p>
    <div class="twocol"><div class="card"><div class="pills" style="margin-bottom:12px"><span class="on" style="border:1px solid var(--primary);border-radius:99px;padding:5px 14px;background:var(--tint);color:var(--primary);font-weight:600">Pending (${all.length})</span></div>
    ${exportBar('pending', activeF('pending'))}${filterBar('pending', [{ k: 'q', label: 'Search person', ph: 'Name' }, { k: 'user_id', label: 'Person', type: 'select', opts: [['', 'All'], ...people.map(p => [p.id, p.name])] }, { k: 'late', label: 'Submitted', type: 'select', opts: [['', 'All'], ['1', 'Late only']] }])}
    ${rows.length ? `<table><tr><th>Person</th><th>Week ending</th><th class="r">Hours</th><th>Submitted</th></tr>
    ${rows.map(s => `<tr class="pick ${sel && s.id === sel.id ? 'sel' : ''}" data-act="ap-pick" data-id="${s.id}"><td><b>${esc(s.user?.name)}</b> ${s.late ? '<span class="badge returned">Late</span>' : ''}</td>
    <td>${wkWeekCell(s)}</td><td class="r">${hrs(s.total_hours)}</td><td>${esc((s.submitted_at || '').slice(0, 10))}</td></tr>`).join('')}</table>`
    : `<p class="muted">${all.length ? 'No pending timesheet matches the filter.' : 'Nothing is waiting for approval.'}</p>`}</div>${detail}</div>`;
}

/* --- Invoices --- */
async function invoicesView() {
  const list = await api('/contractor-invoices');
  const owner = S.me.role === 'owner';
  const people = owner ? (await api('/users')).filter(u => u.worker_type === 'consultant') : [];
  const canInvoice = S.me.worker_type === 'consultant' || owner;
  const F = FL('invoices');
  const shown = list.filter(i => matchQ(F.q, [i.number, i.user?.name]) && (!F.status || i.status === F.status) && (!owner || !F.user_id || i.user_id === Number(F.user_id))
    && (!F.from || i.issued_on >= F.from) && (!F.to || i.issued_on <= F.to));
  const st = { issued: 'Issued', sent: 'Sent', paid: 'Paid' };
  return `<h1>Invoices</h1><p class="sub">Pick the approved weeks to bill. Rate, deductions and totals are calculated for you.</p>
    ${canInvoice ? `<div class="card"><h2>Create an invoice</h2><form data-form="cinv" class="fbar" style="margin:0"><div><label>From</label><input type="date" name="from" value="${addDays(today(), -30)}"></div>
    <div><label>To</label><input type="date" name="to" value="${today()}"></div>
    ${owner ? `<div><label>Consultant</label><select name="user_id"><option value="">Me</option>${people.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></div>` : ''}
    <button class="btn pri" type="submit">Create invoice from approved weeks</button></form></div>` : '<div class="card"><p class="muted" style="margin:0">Employees do not create invoices.</p></div>'}
    <div class="card"><h2>Invoice history</h2>${exportBar('invoices', activeF('invoices'))}${filterBar('invoices', [{ k: 'q', label: 'Search', ph: 'Number or consultant' }, { k: 'status', label: 'Status', type: 'select', opts: [['', 'All'], ['issued', 'Issued'], ['sent', 'Sent'], ['paid', 'Paid']] }, ...(owner ? [{ k: 'user_id', label: 'Consultant', type: 'select', opts: [['', 'All'], ...people.map(p => [p.id, p.name])] }] : []), { k: 'from', label: 'Issued from', type: 'date' }, { k: 'to', label: 'To', type: 'date' }])}
    <table><tr><th>Number</th><th>Consultant</th><th>Period</th><th class="r">Gross</th><th class="r">Net</th><th>Status</th><th>Accountisi</th><th></th></tr>
    ${shown.map(i => `<tr><td>${esc(i.number)}</td><td>${esc(i.user?.name)}</td><td>${esc(i.period_from)} to ${esc(i.period_to)}</td><td class="r">${money(i.gross)}</td><td class="r">${money(i.net)}</td>
    <td>${pill(st[i.status] || i.status, { issued: 'blue', sent: 'amber', paid: 'green' }[i.status] || 'gray')}</td><td>${i.accountisi_status ? pill(i.accountisi_status, i.accountisi_status === 'draft' ? 'amber' : 'green') : '—'}</td>
    <td class="r"><button class="btn ghost sm" data-act="pdf" data-id="${i.id}">PDF</button>
    ${owner && i.accountisi_status !== 'draft' ? `<button class="btn ghost sm" data-act="push-bill" data-id="${i.id}">Send to Accountisi</button>` : ''}</td></tr>`).join('')}</table>
    ${shown.length ? '' : '<p class="muted">No invoices yet.</p>'}</div>`;
}

/* --- Export --- */
async function exportView() {
  const tpls = await api('/export-templates');
  const owner = S.me.role === 'owner';
  const [people, customers] = owner ? await Promise.all([api('/users'), api('/customers')]) : [[], []];
  S.cache.tpls = tpls;
  const x = S.cache.exp = { template_id: tpls[0]?.id, from: addDays(today(), -30), to: today(), user_id: '', customer_id: '', ...(S.cache.exp || {}) };
  // Patients (projects) of the chosen office with approved hours in the period; the owner ticks which ones to send.
  let patients = null;
  if (x.customer_id) {
    const q = new URLSearchParams({ customer_id: x.customer_id, from: x.from, to: x.to, ...(x.user_id ? { user_id: x.user_id } : {}) });
    patients = await api('/export/patients?' + q);
  }
  const ch = 'data-act-change="exp-refresh"';
  return `<h1>Export</h1><p class="sub">Only hours from approved timesheets are exported.</p>
    <div class="card"><form data-form="export" class="grid">
    <div><label>Template</label><select name="template_id" ${ch}>${tpls.map(t => `<option value="${t.id}" ${t.id === Number(x.template_id) ? 'selected' : ''}>${esc(t.name)} (${t.format})</option>`).join('')}</select></div>
    <div><label>From</label><input type="date" name="from" value="${esc(x.from)}" ${ch}></div><div><label>To</label><input type="date" name="to" value="${esc(x.to)}" ${ch}></div>
    ${owner ? `<div><label>Office (client)</label><select name="customer_id" ${ch}><option value="">All</option>${customers.map(c => `<option value="${c.id}" ${c.id === Number(x.customer_id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>` : ''}
    ${owner ? `<div><label>Person</label><select name="user_id" ${ch}><option value="">All</option>${people.map(p => `<option value="${p.id}" ${p.id === Number(x.user_id) ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div>` : ''}
    ${patients ? `<div style="grid-column:1/-1"><label>Patients of this office with approved hours</label>
      ${patients.length ? `<p><button class="btn ghost sm" type="button" data-act="exp-all" data-on="1">Select all</button> <button class="btn ghost sm" type="button" data-act="exp-all" data-on="0">Clear all</button></p>
      <table><tr><th></th><th>Patient</th><th>Health plan</th><th>Contract</th><th class="r">Days</th><th class="r">Hours</th><th class="r">Amount</th></tr>${patients.map(p => `<tr><td><input type="checkbox" name="project_ids" data-multi="1" value="${p.id}" ${(x.picked ? x.picked.includes(p.id) : true) ? 'checked' : ''}></td><td>${esc(p.name)}</td><td>${p.plan ? esc(p.plan) : '<span class="muted">no plan</span>'}</td><td>${esc(p.contract || '')}</td><td class="r">${p.days}</td><td class="r">${p.hours}</td><td class="r">${p.amount}</td></tr>`).join('')}</table>`
      : '<p class="muted">This office has no approved billable hours in that period.</p>'}</div>` : ''}
    <div style="align-self:end"><button class="btn pri" type="submit">Download</button></div></form>
    <p class="muted">The “Consultorio 1500” template produces the “Atendidos &lt;year&gt;” sheets that program imports, one office per file. Each patient is a project of that office, named “Patient - Health plan - Contract”: what comes before the first dash is the patient, then the plan, then the contract number. A patient with no health plan goes to the “Facturada sin plan” column.</p></div>
    ${owner ? `<div class="card"><h2>Templates</h2>${tpls.map(t => `<p><b>${esc(t.name)}</b> ${t.is_system ? '<span class="badge">system</span>' : `<button class="btn danger sm" data-act="del-tpl" data-id="${t.id}">Delete</button>`}
    <span class="muted">${t.config.columns.map(c => esc(c.header)).join(' · ')}</span></p>`).join('')}
    <details><summary>Create a template</summary><form data-form="tpl"><div class="grid">
    <div><label>Name</label><input name="name" required></div><div><label>Format</label><select name="format"><option>xlsx</option><option>csv</option></select></div>
    <div><label>Group</label><select name="group"><option value="">Do not group</option><option value="project,date">By project and day</option><option value="project">By project</option><option value="worker">By person</option><option value="date">By day</option></select></div></div>
    <p><label>Columns</label>
    <textarea name="columns" rows="6">Date | date\nPerson | worker\nProject | project.code\nHours | hours</textarea><small class="help">One per line: Heading | source. Sources: date, week_ending, worker, project.code, project.name, project.patient, project.plan, project.contract, project.meta.KEY, customer, hours, amount, rate, start, end, notes, billable</small></p>
    <button class="btn pri" type="submit">Save template</button></form></details></div>` : ''}`;
}

/* --- Projects --- */
async function projectsView() {
  const [list, customers, approvers, users] = await Promise.all([api('/projects'), api('/customers'), api('/approvers'), api('/users')]);
  S.cache.pl = list;
  const F = FL('projects');
  const shown = list.filter(x => matchQ(F.q, [x.code, x.name, x.customer?.name]) && sameFlag(F.active, x.active) && sameFlag(F.billable, x.billable) && (!F.customer_id || x.customer_id === Number(F.customer_id)));
  return `<div class="pghead"><div><h1>Projects</h1><p class="sub">Only the company owner manages projects. For Consultorio 1500 each patient is a project named “Patient - Health plan - Contract” (if the name has no plan or contract, the plan_medico and numero_contrato fields under “Fields” are used).</p></div><button class="btn pri" type="button" data-act="project-new">New project</button></div>
    <div class="card">${exportBar('projects', activeF('projects'))}${filterBar('projects', [{ k: 'q', label: 'Search', ph: 'Code, name or client' }, { k: 'customer_id', label: 'Client', type: 'select', opts: [['', 'All'], ...customers.map(c => [c.id, c.name])] }, { k: 'active', label: 'Status', type: 'select', opts: YN('Active', 'Inactive') }, { k: 'billable', label: 'Billable', type: 'select', opts: YN('Billable', 'Non-billable') }])}
    <div class="scroll"><table><tr><th>Code</th><th>Name</th><th>Client</th><th class="r">Rate</th><th>Status</th><th></th></tr>
    ${shown.map(x => `<tr><td>${esc(x.code)}</td><td>${esc(x.name)}${(x.services || []).filter(s => s.active).length > 1 ? ' <span class="muted">· ' + (x.services || []).filter(s => s.active).length + ' ' + T('services') + '</span>' : ''}</td><td>${esc(x.customer?.name || '—')}</td>
    <td class="r">${x.bill_rate ? money(x.bill_rate) : '—'}</td>
    <td>${activePill(x.active)} ${x.billable ? '' : pill('Non-billable', 'amber')} ${x.requires_internal_approval ? pill('Internal approval', 'blue') : ''}</td>
    <td class="r"><button class="btn ghost sm" data-act="edit-project" data-id="${x.id}">Edit</button></td></tr>`).join('')}</table></div></div>
`;
}

/* short chips for a list of names: the first `max`, then "+N" (the full list in the tooltip) */
const pchips = (names, max = 3) => names.length ? `<span class="pchips" title="${esc(names.join(', '))}">${names.slice(0, max).map(n => `<span class="pchip">${esc(n)}</span>`).join('')}${names.length > max ? `<span class="pchip">+${names.length - max}</span>` : ''}</span>` : '<span class="muted">—</span>';
async function approversView() {
  const list = await api('/approvers');
  const F = FL('approvers');
  const shown = list.filter(a => matchQ(F.q, [a.name, a.email]) && sameFlag(F.active, a.active));
  return `<div class="pghead"><div><h1>Approvers</h1><p class="sub">They can be external and need no account: they receive an email with a secure link. Optionally they can also sign in to the system to see their approvals.</p></div><button class="btn pri" type="button" data-act="approver-new">Add approver</button></div>
    <div class="card">${exportBar('approvers', activeF('approvers'))}${filterBar('approvers', [{ k: 'q', label: 'Search', ph: 'Name or email' }, { k: 'active', label: 'Status', type: 'select', opts: YN('Active', 'Inactive') }])}
    <div class="scroll"><table><tr><th>Name</th><th>Email</th><th>Clients</th><th>Extra code</th><th>Can sign in</th><th>Account</th><th>Status</th><th></th></tr>${shown.map(a => `<tr><td><b>${esc(a.name)}</b></td><td>${esc(a.email)}</td><td>${pchips((a.customers || []).map(c => c.name), 3)}</td><td>${a.require_code ? pill('Yes', 'blue') : pill('No')}</td><td>${a.can_login ? pill('Yes', 'blue') : pill('No')}</td><td>${accountPill(a.account_status)}</td><td>${activePill(a.active)}</td>
    <td class="r"><button class="btn ghost sm" data-act="edit-approver" data-id="${a.id}">Edit</button> <button class="btn ghost sm" data-act="toggle-login" data-id="${a.id}">${a.can_login ? 'Remove sign-in' : 'Allow sign-in'}</button> <button class="btn ghost sm" data-act="toggle-approver" data-id="${a.id}">${a.active ? 'Deactivate' : 'Activate'}</button></td></tr>`).join('')}</table></div></div>
`;
}

async function customersView() {
  const list = await api('/customers');
  S.cache.cl = list;
  const F = FL('customers');
  const shown = list.filter(c => matchQ(F.q, [c.name, c.email]) && sameFlag(F.active, c.active) && sameFlag(F.linked, c.accountisi_id));
  return `<div class="pghead"><div><h1>Clients</h1><p class="sub">For Consultorio 1500, each client is an office.</p></div><button class="btn pri" type="button" data-act="customer-new">Add client</button></div>
    <div class="card">${exportBar('customers', activeF('customers'))}${filterBar('customers', [{ k: 'q', label: 'Search', ph: 'Name or email' }, { k: 'active', label: 'Status', type: 'select', opts: YN('Active', 'Inactive') }, { k: 'linked', label: 'Accountisi', type: 'select', opts: YN('Linked', 'Not linked') }])}<div class="scroll"><table><tr><th>Name</th><th>Email</th><th>Accountisi</th><th>Status</th><th></th></tr>${shown.map(c => `<tr><td><b>${esc(c.name)}</b></td><td>${esc(c.email || '')}</td><td>${c.accountisi_id ? pill('Linked', 'green') + ' <span class="muted">' + esc(c.accountisi_id) + '</span>' : pill('Not linked')}</td><td>${activePill(c.active)}</td><td class="r"><button class="btn ghost sm" data-act="edit-customer" data-id="${c.id}">Edit</button></td></tr>`).join('')}</table></div></div>
`;
}

/* --- Branding --- */
/* A color: just the picker. The form sends #rrggbb; the preview follows it. */
function colorField(name, label, value, help) {
  const v = /^#[0-9a-f]{6}$/i.test(value || '') ? value.toLowerCase() : '#000000';
  return `<div><label>${label}</label><input name="${name}" type="color" value="${v}" aria-label="${label}">${help ? `<small class="help">${help}</small>` : ''}</div>`;
}
function brandPreview(f) {
  const pv = document.getElementById('bpreview'); if (!pv || !f) return;
  pv.querySelector('.ph').style.background = f.elements.color_primary.value; pv.querySelector('.pvbtn').style.background = f.elements.color_accent.value;
  const nm = pv.querySelector('.pvname'); if (nm) { nm.hidden = !f.elements.show_name.checked; pv.querySelector('.ph img').style.maxHeight = f.elements.show_name.checked ? '34px' : '52px'; }
}
document.addEventListener('input', e => { if (e.target.type === 'color' && e.target.form) brandPreview(e.target.form); });
document.addEventListener('change', e => { if (e.target.name === 'show_name') brandPreview(e.target.form); });
async function brandView() {
  const [b, hist] = await Promise.all([api('/brand'), api('/brand/history')]);
  const sn = b.show_name !== false;
  return `<h1>Branding</h1><p class="sub">Applies to the web app, emails, PDF invoices and the mobile app, without touching code.</p>
    <div class="card"><form data-form="brand"><div class="grid"><div><label>Business name</label><input name="brand_name" value="${esc(b.name)}"></div>
    ${colorField('color_primary', 'Main color', b.color_primary)}
    ${colorField('color_accent', 'Accent color', b.color_accent, 'Used for buttons.')}
    <div><label>Logo</label><input name="logo" type="file" accept="image/*"><small class="help">png, jpg or webp; max 1 MB.</small></div>
    <div><label>Sender name</label><input name="email_from_name" value="${esc(b.email_from_name)}"></div>
    <div><label>Sender email</label><input name="email_from_address" type="email" value="${esc(b.email_from_address)}"></div></div>
    <p><label>Footer of emails and invoices</label><input name="email_footer" value="${esc(b.email_footer)}"></p>
    <p><label class="chk"><input type="checkbox" name="remove_logo"> Remove the logo</label></p>
    <p><label class="chk"><input type="checkbox" name="show_name" ${sn ? 'checked' : ''}> Show the name next to the logo</label><small class="help">Unchecked, the header and the sign-in page show only the logo. Without a logo the name always shows.</small></p>
    <div class="preview" id="bpreview" style="margin:12px 0"><div class="ph" style="background:${esc(b.color_primary)}">${b.logo_url ? `<img src="${esc(b.logo_url)}" style="max-height:${sn ? 34 : 52}px"> <span class="pvname" ${sn ? '' : 'hidden'}>${esc(b.name)}</span>` : esc(b.name)}</div>
    <div class="pb"><p>This is how the header and button of an approval email look.</p><span class="btn pvbtn" style="background:${esc(b.color_accent)}">Review and decide</span></div></div>
    <button class="btn pri" type="submit">Save branding</button></form></div>
    <div class="card"><h2>History</h2>${hist.length ? `<table>${hist.map(h => `<tr><td>${esc(String(h.created_at).slice(0, 16).replace('T', ' '))}</td><td>${esc(h.before.brand_name || '—')} ${esc(h.before.color_primary)} → ${esc(h.after.brand_name || '—')} ${esc(h.after.color_primary)}</td>
    <td class="r"><button class="btn ghost sm" data-act="revert-brand" data-id="${h.id}">Restore the previous one</button></td></tr>`).join('')}</table>` : '<p class="muted">No changes yet.</p>'}</div>`;
}

/* --- Rules: each setting with its explanation on the left and the control on the right --- */
async function rulesView() {
  const c = await api('/company'); const r = c.rules;
  const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const row = (title, help, control, cls = '') => `<div class="setrow ${cls}"><div><b>${title}</b><small>${help}</small></div><div>${control}</div></div>`;
  return `<h1>Rules</h1><p class="sub">Nothing here is hard-coded. The owner changes these without a developer, and the changes apply immediately.</p>
    <form data-form="rules">
    <div class="card"><h2>Company</h2><p class="muted" style="margin-top:-6px">Name and invoice numbering.</p>
    ${row('Company name', 'Shown in the header, emails and invoices.', `<input name="name" value="${esc(c.name)}">`)}
    ${row('Invoice prefix', 'Consultant invoices are numbered with it.', `<input name="invoice_prefix" value="${esc(c.invoice_prefix)}">`)}
    ${row('Domain', 'The web address of this company, for example tiempo.cerevelo.com. The sign-in page and the email links use it.', `<input name="domain" value="${esc(c.domain)}" placeholder="tiempo.cerevelo.com" autocapitalize="none" spellcheck="false">`)}
    ${row('Default language', 'Used for people who have not chosen one, and for emails to external people.', `<select name="locale"><option value="en" ${c.locale === 'en' ? 'selected' : ''}>English</option><option value="es" ${c.locale === 'es' ? 'selected' : ''}>Español</option></select>`)}</div>
    <div class="card"><h2>Timesheets</h2><p class="muted" style="margin-top:-6px">How and when people enter hours.</p>
    ${row('Weeks back a worker may fill in', 'Older weeks are closed.', `<input name="weeks_back" type="number" min="0" max="52" value="${r.weeks_back}">`)}
    ${row('The week starts on', 'Weeks run seven days from this day. Timesheets already created keep their dates.', `<select name="week_starts_on"><option value="0" ${Number(r.week_starts_on) !== 1 ? 'selected' : ''}>Sunday</option><option value="1" ${Number(r.week_starts_on) === 1 ? 'selected' : ''}>Monday</option></select>`)}
    ${row('Weekly submission deadline day', 'Timesheets submitted after it are flagged as late.', `<select name="deadline_dow">${days.map((d, i) => `<option value="${i}" ${r.deadline_dow === i ? 'selected' : ''}>${d}</option>`).join('')}</select>`)}
    ${row('Deadline time', 'Applies on the deadline day.', `<input name="deadline_time" type="time" value="${esc(r.deadline_time)}">`)}
    ${row('Hour increment', 'Applies to every hour cell.', `<select name="increment">${[0.25, 0.5, 1, 0.1].map(v => `<option ${Number(r.increment) === v ? 'selected' : ''}>${v}</option>`).join('')}</select>`)}
    ${row('Maximum hours per day', 'Days above this show a validation warning.', `<input name="max_day_hours" type="number" step="0.5" value="${r.max_day_hours}">`)}
    ${row('Flag late weeks to the approver', 'Shows a Late badge in the approval queue.', `<input type="checkbox" name="flag_late" ${r.flag_late ? 'checked' : ''}>`, 'chkrow')}
    ${row('Allow sending a week in parts', 'People can send the days that are done (in order, with no day skipped) before the week ends. Each part is its own timesheet: it is approved, returned and invoiced apart.', `<input type="checkbox" name="partial_submission" ${r.partial_submission ? 'checked' : ''}>`, 'chkrow')}</div>
    <div class="card"><h2>Approvals</h2><p class="muted" style="margin-top:-6px">Who signs off and how reminders work.</p>
    ${row('Days without a decision before the first reminder', 'The approver gets an email.', `<input name="approval_remind_days" type="number" min="1" value="${r.approval_remind_days}">`)}
    ${row('Repeat the reminder every (days)', 'Until a decision is made.', `<input name="remind_every_days" type="number" min="1" value="${r.remind_every_days}">`)}
    ${row('Approval link validity (days)', 'After that the link stops working.', `<input name="token_days" type="number" min="1" value="${r.token_days}">`)}
    ${row('Wait before notifying a change (minutes)', 'When a submitted timesheet is edited.', `<input name="change_notice_minutes" type="number" min="0" value="${r.change_notice_minutes}">`)}
    ${row('Timesheets above these hours need individual approval with a code', 'Leave empty for no limit.', `<input name="second_approval_hours" type="number" step="0.5" value="${esc(r.second_approval_hours)}" placeholder="no limit">`)}</div>
    <div class="card"><h2>Sign-in</h2><p class="muted" style="margin-top:-6px">How long people stay signed in.</p>
    ${row('Remember for (days since last use)', 'Applies to everyone: how many days without using the app before asking for the password again. For approvers it is also how long a trusted device skips the emailed code. 0 = never remember: the browser session ends when it closes and the mobile app asks for the password every time it opens (it needs signal; without signal it only lets people record hours).', `<input name="remember_days" type="number" min="0" max="365" value="${esc(r.remember_days ?? 30)}">`)}
    ${row('Maximum session length, owner and office (days)', 'A session ends after this many days even if it was used every day, and the password is asked again. 0 = no limit.', `<input name="session_max_days_office" type="number" min="0" max="365" value="${esc(r.session_max_days_office ?? 7)}">`)}
    ${row('Maximum session length, everyone else (days)', 'The same for workers and approvers. 0 = no limit.', `<input name="session_max_days" type="number" min="0" max="365" value="${esc(r.session_max_days ?? 14)}">`)}
    ${row('Ask the owner and office for an emailed code', 'When they sign in on a new device they must type the 6-digit code sent to their email. It needs working email; without it nobody is blocked.', `<input type="checkbox" name="two_step_office" ${r.two_step_office !== false ? 'checked' : ''}>`, 'chkrow')}</div>
    <button class="btn pri" type="submit">Save rules</button></form>`;
}

/* --- Integration --- */
async function integrationsView() {
  const [i, customers] = await Promise.all([api('/integrations/accountisi'), api('/customers')]);
  const linked = customers.filter(c => c.accountisi_id);
  const pv = S.cache.salesPreview;
  return `<h1>Accountisi integration</h1><p class="sub">Optional. If you do not use it, the rest of the system works the same.
    ${i.last_ok_at ? 'Last successful connection: ' + esc(String(i.last_ok_at).slice(0, 16).replace('T', ' ')) : ''} ${i.last_error ? '<span style="color:var(--danger)">' + esc(i.last_error) + '</span>' : ''}</p>
    <div class="card"><form data-form="accountisi"><div class="grid">
    <div><label>API URL</label><input name="base_url" value="${esc(i.base_url || 'http://localhost:5099')}" required><small class="help">Localhost today, the server tomorrow.</small></div>
    <div><label>Access</label><select name="auth_mode"><option value="login" ${i.auth_mode !== 'api_key' ? 'selected' : ''}>Service user</option><option value="api_key" ${i.auth_mode === 'api_key' ? 'selected' : ''}>Service credential (API key)</option></select></div>
    <div><label>Username</label><input name="login" value="${esc(i.login)}"></div>
    <div>${pwField('password', 'Password' + (i.has_password ? ' (saved)' : ''), { auto: 'new-password', optional: true, ph: i.has_password ? 'unchanged' : '' })}</div>
    <div>${pwField('api_key', 'API key' + (i.has_api_key ? ' (saved)' : ''), { auto: 'new-password', optional: true })}</div>
    <div><label>Company id</label><input name="company_id" type="number" value="${esc(i.company_id)}"><small class="help">The company in Accountisi.</small></div>
    <div><label>Sales service item</label><input name="default_item_id" type="number" value="${esc(i.default_item_id)}"><small class="help">Used on sales invoices.</small></div>
    <div><label>Income account</label><input name="default_income_account_id" type="number" value="${esc(i.default_income_account_id)}"><small class="help">Used if there is no item.</small></div>
    <div><label>Bills service item</label><input name="default_service_item_id" type="number" value="${esc(i.default_service_item_id)}"><small class="help">Used on bills.</small></div>
    <div><label>Expense account</label><input name="default_expense_account_id" type="number" value="${esc(i.default_expense_account_id)}"><small class="help">Used if there is no item.</small></div></div>
    <p><label class="chk"><input type="checkbox" name="enabled" ${i.enabled ? 'checked' : ''}> Integration enabled</label></p>
    <p class="row"><button class="btn pri" type="submit">Save</button><button class="btn ghost" type="button" data-act="acc-test">Test connection</button>
    <button class="btn ghost" type="button" data-act="acc-sync">Pull clients from Accountisi</button></p></form></div>
    <div class="card"><h2>Create a sales invoice in Accountisi</h2><p class="muted">Uses a client's approved hours that have not been invoiced yet. The project goes in the invoice comment.</p>
    <form data-form="sales" class="grid"><div><label>Client</label><select name="customer_id" required>${linked.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></div>
    <div><label>From</label><input name="from" type="date" value="${addDays(today(), -30)}"></div><div><label>To</label><input name="to" type="date" value="${today()}"></div>
    <div><label>Invoice</label><select name="mode"><option value="new">Create a new one</option><option value="append">Add to an open one</option></select></div>
    <div><label>Open invoice id</label><input name="accountisi_invoice_id"><small class="help">Only when adding to an open one.</small></div>
    <div style="grid-column:span 2"><label>Extra comment</label><input name="comment" maxlength="300"></div>
    <div style="align-self:end" class="row"><button class="btn ghost" type="button" data-act="sales-preview">Preview</button><button class="btn pri" type="submit">Create in Accountisi</button></div></form>
    ${pv ? `<div style="margin-top:12px"><p><b>${hrs(pv.total_hours)} h</b> · ${money(pv.amount)} · ${esc(pv.comment)}</p>${pv.projects_without_rate.length ? `<div class="msg warn">Missing rate on: ${esc(pv.projects_without_rate.join(', '))}</div>` : ''}
    <table>${pv.lines.map(l => `<tr><td>${esc(l.description)}</td><td class="r">${hrs(l.quantity)} h</td><td class="r">${money(l.unit_price)}</td></tr>`).join('')}</table></div>` : ''}</div>`;
}

/* --- Client timesheet (owner) --- */
async function clientSheetView() {
  const [customers, projects, users] = await Promise.all([api('/customers'), api('/projects'), api('/users')]);
  const q = S.cache.cs || { customer_id: customers[0]?.id, from: addDays(today(), -30), to: today() };
  const pv = S.cache.csPreview;
  const cust = customers.find(c => c.id === Number(q.customer_id));
  const ofCustomer = projects.filter(p => p.customer_id === Number(q.customer_id));
  return `<h1>Client timesheet</h1>
    <p class="sub">A client's hours for a period, with a description of the work. By default only approved billable hours appear, without rates.</p>
    <div class="card"><form data-form="cs"><div class="grid">
    <div><label>Client</label><select name="customer_id" data-act-change="cs-customer">${customers.map(c => `<option value="${c.id}" ${c.id === Number(q.customer_id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
    <div><label>From</label><input name="from" type="date" value="${esc(q.from)}"></div><div><label>To</label><input name="to" type="date" value="${esc(q.to)}"></div>
    <div><label>Person</label><select name="user_id"><option value="">All</option>${users.map(u => `<option value="${u.id}" ${Number(q.user_id) === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></div></div>
    <p><label>Client projects (none ticked = all)</label><span class="row">${ofCustomer.map(p => `<label class="chk"><input type="checkbox" data-multi="1" name="project_ids" value="${p.id}" ${(q.project_ids || []).includes(p.id) ? 'checked' : ''}> ${esc(p.code)} · ${esc(p.name)}</label>`).join('') || '<span class="muted">This client has no projects.</span>'}</span></p>
    <p class="row"><label class="chk"><input type="checkbox" name="include_pending" ${q.include_pending ? 'checked' : ''}> Include hours not yet approved (they are marked)</label>
    <label class="chk"><input type="checkbox" name="include_non_billable" ${q.include_non_billable ? 'checked' : ''}> Include non-billable</label>
    <label class="chk"><input type="checkbox" name="show_amounts" ${q.show_amounts ? 'checked' : ''}> Show amounts</label></p>
    <p class="row"><button class="btn pri" type="submit">Preview</button>
    <button class="btn ghost" type="button" data-act="cs-pdf">Download PDF</button>
    <button class="btn ghost" type="button" data-act="cs-xlsx">Download Excel</button>
    <button class="btn ghost" type="button" data-act="cs-csv">Download CSV</button></p></form></div>
    ${pv ? csPreviewHtml(pv) + `<div class="card"><h2>Send by email</h2><p class="muted">The PDF is sent only when you ask for it here.</p>
      <form data-form="cs-email"><div class="grid"><div><label>Client email</label><input name="email_to" type="email" value="${esc(S.cache.csEmail ?? cust?.email ?? '')}" required></div>
      <div style="grid-column:span 2"><label>Message (optional)</label><input name="message" maxlength="1000"></div></div>
      <p><button class="btn pri" type="submit" ${pv.entry_count ? '' : 'disabled'}>Send the timesheet by email</button></p></form></div>` : ''}`;
}
const csTimes = l => l.entered_as === 'times' || (!l.entered_as && !!l.start);   // hours typed as From–To vs just a total
function csPreviewHtml(d) {
  if (!d.projects.length) return '<div class="card"><p class="muted">There are no hours for this client in the period.</p></div>';
  return `<div class="card"><h2>${esc(d.customer)} · ${esc(d.from)} to ${esc(d.to)}</h2>${d.has_pending ? '<div class="msg warn">Includes hours not yet approved.</div>' : ''}
    ${d.projects.map(p => `<h2 style="margin-top:14px">${esc(p.code)} · ${esc(p.name)}</h2>
    <table><tr><th>Date</th><th>Person</th>${p.lines.some(csTimes) ? '<th>From – To</th>' : ''}<th class="r">Hours</th><th>Description</th>${d.show_amounts ? '<th class="r">Amount</th>' : ''}</tr>
    ${p.lines.map(l => `<tr><td>${esc(l.date)}</td><td>${esc(l.worker)}</td>${p.lines.some(csTimes) ? `<td>${csTimes(l) ? esc(l.start) + '–' + esc(l.end) : ''}</td>` : ''}<td class="r">${hrs(l.hours)}</td>
      <td style="white-space:pre-wrap">${esc(l.description)}${l.approved ? '' : ' <span class="badge submitted">not approved</span>'}</td>${d.show_amounts ? `<td class="r">${money(l.amount)}</td>` : ''}</tr>`).join('')}
    <tr><th colspan="${p.lines.some(csTimes) ? 3 : 2}">Subtotal ${esc(p.code)}</th><th class="r">${hrs(p.hours)}</th><th></th>${d.show_amounts ? `<th class="r">${money(p.amount)}</th>` : ''}</tr></table>`).join('')}
    <p style="margin-top:12px"><b>Total: ${hrs(d.total_hours)} h</b>${d.show_amounts ? ' · ' + money(d.total_amount) : ''}</p></div>`;
}
function csBody(f) {
  const o = formObj(f);
  const body = { customer_id: Number(o.customer_id), from: o.from, to: o.to, project_ids: o.project_ids || [], include_pending: !!o.include_pending,
    include_non_billable: !!o.include_non_billable, show_amounts: !!o.show_amounts };
  if (o.user_id) body.user_ids = [Number(o.user_id)];
  S.cache.cs = { ...body, user_id: o.user_id };
  return body;
}

/* ---------- entry form: the service list follows the project and the fields follow the service ---------- */
function syncEntryForm(f, wantSvc) {
  const p = (S.cache.projects || []).find(x => x.id === Number(f.elements.project_id.value));
  const svcs = (p?.services || []).filter(s => s.active);
  const cur = wantSvc ?? Number(f.elements.service_id.value);
  f.elements.service_id.innerHTML = svcs.map(s => `<option value="${s.id}" ${s.id === cur ? 'selected' : ''}>${esc(s.name)}</option>`).join('');
  f.elements.service_id.closest('div').hidden = svcs.length <= 1;   // with one service there is nothing to choose
  const s = svcs.find(x => x.id === Number(f.elements.service_id.value)) || svcs[0];
  const detail = (s?.mode || S.me.capture_mode) === 'from_to';
  f.querySelectorAll('[data-f]').forEach(el => {
    const on = (el.dataset.f === 'detail') === detail;
    el.hidden = !on; el.querySelectorAll('input').forEach(i => { i.disabled = !on; i.required = on; });
  });
}
function syncClockForm(f) {
  const p = (S.cache.projects || []).find(x => x.id === Number(f.elements.project_id.value));
  const svcs = (p?.services || []).filter(s => s.active && s.mode === 'from_to');
  f.elements.service_id.innerHTML = svcs.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  f.elements.service_id.hidden = svcs.length <= 1;
}
function afterRender() {
  document.querySelectorAll('form[data-form=entry]').forEach(f => syncEntryForm(f));
  document.querySelectorAll('form[data-form=clock]').forEach(f => syncClockForm(f));
}

/* ---------- actions ---------- */
function parseMeta(txt) { const o = {}; (txt || '').split('\n').forEach(l => { const i = l.indexOf('='); if (i > 0) o[l.slice(0, i).trim()] = l.slice(i + 1).trim(); }); return o; }
const actions = {
  logout, 'pw-eye'(el) {
    const inp = el.closest('.pw').querySelector('input'); const show = inp.type === 'password'; inp.type = show ? 'text' : 'password';
    const t = T(show ? 'Hide password' : 'Show password'); el.setAttribute('aria-pressed', String(show)); el.setAttribute('aria-label', t); el.title = t;
  },
  async 'code-resend'() {
    if (!S.login || resendLeft() > 0) return;
    try { await api('/login/code/resend', { method: 'POST', anon: true, body: { challenge: S.login.challenge } }); S.login.resendAt = Date.now() + 60000; loginView(null, 'A new code was sent to ' + S.login.email_hint); }
    catch (e) { loginView(loginError(e)); }
  },
  'login-back'() { S.login = null; clearInterval(S.cache.rt); loginView(); },
  'sp-login'() { S.cache.sp = null; logout(); },
  async 'reset-user'(el) {
    if (!confirm(T(el.dataset.name + ' will receive an email to create a new password and will be signed out everywhere.'))) return;
    const r = await api(`/users/${el.dataset.id}/reset-password`, { method: 'POST' }); flash('Reset email sent to ' + r.emailed_to); render();
  },
  async 'resend-invite'(el) { await api(`/users/${el.dataset.id}/resend-invitation`, { method: 'POST' }); flash('Invitation sent to ' + el.dataset.email); render(); },
  'apr-status'(el) { S.cache.apStatus = el.dataset.s; S.cache.apSel = null; render(); },
  'apr-pick'(el) { S.cache.apSel = Number(el.dataset.id); render(); },
  async 'apr-code'(el) {
    const id = Number(el.dataset.id); saveDraft(id);
    const r = await api(`/approver/sheets/${id}/code`, { method: 'POST' }); (S.cache.apCode ??= {})[id] = true;
    flash('Code sent to your email. It is valid for ' + Math.max(1, Math.round((r.expires_in || 600) / 60)) + ' minutes.'); render();
  },
  async 'apr-approve'(el) { await aprDecide(el, 'approve'); },
  async 'apr-return'(el) { await aprDecide(el, 'return'); },
  async 'int-approve'(el) { await aprDecide(el, 'approve', '/internal/sheets/'); },
  async 'int-return'(el) { await aprDecide(el, 'return', '/internal/sheets/'); },
  wk: el => { S.weekDate = addDays(S.weekDate, Number(el.dataset.n)); render(); },
  'wk-now': () => { S.weekDate = today(); render(); },
  'open-week': el => { S.weekDate = el.dataset.end; location.hash = '#/week'; },
  'ap-pick': el => { S.cache.apSel = Number(el.dataset.id); render(); },
  'cancel-edit': () => { setTimeout(() => { const t = document.getElementById('eform-title'); if (t) t.textContent = 'Add entry'; const f = document.querySelector('form[data-form=entry]'); if (f) f.elements.id.value = ''; }); },
  async edit(el) {
    const e = S.cache.entries.find(x => x.id === Number(el.dataset.id)); const f = document.querySelector('form[data-form=entry]'); if (!e || !f) return;
    f.elements.id.value = e.id; f.elements.work_date.value = e.work_date; f.elements.project_id.value = e.project_id; f.elements.note.value = e.note || '';
    syncEntryForm(f, e.service_id);
    f.elements.hours.value = e.start_time ? '' : e.hours; f.elements.start_time.value = e.start_time || ''; f.elements.end_time.value = e.end_time || '';
    document.getElementById('eform-title').textContent = 'Edit entry'; f.scrollIntoView({ behavior: 'smooth' });
  },
  async del(el) { if (!confirm(T('Delete this entry?'))) return; await api('/entries/' + el.dataset.id, { method: 'DELETE' }); flash('Entry deleted.'); render(); },
  async submit(el) { await api(`/sheets/${el.dataset.id}/submit`, { method: 'POST' }); flash('Timesheet submitted for approval.'); render(); },
  async 'clock-stop'() { await api('/clock/stop', { method: 'POST', body: {} }); flash('Clock stopped.'); render(); },
  async approve(el) { await api(`/sheets/${el.dataset.id}/approve`, { method: 'POST', body: { version: Number(el.dataset.v) } }); flash('Timesheet approved.'); render(); },
  async return(el) {
    const c = (document.getElementById('ap-comment')?.value || '').trim();
    if (!c) throw new Error('Write a comment saying what needs to be corrected.');
    await api(`/sheets/${el.dataset.id}/return`, { method: 'POST', body: { comment: c, version: Number(el.dataset.v) } }); flash('Timesheet returned for correction.'); render();
  },
  pdf: el => download(`/contractor-invoices/${el.dataset.id}/pdf`, {}, 'invoice.pdf'),
  async 'push-bill'(el) { const r = await api(`/contractor-invoices/${el.dataset.id}/push-accountisi`, { method: 'POST' }); flash(r.accountisi_status === 'draft' ? 'Created in Accountisi as a draft.' : 'Status in Accountisi: ' + r.accountisi_status, r.accountisi_status === 'draft' ? 'ok' : 'warn'); render(); },
  'exp-all'(el) { document.querySelectorAll('input[name="project_ids"]').forEach(c => { c.checked = el.dataset.on === '1'; }); },
  async 'del-tpl'(el) { if (!confirm(T('Delete the template?'))) return; await api('/export-templates/' + el.dataset.id, { method: 'DELETE' }); render(); },
  lang: async el => { const to = el.dataset.to; S.lang = to; localStorage.setItem('lang', to); if (S.token) { S.me = await api('/me/locale', { method: 'PUT', body: { locale: to } }); } render(); },
  async 'toggle-approver'(el) { const a = (await api('/approvers')).find(x => x.id === Number(el.dataset.id)); await api('/approvers/' + a.id, { method: 'PUT', body: { name: a.name, email: a.email, require_code: a.require_code, can_login: !!a.can_login, active: !a.active, customer_ids: a.customer_ids || [] } }); render(); },
  async 'toggle-login'(el) {
    const a = (await api('/approvers')).find(x => x.id === Number(el.dataset.id)); const on = !a.can_login;
    if (!on && !confirm(T('This approver will no longer be able to sign in. Their sessions will be closed.'))) return;
    await api('/approvers/' + a.id, { method: 'PUT', body: { name: a.name, email: a.email, require_code: a.require_code, can_login: on, active: a.active, customer_ids: a.customer_ids || [] } });
    flash(on ? 'Invitation sent to ' + a.email : 'Sign-in removed.'); render();
  },
  async 'revert-brand'(el) { await api(`/brand/history/${el.dataset.id}/revert`, { method: 'POST' }); S.me = null; flash('Branding restored.'); render(); },
  async 'acc-test'() { try { await api('/integrations/accountisi/test', { method: 'POST' }); flash('Connection to Accountisi works.'); } catch (e) { flash(e.message, 'err'); } render(); },
  async 'acc-sync'() { const r = await api('/integrations/accountisi/sync-customers', { method: 'POST' }); flash(`Clients linked: ${r.linked}, created: ${r.created}.`); render(); },
  'f-clear'(el) { (S.cache.f ??= {})[el.dataset.view] = {}; render(); },
  async tx(el) {
    const q = JSON.parse(el.dataset.q || '{}'); const fmtName = { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' }[el.dataset.fmt];
    await download(`/tables/${el.dataset.ds}/export`, { method: 'POST', body: { ...q, format: el.dataset.fmt } }, 'table.' + el.dataset.fmt);
    flash(fmtName + ' file created.'); render();
  },
  async 'cs-csv'() { const b = csBody(document.querySelector('form[data-form=cs]')); await download('/client-timesheet/download', { method: 'POST', body: { ...b, format: 'csv' } }, 'timesheet.csv'); flash('CSV file created.'); render(); },
  async 'cs-pdf'() { const b = csBody(document.querySelector('form[data-form=cs]')); await download('/client-timesheet/download', { method: 'POST', body: { ...b, format: 'pdf' } }, 'timesheet.pdf'); flash('PDF file created.'); render(); },
  async 'cs-xlsx'() { const b = csBody(document.querySelector('form[data-form=cs]')); await download('/client-timesheet/download', { method: 'POST', body: { ...b, format: 'xlsx' } }, 'timesheet.xlsx'); flash('Excel file created.'); render(); },
  async 'sales-preview'() { const f = document.querySelector('form[data-form=sales]'); const o = formObj(f); try { S.cache.salesPreview = await api('/integrations/accountisi/sales-preview', { method: 'POST', body: o }); } catch (e) { flash(e.message, 'err'); } render(); },
};
async function aprDecide(el, kind, base = '/approver/sheets/') {
  const id = Number(el.dataset.id); saveDraft(id);
  const { comment, code } = S.cache.apDraft;
  if (kind === 'return' && !comment.trim()) throw new Error('Write a comment saying what needs to be corrected.');
  const body = { version: Number(el.dataset.v), ...(comment.trim() ? { comment: comment.trim() } : {}), ...(code.trim() ? { code: code.trim() } : {}) };
  try { await api(`${base}${id}/${kind}`, { method: 'POST', body }); }
  catch (e) { if (e.needs_code || e.errors?.code) { (S.cache.apCode ??= {})[id] = true; } throw e; }
  S.cache.apDraft = null; flash(kind === 'approve' ? 'Timesheet approved.' : 'Timesheet returned for correction.'); render();
}
const forms = {
  async login(f) {
    if (f.dataset.busy) return;   // Enter pressed twice: one request only
    const o = formObj(f); S.cache.loginEmail = o.email;
    const ts = f.querySelector('[name="cf-turnstile-response"]');
    if (S.cache.pub?.turnstile_site_key && !(ts && ts.value)) return loginInline(f, 'Complete the security check first.');   // never fail silently, and keep what was typed
    f.dataset.busy = '1'; const go = f.querySelector('[type=submit]'); if (go) go.disabled = true;
    let dt = null; try { dt = localStorage.getItem('dt'); } catch { /* storage blocked */ }
    try {
      const r = await api('/login/web', { method: 'POST', anon: true, body: { email: o.email, password: o.password, device: 'web', ...(ts ? { turnstile_token: ts.value } : {}), ...(dt ? { device_token: dt } : {}) } });
      if (r.step === 'code') { S.login = { step: 'code', challenge: r.challenge, email_hint: r.email_hint, remember_days: Number(r.remember_days || 0), expires_in: r.expires_in, resendAt: Date.now() + 60000 }; return loginView(); }
      finishLogin(r);
    } catch (e) { loginView(loginError(e)); }
  },
  async 'login-code'(f) {
    if (f.dataset.busy) return;
    const o = formObj(f); const L = S.login; if (!L) return loginView();
    if (String(o.code || '').replace(/\D/g, '').length !== 6) return loginInline(f, 'Type the 6-digit code.');
    f.dataset.busy = '1'; const go = f.querySelector('[type=submit]'); if (go) go.disabled = true;
    try { finishLogin(await api('/login/code', { method: 'POST', anon: true, body: { challenge: L.challenge, code: String(o.code || '').replace(/\D/g, ''), remember: !!o.remember } })); }
    catch (e) {
      if (e.status === 410 || e.code === 'expired' || e.code === 'challenge') { S.login = null; return loginView('The code expired. Sign in again to get a new one.'); }
      loginView(e.status === 429 || e.code === 'locked' ? loginError(e) : e.message || 'The code is not correct.');
    }
  },
  async 'set-password'(f) {
    const o = formObj(f); const sp = S.cache.sp;
    try { await api('/password/setup', { method: 'POST', anon: true, body: { token: sp.token, password: o.password } }); sp.done = true; setPasswordView(sp.token); }
    catch (e) { if (e.status === 404 || e.status === 410) { sp.bad = 'expired'; return setPasswordView(sp.token); } setPasswordView(sp.token, e.message); }
  },
  async 'change-pw'(f) {
    const o = formObj(f);
    if (o.current_password === o.password) throw new Error('The new password must be different from the current one.');
    await api('/password/change', { method: 'POST', body: { current_password: o.current_password, password: o.password } });
    flash('Password changed. Your other sessions were signed out.'); render();
  },
  async entry(f) {
    const o = formObj(f); const id = o.id; delete o.id; if (!o.service_id) delete o.service_id;
    if (id) await api('/entries/' + id, { method: 'PUT', body: o }); else await api('/entries', { method: 'POST', body: o });
    flash(id ? 'Entry updated.' : 'Entry saved.'); render();
  },
  async clock(f) { const o = formObj(f); if (!o.service_id) delete o.service_id; await api('/clock/start', { method: 'POST', body: o }); flash('Clock started.'); render(); },
  report(f) { S.cache.rep = formObj(f); render(); },
  async cinv(f) { const o = formObj(f); if (!o.user_id) delete o.user_id; const r = await api('/contractor-invoices', { method: 'POST', body: o }); flash('Invoice ' + r.number + ' created and emailed.'); render(); },
  async export(f) {
    const o = formObj(f); const tpl = S.cache.tpls.find(t => t.id === Number(o.template_id)); if (!o.user_id) delete o.user_id; if (!o.customer_id) delete o.customer_id;
    if (f.querySelector('input[name="project_ids"]') && !(o.project_ids || []).length) throw new Error('Pick at least one patient.');
    if (!o.project_ids) delete o.project_ids; else o.customer_id = Number(o.customer_id);
    const r = await download('/export', { method: 'POST', body: o }, 'export.' + tpl.format); flash('File created (' + r.rows + ' rows).'); render();
  },
  async tpl(f) {
    const o = formObj(f);
    const columns = o.columns.split('\n').map(l => l.split('|').map(s => s.trim())).filter(p => p[0] && p[1]).map(([header, source]) => ({ header, source, ...(source === 'date' ? { format: 'date' } : {}) }));
    await api('/export-templates', { method: 'POST', body: { name: o.name, format: o.format, config: { sheet_name: o.name.slice(0, 30), group_by: o.group ? o.group.split(',') : [], filters: { status: 'approved' }, columns } } });
    flash('Template created.'); render();
  },
  async cs(f) { S.cache.csPreview = await api('/client-timesheet/preview', { method: 'POST', body: csBody(f) }); S.cache.csEmail = undefined; render(); },
  async 'cs-email'(f) {
    const o = formObj(f); const body = { ...S.cache.cs }; delete body.user_id;
    if (!confirm(T('Send the timesheet to') + ' ' + o.email_to + '?')) return;
    S.cache.csEmail = o.email_to;
    const r = await api('/client-timesheet/email', { method: 'POST', body: { ...body, email_to: o.email_to, message: o.message || undefined } });
    flash('Timesheet sent to ' + r.sent_to + ' (' + hrs(r.total_hours) + ' h).'); render();
  },
  async brand(f) {
    const fd = new FormData(); const o = formObj(f);
    for (const [k, v] of Object.entries(o)) { if (v === null || v === undefined) continue; fd.append(k, v === true ? '1' : v === false ? '0' : v); }
    await api('/brand', { method: 'POST', body: fd }); S.me = null; flash('Branding saved.'); render();
  },
  async rules(f) {
    const o = formObj(f); const { name, invoice_prefix, flag_late, locale, domain, partial_submission } = o; delete o.partial_submission; delete o.name; delete o.invoice_prefix; delete o.locale; delete o.domain;
    const rules = {}; for (const [k, v] of Object.entries(o)) rules[k] = v;
    rules.week_starts_on = Number(rules.week_starts_on); rules.session_max_days = Number(rules.session_max_days ?? 14); rules.session_max_days_office = Number(rules.session_max_days_office ?? 7); rules.two_step_office = !!o.two_step_office;
    rules.flag_late = !!flag_late; rules.partial_submission = !!partial_submission; rules.increment = Number(rules.increment); rules.remember_days = Number(rules.remember_days ?? 30);
    await api('/company', { method: 'PUT', body: { name, invoice_prefix, locale, domain: domain ? String(domain).trim().toLowerCase() : null, rules } }); S.me = null; flash('Rules saved.'); render();
  },
  async accountisi(f) {
    const o = formObj(f); for (const k of Object.keys(o)) if (o[k] === null) delete o[k]; o.enabled = !!o.enabled;
    await api('/integrations/accountisi', { method: 'PUT', body: o }); flash('Settings saved.'); render();
  },
  async sales(f) {
    const o = formObj(f); for (const k of Object.keys(o)) if (o[k] === null) delete o[k];
    const r = await api('/integrations/accountisi/sales-invoice', { method: 'POST', body: o });
    S.cache.salesPreview = null; flash('Invoice created in Accountisi (id ' + r.accountisi_invoice_id + ').'); render();
  },
};
/* ===== WEB-PERSONAS: People and Approvals screens, copied from the mockup (styles: people.css, Spanish: i18n-es-people.js) ===== */
const optHtml = (v, l, cur) => `<option value="${esc(v)}" ${String(cur ?? '') === String(v) ? 'selected' : ''}>${esc(l)}</option>`;
const numOrNull = v => (v === '' || v == null ? null : Number(v));
const nz = v => (v === '' || v == null ? '' : String(Number(v)));
async function peopleScreen() {
  const [accounts, users] = await Promise.all([api('/accounts'), api('/users')]);
  S.cache.ppUsers = users;
  const F = FL('ppl');
  const shown = accounts.filter(a => matchQ(F.q, [a.name, a.email]) && (!F.role || a.role === F.role) && (!F.worker_type || a.worker_type === F.worker_type));
  const ids = new Set(shown.map(a => a.user_id));
  const billing = users.filter(x => x.worker_type === 'consultant' && ids.has(x.id));
  const roleName = { worker: 'Worker', approver: 'Approver', backoffice: 'Back office' };
  const stPill = { active: pill('Active', 'green'), invited: pill('Invited', 'amber'), disabled: pill('Disabled', 'red') };
  const row = a => {
    const edit = a.role === 'approver' && a.approver_id && a.approver_kind !== 'internal' ?`<button class="btn ghost sm" data-act="pp-edit-approver" data-id="${a.approver_id}">Edit</button>`
      : a.user_id ? `<button class="btn ghost sm" data-act="pp-edit" data-id="${a.user_id}">Edit</button>` : '';
    const reset = a.user_id && a.user_id !== S.me.id ? `<button class="btn ghost sm" data-act="reset-user" data-id="${a.user_id}" data-name="${esc(a.name)}">Reset password</button>` : '';
    const resend = a.status === 'invited' && a.user_id ? `<button class="btn ghost sm" data-act="resend-invite" data-id="${a.user_id}" data-email="${esc(a.email)}">Resend invitation</button>` : '';
    return `<div class="rw cols-users"><div class="nm">${esc(a.name)}</div><div class="em">${esc(a.email)}</div><div>${roleName[a.role] || ''}${a.role === 'approver' && a.approver_kind ? `<div class="note">${a.approver_kind === 'internal' ? 'Internal' : 'External'}</div>` : ''}</div>
      <div>${a.worker_type === 'employee' ? 'Employee' : a.worker_type === 'consultant' ? 'Consultant' : '—'}</div><div>${esc(a.employee_number || '—')}</div>
      <div>${a.two_step ? pill('Enabled', 'green') : a.status === 'invited' ? pill('Not set up') : '—'}</div><div>${stPill[a.status] || pill('None')}</div><div class="acts">${edit}${reset}${resend}</div></div>`;
  };
  const bill = x => { const b = x.terms || {};
    return `<div class="rw cols-bill" data-brow="${x.id}"><div class="nm">${esc(x.name)}</div>
      <input class="num" type="number" min="0" step="0.01" aria-label="Hourly rate" data-bill="rate" value="${esc(nz(b.rate))}">
      <input class="num" type="number" min="0" max="100" step="0.5" aria-label="Professional services retention percent" data-bill="retention_pct" value="${esc(nz(b.retention_pct))}">
      <label class="bchk"><input type="checkbox" data-bill="ivu_agent" ${b.ivu_agent ? 'checked' : ''}><span>${b.ivu_agent ? 'Yes, adds IVU' : 'No'}</span></label>
      <input class="num" type="number" min="0" max="100" step="0.5" aria-label="IVU percent" data-bill="ivu_pct" ${b.ivu_agent ? '' : 'disabled'} value="${esc(nz(b.ivu_pct))}"></div>`; };
  return `<div class="pp"><div class="pghead"><div><h1>People &amp; rates</h1><p class="sub">Invite users, set roles, and configure each contractor's billing terms.</p></div><button class="btn pri" type="button" data-act="inv-open">Invite</button></div>
    <div class="card flt">
      <label class="flbl" style="flex:2 1 240px">Search by name or email<input type="search" data-filter="ppl" name="q" placeholder="Start typing" value="${esc(F.q ?? '')}"></label>
      <label class="flbl" style="flex:1 1 160px">Role<select data-filter="ppl" name="role">${optHtml('', 'All roles', F.role)}${optHtml('worker', 'Worker', F.role)}${optHtml('approver', 'Approver', F.role)}${optHtml('backoffice', 'Back office', F.role)}</select></label>
      <label class="flbl" style="flex:1 1 160px">Worker type<select data-filter="ppl" name="worker_type">${optHtml('', 'All types', F.worker_type)}${optHtml('employee', 'Employee', F.worker_type)}${optHtml('consultant', 'Consultant', F.worker_type)}</select></label>
      <div class="note" style="flex:1 1 100%">Showing ${shown.length} of ${accounts.length} users. The search also filters the contractor billing terms below.</div></div>
    <div class="card"><h2 style="font-size:18px;margin-bottom:12px">Users</h2><div class="scrollx"><div style="min-width:1260px">
      <div class="hd cols-users"><div class="th">Name</div><div class="th">Email</div><div class="th">Role</div><div class="th">Worker type</div><div class="th">ID number</div><div class="th">2-step</div><div class="th">Status</div><div class="th"></div></div>
      ${shown.map(row).join('') || '<div class="empty">No user matches the filter.</div>'}</div></div></div>
    <div class="card"><h2 style="font-size:18px">Contractor billing terms</h2><p class="muted" style="font-size:15px;margin:4px 0 12px">Applies to contractors only. Employees have no billing terms: the system records hours, nothing else. Changes affect new invoices, never invoices already submitted.</p>
      <div class="scrollx"><div style="min-width:760px"><div class="hd cols-bill"><div class="th">Contractor</div><div class="th">Rate ($/h)</div><div class="th">Retention (%)</div><div class="th">IVU withholding agent</div><div class="th">IVU (%)</div></div>
      ${billing.map(bill).join('') || '<div class="empty">No contractor matches the filter.</div>'}</div></div></div>
    </div>`;
}
/* Approvals (owner and approver see the same screen): status pills with counts, filters, list on the left, the selected timesheet on the right. */
function rvState() {
  const R = (S.cache.rv ??= { status: 'pending', q: '', type: '', period: '4w', from: '', to: '' });
  if (R.period === '4w') { R.from = addDays(today(), -28); R.to = ''; } else if (R.period === '3m') { R.from = addDays(today(), -91); R.to = ''; } else if (R.period === 'all') { R.from = ''; R.to = ''; }
  return R;
}
async function reviewScreen() {
  const ap = isAp(), inn = isIn(); const R = rvState(); const pend = R.status === 'pending';
  const qs = new URLSearchParams({ status: R.status });
  if (R.q.trim()) qs.set('q', R.q.trim());
  if (R.type) qs.set('type', R.type);
  if (!pend) { if (R.from) qs.set('from', R.from); if (R.to) qs.set('to', R.to); }
  const res = await api((ap ? '/approver/review?' : inn ? '/internal/review?' : '/review/sheets?') + qs.toString());
  const sheets = res.sheets || [], cnt = res.counts || {};
  const sel = sheets.find(s => s.id === S.cache.apSel) || sheets[0];
  const stColor = { submitted: 'amber', pending: 'amber', approved: 'green', returned: 'red' };
  const stName = s => (s === 'submitted' || s === 'pending' ? 'Pending' : STATUS[s] || s);
  // The owner sees which stage a pending sheet is in: waiting for the internal approvers or for the client.
  const stOf = s => ((s.status === 'submitted' || s.status === 'pending') && !ap && !inn && (s.stage === 'internal' || s.stage === 'external') ? (s.stage === 'internal' ? 'Pending · internal' : 'Pending · client') : stName(s.status));
  const waitTxt = s => (!inn && s.stage && (s.waiting_for || []).length ? `<p class="note" style="margin:0">${T(s.stage === 'internal' ? 'Waiting for' : 'Waiting for client approval:')} ${esc(wkNames(s.waiting_for, 3))}</p>` : '');
  const typeName = s => (s.worker_type === 'consultant' ? 'Contractor' : 'Employee');
  const nameOf = s => s.user?.name ?? s.name ?? s.worker_name ?? '';
  const projOf = s => (s.projects || []).map(p => (typeof p === 'string' ? p : [p.code, p.name].filter(Boolean).join(' · '))).join(', ');
  let detail = '<div class="card r"><div class="empty">No timesheet selected.</div></div>';
  if (sel) {
    let d = null; try { d = await api((ap ? '/approver/sheets/' : inn ? '/internal/sheets/' : '/sheets/') + sel.id); } catch (e) { if (e.message === 'Session expired') throw e; }
    const days = Array.from({ length: wkSpan(d?.period_starting || addDays(sel.week_ending, -6), d?.period_ending || sel.week_ending) }, (_, i) => addDays(d?.period_starting || addDays(sel.week_ending, -6), i));
    const lines = new Map();
    for (const e of (d?.entries || [])) { const k = e.project_id + '|' + (e.service_id || ''); if (!lines.has(k)) lines.set(k, { p: e.project, s: e.service, by: {} }); const r = lines.get(k); r.by[e.work_date] = (r.by[e.work_date] || 0) + e.hours; }
    const open = sel.status === 'submitted' || sel.status === 'pending';
    const dr = S.cache.apDraft && S.cache.apDraft.id === sel.id ? S.cache.apDraft : { comment: '', code: '' };
    const needs = ap && open && (d?.needs_code || sel.needs_code || (S.cache.apCode || {})[sel.id]);
    const ret = sel.status === 'returned' && (d?.return_comment || sel.return_comment) ? `<div class="msg err"><b>Returned for correction:</b> ${esc(d?.return_comment || sel.return_comment)}${(d?.return_by || sel.return_by) ? ` <span class="muted">${T('Returned by')} ${esc(d?.return_by?.name || d?.return_by || sel.return_by?.name || sel.return_by)}</span>` : ''}</div>` : '';
    const A = ap ? ['apr-approve', 'apr-return'] : inn ? ['int-approve', 'int-return'] : ['approve', 'return'];
    const acts = open ? `<div style="display:flex;flex-direction:column;gap:10px"><label class="lbl">Comment (required when returning)<textarea id="ap-comment" placeholder="Example: Thursday should be OT, please fix and resubmit.">${esc(dr.comment)}</textarea></label>
      ${needs ? `<div class="msg warn">This decision needs a 6-digit code. We send it to your email.</div><div class="row" style="align-items:end"><button class="btn ghost" type="button" data-act="apr-code" data-id="${sel.id}">Send me a code</button>
        <label class="lbl" style="max-width:200px">6-digit code<input id="ap-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" value="${esc(dr.code)}" style="letter-spacing:.2em"></label></div>` : ''}
      <div class="decided"><button class="btn" data-act="${A[0]}" data-id="${sel.id}" data-v="${sel.version}">Approve</button><button class="btn danger" data-act="${A[1]}" data-id="${sel.id}" data-v="${sel.version}">Return for correction</button></div></div>`
      : (sel.decided_at ? `<p class="muted" style="margin:0">Decided on ${esc(String(sel.decided_at).slice(0, 10))}.</p>` : '');
    const cell = (v, extra = '') => `<div class="mono" style="text-align:center;${extra}">${v ? h1(v) : ''}</div>`;
    detail = `<div class="card r"><div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;align-items:center"><div><h2 style="font-size:19px">${esc(nameOf(sel))}</h2><p style="margin:0">${typeName(sel)} · week ending ${nice(sel.week_ending)}${sel.is_part ? ' · ' + T('Part') + ' ' + sel.part + ' (' + nice(sel.period_starting) + (sel.period_starting === sel.period_ending ? '' : ' – ' + nice(sel.period_ending)) + ')' : ''}</p></div>${pill(stOf(sel), stColor[sel.status] || 'gray')}</div>${wkParts(d && (d.internal || d.external) ? d : sel, 'in') || waitTxt(sel)}
      ${(d?.warnings || sel.warnings || []).map(w => `<div class="msg warn">${esc(w.message)}</div>`).join('')}${ret}
      <div class="scrollx"><div style="min-width:440px"><div class="hd cols-det"><div class="th">Line</div>${days.map(x => `<div class="th c">${dow(parse(x).getDay())}</div>`).join('')}<div class="th r">Total</div></div>
      ${[...lines.values()].map(r => `<div class="rw cols-det"><div style="font-size:14px">${esc([r.p?.code, r.p?.name, r.s?.name].filter(Boolean).join(' · '))}</div>${days.map(x => cell(r.by[x])).join('')}<div class="mono" style="text-align:right;font-weight:500">${h1(Object.values(r.by).reduce((a, v) => a + v, 0))}</div></div>`).join('')}
      <div class="tot"><span>Total hours</span><span class="mono">${h1(sel.total_hours)}</span></div></div></div>
      ${(d?.entries || []).some(e => e.note) ? `<p class="muted" style="white-space:pre-wrap;margin:0">${esc(d.entries.filter(e => e.note).map(e => dayLabel(e.work_date) + ': ' + e.note).join('\n'))}</p>` : ''}${acts}</div>`;
  }
  const pillBtn = ([k, l]) => `<button type="button" class="pill ${k === R.status ? 'on' : ''}" aria-pressed="${k === R.status}" data-act="rv-status" data-s="${k}">${l} (${cnt[k] ?? 0})</button>`;
  const per = ([k, l]) => `<button type="button" class="pill ${R.period === k ? 'on' : ''}" data-act="rv-period" data-p="${k}" ${pend ? 'disabled' : ''}>${l}</button>`;
  const rows = sheets.map(s => `<button type="button" class="rowbtn cols-rev ${sel && s.id === sel.id ? 'sel' : ''}" data-act="rv-pick" data-id="${s.id}"><span><span style="font-weight:500">${esc(nameOf(s))}</span>${s.late ? ' <span class="bd amber">Late</span>' : ''}</span>
    <span>${wkWeekCell(s)}</span><span>${typeName(s)}</span><span>${esc(projOf(s))}</span><span class="mono" style="text-align:right">${h1(s.total_hours)}</span><span>${pill(stOf(s), stColor[s.status] || 'gray')}</span></button>`).join('');
  return `<div class="pp"><div><h1>Approvals</h1><p class="sub">${inn ? 'These are hours waiting for your approval. After you approve, they go to the external approver.' : 'Approving replaces the manager signature. Weeks filled in after the deadline are flagged as late.'}</p></div>
    <div class="twin"><div class="card l"><div class="pillrow" style="margin-bottom:12px">${[['pending', 'Pending'], ['approved', 'Approved'], ['returned', 'Returned'], ['all', 'All']].map(pillBtn).join('')}</div>
      <div class="fpanel"><label class="flbl" style="flex:1 1 160px">Search person<input type="search" data-rv="q" placeholder="Name" value="${esc(R.q)}"></label>
      <label class="flbl" style="flex:1 1 140px">Type<select data-rv="type">${optHtml('', 'All types', R.type)}${optHtml('employee', 'Employees', R.type)}${optHtml('consultant', 'Contractors', R.type)}</select></label>
      <label class="flbl" style="flex:1 1 150px">Week ending from<input type="date" data-rv="from" ${pend ? 'disabled' : ''} value="${esc(R.from)}"></label>
      <label class="flbl" style="flex:1 1 150px">to<input type="date" data-rv="to" ${pend ? 'disabled' : ''} value="${esc(R.to)}"></label>
      <div class="pillrow" style="flex:1 1 100%"><span class="note">Quick period:</span>${[['4w', 'Last 4 weeks'], ['3m', 'Last 3 months'], ['all', 'All time']].map(per).join('')}</div></div>
      <p class="note" style="margin:0 0 8px">Pending always shows everything waiting for you, whatever the dates. The period applies to Approved, Returned and All.</p>
      <div class="revscroll"><div style="min-width:620px"><div class="hd cols-rev" style="padding:0 8px 8px"><div class="th">Person</div><div class="th">Week ending</div><div class="th">Type</div><div class="th">Project</div><div class="th r">Hours</div><div class="th">Status</div></div>
      ${rows || `<div class="empty">${pend && !R.q && !R.type ? 'Nothing is waiting for approval.' : 'No timesheet matches the filter.'}</div>`}</div></div></div>${detail}</div></div>`;
}
Object.assign(actions, {
  'rv-status'(el) { rvState().status = el.dataset.s; S.cache.apSel = null; render(); },
  'rv-pick'(el) { S.cache.apSel = Number(el.dataset.id); render(); },
  'rv-period'(el) { if (el.disabled) return; rvState().period = el.dataset.p; render(); },
});
document.addEventListener('change', e => {
  const rv = e.target.closest('[data-rv]');
  if (rv) { const R = rvState(); R[rv.dataset.rv] = rv.value; if (rv.dataset.rv === 'from' || rv.dataset.rv === 'to') R.period = ''; S.cache.apSel = null; render(); return; }
  const role = e.target.closest('[data-pp-role]');
  if (role) { const w = role.closest('form').querySelector('[data-pp-wt]'); if (w) w.hidden = role.value !== 'worker'; return; }
  const b = e.target.closest('[data-bill]');
  if (b) guard(async () => {
    const row = b.closest('[data-brow]'); const x = (S.cache.ppUsers || []).find(y => y.id === Number(row.dataset.brow)); if (!x) return;
    const g = k => row.querySelector(`[data-bill="${k}"]`);
    const terms = { rate: numOrNull(g('rate').value) ?? 0, retention_pct: numOrNull(g('retention_pct').value) ?? 0, ivu_agent: g('ivu_agent').checked, ivu_pct: numOrNull(g('ivu_pct').value) ?? 0, terms_days: x.terms?.terms_days ?? 30, accountisi_vendor_id: x.terms?.accountisi_vendor_id ?? null };
    const body = { name: x.name, email: x.email, role: x.role, worker_type: x.worker_type, employee_number: x.employee_number, capture_mode: x.capture_mode, weekly_hours: x.weekly_hours, approver_id: x.approver_id, active: x.active, project_ids: x.project_ids, terms };
    await api('/users/' + x.id, { method: 'PUT', body }); flash('Billing terms saved.'); render();
  })();
});
/* ================= Pop-up windows (modals): ONE component for every add / edit =================
   Overlay + centered box, title, ✕, Esc and click outside (asking first when there are unsaved changes), focus kept inside,
   inner scroll, aria-modal, Cancel / Save at the bottom. Modals stack (the service window opens over the project window).
   Server errors (422) appear inside the window, next to the field and in a strip on top, without closing it. */
const MDL = []; let mdlN = 0;
const mdlSnap = f => JSON.stringify([...f.elements].filter(el => el.name && !el.disabled && el.type !== 'file').map(el => [el.name, el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value]));
const mdlFocusable = box => [...box.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')].filter(x => !x.disabled && x.type !== 'hidden' && x.tabIndex >= 0 && x.getClientRects().length && !x.closest('[hidden]'));
function mdlClear(m) { m.form.querySelectorAll('.fe').forEach(x => x.remove()); m.form.querySelectorAll('.bad').forEach(x => x.classList.remove('bad')); const s = m.el.querySelector('.mdl-err'); s.hidden = true; s.textContent = ''; }
function mdlError(m, err) {
  mdlClear(m); const er = err?.errors || {};
  for (const [k, v] of Object.entries(er)) {
    const fld = m.form.querySelector(`[data-ef="${k}"]`) || m.form.querySelector(`[name="${k}"]`); if (!fld) continue;
    fld.classList.add('bad'); const s = document.createElement('small'); s.className = 'fe'; s.textContent = T([].concat(v)[0]); fld.insertAdjacentElement('afterend', s);
  }
  const strip = m.el.querySelector('.mdl-err'); strip.textContent = T(err?.message || 'Could not save. Try again.'); strip.hidden = false;
  m.el.querySelector('.mdl-body').scrollTop = 0; (m.form.querySelector('.bad') || strip).scrollIntoView?.({ block: 'nearest' });
  const b = m.form.querySelector('.bad'); if (b && b.focus) b.focus();
}
function modalOpen(o) {
  const id = 'mdl-t' + (++mdlN); const ov = document.createElement('div');
  ov.className = 'mdl'; ov.style.zIndex = 800 + MDL.length * 20;
  ov.innerHTML = localizeHtml(`<div class="mdl-box ${o.size || ''}" role="dialog" aria-modal="true" aria-labelledby="${id}">
    <div class="mdl-head"><div><h2 id="${id}">${o.title}</h2>${o.sub ? '<p class="mdl-sub"></p>' : ''}</div><button type="button" class="mdl-x" data-mdl-close aria-label="Close" title="Close">${wkClose}</button></div>
    <form class="mdl-form"><div class="mdl-body"><div class="mdl-err msg err" role="alert" hidden></div>${o.body}</div>
    <div class="mdl-foot"><button type="button" class="btn ghost" data-mdl-close>Cancel</button><button type="submit" class="btn pri">${o.save || 'Save'}</button></div></form></div>`);
  if (o.sub) ov.querySelector('.mdl-sub').textContent = o.sub;
  const box = ov.firstElementChild, form = ov.querySelector('form'), sb = form.querySelector('[type=submit]');
  const m = { el: ov, box, form, busy: false, prev: document.activeElement, down: false, snap: '' };
  m.dirty = () => mdlSnap(form) !== m.snap;
  m.close = skip => { const i = MDL.indexOf(m); if (i >= 0) MDL.splice(i, 1); ov.remove(); if (!MDL.length) document.body.classList.remove('mdl-open'); if (m.prev?.isConnected) { try { m.prev.focus(); } catch { /* gone */ } } if (!skip) o.onClose?.(m); };
  m.request = () => { if (m.busy) return; if (m.dirty() && !confirm(T('Discard your changes?'))) return; m.close(); };
  /* after a successful save: the window closes, the list refreshes and the notice appears */
  m.finish = async (msg, after) => { m.snap = mdlSnap(form); m.close(true); if (msg) toast(msg); await (after ? after() : render()); };
  ov.addEventListener('click', e => { if (e.target.closest('[data-mdl-close]')) m.request(); });
  ov.addEventListener('mousedown', e => { m.down = e.target === ov; });
  ov.addEventListener('mouseup', e => { if (e.target === ov && m.down) m.request(); m.down = false; });
  ov.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.type === 'search') { e.preventDefault(); return; }   // the people search inside a window must not submit it
    if (e.key !== 'Tab') return; const f = mdlFocusable(box); if (!f.length) return; const a = document.activeElement;
    if (e.shiftKey && (a === f[0] || !box.contains(a))) { e.preventDefault(); f[f.length - 1].focus(); } else if (!e.shiftKey && (a === f[f.length - 1] || !box.contains(a))) { e.preventDefault(); f[0].focus(); }
  });
  form.addEventListener('input', e => { e.target.classList.remove('bad'); const n = e.target.nextElementSibling; if (n?.classList.contains('fe')) n.remove(); });
  form.addEventListener('submit', async e => {
    e.preventDefault(); if (m.busy) return; m.busy = true; sb.disabled = true; mdlClear(m);
    try { await o.onSubmit(m); } catch (err) { if (err.message !== 'Session expired') mdlError(m, err); } finally { m.busy = false; sb.disabled = false; }
  });
  document.body.appendChild(ov); document.body.classList.add('mdl-open'); MDL.push(m);
  ov.querySelectorAll('[data-msel]').forEach(mselSync); o.init?.(m); m.snap = mdlSnap(form);
  (form.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=search]),select,textarea') || sb).focus();
  return m;
}
const mdlCloseAll = () => [...MDL].forEach(m => m.close(true));
document.addEventListener('keydown', e => { if (e.key === 'Escape' && MDL.length && !e.target.closest?.('.msel-q[aria-expanded="true"]')) { e.preventDefault(); e.stopImmediatePropagation(); MDL[MDL.length - 1].request(); } }, true);
window.addEventListener('hashchange', mdlCloseAll);
const needErr = (field, msg) => Object.assign(new Error(msg), { errors: { [field]: [msg] } });

/* --- Searchable multi-select: the one component for every long list (people, projects, approvers, clients) ---
   Chips on top (with ✕), a search box that filters while typing (no accents, no case; name and email), a scrolling list
   of about 8 rows (↑ ↓ Enter, Esc), the count and Clear. The state lives in hidden checkboxes (data-multi), so formObj and the
   "discard changes?" check work as before. o = { name, label, options: [{ id, label, sub, disabled }], selected: [ids], ef, placeholder } */
const mselNorm = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
let mselN = 0;
function msel(o) {
  const n = ++mselN, sel = new Set((o.selected || []).map(Number));
  return `<div class="msel" data-msel${o.ef ? ` data-ef="${o.ef}"` : ''}>
    <div class="msel-chips" data-msel-chips></div>
    <input type="search" class="msel-q" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="msel-l${n}" aria-autocomplete="list" aria-label="${esc(o.label)}" placeholder="${esc(o.placeholder || 'Search by name or email')}" autocomplete="off">
    <ul class="msel-list" id="msel-l${n}" role="listbox" aria-multiselectable="true" aria-label="${esc(o.label)}" hidden>${(o.options || []).map((x, i) => `<li role="option" id="msel-${n}-${i}" data-v="${x.id}" data-label="${esc(x.label)}" data-nm="${esc(mselNorm(x.label + ' ' + (x.sub || '')))}" aria-selected="${sel.has(Number(x.id))}"${x.disabled ? ' aria-disabled="true"' : ''}><input type="checkbox" hidden data-multi="1" name="${o.name}" value="${x.id}" ${sel.has(Number(x.id)) ? 'checked' : ''}><span class="msel-nm">${esc(x.label)}</span>${x.sub ? `<small class="msel-sub">${esc(x.sub)}</small>` : ''}<span class="msel-tick" aria-hidden="true">✓</span></li>`).join('')}<li class="msel-none" role="presentation" hidden>${(o.options || []).length ? 'No matches' : esc(o.empty || 'Nothing to choose from yet.')}</li></ul>
    <div class="msel-foot"><span class="msel-n" aria-live="polite"></span><button type="button" class="msel-clear">Clear</button></div></div>`;
}
const mselOpts = w => [...w.querySelectorAll('.msel-list li[data-v]')];
function mselSync(w) {
  const on = mselOpts(w).filter(li => li.querySelector('input').checked);
  mselOpts(w).forEach(li => li.setAttribute('aria-selected', li.querySelector('input').checked));
  w.querySelector('[data-msel-chips]').innerHTML = on.map(li => `<span class="msel-chip"><span>${esc(li.dataset.label)}</span><button type="button" class="msel-x" data-v="${li.dataset.v}" aria-label="${esc(T('Remove'))} ${esc(li.dataset.label)}" ${li.getAttribute('aria-disabled') ? 'disabled' : ''}>✕</button></span>`).join('');
  w.querySelector('.msel-n').textContent = T(`${on.length} selected`); w.querySelector('.msel-clear').hidden = !on.some(li => !li.getAttribute('aria-disabled'));
}
function mselAct(w, li) {
  const q = w.querySelector('.msel-q'); w.querySelectorAll('.msel-list li.act').forEach(x => x.classList.remove('act'));
  if (li) { li.classList.add('act'); q.setAttribute('aria-activedescendant', li.id); li.scrollIntoView({ block: 'nearest' }); } else q.removeAttribute('aria-activedescendant');
}
function mselFilter(w) {
  const terms = mselNorm(w.querySelector('.msel-q').value).split(/\s+/).filter(Boolean); let first = null, n = 0;
  mselOpts(w).forEach(li => { const ok = terms.every(t => li.dataset.nm.includes(t)); li.hidden = !ok; if (ok) { n++; first ??= li; } });
  w.querySelector('.msel-none').hidden = n > 0; mselAct(w, terms.length ? first : null);
}
function mselShow(w, on) {
  const q = w.querySelector('.msel-q'), l = w.querySelector('.msel-list'); if (!on === l.hidden) return;
  l.hidden = !on; q.setAttribute('aria-expanded', String(on)); if (on) mselFilter(w); else mselAct(w, null);
}
function mselToggle(w, li, force) {
  if (!li || li.getAttribute('aria-disabled')) return; const c = li.querySelector('input'); c.checked = force ?? !c.checked;
  mselSync(w); w.classList.remove('bad'); const f = w.nextElementSibling; if (f?.classList.contains('fe')) f.remove();
  w.dispatchEvent(new Event('mselchange', { bubbles: true }));
}
document.addEventListener('focusin', e => { const q = e.target.closest?.('.msel-q'); if (q) mselShow(q.closest('.msel'), true); });
document.addEventListener('click', e => {
  const w = e.target.closest?.('.msel'); if (!w) return;
  if (e.target.closest('.msel-q')) { mselShow(w, true); return; }
  const li = e.target.closest('.msel-list li[data-v]'); if (li) { mselToggle(w, li); return; }
  const x = e.target.closest('.msel-x'); if (x) { mselToggle(w, mselOpts(w).find(l => l.dataset.v === x.dataset.v), false); return; }
  if (e.target.closest('.msel-clear')) { mselOpts(w).forEach(l => mselToggle(w, l, false)); w.querySelector('.msel-q').focus(); }
});
document.addEventListener('mousedown', e => {
  if (e.target.closest?.('.msel-list')) { e.preventDefault(); return; }   // clicking a row keeps the focus in the search box
  document.querySelectorAll('.msel-list:not([hidden])').forEach(l => { const w = l.closest('.msel'); if (!w.contains(e.target)) mselShow(w, false); });
});
document.addEventListener('focusout', e => { const w = e.target.closest?.('.msel'); if (w && !w.contains(e.relatedTarget) && e.relatedTarget) mselShow(w, false); });
document.addEventListener('input', e => { const q = e.target.closest?.('.msel-q'); if (!q) return; const w = q.closest('.msel'); mselShow(w, true); mselFilter(w); });
document.addEventListener('keydown', e => {
  const q = e.target.closest?.('.msel-q'); if (!q) return; const w = q.closest('.msel'), open = !w.querySelector('.msel-list').hidden;
  const vis = mselOpts(w).filter(l => !l.hidden), cur = vis.indexOf(w.querySelector('.msel-list li.act'));
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!open) mselShow(w, true); if (vis.length) mselAct(w, vis[(cur + (e.key === 'ArrowDown' ? 1 : -1) + vis.length) % vis.length]); }
  else if (e.key === 'Enter') { e.preventDefault(); if (open && cur >= 0) mselToggle(w, vis[cur]); }
  else if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); mselShow(w, false); }
});

/* --- People: invite --- */
function openInviteModal() {
  modalOpen({ title: 'Invite person', size: 'sm', save: 'Send invitation',
    body: `<p class="muted" style="margin:0">You never type a password. The person receives an invitation by email to create their own.</p>
      <div><label>Name</label><input name="name" required maxlength="120" placeholder="Full name" autocomplete="off"></div>
      <div><label>Email</label><input name="email" type="email" required placeholder="name@company.com" autocomplete="off"></div>
      <div><label>Role</label><select name="role" data-pp-role><option value="worker">Worker</option><option value="approver">Approver</option><option value="backoffice">Back office</option></select></div>
      <div data-pp-wt><label>Worker type</label><select name="worker_type"><option value="employee">Employee</option><option value="consultant">Consultant</option></select></div>`,
    async onSubmit(m) {
      const o = formObj(m.form); const body = { name: String(o.name || '').trim(), email: o.email, role: o.role || 'worker' };
      if (body.role === 'worker') body.worker_type = o.worker_type || 'employee';
      const r = await api('/invitations', { method: 'POST', body }); await m.finish('Invitation sent to ' + (r.emailed_to || body.email));
    } });
}
/* --- People: edit (ID number, type, preference, external approver, projects, active and the consultant's billing terms) --- */
async function openPersonModal(id) {
  const [users, approvers, projects] = await Promise.all([api('/users'), api('/approvers'), api('/projects')]);
  const u = users.find(x => x.id === Number(id)); if (!u) throw new Error('That person no longer exists.'); const t = u.terms || {};
  modalOpen({ title: 'Edit person', sub: u.name, size: 'lg',
    body: `<div class="grid"><div><label>Name</label><input name="name" required value="${esc(u.name)}"></div><div><label>Email</label><input name="email" type="email" required value="${esc(u.email)}"></div>
      <div><label>ID number</label><input name="employee_number" maxlength="40" value="${esc(u.employee_number)}"></div>
      <div><label>Worker type</label><select name="worker_type"><option value="consultant" ${u.worker_type !== 'employee' ? 'selected' : ''}>Consultant (invoices)</option><option value="employee" ${u.worker_type === 'employee' ? 'selected' : ''}>Employee (no invoices)</option></select></div>
      <div><label>Entry preference</label><select name="capture_mode"><option value="from_to" ${u.capture_mode !== 'full_day' ? 'selected' : ''}>From / to (detail)</option><option value="full_day" ${u.capture_mode === 'full_day' ? 'selected' : ''}>Total hours per day</option></select><small class="help">Used when a service allows both.</small></div>
      <div><label>Hours per week</label><input name="weekly_hours" type="number" step="0.5" value="${esc(u.weekly_hours || '')}" placeholder="40"><small class="help">Employees only.</small></div>
      <div><label>External approver</label><select name="approver_id"><option value="">—</option>${approvers.map(a => `<option value="${a.id}" ${u.approver_id === a.id ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div></div>
      <div class="mdl-sec"><h3>Consultant billing terms</h3><div class="grid">
      <div><label>Hourly rate</label><input name="t_rate" type="number" step="0.01" min="0" value="${esc(t.rate)}"></div>
      <div><label>Withholding (%)</label><input name="t_retention_pct" type="number" step="0.01" min="0" max="100" value="${esc(t.retention_pct)}"><small class="help">Professional services withholding.</small></div>
      <div><label>IVU / sales tax (%)</label><input name="t_ivu_pct" type="number" step="0.01" min="0" max="100" value="${esc(t.ivu_pct)}"></div>
      <div><label>Payment terms (days)</label><input name="t_terms_days" type="number" min="0" max="365" value="${esc(t.terms_days ?? 30)}"></div>
      <div><label>Accountisi vendor id</label><input name="t_accountisi_vendor_id" value="${esc(t.accountisi_vendor_id)}"></div></div>
      <p class="row" style="margin:12px 0 0"><label class="chk"><input type="checkbox" name="t_ivu_agent" ${t.ivu_agent ? 'checked' : ''}> IVU withholding agent</label></p></div>
      <div class="mdl-sec"><h3>Assigned projects</h3>${msel({ name: 'project_ids', ef: 'project_ids', label: 'Assigned projects', placeholder: 'Search by code or name', empty: 'There are no projects yet.', selected: u.project_ids, options: projects.filter(p => p.active || u.project_ids?.includes(p.id)).map(p => ({ id: p.id, label: p.code, sub: p.name })) })}</div>
      <label class="chk"><input type="checkbox" name="active" ${u.active ? 'checked' : ''}> Active</label>`,
    async onSubmit(m) {
      const o = formObj(m.form);
      const terms = { rate: o.t_rate ?? 0, retention_pct: o.t_retention_pct ?? 0, ivu_pct: o.t_ivu_pct ?? 0, terms_days: o.t_terms_days ?? 30, ivu_agent: o.t_ivu_agent, accountisi_vendor_id: o.t_accountisi_vendor_id };
      for (const k of Object.keys(o)) if (k.startsWith('t_')) delete o[k];
      o.project_ids = o.project_ids || []; if (o.approver_id != null) o.approver_id = Number(o.approver_id);
      if (o.worker_type === 'consultant') o.terms = terms;
      await api('/users/' + u.id, { method: 'PUT', body: o }); await m.finish('Person saved.');
    } });
}
/* --- Approvers: add / edit --- */
async function openApproverModal(id) {
  const [alist, custs] = await Promise.all([api('/approvers'), api('/customers')]);
  const a = id ? alist.find(x => x.id === Number(id)) : null; if (id && !a) throw new Error('That approver no longer exists.');
  const mine = a?.customer_ids || [];
  modalOpen({ title: a ? 'Edit approver' : 'Add approver', sub: a?.name, size: 'sm', save: a ? 'Save' : 'Add',
    body: `<div><label>Name</label><input name="name" required value="${esc(a?.name)}"></div><div><label>Email</label><input name="email" type="email" required value="${esc(a?.email)}"></div>
      <div><label>Clients</label>${msel({ name: 'customer_ids', ef: 'customer_ids', label: 'Clients', placeholder: 'Search clients', empty: 'There are no clients yet.', selected: mine, options: custs.filter(c => c.active || mine.includes(c.id)).map(c => ({ id: c.id, label: c.name })) })}<small class="help">This approver can be chosen for services of these clients.</small></div>
      <label class="chk"><input type="checkbox" name="require_code" ${a?.require_code ? 'checked' : ''}> Always ask for a 6-digit code</label>
      <div><label class="chk"><input type="checkbox" name="can_login" ${a?.can_login ? 'checked' : ''}> Can sign in to the system</label><small class="help">The person receives an invitation at the same email, creates a password and signs in with a code sent by email.</small></div>
      ${a ? `<label class="chk"><input type="checkbox" name="active" ${a.active ? 'checked' : ''}> Active</label>` : ''}`,
    async onSubmit(m) {
      const o = formObj(m.form); o.can_login = !!o.can_login; o.require_code = !!o.require_code; o.customer_ids = o.customer_ids || [];
      if (a) { o.active = !!o.active; await api('/approvers/' + a.id, { method: 'PUT', body: o }); return m.finish('Approver saved.'); }
      await api('/approvers', { method: 'POST', body: o }); await m.finish(o.can_login ? 'Approver added. An invitation was sent to ' + o.email : 'Approver added.');
    } });
}
/* --- Clients: add / edit --- */
async function openCustomerModal(id) {
  const c = id ? (await api('/customers')).find(x => x.id === Number(id)) : null; if (id && !c) throw new Error('That client no longer exists.');
  modalOpen({ title: c ? 'Edit client' : 'Add client', sub: c?.name, size: 'sm', save: c ? 'Save' : 'Add',
    body: `<div><label>Name</label><input name="name" required value="${esc(c?.name)}"></div><div><label>Email</label><input name="email" type="email" value="${esc(c?.email)}"></div>
      ${c ? `<div><label>Accountisi</label><div>${c.accountisi_id ? pill('Linked', 'green') + ' <span class="muted">' + esc(c.accountisi_id) + '</span>' : pill('Not linked')}</div></div><label class="chk"><input type="checkbox" name="active" ${c.active ? 'checked' : ''}> Active</label>` : ''}`,
    async onSubmit(m) {
      const o = formObj(m.form);
      if (c) { o.active = !!o.active; o.accountisi_id = c.accountisi_id ?? null; await api('/customers/' + c.id, { method: 'PUT', body: o }); return m.finish('Client saved.'); }
      await api('/customers', { method: 'POST', body: o }); await m.finish('Client added.');
    } });
}
/* --- Projects: add / edit, with the project's services inside --- */
const APPROVAL_MODES = { none: ['None', 'gray'], internal: ['Internal only', 'blue'], external: ['External only', 'amber'], both: ['Internal and external', 'green'] };
const approvalPill = m => { const a = APPROVAL_MODES[m || 'external'] || APPROVAL_MODES.external; return pill(a[0], a[1]); };
const CAPTURE_NAMES = { detail: 'Detail (from / to)', total: 'Total hours per day', both: 'Both (the person’s preference decides)' };
/* the approvers of a service, short: internal and external names, or a dash */
function svcApprovers(x) {
  const nm = l => (l || []).map(a => a.name), short = l => { const n = nm(l); return esc(n.slice(0, 2).join(', ')) + (n.length > 2 ? ' +' + (n.length - 2) : ''); };
  const mode = x.approval_mode || 'external', rows = [];
  if ((mode === 'internal' || mode === 'both') && (x.internal_approvers || []).length) rows.push(`<div class="svap" title="${esc(nm(x.internal_approvers).join(', '))}"><span class="muted">Internal</span>${short(x.internal_approvers)}</div>`);
  if (mode === 'external' || mode === 'both') {
    const sel = x.external_approvers || [], eff = x.external_effective || [];
    if (sel.length) rows.push(`<div class="svap" title="${esc(nm(sel).join(', '))}"><span class="muted">External</span>${short(sel)}</div>`);
    else if (eff.length) rows.push(`<div class="svap" title="${esc(nm(eff).join(', '))}"><span class="muted">External · all</span>${short(eff)}</div>`);
  }
  return rows.join('') || '<span class="muted">—</span>';
}
function svcBox(p) {
  return `<div class="mdl-sech"><h3>Services</h3><button type="button" class="btn ghost sm" data-act="svc-add" data-pid="${p.id}">Add service</button></div>
    <p class="muted" style="margin:0 0 8px">The person picks the project and then the service. A service decides the rate, whether it is billed, how hours are entered and which approval it needs. A project with one service picks it automatically. Changes only affect new entries.</p>
    <div class="scrollx"><table class="svct"><tr><th>Service</th><th class="r">Rate</th><th>Billable</th><th>Entry</th><th>Approval</th><th>Approvers</th><th>Status</th><th></th></tr>
    ${(p.services || []).map(x => `<tr><td><b>${esc(x.name)}</b></td><td class="r">${x.bill_rate !== null && x.bill_rate !== undefined ? money(x.bill_rate) : '<span class="muted">project rate</span>'}</td>
    <td>${x.billable ? pill('Billable', 'green') : pill('Non-billable', 'amber')}</td><td>${CAPTURE_NAMES[x.capture_mode] || ''}</td><td>${approvalPill(x.approval_mode)}</td><td>${svcApprovers(x)}</td><td>${activePill(x.active)}</td>
    <td class="r"><button type="button" class="btn ghost sm" data-act="svc-edit" data-pid="${p.id}" data-id="${x.id}">Edit</button> <button type="button" class="btn ghost sm" data-act="svc-toggle" data-pid="${p.id}" data-id="${x.id}">${x.active ? 'Deactivate' : 'Activate'}</button></td></tr>`).join('')}</table></div>`;
}
async function openProjectModal(id) {
  const [list, customers, approvers, users] = await Promise.all([api('/projects'), api('/customers'), api('/approvers'), api('/users')]);
  S.cache.pl = list; const p = id ? list.find(x => x.id === Number(id)) : null; if (id && !p) throw new Error('That project no longer exists.');
  const bill = p ? p.billable : true; const metaTxt = p?.meta ? Object.entries(p.meta).map(([k, v]) => k + '=' + v).join('\n') : '';
  const m = modalOpen({ title: p ? 'Edit project' : 'New project', sub: p ? `${p.code} · ${p.name}` : '', size: 'xl', save: p ? 'Save' : 'Create project',
    onClose: mm => { if (mm.svcChanged) render(); },
    body: `<div class="grid"><div><label>Code</label><input name="code" required value="${esc(p?.code)}"></div><div><label>Name</label><input name="name" required value="${esc(p?.name)}"></div>
      <div><label>Client${bill ? ' *' : ''}</label><select name="customer_id" data-act-change="proj-client" ${bill ? 'required' : ''}><option value="">—</option>${customers.map(c => `<option value="${c.id}" ${p?.customer_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>
        <small class="help">A billable project needs a client. Non-billable (internal) projects can have none.</small><small class="help" data-nocli style="color:var(--danger)" ${p?.customer_id ? 'hidden' : ''}>Without a client, the services of this project cannot have external approval.</small></div>
      <div><label>Approver (optional)</label><select name="approver_id"><option value="">Use the person's approver</option>${approvers.map(a => `<option value="${a.id}" ${p?.approver_id === a.id ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div>
      <div><label>Default billing rate</label><input name="bill_rate" type="number" step="0.01" min="0" value="${esc(p?.bill_rate)}"><small class="help">Used by services without their own rate.</small></div>
      <div><label>Budgeted hours</label><input name="budget_hours" type="number" step="0.5" min="0" value="${esc(p?.budget_hours)}"></div>
      <div><label>Latitude (work site)</label><input name="geo_lat" type="number" step="0.000001" value="${esc(p?.geo_lat)}"></div>
      <div><label>Longitude</label><input name="geo_lng" type="number" step="0.000001" value="${esc(p?.geo_lng)}"></div>
      <div><label>Radius (m)</label><input name="geo_radius_m" type="number" min="50" max="5000" value="${esc(p?.geo_radius_m)}"></div></div>
      <p class="row" style="margin:0"><label class="chk"><input type="checkbox" name="billable" data-act-change="proj-billable" ${bill ? 'checked' : ''}> Billable</label>
      <label class="chk"><input type="checkbox" name="active" ${p ? (p.active ? 'checked' : '') : 'checked'}> Active</label></p>
      <div><label>Fields (key=value, one per line)</label><textarea name="meta" rows="3">${esc(metaTxt)}</textarea></div>
      <div class="mdl-sec"><h3>Assigned people</h3>${msel({ name: 'user_ids', ef: 'user_ids', label: 'Assigned people', empty: 'There are no people yet.', selected: p?.user_ids, options: users.filter(u => u.active || p?.user_ids?.includes(u.id)).map(u => ({ id: u.id, label: u.name, sub: u.email })) })}</div>
      <div class="mdl-sec" data-svc-box>${p ? svcBox(p) : `<div class="mdl-sech"><h3>Services</h3></div><p class="muted" style="margin:0">A new project starts with one service called General. Save the project, then open it with Edit to rename that service or add more services (each one with its own rate, entry mode and approval).</p>`}</div>`,
    async onSubmit(mm) {
      const o = formObj(mm.form); o.meta = parseMeta(o.meta); o.user_ids = o.user_ids || [];
      for (const k of ['customer_id', 'approver_id']) if (o[k] != null) o[k] = Number(o[k]);
      o.billable = !!o.billable; o.active = !!o.active;
      if (o.billable && !o.customer_id) throw needErr('customer_id', 'Pick a client: a billable project needs one.');
      if (p) await api('/projects/' + p.id, { method: 'PUT', body: o }); else await api('/projects', { method: 'POST', body: o });
      await mm.finish('Project saved.');
    } });
  if (p) m.el.dataset.pid = p.id;
}
/* the small window over the project's: a service (rate, entry mode, approval) */
async function openServiceModal(pid, sid) {
  const p = (S.cache.pl || []).find(x => x.id === Number(pid)); if (!p) return;
  const s = sid ? (p.services || []).find(x => x.id === Number(sid)) : null;
  const opt = await api('/projects/' + p.id + '/approver-options');   // { client: {id,name}|null, internal: [{id,name}], external: [{id,name}] }
  const cname = opt.client?.name || p.customer?.name || '';
  const m = modalOpen({ title: s ? 'Edit service' : 'Add service', sub: `${p.code} · ${p.name}`, size: 'sm', save: s ? 'Save' : 'Add',
    body: `<div><label>Name</label><input name="name" required maxlength="120" value="${esc(s?.name)}"></div>
      <div><label>Rate</label><input name="bill_rate" type="number" step="0.01" min="0" value="${esc(s?.bill_rate)}"><small class="help">Empty = project rate.</small></div>
      <div><label>How hours are entered</label><select name="capture_mode">${['both', 'detail', 'total'].map(k => `<option value="${k}" ${(s?.capture_mode || 'both') === k ? 'selected' : ''}>${CAPTURE_NAMES[k]}</option>`).join('')}</select></div>
      <div><label>Approval</label><select name="approval_mode">${Object.entries(APPROVAL_MODES).map(([k, a]) => `<option value="${k}" ${(s?.approval_mode || 'external') === k ? 'selected' : ''}>${a[0]}</option>`).join('')}</select><small class="help">What this service needs before its hours count as approved.</small></div>
      <div data-sv-int><label>Internal approvers *</label>${msel({ name: 'internal_approver_ids', ef: 'internal_approver_ids', label: 'Internal approvers', placeholder: 'Search by name', empty: 'There are no people yet.', selected: s?.internal_approver_ids, options: (opt.internal || []).map(u => ({ id: u.id, label: u.name, sub: u.email })) })}<small class="help">At least one. When one of them approves, the hours move on.</small></div>
      <div data-sv-ext><label>External approvers</label>${msel({ name: 'external_approver_ids', ef: 'external_approver_ids', label: 'External approvers', placeholder: 'Search by name', empty: 'This client has no approvers yet.', selected: s?.external_approver_ids, options: (opt.external || []).map(u => ({ id: u.id, label: u.name, sub: u.email })) })}<small class="help" data-sv-exthelp></small>
        <div class="msg err" data-sv-warn role="alert" hidden style="margin:8px 0 0"></div></div>
      <div><label>Accountisi item id</label><input name="accountisi_item_id" maxlength="40" value="${esc(s?.accountisi_item_id)}"></div>
      <label class="chk"><input type="checkbox" name="billable" ${s ? (s.billable ? 'checked' : '') : 'checked'}> Billable</label>
      <label class="chk"><input type="checkbox" name="require_note" ${s?.require_note ? 'checked' : ''}> Require a note on every line</label>`,
    async onSubmit(mm) {
      const o = formObj(mm.form); o.billable = !!o.billable; o.require_note = !!o.require_note; const need = svcNeeds(mm);
      o.internal_approver_ids = need.int ? (o.internal_approver_ids || []) : []; o.external_approver_ids = need.ext ? (o.external_approver_ids || []) : [];
      try {
        if (need.int && !o.internal_approver_ids.length) throw needErr('internal_approver_ids', 'Choose at least one internal approver.');
        if (need.ext && need.block) throw needErr('external_approver_ids', need.block);
        if (s) await api('/services/' + s.id, { method: 'PUT', body: o }); else await api('/projects/' + p.id + '/services', { method: 'POST', body: o });
      } catch (e) { setTimeout(() => svcSync(mm), 0); throw e; }
      await mm.finish('Service saved.', () => refreshSvcBox(p.id));
    } });
  m.opt = opt; m.cname = cname; const f = m.form;
  f.elements.approval_mode.addEventListener('change', () => svcSync(m)); f.addEventListener('mselchange', () => svcSync(m)); svcSync(m);
}
/* what the chosen approval mode needs, and whether saving is blocked (the client has no external approvers) */
function svcNeeds(m) {
  const mode = m.form.elements.approval_mode.value, int = mode === 'internal' || mode === 'both', ext = mode === 'external' || mode === 'both';
  const block = !ext ? '' : !m.opt.client ? T('This project has no client. Pick one in the project first.') : !(m.opt.external || []).length ? T('Client ' + m.cname + ' has no approvers. Add one in Approvers first.') : '';
  return { int, ext, block };
}
function svcSync(m) {
  const n = svcNeeds(m), q = x => m.form.querySelector(x);
  q('[data-sv-int]').hidden = !n.int; q('[data-sv-ext]').hidden = !n.ext;
  const w = q('[data-sv-warn]'); w.hidden = !n.block; w.textContent = n.block;
  const names = (m.opt.external || []).map(x => x.name), none = !q('[name=external_approver_ids]:checked');
  const h = q('[data-sv-exthelp]'); h.hidden = !!n.block;
  h.textContent = none ? T('Empty = it goes to all approvers of ' + m.cname + ': ' + names.slice(0, 5).join(', ') + (names.length > 5 ? ' +' + (names.length - 5) : '')) : T('Only the approvers chosen above are asked to approve.');
  q('[type=submit]').disabled = !!n.block;
}
/* redraw the services table inside the open project window (the rest of that window is left as the person typed it) */
async function refreshSvcBox(pid) {
  S.cache.pl = await api('/projects'); const p = S.cache.pl.find(x => x.id === Number(pid));
  const pm = MDL.find(x => x.el.dataset.pid === String(pid)); if (!p || !pm) return;
  pm.el.querySelector('[data-svc-box]').innerHTML = localizeHtml(svcBox(p)); pm.svcChanged = true;
}
Object.assign(actions, {
  'inv-open': () => openInviteModal(),
  'pp-edit': el => openPersonModal(el.dataset.id),
  'pp-edit-approver': el => openApproverModal(el.dataset.id),
  'approver-new': () => openApproverModal(), 'edit-approver': el => openApproverModal(el.dataset.id),
  'customer-new': () => openCustomerModal(), 'edit-customer': el => openCustomerModal(el.dataset.id),
  'project-new': () => openProjectModal(), 'edit-project': el => openProjectModal(el.dataset.id),
  'svc-add': el => openServiceModal(el.dataset.pid), 'svc-edit': el => openServiceModal(el.dataset.pid, el.dataset.id),
  async 'svc-toggle'(el) {
    const p = S.cache.pl.find(x => x.id === Number(el.dataset.pid)); const s = p.services.find(x => x.id === Number(el.dataset.id));
    await api('/services/' + s.id, { method: 'PUT', body: { active: !s.active } }); await refreshSvcBox(p.id); toast(s.active ? 'Service deactivated.' : 'Service activated.');
  },
});
/* ================= Week screen (WEB-HOJA): editable weekly grid, clock icon and toasts ================= */
const HOUR_TYPES = [['reg', 'REG'], ['ot', 'OT'], ['hol', 'HOL'], ['sick', 'Sick'], ['vac', 'VAC'], ['nb', 'NB']];
/* A week can have several sheets (parts). For the lists by week: hours added up and the status that needs attention first. */
/* number of days from a to b (7 when there is no period: the whole week) */
const wkSpan = (a, b) => (b ? Math.max(1, Math.round((parse(b) - parse(a)) / 86400000) + 1) : 7);
const weekAgg = list => {
  const rank = { returned: 4, submitted: 3, draft: 2, approved: 1 }; const o = {};
  for (const x of list) {
    if (x.status === 'draft' && !Number(x.total_hours) && list.some(y => y !== x && y.week_ending === x.week_ending)) continue;   // an empty working draft next to real parts
    const a = o[x.week_ending] ??= { ...x, total_hours: 0, parts: 0 };
    a.total_hours += Number(x.total_hours || 0); a.parts++;
    if ((rank[x.status] || 0) > (rank[a.status] || 0)) a.status = x.status;
  }
  return o;
};
const WK = () => ((S.cache.wkst ??= {})[S.weekDate] ??= { extra: [], edits: {}, pend: {}, pnote: {}, n: 0, cert: false, through: null, snote: null });
const wkSvcs = p => (p?.services || []).filter(s => s.active);
const wkMode = (p, sid) => { const s = (p?.services || []).find(x => x.id === Number(sid)); return (s && s.mode) || (S.me.capture_mode === 'from_to' ? 'from_to' : 'full_day'); };
/* "Submitted · waiting for Ana" (internal stage) or "Submitted · waiting for client approval" (external stage). */
const wkNames = (l, max = 3) => { const n = (l || []).map(a => a.name || a).filter(Boolean); return n.slice(0, max).join(', ') + (n.length > max ? ' +' + (n.length - max) : ''); };
const stageLabel = s => {
  const base = STATUS[s.status] || s.status;
  if (s.status !== 'submitted') return base;
  const names = wkNames(s.waiting_for);
  if (names) return base + ' · waiting for ' + names;
  if (s.stage === 'internal') return base + ' · waiting for internal approval';
  if (s.stage === 'external') return base + ' · waiting for client approval';
  return base;
};
/* One line per part of the sheet, internal and external: "approved by Ana", "waiting for Ana, Luis" or "skipped" (the service name only if it comes). Each role sees only what the server sends it. */
const wkParts = (sheet, cls = '') => {
  const one = (kind, i) => {
    const proj = typeof i.project === 'string' ? i.project : [i.project?.code, i.project?.name].filter(Boolean).join(' · ');
    const svc = typeof i.service === 'string' ? i.service : i.service?.name;
    const by = i.approved_by?.name || i.approved_by;
    const txt = i.status === 'approved' ? 'approved by ' + (by || wkNames(i.approvers)) : i.status === 'skipped' ? 'skipped' : 'waiting for ' + wkNames(i.approvers);
    return `<div><span class="wkk">${kind}</span><span>${esc([proj, svc].filter(Boolean).join(' · '))} — ${esc(txt)}</span></div>`;
  };
  const rows = (sheet?.internal || []).map(i => one('Internal', i)).concat((sheet?.external || []).map(i => one('External', i)));
  return rows.length ? `<div class="wkint ${cls}">${rows.join('')}</div>` : '';
};
const wkPL = p => (p ? `${p.code} · ${p.name}` : '—');
const wkClose = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>`;
const wkClock = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>`;

/* Floating messages. EVERY one disappears by itself (success 2.5 s, warning 6 s, error 8 s); the ✕ closes it sooner and the timer
   pauses while the pointer is over it. None stays fixed and none moves the page. */
const TOAST_MS = { ok: 2500, warn: 6000, err: 8000 };
function toast(text, kind = 'ok') {
  let box = document.getElementById('toasts');
  if (!box) { box = document.createElement('div'); box.id = 'toasts'; document.body.appendChild(box); }
  kind = kind === 'ok' || kind === 'warn' ? kind : 'err'; const ok = kind === 'ok'; const shown = T(text);
  box.querySelectorAll('.toast').forEach(x => { if (x.dataset.k === kind && x.firstChild.textContent === shown) x.remove(); });   // the same message twice shows once
  const t = document.createElement('div'); t.dataset.k = kind;
  t.className = 'toast ' + kind; t.setAttribute('role', ok ? 'status' : 'alert');
  t.innerHTML = `<span>${esc(shown)}</span><button type="button" class="x" aria-label="${esc(T('Close'))}">✕</button>`;
  let left = TOAST_MS[kind], t0 = 0, timer = 0;
  const gone = () => { clearTimeout(timer); t.remove(); };
  const run = () => { t0 = Date.now(); clearTimeout(timer); timer = setTimeout(gone, left); };
  t.addEventListener('mouseenter', () => { clearTimeout(timer); left = Math.max(1500, left - (Date.now() - t0)); });
  t.addEventListener('mouseleave', run);
  t.querySelector('.x').addEventListener('click', gone);
  box.appendChild(t); run();
}

/* the week of a sheet in the lists; a part of the week says which days it covers */
const wkWeekCell = s => nice(s.week_ending) + (s.is_part ? ` <span class="muted">· ${T('Part')} ${s.part} (${esc(dow(parse(s.period_starting).getDay()))}${s.period_starting === s.period_ending ? '' : '–' + esc(dow(parse(s.period_ending).getDay()))})</span>` : '');
const wkNote = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>`;
const DAY_STATE = { submitted: ['Sent', 'blue'], approved: ['Approved', 'green'], returned: ['Returned', 'red'] };
/* header of one day: name and date; with partial sending on, its state (sent / approved / returned) or the "send through here" mark */
function wkDayHead(d, info, PART, st) {
  const base = `${dow(parse(d).getDay())}<br><span class="dd">${parse(d).toLocaleDateString(LOC(), { month: 'short', day: 'numeric' })}</span>`;
  if (!PART) return `<div class="th c">${base}</div>`;
  const state = info && !info.open ? DAY_STATE[info.status] : null;
  const sendable = !state || info.status === 'returned';   // open days and returned days can go; sent / approved days are already in
  const sel = st.through && sendable && d <= st.through;
  let tail = '';
  if (state && info.status !== 'returned') tail = `<span class="dstat ${state[1]}">${T(state[0])}</span>`;
  else {
    if (state) tail = `<span class="dstat ${state[1]}">${T(state[0])}</span>`;
    if (d <= today()) tail += `<button type="button" class="thmark ${st.through === d ? 'on' : ''}" data-act="wk-through" data-d="${d}" aria-pressed="${st.through === d}" title="${esc(T('Send through this day'))}" aria-label="${esc(T('Send through this day'))}"></button>`;
  }
  return `<div class="th c ${sel ? 'sel' : ''}">${base}<div class="dtail">${tail}</div></div>`;
}
/* what "send through here" will send: the open (and returned) days up to the marked one, with their hours */
function wkThrough(sheet, days, tot, through) {
  const sel = (sheet.days || []).filter(x => (x.open || x.status === 'returned') && x.date <= through);
  if (!sel.length) return null;
  const h = sel.reduce((a, x) => a + (tot.day[days.indexOf(x.date)] || 0), 0);
  const a = sel[0].date, b = sel[sel.length - 1].date;
  return { label: `${dow(parse(a).getDay())}${a === b ? '' : '–' + dow(parse(b).getDay())} · ${sel.length} ${T(sel.length === 1 ? 'day' : 'days')} · ${h1(h)} h` };
}
/* the sheets (parts) of this week, with the reason when one came back */
function wkPeriods(sheet) {
  const ps = (sheet.parts || []).filter(p => p.is_part); if (!ps.length) return '';
  const color = { draft: 'gray', submitted: 'blue', approved: 'green', returned: 'red' };
  return `<div class="card wkparts"><div class="lbl">${T('Sent in parts')}</div>${ps.map(p => `<div class="wkpart"><span class="mono">${T('Part')} ${p.part}</span><span>${nice(p.period_starting)}${p.period_starting === p.period_ending ? '' : ' – ' + nice(p.period_ending)}</span><span class="mono">${h1(p.total_hours)} h</span><span class="bd ${color[p.status] || 'gray'}">${T(STATUS[p.status] || p.status)}</span></div>${p.status === 'returned' && p.return_comment ? `<div class="msg err" role="alert"><b>${T('Returned for correction:')}</b> ${esc(p.return_comment)}</div>` : ''}`).join('')}</div>`;
}
async function weekView() {
  wkPopClose(); wkModalClose();
  const end = weekEnd(S.weekDate), start = addDays(end, -6), cur = weekEnd(today());
  const [sheet, ents, projects, mine, recent] = await Promise.all([
    api('/sheets/week?date=' + S.weekDate), api(`/entries?from=${start}&to=${end}`), api('/projects'), api('/sheets').catch(() => []),
    api(`/entries?from=${addDays(today(), -3)}&to=${today()}`).catch(() => ({ entries: [] }))]);
  S.cache.projects = projects; S.cache.entries = ents.entries; S.cache.wkSheet = sheet;
  const R = S.me.rules || {}; const inc = Number(R.increment) || 0.25; const maxDay = Number(R.max_day_hours) || 24;
  const emp = (sheet.worker?.worker_type || S.me.worker_type) === 'employee';
  const locked = !!sheet.locked; const st = WK();
  const PART = !!sheet.partial_submission; const dInfo = Object.fromEntries((sheet.days || []).map(x => [x.date, x])); const dayLocked = d => !!dInfo[d]?.locked;
  S.cache.wkRunning = recent.entries.find(e => e.running) || ents.entries.find(e => e.running) || null;
  const days = Array.from({ length: 7 }, (_, i) => addDays(start, i));
  const list = ents.entries.filter(e => !e.running);

  // Lines: one per project + service + hour type that has entries this week, plus the ones added on screen
  const map = new Map();
  for (const e of list) {
    const lid = [e.project_id, e.service_id ?? '', e.hour_type || 'reg'].join('|');
    if (!map.has(lid)) map.set(lid, { lid, project_id: e.project_id, service_id: e.service_id ?? null, hour_type: e.hour_type || 'reg', ents: [] });
    map.get(lid).ents.push(e);
  }
  if (!locked && !map.size && !st.extra.length) { const p = projects.find(x => x.active && wkSvcs(x).length); if (p) st.extra.push({ lid: 'n' + (++st.n), project_id: p.id, service_id: wkSvcs(p)[0].id, hour_type: 'reg' }); }
  for (const x of st.extra) if (!map.has(x.lid)) map.set(x.lid, { ...x, ents: [] });
  const lines = [...map.values()].map(l => ({ ...l, ...(st.edits[l.lid] || {}), ents: l.ents }));
  S.cache.wkLines = lines; S.cache.wkCtx = { days, emp, inc, maxDay, locked, dayLocked };

  // A. title, deadline, status, clock icon
  const dl = R.deadline_dow != null ? ` · <span>Deadline: ${esc(new Date(2024, 0, 7 + Number(R.deadline_dow)).toLocaleDateString(LOC(), { weekday: 'long' }))} ${esc(R.deadline_time || '')} after the week ends</span>` : '';
  const pillColor = { draft: 'gray', submitted: 'blue', approved: 'green', returned: 'red' }[sheet.status] || 'gray';
  const running = S.cache.wkRunning;
  const head = `<div class="wkhead"><div><h1>Weekly time record</h1><p class="wksub"><span>Week ending ${longDay(end)}</span>${dl}</p></div>
    <div class="wkstat"><button type="button" class="clkbtn ${running ? 'on' : ''}" data-act="clock-open" aria-label="Clock" title="Clock">${wkClock}${running ? '<i class="dot"></i>' : ''}</button>
    <span class="bd ${pillColor}">${stageLabel(sheet)}</span>${sheet.late ? '<span class="bd red">Late</span>' : ''}</div></div>`;

  // B. week selector
  const stateOf = Object.fromEntries(Object.values(weekAgg(mine)).map(s => [s.week_ending, STATUS[s.status] || s.status]));
  const weeks = Array.from({ length: 12 }, (_, i) => addDays(cur, -7 * i));
  if (!weeks.includes(end)) weeks.push(end);
  weeks.sort().reverse();
  const picker = weeks.map(w => `<option value="${w}" ${w === end ? 'selected' : ''}>${nice(w)} · ${stateOf[w] ? T(stateOf[w]) : T('Not started')}${w === cur ? ' · ' + T('current') : ''}</option>`).join('');
  const nav = `<div class="card wknav"><button class="btn ghost" data-act="wk" data-n="-7" aria-label="Previous week (older)">←</button>
    <label class="wkpick">Week ending<select data-act-change="wk-pick">${picker}</select></label>
    <button class="btn ghost" data-act="wk" data-n="7" ${end >= cur ? 'disabled' : ''} aria-label="Next week (newer)">→</button>
    <button class="btn ghost" data-act="wk-now" ${end === cur ? 'disabled' : ''}>Current week</button></div>`;

  // C. past weeks with no timesheet (or no hours), oldest first
  const byEnd = weekAgg(mine); const back = Number(R.weeks_back ?? 8); const miss = [];
  const first = S.me.member_since ? weekEnd(S.me.member_since) : null;   // weeks before the account existed are never reclaimed
  for (let i = back; i >= 1; i--) { const w = addDays(cur, -7 * i); if (first && w < first) continue; const s = byEnd[w]; if (!s || !Number(s.total_hours)) miss.push(w); }
  const alert = miss.length ? `<div class="wkalert" role="status"><div class="t">${miss.length === 1 ? 'You have 1 past week with no timesheet. Start with the oldest:' : `You have ${miss.length} past weeks with no timesheet. Start with the oldest:`}</div>
    <div class="b">${miss.map(w => `<button class="btn ghost" data-act="wk-go" data-end="${w}">Fill in week ending ${nice(w)}</button>`).join('')}</div></div>` : '';

  // D. person data
  const co = S.me.brand?.name || S.me.company?.name || '';
  const empNo = sheet.worker?.employee_number ?? S.me.employee_number;
  const facts = `<div class="card wkfacts"><div><div class="lbl">Name</div><div class="val">${esc(sheet.worker?.name || S.me.name)}</div></div>
    <div><div class="lbl">${esc(co)} ID number</div><div class="val mono">${esc(empNo || '—')}</div></div>
    <div><div class="lbl">Worker type</div><div class="val">${emp ? 'Employee' : 'Consultant'}</div></div>
    <div><div class="lbl">Approver</div><div class="val">${esc(sheet.approver?.name || '—')}</div></div></div>`;

  // E. the editable grid
  const tot = { day: days.map(() => 0), all: 0 };
  const rows = lines.map((ln, li) => {
    const p = projects.find(x => x.id === Number(ln.project_id)); const mode = wkMode(p, ln.service_id);
    const pOpts = projects.filter(x => x.active || x.id === Number(ln.project_id)).map(x => `<option value="${x.id}" ${x.id === Number(ln.project_id) ? 'selected' : ''}>${esc(wkPL(x))}</option>`).join('');
    const svcs = (p?.services || []).filter(s => s.active || s.id === Number(ln.service_id));
    const sSel = wkSvcs(p).length > 1 ? `<select class="wksvc" data-act-change="wk-line" data-lid="${esc(ln.lid)}" data-fld="service_id" aria-label="Service" ${locked ? 'disabled' : ''}>${svcs.map(s => `<option value="${s.id}" ${s.id === Number(ln.service_id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select>` : '';
    const tSel = emp ? `<select data-act-change="wk-line" data-lid="${esc(ln.lid)}" data-fld="hour_type" aria-label="Code" ${locked ? 'disabled' : ''}>${HOUR_TYPES.map(([v, l]) => `<option value="${v}" ${v === ln.hour_type ? 'selected' : ''}>${l}</option>`).join('')}</select>` : '';
    let lt = 0;
    const cells = days.map((d, di) => {
      const es = ln.ents.filter(e => e.work_date === d); const sum = es.reduce((a, e) => a + Number(e.hours), 0); const key = ln.lid + '|' + d;
      const aria = `${dow(parse(d).getDay())} hours, line ${li + 1}`;
      const dl = locked || dayLocked(d);
      const hasNote = Object.prototype.hasOwnProperty.call(st.pnote, key) ? !!st.pnote[key] : es.some(e => e.note);
      const pendH = Object.prototype.hasOwnProperty.call(st.pend, key) ? parseFloat(st.pend[key]) || 0 : 0;
      const noteBtn = (es.length || pendH > 0 || !dl || hasNote) ? `<button type="button" class="nbtn ${hasNote ? 'on' : ''}" data-act="${es.length > 1 ? 'cell-pop' : 'cell-note'}" data-lid="${esc(ln.lid)}" data-d="${d}" title="${hasNote ? 'Note' : 'Add a note'}" aria-label="${hasNote ? 'Note' : 'Add a note'}">${wkNote}</button>` : '';
      if (mode === 'from_to' || es.length > 1) { lt += sum; tot.day[di] += sum; return `<div class="wkcw"><input class="inp num wkc ro ${dl ? 'lk' : ''}" readonly data-di="${di}" data-act="cell-pop" data-lid="${esc(ln.lid)}" data-d="${d}" title="Details" aria-label="${aria}" value="${sum ? h1(sum) : ''}">${noteBtn}</div>`; }
      const orig = es[0] ? Number(es[0].hours) : ''; const dirty = Object.prototype.hasOwnProperty.call(st.pend, key); const v = dirty ? st.pend[key] : orig;
      const n = parseFloat(v) || 0; lt += n; tot.day[di] += n;
      const badc = dirty && !wkMulti(v, inc);   // typed but not a multiple of the increment: red, and Save draft refuses
      return `<div class="wkcw"><input class="inp num wkc ${dirty ? 'dirty' : ''} ${badc ? 'bad' : ''} ${dl ? 'lk' : ''}" type="number" min="0" max="${maxDay}" step="${inc}" data-di="${di}" data-cell="${esc(key)}" data-orig="${orig}" aria-label="${aria}" ${badc ? `aria-invalid="true" title="${esc(wkIncMsg(inc))}"` : ''} value="${esc(v)}" ${dl ? 'readonly' : ''}>${noteBtn}</div>`;
    }).join('');
    tot.all += lt;
    return `<div class="wkrow wkg ${emp ? 'emp' : ''}"><div class="wkproj"><select data-act-change="wk-line" data-lid="${esc(ln.lid)}" data-fld="project_id" aria-label="Project" ${locked ? 'disabled' : ''}>${pOpts}</select>${sSel}</div>${tSel}${cells}
      <div class="mono wktot">${h1(lt)}</div><button class="icon" type="button" data-act="wk-del" data-lid="${esc(ln.lid)}" aria-label="Remove line" ${locked ? 'disabled' : ''}>${wkClose}</button></div>`;
  }).join('');
  const grid = `<div class="card wkcard"><div class="wkscroll"><div class="wkmin">
    <div class="wkg wkh ${emp ? 'emp' : ''}"><div class="th">Project</div>${emp ? '<div class="th">Code</div>' : ''}${days.map(d => wkDayHead(d, dInfo[d], PART, st)).join('')}<div class="th r">Total</div><div></div></div>
    ${rows}
    <div class="wkg wkd ${emp ? 'emp' : ''}"><div class="dl">Daily total</div>${emp ? '<div></div>' : ''}${days.map((d, di) => `<div class="mono wkdt" data-di="${di}">${h1(tot.day[di])}</div>`).join('')}<div class="mono wkgt">${h1(tot.all)}</div><div></div></div></div></div>
    ${locked ? '' : '<div class="wkbtns"><button class="btn ghost" type="button" data-act="wk-add">+ Add project line</button><button class="btn ghost" type="button" data-act="wk-copy">Copy lines from previous week</button></div>'}</div>`;

  // F. totals and certification
  const total = Number(sheet.total_hours ?? tot.all);
  const bill = sheet.billable_hours ?? list.filter(e => e.billable !== false).reduce((a, e) => a + Number(e.hours), 0);
  const non = sheet.non_billable_hours ?? Math.max(0, total - bill);
  const left = emp
    ? `<div class="card wktotals"><div><div class="lbl">Billable hours</div><div class="mono big">${h1(bill)}</div></div><div><div class="lbl">Non-billable hours</div><div class="mono big">${h1(non)}</div></div><div><div class="lbl">Employee total</div><div class="mono big acc wktotbig">${h1(total)}</div></div></div>`
    : `<div class="card wktotals"><div><div class="lbl">Total hours</div><div class="mono big acc wktotbig">${h1(total)}</div></div><p class="note">Your invoice is generated from approved weeks. You never retype hours.</p></div>`;
  const canSubmit = ['draft', 'returned'].includes(sheet.status);
  const needNote = lines.some(l => (projects.find(x => x.id === Number(l.project_id))?.services || []).some(v => v.id === Number(l.service_id) && v.require_note));
  const noteBox = `<div><label for="wk-snote">Submission note</label><textarea id="wk-snote" class="wksnote" rows="2" maxlength="1000" data-snote placeholder="${esc(T('Applied to every line that has no note'))}">${esc(st.snote ?? sheet.submission_note ?? '')}</textarea>${needNote ? `<small class="help">${T('Some services require a note on every line: write one in each line or here.')}</small>` : ''}</div>`;
  const through = PART && st.through ? wkThrough(sheet, days, tot, st.through) : null;
  const sendBtn = PART ? `<button class="btn pri" type="button" data-act="wk-send-days" ${through && st.cert ? '' : 'disabled'} title="${esc(T('Check "send through here" in a day header first.'))}">${through ? esc(T('Send') + ' ' + through.label) : esc(T('Send through the marked day'))}</button>` : '';
  const right = locked ? `<div class="card wkcert"><p class="muted" style="margin:0">Approved and locked.</p></div>`
    : `<div class="card wkcert">${noteBox}<label class="cert"><input type="checkbox" class="chk" data-act-change="wk-cert" ${st.cert ? 'checked' : ''}><span>I certify these hours are accurate. Submitting counts as my electronic signature.</span></label>
      <div class="wkbtns0"><button class="btn ghost" type="button" data-act="wk-save">Save draft</button>${sendBtn}
      ${canSubmit ? `<button class="btn pri" type="button" data-act="wk-submit" ${st.cert && tot.all > 0 ? '' : 'disabled'}>${sheet.status === 'returned' ? 'Corrected: submit again' : 'Submit for approval'}</button>` : ''}</div>
      ${sheet.status === 'submitted' ? '<p class="muted" style="margin:0">Waiting for a decision. If you change it, the approver gets a notice that it changed.</p>' : ''}</div>`;
  const warn = (sheet.warnings || []).map(w => `<div class="msg warn">${esc(w.message)}</div>`).join('');
  const ret = sheet.status === 'returned' && sheet.return_comment ? `<div class="msg err" role="alert"><b>Returned for correction:</b> ${esc(sheet.return_comment)}${sheet.return_by ? ` <span class="muted">${T('Returned by')} ${esc(sheet.return_by.name || sheet.return_by)}</span>` : ''}</div>` : '';
  return `<div class="wk">${head}${ret}${warn}${nav}${alert}${facts}${wkPeriods(sheet)}${wkParts(sheet)}${grid}<div class="wkbottom">${left}${right}</div></div>`;
}

/* live totals while typing */
function wkRecalc() {
  const root = document.querySelector('.wk'); if (!root) return;
  const day = Array(7).fill(0);
  root.querySelectorAll('.wkrow').forEach(r => { let t = 0; r.querySelectorAll('.wkc').forEach(i => { const v = parseFloat(i.value) || 0; day[+i.dataset.di] += v; t += v; }); r.querySelector('.wktot').textContent = h1(t); });
  root.querySelectorAll('.wkdt').forEach(c => { c.textContent = h1(day[+c.dataset.di]); });
  const all = day.reduce((a, v) => a + v, 0);
  const g = root.querySelector('.wkgt'); if (g) g.textContent = h1(all);
  const big = root.querySelector('.wktotbig'); if (big) big.textContent = h1(all);
  const sub = root.querySelector('[data-act="wk-submit"]'); if (sub) sub.disabled = !(WK().cert && all > 0);
  const sd = root.querySelector('[data-act="wk-send-days"]'); if (sd) sd.disabled = !(WK().cert && WK().through);
}
const wkIdKey = ln => [ln.project_id, ln.service_id ?? '', ln.hour_type].join('|');
/* Hour increment (rules.increment): applied when typing, never rounded in silence. 0.25 -> 15 min, 0.5 -> 30, 1 -> 60, 0.1 -> 6, 0.05 -> 3, 0.01 -> no alignment. */
const wkInc = () => Number(S.me?.rules?.increment) || 0.25;
const wkMulti = (v, inc = wkInc()) => { const n = parseFloat(v); if (v === '' || v == null || !Number.isFinite(n) || n === 0) return true; const q = n / inc; return Math.abs(q - Math.round(q)) < 1e-6; };
const wkIncMsg = inc => 'Hours must be multiples of ' + inc;
const wkStepMin = () => { const m = wkInc() * 60; return m >= 1 && Math.abs(m - Math.round(m)) < 1e-6 ? Math.round(m) : null; };
const wkMinMulti = (t, step = wkStepMin()) => { if (!step || !/^\d{1,2}:\d{2}/.test(t || '')) return true; return Number(t.slice(3, 5)) % step === 0; };
/* cells typed with a value that is not a multiple of the increment, as readable names */
function wkBadCells() {
  const st = WK(); const out = [];
  for (const [k, v] of Object.entries(st.pend)) {
    if (wkMulti(v)) continue;
    const i = k.lastIndexOf('|'); const lid = k.slice(0, i), d = k.slice(i + 1); const ln = (S.cache.wkLines || []).find(l => l.lid === lid);
    out.push(dayLabel(d) + (ln ? ' · ' + wkPL((S.cache.projects || []).find(x => x.id === Number(ln.project_id))) : ''));
  }
  return out;
}
function wkBadMsg(list) { return T('Fix the hours marked in red before saving. Hours must be multiples of') + ' ' + wkInc() + ': ' + list.join('; '); }
/* body of an entry for the server, with the line's project / service / type */
function wkBody(e, ln, over = {}) {
  const b = { work_date: e.work_date, project_id: Number(ln.project_id), note: e.note || null };
  if (ln.service_id) b.service_id = Number(ln.service_id);
  if (S.cache.wkCtx.emp) b.hour_type = ln.hour_type;
  if (over.note !== undefined) b.note = over.note;
  if (over.hours !== undefined) b.hours = over.hours; else if (e.start_time) { b.start_time = e.start_time; b.end_time = e.end_time; } else b.hours = e.hours;
  return b;
}
/* Save draft: new entries are created, changed ones updated, cells left at 0 or empty are deleted. Returns how many calls were made. */
async function wkSave() {
  const st = WK(); const C = S.cache.wkCtx; const del = [], put = [], post = []; const now = today();
  const bad = wkBadCells(); if (bad.length) throw new Error(wkBadMsg(bad));
  for (const ln of S.cache.wkLines) {
    const p = (S.cache.projects || []).find(x => x.id === Number(ln.project_id)); const mode = wkMode(p, ln.service_id); const changed = !!st.edits[ln.lid];
    for (const d of C.days) {
      const es = ln.ents.filter(e => e.work_date === d); const key = ln.lid + '|' + d;
      if (Object.prototype.hasOwnProperty.call(st.pend, key) && mode === 'full_day' && es.length <= 1) {
        const raw = String(st.pend[key]).trim(); const n = raw === '' ? 0 : Number(raw);
        if (!Number.isFinite(n) || n < 0) throw new Error('Hours must be a number, zero or more.');
        if (n > C.maxDay) throw new Error('The most hours you can enter for one day is ' + C.maxDay + '.');
        if (n > 0 && d > now) throw new Error('You cannot enter hours on future dates.');
        const e = es[0]; const nt = Object.prototype.hasOwnProperty.call(st.pnote, key) ? { note: st.pnote[key] || null } : {};
        if (e && n === 0) del.push(e.id);
        else if (e) { if (n !== Number(e.hours) || changed || 'note' in nt) put.push([e.id, wkBody(e, ln, { hours: n, ...nt })]); }
        else if (n > 0) post.push(wkBody({ work_date: d, note: null }, ln, { hours: n, ...nt }));
      } else if (changed || Object.prototype.hasOwnProperty.call(st.pnote, key)) { const nt = Object.prototype.hasOwnProperty.call(st.pnote, key) ? { note: st.pnote[key] || null } : {}; for (const e of es) put.push([e.id, wkBody(e, ln, nt)]); }
    }
  }
  for (const id of del) await api('/entries/' + id, { method: 'DELETE' });
  for (const [id, b] of put) await api('/entries/' + id, { method: 'PUT', body: b });
  for (const b of post) await api('/entries', { method: 'POST', body: b });
  delete S.cache.wkst[S.weekDate];
  return del.length + put.length + post.length;
}
/* a line whose project / service / type was changed on screen: move its saved entries first */
async function wkApplyEdits(ln) {
  const st = WK(); if (!st.edits[ln.lid]) return;
  for (const e of ln.ents) await api('/entries/' + e.id, { method: 'PUT', body: wkBody(e, ln) });
  delete st.edits[ln.lid];
}
/* once a line has saved entries it is identified by project + service + type: carry its pending cells over and drop the on-screen placeholder */
function wkRelid(ln) {
  const st = WK(); const nl = wkIdKey(ln);
  if (nl !== ln.lid) for (const k of Object.keys(st.pend)) if (k.startsWith(ln.lid + '|')) { st.pend[nl + k.slice(ln.lid.length)] = st.pend[k]; delete st.pend[k]; }
  st.extra = st.extra.filter(x => x.lid !== ln.lid); delete st.edits[ln.lid];
}

/* checks the time / hours fields of the small box against the hour increment; returns the message ('' = fine) and blocks Save while it is not */
function wkPopCheck(f) {
  const step = wkStepMin(); let msg = '';
  f.querySelectorAll('[data-tm]').forEach(i => { const ok = wkMinMulti(i.value, step); i.classList.toggle('bad', !ok); if (!ok && !msg) msg = 'Minutes must be multiples of ' + step; });
  f.querySelectorAll('[data-hh]').forEach(i => { const ok = wkMulti(i.value); i.classList.toggle('bad', !ok); if (!ok && !msg) msg = wkIncMsg(wkInc()); });
  const box = f.querySelector('.poperr'); if (box) { box.hidden = !msg; box.textContent = msg ? T(msg) : ''; }
  const btn = f.querySelector('[type=submit]'); if (btn) btn.disabled = !!msg;
  return msg;
}
document.addEventListener('input', e => { const f = e.target.closest?.('.wkpop form'); if (f && e.target.matches('[data-tm],[data-hh]')) wkPopCheck(f); });
document.addEventListener('change', e => { const f = e.target.closest?.('.wkpop form'); if (f && e.target.matches('[data-tm],[data-hh]')) { const m = wkPopCheck(f); if (m) toast(m, 'err'); } });
/* small box for "detail" cells (from / to) and for days with several entries */
let wkPopPos = null;
function wkPopClose() { document.querySelectorAll('.wkpop').forEach(x => x.remove()); }
function wkPop(lid, d, anchor, editId) {
  wkPopClose();
  const ln = (S.cache.wkLines || []).find(l => l.lid === lid); if (!ln) return;
  const C = S.cache.wkCtx; const p = (S.cache.projects || []).find(x => x.id === Number(ln.project_id)); const mode = wkMode(p, ln.service_id);
  const es = ln.ents.filter(e => e.work_date === d); const ed = editId ? es.find(e => e.id === editId) : null;
  const svc = (p?.services || []).find(s => s.id === Number(ln.service_id));
  const items = es.map(e => `<li><span class="mono">${e.start_time ? esc(e.start_time) + '–' + esc(e.end_time) : 'Full day'}</span><span class="mono">${h1(e.hours)} h</span><span class="n">${esc(e.note || '')}</span>
    ${C.locked || (C.dayLocked && C.dayLocked(d)) ? '' : `<button type="button" class="btn ghost sm" data-act="pop-edit" data-id="${e.id}">Edit</button><button type="button" class="icon" data-act="pop-del" data-id="${e.id}" aria-label="Delete this entry">${wkClose}</button>`}</li>`).join('');
  const dl = C.locked || (C.dayLocked && C.dayLocked(d));
  const form = dl ? '' : `<form data-form="wk-pop"><input type="hidden" name="lid" value="${esc(lid)}"><input type="hidden" name="d" value="${d}"><input type="hidden" name="id" value="${ed ? ed.id : ''}">
    <div class="g2">${mode === 'from_to'
      ? `<div><label>From</label><input name="start_time" type="time" step="${(wkStepMin() || 1) * 60}" data-tm required value="${esc(ed?.start_time || '')}"></div><div><label>To</label><input name="end_time" type="time" step="${(wkStepMin() || 1) * 60}" data-tm required value="${esc(ed?.end_time || '')}"></div>`
      : `<div><label>Hours for the day</label><input name="hours" type="number" min="0" max="${C.maxDay}" step="${C.inc}" data-hh required value="${ed ? ed.hours : ''}"></div>`}</div>
    <div class="poperr" role="alert" hidden></div>
    <div><label>Note</label><input name="note" maxlength="1000" value="${esc(ed?.note || '')}"></div>
    <div class="fb"><button class="btn pri sm" type="submit">${ed ? 'Save entry' : 'Add entry'}</button><button class="btn ghost sm" type="button" data-act="pop-close">Close</button></div></form>`;
  const el = document.createElement('div'); el.className = 'wkpop'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Entries for ' + dayLabel(d));
  el.innerHTML = localizeHtml(`<div class="h"><b>${dayLabel(d)}</b><span class="muted">${esc(wkPL(p))}${svc && wkSvcs(p).length > 1 ? ' · ' + esc(svc.name) : ''}</span></div>${items ? `<ul>${items}</ul>` : ''}${form}`);
  document.body.appendChild(el);
  if (anchor && anchor.isConnected) { const r = anchor.getBoundingClientRect(); wkPopPos = { left: r.left + scrollX, top: r.bottom + scrollY + 6 }; }
  const pos = wkPopPos || { left: 40, top: 120 };
  el.style.left = Math.max(8, Math.min(pos.left, scrollX + innerWidth - 340)) + 'px'; el.style.top = pos.top + 'px';
  const f = el.querySelector('input:not([type=hidden])'); if (f) f.focus();
}
/* clock: icon on the screen, small dialog to start / stop */
function wkModalClose() { document.querySelectorAll('.wkmodal').forEach(x => x.remove()); }
function wkModalOpen(inner) {
  wkModalClose();
  const m = document.createElement('div'); m.className = 'wkmodal';
  m.innerHTML = localizeHtml(`<div class="box" role="dialog" aria-modal="true" aria-labelledby="clk-t">${inner}</div>`);
  m.addEventListener('mousedown', e => { if (e.target === m) wkModalClose(); });
  document.body.appendChild(m); const f = m.querySelector('select,button'); if (f) f.focus(); return m;
}

const clockStart = run => new Date(`${run.work_date}T${String(run.start_time).slice(0, 5)}:00`);
function clockBelow(passed, min) {
  wkModalOpen(`<h2 id="clk-t">Clock</h2><p>Only ${passed} min passed. The minimum is ${min} min. Record it as ${min} min?</p>
    <div class="fb"><button class="btn pri" type="button" data-act="clock-min" data-min="${min}">Record ${min} min</button><button class="btn ghost" type="button" data-act="wk-modal-close">Keep clock running</button></div>`);
}
async function clockStop(at) {
  try { await api('/clock/stop', { method: 'POST', body: at ? { at: at.toISOString() } : {} }); }
  catch (e) {
    const run = S.cache.wkRunning; const d = e.data || {};
    if (e.status === 422 && d.code === 'below_minimum' && run) { clockBelow(Math.max(0, Math.round((Date.now() - clockStart(run)) / 60000)), d.minimum_minutes); return; }
    if (e.status === 422 && d.code === 'not_aligned' && d.suggested_end_time && !at) { const t = new Date(); const [h, m] = d.suggested_end_time.split(':'); t.setHours(+h, +m, 0, 0); return clockStop(t); }
    throw e;
  }
  wkModalClose(); flash('Clock stopped.'); render();
}
Object.assign(actions, {
  'wk-go': el => { S.weekDate = el.dataset.end; render(); },
  'wk-add'() {
    const p = (S.cache.projects || []).find(x => x.active && wkSvcs(x).length); if (!p) throw new Error('There are no projects to add.');
    const st = WK(); st.extra.push({ lid: 'n' + (++st.n), project_id: p.id, service_id: wkSvcs(p)[0].id, hour_type: 'reg' }); render();
  },
  async 'wk-copy'() {
    const prev = addDays(weekEnd(S.weekDate), -7); const r = await api(`/entries?from=${addDays(prev, -6)}&to=${prev}`);
    const seen = new Set(S.cache.wkLines.map(wkIdKey)); const st = WK(); let n = 0;
    for (const e of r.entries.filter(x => !x.running)) {
      const t = e.hour_type || 'reg'; const k = [e.project_id, e.service_id ?? '', t].join('|'); if (seen.has(k)) continue; seen.add(k);
      st.extra.push({ lid: 'n' + (++st.n), project_id: e.project_id, service_id: e.service_id ?? null, hour_type: t }); n++;
    }
    flash(n ? (n === 1 ? '1 line copied.' : n + ' lines copied.') : 'There are no new lines to copy from the previous week.', n ? 'ok' : 'warn'); render();
  },
  async 'wk-del'(el) {
    const ln = S.cache.wkLines.find(l => l.lid === el.dataset.lid); if (!ln) return; const st = WK();
    const hours = ln.ents.reduce((a, e) => a + Number(e.hours), 0) + Object.entries(st.pend).filter(([k, v]) => k.startsWith(ln.lid + '|') && Number(v) > 0).length;
    if (hours > 0 && !confirm(T('Remove this line and delete its hours for this week?'))) return;
    for (const e of ln.ents) await api('/entries/' + e.id, { method: 'DELETE' });
    st.extra = st.extra.filter(x => x.lid !== ln.lid); delete st.edits[ln.lid];
    for (const k of Object.keys(st.pend)) if (k.startsWith(ln.lid + '|')) delete st.pend[k];
    render();
  },
  async 'wk-save'() {
    const bad = wkBadCells(); if (bad.length) { toast(wkBadMsg(bad), 'err'); document.querySelector('.wk .wkc.bad')?.focus(); return; }   // nothing is saved while a cell is invalid
    const n = await wkSave(); flash(n ? 'Draft saved.' : 'Nothing to save.'); render();
  },
  async 'wk-submit'() {
    if (!WK().cert) return; const id = S.cache.wkSheet.id; const note = WK().snote ?? S.cache.wkSheet.submission_note ?? '';
    const bad = wkBadCells(); if (bad.length) { toast(wkBadMsg(bad), 'err'); document.querySelector('.wk .wkc.bad')?.focus(); return; }
    await wkSave(); await api(`/sheets/${id}/submit`, { method: 'POST', body: { note: note || null } }); flash('Timesheet submitted for approval.'); render();
  },
  /* "send through here": the marked day and every open day before it, in order (each block of open days becomes one sheet) */
  async 'wk-send-days'() {
    const st = WK(); if (!st.cert || !st.through) return; const through = st.through; const note = st.snote ?? S.cache.wkSheet.submission_note ?? '';
    const bad = wkBadCells(); if (bad.length) { toast(wkBadMsg(bad), 'err'); document.querySelector('.wk .wkc.bad')?.focus(); return; }
    await wkSave(); await api('/sheets/submit-days', { method: 'POST', body: { through, note: note || null } }); flash('Days sent for approval.'); render();
  },
  'wk-through'(el) { const st = WK(); st.through = st.through === el.dataset.d ? null : el.dataset.d; render(); },
  /* a note for one line and day: kept with the rest of the pending changes until Save draft */
  'cell-note'(el) {
    const st = WK(); const ln = (S.cache.wkLines || []).find(l => l.lid === el.dataset.lid); if (!ln) return; const d = el.dataset.d; const key = ln.lid + '|' + d;
    const es = ln.ents.filter(e => e.work_date === d); const p = (S.cache.projects || []).find(x => x.id === Number(ln.project_id));
    const cur = Object.prototype.hasOwnProperty.call(st.pnote, key) ? st.pnote[key] : (es[0]?.note || ''); const fromSub = !Object.prototype.hasOwnProperty.call(st.pnote, key) && es[0]?.note_from_submission;
    const ro = S.cache.wkCtx.locked || S.cache.wkCtx.dayLocked(d);
    modalOpen({ title: 'Note', sub: `${dayLabel(d)} · ${wkPL(p)}`, size: 'sm', save: ro ? 'Close' : 'Save note',
      body: `<div><label>Note</label><textarea name="note" rows="5" maxlength="1000" ${ro ? 'readonly' : ''}>${esc(cur)}</textarea>${!es.length ? `<small class="help">${T('A note on a day without hours is saved together with the hours.')}</small>` : ''}${fromSub ? `<small class="help">${T('Copied from the submission note. Edit it to make it this line\'s own.')}</small>` : ''}</div>`,
      async onSubmit(m) {
        if (ro) return m.finish(null, async () => {});
        const v = String(formObj(m.form).note || '').trim(); st.pnote[key] = v; await m.finish(null, async () => render());
      } });
  },
  'cell-pop'(el) { wkPop(el.dataset.lid, el.dataset.d, el); },
  'pop-edit'(el) { const f = el.closest('.wkpop').querySelector('form'); wkPop(f ? f.elements.lid.value : '', f ? f.elements.d.value : '', null, Number(el.dataset.id)); },
  async 'pop-del'(el) {
    if (!confirm(T('Delete this entry?'))) return;
    wkPopClose(); await api('/entries/' + el.dataset.id, { method: 'DELETE' }); flash('Entry deleted.'); render();
  },
  'pop-close': () => wkPopClose(),
  'wk-modal-close': () => wkModalClose(),
  'clock-open'() {
    const run = S.cache.wkRunning;
    if (run) {
      const p = (S.cache.projects || []).find(x => x.id === run.project_id) || run.project;
      wkModalOpen(`<h2 id="clk-t">Clock</h2><p>Running on <b>${esc(wkPL(p))}${run.service && wkSvcs(p).length > 1 ? ' · ' + esc(run.service.name) : ''}</b> since <b class="mono">${esc(run.start_time)}</b></p>
        <div class="fb"><button class="btn pri" type="button" data-act="clock-stop">Stop</button><button class="btn ghost" type="button" data-act="wk-modal-close">Close</button></div>`);
      return;
    }
    const ps = (S.cache.projects || []).filter(x => x.active && wkSvcs(x).some(s => s.mode === 'from_to'));
    if (!ps.length) { wkModalOpen(`<h2 id="clk-t">Clock</h2><p class="muted">There are no projects where the clock can be used.</p><div class="fb"><button class="btn ghost" type="button" data-act="wk-modal-close">Close</button></div>`); return; }
    const m = wkModalOpen(`<h2 id="clk-t">Clock</h2><form data-form="clock"><div><label>Project</label><select name="project_id" data-act-change="clock-project">${ps.map(x => `<option value="${x.id}">${esc(wkPL(x))}</option>`).join('')}</select></div>
      <div><label>Service</label><select name="service_id"></select></div>
      <div class="fb"><button class="btn pri" type="submit">Start</button><button class="btn ghost" type="button" data-act="wk-modal-close">Cancel</button></div></form>`);
    const f = m.querySelector('form'); syncClockForm(f); f.elements.service_id.parentElement.hidden = f.elements.service_id.hidden;
  },
  /* Stop: the end time is the current time rounded to the nearest multiple of the increment, sent as 'at' so the server does not refuse it. */
  async 'clock-stop'() {
    const run = S.cache.wkRunning; const step = wkStepMin(); const now = new Date();
    if (!run || !step) return clockStop(null);
    const mins = Math.min(Math.round((now.getHours() * 60 + now.getMinutes()) / step) * step, Math.floor(1439 / step) * step);
    const at = new Date(now); at.setHours(Math.floor(mins / 60), mins % 60, 0, 0);
    const start = clockStart(run); const min = Number(S.me.rules?.minimum_minutes) || step;
    if ((at - start) / 60000 < min) return clockBelow(Math.max(0, Math.round((now - start) / 60000)), min);
    return clockStop(at);
  },
  'clock-min'(el) { return clockStop(new Date(clockStart(S.cache.wkRunning).getTime() + Number(el.dataset.min) * 60000)); },
});
Object.assign(forms, {
  async clock(f) { const o = formObj(f); if (!o.service_id) delete o.service_id; await api('/clock/start', { method: 'POST', body: o }); wkModalClose(); flash('Clock started.'); render(); },
  async 'wk-pop'(f) {
    const o = formObj(f); const ln = S.cache.wkLines.find(l => l.lid === o.lid); if (!ln) return; const C = S.cache.wkCtx;
    const p = (S.cache.projects || []).find(x => x.id === Number(ln.project_id)); const mode = wkMode(p, ln.service_id);
    if (wkPopCheck(f)) return;   // minutes / hours not aligned to the increment: red, warned, nothing is sent
    const b = { work_date: o.d, project_id: Number(ln.project_id), note: o.note || null };
    if (ln.service_id) b.service_id = Number(ln.service_id); if (C.emp) b.hour_type = ln.hour_type;
    if (mode === 'from_to') { b.start_time = o.start_time; b.end_time = o.end_time; } else b.hours = o.hours;
    try {
      await wkApplyEdits(ln);
      if (o.id) await api('/entries/' + o.id, { method: 'PUT', body: b }); else await api('/entries', { method: 'POST', body: b });
    } catch (e) {   // the server refused (422 start_time / end_time / hours): shown in the box, which stays open
      if (e.message === 'Session expired') throw e;
      const box = f.querySelector('.poperr'); const er = e.errors || {};
      if (box) { box.hidden = false; box.textContent = T(er.start_time?.[0] || er.end_time?.[0] || er.hours?.[0] || e.message); }
      for (const k of Object.keys(er)) f.elements[k]?.classList?.add('bad');
      toast(er.start_time?.[0] || er.end_time?.[0] || er.hours?.[0] || e.message, 'err'); return;
    }
    wkPopClose(); wkRelid(ln); flash('Entry saved.'); render();
  },
});
/* typing in a cell: the change stays pending (marked) until Save draft */
document.addEventListener('input', e => { if (e.target.matches?.('[data-snote]')) { WK().snote = e.target.value; return; }
  
  const i = e.target.closest?.('[data-cell]'); if (!i) return;
  i.classList.add('dirty'); WK().pend[i.dataset.cell] = i.value; wkRecalc();
  const okv = wkMulti(i.value); i.classList.toggle('bad', !okv); if (okv) { i.removeAttribute('aria-invalid'); i.removeAttribute('title'); } else { i.setAttribute('aria-invalid', 'true'); i.title = T(wkIncMsg(wkInc())); }
});
document.addEventListener('change', e => {
  const i = e.target.closest?.('[data-cell]');
  if (i) {
    const C = S.cache.wkCtx; const st = WK(); let v = i.value === '' ? 0 : Number(i.value); let bad = '';
    if (!(v >= 0)) bad = 'Hours must be a number, zero or more.';
    else if (!wkMulti(i.value, C.inc)) {   // not a multiple of the increment: no rounding in silence; mark it red, warn, and keep it pending so Save draft refuses
      st.pend[i.dataset.cell] = i.value; i.classList.add('dirty', 'bad'); i.setAttribute('aria-invalid', 'true'); i.title = T(wkIncMsg(C.inc)); wkRecalc(); toast(wkIncMsg(C.inc), 'err'); return;
    }
    else { i.classList.remove('bad'); i.removeAttribute('aria-invalid'); i.removeAttribute('title'); i.value = v ? h1(v) : ''; wkRecalc(); const di = +i.dataset.di; const dsum = [...document.querySelectorAll(`.wk .wkc[data-di="${di}"]`)].reduce((a, x) => a + (parseFloat(x.value) || 0), 0); if (dsum > C.maxDay) bad = 'The most hours you can enter for one day is ' + C.maxDay + '.'; }
    if (bad) { i.value = i.dataset.orig; delete st.pend[i.dataset.cell]; i.classList.remove('dirty', 'bad'); i.removeAttribute('aria-invalid'); wkRecalc(); toast(bad, 'err'); return; }
    if (String(i.value) === String(i.dataset.orig)) { delete st.pend[i.dataset.cell]; i.classList.remove('dirty'); } else st.pend[i.dataset.cell] = i.value;
    return;
  }
  const c = e.target.closest?.('[data-act-change="wk-cert"]'); if (c) { WK().cert = c.checked; wkRecalc(); return; }
  const s = e.target.closest?.('[data-act-change="wk-line"]');
  if (s) {
    const ln = S.cache.wkLines.find(l => l.lid === s.dataset.lid); if (!ln) return; const ed = (WK().edits[ln.lid] ??= {});
    if (s.dataset.fld === 'project_id') { const p = S.cache.projects.find(x => x.id === Number(s.value)); ed.project_id = p.id; ed.service_id = wkSvcs(p)[0]?.id ?? null; }
    else if (s.dataset.fld === 'service_id') ed.service_id = Number(s.value); else ed.hour_type = s.value;
    render();
  }
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { wkPopClose(); wkModalClose(); return; }
  const cp = e.target.closest?.('[data-act="cell-pop"]'); if (cp && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); cp.click(); return; }
  if (e.key !== 'Enter') return; const i = e.target.closest?.('[data-cell]'); if (!i) return;
  e.preventDefault(); const all = [...document.querySelectorAll('.wk input.wkc:not([readonly])')]; const n = all[all.indexOf(i) + 1]; if (n) { n.focus(); n.select(); }
});
document.addEventListener('mousedown', e => { if (!e.target.closest('.wkpop') && !e.target.closest('[data-act="cell-pop"]') && !e.target.closest('[data-act="pop-edit"]')) wkPopClose(); });

const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { if (e.message !== 'Session expired') { flash(e.message, 'err'); render(); } } };
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="entry-project"],[data-act-change="entry-service"]'); if (el) syncEntryForm(el.closest('form')); });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="clock-project"]'); if (el) syncClockForm(el.closest('form')); });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="cs-customer"]'); if (el) { const f = el.closest('form'); S.cache.cs = { customer_id: Number(el.value), from: f.elements.from.value, to: f.elements.to.value }; S.cache.csPreview = null; render(); } });
document.addEventListener('change', e => {
  const el = e.target.closest('[data-act-change="proj-billable"]'); if (!el) return;
  const f = el.closest('form'); const sel = f.elements.customer_id; sel.required = el.checked;
  const lb = sel.parentElement.querySelector('label'); lb.textContent = T('Client') + (el.checked ? ' *' : '');
});
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="proj-client"]'); if (el) { const w = el.parentElement.querySelector('[data-nocli]'); if (w) w.hidden = !!el.value; } });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="lang-pick"]'); if (el) guard(actions.lang)({ dataset: { to: el.value } }); });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="wk-pick"]'); if (el) { S.weekDate = el.value; render(); } });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="exp-refresh"]'); if (el) { const f = el.closest('form'); const o = formObj(f); S.cache.exp = { ...o, picked: ['customer_id', 'from', 'to', 'user_id'].includes(el.name) ? null : (o.project_ids || null) }; render(); } });
document.addEventListener('change', e => { const el = e.target.closest('[data-filter]'); if (el) { FL(el.dataset.filter)[el.name] = el.value; render(); } });
document.addEventListener('click', e => { const el = e.target.closest('[data-act]'); if (el && actions[el.dataset.act]) { e.preventDefault(); guard(actions[el.dataset.act])(el); } });
document.addEventListener('submit', e => { const f = e.target.closest('form[data-form]'); if (f && forms[f.dataset.form]) { e.preventDefault(); guard(forms[f.dataset.form])(f); } });
/* Password fields: the eye button never takes the focus away from the input; Caps Lock notice; strength bar. */
document.addEventListener('mousedown', e => { if (e.target.closest('.eye')) e.preventDefault(); });
const capsCheck = e => { const i = e.target.closest?.('.pw input'); if (!i || !e.getModifierState) return; const c = i.closest('.pwf').querySelector('.caps'); c.hidden = !(e.getModifierState('CapsLock') && e.type !== 'focusout'); };
['keydown', 'keyup', 'focusout'].forEach(t => document.addEventListener(t, capsCheck));
document.addEventListener('input', e => {
  const i = e.target.closest?.('[data-strength]'); if (!i) return;
  const box = i.closest('.pwf').querySelector('.strength'); const [lv, txt] = pwStrength(i.value);
  box.dataset.level = lv; box.querySelector('small').textContent = T(txt);
});
window.addEventListener('hashchange', render);
render();
})();
