import { getLocale, t } from '../i18n';

export class ApiError extends Error {
  constructor(message: string, public status: number, public errors?: Record<string, string[]>, public code?: string, public minimumMinutes?: number) {
    super(message);
  }
}

export class NetworkError extends Error {
  constructor() {
    super(t('api.network'));
  }
}

export interface ApiConfig {
  baseUrl: string;
  token: string | null;
  onUnauthorized?: () => void;
}

let cfg: ApiConfig = { baseUrl: '', token: null };

export function configureApi(c: Partial<ApiConfig>): void {
  cfg = { ...cfg, ...c };
}

export function apiUrl(path: string): string {
  return cfg.baseUrl.replace(/\/+$/, '') + '/api' + path;
}

export function authHeaders(): Record<string, string> {
  return cfg.token ? { Authorization: 'Bearer ' + cfg.token } : {};
}

export async function api<T = any>(path: string, opts: { method?: string; body?: unknown } = {}): Promise<T> {
  let res: Response;
  try {
    res = await fetch(apiUrl(path), {
      method: opts.method ?? 'GET',
      headers: { Accept: 'application/json', 'X-Locale': getLocale(), ...(opts.body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...authHeaders() },
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
    });
  } catch {
    throw new NetworkError();
  }
  // Una respuesta que no es JSON (por ejemplo una página HTML de una dirección equivocada) no es válida,
  // aunque el código sea 200: se avisa en vez de seguir con datos vacíos.
  const text = await res.text();
  let data: any = {};
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    if (res.ok) throw new ApiError(t('api.unexpected'), res.status);
  }
  if (res.status === 401 && cfg.token) {
    cfg.onUnauthorized?.();
    throw new ApiError(t('api.expired'), 401, undefined, data?.code ?? 'session_expired');
  }
  if (!res.ok) {
    // El servidor rechaza un cierre de reloj por debajo del mínimo: se muestra igual que la validación local.
    if (res.status === 422 && data?.code === 'below_minimum' && data?.minimum_minutes) {
      throw new ApiError(t('rules.below_min', { m: data.minimum_minutes }), 422, data?.errors, 'below_minimum', data.minimum_minutes);
    }
    const first = data?.errors ? (Object.values(data.errors).flat() as string[])[0] : undefined;
    throw new ApiError(first ?? data?.message ?? t('api.error', { n: res.status }), res.status, data?.errors, data?.code);
  }
  return data as T;
}
