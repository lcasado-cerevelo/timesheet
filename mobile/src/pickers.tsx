import React from 'react';
import { Pressable, Text } from 'react-native';
import { useT } from './i18n';
import { useEntries } from './store/entries';
import { C, Sheet } from './ui';

/** La lista de proyectos asignados (la misma que sale al cambiar de proyecto). */
export function ProjectSheet(props: { visible: boolean; title?: string; currentId?: number | null; onPick: (id: number) => void; onClose: () => void }) {
  const { t } = useT();
  const projects = useEntries((s) => s.projects).filter((p) => p.active);
  return (
    <Sheet visible={props.visible} title={props.title ?? t('day.choose_project')} onClose={props.onClose}>
      {projects.map((p) => (
        <Pressable key={p.id} testID={`pick-${p.code}`} onPress={() => props.onPick(p.id)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
          <Text style={{ fontSize: 16, fontWeight: props.currentId === p.id ? '800' : '600' }}>{p.code} · {p.name}{props.currentId === p.id ? '  ✓' : ''}</Text>
        </Pressable>
      ))}
      {projects.length === 0 && <Text style={{ color: C.muted }}>{t('day.no_projects')}</Text>}
    </Sheet>
  );
}
