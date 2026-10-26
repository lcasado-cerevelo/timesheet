import Constants from 'expo-constants';
import { Platform } from 'react-native';

/**
 * Dirección del servidor.
 *
 * En una compilación para una compañía se FIJA al compilar (EXPO_PUBLIC_SERVER_URL o app.json > extra.serverUrl):
 * la persona nunca la ve ni la escribe. Sin servidor fijado (solo desarrollo) se puede escribir a mano en la
 * sección "Developer" del login. En producción solo se aceptan direcciones https://.
 */

/** Valor fijado al compilar, o '' si no hay. EXPO_PUBLIC_* debe leerse con el nombre literal para que Expo lo incruste. */
export function fixedServerUrl(): string {
  const env = process.env.EXPO_PUBLIC_SERVER_URL;
  if (typeof env === 'string' && env.trim()) return env.trim();
  const extra = (Constants.expoConfig?.extra ?? {}) as { serverUrl?: unknown };
  return typeof extra.serverUrl === 'string' ? extra.serverUrl.trim() : '';
}

/** Dirección de desarrollo por omisión (solo se usa en __DEV__). */
export function devDefaultServerUrl(): string {
  if (process.env.EXPO_PUBLIC_API_URL) return process.env.EXPO_PUBLIC_API_URL;
  // En el emulador de Android, 10.0.2.2 es la computadora; en iOS/web, localhost. El Docker de desarrollo publica el puerto 8180.
  return Platform.OS === 'android' ? 'http://10.0.2.2:8180' : 'http://localhost:8180';
}

/** Limpia y valida. Devuelve null si no sirve: no es http(s), o es http:// fuera de desarrollo. */
export function normalizeServerUrl(raw: string | null | undefined, dev: boolean): string | null {
  const url = (raw ?? '').trim().replace(/\/+$/, '');
  if (!/^https?:\/\/[^\s/]+/i.test(url)) return null;
  if (/^http:\/\//i.test(url) && !dev) return null;
  return url;
}

export interface ServerResolution {
  /** Dirección a usar ('' si no hay ninguna válida). */
  url: string;
  /** true si viene fijada al compilar: la pantalla de entrada no muestra nada del servidor. */
  fixed: boolean;
  /** true si falta o es inválida y la app no puede conectarse (compilación mal configurada). */
  misconfigured: boolean;
}

/**
 * Decide qué servidor usa la app.
 *  - Fijo al compilar: manda siempre; lo guardado en el teléfono se ignora.
 *  - Sin fijo y __DEV__: lo guardado, o el de desarrollo por omisión.
 *  - Sin fijo y producción: no hay servidor (misconfigured).
 */
export function resolveServer(opts: { fixed: string; stored: string; devDefault: string; dev: boolean }): ServerResolution {
  if (opts.fixed) {
    const url = normalizeServerUrl(opts.fixed, opts.dev);
    return { url: url ?? '', fixed: true, misconfigured: url === null };
  }
  if (!opts.dev) return { url: '', fixed: false, misconfigured: true };
  const url = normalizeServerUrl(opts.stored, true) ?? normalizeServerUrl(opts.devDefault, true) ?? '';
  return { url, fixed: false, misconfigured: false };
}
