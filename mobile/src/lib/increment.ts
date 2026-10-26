import { isValidHM, minutes, pad } from './time';

/**
 * Reglas de incremento y mínimo (regla de la compañía: rules.increment en horas y rules.minimum_minutes).
 * El incremento puede ser 0.25 (15 min), 0.5, 1, 0.1, 0.05 o 0.01. Nada se redondea en silencio: lo que no
 * está alineado se avisa y se bloquea.
 */

const EPS = 1e-6;

/** ¿El valor (en horas) es múltiplo exacto del incremento? */
export function hoursAligned(hours: number, inc: number): boolean {
  if (!(inc > 0)) return true;
  const q = hours / inc;
  return Math.abs(q - Math.round(q)) < EPS;
}

/** ¿Estos minutos del día (0..1440) caen en un múltiplo del incremento? */
export function minutesAligned(totalMinutes: number, inc: number): boolean {
  return hoursAligned(totalMinutes / 60, inc);
}

/**
 * Paso de los minutos en el reloj: el menor número de minutos enteros alineado con el incremento.
 * 0.25 -> 15, 0.5 -> 30, 1 -> 60, 0.1 -> 6, 0.05 -> 3, 0.01 -> 3.
 */
export function stepMinutes(inc: number): number {
  for (let k = 1; k <= 60; k++) if (minutesAligned(k, inc)) return k;
  return 60;
}

/** Duración mínima de una entrada, en minutos: la de la regla o, si no viene, un incremento. */
export function minimumMinutes(rules: { increment: number; minimum_minutes?: number | null }): number {
  const m = rules.minimum_minutes;
  if (typeof m === 'number' && m > 0) return m;
  return Math.max(1, Math.ceil(rules.increment * 60 - EPS));
}

/** Redondea una hora 'HH:MM' al múltiplo del incremento más cercano (sin pasar de las 23:xx). */
export function roundHM(hm: string, inc: number): string {
  if (!isValidHM(hm)) return hm;
  const step = stepMinutes(inc);
  let total = Math.round(minutes(hm) / step) * step;
  if (total >= 1440) total = Math.floor(1439 / step) * step;
  return `${pad(Math.floor(total / 60))}:${pad(total % 60)}`;
}

/** La hora actual redondeada al múltiplo del incremento más cercano. */
export function roundedNowHM(inc: number, now: Date = new Date()): string {
  return roundHM(`${pad(now.getHours())}:${pad(now.getMinutes())}`, inc);
}

/**
 * ¿La hora 'HH:MM' está alineada? 23:59 se acepta como fin de día: es como el reloj parte una entrada
 * que cruza la medianoche.
 */
export function hmAligned(hm: string, inc: number, allowEndOfDay = false): boolean {
  if (!isValidHM(hm)) return false;
  if (allowEndOfDay && hm === '23:59') return true;
  return minutesAligned(minutes(hm), inc);
}

export type StopCheck = { kind: 'before' } | { kind: 'below'; elapsed: number; minimum: number } | { kind: 'ok'; elapsed: number };

/** Revisa el check-out: antes de la entrada, por debajo del mínimo (hay que pedir permiso) o bien. */
export function checkStop(startedAt: Date, at: Date, minimum: number): StopCheck {
  const elapsed = Math.round((at.getTime() - startedAt.getTime()) / 60000);
  if (at.getTime() < startedAt.getTime()) return { kind: 'before' };
  if (elapsed < minimum) return { kind: 'below', elapsed, minimum };
  return { kind: 'ok', elapsed };
}
