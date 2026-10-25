import React from 'react';
import { Tabs } from 'expo-router';
import { Text } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useT } from '../../src/i18n';
import { tabAllowed } from '../../src/lib/access';
import { useAuth } from '../../src/store/auth';
import { useTheme } from '../../src/ui';

const icon = (glyph: string) => ({ color }: { color: string }) => <Text style={{ fontSize: 18, color }}>{glyph}</Text>;

export default function TabsLayout() {
  const th = useTheme();
  const { t } = useT();
  const insets = useSafeAreaInsets();
  const limited = useAuth((s) => s.limited);
  // Modo limitado: solo la pestaña Día (sin semana, mes, reportes ni ajustes) y sin barra de pestañas.
  const hide = (name: string) => (tabAllowed(name, limited) ? {} : { href: null });
  // La barra de pestañas se levanta lo que ocupa la barra de navegación del teléfono (gestos o botones).
  const bottom = Math.max(insets.bottom, 6);
  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: th.primary },
        headerTintColor: '#fff',
        headerTitleStyle: { fontWeight: '700' },
        tabBarActiveTintColor: th.primary,
        tabBarLabelStyle: { fontWeight: '700', fontSize: 11 },
        tabBarStyle: limited ? { display: 'none' as const } : { height: 52 + bottom, paddingBottom: bottom, paddingTop: 4 },
      }}
    >
      <Tabs.Screen name="index" options={{ title: t('tabs.day'), tabBarIcon: icon('▤') }} />
      <Tabs.Screen name="week" options={{ title: t('tabs.week'), tabBarIcon: icon('▥'), ...hide('week') }} />
      <Tabs.Screen name="month" options={{ title: t('tabs.month'), tabBarIcon: icon('▦'), ...hide('month') }} />
      <Tabs.Screen name="reports" options={{ title: t('tabs.reports'), tabBarIcon: icon('≣'), ...hide('reports') }} />
      <Tabs.Screen name="settings" options={{ title: t('tabs.settings'), tabBarIcon: icon('⚙'), ...hide('settings') }} />
    </Tabs>
  );
}
