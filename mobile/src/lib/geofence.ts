import { Platform } from 'react-native';
import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import { pickRegions } from './geo';
import { showArrivePrompt } from './notify';
import { kv } from './storage';
import type { Project } from './types';

export const GEOFENCE_TASK = 'tiempo-geofence';

/** Debe estar definida al cargar la app: el sistema la despierta en segundo plano al entrar a un lugar. */
if (Platform.OS !== 'web') {
  TaskManager.defineTask(GEOFENCE_TASK, async ({ data, error }: any) => {
    if (error || !data) return;
    if (data.eventType === Location.GeofencingEventType.Enter) {
      const projectId = Number(data.region?.identifier);
      const saved = await kv.get<{ projects: Project[] }>('entries.v1', { projects: [] } as any);
      const p = saved.projects.find((x) => x.id === projectId);
      // Si ya hay un reloj corriendo no se molesta a la persona.
      const clock = await kv.get<unknown>('clock.v1', null);
      if (p && !clock) await showArrivePrompt(p.id, p.name);
    }
  });
}

export async function startWatching(projects: Project[]): Promise<{ ok: boolean; message?: string; count: number }> {
  if (Platform.OS === 'web') return { ok: false, message: 'Los lugares de trabajo solo funcionan en el teléfono.', count: 0 };
  const fg = await Location.requestForegroundPermissionsAsync();
  if (fg.status !== 'granted') return { ok: false, message: 'Hace falta el permiso de ubicación.', count: 0 };
  const bg = await Location.requestBackgroundPermissionsAsync();
  if (bg.status !== 'granted') return { ok: false, message: 'Para avisarte con la app cerrada, permite la ubicación “Siempre”.', count: 0 };
  let here: { latitude: number; longitude: number } | null = null;
  try {
    const p = await Location.getLastKnownPositionAsync();
    here = p ? { latitude: p.coords.latitude, longitude: p.coords.longitude } : null;
  } catch {
    here = null;
  }
  const regions = pickRegions(projects, here, 20);
  if (!regions.length) {
    await stopWatching();
    return { ok: false, message: 'Ningún proyecto tiene un lugar de trabajo definido todavía.', count: 0 };
  }
  await Location.startGeofencingAsync(GEOFENCE_TASK, regions);
  return { ok: true, count: regions.length };
}

export async function stopWatching(): Promise<void> {
  if (Platform.OS === 'web') return;
  try {
    if (await Location.hasStartedGeofencingAsync(GEOFENCE_TASK)) await Location.stopGeofencingAsync(GEOFENCE_TASK);
  } catch {
    /* nada */
  }
}
