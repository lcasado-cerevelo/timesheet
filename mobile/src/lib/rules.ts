import { t } from '../i18n';
import { needsService, resolveService, serviceMode } from './services';
import { addDays, hoursBetween, isValidHM, minutes, weekEnding } from './time';
import type { CaptureMode, Entry, Project, Rules } from './types';

export interface EntryInput {
  project_id: number | null;
  service_id?: number | null;
  work_date: string;
  start_time?: string | null;
  end_time?: string | null;
  hours?: number | null;
  running?: boolean;
}

export interface RuleContext {
  /** Preferencia de la persona (la fija la gerencia); solo cuenta en los servicios que permiten ambas formas. */
  pref: CaptureMode;
  /** Servicio que ya tenía la entrada que se edita (aunque ahora esté desactivado). */
  keepServiceId?: number | null;
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
 * Las mismas reglas que aplica el servidor (el modo efectivo sale del servicio, no de la persona), para avisar en el momento y sin conexión.
 * El servidor sigue siendo quien decide: aquí solo se evita mandar lo que sabemos que va a fallar.
 */
export function validateEntry(input: EntryInput, ctx: RuleContext): string[] {
  const errors: string[] = [];
  const { project, rules } = ctx;
  const service = resolveService(project, input.service_id, ctx.keepServiceId);
  const mode: CaptureMode = service ? serviceMode(service, ctx.pref) : ctx.pref;

  if (!project || !project.active) errors.push(t('rules.pick_project'));
  else if (needsService(project) && !service) errors.push(input.service_id ? t('rules.service_invalid') : t('rules.service_required'));
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input.work_date)) {
    errors.push(t('rules.bad_date'));
    return errors;
  }
  if (input.work_date > ctx.today) errors.push(t('rules.future'));
  const oldest = addDays(weekEnding(ctx.today), -7 * rules.weeks_back);
  if (weekEnding(input.work_date) < oldest) errors.push(t('rules.too_old'));
  if (ctx.lockedWeeks?.has(weekEnding(input.work_date))) errors.push(t('rules.locked_week'));

  if (mode === 'full_day') {
    if (input.start_time || input.end_time || input.running) errors.push(t('rules.total_no_times'));
    if (project?.invoice_detail === 'with_times') errors.push(t('rules.project_needs_times'));
    if (!input.hours || input.hours <= 0) errors.push(t('rules.write_hours'));
  } else {
    if (!isValidHM(input.start_time)) errors.push(t('rules.bad_start'));
    if (!input.running) {
      if (!isValidHM(input.end_time)) errors.push(t('rules.bad_end'));
      else if (isValidHM(input.start_time)) {
        if (minutes(input.end_time) <= minutes(input.start_time)) errors.push(t('rules.end_after_start'));
        else if (hoursBetween(input.start_time, input.end_time, rules.increment) <= 0) errors.push(t('rules.too_short'));
        else {
          const s = minutes(input.start_time);
          const e = minutes(input.end_time);
          const clash = ctx.sameDay.some(
            (o) => o.sync !== 'deleted' && !o.running && o.start_time && o.end_time && minutes(o.start_time) < e && minutes(o.end_time) > s,
          );
          if (clash) errors.push(t('rules.overlap'));
        }
      }
    }
  }

  const hours = computeHours(input, mode, rules.increment);
  const others = ctx.sameDay.filter((o) => o.sync !== 'deleted').reduce((a, o) => a + o.hours, 0);
  if (others + hours > rules.max_day_hours) errors.push(t('rules.max_day', { n: rules.max_day_hours }));
  return errors;
}
