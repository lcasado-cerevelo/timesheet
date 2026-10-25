import type { Entry, ServerEntry, SyncResult } from './types';

/** Lo que se manda al servidor: entradas por enviar y borradas, con su client_uuid (reenviar es seguro). */
export function buildSyncPayload(local: Entry[]): Record<string, unknown>[] {
  return local
    .filter((e) => (e.sync === 'dirty' || e.sync === 'deleted') && !e.running)
    .map((e) =>
      e.sync === 'deleted'
        ? { client_uuid: e.uuid, deleted: true }
        : {
            client_uuid: e.uuid,
            project_id: e.project_id,
            work_date: e.work_date,
            start_time: e.start_time,
            end_time: e.end_time,
            hours: e.start_time ? undefined : e.hours,
            note: e.note,
            billable: e.billable,
          },
    );
}

/** Aplica la respuesta del servidor: ok queda sincronizada; locked o invalid quedan rechazadas con el motivo. */
export function applySyncResults(local: Entry[], results: SyncResult[]): Entry[] {
  const byUuid = new Map(results.map((r) => [r.client_uuid, r]));
  const out: Entry[] = [];
  for (const e of local) {
    const r = byUuid.get(e.uuid);
    if (!r) {
      out.push(e);
      continue;
    }
    if (r.status === 'ok') {
      if (e.sync === 'deleted') continue; // ya se borró en el servidor
      out.push({ ...e, id: r.id ?? e.id, sync: 'synced', reject_reason: undefined });
    } else {
      const reason = r.status === 'locked' ? 'La hoja de esa semana ya fue aprobada.' : firstError(r) ?? 'El servidor no aceptó esta entrada.';
      out.push({ ...e, sync: 'rejected', reject_reason: reason });
    }
  }
  return out;
}

function firstError(r: SyncResult): string | undefined {
  const all = r.errors ? Object.values(r.errors).flat() : [];
  return all[0] ?? r.message;
}

/**
 * Mezcla lo que dice el servidor dentro de la ventana [from, to] con lo local:
 *  - lo pendiente (dirty, deleted, rejected) se conserva tal cual;
 *  - lo sincronizado se reemplaza por la versión del servidor;
 *  - lo sincronizado que el servidor ya no tiene se quita (se borró en otro lado).
 */
export function mergeServer(local: Entry[], server: ServerEntry[], from: string, to: string): Entry[] {
  const pending = local.filter((e) => e.sync !== 'synced');
  const pendingUuids = new Set(pending.map((e) => e.uuid));
  const outside = local.filter((e) => e.sync === 'synced' && (e.work_date < from || e.work_date > to));
  const fromServer: Entry[] = server
    .filter((s) => !s.running && !(s.client_uuid && pendingUuids.has(s.client_uuid)))
    .map((s) => ({
      uuid: s.client_uuid ?? `srv-${s.id}`,
      id: s.id,
      project_id: s.project_id,
      work_date: s.work_date,
      start_time: s.start_time,
      end_time: s.end_time,
      hours: s.hours,
      note: s.note ?? '',
      billable: s.billable,
      running: false,
      sync: 'synced' as const,
    }));
  return [...outside, ...pending, ...fromServer].sort(
    (a, b) => a.work_date.localeCompare(b.work_date) || (a.start_time ?? '').localeCompare(b.start_time ?? ''),
  );
}

export function pendingCount(local: Entry[]): number {
  return local.filter((e) => e.sync === 'dirty' || e.sync === 'deleted').length;
}
