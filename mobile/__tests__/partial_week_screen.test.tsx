jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
jest.mock('expo-secure-store', () => ({}));
jest.mock('expo-notifications', () => ({}));
jest.mock('expo-router', () => ({ useRouter: () => ({ navigate: jest.fn(), push: jest.fn() }) }));
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, bottom: 0, left: 0, right: 0 }) }));
jest.mock('../src/lib/api', () => {
  const actual = jest.requireActual('../src/lib/api');
  return { ...actual, api: jest.fn() };
});

import { setWeekStart } from '../src/lib/time';
import React from 'react';
const TestRenderer = require('react-test-renderer');
const { act } = TestRenderer;
import { setLocaleValue } from '../src/i18n';
import { api } from '../src/lib/api';
import type { Me, Project, Sheet } from '../src/lib/types';
import { useAuth } from '../src/store/auth';
import { useEntries } from '../src/store/entries';
import Week from '../app/(tabs)/week';

beforeAll(() => setLocaleValue('es'));

const proj = (id: number, code: string): Project => ({
  id, code, name: 'Proyecto ' + code, billable: true, active: true, invoice_detail: 'hours_total',
  geo_lat: null, geo_lng: null, geo_radius_m: null, services: [],
});

/** Lunes aprobado, martes devuelto, miércoles enviado, jueves abierto. */
const sheet = (): Sheet => ({
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
});

const hosts = (root: any, id: string) => root.findAll((n: any) => typeof n.type === 'string' && n.props.testID === id);
const texts = (root: any): string[] => root.findAll((n: any) => typeof n.type === 'string' && n.type === 'Text').map((n: any) => [].concat(n.props.children).flat(3).join(''));

setWeekStart(1);   // estas pruebas cuentan la semana de lunes a domingo
beforeEach(() => setWeekStart(1));

describe('pantalla de la semana con partes', () => {
  beforeEach(() => {
    (api as jest.Mock).mockReset();
    (api as jest.Mock).mockResolvedValue(sheet());
    useAuth.setState({ me: { id: 1, capture_mode: 'from_to', worker_type: 'consultant', weekly_hours: null, rules: { week_starts_on: 1, increment: 0.25, max_day_hours: 16, weeks_back: 52 } } as unknown as Me, token: 't' } as any);
    useEntries.setState({ entries: [], projects: [proj(1, 'P-1')], lockedWeeks: [], lockedDays: [], selectedDate: '2026-10-22', sheets: {} });
  });

  test('cada día muestra su estado, la parte devuelta muestra su motivo y todavía se puede enviar', async () => {
    let r: any;
    await act(async () => { r = TestRenderer.create(<Week />); });
    await act(async () => { await Promise.resolve(); });
    const all = texts(r.root).join(' | ');
    expect(all).toMatch(/Aprobada/);                 // lunes
    expect(all).toMatch(/Devuelta/);                 // martes
    expect(all).toMatch(/Revisa el martes/);          // el motivo de esa parte
    expect(hosts(r.root, 'daystate-2026-10-19')).toHaveLength(1);
    expect(hosts(r.root, 'daystate-2026-10-22')).toHaveLength(0);   // el jueves está abierto: sin etiqueta
    expect(hosts(r.root, 'submit-week').length).toBeGreaterThan(0);  // hay días abiertos y una parte devuelta
  });

  test('si todos los días ya están enviados o aprobados no hay nada que enviar', async () => {
    const closed = sheet();
    closed.days = closed.days!.map((d) => ({ ...d, status: 'submitted' as const, open: false, locked: false }));
    closed.parts = closed.parts!.map((p) => ({ ...p, status: 'submitted' as const }));
    (api as jest.Mock).mockResolvedValue(closed);
    let r: any;
    await act(async () => { r = TestRenderer.create(<Week />); });
    await act(async () => { await Promise.resolve(); });
    expect(hosts(r.root, 'submit-week')).toHaveLength(0);
  });
});
