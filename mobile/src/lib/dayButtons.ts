export type DayButtonId = 'check-in' | 'check-out' | 'add-entry' | 'add-hours' | 'template' | 'copy-prev' | 'today';

export interface DayButton {
  id: DayButtonId;
  /** Ocupa toda la fila (cuando quedaría solo). */
  wide: boolean;
}

/**
 * La cuadrícula de botones de la pantalla Día, fila por fila:
 *  - Por detalle (from_to):  [Entrar ahora | Salir ahora]  [Agregar entrada | Repetir]  [Copiar día anterior | Ir a hoy]
 *  - Por total (full_day):   [Agregar horas | Repetir]  [Copiar día anterior | Ir a hoy]
 * Si Agregar queda solo en su fila (modo limitado: no hay Repetir), va primero, encima de Entrar ahora.
 * Un botón que queda solo en su fila ocupa todo el ancho.
 */
export function dayButtonRows(o: { fromTo: boolean; repeat: boolean; copyPrev: boolean }): DayButton[][] {
  const add: DayButtonId = o.fromTo ? 'add-entry' : 'add-hours';
  const rows: DayButtonId[][] = [];
  const check: DayButtonId[] = o.fromTo ? ['check-in', 'check-out'] : [];
  const addRow: DayButtonId[] = o.repeat ? [add, 'template'] : [add];
  const last: DayButtonId[] = o.copyPrev ? ['copy-prev', 'today'] : ['today'];
  if (addRow.length === 1) rows.push(addRow, ...(check.length ? [check] : []), last);
  else rows.push(...(check.length ? [check] : []), addRow, last);
  return rows.map((r) => r.map((id) => ({ id, wide: r.length === 1 })));
}

import type { TKey } from '../i18n';

/** Texto de cada botón (clave de traducción). */
export const DAY_BUTTON_LABEL: Record<DayButtonId, TKey> = {
  'check-in': 'day.check_in',
  'check-out': 'day.check_out',
  'add-entry': 'day.add_entry',
  'add-hours': 'day.add_hours',
  template: 'day.repeat',
  'copy-prev': 'day.copy_prev',
  today: 'day.go_today',
};
