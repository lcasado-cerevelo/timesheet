import { create } from 'zustand';
import { t } from '../i18n';
import { ClockState, segmentsFor } from '../lib/clock';
import { effectiveMode, needsService, resolveService } from '../lib/services';
import { kv } from '../lib/storage';
import { uuid } from '../lib/uuid';
import { cancelClockNotification, showClockNotification } from '../lib/notify';
import { useAuth } from './auth';
import { useEntries } from './entries';

const KEY = 'clock.v1';

interface ClockStore {
  clock: ClockState | null;
  load: () => Promise<void>;
  /** Prende el reloj. El servicio es opcional solo si el proyecto no tiene servicios o tiene uno solo (se escoge solo). */
  start: (projectId: number, serviceId?: number | null, note?: string, at?: Date) => string | null;
  /** Para el reloj. Devuelve un mensaje de error (y deja el reloj corriendo) o null si salió bien. */
  stop: (at?: Date) => string | null;
  /** Cambia de proyecto en un solo paso: cierra el tramo actual y abre el nuevo. */
  switchTo: (projectId: number, serviceId?: number | null) => string | null;
}

/** Revisa que se pueda prender el reloj en ese proyecto y servicio. */
function checkStart(projectId: number, serviceId: number | null, at: Date, ignoreRunning: boolean) {
  const me = useAuth.getState().me;
  if (!me) return { error: t('common.sign_in_first') };
  if (!ignoreRunning && useClock.getState().clock) return { error: t('clock.already') };
  if (at.getTime() > Date.now() + 60_000) return { error: t('clock.future') };
  const project = useEntries.getState().projects.find((p) => p.id === projectId);
  if (!project || !project.active) return { error: t('rules.pick_project') };
  const service = resolveService(project, serviceId);
  if (needsService(project) && !service) return { error: t('rules.service_required') };
  // El reloj solo sirve en servicios que se entran por detalle (desde/hasta).
  if (effectiveMode(project, service?.id ?? null, me.capture_mode) !== 'from_to') return { error: t('clock.not_applicable') };
  return { error: null as string | null, project, service };
}

export const useClock = create<ClockStore>((set, get) => ({
  clock: null,

  async load() {
    set({ clock: await kv.get<ClockState | null>(KEY, null) });
  },

  start(projectId, serviceId = null, note = '', at = new Date()) {
    const me = useAuth.getState().me;
    const check = checkStart(projectId, serviceId, at, false);
    if (check.error || !check.project || !me) return check.error ?? t('common.sign_in_first');
    const { project, service } = check;
    const clock: ClockState = { entry_uuid: uuid(), project_id: projectId, service_id: service?.id ?? null, started_at: at.toISOString(), note };
    set({ clock });
    void kv.set(KEY, clock);
    void showClockNotification(project.code + ' · ' + project.name + (service ? ' · ' + service.name : ''), at);
    return null;
  },

  stop(at = new Date()) {
    const me = useAuth.getState().me;
    const clock = get().clock;
    if (!me || !clock) return t('clock.none');
    if (at.getTime() <= new Date(clock.started_at).getTime()) return t('clock.end_before_start');
    if (at.getTime() > Date.now() + 60_000) return t('clock.future');
    const segments = segmentsFor(clock, at, me.rules.increment);
    const entries = useEntries.getState();
    // Se validan todos los tramos antes de guardar ninguno: o entra todo o no entra nada.
    const saved: string[] = [];
    for (const [i, s] of segments.entries()) {
      const r = entries.save({
        project_id: clock.project_id, service_id: clock.service_id ?? null, work_date: s.work_date, start_time: s.start_time, end_time: s.end_time,
        note: clock.note,
      });
      if (r.errors.length) {
        for (const id of saved) useEntries.getState().discard(id);
        return t('clock.segment_failed', { n: i + 1, error: r.errors[0] });
      }
      saved.push(r.entry!.uuid);
    }
    set({ clock: null });
    void kv.del(KEY);
    void cancelClockNotification();
    return null;
  },

  switchTo(projectId, serviceId = null) {
    const clock = get().clock;
    if (clock?.project_id === projectId && (clock.service_id ?? null) === (serviceId ?? clock.service_id ?? null)) return null;
    // Se comprueba que el nuevo reloj pueda arrancar ANTES de parar el actual, para no perder el tramo en curso.
    const check = checkStart(projectId, serviceId, new Date(), true);
    if (check.error) return check.error;
    if (clock) {
      const err = get().stop();
      if (err) return err;
    }
    return get().start(projectId, serviceId);
  },
}));
