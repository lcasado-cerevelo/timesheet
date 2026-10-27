import { create } from 'zustand';
import { t } from '../i18n';
import { api, ApiError, NetworkError } from '../lib/api';
import { computeHours, EntryInput, validateEntry } from '../lib/rules';
import { effectiveMode, resolveService } from '../lib/services';
import { resolveTask } from '../lib/tasks';
import { kv } from '../lib/storage';
import { applySyncResults, buildSyncPayload, mergeServer, pendingCount } from '../lib/sync';
import { addDays, today, weekEnding, weekStarting } from '../lib/time';
import type { Entry, Project, ServerEntry, Sheet, SyncResult } from '../lib/types';
import { uuid } from '../lib/uuid';
import { useAuth } from './auth';

const KEY = 'entries.v1';

interface Persisted {
  entries: Entry[];
  projects: Project[];
  lockedWeeks: string[];
  /** Días aprobados dentro de una semana enviada por partes. */
  lockedDays: string[];
  lastSync: string | null;
}

interface EntriesState extends Persisted {
  loaded: boolean;
  selectedDate: string;
  sheets: Record<string, Sheet>;
  syncing: boolean;
  lastError: string | null;
  load: () => Promise<void>;
  setDate: (d: string) => void;
  save: (input: EntryInput & { note?: string; billable?: boolean }, uuidToEdit?: string) => { errors: string[]; entry?: Entry };
  remove: (uuid: string) => void;
  discard: (uuid: string) => void;
  retry: (uuid: string) => void;
  sync: () => Promise<void>;
  fetchSheet: (date: string) => Promise<Sheet | null>;
  submitWeek: (date: string) => Promise<Sheet>;
  pending: () => number;
  reset: () => Promise<void>;
}

export const useEntries = create<EntriesState>((set, get) => {
  const persist = () => {
    const { entries, projects, lockedWeeks, lockedDays, lastSync } = get();
    void kv.set(KEY, { entries, projects, lockedWeeks, lockedDays, lastSync } satisfies Persisted);
  };
  const update = (patch: Partial<EntriesState>) => {
    set(patch);
    persist();
  };

  return {
    loaded: false,
    entries: [],
    projects: [],
    lockedWeeks: [],
    lockedDays: [],
    lastSync: null,
    selectedDate: today(),
    sheets: {},
    syncing: false,
    lastError: null,

    async load() {
      const p = await kv.get<Persisted>(KEY, { entries: [], projects: [], lockedWeeks: [], lockedDays: [], lastSync: null });
      set({ ...p, lockedDays: p.lockedDays ?? [], loaded: true });
    },

    setDate: (d) => set({ selectedDate: d }),

    save(input, uuidToEdit) {
      const me = useAuth.getState().me;
      if (!me) return { errors: [t('common.sign_in_first')] };
      const { entries, projects, lockedWeeks, lockedDays } = get();
      const project = projects.find((p) => p.id === input.project_id);
      const prev = uuidToEdit ? entries.find((e) => e.uuid === uuidToEdit) : undefined;
      const sameDay = entries.filter((e) => e.work_date === input.work_date && e.uuid !== uuidToEdit);
      // El servicio indicado; si no se indicó, el que ya tenía la entrada; y si el proyecto tiene uno solo, ese.
      const keep = prev && prev.project_id === input.project_id ? prev.service_id ?? null : null;
      const service = resolveService(project, input.service_id ?? keep, keep);
      const withService: EntryInput = { ...input, service_id: service?.id ?? input.service_id ?? null };
      const errors = validateEntry(withService, {
        pref: me.capture_mode, keepServiceId: keep, project, rules: me.rules, sameDay, today: today(), lockedWeeks: new Set(lockedWeeks), lockedDays: new Set(lockedDays),
      });
      if (errors.length) return { errors };

      // La tarea es opcional: la indicada (null = ninguna) o, si no se indicó, la que ya tenía la entrada en este mismo proyecto.
      const keepTask = prev && prev.project_id === input.project_id ? prev.task_id ?? null : null;
      const task = resolveTask(project, input.task_id === undefined ? keepTask : input.task_id, keepTask);
      const mode = effectiveMode(project, service?.id ?? null, me.capture_mode);
      const hours = computeHours(input, mode, me.rules.increment);
      const entry: Entry = {
        uuid: prev?.uuid ?? uuid(),
        id: prev?.id,
        project_id: input.project_id as number,
        service_id: service?.id ?? null,
        task_id: task?.id ?? null,
        work_date: input.work_date,
        start_time: mode === 'full_day' ? null : input.start_time ?? null,
        end_time: mode === 'full_day' || input.running ? null : input.end_time ?? null,
        hours,
        note: input.note ?? prev?.note ?? '',
        billable: input.billable ?? prev?.billable ?? service?.billable ?? project?.billable ?? true,
        running: !!input.running,
        sync: 'dirty',
      };
      update({ entries: prev ? entries.map((e) => (e.uuid === prev.uuid ? entry : e)) : [...entries, entry] });
      void get().sync();
      return { errors: [], entry };
    },

    remove(id) {
      const { entries } = get();
      const e = entries.find((x) => x.uuid === id);
      if (!e) return;
      // Lo que nunca llegó al servidor se quita sin más; lo demás se marca para borrarse allá.
      update({ entries: e.id === undefined && e.sync !== 'synced' ? entries.filter((x) => x.uuid !== id) : entries.map((x) => (x.uuid === id ? { ...x, sync: 'deleted' as const } : x)) });
      void get().sync();
    },

    discard(id) {
      // Descartar una rechazada: se pierde el cambio local y la próxima sincronización trae la versión del servidor.
      update({ entries: get().entries.filter((x) => x.uuid !== id) });
      void get().sync();
    },

    retry(id) {
      update({ entries: get().entries.map((x) => (x.uuid === id ? { ...x, sync: 'dirty' as const, reject_reason: undefined } : x)) });
      void get().sync();
    },

    async sync() {
      const me = useAuth.getState().me;
      if (!me || !useAuth.getState().token || get().syncing) return;
      set({ syncing: true, lastError: null });
      try {
        const payload = buildSyncPayload(get().entries);
        if (payload.length) {
          const r = await api<{ results: SyncResult[] }>('/sync', { method: 'POST', body: { entries: payload } });
          set({ entries: applySyncResults(get().entries, r.results) });
          // Si el servidor dijo que una semana está bloqueada, la recordamos para avisar antes de teclear.
          const locked = r.results.filter((x) => x.status === 'locked').map((x) => get().entries.find((e) => e.uuid === x.client_uuid)?.work_date).filter(Boolean) as string[];
          if (locked.length) {
            set({ lockedDays: [...new Set([...get().lockedDays, ...locked])] });
            // El servidor dice si fue la semana entera (hoja aprobada) o solo esos días (partes): se lee la hoja de cada semana.
            for (const w of new Set(locked.map(weekEnding))) void get().fetchSheet(w);
          }
        }
        const to = today();
        const from = weekStarting(addDays(weekEnding(to), -7 * me.rules.weeks_back));
        const [server, projects] = await Promise.all([
          api<{ entries: ServerEntry[] }>(`/entries?from=${from}&to=${to}`),
          api<Project[]>('/projects'),
        ]);
        update({ entries: mergeServer(get().entries, server.entries, from, to), projects, lastSync: new Date().toISOString() });
      } catch (e) {
        set({ lastError: e instanceof NetworkError ? t('entries.offline') : (e as Error).message });
        persist();
      } finally {
        set({ syncing: false });
      }
    },

    async fetchSheet(date) {
      try {
        const s = await api<Sheet>('/sheets/week?date=' + date);
        const locked = new Set(get().lockedWeeks);
        if (s.locked) locked.add(s.week_ending);
        else locked.delete(s.week_ending);
        // Los días de esa semana: los aprobados se bloquean, los demás se liberan (el servidor manda el estado de cada día).
        const days = new Set(get().lockedDays);
        for (const d of s.days ?? []) {
          if (d.locked) days.add(d.date);
          else days.delete(d.date);
        }
        if (!s.days) for (let i = 0; i < 7; i++) days.delete(addDays(weekStarting(s.week_ending), i));
        update({ sheets: { ...get().sheets, [s.week_ending]: s }, lockedWeeks: [...locked], lockedDays: [...days] });
        return s;
      } catch {
        return get().sheets[weekEnding(date)] ?? null;
      }
    },

    async submitWeek(date) {
      await get().sync();
      const end = weekEnding(date);
      const start = weekStarting(date);
      const bad = get().entries.filter((e) => e.work_date >= start && e.work_date <= end && (e.sync === 'rejected' || e.sync === 'dirty' || e.sync === 'deleted'));
      if (bad.length) throw new ApiError(bad.some((e) => e.sync === 'rejected') ? t('entries.has_rejected') : t('entries.has_unsynced'), 422);
      const sheet = await get().fetchSheet(date);
      if (!sheet) throw new NetworkError();
      const s = await api<Sheet>(`/sheets/${sheet.id}/submit`, { method: 'POST' });
      update({ sheets: { ...get().sheets, [s.week_ending]: s } });
      return s;
    },

    pending: () => pendingCount(get().entries),

    async reset() {
      await kv.del(KEY);
      set({ entries: [], projects: [], lockedWeeks: [], lockedDays: [], lastSync: null, sheets: {}, loaded: true });
    },
  };
});
