/**
 * Acceso según la regla "Recordar por (días)" (remember_days) y la conexión.
 * - remember_days > 0 (o desconocido): se reutiliza el token guardado; nada cambia.
 * - remember_days = 0: en cada apertura en frío se pide la contraseña. Con señal, login. Sin señal, y si ya hubo
 *   una sesión en este teléfono (hay datos guardados), MODO LIMITADO: solo anotar horas.
 * - Con la huella activada la sesión guardada se reutiliza aunque la regla sea 0: la huella reemplaza a la contraseña
 *   y la sesión dura hasta el tope máximo de las Reglas.
 */
export type BootDecision = 'resume' | 'login' | 'limited';

export function bootDecision(i: { rememberDays: number | null | undefined; online: boolean; hasToken: boolean; hasSavedSession: boolean; biometric?: boolean }): BootDecision {
  if (i.rememberDays === 0 && i.biometric && i.hasToken) return 'resume';
  if (i.rememberDays === 0) {
    if (i.online) return 'login';
    return i.hasSavedSession ? 'limited' : 'login';
  }
  return i.hasToken ? 'resume' : 'login';
}

/** Pestañas visibles en modo limitado: solo el Día. */
export const LIMITED_TABS = ['index'] as const;
export const ALL_TABS = ['index', 'week', 'month', 'reports', 'settings'] as const;
export type TabName = (typeof ALL_TABS)[number];

export function tabAllowed(tab: string, limited: boolean): boolean {
  return !limited || (LIMITED_TABS as readonly string[]).includes(tab);
}

/** Acciones de la pantalla Día. Las que leen historial no se permiten en modo limitado. */
export type DayAction = 'check_in' | 'check_out' | 'add_entry' | 'add_hours' | 'change_project' | 'day_note' | 'repeat' | 'copy_prev' | 'navigate_days';
const LIMITED_ACTIONS: DayAction[] = ['check_in', 'check_out', 'add_entry', 'add_hours', 'change_project', 'day_note', 'navigate_days'];

export function dayActionAllowed(action: DayAction, limited: boolean): boolean {
  return !limited || LIMITED_ACTIONS.includes(action);
}

/** En modo limitado solo se ven las entradas que se anotan ahora (pendientes de enviar), no el historial. */
export function visibleEntries<T extends { sync: string }>(entries: T[], limited: boolean): T[] {
  return limited ? entries.filter((e) => e.sync === 'dirty') : entries;
}
