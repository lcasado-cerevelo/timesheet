import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { to12h } from './time';
import { nowHM } from './time';

/**
 * Notificaciones locales (no usan servidor de push):
 *  - reloj corriendo: notificación fija con el botón "Parar", como un reproductor de música;
 *  - llegada a un lugar de trabajo: "Estás en X. ¿Prendo el reloj?";
 *  - recordatorio semanal de enviar la hoja.
 */
export const CLOCK_ID = 'clock-running';
export const CHANNEL = 'reloj';

export async function setupNotifications(): Promise<void> {
  if (Platform.OS === 'web') return;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowAlert: true, shouldPlaySound: false, shouldSetBadge: false }),
  });
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync(CHANNEL, {
      name: 'Reloj de trabajo',
      importance: Notifications.AndroidImportance.LOW, // fija y sin sonido
      lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
    });
    await Notifications.setNotificationChannelAsync('avisos', { name: 'Avisos', importance: Notifications.AndroidImportance.DEFAULT });
  }
  await Notifications.setNotificationCategoryAsync('clock', [
    { identifier: 'stop', buttonTitle: 'Parar', options: { opensAppToForeground: false } },
  ]);
  await Notifications.setNotificationCategoryAsync('arrive', [
    { identifier: 'start', buttonTitle: 'Prender reloj', options: { opensAppToForeground: true } },
    { identifier: 'dismiss', buttonTitle: 'Ahora no', options: { opensAppToForeground: false } },
  ]);
}

export async function ensureNotificationPermission(): Promise<boolean> {
  if (Platform.OS === 'web') return false;
  const cur = await Notifications.getPermissionsAsync();
  if (cur.granted) return true;
  const req = await Notifications.requestPermissionsAsync();
  return req.granted;
}

export async function showClockNotification(projectLabel: string, startedAt: Date): Promise<void> {
  if (Platform.OS === 'web') return;
  try {
    await Notifications.dismissNotificationAsync(CLOCK_ID).catch(() => undefined);
    await Notifications.scheduleNotificationAsync({
      identifier: CLOCK_ID,
      content: {
        title: 'Reloj corriendo',
        body: `${projectLabel} · desde ${to12h(nowHM(startedAt))}`,
        categoryIdentifier: 'clock',
        sticky: true,
        autoDismiss: false,
        priority: Notifications.AndroidNotificationPriority.LOW,
        data: { kind: 'clock' },
      },
      trigger: Platform.OS === 'android' ? { channelId: CHANNEL } : null,
    });
  } catch {
    /* sin permiso de notificaciones: el reloj sigue funcionando dentro de la app */
  }
}

export async function cancelClockNotification(): Promise<void> {
  if (Platform.OS === 'web') return;
  try {
    await Notifications.dismissNotificationAsync(CLOCK_ID);
    await Notifications.cancelScheduledNotificationAsync(CLOCK_ID);
  } catch {
    /* nada */
  }
}

export async function showArrivePrompt(projectId: number, label: string): Promise<void> {
  if (Platform.OS === 'web') return;
  await Notifications.scheduleNotificationAsync({
    content: {
      title: `Estás en ${label}`,
      body: '¿Prendo el reloj para empezar a contar?',
      categoryIdentifier: 'arrive',
      data: { kind: 'arrive', project_id: projectId },
    },
    trigger: Platform.OS === 'android' ? { channelId: 'avisos' } : null,
  });
}

/** Recordatorio semanal (viernes 4:00 p. m.) de revisar y enviar la hoja. */
export async function setWeeklyReminder(on: boolean): Promise<void> {
  if (Platform.OS === 'web') return;
  await Notifications.cancelScheduledNotificationAsync('weekly-reminder').catch(() => undefined);
  if (!on) return;
  await Notifications.scheduleNotificationAsync({
    identifier: 'weekly-reminder',
    content: { title: 'Hora de enviar tu hoja', body: 'Revisa tus horas de la semana y envíala a aprobación.', data: { kind: 'weekly' } },
    trigger: { type: Notifications.SchedulableTriggerInputTypes.WEEKLY, weekday: 6, hour: 16, minute: 0, channelId: 'avisos' },
  });
}
