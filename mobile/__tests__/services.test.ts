jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
jest.mock('expo-secure-store', () => ({}));
jest.mock('expo-notifications', () => ({}));

import { setLocaleValue } from '../src/i18n';
import { validateEntry, RuleContext } from '../src/lib/rules';
import { activeServices, effectiveMode, needsService, resolveService, serviceMode } from '../src/lib/services';
import { applySyncResults, buildSyncPayload, mergeServer } from '../src/lib/sync';
import type { Entry, Me, Project, Service, ServerEntry } from '../src/lib/types';
import { computeCurrent } from '../src/store/selection';
import { useAuth } from '../src/store/auth';
import { useEntries } from '../src/store/entries';
import { useClock } from '../src/store/clock';

beforeAll(() => setLocaleValue('es'));

const svc = (over: Partial<Service> = {}): Service => ({ id: 1, project_id: 1, name: 'Consultoría', billable: true, capture_mode: 'detail', active: true, sort: 0, mode: 'from_to', ...over });
const proj = (services: Service[], over: Partial<Project> = {}): Project => ({
  id: 1, code: 'P-1', name: 'Planta A', billable: true, active: true, invoice_detail: 'hours_total',
  geo_lat: null, geo_lng: null, geo_radius_m: null, services, ...over,
});
const entry = (over: Partial<Entry> = {}): Entry => ({
  uuid: 'u-' + Math.random(), project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '16:00', hours: 8, note: '',
  billable: true, running: false, sync: 'dirty', ...over,
});
const ctx = (project: Project, over: Partial<RuleContext> = {}): RuleContext => ({
  pref: 'from_to', project, rules: { increment: 0.25, max_day_hours: 16, weeks_back: 8 }, sameDay: [], today: '2026-10-21', ...over,
});

describe('modo efectivo por servicio (la regla del servidor)', () => {
  test('detalle -> desde/hasta, total -> día completo, ambos -> manda la preferencia de la persona', () => {
    expect(serviceMode(svc({ capture_mode: 'detail' }), 'full_day')).toBe('from_to');
    expect(serviceMode(svc({ capture_mode: 'total' }), 'from_to')).toBe('full_day');
    expect(serviceMode(svc({ capture_mode: 'both' }), 'full_day')).toBe('full_day');
    expect(serviceMode(svc({ capture_mode: 'both' }), 'from_to')).toBe('from_to');
  });

  test('validación: un servicio por total pide horas y rechaza desde/hasta, aunque la persona prefiera detalle', () => {
    const p = proj([svc({ capture_mode: 'total', mode: 'full_day' })]);
    expect(validateEntry({ project_id: 1, service_id: 1, work_date: '2026-10-20', hours: 8 }, ctx(p))).toEqual([]);
    expect(validateEntry({ project_id: 1, service_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '16:00' }, ctx(p)).join()).toMatch(/total de horas del día/);
    expect(validateEntry({ project_id: 1, service_id: 1, work_date: '2026-10-20' }, ctx(p)).join()).toMatch(/Escribe las horas/);
  });

  test('validación: un servicio por detalle pide desde/hasta aunque la persona prefiera el total', () => {
    const p = proj([svc({ capture_mode: 'detail' })]);
    const c = ctx(p, { pref: 'full_day' });
    expect(validateEntry({ project_id: 1, service_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '16:00' }, c)).toEqual([]);
    expect(validateEntry({ project_id: 1, service_id: 1, work_date: '2026-10-20', hours: 8 }, c).join()).toMatch(/inicio/);
  });

  test('validación: con "ambos" decide la preferencia', () => {
    const p = proj([svc({ capture_mode: 'both', mode: 'from_to' })]);
    expect(validateEntry({ project_id: 1, service_id: 1, work_date: '2026-10-20', hours: 8 }, ctx(p, { pref: 'full_day' }))).toEqual([]);
    expect(validateEntry({ project_id: 1, service_id: 1, work_date: '2026-10-20', hours: 8 }, ctx(p, { pref: 'from_to' })).join()).toMatch(/inicio/);
  });

  test('con varios servicios activos el servicio es obligatorio; con un id inexistente o desactivado, inválido', () => {
    const p = proj([svc({ id: 1 }), svc({ id: 2, name: 'Soporte' }), svc({ id: 3, name: 'Viejo', active: false })]);
    const input = { project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00' };
    expect(validateEntry(input, ctx(p)).join()).toMatch(/Escoge el servicio/);
    expect(validateEntry({ ...input, service_id: 2 }, ctx(p))).toEqual([]);
    expect(validateEntry({ ...input, service_id: 3 }, ctx(p)).join()).toMatch(/no existe/);
    expect(validateEntry({ ...input, service_id: 99 }, ctx(p)).join()).toMatch(/no existe/);
    // una entrada vieja puede conservar un servicio desactivado
    expect(validateEntry({ ...input, service_id: 3 }, ctx(p, { keepServiceId: 3 }))).toEqual([]);
  });

  test('mensajes en inglés', () => {
    setLocaleValue('en');
    const p = proj([svc({ id: 1 }), svc({ id: 2 })]);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00' }, ctx(p)).join()).toMatch(/Choose the service/);
    setLocaleValue('es');
  });

  test('proyectos guardados antes de que existieran los servicios siguen funcionando con la preferencia', () => {
    const old = proj([]);
    delete (old as any).services;
    expect(needsService(old)).toBe(false);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00' }, ctx(old))).toEqual([]);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', hours: 8 }, ctx(old, { pref: 'full_day' }))).toEqual([]);
    expect(effectiveMode(old, null, 'full_day')).toBe('full_day');
  });
});

describe('selección automática del único servicio', () => {
  test('con un solo servicio activo se escoge solo; con varios hay que escoger', () => {
    const one = proj([svc({ id: 5 }), svc({ id: 6, active: false })]);
    expect(resolveService(one, null)?.id).toBe(5);
    expect(activeServices(one)).toHaveLength(1);
    const many = proj([svc({ id: 1 }), svc({ id: 2 })]);
    expect(resolveService(many, null)).toBeUndefined();
    expect(resolveService(many, 2)?.id).toBe(2);
  });

  test('el proyecto y servicio recordados se respetan; si ya no existen, se cae al primero y al único', () => {
    const a = proj([svc({ id: 1 }), svc({ id: 2, name: 'B', capture_mode: 'total', mode: 'full_day' })]);
    const b = proj([svc({ id: 7, project_id: 2, capture_mode: 'total', mode: 'full_day' })], { id: 2, code: 'P-2' });
    let c = computeCurrent([a, b], 1, { '1': 2 }, 'from_to');
    expect(c.project?.id).toBe(1);
    expect(c.service?.id).toBe(2);
    expect(c.mode).toBe('full_day');
    c = computeCurrent([a, b], 2, {}, 'from_to');
    expect(c.service?.id).toBe(7); // único servicio: se escoge solo
    expect(c.mode).toBe('full_day');
    c = computeCurrent([a, b], 99, {}, 'from_to');
    expect(c.project?.id).toBe(1);
    expect(c.service).toBeUndefined(); // dos servicios y ninguno recordado
    expect(c.mode).toBe('from_to');
  });
});

describe('guardar una entrada (store)', () => {
  const me = { id: 1, capture_mode: 'from_to', rules: { increment: 0.25, max_day_hours: 16, weeks_back: 52 } } as unknown as Me;
  beforeEach(() => {
    useAuth.setState({ me, token: null });
    useEntries.setState({ entries: [], lockedWeeks: [], projects: [] });
  });
  const yesterday = () => {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  test('con un solo servicio queda asignado sin pedirlo', () => {
    useEntries.setState({ projects: [proj([svc({ id: 5 })])] });
    const r = useEntries.getState().save({ project_id: 1, work_date: yesterday(), start_time: '08:00', end_time: '10:00' });
    expect(r.errors).toEqual([]);
    expect(r.entry?.service_id).toBe(5);
    expect(r.entry?.hours).toBe(2);
  });

  test('con varios servicios, sin servicio da error; con servicio por total guarda las horas sin tiempos', () => {
    useEntries.setState({ projects: [proj([svc({ id: 1 }), svc({ id: 2, capture_mode: 'total', mode: 'full_day' })])] });
    const day = yesterday();
    expect(useEntries.getState().save({ project_id: 1, work_date: day, start_time: '08:00', end_time: '10:00' }).errors.join()).toMatch(/Escoge el servicio/);
    const r = useEntries.getState().save({ project_id: 1, service_id: 2, work_date: day, hours: 6.5 });
    expect(r.errors).toEqual([]);
    expect(r.entry).toMatchObject({ service_id: 2, hours: 6.5, start_time: null, end_time: null });
  });

  test('editar una entrada vieja sin servicio en un proyecto de un solo servicio la deja con ese servicio', () => {
    useEntries.setState({ projects: [proj([svc({ id: 5 })])], entries: [entry({ uuid: 'old', work_date: yesterday(), sync: 'synced', id: 3 })] });
    const r = useEntries.getState().save({ project_id: 1, work_date: yesterday(), start_time: '08:00', end_time: '09:00' }, 'old');
    expect(r.errors).toEqual([]);
    expect(r.entry).toMatchObject({ uuid: 'old', id: 3, service_id: 5 });
  });

  test('el reloj solo prende en servicios por detalle y no se pierde al cambiar a un proyecto que no se puede', () => {
    const detail = proj([svc({ id: 1 })]);
    const total = proj([svc({ id: 9, project_id: 2, capture_mode: 'total', mode: 'full_day' })], { id: 2, code: 'P-2' });
    useEntries.setState({ projects: [detail, total] });
    useClock.setState({ clock: null });
    expect(useClock.getState().start(2, null)).toMatch(/el reloj no aplica/);
    expect(useClock.getState().start(1, null)).toBeNull();
    expect(useClock.getState().clock?.service_id).toBe(1);
    // cambiar a un proyecto por total falla sin parar el reloj actual
    expect(useClock.getState().switchTo(2, null)).toMatch(/el reloj no aplica/);
    expect(useClock.getState().clock?.project_id).toBe(1);
    useClock.setState({ clock: null });
  });
});

describe('cola de sincronización con service_id', () => {
  test('lo pendiente lleva service_id; las entradas viejas sin servicio no lo mandan', () => {
    const withSvc = entry({ service_id: 7 });
    const legacy = entry();
    const [a, b] = buildSyncPayload([withSvc, legacy]);
    expect(a).toMatchObject({ client_uuid: withSvc.uuid, project_id: 1, service_id: 7 });
    expect(JSON.parse(JSON.stringify(b))).not.toHaveProperty('service_id');
  });

  test('una entrada por total manda horas y servicio, sin tiempos', () => {
    const e = entry({ service_id: 3, start_time: null, end_time: null, hours: 7.5 });
    expect(buildSyncPayload([e])[0]).toMatchObject({ service_id: 3, hours: 7.5, start_time: null });
  });

  test('lo borrado solo manda su client_uuid; lo rechazado conserva el servicio', () => {
    const del = entry({ service_id: 3, sync: 'deleted' });
    expect(buildSyncPayload([del])).toEqual([{ client_uuid: del.uuid, deleted: true }]);
    const bad = entry({ service_id: 3 });
    const out = applySyncResults([bad], [{ client_uuid: bad.uuid, status: 'invalid', errors: { service_id: ['Escoge el servicio.'] } }]);
    expect(out[0]).toMatchObject({ sync: 'rejected', service_id: 3, reject_reason: 'Escoge el servicio.' });
  });

  test('al traer del servidor se guarda service_id (también si solo viene el objeto service)', () => {
    const base = { client_uuid: null, project_id: 1, work_date: '2026-10-21', start_time: null, end_time: null, running: false, hours: 8, note: null, billable: true };
    const server: ServerEntry[] = [
      { ...base, id: 1, service_id: 4 },
      { ...base, id: 2, service: { id: 6, name: 'Soporte' } },
      { ...base, id: 3 },
    ];
    const out = mergeServer([], server, '2026-10-01', '2026-10-31');
    expect(out.map((e) => e.service_id)).toEqual([4, 6, null]);
  });

  test('el mensaje de hoja aprobada sale en el idioma activo', () => {
    const e = entry();
    setLocaleValue('en');
    expect(applySyncResults([e], [{ client_uuid: e.uuid, status: 'locked' }])[0].reject_reason).toBe('The timesheet for that week is already approved.');
    setLocaleValue('es');
  });
});
