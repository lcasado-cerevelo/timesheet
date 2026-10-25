import { create } from 'zustand';
import { api, configureApi } from '../lib/api';
import { normalizeLocale } from '../i18n';
import type { Locale } from '../i18n';
import { loadLocale, pendingLocale, saveLocale } from '../i18n/persist';
import { kv, secure } from '../lib/storage';
import type { Brand, Me } from '../lib/types';

const NEUTRAL: Brand = { name: 'Tiempo', logo_url: null, logo_dark_url: null, color_primary: '#1f4e79', color_accent: '#2e8b57', email_footer: null };

interface AuthState {
  ready: boolean;
  baseUrl: string;
  token: string | null;
  me: Me | null;
  brand: Brand;
  init: () => Promise<void>;
  login: (baseUrl: string, email: string, password: string) => Promise<void>;
  refreshMe: () => Promise<void>;
  logout: () => Promise<void>;
  /** Cambia el idioma de la persona: se aplica y se guarda en el teléfono al instante; el servidor se avisa en cuanto haya conexión. */
  setLanguage: (l: Locale) => Promise<void>;
}

export const useAuth = create<AuthState>((set, get) => ({
  ready: false,
  baseUrl: process.env.EXPO_PUBLIC_API_URL ?? '',
  token: null,
  me: null,
  brand: NEUTRAL,

  async init() {
    await loadLocale();
    const [token, baseUrl, me] = await Promise.all([secure.get('token'), kv.get<string>('baseUrl', ''), kv.get<Me | null>('me', null)]);
    const url = baseUrl || get().baseUrl;
    configureApi({ baseUrl: url, token, onUnauthorized: () => void get().logout() });
    set({ ready: true, token, baseUrl: url, me, brand: me?.brand ?? NEUTRAL });
    if (token) void get().refreshMe().catch(() => undefined); // la marca se actualiza sola si hay conexión
  },

  async login(baseUrl, email, password) {
    const url = baseUrl.trim().replace(/\/+$/, '');
    configureApi({ baseUrl: url, token: null });
    const r = await api<{ token: string; user: Me }>('/login', { method: 'POST', body: { email, password, device: 'movil' } });
    await Promise.all([secure.set('token', r.token), kv.set('baseUrl', url), kv.set('me', r.user)]);
    configureApi({ baseUrl: url, token: r.token });
    const l = normalizeLocale(r.user.locale);
    if (l) await saveLocale(l);
    await pendingLocale.clear();
    set({ token: r.token, baseUrl: url, me: r.user, brand: r.user.brand });
  },

  async refreshMe() {
    // Un cambio de idioma hecho sin conexión se envía primero, para que el servidor no lo pise.
    const pend = await pendingLocale.get();
    if (pend) {
      await api('/me/locale', { method: 'PUT', body: { locale: pend } });
      await pendingLocale.clear();
    }
    const me = await api<Me>('/me');
    const l = normalizeLocale(me.locale);
    if (l) await saveLocale(l);
    await kv.set('me', me);
    set({ me, brand: me.brand });
  },

  async setLanguage(l) {
    await saveLocale(l);
    const me = get().me;
    if (me) {
      const next = { ...me, locale: l };
      set({ me: next });
      await kv.set('me', next);
    }
    if (!get().token) return;
    try {
      await pendingLocale.set(l);
      await api('/me/locale', { method: 'PUT', body: { locale: l } });
      await pendingLocale.clear();
    } catch {
      /* sin conexión: queda pendiente y se envía al volver */
    }
  },

  async logout() {
    try {
      if (get().token) await api('/logout', { method: 'POST' });
    } catch {
      /* sin conexión: igual se cierra localmente */
    }
    await Promise.all([secure.del('token'), kv.del('me')]);
    configureApi({ token: null });
    set({ token: null, me: null, brand: NEUTRAL });
  },
}));
