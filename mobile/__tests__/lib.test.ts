import { setLocaleValue } from '../src/i18n';
import { elapsedMs, fmtElapsed, segmentsFor, ClockState } from '../src/lib/clock';
import { pickRegions } from '../src/lib/geo';
import { dayTotals, summarize } from '../src/lib/report';
import { computeHours, validateEntry, RuleContext } from '../src/lib/rules';
import { applySyncResults, buildSyncPayload, mergeServer, pendingCount } from '../src/lib/sync';
import { addDays, hoursBetween, hoursToHM, monthGrid, roundTo, setWeekStart, to12h, weekDays, weekEnding, weekStarting } from '../src/lib/time';
import type { Entry, Project, ServerEntry } from '../src/lib/types';
import { uuid } from '../src/lib/uuid';

beforeAll(() => setLocaleValue('es'));

const project = (over: Partial<Project> = {}): Project => ({
  id: 1, code: 'P-1', name: 'Planta A', billable: true, active: true, invoice_detail: 'hours_total',
  geo_lat: null, geo_lng: null, geo_radius_m: null, ...over,
});
const entry = (over: Partial<Entry> = {}): Entry => ({
  uuid: uuid(), project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '16:00', hours: 8, note: '',
  billable: true, running: false, sync: 'dirty', ...over,
});

beforeEach(() => setWeekStart(1));   // las pruebas históricas cuentan la semana de lunes a domingo

describe('tiempo', () => {
  test('la semana empieza el domingo (por defecto) o el lunes, según las reglas', () => {
    setWeekStart(0);
    expect(weekEnding('2026-10-21')).toBe('2026-10-24');
    expect(weekEnding('2026-10-18')).toBe('2026-10-24');
    expect(weekEnding('2026-10-25')).toBe('2026-10-31');
    expect(weekStarting('2026-10-21')).toBe('2026-10-18');
    expect(monthGrid('2026-11-15')[0][0]).toBe('2026-11-01');   // el 1 de noviembre cae en domingo
    setWeekStart(1);
    expect(weekEnding('2026-10-21')).toBe('2026-10-25');
    expect(weekEnding('2026-10-25')).toBe('2026-10-25');
    expect(weekEnding('2026-10-26')).toBe('2026-11-01');
    expect(weekStarting('2026-10-21')).toBe('2026-10-19');
    expect(weekDays('2026-10-21')).toHaveLength(7);
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
  });
  test('horas entre dos horas se redondean al incremento', () => {
    expect(hoursBetween('08:00', '16:00')).toBe(8);
    expect(hoursBetween('08:00', '08:20')).toBe(0.25);
    expect(hoursBetween('08:00', '09:10')).toBe(1.25);
    expect(hoursBetween('16:00', '08:00')).toBe(0);
    expect(roundTo(1.1, 0.5)).toBe(1);
    expect(hoursToHM(8.5)).toBe('08:30');
    expect(to12h('13:05')).toBe('1:05 PM');
    expect(to12h('00:10')).toBe('12:10 AM');
  });
  test('el calendario del mes arranca en lunes', () => {
    const g = monthGrid('2026-10-15');
    expect(g[0]).toEqual([null, null, null, '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
    expect(g.flat().filter(Boolean)).toHaveLength(31);
    expect(g.every((r) => r.length === 7)).toBe(true);
  });
});

describe('reglas de captura (las mismas del servidor)', () => {
  const ctx = (over: Partial<RuleContext> = {}): RuleContext => ({
    pref: 'from_to', project: project(), rules: { increment: 0.25, max_day_hours: 16, weeks_back: 8 },
    sameDay: [], today: '2026-10-21', ...over,
  });

  test('desde/hasta exige horas y calcula las horas', () => {
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '16:00' }, ctx())).toEqual([]);
    expect(computeHours({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '16:30' }, 'from_to', 0.25)).toBe(8.5);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', hours: 8 }, ctx()).join()).toMatch(/inicio/);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '10:00', end_time: '09:00' }, ctx()).join()).toMatch(/después/);
  });
  test('día completo no admite horas desde/hasta ni proyectos que las exigen', () => {
    const c = ctx({ pref: 'full_day' });
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', hours: 8 }, c)).toEqual([]);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', hours: 8, start_time: '08:00' }, c).join()).toMatch(/total de horas del día/);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', hours: 8 }, ctx({ pref: 'full_day', project: project({ invoice_detail: 'with_times' }) })).join()).toMatch(/exige hora/);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20' }, c).join()).toMatch(/Escribe las horas/);
  });
  test('futuro, muy atrás, semana aprobada, cruce y máximo del día', () => {
    expect(validateEntry({ project_id: 1, work_date: '2026-10-22', start_time: '08:00', end_time: '09:00' }, ctx()).join()).toMatch(/futuras/);
    expect(validateEntry({ project_id: 1, work_date: '2026-06-01', start_time: '08:00', end_time: '09:00' }, ctx()).join()).toMatch(/más atrás/);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00' }, ctx({ lockedWeeks: new Set(['2026-10-25']) })).join()).toMatch(/aprobada/);
    const other = entry({ start_time: '08:00', end_time: '12:00', hours: 4 });
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '11:00', end_time: '13:00' }, ctx({ sameDay: [other] })).join()).toMatch(/cruza/);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '12:00', end_time: '13:00' }, ctx({ sameDay: [other] }))).toEqual([]);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '12:00', end_time: '23:59' }, ctx({ sameDay: [entry({ hours: 8, start_time: '00:00', end_time: '08:00' })], rules: { increment: 0.25, max_day_hours: 10, weeks_back: 8 } })).join()).toMatch(/máximo/);
  });
  test('una entrada borrada localmente no cuenta para el cruce ni para el máximo', () => {
    const gone = entry({ start_time: '08:00', end_time: '12:00', hours: 4, sync: 'deleted' });
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '09:00', end_time: '10:00' }, ctx({ sameDay: [gone] }))).toEqual([]);
  });
});

describe('reloj', () => {
  const c = (iso: string): ClockState => ({ entry_uuid: uuid(), project_id: 1, started_at: iso, note: '' });
  test('tiempo transcurrido y formato', () => {
    const st = c(new Date(2026, 9, 21, 8, 0, 0).toISOString());
    expect(fmtElapsed(elapsedMs(st, new Date(2026, 9, 21, 9, 30, 15)))).toBe('01:30:15');
    expect(elapsedMs(st, new Date(2026, 9, 21, 7, 0, 0))).toBe(0);
  });
  test('un tramo el mismo día', () => {
    const st = c(new Date(2026, 9, 21, 8, 0).toISOString());
    expect(segmentsFor(st, new Date(2026, 9, 21, 12, 30))).toEqual([{ work_date: '2026-10-21', start_time: '08:00', end_time: '12:30', hours: 4.5 }]);
  });
  test('si cruza la medianoche se parte en dos días', () => {
    const st = c(new Date(2026, 9, 21, 22, 0).toISOString());
    const seg = segmentsFor(st, new Date(2026, 9, 22, 1, 30));
    expect(seg).toHaveLength(2);
    expect(seg[0]).toMatchObject({ work_date: '2026-10-21', start_time: '22:00', end_time: '23:59' });
    expect(seg[1]).toMatchObject({ work_date: '2026-10-22', start_time: '00:00', end_time: '01:30', hours: 1.5 });
  });
  test('un reloj de segundos no produce entrada', () => {
    const st = c(new Date(2026, 9, 21, 8, 0, 0).toISOString());
    expect(segmentsFor(st, new Date(2026, 9, 21, 8, 0, 40))).toEqual([]);
  });
});

describe('sincronización sin conexión', () => {
  test('solo se envía lo pendiente y lo borrado', () => {
    const a = entry({ sync: 'dirty' }), b = entry({ sync: 'synced' }), c = entry({ sync: 'deleted' }), d = entry({ sync: 'rejected' });
    const p = buildSyncPayload([a, b, c, d]);
    expect(p).toHaveLength(2);
    expect(p[0]).toMatchObject({ client_uuid: a.uuid, project_id: 1, start_time: '08:00' });
    expect(p[1]).toEqual({ client_uuid: c.uuid, deleted: true });
    expect(pendingCount([a, b, c, d])).toBe(2);
  });
  test('entradas de día completo mandan horas, no tiempos', () => {
    const e = entry({ start_time: null, end_time: null, hours: 8 });
    expect(buildSyncPayload([e])[0]).toMatchObject({ hours: 8, start_time: null });
  });
  test('ok queda sincronizada, locked e invalid quedan rechazadas con motivo, y lo borrado ok desaparece', () => {
    const ok = entry(), locked = entry(), bad = entry(), del = entry({ sync: 'deleted' }), untouched = entry();
    const out = applySyncResults([ok, locked, bad, del, untouched], [
      { client_uuid: ok.uuid, status: 'ok', id: 7 },
      { client_uuid: locked.uuid, status: 'locked' },
      { client_uuid: bad.uuid, status: 'invalid', errors: { hours: ['Pasa del máximo'] } },
      { client_uuid: del.uuid, status: 'ok' },
    ]);
    expect(out).toHaveLength(4);
    expect(out.find((e) => e.uuid === ok.uuid)).toMatchObject({ sync: 'synced', id: 7 });
    expect(out.find((e) => e.uuid === locked.uuid)).toMatchObject({ sync: 'rejected', reject_reason: expect.stringMatching(/aprobada/) });
    expect(out.find((e) => e.uuid === bad.uuid)?.reject_reason).toBe('Pasa del máximo');
    expect(out.find((e) => e.uuid === untouched.uuid)?.sync).toBe('dirty');
  });
  test('mezclar con el servidor conserva lo pendiente y quita lo que ya no existe allá', () => {
    const keepPending = entry({ sync: 'dirty', work_date: '2026-10-20' });
    const staleSynced = entry({ sync: 'synced', id: 1, work_date: '2026-10-19' });
    const outside = entry({ sync: 'synced', id: 2, work_date: '2026-01-05' });
    const server: ServerEntry[] = [
      { id: 10, client_uuid: keepPending.uuid, project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00', running: false, hours: 1, note: null, billable: true },
      { id: 11, client_uuid: null, project_id: 1, work_date: '2026-10-21', start_time: null, end_time: null, running: false, hours: 8, note: 'x', billable: true },
    ];
    const out = mergeServer([keepPending, staleSynced, outside], server, '2026-10-01', '2026-10-31');
    expect(out.map((e) => e.uuid)).toContain(keepPending.uuid);
    expect(out.find((e) => e.uuid === keepPending.uuid)?.hours).toBe(8);          // la versión local pendiente gana
    expect(out.find((e) => e.uuid === staleSynced.uuid)).toBeUndefined();           // ya no está en el servidor
    expect(out.find((e) => e.uuid === outside.uuid)).toBeDefined();                 // fuera de la ventana: no se toca
    expect(out.find((e) => e.id === 11)).toMatchObject({ uuid: 'srv-11', sync: 'synced', note: 'x' });
  });
  test('reenviar lo mismo no duplica (mismo client_uuid)', () => {
    const e = entry();
    const first = applySyncResults([e], [{ client_uuid: e.uuid, status: 'ok', id: 5 }]);
    const again = mergeServer(first, [{ id: 5, client_uuid: e.uuid, project_id: 1, work_date: e.work_date, start_time: '08:00', end_time: '16:00', running: false, hours: 8, note: null, billable: true }], '2026-10-01', '2026-10-31');
    expect(again).toHaveLength(1);
  });
});

describe('reportes sin conexión', () => {
  const projects = [project(), project({ id: 2, code: 'P-2', name: 'Otro' })];
  const es = [
    entry({ work_date: '2026-10-19', hours: 8 }), entry({ work_date: '2026-10-19', hours: 2, project_id: 2, billable: false }),
    entry({ work_date: '2026-10-20', hours: 4 }), entry({ work_date: '2026-10-20', hours: 9, sync: 'deleted' }), entry({ work_date: '2026-09-01', hours: 5 }),
  ];
  test('por proyecto, por día y por semana', () => {
    const p = summarize(es, projects, '2026-10-01', '2026-10-31', 'project');
    expect(p.total).toBe(14); expect(p.billable).toBe(12);
    expect(p.rows.map((r) => [r.label, r.hours])).toEqual([['P-1 · Planta A', 12], ['P-2 · Otro', 2]]);
    expect(summarize(es, projects, '2026-10-01', '2026-10-31', 'day').rows.map((r) => r.hours)).toEqual([10, 4]);
    expect(summarize(es, projects, '2026-10-01', '2026-10-31', 'week').rows).toHaveLength(1);
    expect(dayTotals(es).get('2026-10-20')).toBe(4);
  });
});

describe('geocercas', () => {
  const near = project({ id: 1, geo_lat: '18.4000', geo_lng: '-66.1000', geo_radius_m: 100 });
  const far = project({ id: 2, geo_lat: 18.5, geo_lng: -66.2 });
  const none = project({ id: 3 });
  test('solo proyectos activos con lugar; el radio queda entre 50 y 5000', () => {
    const r = pickRegions([near, far, none, project({ id: 4, active: false, geo_lat: 1, geo_lng: 1 })], null);
    expect(r.map((x) => x.identifier)).toEqual(['1', '2']);
    expect(r[0]).toMatchObject({ latitude: 18.4, longitude: -66.1, radius: 100, notifyOnEnter: true });
    expect(r[1].radius).toBe(150);
    expect(pickRegions([project({ id: 9, geo_lat: 1, geo_lng: 1, geo_radius_m: 10 })], null)[0].radius).toBe(50);
  });
  test('con más de 20 lugares se eligen los más cercanos', () => {
    const many = Array.from({ length: 25 }, (_, i) => project({ id: i + 1, geo_lat: 18 + i * 0.1, geo_lng: -66 }));
    const r = pickRegions(many, { latitude: 18 + 24 * 0.1, longitude: -66 });
    expect(r).toHaveLength(20);
    expect(r[0].identifier).toBe('25');
  });
});

describe('uuid', () => {
  test('formato v4 y sin repetirse', () => {
    const a = uuid(), b = uuid();
    expect(a).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(a).not.toBe(b);
  });
});
