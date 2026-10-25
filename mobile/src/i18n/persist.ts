import { kv } from '../lib/storage';
import { deviceLocale } from './device';
import { Locale, normalizeLocale, setLocaleValue } from './index';

const KEY = 'locale.v1';
const PENDING = 'locale.pending.v1';

/** Al abrir la app: el idioma guardado; si no hay, el del dispositivo. */
export async function loadLocale(): Promise<Locale> {
  const saved = normalizeLocale(await kv.get<string | null>(KEY, null));
  const l = saved ?? deviceLocale();
  setLocaleValue(l);
  return l;
}

export async function saveLocale(l: Locale): Promise<void> {
  setLocaleValue(l);
  await kv.set(KEY, l);
}

/** Cambio de idioma que no se pudo avisar al servidor (sin conexión). */
export const pendingLocale = {
  get: async () => normalizeLocale(await kv.get<string | null>(PENDING, null)),
  set: (l: Locale) => kv.set(PENDING, l),
  clear: () => kv.del(PENDING),
};
