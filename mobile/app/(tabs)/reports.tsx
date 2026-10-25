import React, { useEffect, useMemo, useState } from 'react';
import { Image, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import * as FileSystem from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { api, apiUrl, authHeaders } from '../../src/lib/api';
import { Group, summarize } from '../../src/lib/report';
import { addDays, fmtHours, today, weekStarting } from '../../src/lib/time';
import { useEntries } from '../../src/store/entries';
import { useSettings } from '../../src/store/settings';
import { Btn, C, Card, Msg, useTheme } from '../../src/ui';

interface Tpl { id: number; name: string; format: 'xlsx' | 'csv' }

export default function Reports() {
  const t = useTheme();
  const decimal = useSettings((s) => s.decimal);
  const { entries, projects } = useEntries();
  const [range, setRange] = useState<'week' | 'month' | '30'>('month');
  const [group, setGroup] = useState<Group>('project');
  const [tpls, setTpls] = useState<Tpl[]>([]);
  const [tpl, setTpl] = useState<number | null>(null);
  const [msg, setMsg] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const to = today();
  const from = range === 'week' ? weekStarting(to) : range === '30' ? addDays(to, -30) : to.slice(0, 8) + '01';
  const r = useMemo(() => summarize(entries, projects, from, to, group), [entries, projects, from, to, group]);

  useEffect(() => {
    api<Tpl[]>('/export-templates').then((x) => { const list = Array.isArray(x) ? x : []; setTpls(list); setTpl((c) => c ?? list[0]?.id ?? null); }).catch(() => undefined);
  }, []);

  const exportFile = async () => {
    if (!tpl) return;
    setBusy(true);
    setMsg(null);
    try {
      const chosen = tpls.find((x) => x.id === tpl)!;
      const url = apiUrl(`/export?template_id=${tpl}&from=${from}&to=${to}`);
      const name = `${chosen.name.replace(/[^a-z0-9]+/gi, '-')}-${from}_${to}.${chosen.format}`;
      if (Platform.OS === 'web') {
        const res = await fetch(url, { headers: { ...authHeaders(), Accept: 'application/json' } });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message ?? 'No se pudo generar el archivo.');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(await res.blob());
        a.download = name;
        a.click();
      } else {
        const file = (FileSystem.cacheDirectory ?? '') + name;
        const res = await FileSystem.downloadAsync(url, file, { headers: { ...authHeaders(), Accept: 'application/json' } });
        if (res.status !== 200) throw new Error('El servidor no pudo generar el archivo (¿hay conexión? ¿hay horas aprobadas en el período?).');
        if (await Sharing.isAvailableAsync()) await Sharing.shareAsync(res.uri, { dialogTitle: name });
      }
      setMsg({ kind: 'ok', text: 'Archivo listo: ' + name });
    } catch (e: any) {
      setMsg({ kind: 'err', text: e.message });
    } finally {
      setBusy(false);
    }
  };

  const chip = (label: string, on: boolean, f: () => void, id: string) => (
    <Pressable key={label} testID={id} onPress={f} style={{ paddingHorizontal: 12, paddingVertical: 7, borderRadius: 99, backgroundColor: on ? t.primary : '#fff', marginRight: 6, marginBottom: 6 }}>
      <Text style={{ color: on ? '#fff' : C.text, fontWeight: '700' }}>{label}</Text>
    </Pressable>
  );

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 12 }}>
      <View style={{ alignItems: 'center', paddingVertical: 8, marginBottom: 8, borderBottomWidth: 3, borderColor: t.primary }}>
        {t.brand.logo_url ? <Image source={{ uri: t.brand.logo_url }} style={{ width: 180, height: 44 }} resizeMode="contain" /> : <Text style={{ fontSize: 20, fontWeight: '800', color: t.primary }}>{t.brand.name}</Text>}
      </View>
      <Card>
        <Text style={{ fontWeight: '700', marginBottom: 8 }}>Período</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
          {chip('Esta semana', range === 'week', () => setRange('week'), 'r-week')}
          {chip('Este mes', range === 'month', () => setRange('month'), 'r-month')}
          {chip('30 días', range === '30', () => setRange('30'), 'r-30')}
        </View>
        <Text style={{ fontWeight: '700', marginVertical: 8 }}>Agrupar por</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
          {chip('Proyecto', group === 'project', () => setGroup('project'), 'g-project')}
          {chip('Día', group === 'day', () => setGroup('day'), 'g-day')}
          {chip('Semana', group === 'week', () => setGroup('week'), 'g-week')}
        </View>
        <Text style={{ color: C.muted }}>{from} a {to}</Text>
      </Card>

      <Card>
        <View style={{ flexDirection: 'row', paddingBottom: 6 }}>
          <Text style={{ flex: 1, fontWeight: '700' }} />
          <Text style={{ width: 70, textAlign: 'right', fontWeight: '700' }}>Horas</Text>
          <Text style={{ width: 90, textAlign: 'right', fontWeight: '700' }}>Facturables</Text>
        </View>
        {r.rows.length === 0 ? <Text style={{ color: C.muted }}>Sin horas en este período.</Text> : null}
        {r.rows.map((x) => (
          <View key={x.label} style={{ flexDirection: 'row', paddingVertical: 6, borderTopWidth: 1, borderColor: '#eee' }}>
            <Text style={{ flex: 1 }}>{x.label}</Text>
            <Text style={{ width: 70, textAlign: 'right' }}>{fmtHours(x.hours, decimal)}</Text>
            <Text style={{ width: 90, textAlign: 'right' }}>{fmtHours(x.billable, decimal)}</Text>
          </View>
        ))}
        <View style={{ flexDirection: 'row', paddingTop: 8, borderTopWidth: 2, borderColor: '#ddd' }}>
          <Text style={{ flex: 1, fontWeight: '800' }}>Total</Text>
          <Text testID="report-total" style={{ width: 70, textAlign: 'right', fontWeight: '800' }}>{fmtHours(r.total, decimal)}</Text>
          <Text style={{ width: 90, textAlign: 'right', fontWeight: '800' }}>{fmtHours(r.billable, decimal)}</Text>
        </View>
      </Card>

      <Card>
        <Text style={{ fontWeight: '700', marginBottom: 6 }}>Exportar</Text>
        <Text style={{ color: C.muted, marginBottom: 8 }}>Genera el archivo en el servidor con las horas aprobadas del período. Necesita conexión.</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
          {tpls.map((x) => chip(`${x.name} (${x.format})`, tpl === x.id, () => setTpl(x.id), `tpl-${x.id}`))}
        </View>
        {msg ? <Msg kind={msg.kind} text={msg.text} /> : null}
        <Btn testID="export" kind="pri" title={busy ? 'Generando…' : 'Exportar y compartir'} disabled={busy || !tpl} onPress={exportFile} />
      </Card>
    </ScrollView>
  );
}
