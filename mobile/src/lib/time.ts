import { getLocale } from '../i18n';

/** Fechas y horas. Las fechas son 'YYYY-MM-DD' (hora local del dispositivo), las horas 'HH:MM'. */

export function pad(n: number): string {
  return String(n).padStart(2, '0');
}

export function fmtDate(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function parseDate(s: string): Date {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d);
}

export function today(now: Date = new Date()): string {
  return fmtDate(now);
}

export function addDays(s: string, n: number): string {
  const d = parseDate(s);
  d.setDate(d.getDate() + n);
  return fmtDate(d);
}

/** Día en que empieza la semana de la compañía (0 = domingo, 1 = lunes), según sus reglas; la fija la sesión al cargar el perfil. */
let weekStartDow = 0;
export function setWeekStart(n: number | null | undefined): void {
  weekStartDow = n === 1 ? 1 : 0;
}
export function getWeekStart(): number {
  return weekStartDow;
}

/** La semana dura siete días desde el día que elige la compañía (igual que el servidor). */
export function weekEnding(s: string): string {
  const d = parseDate(s);
  d.setDate(d.getDate() + ((((weekStartDow + 6) % 7) - d.getDay() + 7) % 7));
  return fmtDate(d);
}

export function weekStarting(s: string): string {
  return addDays(weekEnding(s), -6);
}

export function weekDays(s: string): string[] {
  const start = weekStarting(s);
  return Array.from({ length: 7 }, (_, i) => addDays(start, i));
}

export function monthGrid(s: string): (string | null)[][] {
  const d = parseDate(s);
  const first = new Date(d.getFullYear(), d.getMonth(), 1);
  const last = new Date(d.getFullYear(), d.getMonth() + 1, 0);
  const lead = (first.getDay() - weekStartDow + 7) % 7; // el primer día de la semana de la compañía va primero
  const cells: (string | null)[] = Array(lead).fill(null);
  for (let day = 1; day <= last.getDate(); day++) cells.push(fmtDate(new Date(d.getFullYear(), d.getMonth(), day)));
  while (cells.length % 7) cells.push(null);
  const rows: (string | null)[][] = [];
  for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
  return rows;
}

const DOW = {
  es: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
  en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
};
const MONTHS = {
  es: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
  en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
};

/** 'lun 21/10' en español, 'Mon 10/21' en inglés. */
export function dayLabel(s: string): string {
  const d = parseDate(s);
  const l = getLocale();
  const md = l === 'es' ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}` : `${pad(d.getMonth() + 1)}/${pad(d.getDate())}`;
  return `${DOW[l][d.getDay()]} ${md}`;
}

/** 'mié 21 de octubre de 2026' / 'Wed, October 21, 2026'. */
export function longDate(s: string): string {
  const d = parseDate(s);
  const l = getLocale();
  if (l === 'es') return `${DOW.es[d.getDay()]} ${d.getDate()} de ${MONTHS.es[d.getMonth()]} de ${d.getFullYear()}`;
  return `${DOW.en[d.getDay()]}, ${MONTHS.en[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
}

export function monthLabel(s: string): string {
  const d = parseDate(s);
  return `${MONTHS[getLocale()][d.getMonth()]} ${d.getFullYear()}`;
}

export function isValidHM(t: string | null | undefined): t is string {
  return typeof t === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(t);
}

export function minutes(t: string): number {
  const [h, m] = t.split(':').map(Number);
  return h * 60 + m;
}

export function nowHM(now: Date = new Date()): string {
  return `${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

export function roundTo(v: number, inc: number): number {
  return Math.round((Math.round(v / inc) * inc) * 100) / 100;
}

/** Horas entre dos horas 'HH:MM' del mismo día, redondeadas al incremento. 0 si el rango no es válido. */
export function hoursBetween(start: string, end: string, inc = 0.25): number {
  const m = minutes(end) - minutes(start);
  return m > 0 ? roundTo(m / 60, inc) : 0;
}

/** 8.5 -> '08:30' */
export function hoursToHM(h: number): string {
  const total = Math.round(h * 60);
  return `${pad(Math.floor(total / 60))}:${pad(total % 60)}`;
}

export function fmtHours(h: number, decimal: boolean): string {
  return decimal ? h.toFixed(2) : hoursToHM(h);
}

export function to12h(t: string): string {
  const [h, m] = t.split(':').map(Number);
  return `${h % 12 === 0 ? 12 : h % 12}:${pad(m)} ${h < 12 ? 'AM' : 'PM'}`;
}
