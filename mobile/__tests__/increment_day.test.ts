jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
jest.mock('expo-secure-store', () => ({}));
jest.mock('expo-notifications', () => ({}));

import { DICTS, setLocaleValue } from '../src/i18n';
import { ApiError, api } from '../src/lib/api';
import { dayButtonRows } from '../src/lib/dayButtons';
import { checkStop, hmAligned, hoursAligned, minimumMinutes, roundHM, roundedNowHM, stepMinutes } from '../src/lib/increment';
import { minimumDialog } from '../src/lib/minimumDialog';
import { sortedProjects, stepProject } from '../src/lib/projectSearch';
import { validateEntry, RuleContext } from '../src/lib/rules';
import { applySyncResults } from '../src/lib/sync';
import type { Entry, Me, Project } from '../src/lib/types';
import { useAuth } from '../src/store/auth';
import { useClock } from '../src/store/clock';
import { useEntries } from '../src/store/entries';

beforeAll(() => setLocaleValue('es'));

const INCS = [0.25, 0.5, 1, 0.1, 0.05, 0.01];

const proj = (id: number, code: string, over: Partial<Project> = {}): Project => ({
  id, code, name: 'Proyecto ' + code, billable: true, active: true, invoice_detail: 'hours_total',
  geo_lat: null, geo_lng: null, geo_radius_m: null, services: [], ...over,
});
const ctx = (inc: number, over: Partial<RuleContext> = {}, min?: number): RuleContext => ({
  pref: 'from_to', project: proj(1, 'P-1'), rules: { increment: inc, max_day_hours: 16, weeks_back: 8, ...(min ? { minimum_minutes: min } : {}) },
  sameDay: [], today: '2026-10-21', ...over,
});

describe('navegación de proyectos con flechas', () => {
  const list = [proj(3, 'P-10'), proj(1, 'P-2'), proj(2, 'P-1'), proj(4, 'X', { active: false })];

  test('van en orden por código (numérico) y sin los inactivos', () => {
    expect(sortedProjects(list).map((p) => p.code)).toEqual(['P-1', 'P-2', 'P-10']);
  });

  test('› y ‹ recorren uno a uno y dan la vuelta en los extremos', () => {
    expect(stepProject(list, 2, 1)?.code).toBe('P-2');
    expect(stepProject(list, 1, 1)?.code).toBe('P-10');
    expect(stepProject(list, 3, 1)?.code).toBe('P-1');
    expect(stepProject(list, 2, -1)?.code).toBe('P-10');
    expect(stepProject(list, 3, -1)?.code).toBe('P-2');
  });

  test('sin proyecto escogido entra por el primero (›) o el último (‹); con uno solo no hay a dónde ir', () => {
    expect(stepProject(list, null, 1)?.code).toBe('P-1');
    expect(stepProject(list, null, -1)?.code).toBe('P-10');
    expect(stepProject([proj(1, 'A')], 1, 1)).toBeNull();
    expect(stepProject([], null, 1)).toBeNull();
  });
});

describe('cuadrícula de botones del día', () => {
  const ids = (o: Parameters<typeof dayButtonRows>[0]) => dayButtonRows(o).map((r) => r.map((b) => b.id));

  test('servicio por detalle: Entrar|Salir, Agregar entrada|Repetir, Copiar día anterior|Ir a hoy', () => {
    expect(ids({ fromTo: true, repeat: true, copyPrev: true })).toEqual([['check-in', 'check-out'], ['add-entry', 'template'], ['copy-prev', 'today']]);
  });

  test('servicio por total: sin fila de reloj; Agregar horas|Repetir, Copiar|Ir a hoy', () => {
    expect(ids({ fromTo: false, repeat: true, copyPrev: true })).toEqual([['add-hours', 'template'], ['copy-prev', 'today']]);
  });

  test('no existe el botón Cambiar proyecto', () => {
    const all = dayButtonRows({ fromTo: true, repeat: true, copyPrev: true }).flat().map((b) => b.id as string);
    expect(all).not.toContain('switch');
  });

  test('modo limitado (sin Repetir ni Copiar): Agregar va primero, encima de Entrar ahora; lo que queda solo ocupa la fila', () => {
    const rows = dayButtonRows({ fromTo: true, repeat: false, copyPrev: false });
    expect(rows.map((r) => r.map((b) => b.id))).toEqual([['add-entry'], ['check-in', 'check-out'], ['today']]);
    expect(rows[0][0].wide).toBe(true);
    expect(rows[1][0].wide).toBe(false);
    expect(rows[2][0].wide).toBe(true);
    expect(dayButtonRows({ fromTo: false, repeat: false, copyPrev: false }).map((r) => r.map((b) => b.id))).toEqual([['add-hours'], ['today']]);
  });
});

describe('alineación al incremento', () => {
  test('el paso de los minutos para cada incremento', () => {
    expect(INCS.map(stepMinutes)).toEqual([15, 30, 60, 6, 3, 3]);
  });

  test('horas: múltiplos del incremento sí, lo demás no (todos los incrementos)', () => {
    for (const inc of INCS) {
      for (const k of [1, 2, 3, 7, 32]) expect(hoursAligned(Math.round(k * inc * 1000) / 1000, inc)).toBe(true);
    }
    expect(hoursAligned(7.3, 0.25)).toBe(false);
    expect(hoursAligned(7.25, 0.5)).toBe(false);
    expect(hoursAligned(7.5, 1)).toBe(false);
    expect(hoursAligned(0.15, 0.1)).toBe(false);
    expect(hoursAligned(0.07, 0.05)).toBe(false);
    expect(hoursAligned(7.555, 0.01)).toBe(false);
  });

  test('horas del reloj: solo valen los minutos del paso', () => {
    for (const inc of INCS) {
      const step = stepMinutes(inc);
      for (let m = 0; m < 60; m++) expect(hmAligned(`08:${String(m).padStart(2, '0')}`, inc)).toBe(m % step === 0);
    }
    expect(hmAligned('08:15', 0.25)).toBe(true);
    expect(hmAligned('08:10', 0.25)).toBe(false);
    expect(hmAligned('23:59', 0.25)).toBe(false);
    expect(hmAligned('23:59', 0.25, true)).toBe(true);
  });

  test('la hora actual se redondea al múltiplo más cercano', () => {
    expect(roundHM('08:07', 0.25)).toBe('08:00');
    expect(roundHM('08:08', 0.25)).toBe('08:15');
    expect(roundHM('08:53', 0.25)).toBe('09:00');
    expect(roundHM('08:44', 0.5)).toBe('08:30');
    expect(roundHM('08:31', 1)).toBe('09:00');
    expect(roundHM('08:29', 1)).toBe('08:00');
    expect(roundHM('08:10', 0.1)).toBe('08:12');
    expect(roundHM('08:04', 0.05)).toBe('08:03');
    expect(roundHM('23:59', 0.25)).toBe('23:45'); // nunca pasa a las 24:00
    expect(roundedNowHM(0.25, new Date(2026, 9, 21, 14, 38))).toBe('14:45');
    for (const inc of INCS) expect(hmAligned(roundHM('13:37', inc), inc)).toBe(true);
  });

  test('validación local: hora desde/hasta no alineada avisa con el paso y bloquea', () => {
    const e = validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:10', end_time: '09:00' }, ctx(0.25));
    expect(e).toContain('Los minutos deben ser múltiplos de 15.');
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:15', end_time: '09:00' }, ctx(0.25))).toEqual([]);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:20' }, ctx(0.5)).join()).toMatch(/múltiplos de 30/);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:20' }, ctx(0.1)).join()).toMatch(/múltiplos de 6/);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '10:00' }, ctx(1))).toEqual([]);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '10:30' }, ctx(1)).join()).toMatch(/múltiplos de 60/);
  });

  test('Add hours: el total debe ser múltiplo del incremento (no se redondea en silencio)', () => {
    const fd = (inc: number, hours: number) =>
      validateEntry({ project_id: 1, work_date: '2026-10-20', hours }, ctx(inc, { pref: 'full_day' }));
    expect(fd(0.25, 7.5)).toEqual([]);
    expect(fd(0.25, 7.3)).toEqual(['Las horas deben ser múltiplos de 0.25.']);
    expect(fd(0.5, 7.25).join()).toMatch(/múltiplos de 0.5/);
    expect(fd(1, 7.5).join()).toMatch(/múltiplos de 1/);
    expect(fd(0.1, 7.2)).toEqual([]);
    expect(fd(0.05, 7.07).join()).toMatch(/múltiplos/);
    expect(fd(0.01, 7.55)).toEqual([]);
  });
});

describe('mínimo de una entrada', () => {
  test('minimum_minutes de la regla; si no viene, un incremento', () => {
    expect(minimumMinutes({ increment: 0.25 })).toBe(15);
    expect(minimumMinutes({ increment: 0.5, minimum_minutes: 30 })).toBe(30);
    expect(minimumMinutes({ increment: 1 })).toBe(60);
    expect(minimumMinutes({ increment: 0.1 })).toBe(6);
    expect(minimumMinutes({ increment: 0.05 })).toBe(3);
  });

  test('Add entry (desde/hasta): menos del mínimo bloquea con el mensaje', () => {
    const e = validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '08:03' }, ctx(0.05, {}, 6));
    expect(e).toEqual(['La duración mínima de una entrada es de 6 min.']);
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '08:15' }, ctx(0.25))).toEqual([]);
    setLocaleValue('en');
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '08:06' }, ctx(0.5, {}, 30))).toEqual(
      expect.arrayContaining(['The minimum duration of an entry is 30 min.']),
    );
    setLocaleValue('es');
  });

  test('checkStop: antes de la entrada, bajo el mínimo (pide permiso) o bien', () => {
    const start = new Date(2026, 9, 21, 8, 0);
    expect(checkStop(start, new Date(2026, 9, 21, 7, 45), 15)).toEqual({ kind: 'before' });
    expect(checkStop(start, new Date(2026, 9, 21, 8, 0), 15)).toEqual({ kind: 'below', elapsed: 0, minimum: 15 });
    expect(checkStop(start, new Date(2026, 9, 21, 8, 10), 15)).toEqual({ kind: 'below', elapsed: 10, minimum: 15 });
    expect(checkStop(start, new Date(2026, 9, 21, 8, 15), 15)).toEqual({ kind: 'ok', elapsed: 15 });
  });

  test('el diálogo de permiso: texto y los tres botones, en español e inglés', () => {
    const d = minimumDialog(5, 15);
    expect(d.message).toBe('Solo pasaron 5 min. El mínimo es 15 min. ¿Registrarlo como una entrada de 15 min?');
    expect([d.register, d.keep, d.discard]).toEqual(['Registrar 15 min', 'Seguir con el reloj', 'Descartar']);
    setLocaleValue('en');
    const e = minimumDialog(5, 15);
    expect(e.message).toBe('Only 5 min have passed. The minimum is 15 min. Record it as a 15 min entry?');
    expect([e.register, e.keep, e.discard]).toEqual(['Record 15 min', 'Keep the clock running', 'Discard']);
    setLocaleValue('es');
  });
});

describe('Check out now con el mínimo (store)', () => {
  const me = { id: 1, capture_mode: 'from_to', rules: { increment: 0.25, minimum_minutes: 15, max_day_hours: 16, weeks_back: 52 } } as unknown as Me;
  const fakeNow = new Date(2026, 9, 21, 12, 0);
  beforeEach(() => {
    jest.useFakeTimers({ now: fakeNow, doNotFake: ['setTimeout', 'setInterval', 'setImmediate', 'clearTimeout', 'clearInterval', 'clearImmediate', 'nextTick', 'queueMicrotask', 'performance', 'hrtime'] });
    useAuth.setState({ me, token: null });
    useEntries.setState({ entries: [], lockedWeeks: [], projects: [proj(1, 'P-1')] });
    useClock.setState({ clock: { entry_uuid: 'c1', project_id: 1, service_id: null, started_at: new Date(2026, 9, 21, 10, 0).toISOString(), note: '' } });
  });
  afterEach(() => {
    jest.useRealTimers();
    useClock.setState({ clock: null });
  });

  test('sin confirmar no se cierra: el reloj sigue y no se guarda nada', () => {
    const msg = useClock.getState().stop(new Date(2026, 9, 21, 10, 5));
    expect(msg).toBe('Solo pasaron 5 min. El mínimo es 15 min.');
    expect(useClock.getState().clock).not.toBeNull();
    expect(useEntries.getState().entries).toHaveLength(0);
  });

  test('con permiso registra una entrada del mínimo', () => {
    expect(useClock.getState().stop(new Date(2026, 9, 21, 10, 5), { registerMinimum: true })).toBeNull();
    expect(useClock.getState().clock).toBeNull();
    const [e] = useEntries.getState().entries;
    expect(e).toMatchObject({ start_time: '10:00', end_time: '10:15', hours: 0.25, work_date: '2026-10-21' });
  });

  test('con el mínimo cumplido cierra normal; Descartar quita el reloj sin guardar', () => {
    expect(useClock.getState().stop(new Date(2026, 9, 21, 10, 45))).toBeNull();
    expect(useEntries.getState().entries[0]).toMatchObject({ start_time: '10:00', end_time: '10:45', hours: 0.75 });
    useClock.setState({ clock: { entry_uuid: 'c2', project_id: 1, service_id: null, started_at: new Date(2026, 9, 21, 11, 0).toISOString(), note: '' } });
    useClock.getState().discard();
    expect(useClock.getState().clock).toBeNull();
    expect(useEntries.getState().entries).toHaveLength(1);
  });

  test('la cola sin conexión conserva la regla: lo no alineado ni siquiera se guarda', () => {
    const r = useEntries.getState().save({ project_id: 1, work_date: '2026-10-21', start_time: '08:10', end_time: '09:00' });
    expect(r.errors.join()).toMatch(/múltiplos de 15/);
    expect(useEntries.getState().entries).toHaveLength(0);
  });
});

describe('errores 422 del servidor', () => {
  test('sync: below_minimum se muestra igual que la validación local; la alineación llega con su mensaje', () => {
    const e: Entry = { uuid: 'u1', project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '08:05', hours: 0, note: '', billable: true, running: false, sync: 'dirty' };
    const out = applySyncResults([e], [{ client_uuid: 'u1', status: 'invalid', code: 'below_minimum', minimum_minutes: 15 }]);
    expect(out[0].reject_reason).toBe('La duración mínima de una entrada es de 15 min.');
    const out2 = applySyncResults([e], [{ client_uuid: 'u1', status: 'invalid', errors: { start_time: ['Los minutos deben ser múltiplos de 15.'] } }]);
    expect(out2[0].reject_reason).toBe('Los minutos deben ser múltiplos de 15.');
  });

  test('api: 422 below_minimum de clock/stop sale con el mensaje local y su minimum_minutes', async () => {
    const real = globalThis.fetch;
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: false, status: 422, text: async () => JSON.stringify({ code: 'below_minimum', minimum_minutes: 15, message: 'x' }) }) as any;
    const err = await api('/clock/stop', { method: 'POST' }).catch((x) => x);
    expect(err).toBeInstanceOf(ApiError);
    expect(err.message).toBe('La duración mínima de una entrada es de 15 min.');
    expect(err.code).toBe('below_minimum');
    expect(err.minimumMinutes).toBe(15);
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: false, status: 422, text: async () => JSON.stringify({ errors: { hours: ['Las horas deben ser múltiplos de 0.25.'] } }) }) as any;
    await expect(api('/entries', { method: 'POST' })).rejects.toThrow('Las horas deben ser múltiplos de 0.25.');
    globalThis.fetch = real;
  });
});

describe('textos nuevos es/en', () => {
  const keys = [
    'rules.not_aligned', 'rules.hours_not_aligned', 'rules.below_min', 'clock.below_min', 'clock.below_min_title', 'clock.below_min_ask',
    'clock.register_min', 'clock.keep_going', 'clock.discard', 'day.search', 'day.prev_project', 'day.next_project', 'day.pick_service',
    'picker.hour', 'picker.minute',
  ];
  test('existen en los dos idiomas, con las mismas marcas', () => {
    const marks = (s: string) => (s.match(/\{\w+\}/g) ?? []).sort().join();
    for (const k of keys) {
      const es = (DICTS.es as any)[k];
      const en = (DICTS.en as any)[k];
      expect(es).toBeTruthy();
      expect(en).toBeTruthy();
      expect(marks(en)).toBe(marks(es));
    }
    expect(Object.keys(DICTS.en).sort()).toEqual(Object.keys(DICTS.es).sort());
  });
});
