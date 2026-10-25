import React, { useEffect } from 'react';
import { Platform } from 'react-native';
import { Stack, useRouter, useSegments } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import NetInfo from '@react-native-community/netinfo';
import * as Notifications from 'expo-notifications';
import '../src/lib/geofence';
import { useT } from '../src/i18n';
import { setupNotifications, setWeeklyReminder } from '../src/lib/notify';
import { useAuth } from '../src/store/auth';
import { useClock } from '../src/store/clock';
import { useEntries } from '../src/store/entries';
import { reconcileOwner } from '../src/store/session';
import { useSelection } from '../src/store/selection';
import { useSettings } from '../src/store/settings';

export default function Root() {
  const { ready, token, brand, init, me } = useAuth();
  const segments = useSegments();
  const router = useRouter();
  const { t, locale } = useT();

  useEffect(() => {
    void (async () => {
      await Promise.all([init(), useEntries.getState().load(), useClock.getState().load(), useSelection.getState().load(), useSettings.getState().load()]);
    })();
  }, [init]);

  // Los textos de las notificaciones (botones, canales, recordatorio) siguen el idioma elegido.
  useEffect(() => {
    if (!ready) return;
    void setupNotifications();
    if (useSettings.getState().weeklyReminder) void setWeeklyReminder(true);
  }, [ready, locale]);

  // Cuando hay una persona con sesión: se separan los datos de otra persona y se sincroniza.
  useEffect(() => {
    if (!ready || !token || !me) return;
    void reconcileOwner(me.id).then(() => useEntries.getState().sync());
  }, [ready, token, me?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  // Al recuperar la conexión se sincroniza solo.
  useEffect(() => {
    const unsub = NetInfo.addEventListener((st) => {
      if (st.isConnected && st.isInternetReachable !== false) void useEntries.getState().sync();
    });
    return unsub;
  }, []);

  // Botones de las notificaciones: "Parar" el reloj y "Prender reloj" al llegar a un lugar.
  useEffect(() => {
    if (Platform.OS === 'web') return;
    const handle = (r: Notifications.NotificationResponse) => {
      const action = r.actionIdentifier;
      const data: any = r.notification.request.content.data ?? {};
      if (data.kind === 'clock' && action === 'stop') useClock.getState().stop();
      if (data.kind === 'arrive' && action === 'start') useClock.getState().start(Number(data.project_id));
    };
    const sub = Notifications.addNotificationResponseReceivedListener(handle);
    void Notifications.getLastNotificationResponseAsync().then((r) => r && handle(r));
    return () => sub.remove();
  }, []);

  useEffect(() => {
    if (!ready) return;
    const inLogin = segments[0] === 'login';
    if (!token && !inLogin) router.replace('/login');
    if (token && inLogin) router.replace('/');
  }, [ready, token, segments, router]);

  return (
    <>
      <StatusBar style="light" backgroundColor={brand.color_primary} />
      <Stack screenOptions={{ headerStyle: { backgroundColor: brand.color_primary }, headerTintColor: '#fff', headerTitleStyle: { fontWeight: '700' } }}>
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="login" options={{ headerShown: false }} />
        <Stack.Screen name="entry" options={{ presentation: 'modal', title: t('entry.title') }} />
      </Stack>
    </>
  );
}
