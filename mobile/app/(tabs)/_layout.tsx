import React from 'react';
import { Tabs } from 'expo-router';
import { Text } from 'react-native';
import { useTheme } from '../../src/ui';

const icon = (glyph: string) => ({ color }: { color: string }) => <Text style={{ fontSize: 18, color }}>{glyph}</Text>;

export default function TabsLayout() {
  const t = useTheme();
  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: t.primary },
        headerTintColor: '#fff',
        headerTitleStyle: { fontWeight: '700' },
        tabBarActiveTintColor: t.primary,
        tabBarLabelStyle: { fontWeight: '700', fontSize: 11 },
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Día', tabBarIcon: icon('▤') }} />
      <Tabs.Screen name="week" options={{ title: 'Semana', tabBarIcon: icon('▥') }} />
      <Tabs.Screen name="month" options={{ title: 'Mes', tabBarIcon: icon('▦') }} />
      <Tabs.Screen name="reports" options={{ title: 'Reportes', tabBarIcon: icon('≣') }} />
      <Tabs.Screen name="settings" options={{ title: 'Ajustes', tabBarIcon: icon('⚙') }} />
    </Tabs>
  );
}
