jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
jest.mock('expo-secure-store', () => ({}));
jest.mock('expo-notifications', () => ({}));
jest.mock('../src/lib/api', () => {
  const actual = jest.requireActual('../src/lib/api');
  return { ...actual, api: jest.fn() };
});

import { setWeekStart } from '../src/lib/time';
import { setLocaleValue } from '../src/i18n';
import { api } from '../src/lib/api';
import { validateEntry, RuleContext } from '../src/lib/rules';
import type { Me, Project, Sheet } from '../src/lib/types';
import { useAuth } from '../src/store/auth';
import { useEntries } from '../src/store/entries';

beforeAll(() => setLocaleValue('es'));

const proj = (id: number, code: string): Project => ({
  id, code, name: 'Proyecto ' + code, billable: true, active: true, invoice_detail: 'hours_total',
  geo_lat: null, geo_lng: null, geo_radius_m: null, services: [],
});
const ctx = (over: Partial<RuleContext> = {}): RuleContext => ({
  pref: 'from_to', project: proj(1, 'P-1'), rules: { increment: 0.25, max_day_hours: 16, weeks_back: 8 }, sameDay: [], today: '2026-10-22', ...over,
});

/** Semana del 19 al 25 de octubre de 2026: lunes aprobado, martes devuelto, miércoles enviado, el resto abierto. */
const week = (over: Partial<Sheet> = {}): Sheet => ({
  id: 9, week_starting: '2026-10-19', week_ending: '2026-10-25', status: 'draft', locked: false, version: 1, total_hours: 8, late: false,
  return_comment: null, warnings: [], partial_submission: true,
  days: [
    { date: '2026-10-19', sheet_id: 1, part: 1, status: 'approved', locked: true, open: false },
    { date: '2026-10-20', sheet_id: 2, part: 2, status: 'returned', locked: false, open: false },
    { date: '2026-10-21', sheet_id: 3, part: 3, status: 'submitted', locked: false, open: false },
    { date: '2026-10-22', sheet_id: 9, part: null, status: 'draft', locked: false, open: true },
    { date: '2026-10-23', sheet_id: 9, part: null, status: 'draft', locked: false, open: true },
    { date: '2026-10-24', sheet_id: 9, part: null, status: 'draft', locked: false, open: true },
    { date: '2026-10-25', sheet_id: 9, part: null, status: 'draft', locked: false, open: true },
  ],
  parts: [
    { id: 1, part: 1, status: 'approved', is_part: true, period_starting: '2026-10-19', period_ending: '2026-10-19', total_hours: 8, return_comment: null },
    { id: 2, part: 2, status: 'returned', is_part: true, period_starting: '2026-10-20', period_ending: '2026-10-20', total_hours: 8, return_comment: 'Revisa el martes' },
    { id: 3, part: 3, status: 'submitted', is_part: true, period_starting: '2026-10-21', period_ending: '2026-10-21', total_hours: 8, return_comment: null },
  ],
  ...over,
});

setWeekStart(1);   // estas pruebas cuentan la semana de lunes a domingo
beforeEach(() => setWeekStart(1));

describe('el móvil reconoce el día aprobado', () => {
  test('un día aprobado no se puede cambiar, pero los otros días de la misma semana sí', () => {
    const lockedDays = new Set(['2026-10-19']);
    const input = (d: string) => ({ project_id: 1, work_date: d, start_time: '08:00', end_time: '09:00' });
    expect(validateEntry(input('2026-10-19'), ctx({ lockedDays })).join()).toMatch(/Ese día ya se envió/);
    expect(validateEntry(input('2026-10-20'), ctx({ lockedDays }))).toEqual([]);
    expect(validateEntry(input('2026-10-22'), ctx({ lockedDays }))).toEqual([]);
  });

  test('una semana entera aprobada sigue diciendo semana', () => {
    expect(validateEntry({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00' },
      ctx({ lockedWeeks: new Set(['2026-10-25']), lockedDays: new Set(['2026-10-20']) })).join()).toMatch(/Esa semana ya se envió/);
  });
});

describe('la hoja de la semana trae el estado de cada día (store)', () => {
  const me = { id: 1, capture_mode: 'from_to', rules: { week_starts_on: 1, increment: 0.25, minimum_minutes: 15, max_day_hours: 16, weeks_back: 52 } } as unknown as Me;
  beforeEach(() => {
    (api as jest.Mock).mockReset();
    useAuth.setState({ me, token: 't' } as any);
    useEntries.setState({ entries: [], lockedWeeks: [], lockedDays: [], projects: [proj(1, 'P-1')], sheets: {} });
  });

  test('solo el día aprobado queda bloqueado; el martes devuelto y el miércoles enviado se pueden corregir', async () => {
    (api as jest.Mock).mockResolvedValueOnce(week());
    await useEntries.getState().fetchSheet('2026-10-22');
    expect(useEntries.getState().lockedDays).toEqual(['2026-10-19']);
    expect(useEntries.getState().lockedWeeks).toEqual([]);

    const ok = useEntries.getState().save({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00' });
    expect(ok.errors).toEqual([]);
    const no = useEntries.getState().save({ project_id: 1, work_date: '2026-10-19', start_time: '10:00', end_time: '11:00' });
    expect(no.errors.join()).toMatch(/Ese día ya se envió/);
  });

  test('si el servidor libera el día (parte devuelta), el bloqueo se quita', async () => {
    useEntries.setState({ lockedDays: ['2026-10-19', '2026-10-20'] });
    (api as jest.Mock).mockResolvedValueOnce(week());
    await useEntries.getState().fetchSheet('2026-10-22');
    expect(useEntries.getState().lockedDays).toEqual(['2026-10-19']);
  });

  test('sin días en la respuesta (semana de una sola hoja) no queda ningún día bloqueado de esa semana', async () => {
    useEntries.setState({ lockedDays: ['2026-10-20', '2026-10-27'] });
    const single = week({ days: undefined, parts: undefined, partial_submission: false });
    (api as jest.Mock).mockResolvedValueOnce(single);
    await useEntries.getState().fetchSheet('2026-10-22');
    expect(useEntries.getState().lockedDays).toEqual(['2026-10-27']);
  });

  test('un día rechazado como bloqueado por el servidor se recuerda como día, no como semana', async () => {
    useEntries.setState({ lockedWeeks: [], lockedDays: [] });
    const approvedTuesday = week({ days: week().days!.map((d) => (d.date === '2026-10-20' ? { ...d, status: 'approved' as const, locked: true } : d)) });
    (api as jest.Mock).mockImplementation(async (path: string, opts?: any) => {
      if (path === '/sync') return { results: (opts?.body?.entries ?? []).map((x: any) => ({ client_uuid: x.client_uuid, status: 'locked' })) };
      if (path.startsWith('/sheets/week')) return approvedTuesday;
      if (path.startsWith('/entries')) return { entries: [] };
      if (path === '/projects') return [proj(1, 'P-1')];
      return {};
    });
    // guardar dispara la sincronización sola: se espera a que termine
    useEntries.getState().save({ project_id: 1, work_date: '2026-10-20', start_time: '08:00', end_time: '09:00' });
    await new Promise((r) => setTimeout(r, 50));
    expect(useEntries.getState().lockedWeeks).toEqual([]);
    expect(useEntries.getState().lockedDays).toContain('2026-10-20');
  });
});
