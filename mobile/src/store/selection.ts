import { create } from 'zustand';
import { activeServices, effectiveMode, resolveService } from '../lib/services';
import { kv } from '../lib/storage';
import type { CaptureMode, Project, Service } from '../lib/types';
import { useAuth } from './auth';
import { useEntries } from './entries';

const PROJECT_KEY = 'lastProject.v1';
const SERVICE_KEY = 'lastService.v1';

interface SelectionState {
  loaded: boolean;
  projectId: number | null;
  /** Servicio recordado por proyecto. */
  services: Record<string, number>;
  load: () => Promise<void>;
  setProject: (id: number) => void;
  setService: (projectId: number, serviceId: number) => void;
  reset: () => void;
}

/** El proyecto y el servicio que la persona escogió por última vez; se recuerdan entre aperturas. */
export const useSelection = create<SelectionState>((set, get) => ({
  loaded: false,
  projectId: null,
  services: {},
  async load() {
    const [projectId, services] = await Promise.all([kv.get<number | null>(PROJECT_KEY, null), kv.get<Record<string, number>>(SERVICE_KEY, {})]);
    set({ projectId, services, loaded: true });
  },
  setProject(id) {
    set({ projectId: id });
    void kv.set(PROJECT_KEY, id);
  },
  setService(projectId, serviceId) {
    const services = { ...get().services, [String(projectId)]: serviceId };
    set({ services });
    void kv.set(SERVICE_KEY, services);
  },
  reset() {
    set({ projectId: null, services: {} });
    void kv.del(PROJECT_KEY);
    void kv.del(SERVICE_KEY);
  },
}));

export interface Current {
  projects: Project[];
  project: Project | undefined;
  services: Service[];
  /** Servicio escogido, o el único activo. undefined = hay que escoger. */
  service: Service | undefined;
  mode: CaptureMode;
}

/** Calcula el proyecto, los servicios y el modo vigentes a partir de lo recordado y de lo que haya en caché. */
export function computeCurrent(projects: Project[], projectId: number | null, remembered: Record<string, number>, pref: CaptureMode): Current {
  const active = projects.filter((p) => p.active);
  const project = active.find((p) => p.id === projectId) ?? active[0];
  const services = activeServices(project);
  const service = resolveService(project, project ? remembered[String(project.id)] : null) ?? resolveService(project, null);
  return { projects: active, project, services, service, mode: effectiveMode(project, service?.id ?? null, pref) };
}

export function useCurrent(): Current {
  const projects = useEntries((s) => s.projects);
  const projectId = useSelection((s) => s.projectId);
  const remembered = useSelection((s) => s.services);
  const pref = useAuth((s) => s.me?.capture_mode) ?? 'from_to';
  return computeCurrent(projects, projectId, remembered, pref);
}
