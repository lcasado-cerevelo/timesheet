import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { addDays, dayLabel, fmtHours, to12h, today, weekDays, weekEnding } from '../../src/lib/time';
import { dayTotals } from '../../src/lib/report';
import { useAuth } from '../../src/store/auth';
import { useEntries } from '../../src/store/entries';
import { useSettings } from '../../src/store/settings';
import { Badge, Btn, C, Card, Msg, STATUS, useTheme } from '../../src/ui';

export default function Week() {
  const router = useRouter();
  const t = useTheme();
  const me = useAuth((s) => s.me)!;
  const decimal = useSettings((s) => s.decimal);
  const { entries, projects, selectedDate, setDate, sheets, fetchSheet, submitWeek } = useEntries();
  const [msg, setMsg] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const end = weekEnding(selectedDate);
  const days = weekDays(selectedDate);
  const sheet = sheets[end];
  const totals = useMemo(() => dayTotals(entries), [entries]);
  const weekTotal = days.reduce((a, d) => a + (totals.get(d) ?? 0), 0);

  useEffect(() => {
    setMsg(null);
    void fetchSheet(selectedDate);
  }, [end, fetchSheet, selectedDate]);

  const submit = useCallback(async () => {
    setBusy(true);
    setMsg(null);
    try {
      await submitWeek(selectedDate);
      setMsg({ kind: 'ok', text: 'Hoja enviada a aprobación.' });
    } catch (e: any) {
      setMsg({ kind: 'err', text: e.message });
    } finally {
      setBusy(false);
    }
  }, [selectedDate, submitWeek]);

  const st = sheet ? STATUS[sheet.status] : null;
  const canSubmit = sheet && (sheet.status === 'draft' || sheet.status === 'returned');
  const over = me.worker_type === 'employee' && me.weekly_hours != null && weekTotal > Number(me.weekly_hours);

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 12 }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
        <Pressable testID="wprev" onPress={() => setDate(addDays(selectedDate, -7))} style={{ padding: 8 }}><Text style={{ fontSize: 22, color: t.primary }}>‹</Text></Pressable>
        <Text style={{ flex: 1, textAlign: 'center', fontWeight: '800', fontSize: 16 }}>{dayLabel(days[0])} – {dayLabel(days[6])}</Text>
        <Pressable testID="wnext" onPress={() => setDate(addDays(selectedDate, 7))} style={{ padding: 8 }}><Text style={{ fontSize: 22, color: t.primary }}>›</Text></Pressable>
      </View>

      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          {st ? <Badge text={st.label} color={st.color} bg={st.bg} /> : <Badge text="Sin conexión: estado desconocido" />}
          {sheet?.late ? <Badge text="Tarde" color={C.danger} bg="#fdecea" /> : null}
          <View style={{ flex: 1 }} />
          <Text testID="week-total" style={{ fontWeight: '800', fontSize: 16 }}>{fmtHours(weekTotal, decimal)} h</Text>
        </View>
        {sheet?.status === 'returned' && sheet.return_comment ? <View style={{ marginTop: 10 }}><Msg kind="err" text={`Devuelta: ${sheet.return_comment}`} /></View> : null}
        {sheet?.locked ? <View style={{ marginTop: 10 }}><Msg kind="ok" text="Aprobada: ya no se puede cambiar." /></View> : null}
        {over ? <View style={{ marginTop: 10 }}><Msg kind="warn" text={`Pasa de ${Number(me.weekly_hours)} h por semana.`} /></View> : null}
        {sheet?.status === 'submitted' ? <Text style={{ color: C.muted, marginTop: 8 }}>Esperando decisión. Si cambias algo, el aprobador recibe un aviso de que la hoja cambió.</Text> : null}
      </Card>

      <Card>
        {days.map((d) => {
          const list = entries.filter((e) => e.work_date === d && e.sync !== 'deleted');
          return (
            <Pressable key={d} testID={`day-${d}`} onPress={() => { setDate(d); router.navigate('/'); }} style={{ paddingVertical: 8, borderBottomWidth: 1, borderColor: '#eee' }}>
              <View style={{ flexDirection: 'row' }}>
                <Text style={{ width: 90, fontWeight: '700' }}>{dayLabel(d)}</Text>
                <View style={{ flex: 1 }}>
                  {list.length === 0 ? <Text style={{ color: '#9ca3af' }}>—</Text> : list.map((e) => (
                    <Text key={e.uuid} style={{ color: C.text }}>
                      {projects.find((p) => p.id === e.project_id)?.code ?? '?'} {e.start_time ? `${to12h(e.start_time)}–${to12h(e.end_time ?? '00:00')}` : ''} · {fmtHours(e.hours, decimal)}
                      {e.sync === 'rejected' ? ' ⚠' : e.sync === 'dirty' ? ' ⟳' : ''}
                    </Text>
                  ))}
                </View>
                <Text style={{ fontWeight: '800' }}>{totals.get(d) ? fmtHours(totals.get(d)!, decimal) : ''}</Text>
              </View>
            </Pressable>
          );
        })}
      </Card>

      {msg ? <Msg kind={msg.kind} text={msg.text} /> : null}
      {canSubmit ? <Btn testID="submit-week" kind="pri" title={sheet!.status === 'returned' ? 'Corregida: enviar de nuevo' : 'Enviar a aprobación'} disabled={busy || weekTotal <= 0} onPress={submit} /> : null}
      {!sheet ? <Text style={{ color: C.muted, marginTop: 8 }}>Para enviar la hoja hace falta conexión; tus entradas se guardan y se sincronizan al volver.</Text> : null}
    </ScrollView>
  );
}
