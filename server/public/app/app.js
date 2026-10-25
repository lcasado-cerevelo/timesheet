/* Interfaz web de Tiempo. Sin compilación: corre en cualquier hosting con PHP. Habla con /api. */
(() => {
const $app = document.getElementById('app');
const S = { token: localStorage.getItem('tt'), me: null, flash: null, weekDate: today(), cache: {} };
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = n => '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const hrs = n => Number(n || 0).toFixed(2);

function today() { const d = new Date(); return fmt(d); }
function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function parse(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); }
function addDays(s, n) { const d = parse(s); d.setDate(d.getDate() + n); return fmt(d); }
function weekEnd(s) { const d = parse(s); const idx = (d.getDay() + 6) % 7; d.setDate(d.getDate() + (6 - idx)); return fmt(d); }
const DOW = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const dayLabel = s => DOW[parse(s).getDay()] + ' ' + s.slice(5);

async function api(path, o = {}) {
  const headers = { Accept: 'application/json' };
  if (S.token) headers.Authorization = 'Bearer ' + S.token;
  let body;
  if (o.body instanceof FormData) body = o.body;
  else if (o.body !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(o.body); }
  const r = await fetch('/api' + path, { method: o.method || 'GET', headers, body });
  if (o.blob) { if (!r.ok) throw new Error('No se pudo descargar'); return r; }
  const data = await r.json().catch(() => ({}));
  if (r.status === 401 && S.token) { logout(); throw new Error('Sesión vencida'); }
  if (!r.ok) {
    const first = data.errors ? Object.values(data.errors).flat()[0] : null;
    const e = new Error(first || data.message || 'Error ' + r.status); e.status = r.status; e.errors = data.errors; throw e;
  }
  return data;
}
/* Filtros de las tablas. Lo que se ve filtrado en pantalla es exactamente lo que se exporta. */
const fold = s => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const matchQ = (q, fields) => { const hay = fold(fields.join(' ')); return fold(q).split(/\s+/).filter(Boolean).every(w => hay.includes(w)); };
const FL = view => ((S.cache.f ??= {})[view] ??= {});
const activeF = view => Object.fromEntries(Object.entries(FL(view)).filter(([, v]) => v !== '' && v != null));
const flag = v => (v === '1' ? true : v === '0' ? false : null);
const sameFlag = (v, actual) => flag(v) === null || Boolean(actual) === flag(v);
const YN = (a, b) => [['', 'Todos'], ['1', a], ['0', b]];
function filterBar(view, defs) {
  const F = FL(view);
  const ctl = d => d.type === 'select'
    ? `<select data-filter="${view}" name="${d.k}">${d.opts.map(([v, l]) => `<option value="${esc(v)}" ${String(F[d.k] ?? '') === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`
    : `<input data-filter="${view}" name="${d.k}" type="${d.type || 'text'}" value="${esc(F[d.k] ?? '')}" placeholder="${esc(d.ph || '')}">`;
  const on = Object.keys(activeF(view)).length;
  return `<div class="fbar">${defs.map(d => `<div><label>${d.label}</label>${ctl(d)}</div>`).join('')}
    <button class="btn ghost sm" type="button" data-act="f-clear" data-view="${view}" ${on ? '' : 'disabled'}>Limpiar filtros</button></div>`;
}
/** Botones para exportar cualquier tabla a CSV, Excel o PDF (con el logo y los colores de la compañía en Excel y PDF). */
function exportBar(ds, q = {}) {
  const j = esc(JSON.stringify(q));
  return `<span class="xbar" title="Exportar esta tabla">${[['csv', 'CSV'], ['xlsx', 'Excel'], ['pdf', 'PDF']].map(([f, l]) => `<button class="btn ghost sm" type="button" data-act="tx" data-ds="${ds}" data-fmt="${f}" data-q="${j}">${l}</button>`).join('')}</span>`;
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

/* ---------- vistas ---------- */
const ROUTES = {
  week: { t: 'Mi semana', f: weekView },
  reports: { t: 'Reportes', f: reportsView },
  approvals: { t: 'Aprobaciones', owner: true, f: approvalsView },
  invoices: { t: 'Facturas', f: invoicesView },
  clientsheet: { t: 'Hoja para cliente', owner: true, f: clientSheetView },
  export: { t: 'Exportar', f: exportView },
  projects: { t: 'Proyectos', owner: true, f: projectsView },
  people: { t: 'Personas', owner: true, f: peopleView },
  approvers: { t: 'Aprobadores', owner: true, f: approversView },
  customers: { t: 'Clientes', owner: true, f: customersView },
  brand: { t: 'Marca', owner: true, f: brandView },
  rules: { t: 'Reglas', owner: true, f: rulesView },
  integrations: { t: 'Integración', owner: true, f: integrationsView },
};
const route = () => (location.hash.replace('#/', '') || 'week').split('?')[0];

async function render() {
  if (!S.token) return loginView();
  try {
    if (!S.me) { S.me = await api('/me'); }
    applyBrand(S.me.brand);
    const name = ROUTES[route()] && (!ROUTES[route()].owner || S.me.role === 'owner') ? route() : 'week';
    const view = ROUTES[name];
    const body = await view.f();
    const b = S.me.brand;
    const links = Object.entries(ROUTES).filter(([, v]) => !v.owner || S.me.role === 'owner')
      .map(([k, v]) => `<a href="#/${k}" class="${k === name ? 'on' : ''}">${v.t}</a>`).join('');
    const fl = S.flash ? `<div class="msg ${S.flash.kind}">${esc(S.flash.text)}</div>` : '';
    S.flash = null;
    $app.innerHTML = `<header class="top"><div class="brand">${b.logo_url ? `<img src="${esc(b.logo_url)}" alt="${esc(b.name)}">` : esc(b.name)}</div>
      <nav>${links}</nav><span class="who">${esc(S.me.name)}</span><button class="btn ghost sm" style="color:#fff;border-color:#fff;background:transparent" data-act="logout">Salir</button></header>
      <main>${fl}${body}</main>`;
  } catch (e) {
    if (S.token) { $app.innerHTML = `<main><div class="msg err">${esc(e.message)}</div></main>`; }
  }
}

function loginView(err) {
  $app.innerHTML = `<div class="card login"><h1>Entrar</h1>${err ? `<div class="msg err">${esc(err)}</div>` : ''}
    <form data-form="login"><p><label>Correo</label><input name="email" type="email" required autocomplete="username"></p>
    <p><label>Contraseña</label><input name="password" type="password" required autocomplete="current-password"></p>
    <button class="btn pri" type="submit">Entrar</button></form></div>`;
}

/* --- Mi semana --- */
async function weekView() {
  const end = weekEnd(S.weekDate), start = addDays(end, -6);
  const [sheet, ents, projects] = await Promise.all([
    api('/sheets/week?date=' + S.weekDate), api(`/entries?from=${start}&to=${end}`), api('/projects')]);
  S.cache.projects = projects;
  const days = Array.from({ length: 7 }, (_, i) => addDays(start, i));
  const mode = S.me.capture_mode;
  const running = ents.entries.find(e => e.running);
  const locked = sheet.locked;
  const byDay = d => ents.entries.filter(e => e.work_date === d);
  const rows = days.map(d => {
    const list = byDay(d); const tot = list.reduce((a, e) => a + e.hours, 0);
    const lines = list.map(e => `<tr><td>${dayLabel(d)}</td><td>${esc(e.project?.code)}</td>
      <td>${e.running ? '<b>corriendo desde ' + esc(e.start_time) + '</b>' : e.start_time ? esc(e.start_time) + '–' + esc(e.end_time) : 'Día completo'}</td>
      <td class="r">${hrs(e.hours)}</td><td>${esc(e.note)}</td>
      <td class="r">${locked ? '' : `<button class="btn ghost sm" data-act="edit" data-id="${e.id}">Editar</button> <button class="btn danger sm" data-act="del" data-id="${e.id}">Borrar</button>`}</td></tr>`).join('');
    return lines || `<tr><td>${dayLabel(d)}</td><td colspan="5" class="muted">—</td></tr>`;
  }).join('');
  S.cache.entries = ents.entries;
  const warn = sheet.warnings.map(w => `<div class="msg warn">${esc(w.message)}</div>`).join('');
  const ret = sheet.status === 'returned' && sheet.return_comment ? `<div class="msg err"><b>Devuelta:</b> ${esc(sheet.return_comment)}</div>` : '';
  const opts = projects.filter(p => p.active).map(p => `<option value="${p.id}">${esc(p.code)} · ${esc(p.name)}</option>`).join('');
  const dayOpts = days.map(d => `<option value="${d}" ${d === (days.includes(today()) ? today() : days[0]) ? 'selected' : ''}>${dayLabel(d)}</option>`).join('');
  const form = locked ? `<div class="msg ok">Esta hoja está aprobada y ya no se puede cambiar.</div>` : `
    <div class="card"><h2 id="eform-title">Agregar entrada</h2>
    <form data-form="entry"><input type="hidden" name="id">
    <div class="grid">
      <div><label>Día</label><select name="work_date">${dayOpts}</select></div>
      <div><label>Proyecto</label><select name="project_id" required>${opts}</select></div>
      ${mode === 'full_day' ? `<div><label>Horas del día</label><input name="hours" type="number" step="0.25" min="0.25" required></div>`
        : `<div><label>Desde</label><input name="start_time" type="time" required></div><div><label>Hasta</label><input name="end_time" type="time" required></div>`}
      <div style="grid-column:span 2"><label>Nota</label><input name="note" maxlength="1000"></div>
    </div>
    <p class="row"><button class="btn" type="submit">Guardar</button><button class="btn ghost" type="reset" data-act="cancel-edit">Limpiar</button></p></form></div>
    ${mode === 'from_to' ? `<div class="card"><h2>Reloj</h2>${running
      ? `<div class="running">Corriendo en <b>${esc(running.project?.code)}</b> desde ${esc(running.start_time)} · <button class="btn sm" data-act="clock-stop">Parar</button></div>`
      : `<form data-form="clock" class="row"><select name="project_id" style="max-width:320px">${opts}</select><button class="btn" type="submit">Iniciar reloj</button></form>`}</div>` : ''}`;
  return `<div class="card"><div class="row"><button class="btn ghost sm" data-act="wk" data-n="-7">‹</button>
    <h1 style="margin:0">Semana ${esc(start)} a ${esc(end)}</h1><button class="btn ghost sm" data-act="wk" data-n="7">›</button><span class="spacer"></span>${exportBar('week', { date: S.weekDate })}
    <span class="badge ${sheet.status}">${{ draft: 'Borrador', submitted: 'Enviada', approved: 'Aprobada', returned: 'Devuelta' }[sheet.status]}</span>
    ${sheet.late ? '<span class="badge returned">Tarde</span>' : ''}</div>
    <p class="muted">Total <b>${hrs(sheet.total_hours)} h</b> · versión ${sheet.version} · modo ${mode === 'full_day' ? 'día completo' : 'desde / hasta'}</p>${warn}${ret}
    <table><tr><th>Día</th><th>Proyecto</th><th>Horario</th><th class="r">Horas</th><th>Nota</th><th></th></tr>${rows}</table>
    <p class="row">${['draft', 'returned'].includes(sheet.status) ? `<button class="btn pri" data-act="submit" data-id="${sheet.id}">${sheet.status === 'returned' ? 'Corregida: enviar de nuevo' : 'Enviar a aprobación'}</button>` : ''}
    ${sheet.status === 'submitted' ? '<span class="muted">Esperando decisión. Si la cambias, el aprobador recibe un aviso de que cambió.</span>' : ''}</p></div>${form}`;
}

/* --- Reportes --- */
async function reportsView() {
  const q = S.cache.rep || { from: addDays(today(), -30), to: today(), group: 'project' };
  S.cache.rep = q;
  const projs = await api('/projects');
  const r = await api(`/reports/summary?from=${q.from}&to=${q.to}&group=${q.group}${q.project_id ? '&project_id=' + q.project_id : ''}`);
  return `<div class="card"><h1>Reportes</h1><form data-form="report" class="row">
    <div><label>Desde</label><input type="date" name="from" value="${q.from}"></div><div><label>Hasta</label><input type="date" name="to" value="${q.to}"></div>
    <div><label>Agrupar por</label><select name="group">${[['project', 'Proyecto'], ['day', 'Día'], ['week', 'Semana'], ['customer', 'Cliente']].map(([v, t]) => `<option value="${v}" ${q.group === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
    <div><label>Proyecto</label><select name="project_id"><option value="">Todos</option>${projs.map(p => `<option value="${p.id}" ${Number(q.project_id) === p.id ? 'selected' : ''}>${esc(p.code)} · ${esc(p.name)}</option>`).join('')}</select></div>
    <button class="btn pri" type="submit" style="align-self:end">Ver</button></form>
    <div style="margin-top:12px;text-align:right">${exportBar('report', { from: q.from, to: q.to, group: q.group, ...(q.project_id ? { project_id: q.project_id } : {}) })}</div>
    <table style="margin-top:6px"><tr><th></th><th class="r">Horas</th><th class="r">Facturables</th><th class="r">No facturables</th></tr>
    ${r.rows.map(x => `<tr><td>${esc(x.label)}</td><td class="r">${hrs(x.hours)}</td><td class="r">${hrs(x.billable_hours)}</td><td class="r">${hrs(x.non_billable_hours)}</td></tr>`).join('')}
    <tr><th>Total</th><th class="r">${hrs(r.total_hours)}</th><th class="r">${hrs(r.billable_hours)}</th><th></th></tr></table></div>`;
}

/* --- Aprobaciones (dueño) --- */
async function approvalsView() {
  const [all, people] = await Promise.all([api('/sheets-pending'), api('/users')]);
  const F = FL('pending');
  const rows = all.filter(s => matchQ(F.q, [s.user?.name]) && (!F.user_id || s.user_id === Number(F.user_id)) && (flag(F.late) !== true || s.late));
  return `<div class="card"><h1>Hojas pendientes</h1>${exportBar('pending', activeF('pending'))}${filterBar('pending', [{ k: 'q', label: 'Buscar', ph: 'persona' }, { k: 'user_id', label: 'Persona', type: 'select', opts: [['', 'Todas'], ...people.map(p => [p.id, p.name])] }, { k: 'late', label: 'Envío', type: 'select', opts: [['', 'Todas'], ['1', 'Solo tarde']] }])}${rows.length ? `<table><tr><th>Persona</th><th>Semana</th><th class="r">Horas</th><th>Enviada</th><th></th></tr>
    ${rows.map(s => `<tr><td>${esc(s.user?.name)} ${s.late ? '<span class="badge returned">Tarde</span>' : ''}${s.warnings.map(w => `<div class="muted">${esc(w.message)}</div>`).join('')}</td>
    <td>${esc(s.week_ending)}</td><td class="r">${hrs(s.total_hours)}</td><td>${esc((s.submitted_at || '').slice(0, 10))}</td>
    <td class="r"><button class="btn sm" data-act="approve" data-id="${s.id}" data-v="${s.version}">Aprobar</button>
    <button class="btn danger sm" data-act="return" data-id="${s.id}" data-v="${s.version}">Devolver</button></td></tr>`).join('')}</table>`
    : `<p class="muted">${all.length ? 'Ninguna hoja pendiente cumple el filtro.' : 'No hay hojas pendientes.'}</p>`}</div>`;
}

/* --- Facturas --- */
async function invoicesView() {
  const list = await api('/contractor-invoices');
  const owner = S.me.role === 'owner';
  const people = owner ? (await api('/users')).filter(u => u.worker_type === 'consultant') : [];
  const canInvoice = S.me.worker_type === 'consultant' || owner;
  const F = FL('invoices');
  const shown = list.filter(i => matchQ(F.q, [i.number, i.user?.name]) && (!F.status || i.status === F.status) && (!owner || !F.user_id || i.user_id === Number(F.user_id))
    && (!F.from || i.issued_on >= F.from) && (!F.to || i.issued_on <= F.to));
  return `<div class="card"><h1>Facturas de consultores</h1>${exportBar('invoices', activeF('invoices'))}${filterBar('invoices', [{ k: 'q', label: 'Buscar', ph: 'número o consultor' }, { k: 'status', label: 'Estado', type: 'select', opts: [['', 'Todos'], ['issued', 'Emitida'], ['sent', 'Enviada'], ['paid', 'Pagada']] }, ...(owner ? [{ k: 'user_id', label: 'Consultor', type: 'select', opts: [['', 'Todos'], ...people.map(p => [p.id, p.name])] }] : []), { k: 'from', label: 'Emitida desde', type: 'date' }, { k: 'to', label: 'hasta', type: 'date' }])}
    ${canInvoice ? `<form data-form="cinv" class="row"><div><label>Desde</label><input type="date" name="from" value="${addDays(today(), -30)}"></div>
    <div><label>Hasta</label><input type="date" name="to" value="${today()}"></div>
    ${owner ? `<div><label>Consultor</label><select name="user_id"><option value="">Yo</option>${people.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></div>` : ''}
    <button class="btn pri" type="submit" style="align-self:end">Generar factura de semanas aprobadas</button></form>` : '<p class="muted">Los empleados no generan factura.</p>'}
    <table style="margin-top:12px"><tr><th>Número</th><th>Consultor</th><th>Período</th><th class="r">Bruto</th><th class="r">Neto</th><th>Estado</th><th>Accountisi</th><th></th></tr>
    ${shown.map(i => `<tr><td>${esc(i.number)}</td><td>${esc(i.user?.name)}</td><td>${esc(i.period_from)} a ${esc(i.period_to)}</td><td class="r">${money(i.gross)}</td><td class="r">${money(i.net)}</td>
    <td>${esc(i.status)}</td><td>${esc(i.accountisi_status || '—')}</td>
    <td class="r"><button class="btn ghost sm" data-act="pdf" data-id="${i.id}">PDF</button>
    ${owner && i.accountisi_status !== 'draft' ? `<button class="btn ghost sm" data-act="push-bill" data-id="${i.id}">Enviar a Accountisi</button>` : ''}</td></tr>`).join('')}</table></div>`;
}

/* --- Exportar --- */
async function exportView() {
  const tpls = await api('/export-templates');
  const owner = S.me.role === 'owner';
  const people = owner ? await api('/users') : [];
  S.cache.tpls = tpls;
  return `<div class="card"><h1>Exportar</h1><form data-form="export" class="grid">
    <div><label>Plantilla</label><select name="template_id">${tpls.map(t => `<option value="${t.id}">${esc(t.name)} (${t.format})</option>`).join('')}</select></div>
    <div><label>Desde</label><input type="date" name="from" value="${addDays(today(), -30)}"></div><div><label>Hasta</label><input type="date" name="to" value="${today()}"></div>
    ${owner ? `<div><label>Persona</label><select name="user_id"><option value="">Todas</option>${people.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select></div>` : ''}
    <div style="align-self:end"><button class="btn pri" type="submit">Descargar</button></div></form>
    <p class="muted">Solo salen horas de hojas aprobadas. La plantilla “Consultorio 1500” produce las hojas “Atendidos &lt;año&gt;” que ese programa importa; cada paciente es un proyecto.</p></div>
    ${owner ? `<div class="card"><h2>Mis plantillas</h2>${tpls.map(t => `<p><b>${esc(t.name)}</b> ${t.is_system ? '<span class="badge">sistema</span>' : `<button class="btn danger sm" data-act="del-tpl" data-id="${t.id}">Borrar</button>`}
    <span class="muted">${t.config.columns.map(c => esc(c.header)).join(' · ')}</span></p>`).join('')}
    <details><summary>Crear plantilla</summary><form data-form="tpl"><div class="grid">
    <div><label>Nombre</label><input name="name" required></div><div><label>Formato</label><select name="format"><option>xlsx</option><option>csv</option></select></div>
    <div><label>Agrupar</label><select name="group"><option value="">No agrupar</option><option value="project,date">Por proyecto y día</option><option value="project">Por proyecto</option><option value="worker">Por persona</option><option value="date">Por día</option></select></div></div>
    <p><label>Columnas (una por línea: Encabezado | origen). Orígenes: date, week_ending, worker, project.code, project.name, project.meta.CLAVE, customer, hours, amount, rate, start, end, notes, billable</label>
    <textarea name="columns" rows="6">Fecha | date\nPersona | worker\nProyecto | project.code\nHoras | hours</textarea></p>
    <button class="btn pri" type="submit">Guardar plantilla</button></form></details></div>` : ''}`;
}

/* --- Proyectos --- */
async function projectsView() {
  const [list, customers, approvers, users] = await Promise.all([api('/projects'), api('/customers'), api('/approvers'), api('/users')]);
  S.cache.pl = list;
  const F = FL('projects');
  const shown = list.filter(x => matchQ(F.q, [x.code, x.name, x.customer?.name]) && sameFlag(F.active, x.active) && sameFlag(F.billable, x.billable) && (!F.customer_id || x.customer_id === Number(F.customer_id)));
  const edit = S.cache.editProject; const p = edit ? list.find(x => x.id === edit) : null;
  const metaTxt = p?.meta ? Object.entries(p.meta).map(([k, v]) => k + '=' + v).join('\n') : '';
  return `<div class="card"><h1>Proyectos</h1>${exportBar('projects', activeF('projects'))}${filterBar('projects', [{ k: 'q', label: 'Buscar', ph: 'código, nombre o cliente' }, { k: 'customer_id', label: 'Cliente', type: 'select', opts: [['', 'Todos'], ...customers.map(c => [c.id, c.name])] }, { k: 'active', label: 'Estado', type: 'select', opts: YN('Activos', 'Inactivos') }, { k: 'billable', label: 'Facturable', type: 'select', opts: YN('Facturables', 'No facturables') }])}<p class="muted">Solo el dueño de la compañía administra los proyectos. Para Consultorio 1500, cada paciente es un proyecto (plan_medico y numero_contrato en “Campos”).</p>
    <table><tr><th>Código</th><th>Nombre</th><th>Cliente</th><th>Detalle de factura</th><th class="r">Tarifa</th><th></th></tr>
    ${shown.map(x => `<tr><td>${esc(x.code)} ${x.active ? '' : '<span class="badge">inactivo</span>'}</td><td>${esc(x.name)}</td><td>${esc(x.customer?.name || '—')}</td>
    <td>${{ hours_total: 'Total', by_day: 'Por día', with_times: 'Con desde/hasta' }[x.invoice_detail]}</td><td class="r">${x.bill_rate ? money(x.bill_rate) : '—'}</td>
    <td class="r"><button class="btn ghost sm" data-act="edit-project" data-id="${x.id}">Editar</button></td></tr>`).join('')}</table></div>
    <div class="card"><h2>${p ? 'Editar ' + esc(p.code) : 'Nuevo proyecto'}</h2><form data-form="project"><input type="hidden" name="id" value="${p?.id || ''}">
    <div class="grid"><div><label>Código</label><input name="code" required value="${esc(p?.code)}"></div><div><label>Nombre</label><input name="name" required value="${esc(p?.name)}"></div>
    <div><label>Cliente</label><select name="customer_id"><option value="">—</option>${customers.map(c => `<option value="${c.id}" ${p?.customer_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
    <div><label>Aprobador (opcional)</label><select name="approver_id"><option value="">Usar el de la persona</option>${approvers.map(a => `<option value="${a.id}" ${p?.approver_id === a.id ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div>
    <div><label>Detalle de la factura</label><select name="invoice_detail">${[['hours_total', 'Solo horas totales'], ['by_day', 'Horas por día'], ['with_times', 'Con hora desde y hasta']].map(([v, t]) => `<option value="${v}" ${p?.invoice_detail === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
    <div><label>Tarifa de facturación</label><input name="bill_rate" type="number" step="0.01" min="0" value="${esc(p?.bill_rate)}"></div>
    <div><label>Horas presupuestadas</label><input name="budget_hours" type="number" step="0.5" min="0" value="${esc(p?.budget_hours)}"></div>
    <div><label>Latitud (lugar de trabajo)</label><input name="geo_lat" type="number" step="0.000001" value="${esc(p?.geo_lat)}"></div>
    <div><label>Longitud</label><input name="geo_lng" type="number" step="0.000001" value="${esc(p?.geo_lng)}"></div>
    <div><label>Radio (m)</label><input name="geo_radius_m" type="number" min="50" max="5000" value="${esc(p?.geo_radius_m)}"></div></div>
    <p class="row"><label><input type="checkbox" name="billable" ${p ? (p.billable ? 'checked' : '') : 'checked'}> Facturable</label>
    <label><input type="checkbox" name="active" ${p ? (p.active ? 'checked' : '') : 'checked'}> Activo</label></p>
    <p><label>Campos (clave=valor, una por línea)</label><textarea name="meta" rows="3">${esc(metaTxt)}</textarea></p>
    <p><label>Personas asignadas</label><span class="row">${users.filter(u => u.active).map(u => `<label><input type="checkbox" data-multi="1" name="user_ids" value="${u.id}" ${p?.user_ids?.includes(u.id) ? 'checked' : ''}> ${esc(u.name)}</label>`).join('')}</span></p>
    <button class="btn pri" type="submit">Guardar</button> ${p ? '<button class="btn ghost" type="button" data-act="new-project">Nuevo</button>' : ''}</form></div>`;
}

/* --- Personas --- */
async function peopleView() {
  const [list, approvers, projects] = await Promise.all([api('/users'), api('/approvers'), api('/projects')]);
  const edit = S.cache.editUser; const u = edit ? list.find(x => x.id === edit) : null; const t = u?.terms || {};
  const F = FL('people');
  const shown = list.filter(x => matchQ(F.q, [x.name, x.email]) && sameFlag(F.active, x.active) && (!F.worker_type || x.worker_type === F.worker_type) && (!F.capture_mode || x.capture_mode === F.capture_mode));
  return `<div class="card"><h1>Personas</h1>${exportBar('people', activeF('people'))}${filterBar('people', [{ k: 'q', label: 'Buscar', ph: 'nombre o correo' }, { k: 'worker_type', label: 'Tipo', type: 'select', opts: [['', 'Todos'], ['consultant', 'Consultores'], ['employee', 'Empleados']] }, { k: 'capture_mode', label: 'Modo de captura', type: 'select', opts: [['', 'Todos'], ['from_to', 'Desde / hasta'], ['full_day', 'Día completo']] }, { k: 'active', label: 'Estado', type: 'select', opts: YN('Activas', 'Inactivas') }])}<table><tr><th>Nombre</th><th>Tipo</th><th>Modo de captura</th><th>Aprobador</th><th></th></tr>
    ${shown.map(x => `<tr><td>${esc(x.name)}<div class="muted">${esc(x.email)}</div> ${x.active ? '' : '<span class="badge">inactivo</span>'}</td>
    <td>${x.worker_type === 'employee' ? 'Empleado (' + (x.weekly_hours || 40) + ' h/sem)' : 'Consultor'}${x.role === 'owner' ? ' · dueño' : ''}</td>
    <td>${x.capture_mode === 'full_day' ? 'Día completo' : 'Desde / hasta'}</td><td>${esc(approvers.find(a => a.id === x.approver_id)?.name || '—')}</td>
    <td class="r"><button class="btn ghost sm" data-act="edit-user" data-id="${x.id}">Editar</button></td></tr>`).join('')}</table></div>
    <div class="card"><h2>${u ? 'Editar ' + esc(u.name) : 'Nueva persona'}</h2><form data-form="user"><input type="hidden" name="id" value="${u?.id || ''}">
    <div class="grid"><div><label>Nombre</label><input name="name" required value="${esc(u?.name)}"></div><div><label>Correo</label><input name="email" type="email" required value="${esc(u?.email)}"></div>
    <div><label>${u ? 'Nueva contraseña (opcional)' : 'Contraseña (10+ caracteres)'}</label><input name="password" type="password" minlength="10" ${u ? '' : 'required'} autocomplete="new-password"></div>
    <div><label>Tipo</label><select name="worker_type"><option value="consultant" ${u?.worker_type !== 'employee' ? 'selected' : ''}>Consultor (factura)</option><option value="employee" ${u?.worker_type === 'employee' ? 'selected' : ''}>Empleado (no factura)</option></select></div>
    <div><label>Modo de captura</label><select name="capture_mode"><option value="from_to" ${u?.capture_mode !== 'full_day' ? 'selected' : ''}>Se exige desde / hasta</option><option value="full_day" ${u?.capture_mode === 'full_day' ? 'selected' : ''}>Se permite el día completo</option></select></div>
    <div><label>Horas por semana (empleado)</label><input name="weekly_hours" type="number" step="0.5" value="${esc(u?.weekly_hours || '')}" placeholder="40"></div>
    <div><label>Aprobador</label><select name="approver_id"><option value="">—</option>${approvers.map(a => `<option value="${a.id}" ${u?.approver_id === a.id ? 'selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div>
    <div><label>Rol</label><select name="role"><option value="member" ${u?.role !== 'owner' ? 'selected' : ''}>Miembro</option><option value="owner" ${u?.role === 'owner' ? 'selected' : ''}>Dueño de la compañía</option></select></div></div>
    <h2 style="margin-top:14px">Términos del consultor</h2><div class="grid">
    <div><label>Tarifa por hora</label><input name="t_rate" type="number" step="0.01" min="0" value="${esc(t.rate)}"></div>
    <div><label>Retención servicios prof. (%)</label><input name="t_retention_pct" type="number" step="0.01" min="0" max="100" value="${esc(t.retention_pct)}"></div>
    <div><label>IVU (%)</label><input name="t_ivu_pct" type="number" step="0.01" min="0" max="100" value="${esc(t.ivu_pct)}"></div>
    <div><label>Días de pago</label><input name="t_terms_days" type="number" min="0" max="365" value="${esc(t.terms_days ?? 30)}"></div>
    <div><label>Id de suplidor en Accountisi</label><input name="t_accountisi_vendor_id" value="${esc(t.accountisi_vendor_id)}"></div></div>
    <p><label><input type="checkbox" name="t_ivu_agent" ${t.ivu_agent ? 'checked' : ''}> Agente de retención del IVU</label> <label><input type="checkbox" name="active" ${u ? (u.active ? 'checked' : '') : 'checked'}> Activo</label></p>
    <p><label>Proyectos asignados</label><span class="row">${projects.map(p => `<label><input type="checkbox" data-multi="1" name="project_ids" value="${p.id}" ${u?.project_ids?.includes(p.id) ? 'checked' : ''}> ${esc(p.code)}</label>`).join('')}</span></p>
    <button class="btn pri" type="submit">Guardar</button> ${u ? '<button class="btn ghost" type="button" data-act="new-user">Nueva</button>' : ''}</form></div>`;
}

async function approversView() {
  const list = await api('/approvers');
  const F = FL('approvers');
  const shown = list.filter(a => matchQ(F.q, [a.name, a.email]) && sameFlag(F.active, a.active));
  return `<div class="card"><h1>Aprobadores</h1>${exportBar('approvers', activeF('approvers'))}${filterBar('approvers', [{ k: 'q', label: 'Buscar', ph: 'nombre o correo' }, { k: 'active', label: 'Estado', type: 'select', opts: YN('Activos', 'Inactivos') }])}<p class="muted">Pueden ser externos y no necesitan cuenta: reciben un email con un enlace seguro.</p>
    <table><tr><th>Nombre</th><th>Correo</th><th>Código extra</th><th></th></tr>${shown.map(a => `<tr><td>${esc(a.name)} ${a.active ? '' : '<span class="badge">inactivo</span>'}</td><td>${esc(a.email)}</td><td>${a.require_code ? 'Sí' : 'No'}</td>
    <td class="r"><button class="btn ghost sm" data-act="toggle-approver" data-id="${a.id}">${a.active ? 'Desactivar' : 'Activar'}</button></td></tr>`).join('')}</table></div>
    <div class="card"><h2>Nuevo aprobador</h2><form data-form="approver" class="grid"><div><label>Nombre</label><input name="name" required></div><div><label>Correo</label><input name="email" type="email" required></div>
    <div><label><input type="checkbox" name="require_code"> Pedir código de 6 dígitos siempre</label></div><div style="align-self:end"><button class="btn pri" type="submit">Agregar</button></div></form></div>`;
}

async function customersView() {
  const list = await api('/customers');
  const F = FL('customers');
  const shown = list.filter(c => matchQ(F.q, [c.name, c.email]) && sameFlag(F.active, c.active) && sameFlag(F.linked, c.accountisi_id));
  return `<div class="card"><h1>Clientes</h1>${exportBar('customers', activeF('customers'))}${filterBar('customers', [{ k: 'q', label: 'Buscar', ph: 'nombre o correo' }, { k: 'active', label: 'Estado', type: 'select', opts: YN('Activos', 'Inactivos') }, { k: 'linked', label: 'Accountisi', type: 'select', opts: YN('Vinculados', 'Sin vincular') }])}<table><tr><th>Nombre</th><th>Correo</th><th>Accountisi</th></tr>${shown.map(c => `<tr><td>${esc(c.name)}</td><td>${esc(c.email || '')}</td><td>${esc(c.accountisi_id || '—')}</td></tr>`).join('')}</table></div>
    <div class="card"><h2>Nuevo cliente</h2><form data-form="customer" class="grid"><div><label>Nombre</label><input name="name" required></div><div><label>Correo</label><input name="email" type="email"></div>
    <div style="align-self:end"><button class="btn pri" type="submit">Agregar</button></div></form></div>`;
}

/* --- Marca --- */
async function brandView() {
  const [b, hist] = await Promise.all([api('/brand'), api('/brand/history')]);
  return `<div class="card"><h1>Marca</h1><p class="muted">Se aplica a la web, los emails, las facturas en PDF y la aplicación móvil, sin tocar código.</p>
    <form data-form="brand"><div class="grid"><div><label>Nombre comercial</label><input name="brand_name" value="${esc(b.name)}"></div>
    <div><label>Color principal</label><input name="color_primary" type="color" value="${esc(b.color_primary)}"></div>
    <div><label>Color de acento (botones)</label><input name="color_accent" type="color" value="${esc(b.color_accent)}"></div>
    <div><label>Logo (png, jpg o webp; máx. 1 MB)</label><input name="logo" type="file" accept="image/*"></div>
    <div><label>Nombre del remitente</label><input name="email_from_name" value="${esc(b.email_from_name)}"></div>
    <div><label>Correo del remitente</label><input name="email_from_address" type="email" value="${esc(b.email_from_address)}"></div></div>
    <p><label>Pie de los emails y facturas</label><input name="email_footer" value="${esc(b.email_footer)}"></p>
    <p><label><input type="checkbox" name="remove_logo"> Quitar el logo</label></p>
    <div class="preview" id="bpreview" style="margin:12px 0"><div class="ph" style="background:${esc(b.color_primary)}">${b.logo_url ? `<img src="${esc(b.logo_url)}" style="max-height:34px">` : esc(b.name)}</div>
    <div class="pb"><p>Así se ve el encabezado y el botón de un email de aprobación.</p><span class="btn" style="background:${esc(b.color_accent)}">Revisar y decidir</span></div></div>
    <button class="btn pri" type="submit">Guardar marca</button></form></div>
    <div class="card"><h2>Historial</h2>${hist.length ? `<table>${hist.map(h => `<tr><td>${esc(String(h.created_at).slice(0, 16).replace('T', ' '))}</td><td>${esc(h.before.brand_name || '—')} ${esc(h.before.color_primary)} → ${esc(h.after.brand_name || '—')} ${esc(h.after.color_primary)}</td>
    <td class="r"><button class="btn ghost sm" data-act="revert-brand" data-id="${h.id}">Volver a la anterior</button></td></tr>`).join('')}</table>` : '<p class="muted">Sin cambios todavía.</p>'}</div>`;
}

/* --- Reglas --- */
async function rulesView() {
  const c = await api('/company'); const r = c.rules;
  const days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
  return `<div class="card"><h1>Reglas</h1><form data-form="rules"><div class="grid">
    <div><label>Nombre de la compañía</label><input name="name" value="${esc(c.name)}"></div>
    <div><label>Prefijo de factura</label><input name="invoice_prefix" value="${esc(c.invoice_prefix)}"></div>
    <div><label>Semanas hacia atrás que se pueden llenar</label><input name="weeks_back" type="number" min="0" max="52" value="${r.weeks_back}"></div>
    <div><label>Día límite de envío</label><select name="deadline_dow">${days.map((d, i) => `<option value="${i}" ${r.deadline_dow === i ? 'selected' : ''}>${d}</option>`).join('')}</select></div>
    <div><label>Hora límite</label><input name="deadline_time" type="time" value="${esc(r.deadline_time)}"></div>
    <div><label>Incremento de hora</label><select name="increment">${[0.25, 0.5, 1, 0.1].map(v => `<option ${Number(r.increment) === v ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
    <div><label>Máximo de horas por día</label><input name="max_day_hours" type="number" step="0.5" value="${r.max_day_hours}"></div>
    <div><label>Días sin decisión antes del recordatorio</label><input name="approval_remind_days" type="number" min="1" value="${r.approval_remind_days}"></div>
    <div><label>Repetir recordatorio cada (días)</label><input name="remind_every_days" type="number" min="1" value="${r.remind_every_days}"></div>
    <div><label>Vigencia del enlace (días)</label><input name="token_days" type="number" min="1" value="${r.token_days}"></div>
    <div><label>Espera antes de avisar un cambio (min)</label><input name="change_notice_minutes" type="number" min="0" value="${r.change_notice_minutes}"></div>
    <div><label>Hojas sobre estas horas: individual y con código</label><input name="second_approval_hours" type="number" step="0.5" value="${esc(r.second_approval_hours)}" placeholder="sin límite"></div></div>
    <p><label><input type="checkbox" name="flag_late" ${r.flag_late ? 'checked' : ''}> Marcar como “tarde” las hojas enviadas después del límite</label></p>
    <button class="btn pri" type="submit">Guardar reglas</button></form></div>`;
}

/* --- Integración --- */
async function integrationsView() {
  const [i, customers] = await Promise.all([api('/integrations/accountisi'), api('/customers')]);
  const linked = customers.filter(c => c.accountisi_id);
  const pv = S.cache.salesPreview;
  return `<div class="card"><h1>Integración con Accountisi</h1><p class="muted">Opcional. Si no la usas, el resto del sistema funciona igual.
    ${i.last_ok_at ? 'Última conexión correcta: ' + esc(String(i.last_ok_at).slice(0, 16).replace('T', ' ')) : ''} ${i.last_error ? '<span style="color:var(--danger)">' + esc(i.last_error) + '</span>' : ''}</p>
    <form data-form="accountisi"><div class="grid">
    <div><label>URL de la API (localhost hoy, el servidor mañana)</label><input name="base_url" value="${esc(i.base_url || 'http://localhost:5099')}" required></div>
    <div><label>Acceso</label><select name="auth_mode"><option value="login" ${i.auth_mode !== 'api_key' ? 'selected' : ''}>Usuario de servicio</option><option value="api_key" ${i.auth_mode === 'api_key' ? 'selected' : ''}>Credencial de servicio (API key)</option></select></div>
    <div><label>Usuario (login)</label><input name="login" value="${esc(i.login)}"></div>
    <div><label>Contraseña ${i.has_password ? '(guardada)' : ''}</label><input name="password" type="password" autocomplete="new-password" placeholder="${i.has_password ? 'sin cambios' : ''}"></div>
    <div><label>API key ${i.has_api_key ? '(guardada)' : ''}</label><input name="api_key" type="password" autocomplete="new-password"></div>
    <div><label>Id de empresa en Accountisi</label><input name="company_id" type="number" value="${esc(i.company_id)}"></div>
    <div><label>Artículo de servicio (facturas de venta)</label><input name="default_item_id" type="number" value="${esc(i.default_item_id)}"></div>
    <div><label>Cuenta de ingreso (si no hay artículo)</label><input name="default_income_account_id" type="number" value="${esc(i.default_income_account_id)}"></div>
    <div><label>Artículo de servicio (cuentas por pagar)</label><input name="default_service_item_id" type="number" value="${esc(i.default_service_item_id)}"></div>
    <div><label>Cuenta de gasto (si no hay artículo)</label><input name="default_expense_account_id" type="number" value="${esc(i.default_expense_account_id)}"></div></div>
    <p><label><input type="checkbox" name="enabled" ${i.enabled ? 'checked' : ''}> Integración activada</label></p>
    <p class="row"><button class="btn pri" type="submit">Guardar</button><button class="btn ghost" type="button" data-act="acc-test">Probar conexión</button>
    <button class="btn ghost" type="button" data-act="acc-sync">Traer clientes de Accountisi</button></p></form></div>
    <div class="card"><h2>Crear factura de venta en Accountisi</h2><p class="muted">Usa las horas aprobadas y aún no facturadas de un cliente. El proyecto va en el comentario de la factura.</p>
    <form data-form="sales" class="grid"><div><label>Cliente (vinculado)</label><select name="customer_id" required>${linked.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select></div>
    <div><label>Desde</label><input name="from" type="date" value="${addDays(today(), -30)}"></div><div><label>Hasta</label><input name="to" type="date" value="${today()}"></div>
    <div><label>Factura</label><select name="mode"><option value="new">Crear una nueva</option><option value="append">Añadir a una abierta</option></select></div>
    <div><label>Id de la factura abierta (si añade)</label><input name="accountisi_invoice_id"></div>
    <div style="grid-column:span 2"><label>Comentario adicional</label><input name="comment" maxlength="300"></div>
    <div style="align-self:end" class="row"><button class="btn ghost" type="button" data-act="sales-preview">Vista previa</button><button class="btn pri" type="submit">Crear en Accountisi</button></div></form>
    ${pv ? `<div style="margin-top:12px"><p><b>${hrs(pv.total_hours)} h</b> · ${money(pv.amount)} · ${esc(pv.comment)}</p>${pv.projects_without_rate.length ? `<div class="msg warn">Falta tarifa en: ${esc(pv.projects_without_rate.join(', '))}</div>` : ''}
    <table>${pv.lines.map(l => `<tr><td>${esc(l.description)}</td><td class="r">${hrs(l.quantity)} h</td><td class="r">${money(l.unit_price)}</td></tr>`).join('')}</table></div>` : ''}</div>`;
}


/* --- Hoja de tiempo para el cliente (dueño) --- */
async function clientSheetView() {
  const [customers, projects, users] = await Promise.all([api('/customers'), api('/projects'), api('/users')]);
  const q = S.cache.cs || { customer_id: customers[0]?.id, from: addDays(today(), -30), to: today() };
  const pv = S.cache.csPreview;
  const cust = customers.find(c => c.id === Number(q.customer_id));
  const ofCustomer = projects.filter(p => p.customer_id === Number(q.customer_id));
  return `<div class="card"><h1>Hoja de tiempo para el cliente</h1>
    <p class="muted">Las horas de un cliente en un período, con la descripción de lo trabajado. Por omisión sale solo lo aprobado y facturable, sin tarifas.</p>
    <form data-form="cs"><div class="grid">
    <div><label>Cliente</label><select name="customer_id" data-act-change="cs-customer">${customers.map(c => `<option value="${c.id}" ${c.id === Number(q.customer_id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
    <div><label>Desde</label><input name="from" type="date" value="${esc(q.from)}"></div><div><label>Hasta</label><input name="to" type="date" value="${esc(q.to)}"></div>
    <div><label>Persona</label><select name="user_id"><option value="">Todas</option>${users.map(u => `<option value="${u.id}" ${Number(q.user_id) === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></div></div>
    <p><label>Proyectos del cliente (sin marcar = todos)</label><span class="row">${ofCustomer.map(p => `<label><input type="checkbox" data-multi="1" name="project_ids" value="${p.id}" ${(q.project_ids || []).includes(p.id) ? 'checked' : ''}> ${esc(p.code)} · ${esc(p.name)}</label>`).join('') || '<span class="muted">Este cliente no tiene proyectos.</span>'}</span></p>
    <p class="row"><label><input type="checkbox" name="include_pending" ${q.include_pending ? 'checked' : ''}> Incluir horas aún sin aprobar (salen marcadas)</label>
    <label><input type="checkbox" name="include_non_billable" ${q.include_non_billable ? 'checked' : ''}> Incluir no facturables</label>
    <label><input type="checkbox" name="show_amounts" ${q.show_amounts ? 'checked' : ''}> Mostrar importes</label></p>
    <p class="row"><button class="btn pri" type="submit">Vista previa</button>
    <button class="btn ghost" type="button" data-act="cs-pdf">Descargar PDF</button>
    <button class="btn ghost" type="button" data-act="cs-xlsx">Descargar Excel</button>
    <button class="btn ghost" type="button" data-act="cs-csv">Descargar CSV</button></p></form></div>
    ${pv ? csPreviewHtml(pv) + `<div class="card"><h2>Enviar por email</h2><p class="muted">Se envía el PDF solo cuando tú lo pides aquí.</p>
      <form data-form="cs-email"><div class="grid"><div><label>Correo del cliente</label><input name="email_to" type="email" value="${esc(S.cache.csEmail ?? cust?.email ?? '')}" required></div>
      <div style="grid-column:span 2"><label>Mensaje (opcional)</label><input name="message" maxlength="1000"></div></div>
      <p><button class="btn pri" type="submit" ${pv.entry_count ? '' : 'disabled'}>Enviar la hoja por email</button></p></form></div>` : ''}`;
}
function csPreviewHtml(d) {
  if (!d.projects.length) return '<div class="card"><p class="muted">No hay horas para este cliente en el período.</p></div>';
  return `<div class="card"><h2>${esc(d.customer)} · ${esc(d.from)} a ${esc(d.to)}</h2>${d.has_pending ? '<div class="msg warn">Incluye horas aún sin aprobar.</div>' : ''}
    ${d.projects.map(p => `<h2 style="margin-top:14px">${esc(p.code)} · ${esc(p.name)}</h2>
    <table><tr><th>Fecha</th><th>Persona</th><th>Horario</th><th class="r">Horas</th><th>Descripción</th>${d.show_amounts ? '<th class="r">Importe</th>' : ''}</tr>
    ${p.lines.map(l => `<tr><td>${esc(l.date)}</td><td>${esc(l.worker)}</td><td>${l.start ? esc(l.start) + '–' + esc(l.end) : 'Día completo'}</td><td class="r">${hrs(l.hours)}</td>
      <td style="white-space:pre-wrap">${esc(l.description)}${l.approved ? '' : ' <span class="badge submitted">sin aprobar</span>'}</td>${d.show_amounts ? `<td class="r">${money(l.amount)}</td>` : ''}</tr>`).join('')}
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

/* ---------- acciones ---------- */
function parseMeta(txt) { const o = {}; (txt || '').split('\n').forEach(l => { const i = l.indexOf('='); if (i > 0) o[l.slice(0, i).trim()] = l.slice(i + 1).trim(); }); return o; }
const actions = {
  logout, wk: el => { S.weekDate = addDays(S.weekDate, Number(el.dataset.n)); render(); },
  'cancel-edit': () => { setTimeout(() => { const t = document.getElementById('eform-title'); if (t) t.textContent = 'Agregar entrada'; const f = document.querySelector('form[data-form=entry]'); if (f) f.elements.id.value = ''; }); },
  async edit(el) {
    const e = S.cache.entries.find(x => x.id === Number(el.dataset.id)); const f = document.querySelector('form[data-form=entry]'); if (!e || !f) return;
    f.elements.id.value = e.id; f.elements.work_date.value = e.work_date; f.elements.project_id.value = e.project_id; f.elements.note.value = e.note || '';
    if (f.elements.hours) f.elements.hours.value = e.hours; if (f.elements.start_time) { f.elements.start_time.value = e.start_time || ''; f.elements.end_time.value = e.end_time || ''; }
    document.getElementById('eform-title').textContent = 'Editar entrada'; f.scrollIntoView({ behavior: 'smooth' });
  },
  async del(el) { if (!confirm('¿Borrar esta entrada?')) return; await api('/entries/' + el.dataset.id, { method: 'DELETE' }); flash('Entrada borrada.'); render(); },
  async submit(el) { await api(`/sheets/${el.dataset.id}/submit`, { method: 'POST' }); flash('Hoja enviada a aprobación.'); render(); },
  async 'clock-stop'() { await api('/clock/stop', { method: 'POST', body: {} }); flash('Reloj detenido.'); render(); },
  async approve(el) { await api(`/sheets/${el.dataset.id}/approve`, { method: 'POST', body: { version: Number(el.dataset.v) } }); flash('Hoja aprobada.'); render(); },
  async return(el) { const c = prompt('¿Qué hay que corregir? (obligatorio)'); if (!c) return; await api(`/sheets/${el.dataset.id}/return`, { method: 'POST', body: { comment: c, version: Number(el.dataset.v) } }); flash('Hoja devuelta.'); render(); },
  pdf: el => download(`/contractor-invoices/${el.dataset.id}/pdf`, {}, 'factura.pdf'),
  async 'push-bill'(el) { const r = await api(`/contractor-invoices/${el.dataset.id}/push-accountisi`, { method: 'POST' }); flash(r.accountisi_status === 'draft' ? 'Creada en Accountisi como borrador.' : 'Estado en Accountisi: ' + r.accountisi_status, r.accountisi_status === 'draft' ? 'ok' : 'warn'); render(); },
  async 'del-tpl'(el) { if (!confirm('¿Borrar la plantilla?')) return; await api('/export-templates/' + el.dataset.id, { method: 'DELETE' }); render(); },
  'edit-project': el => { S.cache.editProject = Number(el.dataset.id); render(); }, 'new-project': () => { S.cache.editProject = null; render(); },
  'edit-user': el => { S.cache.editUser = Number(el.dataset.id); render(); }, 'new-user': () => { S.cache.editUser = null; render(); },
  async 'toggle-approver'(el) { const a = (await api('/approvers')).find(x => x.id === Number(el.dataset.id)); await api('/approvers/' + a.id, { method: 'PUT', body: { name: a.name, email: a.email, require_code: a.require_code, active: !a.active } }); render(); },
  async 'revert-brand'(el) { await api(`/brand/history/${el.dataset.id}/revert`, { method: 'POST' }); S.me = null; flash('Marca restaurada.'); render(); },
  async 'acc-test'() { try { await api('/integrations/accountisi/test', { method: 'POST' }); flash('Conexión correcta con Accountisi.'); } catch (e) { flash(e.message, 'err'); } render(); },
  async 'acc-sync'() { const r = await api('/integrations/accountisi/sync-customers', { method: 'POST' }); flash(`Clientes vinculados: ${r.linked}, creados: ${r.created}.`); render(); },
  'f-clear'(el) { (S.cache.f ??= {})[el.dataset.view] = {}; render(); },
  async tx(el) {
    const q = JSON.parse(el.dataset.q || '{}'); const fmtName = { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' }[el.dataset.fmt];
    await download(`/tables/${el.dataset.ds}/export`, { method: 'POST', body: { ...q, format: el.dataset.fmt } }, 'tabla.' + el.dataset.fmt);
    flash(fmtName + ' generado.'); render();
  },
  async 'cs-csv'() { const b = csBody(document.querySelector('form[data-form=cs]')); await download('/client-timesheet/download', { method: 'POST', body: { ...b, format: 'csv' } }, 'hoja-de-tiempo.csv'); flash('CSV generado.'); render(); },
  async 'cs-pdf'() { const b = csBody(document.querySelector('form[data-form=cs]')); await download('/client-timesheet/download', { method: 'POST', body: { ...b, format: 'pdf' } }, 'hoja-de-tiempo.pdf'); flash('PDF generado.'); render(); },
  async 'cs-xlsx'() { const b = csBody(document.querySelector('form[data-form=cs]')); await download('/client-timesheet/download', { method: 'POST', body: { ...b, format: 'xlsx' } }, 'hoja-de-tiempo.xlsx'); flash('Excel generado.'); render(); },
  async 'sales-preview'() { const f = document.querySelector('form[data-form=sales]'); const o = formObj(f); try { S.cache.salesPreview = await api('/integrations/accountisi/sales-preview', { method: 'POST', body: o }); } catch (e) { flash(e.message, 'err'); } render(); },
};
const forms = {
  async login(f) {
    const o = formObj(f);
    try { const r = await api('/login', { method: 'POST', body: { ...o, device: 'web' } }); S.token = r.token; localStorage.setItem('tt', r.token); S.me = null; location.hash = '#/week'; render(); }
    catch (e) { loginView(e.message); }
  },
  async entry(f) {
    const o = formObj(f); const id = o.id; delete o.id;
    if (id) await api('/entries/' + id, { method: 'PUT', body: o }); else await api('/entries', { method: 'POST', body: o });
    flash(id ? 'Entrada actualizada.' : 'Entrada guardada.'); render();
  },
  async clock(f) { await api('/clock/start', { method: 'POST', body: formObj(f) }); flash('Reloj iniciado.'); render(); },
  report(f) { S.cache.rep = formObj(f); render(); },
  async cinv(f) { const o = formObj(f); if (!o.user_id) delete o.user_id; const r = await api('/contractor-invoices', { method: 'POST', body: o }); flash('Factura ' + r.number + ' generada y enviada por email.'); render(); },
  async export(f) {
    const o = formObj(f); const tpl = S.cache.tpls.find(t => t.id === Number(o.template_id)); if (!o.user_id) delete o.user_id;
    const r = await download('/export', { method: 'POST', body: o }, 'exportacion.' + tpl.format); flash('Archivo generado (' + r.rows + ' filas).'); render();
  },
  async tpl(f) {
    const o = formObj(f);
    const columns = o.columns.split('\n').map(l => l.split('|').map(s => s.trim())).filter(p => p[0] && p[1]).map(([header, source]) => ({ header, source, ...(source === 'date' ? { format: 'date' } : {}) }));
    await api('/export-templates', { method: 'POST', body: { name: o.name, format: o.format, config: { sheet_name: o.name.slice(0, 30), group_by: o.group ? o.group.split(',') : [], filters: { status: 'approved' }, columns } } });
    flash('Plantilla creada.'); render();
  },
  async project(f) {
    const o = formObj(f); const id = o.id; delete o.id; o.meta = parseMeta(o.meta); o.user_ids = o.user_ids || [];
    for (const k of ['customer_id', 'approver_id']) if (o[k] != null) o[k] = Number(o[k]);
    if (id) await api('/projects/' + id, { method: 'PUT', body: o }); else await api('/projects', { method: 'POST', body: o });
    S.cache.editProject = null; flash('Proyecto guardado.'); render();
  },
  async user(f) {
    const o = formObj(f); const id = o.id; delete o.id;
    const terms = { rate: o.t_rate, retention_pct: o.t_retention_pct, ivu_pct: o.t_ivu_pct, terms_days: o.t_terms_days, ivu_agent: o.t_ivu_agent, accountisi_vendor_id: o.t_accountisi_vendor_id };
    for (const k of Object.keys(o)) if (k.startsWith('t_')) delete o[k];
    o.project_ids = o.project_ids || []; if (o.approver_id != null) o.approver_id = Number(o.approver_id);
    if (o.worker_type === 'consultant') o.terms = terms; if (!o.password) delete o.password;
    if (id) await api('/users/' + id, { method: 'PUT', body: o }); else await api('/users', { method: 'POST', body: o });
    S.cache.editUser = null; flash('Persona guardada.'); render();
  },
  async cs(f) { S.cache.csPreview = await api('/client-timesheet/preview', { method: 'POST', body: csBody(f) }); S.cache.csEmail = undefined; render(); },
  async 'cs-email'(f) {
    const o = formObj(f); const body = { ...S.cache.cs }; delete body.user_id;
    if (!confirm('¿Enviar la hoja de tiempo a ' + o.email_to + '?')) return;
    S.cache.csEmail = o.email_to;
    const r = await api('/client-timesheet/email', { method: 'POST', body: { ...body, email_to: o.email_to, message: o.message || undefined } });
    flash('Hoja enviada a ' + r.sent_to + ' (' + hrs(r.total_hours) + ' h).'); render();
  },
  async approver(f) { await api('/approvers', { method: 'POST', body: formObj(f) }); flash('Aprobador agregado.'); render(); },
  async customer(f) { await api('/customers', { method: 'POST', body: formObj(f) }); flash('Cliente agregado.'); render(); },
  async brand(f) {
    const fd = new FormData(); const o = formObj(f);
    for (const [k, v] of Object.entries(o)) { if (v === null || v === undefined) continue; fd.append(k, v === true ? '1' : v === false ? '0' : v); }
    await api('/brand', { method: 'POST', body: fd }); S.me = null; flash('Marca guardada.'); render();
  },
  async rules(f) {
    const o = formObj(f); const { name, invoice_prefix, flag_late } = o; delete o.name; delete o.invoice_prefix;
    const rules = {}; for (const [k, v] of Object.entries(o)) rules[k] = v;
    rules.flag_late = !!flag_late; rules.increment = Number(rules.increment);
    await api('/company', { method: 'PUT', body: { name, invoice_prefix, rules } }); S.me = null; flash('Reglas guardadas.'); render();
  },
  async accountisi(f) {
    const o = formObj(f); for (const k of Object.keys(o)) if (o[k] === null) delete o[k]; o.enabled = !!o.enabled;
    await api('/integrations/accountisi', { method: 'PUT', body: o }); flash('Configuración guardada.'); render();
  },
  async sales(f) {
    const o = formObj(f); for (const k of Object.keys(o)) if (o[k] === null) delete o[k];
    const r = await api('/integrations/accountisi/sales-invoice', { method: 'POST', body: o });
    S.cache.salesPreview = null; flash('Factura creada en Accountisi (id ' + r.accountisi_invoice_id + ').'); render();
  },
};
const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { if (e.message !== 'Sesión vencida') { flash(e.message, 'err'); render(); } } };
document.addEventListener('change', e => { const el = e.target.closest('[data-act-change="cs-customer"]'); if (el) { const f = el.closest('form'); S.cache.cs = { customer_id: Number(el.value), from: f.elements.from.value, to: f.elements.to.value }; S.cache.csPreview = null; render(); } });
document.addEventListener('change', e => { const el = e.target.closest('[data-filter]'); if (el) { FL(el.dataset.filter)[el.name] = el.value; render(); } });
document.addEventListener('click', e => { const el = e.target.closest('[data-act]'); if (el && actions[el.dataset.act]) { e.preventDefault(); guard(actions[el.dataset.act])(el); } });
document.addEventListener('submit', e => { const f = e.target.closest('form[data-form]'); if (f && forms[f.dataset.form]) { e.preventDefault(); guard(forms[f.dataset.form])(f); } });
window.addEventListener('hashchange', render);
render();
})();
