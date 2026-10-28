jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
jest.mock('expo-secure-store', () => ({}));
jest.mock('expo-notifications', () => ({}));
jest.mock('@react-native-community/netinfo', () => ({ __esModule: true, default: { fetch: jest.fn(), addEventListener: jest.fn() } }));

import { DICTS } from '../src/i18n';
import { ALL_TABS, bootDecision, dayActionAllowed, tabAllowed, visibleEntries } from '../src/lib/access';
import { fold, pushRecent, searchProjects } from '../src/lib/projectSearch';
import { ApiError, api, configureApi } from '../src/lib/api';
import type { Project } from '../src/lib/types';

const P = (id: number, code: string, name: string, customer?: string, active = true): Project =>
  ({ id, code, name, billable: true, active, invoice_detail: 'hours_total', geo_lat: null, geo_lng: null, geo_radius_m: null, customer: customer ? { name: customer } : undefined });
const projects = [
  P(1, 'ZED-2', 'Migración', 'Ñandú Café'),
  P(2, 'ADV-1', 'Soporte presencial', 'Advanced Corp'),
  P(3, 'ADV-2', 'Desarrollo WebApp', 'Advanced Corp'),
  P(4, 'INT-1', 'Administración interna'),
  P(5, 'OLD-1', 'Viejo', 'Advanced Corp', false),
];

describe('buscador de proyecto', () => {
  const codes = (q: string, recent: number[] = []) => searchProjects(projects, q, recent).map((h) => h.project.code);

  test('fold ignora tildes y mayúsculas', () => {
    expect(fold('  MigRación ')).toBe('migracion');
    expect(fold(undefined)).toBe('');
  });
  test('sin texto: todos los activos por código', () => {
    expect(codes('')).toEqual(['ADV-1', 'ADV-2', 'INT-1', 'ZED-2']);
  });
  test('por código, nombre y cliente, sin tildes ni mayúsculas', () => {
    expect(codes('zed')).toEqual(['ZED-2']);
    expect(codes('MIGRACION')).toEqual(['ZED-2']);
    expect(codes('administracion')).toEqual(['INT-1']);
    expect(codes('nandu cafe')).toEqual(['ZED-2']);
    expect(codes('advanced')).toEqual(['ADV-1', 'ADV-2']);
  });
  test('un proyecto sin cliente solo se busca por código y nombre', () => {
    expect(codes('corp')).toEqual(['ADV-1', 'ADV-2']);
    expect(codes('interna')).toEqual(['INT-1']);
  });
  test('los inactivos no salen', () => {
    expect(codes('viejo')).toEqual([]);
  });
  test('recientes primero (en su orden), luego el resto por código', () => {
    const hits = searchProjects(projects, '', [4, 1]);
    expect(hits.map((h) => h.project.code)).toEqual(['INT-1', 'ZED-2', 'ADV-1', 'ADV-2']);
    expect(hits.map((h) => h.recent)).toEqual([true, true, false, false]);
    expect(codes('advanced', [3])).toEqual(['ADV-2', 'ADV-1']);
  });
  test('sin resultados', () => {
    expect(codes('xyz')).toEqual([]);
  });
  test('recientes: sin repetir, el último primero, máximo 5', () => {
    expect(pushRecent([1, 2, 3], 2)).toEqual([2, 1, 3]);
    expect(pushRecent([1, 2, 3, 4, 5], 6)).toEqual([6, 1, 2, 3, 4]);
  });
});

describe('arranque según remember_days y conexión', () => {
  const base = { hasToken: true, hasSavedSession: true };
  test('remember_days = 0: con señal pide la contraseña aunque haya token', () => {
    expect(bootDecision({ ...base, rememberDays: 0, online: true })).toBe('login');
  });
  test('remember_days = 0 con la huella activada: se reutiliza la sesión guardada (la huella la desbloquea), con o sin señal', () => {
    expect(bootDecision({ ...base, rememberDays: 0, online: true, biometric: true })).toBe('resume');
    expect(bootDecision({ ...base, rememberDays: 0, online: false, biometric: true })).toBe('resume');
    expect(bootDecision({ hasToken: false, hasSavedSession: true, rememberDays: 0, online: true, biometric: true })).toBe('login');
  });
  test('remember_days = 0: sin señal y con sesión previa, modo limitado', () => {
    expect(bootDecision({ ...base, rememberDays: 0, online: false })).toBe('limited');
  });
  test('remember_days = 0: sin señal y nunca hubo sesión, login', () => {
    expect(bootDecision({ hasToken: false, hasSavedSession: false, rememberDays: 0, online: false })).toBe('login');
  });
  test('remember_days > 0 (o desconocido): nada cambia, con o sin señal', () => {
    for (const rememberDays of [30, 1, undefined, null]) {
      for (const online of [true, false]) {
        expect(bootDecision({ ...base, rememberDays, online })).toBe('resume');
        expect(bootDecision({ hasToken: false, hasSavedSession: true, rememberDays, online })).toBe('login');
      }
    }
  });
});

describe('modo limitado', () => {
  test('solo la pestaña Día', () => {
    expect(ALL_TABS.filter((t) => tabAllowed(t, true))).toEqual(['index']);
    expect(ALL_TABS.filter((t) => tabAllowed(t, false))).toEqual([...ALL_TABS]);
  });
  test('acciones: anotar horas sí; repetir y copiar día anterior (historial) no', () => {
    for (const a of ['check_in', 'check_out', 'add_entry', 'add_hours', 'change_project'] as const) expect(dayActionAllowed(a, true)).toBe(true);
    expect(dayActionAllowed('repeat', true)).toBe(false);
    expect(dayActionAllowed('copy_prev', true)).toBe(false);
    expect(dayActionAllowed('repeat', false)).toBe(true);
  });
  test('solo se ven las entradas que se están anotando (pendientes), no el historial', () => {
    const es = [{ sync: 'synced' }, { sync: 'dirty' }, { sync: 'rejected' }];
    expect(visibleEntries(es, true)).toEqual([{ sync: 'dirty' }]);
    expect(visibleEntries(es, false)).toHaveLength(3);
  });
});

describe('401 session_expired', () => {
  const realFetch = globalThis.fetch;
  afterEach(() => {
    globalThis.fetch = realFetch;
    configureApi({ baseUrl: '', token: null, onUnauthorized: undefined });
  });
  test('lleva a cerrar la sesión y el error trae el código', async () => {
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: false, status: 401, text: async () => JSON.stringify({ message: 'x', code: 'session_expired' }) }) as any;
    const onUnauthorized = jest.fn();
    configureApi({ baseUrl: 'https://x.com', token: 't', onUnauthorized });
    const e = await api('/me').catch((x) => x);
    expect(e).toBeInstanceOf(ApiError);
    expect(e.code).toBe('session_expired');
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });
});

describe('textos es/en nuevos', () => {
  test('existen en los dos idiomas', () => {
    for (const k of ['search.label', 'search.placeholder', 'search.clear', 'search.empty', 'search.recent', 'limited.banner', 'login.continue_limited', 'login.reconnected'] as const) {
      expect(DICTS.es[k]).toBeTruthy();
      expect(DICTS.en[k]).toBeTruthy();
    }
    expect(DICTS.es['limited.banner']).toBe('Sin señal: solo puedes anotar horas. Al volver la señal te pediremos la contraseña y se enviará todo.');
    expect(DICTS.es['search.empty']).toBe('Sin resultados');
  });
});
