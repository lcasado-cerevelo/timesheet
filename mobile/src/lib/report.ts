import { weekEnding } from './time';
import type { Entry, Project } from './types';

export type Group = 'project' | 'day' | 'week';

export interface ReportRow {
  label: string;
  hours: number;
  billable: number;
  nonBillable: number;
}

/** Reportes básicos sobre lo que hay en el teléfono (funcionan sin conexión). */
export function summarize(entries: Entry[], projects: Project[], from: string, to: string, group: Group) {
  const byProject = new Map(projects.map((p) => [p.id, p]));
  const rows = new Map<string, ReportRow>();
  let total = 0;
  let billable = 0;
  for (const e of entries) {
    if (e.sync === 'deleted' || e.running || e.work_date < from || e.work_date > to) continue;
    const key =
      group === 'day' ? e.work_date : group === 'week' ? weekEnding(e.work_date) : `${byProject.get(e.project_id)?.code ?? '?'} · ${byProject.get(e.project_id)?.name ?? ''}`;
    const r = rows.get(key) ?? { label: key, hours: 0, billable: 0, nonBillable: 0 };
    r.hours += e.hours;
    if (e.billable) r.billable += e.hours;
    else r.nonBillable += e.hours;
    rows.set(key, r);
    total += e.hours;
    if (e.billable) billable += e.hours;
  }
  const round = (n: number) => Math.round(n * 100) / 100;
  return {
    rows: [...rows.values()].map((r) => ({ ...r, hours: round(r.hours), billable: round(r.billable), nonBillable: round(r.nonBillable) })).sort((a, b) => a.label.localeCompare(b.label)),
    total: round(total),
    billable: round(billable),
  };
}

export function dayTotals(entries: Entry[]): Map<string, number> {
  const m = new Map<string, number>();
  for (const e of entries) {
    if (e.sync === 'deleted' || e.running) continue;
    m.set(e.work_date, Math.round(((m.get(e.work_date) ?? 0) + e.hours) * 100) / 100);
  }
  return m;
}
