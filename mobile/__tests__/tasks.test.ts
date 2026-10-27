jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
jest.mock('expo-secure-store', () => ({}));
jest.mock('expo-notifications', () => ({}));

import { setLocaleValue } from '../src/i18n';
import { activeTasks, resolveTask, taskName } from '../src/lib/tasks';
import { buildSyncPayload, mergeServer } from '../src/lib/sync';
import type { Entry, Me, Project, ServerEntry, Service, Task } from '../src/lib/types';
import { computeCurrent } from '../src/store/selection';
import { useAuth } from '../src/store/auth';
import { useClock } from '../src/store/clock';
import { useEntries } from '../src/store/entries';

beforeAll(() => setLocaleValue('es'));

const svc = (): Service => ({ id: 1, project_id: 1, name: 'Consultoría', billable: true, capture_mode: 'detail', active: true, sort: 0, mode: 'from_to' });
const task = (over: Partial<Task> = {}): Task => ({ id: 10, project_id: 1, name: 'Diseño', active: true, sort: 0, ...over });
const proj = (tasks?: Task[]): Project => ({
  id: 1, code: 'P-1', name: 'Planta A', billable: true, active: true, invoice_detail: 'hours_total',
  geo_lat: null, geo_lng: null, geo_radius_m: null, services: [svc()], tasks,
});
const entry = (over: Partial<Entry> = {}): Entry => ({
  uuid: 'u-' + Math.random(), project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '16:00', hours: 8, note: '',
  billable: true, running: false, sync: 'dirty', ...over,
});
const yesterday = () => {
  const d = new Date();
  d.setDate(d.getDate() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

describe('tareas del proyecto (obligatorias solo si el proyecto las tiene)', () => {
  test('un proyecto sin tareas (o de una versión vieja que no las trae) no muestra nada', () => {
    expect(activeTasks(proj(undefined))).toEqual([]);
    expect(activeTasks(proj([]))).toEqual([]);
    const cur = computeCurrent([proj([])], 1, {}, 'from_to');
    expect(cur.tasks).toEqual([]);
    expect(cur.task).toBeNull();
  });

  test('solo cuentan las activas; una desactivada vale únicamente si la entrada ya la tenía', () => {
    const p = proj([task({ id: 10 }), task({ id: 11, name: 'Vieja', active: false })]);
    expect(activeTasks(p).map((x) => x.id)).toEqual([10]);
    expect(resolveTask(p, 10)?.name).toBe('Diseño');
    expect(resolveTask(p, 11)).toBeNull();
    expect(resolveTask(p, 11, 11)?.name).toBe('Vieja');
    expect(resolveTask(p, 99)).toBeNull();
    expect(resolveTask(p, null)).toBeNull();
    expect(taskName(p, 10)).toBe('Diseño');
    expect(taskName(p, null)).toBeUndefined();
  });

  test('la tarea recordada del proyecto es la escogida; sin recordar no hay tarea', () => {
    const p = proj([task({ id: 10 }), task({ id: 11, name: 'Pruebas' })]);
    expect(computeCurrent([p], 1, {}, 'from_to', { '1': 11 }).task?.name).toBe('Pruebas');
    expect(computeCurrent([p], 1, {}, 'from_to', {}).task).toBeNull();
    expect(computeCurrent([p], 1, {}, 'from_to', { '1': 99 }).task).toBeNull();
    expect(computeCurrent([p], 1, {}, 'from_to', {}).tasks).toHaveLength(2);
  });

  test('se manda al servidor: null si no tiene; las entradas viejas (sin el campo) no la tocan', () => {
    const p = buildSyncPayload([entry({ task_id: 10 }), entry({ task_id: null }), entry()]);
    expect(p[0]).toMatchObject({ task_id: 10 });
    expect(p[1]).toMatchObject({ task_id: null });
    expect(p[2].task_id).toBeUndefined();
  });

  test('lo que trae el servidor conserva la tarea', () => {
    const s = (over: Partial<ServerEntry>): ServerEntry => ({
      id: 1, client_uuid: null, project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00', running: false, hours: 1, note: null, billable: true, ...over,
    });
    const out = mergeServer([], [s({ id: 1, task_id: 10 }), s({ id: 2, task: { id: 11, name: 'Pruebas' }, start_time: '09:00', end_time: '10:00' }), s({ id: 3, start_time: '10:00', end_time: '11:00' })], '2026-10-19', '2026-10-25');
    expect(out.map((e) => e.task_id)).toEqual([10, 11, null]);
  });
});

describe('guardar con tarea (store)', () => {
  const me = { id: 1, capture_mode: 'from_to', rules: { increment: 0.25, max_day_hours: 16, weeks_back: 52 } } as unknown as Me;
  beforeEach(() => {
    useAuth.setState({ me, token: null });
    useEntries.setState({ entries: [], lockedWeeks: [], projects: [proj([task({ id: 10 }), task({ id: 11, name: 'Pruebas' })])] });
    useClock.setState({ clock: null });
  });
  const input = { project_id: 1, work_date: yesterday(), start_time: '08:00', end_time: '10:00' };

  test('con tareas en el proyecto, sin tarea da error; sin tareas en el proyecto guarda igual', () => {
    const r = useEntries.getState().save(input);
    expect(r.errors.join()).toMatch(/Escoge la tarea/);
    expect(useEntries.getState().entries).toHaveLength(0);
    useEntries.setState({ projects: [proj([])] });
    const ok = useEntries.getState().save(input);
    expect(ok.errors).toEqual([]);
    expect(ok.entry?.task_id).toBeNull();
  });

  test('una entrada vieja que estaba sin tarea se puede seguir editando sin ella', () => {
    useEntries.setState({ entries: [entry({ uuid: 'old', work_date: yesterday(), sync: 'synced', id: 3 })] });
    const r = useEntries.getState().save({ ...input, end_time: '11:00' }, 'old');
    expect(r.errors).toEqual([]);
    expect(r.entry).toMatchObject({ uuid: 'old', task_id: null });
  });

  test('guarda la tarea escogida y, al editar sin decir nada, la conserva; no se puede quitar', () => {
    const a = useEntries.getState().save({ ...input, task_id: 10 });
    expect(a.entry?.task_id).toBe(10);
    const b = useEntries.getState().save({ ...input, end_time: '11:00' }, a.entry!.uuid);
    expect(b.entry?.task_id).toBe(10);
    const c = useEntries.getState().save({ ...input, end_time: '11:00', task_id: null }, a.entry!.uuid);
    expect(c.errors.join()).toMatch(/Escoge la tarea/);
    expect(useEntries.getState().entries.find((e) => e.uuid === a.entry!.uuid)?.task_id).toBe(10);
  });

  test('una tarea que no es del proyecto o está desactivada no sirve: se pide escoger otra', () => {
    useEntries.setState({ projects: [proj([task({ id: 10 }), task({ id: 11, active: false })])] });
    expect(useEntries.getState().save({ ...input, task_id: 99 }).errors.join()).toMatch(/Escoge la tarea/);
    expect(useEntries.getState().save({ ...input, start_time: '12:00', end_time: '13:00', task_id: 11 }).errors.join()).toMatch(/Escoge la tarea/);
  });

  test('el reloj no prende sin tarea si el proyecto tiene tareas', () => {
    expect(useClock.getState().start(1, null)).toMatch(/Escoge la tarea/);
    expect(useClock.getState().clock).toBeNull();
  });

  test('el reloj guarda la tarea en el tramo y la pasa a la entrada al parar', () => {
    const at = (h: number) => { const d = new Date(); d.setDate(d.getDate() - 1); d.setHours(h, 0, 0, 0); return d; };
    expect(useClock.getState().start(1, null, '', at(8), 10)).toBeNull();
    expect(useClock.getState().clock?.task_id).toBe(10);
    expect(useClock.getState().stop(at(10))).toBeNull();
    expect(useEntries.getState().entries.every((e) => e.task_id === 10)).toBe(true);
    expect(useEntries.getState().entries.length).toBeGreaterThan(0);
  });
});
