import React, { useMemo, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { computeHours } from '../src/lib/rules';
import { fmtHours, longDate, nowHM } from '../src/lib/time';
import { useAuth } from '../src/store/auth';
import { useEntries } from '../src/store/entries';
import { Btn, C, Card, Field, Msg, TimeField, useTheme } from '../src/ui';

/** Agregar o editar una entrada. Lo que se pide depende del modo de captura que fijó el dueño para esta persona. */
export default function EntryScreen() {
  const router = useRouter();
  const t = useTheme();
  const params = useLocalSearchParams<{ uuid?: string; date?: string }>();
  const me = useAuth((s) => s.me)!;
  const { entries, projects, selectedDate, save } = useEntries();
  const editing = params.uuid ? entries.find((e) => e.uuid === params.uuid) : undefined;
  const fromTo = me.capture_mode === 'from_to';

  const [projectId, setProjectId] = useState<number | null>(editing?.project_id ?? projects.find((p) => p.active)?.id ?? null);
  const [date] = useState(editing?.work_date ?? params.date ?? selectedDate);
  const [start, setStart] = useState<string | null>(editing?.start_time ?? (fromTo ? nowHM() : null));
  const [end, setEnd] = useState<string | null>(editing?.end_time ?? null);
  const [hours, setHours] = useState(editing && !editing.start_time ? String(editing.hours) : '');
  const [note, setNote] = useState(editing?.note ?? '');
  const [errors, setErrors] = useState<string[]>([]);

  const calc = useMemo(
    () => computeHours({ project_id: projectId, work_date: date, start_time: start, end_time: end, hours: Number(hours.replace(',', '.')) || 0 }, me.capture_mode, me.rules.increment),
    [projectId, date, start, end, hours, me],
  );

  const submit = () => {
    const r = save(
      { project_id: projectId, work_date: date, start_time: fromTo ? start : null, end_time: fromTo ? end : null, hours: fromTo ? null : Number(hours.replace(',', '.')) || 0, note },
      editing?.uuid,
    );
    if (r.errors.length) return setErrors(r.errors);
    router.back();
  };

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 14 }} keyboardShouldPersistTaps="handled">
      <Card>
        <Text style={{ fontWeight: '700', marginBottom: 10 }}>{editing ? 'Editar entrada' : 'Agregar entrada'} · {longDate(date)}</Text>
        {errors.map((e) => <Msg key={e} kind="err" text={e} />)}
        {editing?.sync === 'rejected' && editing.reject_reason ? <Msg kind="warn" text={editing.reject_reason} /> : null}
        <Text style={{ fontSize: 12, color: C.muted, marginBottom: 4 }}>Proyecto</Text>
        <View style={{ marginBottom: 12 }}>
          {projects.filter((p) => p.active).map((p) => (
            <Pressable key={p.id} testID={`proj-${p.code}`} onPress={() => setProjectId(p.id)} style={{ paddingVertical: 10, paddingHorizontal: 10, borderRadius: 8, marginBottom: 6, borderWidth: 1, borderColor: projectId === p.id ? t.accent : '#d1d5db', backgroundColor: projectId === p.id ? '#eef7f1' : '#fff' }}>
              <Text style={{ fontWeight: projectId === p.id ? '700' : '400' }}>{p.code} · {p.name}</Text>
            </Pressable>
          ))}
        </View>
        {fromTo ? (
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <TimeField testID="start" label="Desde" value={start} onChange={setStart} />
            <TimeField testID="end" label="Hasta" value={end} onChange={setEnd} />
          </View>
        ) : (
          <Field testID="hours" label="Horas del día" value={hours} onChangeText={setHours} keyboardType="decimal-pad" placeholder="8" />
        )}
        <Text style={{ color: C.muted, marginBottom: 10 }}>Total: <Text style={{ fontWeight: '800', color: C.text }}>{fmtHours(calc, true)} h</Text></Text>
        <Field testID="note" label="Nota" value={note} onChangeText={setNote} multiline autoCapitalize="sentences" />
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <Btn testID="save" title="Guardar" kind="pri" onPress={submit} />
          <Btn title="Cancelar" kind="ghost" onPress={() => router.back()} />
        </View>
      </Card>
    </ScrollView>
  );
}
