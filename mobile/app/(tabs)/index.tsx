import React, { useEffect, useMemo, useState } from 'react';
import { Alert, FlatList, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { elapsedMs, fmtElapsed } from '../../src/lib/clock';
import { kv } from '../../src/lib/storage';
import { addDays, fmtHours, longDate, nowHM, to12h, today } from '../../src/lib/time';
import { useAuth } from '../../src/store/auth';
import { useClock } from '../../src/store/clock';
import { useEntries } from '../../src/store/entries';
import { useSettings } from '../../src/store/settings';
import { api } from '../../src/lib/api';
import { Badge, Btn, C, GridBtn, Msg, Sheet, TimeField, useTheme } from '../../src/ui';
import type { Entry } from '../../src/lib/types';

type Pick = null | 'start' | 'switch';

export default function Day() {
  const router = useRouter();
  const t = useTheme();
  const me = useAuth((s) => s.me);
  const decimal = useSettings((s) => s.decimal);
  const { entries, projects, selectedDate, setDate, remove, retry, discard, save, syncing, lastError } = useEntries();
  const { clock, start, stop, switchTo } = useClock();
  const [pick, setPick] = useState<Pick>(null);
  const [tmpl, setTmpl] = useState(false);
  const [endAt, setEndAt] = useState<string | null>(null);
  const [now, setNow] = useState(Date.now());
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [err, setErr] = useState('');
  const [lastProject, setLastProject] = useState<number | null>(null);

  const mode = me?.capture_mode ?? 'from_to';
  const fromTo = mode === 'from_to';

  useEffect(() => {
    void kv.get<Record<string, string>>('daynotes.v1', {}).then(setNotes);
    void kv.get<number | null>('lastProject.v1', null).then(setLastProject);
  }, []);
  useEffect(() => {
    if (!clock) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [clock]);

  const day = useMemo(
    () => entries.filter((e) => e.work_date === selectedDate && e.sync !== 'deleted').sort((a, b) => (a.start_time ?? '').localeCompare(b.start_time ?? '')),
    [entries, selectedDate],
  );
  const total = day.reduce((a, e) => a + e.hours, 0);
  const project = (id: number) => projects.find((p) => p.id === id);
  const fail = (m: string | null) => setErr(m ?? '');

  const remember = (id: number) => {
    setLastProject(id);
    void kv.set('lastProject.v1', id);
  };

  const startNow = () => {
    const id = lastProject && project(lastProject)?.active ? lastProject : projects.filter((p) => p.active).length === 1 ? projects.find((p) => p.active)!.id : null;
    if (!id) return setPick('start');
    remember(id);
    fail(start(id));
  };

  const recent = useMemo(() => {
    const seen = new Set<string>();
    const out: Entry[] = [];
    for (const e of [...entries].filter((x) => x.sync !== 'deleted').reverse()) {
      const k = `${e.project_id}|${e.start_time}|${e.end_time}|${fromTo ? '' : e.hours}|${e.note}`;
      if (!seen.has(k)) {
        seen.add(k);
        out.push(e);
      }
      if (out.length >= 8) break;
    }
    return out;
  }, [entries, fromTo]);

  const repeat = (e: Entry) => {
    const r = save({ project_id: e.project_id, work_date: selectedDate, start_time: e.start_time, end_time: e.end_time, hours: e.hours, note: e.note });
    if (r.errors.length) Alert.alert('No se pudo repetir', r.errors[0]);
    else setTmpl(false);
  };

  const copyPrevious = () => {
    for (let i = 1; i <= 7; i++) {
      const d = addDays(selectedDate, -i);
      const prev = entries.filter((e) => e.work_date === d && e.sync !== 'deleted');
      if (prev.length) {
        const errors = prev.map((e) => save({ project_id: e.project_id, work_date: selectedDate, start_time: e.start_time, end_time: e.end_time, hours: e.hours, note: e.note }).errors[0]).filter(Boolean);
        return fail(errors[0] ?? null);
      }
    }
    fail('No hay entradas en los 7 días anteriores.');
  };

  const saveNote = (text: string) => {
    const next = { ...notes, [selectedDate]: text };
    setNotes(next);
    void kv.set('daynotes.v1', next);
    api('/day-notes', { method: 'PUT', body: { work_date: selectedDate, note: text } }).catch(() => undefined);
  };

  const endClock = () => {
    if (!endAt) return;
    const [h, m] = endAt.split(':').map(Number);
    const at = new Date();
    at.setHours(h, m, 0, 0);
    fail(stop(at));
    setEndAt(null);
  };

  const confirmDelete = (e: Entry) => {
    const go = () => remove(e.uuid);
    if (Platform.OS === 'web') return go();
    Alert.alert('Borrar entrada', '¿Borrar esta entrada?', [{ text: 'Cancelar' }, { text: 'Borrar', style: 'destructive', onPress: go }]);
  };

  const pickProject = (id: number) => {
    remember(id);
    const action = pick;
    setPick(null);
    fail(action === 'switch' ? switchTo(id) : start(id));
  };

  const clockProject = clock ? project(clock.project_id) : undefined;

  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      <View style={{ backgroundColor: t.primary, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingVertical: 10 }}>
        <Pressable testID="prev" onPress={() => setDate(addDays(selectedDate, -1))} style={{ padding: 8 }}><Text style={{ color: '#fff', fontSize: 22 }}>‹</Text></Pressable>
        <Pressable style={{ flex: 1 }} onPress={() => setDate(today())}><Text testID="date" style={{ color: '#fff', fontSize: 17, fontWeight: '700', textAlign: 'center' }}>{longDate(selectedDate)}</Text></Pressable>
        <Pressable testID="next" onPress={() => setDate(addDays(selectedDate, 1))} style={{ padding: 8 }}><Text style={{ color: '#fff', fontSize: 22 }}>›</Text></Pressable>
      </View>

      {clock && (
        <View testID="running" style={{ backgroundColor: '#e6f4ea', borderBottomWidth: 1, borderColor: '#9bd1ac', padding: 10, flexDirection: 'row', alignItems: 'center' }}>
          <View style={{ flex: 1 }}>
            <Text style={{ fontWeight: '700' }}>{clockProject ? `${clockProject.code} · ${clockProject.name}` : 'Reloj corriendo'}</Text>
            <Text style={{ color: C.muted }}>desde {to12h(nowHM(new Date(clock.started_at)))}</Text>
          </View>
          <Text testID="elapsed" style={{ fontSize: 24, fontWeight: '800', fontVariant: ['tabular-nums'] }}>{fmtElapsed(elapsedMs(clock, new Date(now)))}</Text>
        </View>
      )}

      <View style={{ flexDirection: 'row', flexWrap: 'wrap', borderBottomWidth: 1, borderColor: C.line }}>
        {fromTo ? (
          <>
            <GridBtn testID="check-in" title="ENTRAR AHORA" disabled={!!clock} onPress={startNow} />
            <GridBtn testID="add-entry" title="AGREGAR ENTRADA »" onPress={() => router.push({ pathname: '/entry', params: { date: selectedDate } })} />
            <GridBtn testID="check-out" title="SALIR AHORA" disabled={!clock} onPress={() => fail(stop())} />
            <GridBtn testID="add-out" title="AGREGAR SALIDA »" disabled={!clock} onPress={() => setEndAt(nowHM())} />
            <GridBtn testID="switch" title="CAMBIAR PROYECTO »" onPress={() => setPick(clock ? 'switch' : 'start')} />
            <GridBtn testID="template" title="REPETIR »" onPress={() => setTmpl(true)} />
          </>
        ) : (
          <>
            <GridBtn testID="add-hours" title="AGREGAR HORAS »" onPress={() => router.push({ pathname: '/entry', params: { date: selectedDate } })} />
            <GridBtn testID="copy-prev" title="COPIAR DÍA ANTERIOR" onPress={copyPrevious} />
            <GridBtn testID="template" title="REPETIR »" onPress={() => setTmpl(true)} />
            <GridBtn testID="today" title="IR A HOY" onPress={() => setDate(today())} />
          </>
        )}
      </View>

      <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 12 }}>
        {err ? <Msg kind="err" text={err} /> : null}
        {lastError && !syncing ? <Msg kind="warn" text={lastError} /> : null}
        {day.length === 0 ? (
          <Text style={{ color: '#9ca3af', fontSize: 16 }}>Sin entradas</Text>
        ) : (
          <>
            <View style={{ flexDirection: 'row', paddingBottom: 4 }}>
              {fromTo ? (
                <>
                  <Text style={[hd, { width: 82 }]}>Entrada</Text><Text style={[hd, { width: 82 }]}>Salida</Text><Text style={[hd, { width: 62 }]}>Total</Text>
                </>
              ) : (
                <Text style={[hd, { width: 70 }]}>Total</Text>
              )}
              <Text style={[hd, { flex: 1 }]}>Proyecto</Text>
            </View>
            {day.map((e) => (
              <View key={e.uuid} style={{ borderBottomWidth: 1, borderColor: '#e0e0e0', paddingVertical: 8 }}>
                <Pressable testID="row" onPress={() => router.push({ pathname: '/entry', params: { uuid: e.uuid } })} style={{ flexDirection: 'row', alignItems: 'center' }}>
                  {fromTo ? (
                    <>
                      <Text style={[cell, { width: 82 }]}>{e.running ? '—' : e.start_time ? to12h(e.start_time) : ''}</Text>
                      <Text style={[cell, { width: 82, color: '#0b84c6' }]}>{e.end_time ? to12h(e.end_time) : ''}</Text>
                      <Text style={[cell, { width: 62, color: '#0b84c6' }]}>{fmtHours(e.hours, decimal)}</Text>
                    </>
                  ) : (
                    <Text style={[cell, { width: 70, color: '#0b84c6' }]}>{fmtHours(e.hours, decimal)}</Text>
                  )}
                  <Text style={[cell, { flex: 1, color: C.muted }]} numberOfLines={1}>{project(e.project_id)?.code ?? '?'}{e.note ? ' · ' + e.note : ''}</Text>
                  <Pressable onPress={() => confirmDelete(e)} hitSlop={8} style={{ paddingHorizontal: 6 }}><Text style={{ color: C.danger, fontSize: 18 }}>✕</Text></Pressable>
                </Pressable>
                {e.sync === 'dirty' && <Badge text="Por sincronizar" bg="#fff4d6" color="#7a5200" />}
                {e.sync === 'rejected' && (
                  <View style={{ marginTop: 4 }}>
                    <Msg kind="err" text={e.reject_reason ?? 'Rechazada por el servidor.'} />
                    <View style={{ flexDirection: 'row', gap: 8 }}>
                      <Btn small kind="ghost" title="Reintentar" onPress={() => retry(e.uuid)} />
                      <Btn small kind="danger" title="Descartar cambio" onPress={() => discard(e.uuid)} />
                    </View>
                  </View>
                )}
              </View>
            ))}
            <Text testID="day-total" style={{ fontWeight: '800', fontSize: 16, marginTop: 8 }}>Total del día: {fmtHours(total, decimal)}</Text>
          </>
        )}
      </ScrollView>

      <View style={{ backgroundColor: '#eeee99', margin: 10, borderRadius: 4, paddingHorizontal: 12 }}>
        <TextInput testID="day-note" placeholder="Nota del día" value={notes[selectedDate] ?? ''} onChangeText={saveNote} style={{ height: 44 }} multiline={false} />
      </View>

      <Sheet visible={pick !== null} title="Elegir proyecto" onClose={() => setPick(null)}>
        {projects.filter((p) => p.active).map((p) => (
          <Pressable key={p.id} testID={`pick-${p.code}`} onPress={() => pickProject(p.id)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
            <Text style={{ fontSize: 16, fontWeight: '600' }}>{p.code} · {p.name}</Text>
          </Pressable>
        ))}
        {projects.length === 0 && <Text style={{ color: C.muted }}>No tienes proyectos asignados. Pídele al dueño de la compañía que te asigne alguno.</Text>}
      </Sheet>

      <Sheet visible={tmpl} title="Repetir una entrada anterior" onClose={() => setTmpl(false)}>
        {recent.length === 0 && <Text style={{ color: C.muted }}>Todavía no hay entradas para repetir.</Text>}
        {recent.map((e) => (
          <Pressable key={e.uuid} onPress={() => repeat(e)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
            <Text style={{ fontSize: 16, fontWeight: '600' }}>{project(e.project_id)?.code} · {e.start_time ? `${to12h(e.start_time)} – ${to12h(e.end_time ?? '00:00')}` : fmtHours(e.hours, decimal) + ' h'}</Text>
            {e.note ? <Text style={{ color: C.muted }}>{e.note}</Text> : null}
          </Pressable>
        ))}
      </Sheet>

      <Sheet visible={endAt !== null} title="Hora de salida" onClose={() => setEndAt(null)}>
        <TimeField label="Salí a las" value={endAt} onChange={setEndAt} testID="end-at" />
        <Btn title="Guardar salida" kind="pri" onPress={endClock} />
      </Sheet>
    </View>
  );
}

const hd = { fontSize: 13, fontWeight: '700' as const, color: '#222' };
const cell = { fontSize: 15, color: '#222' };
