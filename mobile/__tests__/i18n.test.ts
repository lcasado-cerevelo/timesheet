import { DICTS, getLocale, normalizeLocale, setLocaleValue, t, tIn, useLocaleStore } from '../src/i18n';
import { dayLabel, longDate, monthLabel } from '../src/lib/time';

describe('traducción', () => {
  afterEach(() => setLocaleValue('es'));

  test('los dos diccionarios tienen exactamente las mismas claves y ningún texto vacío', () => {
    const es = Object.keys(DICTS.es).sort();
    expect(Object.keys(DICTS.en).sort()).toEqual(es);
    for (const k of es) {
      expect((DICTS.es as any)[k]).not.toBe('');
      expect((DICTS.en as any)[k]).not.toBe('');
    }
  });

  test('las marcas {x} son las mismas en español e inglés', () => {
    const marks = (s: string) => (s.match(/\{\w+\}/g) ?? []).sort().join();
    for (const k of Object.keys(DICTS.es)) expect(marks((DICTS.en as any)[k])).toBe(marks((DICTS.es as any)[k]));
  });

  test('t() sigue el idioma actual', () => {
    setLocaleValue('es');
    expect(t('day.check_in')).toBe('Entrar ahora');
    setLocaleValue('en');
    expect(t('day.check_in')).toBe('Check in now');
    expect(t('day.check_out')).toBe('Check out now');
  });

  test('interpolación: reemplaza las marcas y deja intacta la que no recibe valor', () => {
    setLocaleValue('en');
    expect(t('rules.max_day', { n: 16 })).toBe('Over the maximum hours per day (16).');
    expect(t('clock.segment_failed', { n: 2, error: 'x' })).toBe('Could not save segment 2: x');
    expect(tIn('es', 'week.over', { n: 40 })).toBe('Pasa de 40 h por semana.');
    expect(t('week.over')).toContain('{n}');
  });

  test('cambiar de idioma avisa a los suscriptores (la pantalla se re-pinta)', () => {
    setLocaleValue('es');
    const seen: string[] = [];
    const unsub = useLocaleStore.subscribe((s) => seen.push(s.locale));
    setLocaleValue('en');
    setLocaleValue('en'); // sin cambio: no avisa
    setLocaleValue('es');
    unsub();
    expect(seen).toEqual(['en', 'es']);
    expect(getLocale()).toBe('es');
  });

  test('normalizeLocale acepta es-PR, en_US y rechaza el resto', () => {
    expect(normalizeLocale('es-PR')).toBe('es');
    expect(normalizeLocale('en_US')).toBe('en');
    expect(normalizeLocale('fr-FR')).toBeNull();
    expect(normalizeLocale(undefined)).toBeNull();
  });

  test('fechas según el idioma', () => {
    setLocaleValue('es');
    expect(longDate('2026-10-21')).toBe('mié 21 de octubre de 2026');
    expect(dayLabel('2026-10-21')).toBe('mié 21/10');
    expect(monthLabel('2026-10-21')).toBe('octubre 2026');
    setLocaleValue('en');
    expect(longDate('2026-10-21')).toBe('Wed, October 21, 2026');
    expect(dayLabel('2026-10-21')).toBe('Wed 10/21');
    expect(monthLabel('2026-10-21')).toBe('October 2026');
  });

  test('los mensajes de validación salen en el idioma activo', () => {
    const { validateEntry } = require('../src/lib/rules');
    const ctx = { pref: 'from_to', project: undefined, rules: { increment: 0.25, max_day_hours: 16, weeks_back: 8 }, sameDay: [], today: '2026-10-21' };
    setLocaleValue('es');
    expect(validateEntry({ project_id: null, work_date: '2026-10-20' }, ctx)[0]).toBe('Elige un proyecto.');
    setLocaleValue('en');
    expect(validateEntry({ project_id: null, work_date: '2026-10-20' }, ctx)[0]).toBe('Choose a project.');
  });

  test('X-Locale viaja en las peticiones y los errores de red salen en el idioma activo', async () => {
    const { api } = require('../src/lib/api');
    const real = globalThis.fetch;
    const fetchMock = jest.fn().mockResolvedValue({ ok: true, status: 200, text: async () => '{}' });
    globalThis.fetch = fetchMock as any;
    setLocaleValue('en');
    await api('/x');
    expect(fetchMock.mock.calls[0][1].headers['X-Locale']).toBe('en');
    setLocaleValue('es');
    await api('/x');
    expect(fetchMock.mock.calls[1][1].headers['X-Locale']).toBe('es');
    globalThis.fetch = jest.fn().mockRejectedValue(new Error('x')) as any;
    setLocaleValue('en');
    await expect(api('/x')).rejects.toThrow('Cannot reach the server.');
    globalThis.fetch = real;
  });
});
