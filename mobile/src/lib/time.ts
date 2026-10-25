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

/** La semana termina el domingo (igual que el servidor). */
export function weekEnding(s: string): string {
  const d = parseDate(s);
  const fromMonday = (d.getDay() + 6) % 7;
  d.setDate(d.getDate() + (6 - fromMonday));
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
  const lead = (first.getDay() + 6) % 7; // lunes primero
  const cells: (string | null)[] = Array(lead).fill(null);
  for (let day = 1; day <= last.getDate(); day++) cells.push(fmtDate(new Date(d.getFullYear(), d.getMonth(), day)));
  while (cells.length % 7) cells.push(null);
  const rows: (string | null)[][] = [];
  for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
  return rows;
}

const DOW = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

export function dayLabel(s: string): string {
  const d = parseDate(s);
  return `${DOW[d.getDay()]} ${pad(d.getDate())}/${pad(d.getMonth() + 1)}`;
}

export function longDate(s: string): string {
  const d = parseDate(s);
  return `${DOW[d.getDay()]} ${d.getDate()} de ${MONTHS[d.getMonth()]} de ${d.getFullYear()}`;
}

export function monthLabel(s: string): string {
  const d = parseDate(s);
  return `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
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
