import React, { useEffect, useMemo, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useT } from './i18n';
import { searchProjects } from './lib/projectSearch';
import { useEntries } from './store/entries';
import { useSelection } from './store/selection';
import { C, Sheet } from './ui';

/**
 * La lista de proyectos asignados con buscador: se escribe y se filtra por código, nombre o cliente del proyecto
 * (sin tildes ni mayúsculas). Primero los recientes, luego el resto por código. Funciona con los proyectos en caché.
 */
export function ProjectSheet(props: { visible: boolean; title?: string; currentId?: number | null; onPick: (id: number) => void; onClose: () => void }) {
  const { t } = useT();
  const projects = useEntries((s) => s.projects);
  const recent = useSelection((s) => s.recent);
  const [q, setQ] = useState('');
  useEffect(() => {
    if (props.visible) setQ('');
  }, [props.visible]);
  const hits = useMemo(() => searchProjects(projects, q, recent), [projects, q, recent]);
  const hasAny = projects.some((p) => p.active);

  return (
    <Sheet visible={props.visible} title={props.title ?? t('day.choose_project')} onClose={props.onClose}>
      {hasAny ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: '#c9ced6', borderRadius: 8, paddingHorizontal: 10, marginBottom: 6 }}>
          <TextInput
            testID="project-search"
            accessibilityLabel={t('search.label')}
            placeholder={t('search.placeholder')}
            value={q}
            onChangeText={setQ}
            autoCorrect={false}
            autoCapitalize="none"
            style={{ flex: 1, height: 42, fontSize: 16 }}
          />
          {q ? (
            <Pressable testID="project-search-clear" accessibilityRole="button" accessibilityLabel={t('search.clear')} onPress={() => setQ('')} hitSlop={8} style={{ paddingLeft: 8 }}>
              <Text style={{ fontSize: 18, color: C.muted }}>✕</Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}
      {hits.map(({ project: p, recent: isRecent }) => (
        <Pressable key={p.id} testID={`pick-${p.code}`} onPress={() => props.onPick(p.id)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
          <Text style={{ fontSize: 16, fontWeight: props.currentId === p.id ? '800' : '600' }}>
            {p.code} · {p.name}{props.currentId === p.id ? '  ✓' : ''}
          </Text>
          {p.customer?.name || isRecent ? (
            <Text style={{ fontSize: 12, color: C.muted, marginTop: 2 }}>{[p.customer?.name, isRecent ? t('search.recent') : null].filter(Boolean).join(' · ')}</Text>
          ) : null}
        </Pressable>
      ))}
      {!hasAny && <Text style={{ color: C.muted }}>{t('day.no_projects')}</Text>}
      {hasAny && hits.length === 0 && (
        <View testID="project-search-empty">
          <Text style={{ color: C.muted, marginVertical: 10 }}>{t('search.empty')}</Text>
        </View>
      )}
    </Sheet>
  );
}
