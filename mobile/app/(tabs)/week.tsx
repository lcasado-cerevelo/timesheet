import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { addDays, dayLabel, fmtHours, to12h, today, weekDays, weekEnding } from '../../src/lib/time';
import { dayTotals } from '../../src/lib/report';
import { useAuth } from '../../src/store/auth';
import { useT } from '../../src/i18n';
import { useEntries } from '../../src/store/entries';
import { useSettings } from '../../src/store/settings';
import { Badge, Btn, C, Card, Msg, STATUS, useBottomPad, useTheme } from '../../src/ui';

export default function Week() {
  const router = useRouter();
  const th = useTheme();
  const { t } = useT();
  const bottom = useBottomPad(12);
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
      setMsg({ kind: 'ok', text: t('week.sent') });
    } catch (e: any) {
      setMsg({ kind: 'err', text: e.message });
    } finally {
      setBusy(false);
    }
  }, [selectedDate, submitWeek]);

  // Semana enviada por partes: cada día trae su estado, y las partes devueltas traen su comentario.
  const dayState = new Map((sheet?.days ?? []).map((d) => [d.date, d]));
  const returnedParts = (sheet?.parts ?? []).filter((p) => p.is_part && p.status === 'returned');
  const hasOpen = (sheet?.days ?? []).some((d) => d.open || d.status === 'returned');
  const st = sheet ? STATUS[returnedParts.length ? 'returned' : sheet.status] : null;
  const canSubmit = sheet && (sheet.status === 'draft' || sheet.status === 'returned' || returnedParts.length > 0) && (!sheet.days || hasOpen);
  const over = me.worker_type === 'employee' && me.weekly_hours != null && weekTotal > Number(me.weekly_hours);

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 12, paddingBottom: bottom }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
        <Pressable testID="wprev" onPress={() => setDate(addDays(selectedDate, -7))} style={{ padding: 8 }}><Text style={{ fontSize: 22, color: th.primary }}>‹</Text></Pressable>
        <Text style={{ flex: 1, textAlign: 'center', fontWeight: '800', fontSize: 16 }}>{dayLabel(days[0])} – {dayLabel(days[6])}</Text>
        <Pressable testID="wnext" onPress={() => setDate(addDays(selectedDate, 7))} style={{ padding: 8 }}><Text style={{ fontSize: 22, color: th.primary }}>›</Text></Pressable>
      </View>

      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
          {st ? <Badge text={t(st.key)} color={st.color} bg={st.bg} /> : <Badge text={t('week.unknown')} />}
          {sheet?.late ? <Badge text={t('week.late')} color={C.danger} bg="#fdecea" /> : null}
          <View style={{ flex: 1 }} />
          <Text testID="week-total" style={{ fontWeight: '800', fontSize: 16 }}>{fmtHours(weekTotal, decimal)} h</Text>
        </View>
        {returnedParts.map((p) => (
          <View key={p.id} style={{ marginTop: 10 }}>
            <Msg kind="err" text={t('week.part_returned', { period: p.period_starting === p.period_ending ? dayLabel(p.period_starting) : `${dayLabel(p.period_starting)} – ${dayLabel(p.period_ending)}`, comment: p.return_comment ?? '' })} />
          </View>
        ))}
        {!returnedParts.length && sheet?.status === 'returned' && sheet.return_comment ? <View style={{ marginTop: 10 }}><Msg kind="err" text={t('week.returned', { comment: sheet.return_comment })} /></View> : null}
        {sheet?.locked ? <View style={{ marginTop: 10 }}><Msg kind="ok" text={t('week.approved')} /></View> : null}
        {over ? <View style={{ marginTop: 10 }}><Msg kind="warn" text={t('week.over', { n: Number(me.weekly_hours) })} /></View> : null}
        {sheet?.status === 'submitted' || (sheet?.days ?? []).some((d) => d.status === 'submitted') ? <Text style={{ color: C.muted, marginTop: 8 }}>{t('week.waiting')}</Text> : null}
      </Card>

      <Card>
        {days.map((d) => {
          const list = entries.filter((e) => e.work_date === d && e.sync !== 'deleted');
          const ds = dayState.get(d);
          const dst = ds && !ds.open ? STATUS[ds.status] : null;   // enviado, aprobado o devuelto (un día abierto no lleva etiqueta)
          return (
            <Pressable key={d} testID={`day-${d}`} onPress={() => { setDate(d); router.navigate('/'); }} style={{ paddingVertical: 8, borderBottomWidth: 1, borderColor: '#eee' }}>
              <View style={{ flexDirection: 'row' }}>
                <View style={{ width: 90 }}>
                  <Text style={{ fontWeight: '700' }}>{dayLabel(d)}</Text>
                  {dst ? <View testID={`daystate-${d}`} style={{ marginTop: 2, alignSelf: 'flex-start' }}><Badge text={t(dst.key)} color={dst.color} bg={dst.bg} /></View> : null}
                </View>
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
      {canSubmit ? <Btn testID="submit-week" kind="pri" title={sheet!.status === 'returned' ? t('week.resubmit') : t('week.submit')} disabled={busy || weekTotal <= 0} onPress={submit} /> : null}
      {!sheet ? <Text style={{ color: C.muted, marginTop: 8 }}>{t('week.need_connection')}</Text> : null}
    </ScrollView>
  );
}
