/* Tiempo web interface. No build step: runs on any PHP hosting. Talks to /api. */
(() => {
const $app = document.getElementById('app');
const S = { token: localStorage.getItem('tt'), me: null, flash: null, weekDate: today(), cache: {},
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
function weekEnd(s) { const d = parse(s); const idx = (d.getDay() + 6) % 7; d.setDate(d.getDate() + (6 - idx)); return fmt(d); }
const DOWS = { en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], es: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] };
const dow = i => DOWS[LANG() === 'es' ? 'es' : 'en'][i];
const LOC = () => (LANG() === 'es' ? 'es-US' : 'en-US');
const dayLabel = s => dow(parse(s).getDay()) + ' ' + s.slice(5);
const nice = s => parse(s).toLocaleDateString(LOC(), { month: 'short', day: 'numeric', year: 'numeric' });
const longDay = s => parse(s).toLocaleDateString(LOC(), { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
const STATUS = { draft: 'Draft', submitted: 'Submitted', approved: 'Approved', returned: 'Returned' };
/* Status pills in tables, like the mockup: gray, amber, green, red, blue. */
const pill = (text, color = 'gray') => `<span class="bd ${color}">${esc(text)}</span>`;
const activePill = on => (on ? pill('Active', 'green') : pill('Inactive'));

async function api(path, o = {}) {
  const headers = { Accept: 'application/json', 'X-Locale': LANG() };
  if (S.token) headers.Authorization = 'Bearer ' + S.token;
  let body;
  if (o.body instanceof FormData) body = o.body;
  else if (o.body !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(o.body); }
  const r = await fetch('/api' + path, { method: o.method || 'GET', headers, body });
  if (o.blob) { if (!r.ok) throw new Error('The file could not be downloaded'); return r; }
  const data = await r.json().catch(() => ({}));
  if (r.status === 401 && S.token) { logout(); throw new Error('Session expired'); }
  if (!r.ok) {
    const first = data.errors ? Object.values(data.errors).flat()[0] : null;
    const e = new Error(first || data.message || 'Error ' + r.status); e.status = r.status; e.errors = data.errors; throw e;
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
function logout() { S.token = null; S.me = null; localStorage.removeItem('tt'); location.hash = ''; render(); }
function applyBrand(b) {
  const r = document.documentElement.style;
  r.setProperty('--primary', b.color_primary); r.setProperty('--accent', b.color_accent);
  document.title = b.name;
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
  approvals: { t: 'Approvals', owner: true, f: approvalsView },
  invoices: { t: 'Invoices', f: invoicesView },
  reports: { t: 'Hours report', f: reportsView },
  clientsheet: { t: 'Client timesheet', owner: true, f: clientSheetView },
  export: { t: 'Export', f: exportView },
  people: { t: 'People', owner: true, f: peopleView },
  approvers: { t: 'Approvers', owner: true, f: approversView },
  customers: { t: 'Clients', owner: true, f: customersView },
  projects: { t: 'Projects', owner: true, f: projectsView },
  rules: { t: 'Rules', owner: true, f: rulesView },
  brand: { t: 'Branding', owner: true, f: brandView },
  integrations: { t: 'Integration', owner: true, f: integrationsView },
};
/* Top tabs: related screens share one tab and show a second row of pills inside the page. */
const GROUPS = [
  { t: 'Timesheet', r: ['week', 'weeks'] },
  { t: 'Approvals', r: ['approvals'] },
  { t: 'Invoices', r: ['invoices'] },
  { t: 'Reports', r: ['reports', 'clientsheet', 'export'] },
  { t: 'People & projects', r: ['people', 'approvers', 'customers', 'projects'] },
  { t: 'Settings', r: ['rules', 'brand', 'integrations'] },
];
const route = () => (location.hash.replace('#/', '') || 'week').split('?')[0];
const canSee = k => ROUTES[k] && (!ROUTES[k].owner || S.me.role === 'owner');
const roleLabel = u => (u.role === 'owner' ? 'Owner' : u.worker_type === 'employee' ? 'Employee' : 'Consultant');
const initials = n => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
let RID = 0, LAST = null;

function shell(name, body, withFlash) {
  const b = S.me.brand;
  const groups = GROUPS.map(g => ({ ...g, vis: g.r.filter(canSee) })).filter(g => g.vis.length);
  const cur = groups.find(g => g.vis.includes(name));
  const fl = withFlash && S.flash ? `<div class="msg ${S.flash.kind}">${esc(T(S.flash.text))}</div>` : '';
  if (withFlash) S.flash = null;
  const sub = cur && cur.vis.length > 1 ? `<div class="subtabs">${cur.vis.map(k => `<a href="#/${k}" class="${k === name ? 'on' : ''}">${ROUTES[k].t}</a>`).join('')}</div>` : '';
  return `<header class="hdr"><div class="brand">${b.logo_url ? `<img src="${esc(b.logo_url)}" alt="${esc(b.name)}"><span class="sep"></span>` : ''}<div><b>${esc(b.name)}</b><small>Timesheets &amp; invoices</small></div></div>
    <span class="spacer"></span><div class="who"><span class="av">${esc(initials(S.me.name))}</span><div><b>${esc(S.me.name)}</b><small>${roleLabel(S.me)}</small></div></div>
    <button class="btn ghost sm" data-act="lang" data-to="${LANG() === 'es' ? 'en' : 'es'}" title="Language">${LANG() === 'es' ? 'English' : 'Español'}</button>
    <button class="btn ghost sm" data-act="logout">Sign out</button></header>
    <div class="stripe"></div>
    <nav class="tabs">${groups.map(g => `<a href="#/${g.vis[0]}" class="${g === cur ? 'on' : ''}">${g.t}</a>`).join('')}</nav>
    <main>${sub}${fl}${body}</main>`;
}
const setApp = html => { $app.innerHTML = localizeHtml(html); afterRender(); };

async function render() {
  if (!S.token) return loginView();
  const rid = ++RID;
  try {
    if (!S.me) { S.me = await api('/me'); S.lang = S.me.locale; localStorage.setItem('lang', S.lang); }
    if (rid !== RID) return;
    applyBrand(S.me.brand);
    const name = canSee(route()) ? route() : 'week';
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

/* Sign in. If the server has Cloudflare Turnstile keys, the anti-bot challenge is shown before signing in. */
async function loginView(err) {
  LAST = null;
  if (S.cache.pub === undefined) { try { S.cache.pub = await (await fetch('/api/public-config')).json(); } catch { S.cache.pub = {}; } }
  const key = S.cache.pub.turnstile_site_key;
  setApp(`<div class="login-wrap"><div class="stripe"></div><div class="card login">
    <div class="eyebrow">Tiempo · Timesheets &amp; invoices</div><h1>Sign in</h1>
    <p class="sub">Use the email address your invitation was sent to.</p>${err ? `<div class="msg err">${esc(err)}</div>` : ''}
    <form data-form="login"><p><label>Email</label><input name="email" type="email" required autocomplete="username" placeholder="name@company.com"></p>
    <p><label>Password</label><input name="password" type="password" required autocomplete="current-password" placeholder="Your password"></p>
    ${key ? '<div id="ts-box" style="margin:8px 0"></div>' : ''}
    <button class="btn pri" type="submit">Continue</button></form>
    <p class="muted" style="margin:14px 0 0">Access is by invitation only. Forgot your password? Ask your administrator to send a reset link.</p>
    <p style="text-align:center;margin:14px 0 0"><button class="btn ghost sm" type="button" data-act="lang" data-to="${LANG() === 'es' ? 'en' : 'es'}">${LANG() === 'es' ? 'English' : 'Español'}</button></p></div></div>`);
  if (key) mountTurnstile(key);
}
function mountTurnstile(key) {
  const draw = () => { const box = document.getElementById('ts-box'); if (box && window.turnstile) { box.innerHTML = ''; S.cache.tsWidget = window.turnstile.render(box, { sitekey: key }); } };
  if (window.turnstile) return draw();
  if (document.getElementById('ts-script')) return;
  const s = document.createElement('script');
  s.id = 'ts-script'; s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'; s.async = true; s.onload = draw;
  document.head.appendChild(s);
}

/* --- Timesheet (weekly time record) --- */
async function weekView() {
  const end = weekEnd(S.weekDate), start = addDays(end, -6);
  const [sheet, ents, projects, mine] = await Promise.all([
    api('/sheets/week?date=' + S.weekDate), api(`/entries?from=${start}&to=${end}`), api('/projects'), api('/sheets').catch(() => [])]);
  S.cache.projects = projects;
  const days = Array.from({ length: 7 }, (_, i) => addDays(start, i));
  const mode = S.me.capture_mode;
  const running = ents.entries.find(e => e.running);
  const locked = sheet.locked;
  const list = ents.entries.filter(e => !e.running);
  S.cache.entries = ents.entries;

  // Week picker: the last 12 weeks, with the state of each one
  const cur = weekEnd(today());
  const stateOf = Object.fromEntries(mine.map(s => [s.week_ending, STATUS[s.status] || s.status]));
  const weeks = Array.from({ length: 12 }, (_, i) => addDays(cur, -7 * i));
  if (!weeks.includes(end)) weeks.push(end);
  weeks.sort().reverse();
  const picker = weeks.map(w => `<option value="${w}" ${w === end ? 'selected' : ''}>${nice(w)} · ${stateOf[w] ? T(stateOf[w]) : T('Not started')}${w === cur ? ' · ' + T('current') : ''}</option>`).join('');

  // Grid: one row per project, one column per day
  const byProj = new Map();
  const svcCount = id => (projects.find(p => p.id === id)?.services || []).length;
  for (const e of list) { const k = e.project_id + '|' + (svcCount(e.project_id) > 1 ? e.service_id : ''); if (!byProj.has(k)) byProj.set(k, { p: e.project, s: svcCount(e.project_id) > 1 ? e.service : null, by: {} }); const r = byProj.get(k); r.by[e.work_date] = (r.by[e.work_date] || 0) + e.hours; }
  const dayTot = d => list.filter(e => e.work_date === d).reduce((a, e) => a + e.hours, 0);
  const cell = v => (v ? `<td>${h1(v)}</td>` : '<td class="zero">0</td>');
  const gridRows = [...byProj.values()].map(r => `<tr><td>${esc(r.p?.code)} · ${esc(r.p?.name)}${r.s ? ' · ' + esc(r.s.name) : ''}</td>${days.map(d => cell(r.by[d])).join('')}<td class="r"><b>${h1(Object.values(r.by).reduce((a, v) => a + v, 0))}</b></td></tr>`).join('')
    || `<tr><td colspan="9" class="muted">No hours yet this week. Add an entry below.</td></tr>`;
  const bill = list.filter(e => e.billable !== false).reduce((a, e) => a + e.hours, 0);
  const non = list.filter(e => e.billable === false).reduce((a, e) => a + e.hours, 0);

  const lines = list.map(e => `<tr><td>${dayLabel(e.work_date)}</td><td>${esc(e.project?.code)}${e.service && (projects.find(p => p.id === e.project_id)?.services || []).length > 1 ? ' · ' + esc(e.service.name) : ''}</td>
      <td>${e.start_time ? esc(e.start_time) + '–' + esc(e.end_time) : 'Full day'}</td>
      <td class="r">${hrs(e.hours)}</td><td>${esc(e.note)}</td>
      <td class="r">${locked ? '' : `<button class="btn ghost sm" data-act="edit" data-id="${e.id}">Edit</button> <button class="btn danger sm" data-act="del" data-id="${e.id}">Delete</button>`}</td></tr>`).join('');
  const warn = sheet.warnings.map(w => `<div class="msg warn">${esc(w.message)}</div>`).join('');
  const ret = sheet.status === 'returned' && sheet.return_comment ? `<div class="msg err"><b>Returned for correction:</b> ${esc(sheet.return_comment)}</div>` : '';
  const opts = projects.filter(p => p.active).map(p => `<option value="${p.id}">${esc(p.code)} · ${esc(p.name)}</option>`).join('');
  const dayOpts = days.map(d => `<option value="${d}" ${d === (days.includes(today()) ? today() : days[0]) ? 'selected' : ''}>${dayLabel(d)}</option>`).join('');
  const form = locked ? `<div class="msg ok">This timesheet is approved and can no longer be changed.</div>` : `
    <div class="card"><h2 id="eform-title">Add entry</h2>
    <form data-form="entry"><input type="hidden" name="id">
    <div class="grid">
      <div><label>Day</label><select name="work_date">${dayOpts}</select></div>
      <div><label>Project</label><select name="project_id" required data-act-change="entry-project">${opts}</select></div>
      <div><label>Service</label><select name="service_id" data-act-change="entry-service"></select></div>
      <div data-f="detail"><label>From</label><input name="start_time" type="time" required></div><div data-f="detail"><label>To</label><input name="end_time" type="time" required></div>
      <div data-f="total"><label>Hours for the day</label><input name="hours" type="number" step="0.25" min="0.25" required></div>
      <div style="grid-column:span 2"><label>Note</label><input name="note" maxlength="1000"></div>
    </div>
    <p class="row"><button class="btn" type="submit">Save entry</button><button class="btn ghost" type="reset" data-act="cancel-edit">Clear</button></p></form></div>
    <div class="card"><h2>Clock</h2>${running
      ? `<div class="running">Running on <b>${esc(running.project?.code)}</b>${running.service && (running.project?.services || []).length > 1 ? ' · ' + esc(running.service.name) : ''} since ${esc(running.start_time)} · <button class="btn sm" data-act="clock-stop">Stop</button></div>`
      : `<form data-form="clock" class="row"><select name="project_id" style="max-width:320px" data-act-change="clock-project">${opts}</select><select name="service_id" style="max-width:240px"></select><button class="btn" type="submit">Start clock</button></form>`}</div>`;
  const late = sheet.late ? ' <span class="badge returned">Late</span>' : '';
  return `<div class="row"><div><h1>Weekly time record</h1><p class="sub" style="margin:0">Week ending ${longDay(end)}</p></div><span class="spacer"></span>
    <span class="badge ${sheet.status}">${STATUS[sheet.status]}</span>${late}</div>
    <div class="card" style="margin-top:16px"><div class="weeknav">
      <button class="btn ghost" data-act="wk" data-n="-7" title="Previous week">←</button>
      <div class="pick"><label>Week ending (Sunday)</label><select data-act-change="wk-pick">${picker}</select></div>
      <button class="btn ghost" data-act="wk" data-n="7" ${end >= cur ? 'disabled' : ''} title="Next week">→</button>
      <button class="btn ghost" data-act="wk-now" ${end === cur ? 'disabled' : ''}>Current week</button></div></div>
    ${warn}${ret}
    <div class="card"><div class="facts"><div><label>Name</label><b>${esc(S.me.name)}</b></div><div><label>Worker type</label><b>${S.me.worker_type === 'employee' ? 'Employee' : 'Consultant'}</b></div>
      <div><label>Entry preference</label><b>${mode === 'full_day' ? 'Total hours per day' : 'From / to'}</b></div><div><label>Version</label><b>${sheet.version}</b></div></div></div>
    <div class="card"><div class="row"><h2 style="margin:0">Hours by project</h2><span class="spacer"></span>${exportBar('week', { date: S.weekDate })}</div>
      <div class="scroll"><table class="daygrid"><tr><th>Project</th>${days.map(d => `<th>${dow(parse(d).getDay())}<br><span class="muted">${nice(d).replace(/, \d+$/, '')}</span></th>`).join('')}<th class="r">Total</th></tr>
      ${gridRows}<tr class="tot"><td>Daily total</td>${days.map(d => `<td>${h1(dayTot(d))}</td>`).join('')}<td class="r">${h1(sheet.total_hours)}</td></tr></table></div></div>
    <div class="stats"><div class="stat"><span>Billable hours</span><b>${h1(bill)}</b></div><div class="stat"><span>Non-billable hours</span><b>${h1(non)}</b></div><div class="stat"><span>Total</span><b>${h1(sheet.total_hours)}</b></div></div>
    <div class="card"><h2>Entries</h2>${lines ? `<table><tr><th>Day</th><th>Project</th><th>Time</th><th class="r">Hours</th><th>Note</th><th></th></tr>${lines}</table>` : '<p class="muted">No entries yet.</p>'}</div>
    <div class="card"><p class="muted" style="margin-top:0">I certify these hours are accurate. Submitting counts as my electronic signature.</p><p class="row" style="margin:0">
      ${['draft', 'returned'].includes(sheet.status) ? `<button class="btn pri" data-act="submit" data-id="${sheet.id}">${sheet.status === 'returned' ? 'Corrected: submit again' : 'Submit for approval'}</button>` : ''}
      ${sheet.status === 'submitted' ? '<span class="muted">Waiting for a decision. If you change it, the approver gets a notice that it changed.</span>' : ''}
      ${sheet.status === 'approved' ? '<span class="muted">Approved and locked.</span>' : ''}</p></div>${form}`;
}

/* --- All weeks: every week of the person, including the ones not filled in yet --- */
async function weeksView() {
  const mine = await api('/sheets');
  const cur = weekEnd(today());
  const back = Number(S.me.rules?.weeks_back ?? 8);
  const byEnd = Object.fromEntries(mine.map(s => [s.week_ending, s]));
  const ends = new Set([...Array.from({ length: back + 1 }, (_, i) => addDays(cur, -7 * i)), ...mine.map(s => s.week_ending)]);
  const rows = [...ends].sort().reverse().map(end => {
    const s = byEnd[end];
    const st = s ? s.status : 'none';
    const color = { draft: 'gray', submitted: 'blue', approved: 'green', returned: 'red', none: 'amber' }[st];
    const label = st === 'none' ? 'Not submitted' : STATUS[st];
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
    const days = Array.from({ length: 7 }, (_, i) => addDays(d.week_starting, i));
    const lines = new Map();
    const multi = id => (S.cache.allProjects?.find(p => p.id === id)?.services || []).length > 1;
    for (const e of d.entries) { const k = e.project_id + '|' + (multi(e.project_id) ? e.service_id : ''); if (!lines.has(k)) lines.set(k, { p: e.project, s: multi(e.project_id) ? e.service : null, by: {} }); const r = lines.get(k); r.by[e.work_date] = (r.by[e.work_date] || 0) + e.hours; }
    detail = `<div class="card"><div class="row"><div><h2 style="margin:0">${esc(sel.user?.name)}</h2><span class="muted">week ending ${nice(sel.week_ending)}</span></div><span class="spacer"></span><span class="badge submitted">Pending</span>${sel.late ? ' <span class="badge returned">Late</span>' : ''}</div>
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
    <td>${nice(s.week_ending)}</td><td class="r">${hrs(s.total_hours)}</td><td>${esc((s.submitted_at || '').slice(0, 10))}</td></tr>`).join('')}</table>`
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
    <p><label>Columns (one per line: Heading | source). Sources: date, week_ending, worker, project.code, project.name, project.patient, project.plan, project.contract, project.meta.KEY, customer, hours, amount, rate, start, end, notes, billable</label>
    <textarea name="columns" rows="6">Date | date\nPerson | worker\nProject | project.code\nHours | hours</textarea></p>
    <button class="btn pri" type="submit">Save template</button></form></details></div>` : ''}`;
}

/* --- Projects --- */
async function projectsView() {
  const [list, customers, approvers, users] = await Promise.all([api('/projects'), api('/customers'), api('/approvers'), api('/users')]);
  S.cache.pl = list;
  const F = FL('projects');
  const shown = list.filter(x => matchQ(F.q, [x.code, x.name, x.customer?.name]) && sameFlag(F.active, x.active) && sameFlag(F.billable, x.billable) && (!F.customer_id || x.customer_id === Number(F.customer_id)));
  const edit = S.cache.editProject; const p = edit ? list.find(x => x.id === edit) : null;
  const metaTxt = p?.meta ? Object.entries(p.meta).map(([k, v]) => k + '=' + v).join('\n') : '';
  return `<h1>Projects</h1><p class="sub">Only the company owner manages projects. For Consultorio 1500 each patient is a project named “Patient - Health plan - Contract” (if the name has no plan or contract, the plan_medico and numero_contrato fields under “Fields” are used).</p>
    <div class="card">${exportBar('projects', activeF('projects'))}${filterBar('projects', [{ k: 'q', label: 'Search', ph: 'Code, name or client' }, { k: 'customer_id', label: 'Client', type: 'select', opts: [['', 'All'], ...customers.map(c => [c.id, c.name])] }, { k: 'active', label: 'Status', type: 'select', opts: YN('Active', 'Inactive') }, { k: 'billable', label: 'Billable', type: 'select', opts: YN('Billable', 'Non-billable') }])}
    <table><tr><th>Code</th><th>Name</th><th>Client</th><th>Invoice detail</th><th class="r">Rate</th><th>Status</th><th></th></tr>
    ${shown.map(x => `<tr><td>${esc(x.code)}</td><td>${esc(x.name)}${(x.services || []).filter(s => s.active).length > 1 ? ' <span class="muted">· ' + (x.services || []).filter(s => s.active).length + ' ' + T('services') + '</span>' : ''}</td><td>${esc(x.customer?.name || '—')}</td>
    <td>${{ hours_total: 'Total only', by_day: 'By day', with_times: 'With from/to' }[x.invoice_detail]}</td><td class="r">${x.bill_rate ? money(x.bill_rate) : '—'}</td>
    <td>${activePill(x.active)} ${x.billable ? '' : pill('Non-billable', 'amber')}</td>
    <td class="r"><button class="btn ghost sm" data-act="edit-project" data-id="${x.id}">Edit</button></td></tr>`).join('')}</table></div>
    <div class="card"><h2>${p ? 'Edit ' + esc(p.code) : 'New project'}</h2><form data-form="project"><input type="hidden" name="id" value="${p?.id || ''}">
    <div class="grid"><div><label>Code</label><input name="code" required value="${esc(p?.code)}"></div><div><label>Name</label><input name="name" required value="${esc(p?.name)}"></div>
    <div><label>Client</label><select name="customer_id"><option value="">—</option>${customers.map(c => `<option value="${c.id}" ${p?.customer_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
    <div><label>Approver (optional)</label><select name="approver_id"><option value="">Use the person's approver</option>${approvers.map(a => `<option value="${a.id}" ${p?.approver_id === a.id ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div>
    <div><label>Invoice detail</label><select name="invoice_detail">${[['hours_total', 'Total hours only'], ['by_day', 'Hours by day'], ['with_times', 'With start and end time']].map(([v, t]) => `<option value="${v}" ${p?.invoice_detail === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
    <div><label>Default billing rate (services without their own)</label><input name="bill_rate" type="number" step="0.01" min="0" value="${esc(p?.bill_rate)}"></div>
    <div><label>Budgeted hours</label><input name="budget_hours" type="number" step="0.5" min="0" value="${esc(p?.budget_hours)}"></div>
    <div><label>Latitude (work site)</label><input name="geo_lat" type="number" step="0.000001" value="${esc(p?.geo_lat)}"></div>
    <div><label>Longitude</label><input name="geo_lng" type="number" step="0.000001" value="${esc(p?.geo_lng)}"></div>
    <div><label>Radius (m)</label><input name="geo_radius_m" type="number" min="50" max="5000" value="${esc(p?.geo_radius_m)}"></div></div>
    <p class="row"><label class="chk"><input type="checkbox" name="billable" ${p ? (p.billable ? 'checked' : '') : 'checked'}> Billable</label>
    <label class="chk"><input type="checkbox" name="active" ${p ? (p.active ? 'checked' : '') : 'checked'}> Active</label></p>
    <p><label>Fields (key=value, one per line)</label><textarea name="meta" rows="3">${esc(metaTxt)}</textarea></p>
    <p><label>Assigned people</label><span class="row">${users.filter(u => u.active).map(u => `<label class="chk"><input type="checkbox" data-multi="1" name="user_ids" value="${u.id}" ${p?.user_ids?.includes(u.id) ? 'checked' : ''}> ${esc(u.name)}</label>`).join('')}</span></p>
    <button class="btn pri" type="submit">Save</button> ${p ? '<button class="btn ghost" type="button" data-act="new-project">New</button>' : ''}</form></div>
    ${p ? servicesCard(p) : ''}`;
}

/* Services of a project: each one has its own rate, billable flag and way of entering hours. They are deactivated, never deleted. */
function servicesCard(p) {
  const sv = S.cache.editService; const s = sv ? (p.services || []).find(x => x.id === sv) : null;
  const modeName = { detail: 'Detail (from / to)', total: 'Total hours per day', both: 'Both (the person’s preference decides)' };
  return `<div class="card"><h2>Services of ${esc(p.code)}</h2>
    <p class="muted">The person picks the project and then the service. A service decides the rate, whether it is billed and how hours are entered. A project with one service picks it automatically. Changes only affect new entries.</p>
    <table><tr><th>Service</th><th class="r">Rate</th><th>Billable</th><th>Entry</th><th>Accountisi item</th><th>Status</th><th></th></tr>
    ${(p.services || []).map(x => `<tr><td><b>${esc(x.name)}</b></td><td class="r">${x.bill_rate !== null && x.bill_rate !== undefined ? money(x.bill_rate) : '<span class="muted">project rate</span>'}</td>
    <td>${x.billable ? pill('Billable', 'green') : pill('Non-billable', 'amber')}</td><td>${modeName[x.capture_mode]}</td><td>${esc(x.accountisi_item_id || '—')}</td><td>${activePill(x.active)}</td>
    <td class="r"><button class="btn ghost sm" data-act="edit-service" data-id="${x.id}">Edit</button> <button class="btn ghost sm" data-act="toggle-service" data-id="${x.id}" data-pid="${p.id}">${x.active ? 'Deactivate' : 'Activate'}</button></td></tr>`).join('')}</table>
    <h2 style="margin-top:16px">${s ? 'Edit service' : 'New service'}</h2>
    <form data-form="service"><input type="hidden" name="id" value="${s?.id || ''}"><input type="hidden" name="project_id" value="${p.id}">
    <div class="grid"><div><label>Name</label><input name="name" required maxlength="120" value="${esc(s?.name)}"></div>
    <div><label>Rate (empty = project rate)</label><input name="bill_rate" type="number" step="0.01" min="0" value="${esc(s?.bill_rate)}"></div>
    <div><label>How hours are entered</label><select name="capture_mode">${['both', 'detail', 'total'].map(m => `<option value="${m}" ${(s?.capture_mode || 'both') === m ? 'selected' : ''}>${modeName[m]}</option>`).join('')}</select></div>
    <div><label>Accountisi item id</label><input name="accountisi_item_id" maxlength="40" value="${esc(s?.accountisi_item_id)}"></div></div>
    <p class="row"><label class="chk"><input type="checkbox" name="billable" ${s ? (s.billable ? 'checked' : '') : 'checked'}> Billable</label></p>
    <button class="btn pri" type="submit">Save service</button> ${s ? '<button class="btn ghost" type="button" data-act="new-service">New service</button>' : ''}</form></div>`;
}

/* --- People & rates --- */
async function peopleView() {
  const [list, approvers, projects] = await Promise.all([api('/users'), api('/approvers'), api('/projects')]);
  const edit = S.cache.editUser; const u = edit ? list.find(x => x.id === edit) : null; const t = u?.terms || {};
  const F = FL('people');
  const shown = list.filter(x => matchQ(F.q, [x.name, x.email]) && sameFlag(F.active, x.active) && (!F.worker_type || x.worker_type === F.worker_type) && (!F.capture_mode || x.capture_mode === F.capture_mode));
  return `<h1>People</h1><p class="sub">Add people, set their role and entry preference, and configure each consultant's billing terms.</p>
    <div class="card">${exportBar('people', activeF('people'))}${filterBar('people', [{ k: 'q', label: 'Search by name or email', ph: 'Start typing' }, { k: 'worker_type', label: 'Worker type', type: 'select', opts: [['', 'All types'], ['consultant', 'Consultants'], ['employee', 'Employees']] }, { k: 'capture_mode', label: 'Entry preference', type: 'select', opts: [['', 'All'], ['from_to', 'From / to'], ['full_day', 'Total hours per day']] }, { k: 'active', label: 'Status', type: 'select', opts: YN('Active', 'Inactive') }])}
    <table><tr><th>Name</th><th>Worker type</th><th>Entry preference</th><th>Approver</th><th>Status</th><th></th></tr>
    ${shown.map(x => `<tr><td><b>${esc(x.name)}</b><div class="muted">${esc(x.email)}</div></td>
    <td>${x.worker_type === 'employee' ? 'Employee (' + (x.weekly_hours || 40) + ' h/week)' : 'Consultant'}${x.role === 'owner' ? ' · owner' : ''}</td>
    <td>${x.capture_mode === 'full_day' ? 'Total hours per day' : 'From / to'}</td><td>${esc(approvers.find(a => a.id === x.approver_id)?.name || '—')}</td>
    <td>${activePill(x.active)}</td><td class="r"><button class="btn ghost sm" data-act="edit-user" data-id="${x.id}">Edit</button></td></tr>`).join('')}</table></div>
    <div class="card"><h2>${u ? 'Edit ' + esc(u.name) : 'New person'}</h2><form data-form="user"><input type="hidden" name="id" value="${u?.id || ''}">
    <div class="grid"><div><label>Name</label><input name="name" required value="${esc(u?.name)}"></div><div><label>Email</label><input name="email" type="email" required value="${esc(u?.email)}"></div>
    <div><label>${u ? 'New password (optional)' : 'Password (10+ characters)'}</label><input name="password" type="password" minlength="10" ${u ? '' : 'required'} autocomplete="new-password"></div>
    <div><label>Worker type</label><select name="worker_type"><option value="consultant" ${u?.worker_type !== 'employee' ? 'selected' : ''}>Consultant (invoices)</option><option value="employee" ${u?.worker_type === 'employee' ? 'selected' : ''}>Employee (no invoices)</option></select></div>
    <div><label>Entry preference (used when a service allows both)</label><select name="capture_mode"><option value="from_to" ${u?.capture_mode !== 'full_day' ? 'selected' : ''}>From / to (detail)</option><option value="full_day" ${u?.capture_mode === 'full_day' ? 'selected' : ''}>Total hours per day</option></select></div>
    <div><label>Hours per week (employee)</label><input name="weekly_hours" type="number" step="0.5" value="${esc(u?.weekly_hours || '')}" placeholder="40"></div>
    <div><label>Approver</label><select name="approver_id"><option value="">—</option>${approvers.map(a => `<option value="${a.id}" ${u?.approver_id === a.id ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div>
    <div><label>Role</label><select name="role"><option value="member" ${u?.role !== 'owner' ? 'selected' : ''}>Member</option><option value="owner" ${u?.role === 'owner' ? 'selected' : ''}>Company owner</option></select></div></div>
    <h2 style="margin-top:14px">Consultant billing terms</h2><div class="grid">
    <div><label>Hourly rate</label><input name="t_rate" type="number" step="0.01" min="0" value="${esc(t.rate)}"></div>
    <div><label>Professional services withholding (%)</label><input name="t_retention_pct" type="number" step="0.01" min="0" max="100" value="${esc(t.retention_pct)}"></div>
    <div><label>IVU / sales tax (%)</label><input name="t_ivu_pct" type="number" step="0.01" min="0" max="100" value="${esc(t.ivu_pct)}"></div>
    <div><label>Payment terms (days)</label><input name="t_terms_days" type="number" min="0" max="365" value="${esc(t.terms_days ?? 30)}"></div>
    <div><label>Accountisi vendor id</label><input name="t_accountisi_vendor_id" value="${esc(t.accountisi_vendor_id)}"></div></div>
    <p class="row"><label class="chk"><input type="checkbox" name="t_ivu_agent" ${t.ivu_agent ? 'checked' : ''}> IVU withholding agent</label> <label class="chk"><input type="checkbox" name="active" ${u ? (u.active ? 'checked' : '') : 'checked'}> Active</label></p>
    <p><label>Assigned projects</label><span class="row">${projects.map(p => `<label class="chk"><input type="checkbox" data-multi="1" name="project_ids" value="${p.id}" ${u?.project_ids?.includes(p.id) ? 'checked' : ''}> ${esc(p.code)}</label>`).join('')}</span></p>
    <button class="btn pri" type="submit">Save</button> ${u ? '<button class="btn ghost" type="button" data-act="new-user">New person</button>' : ''}</form></div>`;
}

async function approversView() {
  const list = await api('/approvers');
  const F = FL('approvers');
  const shown = list.filter(a => matchQ(F.q, [a.name, a.email]) && sameFlag(F.active, a.active));
  return `<h1>Approvers</h1><p class="sub">They can be external and need no account: they receive an email with a secure link.</p>
    <div class="card">${exportBar('approvers', activeF('approvers'))}${filterBar('approvers', [{ k: 'q', label: 'Search', ph: 'Name or email' }, { k: 'active', label: 'Status', type: 'select', opts: YN('Active', 'Inactive') }])}
    <table><tr><th>Name</th><th>Email</th><th>Extra code</th><th>Status</th><th></th></tr>${shown.map(a => `<tr><td><b>${esc(a.name)}</b></td><td>${esc(a.email)}</td><td>${a.require_code ? pill('Yes', 'blue') : pill('No')}</td><td>${activePill(a.active)}</td>
    <td class="r"><button class="btn ghost sm" data-act="toggle-approver" data-id="${a.id}">${a.active ? 'Deactivate' : 'Activate'}</button></td></tr>`).join('')}</table></div>
    <div class="card"><h2>New approver</h2><form data-form="approver" class="grid"><div><label>Name</label><input name="name" required></div><div><label>Email</label><input name="email" type="email" required></div>
    <div><label class="chk"><input type="checkbox" name="require_code"> Always ask for a 6-digit code</label></div><div style="align-self:end"><button class="btn pri" type="submit">Add</button></div></form></div>`;
}

async function customersView() {
  const list = await api('/customers');
  const F = FL('customers');
  const shown = list.filter(c => matchQ(F.q, [c.name, c.email]) && sameFlag(F.active, c.active) && sameFlag(F.linked, c.accountisi_id));
  return `<h1>Clients</h1><p class="sub">For Consultorio 1500, each client is an office.</p>
    <div class="card">${exportBar('customers', activeF('customers'))}${filterBar('customers', [{ k: 'q', label: 'Search', ph: 'Name or email' }, { k: 'active', label: 'Status', type: 'select', opts: YN('Active', 'Inactive') }, { k: 'linked', label: 'Accountisi', type: 'select', opts: YN('Linked', 'Not linked') }])}<table><tr><th>Name</th><th>Email</th><th>Accountisi</th><th>Status</th></tr>${shown.map(c => `<tr><td><b>${esc(c.name)}</b></td><td>${esc(c.email || '')}</td><td>${c.accountisi_id ? pill('Linked', 'green') + ' <span class="muted">' + esc(c.accountisi_id) + '</span>' : pill('Not linked')}</td><td>${activePill(c.active)}</td></tr>`).join('')}</table></div>
    <div class="card"><h2>New client</h2><form data-form="customer" class="grid"><div><label>Name</label><input name="name" required></div><div><label>Email</label><input name="email" type="email"></div>
    <div style="align-self:end"><button class="btn pri" type="submit">Add</button></div></form></div>`;
}

/* --- Branding --- */
async function brandView() {
  const [b, hist] = await Promise.all([api('/brand'), api('/brand/history')]);
  return `<h1>Branding</h1><p class="sub">Applies to the web app, emails, PDF invoices and the mobile app, without touching code.</p>
    <div class="card"><form data-form="brand"><div class="grid"><div><label>Business name</label><input name="brand_name" value="${esc(b.name)}"></div>
    <div><label>Main color</label><input name="color_primary" type="color" value="${esc(b.color_primary)}"></div>
    <div><label>Accent color (buttons)</label><input name="color_accent" type="color" value="${esc(b.color_accent)}"></div>
    <div><label>Logo (png, jpg or webp; max 1 MB)</label><input name="logo" type="file" accept="image/*"></div>
    <div><label>Sender name</label><input name="email_from_name" value="${esc(b.email_from_name)}"></div>
    <div><label>Sender email</label><input name="email_from_address" type="email" value="${esc(b.email_from_address)}"></div></div>
    <p><label>Footer of emails and invoices</label><input name="email_footer" value="${esc(b.email_footer)}"></p>
    <p><label class="chk"><input type="checkbox" name="remove_logo"> Remove the logo</label></p>
    <div class="preview" id="bpreview" style="margin:12px 0"><div class="ph" style="background:${esc(b.color_primary)}">${b.logo_url ? `<img src="${esc(b.logo_url)}" style="max-height:34px">` : esc(b.name)}</div>
    <div class="pb"><p>This is how the header and button of an approval email look.</p><span class="btn" style="background:${esc(b.color_accent)}">Review and decide</span></div></div>
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
    ${row('Default language', 'Used for people who have not chosen one, and for emails to external people.', `<select name="locale"><option value="en" ${c.locale === 'en' ? 'selected' : ''}>English</option><option value="es" ${c.locale === 'es' ? 'selected' : ''}>Español</option></select>`)}</div>
    <div class="card"><h2>Timesheets</h2><p class="muted" style="margin-top:-6px">How and when people enter hours.</p>
    ${row('Weeks back a worker may fill in', 'Older weeks are closed.', `<input name="weeks_back" type="number" min="0" max="52" value="${r.weeks_back}">`)}
    ${row('Weekly submission deadline day', 'Timesheets submitted after it are flagged as late.', `<select name="deadline_dow">${days.map((d, i) => `<option value="${i}" ${r.deadline_dow === i ? 'selected' : ''}>${d}</option>`).join('')}</select>`)}
    ${row('Deadline time', 'Applies on the deadline day.', `<input name="deadline_time" type="time" value="${esc(r.deadline_time)}">`)}
    ${row('Hour increment', 'Applies to every hour cell.', `<select name="increment">${[0.25, 0.5, 1, 0.1].map(v => `<option ${Number(r.increment) === v ? 'selected' : ''}>${v}</option>`).join('')}</select>`)}
    ${row('Maximum hours per day', 'Days above this show a validation warning.', `<input name="max_day_hours" type="number" step="0.5" value="${r.max_day_hours}">`)}
    ${row('Flag late weeks to the approver', 'Shows a Late badge in the approval queue.', `<input type="checkbox" name="flag_late" ${r.flag_late ? 'checked' : ''}>`, 'chkrow')}</div>
    <div class="card"><h2>Approvals</h2><p class="muted" style="margin-top:-6px">Who signs off and how reminders work.</p>
    ${row('Days without a decision before the first reminder', 'The approver gets an email.', `<input name="approval_remind_days" type="number" min="1" value="${r.approval_remind_days}">`)}
    ${row('Repeat the reminder every (days)', 'Until a decision is made.', `<input name="remind_every_days" type="number" min="1" value="${r.remind_every_days}">`)}
    ${row('Approval link validity (days)', 'After that the link stops working.', `<input name="token_days" type="number" min="1" value="${r.token_days}">`)}
    ${row('Wait before notifying a change (minutes)', 'When a submitted timesheet is edited.', `<input name="change_notice_minutes" type="number" min="0" value="${r.change_notice_minutes}">`)}
    ${row('Timesheets above these hours need individual approval with a code', 'Leave empty for no limit.', `<input name="second_approval_hours" type="number" step="0.5" value="${esc(r.second_approval_hours)}" placeholder="no limit">`)}</div>
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
    <div><label>API URL (localhost today, the server tomorrow)</label><input name="base_url" value="${esc(i.base_url || 'http://localhost:5099')}" required></div>
    <div><label>Access</label><select name="auth_mode"><option value="login" ${i.auth_mode !== 'api_key' ? 'selected' : ''}>Service user</option><option value="api_key" ${i.auth_mode === 'api_key' ? 'selected' : ''}>Service credential (API key)</option></select></div>
    <div><label>Username (login)</label><input name="login" value="${esc(i.login)}"></div>
    <div><label>Password ${i.has_password ? '(saved)' : ''}</label><input name="password" type="password" autocomplete="new-password" placeholder="${i.has_password ? 'unchanged' : ''}"></div>
    <div><label>API key ${i.has_api_key ? '(saved)' : ''}</label><input name="api_key" type="password" autocomplete="new-password"></div>
    <div><label>Company id in Accountisi</label><input name="company_id" type="number" value="${esc(i.company_id)}"></div>
    <div><label>Service item (sales invoices)</label><input name="default_item_id" type="number" value="${esc(i.default_item_id)}"></div>
    <div><label>Income account (if no item)</label><input name="default_income_account_id" type="number" value="${esc(i.default_income_account_id)}"></div>
    <div><label>Service item (bills)</label><input name="default_service_item_id" type="number" value="${esc(i.default_service_item_id)}"></div>
    <div><label>Expense account (if no item)</label><input name="default_expense_account_id" type="number" value="${esc(i.default_expense_account_id)}"></div></div>
    <p><label class="chk"><input type="checkbox" name="enabled" ${i.enabled ? 'checked' : ''}> Integration enabled</label></p>
    <p class="row"><button class="btn pri" type="submit">Save</button><button class="btn ghost" type="button" data-act="acc-test">Test connection</button>
    <button class="btn ghost" type="button" data-act="acc-sync">Pull clients from Accountisi</button></p></form></div>
    <div class="card"><h2>Create a sales invoice in Accountisi</h2><p class="muted">Uses a client's approved hours that have not been invoiced yet. The project goes in the invoice comment.</p>
    <form data-form="sales" class="grid"><div><label>Client (linked)</label><select name="customer_id" required>${linked.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></div>
    <div><label>From</label><input name="from" type="date" value="${addDays(today(), -30)}"></div><div><label>To</label><input name="to" type="date" value="${today()}"></div>
    <div><label>Invoice</label><select name="mode"><option value="new">Create a new one</option><option value="append">Add to an open one</option></select></div>
    <div><label>Open invoice id (when adding)</label><input name="accountisi_invoice_id"></div>
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
function csPreviewHtml(d) {
  if (!d.projects.length) return '<div class="card"><p class="muted">There are no hours for this client in the period.</p></div>';
  return `<div class="card"><h2>${esc(d.customer)} · ${esc(d.from)} to ${esc(d.to)}</h2>${d.has_pending ? '<div class="msg warn">Includes hours not yet approved.</div>' : ''}
    ${d.projects.map(p => `<h2 style="margin-top:14px">${esc(p.code)} · ${esc(p.name)}</h2>
    <table><tr><th>Date</th><th>Person</th><th>Time</th><th class="r">Hours</th><th>Description</th>${d.show_amounts ? '<th class="r">Amount</th>' : ''}</tr>
    ${p.lines.map(l => `<tr><td>${esc(l.date)}</td><td>${esc(l.worker)}</td><td>${l.start ? esc(l.start) + '–' + esc(l.end) : 'Full day'}</td><td class="r">${hrs(l.hours)}</td>
      <td style="white-space:pre-wrap">${esc(l.description)}${l.approved ? '' : ' <span class="badge submitted">not approved</span>'}</td>${d.show_amounts ? `<td class="r">${money(l.amount)}</td>` : ''}</tr>`).join('')}
    <tr><th colspan="3">Subtotal ${esc(p.code)}</th><th class="r">${hrs(p.hours)}</th><th></th>${d.show_amounts ? `<th class="r">${money(p.amount)}</th>` : ''}</tr></table>`).join('')}
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
  logout, wk: el => { S.weekDate = addDays(S.weekDate, Number(el.dataset.n)); render(); },
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
  'edit-project': el => { S.cache.editProject = Number(el.dataset.id); S.cache.editService = null; render(); }, 'new-project': () => { S.cache.editProject = null; S.cache.editService = null; render(); },
  'edit-service': el => { S.cache.editService = Number(el.dataset.id); render(); }, 'new-service': () => { S.cache.editService = null; render(); },
  async 'toggle-service'(el) {
    const p = S.cache.pl.find(x => x.id === Number(el.dataset.pid)); const s = p.services.find(x => x.id === Number(el.dataset.id));
    await api('/services/' + s.id, { method: 'PUT', body: { active: !s.active } }); render();
  },
  lang: async el => { const to = el.dataset.to; S.lang = to; localStorage.setItem('lang', to); if (S.token) { S.me = await api('/me/locale', { method: 'PUT', body: { locale: to } }); } render(); },
  'edit-user': el => { S.cache.editUser = Number(el.dataset.id); render(); }, 'new-user': () => { S.cache.editUser = null; render(); },
  async 'toggle-approver'(el) { const a = (await api('/approvers')).find(x => x.id === Number(el.dataset.id)); await api('/approvers/' + a.id, { method: 'PUT', body: { name: a.name, email: a.email, require_code: a.require_code, active: !a.active } }); render(); },
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
const forms = {
  async login(f) {
    const o = formObj(f);
    const ts = f.querySelector('[name="cf-turnstile-response"]');
    try { const r = await api('/login/web', { method: 'POST', body: { ...o, device: 'web', ...(ts ? { turnstile_token: ts.value } : {}) } }); S.token = r.token; localStorage.setItem('tt', r.token); S.me = null; location.hash = '#/week'; render(); }
    catch (e) { loginView(e.message); }
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
  async service(f) {
    const o = formObj(f); const id = o.id; const pid = o.project_id; delete o.id; delete o.project_id;
    if (o.bill_rate === null) o.bill_rate = null; o.billable = !!o.billable; if (o.accountisi_item_id === null) o.accountisi_item_id = null;
    if (id) await api('/services/' + id, { method: 'PUT', body: o }); else await api('/projects/' + pid + '/services', { method: 'POST', body: o });
    S.cache.editService = null; flash('Service saved.'); render();
  },
  async project(f) {
    const o = formObj(f); const id = o.id; delete o.id; o.meta = parseMeta(o.meta); o.user_ids = o.user_ids || [];
    for (const k of ['customer_id', 'approver_id']) if (o[k] != null) o[k] = Number(o[k]);
    if (id) await api('/projects/' + id, { method: 'PUT', body: o }); else await api('/projects', { method: 'POST', body: o });
    S.cache.editProject = null; flash('Project saved.'); render();
  },
  async user(f) {
    const o = formObj(f); const id = o.id; delete o.id;
    const terms = { rate: o.t_rate, retention_pct: o.t_retention_pct, ivu_pct: o.t_ivu_pct, terms_days: o.t_terms_days, ivu_agent: o.t_ivu_agent, accountisi_vendor_id: o.t_accountisi_vendor_id };
    for (const k of Object.keys(o)) if (k.startsWith('t_')) delete o[k];
    o.project_ids = o.project_ids || []; if (o.approver_id != null) o.approver_id = Number(o.approver_id);
    if (o.worker_type === 'consultant') o.terms = terms; if (!o.password) delete o.password;
    if (id) await api('/users/' + id, { method: 'PUT', body: o }); else await api('/users', { method: 'POST', body: o });
    S.cache.editUser = null; flash('Person saved.'); render();
  },
  async cs(f) { S.cache.csPreview = await api('/client-timesheet/preview', { method: 'POST', body: csBody(f) }); S.cache.csEmail = undefined; render(); },
  async 'cs-email'(f) {
    const o = formObj(f); const body = { ...S.cache.cs }; delete body.user_id;
    if (!confirm(T('Send the timesheet to') + ' ' + o.email_to + '?')) return;
    S.cache.csEmail = o.email_to;
    const r = await api('/client-timesheet/email', { method: 'POST', body: { ...body, email_to: o.email_to, message: o.message || undefined } });
    flash('Timesheet sent to ' + r.sent_to + ' (' + hrs(r.total_hours) + ' h).'); render();
  },
  async approver(f) { await api('/approvers', { method: 'POST', body: formObj(f) }); flash('Approver added.'); render(); },
  async customer(f) { await api('/customers', { method: 'POST', body: formObj(f) }); flash('Client added.'); render(); },
  async brand(f) {
    const fd = new FormData(); const o = formObj(f);
    for (const [k, v] of Object.entries(o)) { if (v === null || v === undefined) continue; fd.append(k, v === true ? '1' : v === false ? '0' : v); }
    await api('/brand', { method: 'POST', body: fd }); S.me = null; flash('Branding saved.'); render();
  },
  async rules(f) {
    const o = formObj(f); const { name, invoice_prefix, flag_late, locale } = o; delete o.name; delete o.invoice_prefix; delete o.locale;
    const rules = {}; for (const [k, v] of Object.entries(o)) rules[k] = v;
    rules.flag_late = !!flag_late; rules.increment = Number(rules.increment);
    await api('/company', { method: 'PUT', body: { name, invoice_prefix, locale, rules } }); S.me = null; flash('Rules saved.'); render();
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
const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { if (e.message !== 'Session expired') { flash(e.message, 'err'); render(); } } };
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="entry-project"],[data-act-change="entry-service"]'); if (el) syncEntryForm(el.closest('form')); });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="clock-project"]'); if (el) syncClockForm(el.closest('form')); });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="cs-customer"]'); if (el) { const f = el.closest('form'); S.cache.cs = { customer_id: Number(el.value), from: f.elements.from.value, to: f.elements.to.value }; S.cache.csPreview = null; render(); } });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="wk-pick"]'); if (el) { S.weekDate = el.value; render(); } });
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="exp-refresh"]'); if (el) { const f = el.closest('form'); const o = formObj(f); S.cache.exp = { ...o, picked: ['customer_id', 'from', 'to', 'user_id'].includes(el.name) ? null : (o.project_ids || null) }; render(); } });
document.addEventListener('change', e => { const el = e.target.closest('[data-filter]'); if (el) { FL(el.dataset.filter)[el.name] = el.value; render(); } });
document.addEventListener('click', e => { const el = e.target.closest('[data-act]'); if (el && actions[el.dataset.act]) { e.preventDefault(); guard(actions[el.dataset.act])(el); } });
document.addEventListener('submit', e => { const f = e.target.closest('form[data-form]'); if (f && forms[f.dataset.form]) { e.preventDefault(); guard(forms[f.dataset.form])(f); } });
window.addEventListener('hashchange', render);
render();
})();
