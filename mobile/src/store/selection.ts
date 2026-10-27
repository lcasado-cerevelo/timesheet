import { create } from 'zustand';
import { activeServices, effectiveMode, resolveService } from '../lib/services';
import { pushRecent } from '../lib/projectSearch';
import { activeTasks } from '../lib/tasks';
import { kv } from '../lib/storage';
import type { CaptureMode, Project, Service, Task } from '../lib/types';
import { useAuth } from './auth';
import { useEntries } from './entries';

const PROJECT_KEY = 'lastProject.v1';
const SERVICE_KEY = 'lastService.v1';
const TASK_KEY = 'lastTask.v1';
const RECENT_KEY = 'recentProjects.v1';

interface SelectionState {
  loaded: boolean;
  projectId: number | null;
  /** Servicio recordado por proyecto. */
  services: Record<string, number>;
  /** Tarea recordada por proyecto (sin entrada = sin tarea). */
  tasks: Record<string, number>;
  /** Últimos proyectos usados (hasta 5), el más reciente primero. */
  recent: number[];
  load: () => Promise<void>;
  /** remember=false: se recuerda el proyecto escogido pero no cuenta como reciente (flechas de la pantalla Día). */
  setProject: (id: number, remember?: boolean) => void;
  setService: (projectId: number, serviceId: number) => void;
  /** null = sin tarea. */
  setTask: (projectId: number, taskId: number | null) => void;
  reset: () => void;
}

/** El proyecto y el servicio que la persona escogió por última vez; se recuerdan entre aperturas. */
export const useSelection = create<SelectionState>((set, get) => ({
  loaded: false,
  projectId: null,
  services: {},
  tasks: {},
  recent: [],
  async load() {
    const [projectId, services, tasks, recent] = await Promise.all([kv.get<number | null>(PROJECT_KEY, null), kv.get<Record<string, number>>(SERVICE_KEY, {}), kv.get<Record<string, number>>(TASK_KEY, {}), kv.get<number[]>(RECENT_KEY, [])]);
    set({ projectId, services, tasks, recent, loaded: true });
  },
  setProject(id, remember = true) {
    const recent = remember ? pushRecent(get().recent, id) : get().recent;
    set({ projectId: id, recent });
    void kv.set(PROJECT_KEY, id);
    if (remember) void kv.set(RECENT_KEY, recent);
  },
  setService(projectId, serviceId) {
    const services = { ...get().services, [String(projectId)]: serviceId };
    set({ services });
    void kv.set(SERVICE_KEY, services);
  },
  setTask(projectId, taskId) {
    const tasks = { ...get().tasks };
    if (taskId) tasks[String(projectId)] = taskId;
    else delete tasks[String(projectId)];
    set({ tasks });
    void kv.set(TASK_KEY, tasks);
  },
  reset() {
    set({ projectId: null, services: {}, tasks: {}, recent: [] });
    void kv.del(TASK_KEY);
    void kv.del(RECENT_KEY);
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
  /** Tareas activas del proyecto. Vacío = el proyecto no usa tareas y la pantalla no muestra nada. */
  tasks: Task[];
  /** Tarea escogida (opcional); null = sin tarea. */
  task: Task | null;
  mode: CaptureMode;
}

/** Calcula el proyecto, los servicios y el modo vigentes a partir de lo recordado y de lo que haya en caché. */
export function computeCurrent(projects: Project[], projectId: number | null, remembered: Record<string, number>, pref: CaptureMode, rememberedTasks: Record<string, number> = {}): Current {
  const active = projects.filter((p) => p.active);
  const project = active.find((p) => p.id === projectId) ?? active[0];
  const services = activeServices(project);
  const service = resolveService(project, project ? remembered[String(project.id)] : null) ?? resolveService(project, null);
  const tasks = activeTasks(project);
  const task = (project && tasks.find((x) => x.id === rememberedTasks[String(project.id)])) || null;
  return { projects: active, project, services, service, tasks, task, mode: effectiveMode(project, service?.id ?? null, pref) };
}

export function useCurrent(): Current {
  const projects = useEntries((s) => s.projects);
  const projectId = useSelection((s) => s.projectId);
  const remembered = useSelection((s) => s.services);
  const rememberedTasks = useSelection((s) => s.tasks);
  const pref = useAuth((s) => s.me?.capture_mode) ?? 'from_to';
  return computeCurrent(projects, projectId, remembered, pref, rememberedTasks);
}
