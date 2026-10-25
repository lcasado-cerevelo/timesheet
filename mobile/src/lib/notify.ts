import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { t } from '../i18n';
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
      name: t('notify.channel_clock'),
      importance: Notifications.AndroidImportance.LOW, // fija y sin sonido
      lockscreenVisibility: Notifications.AndroidNotificationVisibility.PUBLIC,
    });
    await Notifications.setNotificationChannelAsync('avisos', { name: t('notify.channel_alerts'), importance: Notifications.AndroidImportance.DEFAULT });
  }
  await Notifications.setNotificationCategoryAsync('clock', [
    { identifier: 'stop', buttonTitle: t('notify.stop'), options: { opensAppToForeground: false } },
  ]);
  await Notifications.setNotificationCategoryAsync('arrive', [
    { identifier: 'start', buttonTitle: t('notify.start_clock'), options: { opensAppToForeground: true } },
    { identifier: 'dismiss', buttonTitle: t('notify.not_now'), options: { opensAppToForeground: false } },
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
        title: t('notify.running'),
        body: t('notify.running_body', { project: projectLabel, time: to12h(nowHM(startedAt)) }),
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
      title: t('notify.arrived', { place: label }),
      body: t('notify.arrived_body'),
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
    content: { title: t('notify.weekly_title'), body: t('notify.weekly_body'), data: { kind: 'weekly' } },
    trigger: { type: Notifications.SchedulableTriggerInputTypes.WEEKLY, weekday: 6, hour: 16, minute: 0, channelId: 'avisos' },
  });
}
