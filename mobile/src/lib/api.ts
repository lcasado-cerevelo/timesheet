export class ApiError extends Error {
  constructor(message: string, public status: number, public errors?: Record<string, string[]>) {
    super(message);
  }
}

export class NetworkError extends Error {
  constructor() {
    super('Sin conexión con el servidor.');
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
      headers: { Accept: 'application/json', ...(opts.body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...authHeaders() },
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
    });
  } catch {
    throw new NetworkError();
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && cfg.token) {
    cfg.onUnauthorized?.();
    throw new ApiError('La sesión venció. Entra de nuevo.', 401);
  }
  if (!res.ok) {
    const first = data?.errors ? (Object.values(data.errors).flat() as string[])[0] : undefined;
    throw new ApiError(first ?? data?.message ?? `Error ${res.status}`, res.status, data?.errors);
  }
  return data as T;
}
