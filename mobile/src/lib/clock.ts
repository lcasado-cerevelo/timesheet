import { fmtDate, hoursBetween, nowHM, pad, roundTo } from './time';

/** El reloj es una hora de inicio guardada, no un proceso que corre: sobrevive al cierre de la app. */
export interface ClockState {
  entry_uuid: string;
  project_id: number;
  /** Servicio del tramo (las relojes viejos no lo tienen). */
  service_id?: number | null;
  /** Tarea del tramo, opcional (los relojes viejos no la tienen). */
  task_id?: number | null;
  /** ISO con zona; de aquí se calcula todo. */
  started_at: string;
  note: string;
}

export function elapsedMs(c: ClockState, now: Date = new Date()): number {
  return Math.max(0, now.getTime() - new Date(c.started_at).getTime());
}

export function fmtElapsed(ms: number): string {
  const s = Math.floor(ms / 1000);
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
}

export interface Segment {
  work_date: string;
  start_time: string;
  end_time: string;
  hours: number;
}

/**
 * Convierte el reloj en entradas al pararlo. Si cruzó la medianoche se parte en un tramo por día
 * (hasta 23:59 y desde 00:00), porque el servidor registra cada entrada en un solo día.
 * Los tramos de menos de un incremento se descartan, igual que haría el servidor.
 */
export function segmentsFor(c: ClockState, end: Date, inc = 0.25, forcedMinutes?: number): Segment[] {
  // Entrada del mínimo registrada con permiso: un solo tramo con esa duración exacta.
  if (forcedMinutes) {
    const s = new Date(c.started_at);
    return [{ work_date: fmtDate(s), start_time: nowHM(s), end_time: fmtDate(end) === fmtDate(s) ? nowHM(end) : '23:59', hours: roundTo(forcedMinutes / 60, inc) }];
  }
  const start = new Date(c.started_at);
  const out: Segment[] = [];
  let cursor = start;
  while (fmtDate(cursor) !== fmtDate(end) && cursor < end) {
    const day = fmtDate(cursor);
    pushSegment(out, day, nowHM(cursor), '23:59', inc);
    cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1, 0, 0, 0);
  }
  if (cursor <= end) pushSegment(out, fmtDate(end), nowHM(cursor), nowHM(end), inc);
  return out;
}

function pushSegment(out: Segment[], day: string, s: string, e: string, inc: number): void {
  const hours = hoursBetween(s, e, inc);
  if (hours > 0) out.push({ work_date: day, start_time: s, end_time: e, hours: roundTo(hours, inc) });
}
