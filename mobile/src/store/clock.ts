import { create } from 'zustand';
import { ClockState, segmentsFor } from '../lib/clock';
import { kv } from '../lib/storage';
import { uuid } from '../lib/uuid';
import { cancelClockNotification, showClockNotification } from '../lib/notify';
import { useAuth } from './auth';
import { useEntries } from './entries';

const KEY = 'clock.v1';

interface ClockStore {
  clock: ClockState | null;
  load: () => Promise<void>;
  start: (projectId: number, note?: string, at?: Date) => string | null;
  /** Para el reloj. Devuelve un mensaje de error (y deja el reloj corriendo) o null si salió bien. */
  stop: (at?: Date) => string | null;
  /** Cambia de proyecto en un solo paso: cierra el tramo actual y abre el nuevo. */
  switchTo: (projectId: number) => string | null;
}

export const useClock = create<ClockStore>((set, get) => ({
  clock: null,

  async load() {
    set({ clock: await kv.get<ClockState | null>(KEY, null) });
  },

  start(projectId, note = '', at = new Date()) {
    const me = useAuth.getState().me;
    if (!me) return 'Entra primero.';
    if (me.capture_mode !== 'from_to') return 'Tu cuenta registra el día completo; el reloj no aplica.';
    if (get().clock) return 'Ya hay un reloj corriendo.';
    const project = useEntries.getState().projects.find((p) => p.id === projectId);
    if (!project || !project.active) return 'Elige un proyecto.';
    const clock: ClockState = { entry_uuid: uuid(), project_id: projectId, started_at: at.toISOString(), note };
    set({ clock });
    void kv.set(KEY, clock);
    void showClockNotification(project.code + ' · ' + project.name, at);
    return null;
  },

  stop(at = new Date()) {
    const me = useAuth.getState().me;
    const clock = get().clock;
    if (!me || !clock) return 'No hay un reloj corriendo.';
    const segments = segmentsFor(clock, at, me.rules.increment);
    const entries = useEntries.getState();
    // Se validan todos los tramos antes de guardar ninguno: o entra todo o no entra nada.
    const saved: string[] = [];
    for (const [i, s] of segments.entries()) {
      const r = entries.save({
        project_id: clock.project_id, work_date: s.work_date, start_time: s.start_time, end_time: s.end_time,
        note: clock.note,
      });
      if (r.errors.length) {
        for (const id of saved) useEntries.getState().discard(id);
        return `No se pudo guardar el tramo ${i + 1}: ${r.errors[0]}`;
      }
      saved.push(r.entry!.uuid);
    }
    set({ clock: null });
    void kv.del(KEY);
    void cancelClockNotification();
    return null;
  },

  switchTo(projectId) {
    const clock = get().clock;
    if (clock?.project_id === projectId) return null;
    if (clock) {
      const err = get().stop();
      if (err) return err;
    }
    return get().start(projectId);
  },
}));

