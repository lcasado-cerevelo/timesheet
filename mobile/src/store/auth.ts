import { create } from 'zustand';
import { api, configureApi } from '../lib/api';
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
}

export const useAuth = create<AuthState>((set, get) => ({
  ready: false,
  baseUrl: process.env.EXPO_PUBLIC_API_URL ?? '',
  token: null,
  me: null,
  brand: NEUTRAL,

  async init() {
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
    set({ token: r.token, baseUrl: url, me: r.user, brand: r.user.brand });
  },

  async refreshMe() {
    const me = await api<Me>('/me');
    await kv.set('me', me);
    set({ me, brand: me.brand });
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
