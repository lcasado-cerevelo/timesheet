import { create } from 'zustand';
import { api, configureApi } from '../lib/api';
import { normalizeLocale, t } from '../i18n';
import type { Locale } from '../i18n';
import { loadLocale, pendingLocale, saveLocale } from '../i18n/persist';
import { bootDecision } from '../lib/access';
import { isOnline } from '../lib/net';
import { kv, secure } from '../lib/storage';
import { devDefaultServerUrl, fixedServerUrl, normalizeServerUrl, resolveServer } from '../lib/server';
import type { Brand, Me } from '../lib/types';

const NEUTRAL: Brand = { name: 'Tiempo', logo_url: null, logo_dark_url: null, color_primary: '#1f4e79', color_accent: '#2e8b57', email_footer: null };

interface AuthState {
  ready: boolean;
  baseUrl: string;
  /** El servidor viene fijado al compilar: no se muestra ni se pide en ninguna pantalla. */
  serverFixed: boolean;
  /** Compilación sin servidor válido (producción sin EXPO_PUBLIC_SERVER_URL / extra.serverUrl). */
  misconfigured: boolean;
  /** El servidor cerró la sesión (401): el login lo avisa. Las entradas guardadas sin conexión se conservan. */
  sessionClosed: boolean;
  /** Modo limitado (remember_days = 0 y sin señal al abrir): solo se pueden anotar horas; sin token. */
  limited: boolean;
  /** Volvió la señal estando en modo limitado: el login lo explica. */
  reconnected: boolean;
  token: string | null;
  me: Me | null;
  brand: Brand;
  init: () => Promise<void>;
  /** devUrl solo se usa en desarrollo y sin servidor fijo. */
  login: (email: string, password: string, devUrl?: string) => Promise<void>;
  /** Entra al modo limitado (solo anotar horas) si la regla es 0 y hay una sesión guardada en este teléfono. */
  enterLimited: () => boolean;
  /** Vuelve la señal: se sale del modo limitado y se pide la contraseña. */
  leaveLimited: () => void;
  /** Cierre forzado por el servidor (401): solo limpia la sesión local, sin llamar al servidor. */
  sessionExpired: () => Promise<void>;
  refreshMe: () => Promise<void>;
  logout: () => Promise<void>;
  /** Cambia el idioma de la persona: se aplica y se guarda en el teléfono al instante; el servidor se avisa en cuanto haya conexión. */
  setLanguage: (l: Locale) => Promise<void>;
}

export const useAuth = create<AuthState>((set, get) => ({
  ready: false,
  baseUrl: '',
  serverFixed: false,
  misconfigured: false,
  sessionClosed: false,
  limited: false,
  reconnected: false,
  token: null,
  me: null,
  brand: NEUTRAL,

  async init() {
    await loadLocale();
    const [storedToken, baseUrl, me] = await Promise.all([secure.get('token'), kv.get<string>('baseUrl', ''), kv.get<Me | null>('me', null)]);
    const srv = resolveServer({ fixed: fixedServerUrl(), stored: baseUrl, devDefault: devDefaultServerUrl(), dev: __DEV__ });
    // Con remember_days = 0 el token guardado nunca se reutiliza: cada apertura en frío pide la contraseña.
    const rememberDays = me?.remember_days;
    const decision = bootDecision({ rememberDays, online: rememberDays === 0 ? await isOnline() : true, hasToken: !!storedToken, hasSavedSession: !!me });
    if (rememberDays === 0 && storedToken) await secure.del('token');
    const token = decision === 'resume' ? storedToken : null;
    configureApi({ baseUrl: srv.url, token, onUnauthorized: () => void get().sessionExpired() });
    set({ ready: true, token, limited: decision === 'limited', baseUrl: srv.url, serverFixed: srv.fixed, misconfigured: srv.misconfigured, me, brand: me?.brand ?? NEUTRAL });
    if (token) void get().refreshMe().catch(() => undefined); // la marca se actualiza sola si hay conexión
  },

  async login(email, password, devUrl) {
    const { serverFixed, baseUrl: current } = get();
    // Con servidor fijo se usa siempre ese; si no, solo en desarrollo se acepta el escrito a mano.
    const url = serverFixed ? current : __DEV__ ? normalizeServerUrl(devUrl ?? current, true) ?? '' : '';
    if (!url) throw new Error(t('login.no_server'));
    configureApi({ baseUrl: url, token: null });
    const r = await api<{ token: string; user: Me }>('/login', { method: 'POST', body: { email, password, device: 'movil' } });
    await Promise.all([secure.set('token', r.token), serverFixed ? Promise.resolve() : kv.set('baseUrl', url), kv.set('me', r.user)]);
    configureApi({ baseUrl: url, token: r.token });
    const l = normalizeLocale(r.user.locale);
    if (l) await saveLocale(l);
    await pendingLocale.clear();
    set({ token: r.token, baseUrl: url, sessionClosed: false, limited: false, reconnected: false, me: r.user, brand: r.user.brand });
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

  enterLimited() {
    const me = get().me;
    if (!me || me.remember_days !== 0) return false;
    set({ limited: true });
    return true;
  },

  leaveLimited() {
    if (get().limited) set({ limited: false, reconnected: true });
  },

  async sessionExpired() {
    if (!get().token) return;
    // 'me' se conserva en el teléfono (con remember_days): permite el modo limitado si la regla es 0 y no hay señal.
    await secure.del('token');
    configureApi({ token: null });
    set({ token: null, me: null, brand: NEUTRAL, sessionClosed: true });
  },

  async logout() {
    try {
      if (get().token) await api('/logout', { method: 'POST' });
    } catch {
      /* sin conexión: igual se cierra localmente */
    }
    await Promise.all([secure.del('token'), kv.del('me')]);
    configureApi({ token: null });
    set({ token: null, me: null, brand: NEUTRAL, sessionClosed: false });
  },
}));
