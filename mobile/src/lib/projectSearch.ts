import type { Project } from './types';

/** Texto sin tildes ni mayúsculas, para comparar búsquedas. */
export function fold(s: string | null | undefined): string {
  return (s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

export const MAX_RECENT = 5;

/** Agrega un proyecto al inicio de los recientes (sin repetir, máximo 5). */
export function pushRecent(recent: number[], id: number): number[] {
  return [id, ...recent.filter((x) => x !== id)].slice(0, MAX_RECENT);
}

export interface ProjectHit {
  project: Project;
  recent: boolean;
}

/** ¿El proyecto coincide con lo escrito? Busca en código, nombre y cliente (si el payload lo trae). */
export function matchesProject(p: Project, query: string): boolean {
  const q = fold(query);
  if (!q) return true;
  return [p.code, p.name, p.customer?.name].some((v) => fold(v).includes(q));
}

/**
 * Proyectos activos que coinciden con la búsqueda: primero los recientes (en el orden en que se usaron),
 * luego el resto por código. Sin texto devuelve todos.
 */
export function searchProjects(projects: Project[], query: string, recentIds: number[]): ProjectHit[] {
  const found = projects.filter((p) => p.active && matchesProject(p, query));
  const rank = (id: number) => recentIds.indexOf(id);
  const recent = found.filter((p) => rank(p.id) >= 0).sort((a, b) => rank(a.id) - rank(b.id));
  const rest = found.filter((p) => rank(p.id) < 0).sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true, sensitivity: 'base' }));
  return [...recent.map((project) => ({ project, recent: true })), ...rest.map((project) => ({ project, recent: false }))];
}
