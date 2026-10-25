import { create } from 'zustand';
import { es } from './es';
import { en } from './en';

import type { Locale } from '../lib/types';
export type { Locale };
export type TKey = keyof typeof es;
export type TParams = Record<string, string | number>;

export const LOCALES: Locale[] = ['es', 'en'];
export const DICTS: Record<Locale, Record<TKey, string>> = { es, en };

/** Idioma que se usa ahora. Antes de iniciar sesión es el del dispositivo; después, el de la persona. */
export const useLocaleStore = create<{ locale: Locale }>(() => ({ locale: 'en' }));

export function getLocale(): Locale {
  return useLocaleStore.getState().locale;
}

export function setLocaleValue(l: Locale): void {
  if (LOCALES.includes(l) && l !== getLocale()) useLocaleStore.setState({ locale: l });
}

export function normalizeLocale(raw: unknown): Locale | null {
  if (typeof raw !== 'string') return null;
  const base = raw.toLowerCase().replace('_', '-').split('-')[0];
  return base === 'es' ? 'es' : base === 'en' ? 'en' : null;
}

/** Texto en el idioma actual, con {marcas} reemplazadas. Si falta la clave, se muestra la clave (nunca se rompe). */
export function tIn(locale: Locale, key: TKey, params?: TParams): string {
  const raw = DICTS[locale][key] ?? DICTS.en[key] ?? String(key);
  if (!params) return raw;
  return raw.replace(/\{(\w+)\}/g, (m, name) => (name in params ? String(params[name]) : m));
}

export function t(key: TKey, params?: TParams): string {
  return tIn(getLocale(), key, params);
}

/** Hook: re-pinta la pantalla cuando cambia el idioma. Devuelve t y el idioma. */
export function useT(): { t: typeof t; locale: Locale } {
  const locale = useLocaleStore((s) => s.locale);
  return { t: (key, params) => tIn(locale, key, params), locale };
}
