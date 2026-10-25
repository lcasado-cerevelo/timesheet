import { addDays, hoursBetween, isValidHM, minutes, weekEnding } from './time';
import type { CaptureMode, Entry, Project, Rules } from './types';

export interface EntryInput {
  project_id: number | null;
  work_date: string;
  start_time?: string | null;
  end_time?: string | null;
  hours?: number | null;
  running?: boolean;
}

export interface RuleContext {
  mode: CaptureMode;
  project: Project | undefined;
  rules: Pick<Rules, 'increment' | 'max_day_hours' | 'weeks_back'>;
  /** Otras entradas de la persona ese mismo día (sin la que se edita). */
  sameDay: Entry[];
  today: string;
  /** Semanas que ya se sabe que están aprobadas ('YYYY-MM-DD' del domingo). */
  lockedWeeks?: Set<string>;
}

/** Normaliza una entrada según el modo y devuelve las horas que le tocan. */
export function computeHours(input: EntryInput, mode: CaptureMode, inc: number): number {
  if (mode === 'full_day') return Math.round((input.hours ?? 0) * 100) / 100;
  if (input.running) return 0;
  if (!input.start_time || !input.end_time) return 0;
  return hoursBetween(input.start_time, input.end_time, inc);
}

/**
 * Las mismas reglas que aplica el servidor, para avisar en el momento y sin conexión.
 * El servidor sigue siendo quien decide: aquí solo se evita mandar lo que sabemos que va a fallar.
 */
export function validateEntry(input: EntryInput, ctx: RuleContext): string[] {
  const errors: string[] = [];
  const { mode, project, rules } = ctx;

  if (!project || !project.active) errors.push('Elige un proyecto.');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input.work_date)) {
    errors.push('Fecha inválida.');
    return errors;
  }
  if (input.work_date > ctx.today) errors.push('No se pueden registrar horas en fechas futuras.');
  const oldest = addDays(weekEnding(ctx.today), -7 * rules.weeks_back);
  if (weekEnding(input.work_date) < oldest) errors.push('Esa fecha queda más atrás de lo que se permite llenar.');
  if (ctx.lockedWeeks?.has(weekEnding(input.work_date))) errors.push('Esa semana ya fue aprobada y no se puede cambiar.');

  if (mode === 'full_day') {
    if (input.start_time || input.end_time || input.running) errors.push('Registras el día completo; no lleva hora desde ni hasta.');
    if (project?.invoice_detail === 'with_times') errors.push('Ese proyecto exige hora desde y hasta; tu cuenta registra el día completo.');
    if (!input.hours || input.hours <= 0) errors.push('Escribe las horas del día.');
  } else {
    if (!isValidHM(input.start_time)) errors.push('Hora de inicio inválida.');
    if (!input.running) {
      if (!isValidHM(input.end_time)) errors.push('Hora de fin inválida.');
      else if (isValidHM(input.start_time)) {
        if (minutes(input.end_time) <= minutes(input.start_time)) errors.push('La hora de fin debe ser después de la de inicio.');
        else if (hoursBetween(input.start_time, input.end_time, rules.increment) <= 0) errors.push('La duración es demasiado corta.');
        else {
          const s = minutes(input.start_time);
          const e = minutes(input.end_time);
          const clash = ctx.sameDay.some(
            (o) => o.sync !== 'deleted' && !o.running && o.start_time && o.end_time && minutes(o.start_time) < e && minutes(o.end_time) > s,
          );
          if (clash) errors.push('Se cruza con otra entrada del mismo día.');
        }
      }
    }
  }

  const hours = computeHours(input, mode, rules.increment);
  const others = ctx.sameDay.filter((o) => o.sync !== 'deleted').reduce((a, o) => a + o.hours, 0);
  if (others + hours > rules.max_day_hours) errors.push(`Pasa del máximo de horas por día (${rules.max_day_hours}).`);
  return errors;
}
