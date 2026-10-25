import type { CaptureMode, Project, Service } from './types';

/** Servicios activos del proyecto, en el orden del servidor. */
export function activeServices(project: Project | undefined): Service[] {
  return (project?.services ?? []).filter((s) => s.active).sort((a, b) => a.sort - b.sort || a.id - b.id);
}

/**
 * Cómo entra las horas esta persona en este servicio (la misma regla del servidor):
 * detalle -> desde/hasta, total -> total del día, ambos -> manda la preferencia de la persona.
 */
export function serviceMode(service: Pick<Service, 'capture_mode' | 'mode'>, pref: CaptureMode): CaptureMode {
  if (service.capture_mode === 'detail') return 'from_to';
  if (service.capture_mode === 'total') return 'full_day';
  if (service.capture_mode === 'both') return pref === 'full_day' ? 'full_day' : 'from_to';
  return service.mode ?? pref;
}

/**
 * El servicio de una entrada: el indicado (si existe en el proyecto; uno desactivado solo vale si ya lo tenía la entrada)
 * o, si no se indicó, el único servicio activo. undefined = hay que escoger (o el proyecto no trae servicios).
 */
export function resolveService(project: Project | undefined, serviceId: number | null | undefined, keepId?: number | null): Service | undefined {
  if (!project) return undefined;
  if (serviceId) {
    const s = (project.services ?? []).find((x) => x.id === serviceId);
    return s && (s.active || s.id === keepId) ? s : undefined;
  }
  const active = activeServices(project);
  return active.length === 1 ? active[0] : undefined;
}

/** El proyecto trae servicios activos: entonces el servicio es obligatorio en cada entrada. */
export function needsService(project: Project | undefined): boolean {
  return activeServices(project).length > 0;
}

/** Modo efectivo para un proyecto y servicio. Proyectos sin servicios en caché (versión vieja) usan la preferencia. */
export function effectiveMode(project: Project | undefined, serviceId: number | null | undefined, pref: CaptureMode): CaptureMode {
  const s = resolveService(project, serviceId, serviceId);
  return s ? serviceMode(s, pref) : pref;
}
