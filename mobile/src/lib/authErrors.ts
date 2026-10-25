import { ApiError } from './api';
import { t } from '../i18n';

/** Mensaje claro para un fallo al entrar (POST /login). */
export function loginErrorMessage(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.status === 403 && e.code === 'approver_web_only') return t('login.approver_web_only');
    if (e.status === 401 || e.status === 422) return e.message && !/^Error \d+$/.test(e.message) ? e.message : t('login.bad_credentials');
    if (e.status === 429) return t('login.too_many');
    return e.message || t('login.failed');
  }
  return (e as any)?.message ?? t('login.failed');
}

export const MIN_PASSWORD = 10;

export interface PasswordForm {
  current: string;
  next: string;
}

/** Validación local antes de enviar. Devuelve el mensaje por campo (vacío = todo bien). */
export function validatePasswordChange(f: PasswordForm): { current?: string; next?: string } {
  const out: { current?: string; next?: string } = {};
  if (!f.current) out.current = t('password.current_required');
  if (f.next.length < MIN_PASSWORD) out.next = t('password.too_short', { n: MIN_PASSWORD });
  else if (f.next === f.current) out.next = t('password.same');
  return out;
}

/** Errores del servidor (422) repartidos por campo; lo demás va como mensaje general. */
export function passwordServerErrors(e: unknown): { current?: string; next?: string; general?: string } {
  if (e instanceof ApiError && e.errors && (e.errors.current_password || e.errors.password)) {
    return { current: e.errors.current_password?.[0], next: e.errors.password?.[0] };
  }
  return { general: (e as any)?.message ?? t('password.failed') };
}
