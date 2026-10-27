import type { Project, Task } from './types';

/** Tareas activas del proyecto, en el orden del servidor. Vacío = el proyecto no usa tareas: no se muestra nada ni se exige. */
export function activeTasks(project: Project | undefined): Task[] {
  return (project?.tasks ?? []).filter((x) => x.active).sort((a, b) => a.sort - b.sort || a.name.localeCompare(b.name));
}

/** La tarea de una entrada: debe ser del proyecto y estar activa (una desactivada solo vale si la entrada ya la tenía). null = sin tarea. */
export function resolveTask(project: Project | undefined, taskId: number | null | undefined, keepId?: number | null): Task | null {
  if (!project || !taskId) return null;
  const x = (project.tasks ?? []).find((t) => t.id === taskId);
  return x && (x.active || x.id === keepId) ? x : null;
}

export function taskName(project: Project | undefined, taskId: number | null | undefined): string | undefined {
  return taskId ? (project?.tasks ?? []).find((t) => t.id === taskId)?.name : undefined;
}
