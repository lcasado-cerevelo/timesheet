import React, { useState } from 'react';
import { ScrollView, Switch, Text, View } from 'react-native';
import { ensureNotificationPermission, setWeeklyReminder } from '../../src/lib/notify';
import { startWatching, stopWatching } from '../../src/lib/geofence';
import { useAuth } from '../../src/store/auth';
import { useEntries } from '../../src/store/entries';
import { useSettings } from '../../src/store/settings';
import { Btn, C, Card, Msg, useTheme } from '../../src/ui';

function Row(props: { label: string; hint?: string; value: boolean; onChange: (v: boolean) => void; testID?: string }) {
  const t = useTheme();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', paddingVertical: 10 }}>
      <View style={{ flex: 1, paddingRight: 10 }}>
        <Text style={{ fontWeight: '600' }}>{props.label}</Text>
        {props.hint ? <Text style={{ color: C.muted, fontSize: 12 }}>{props.hint}</Text> : null}
      </View>
      <Switch testID={props.testID} value={props.value} onValueChange={props.onChange} trackColor={{ true: t.accent }} />
    </View>
  );
}

export default function Settings() {
  const me = useAuth((s) => s.me)!;
  const baseUrl = useAuth((s) => s.baseUrl);
  const logout = useAuth((s) => s.logout);
  const { decimal, arriveAlerts, weeklyReminder, set } = useSettings();
  const { entries, projects, sync, syncing, lastSync, lastError } = useEntries();
  const [note, setNote] = useState<{ kind: 'ok' | 'err' | 'warn'; text: string } | null>(null);
  const pending = entries.filter((e) => e.sync === 'dirty' || e.sync === 'deleted').length;
  const rejected = entries.filter((e) => e.sync === 'rejected').length;
  const withPlace = projects.filter((p) => p.geo_lat != null && p.geo_lng != null).length;

  const toggleArrive = async (on: boolean) => {
    setNote(null);
    if (!on) {
      await stopWatching();
      return set({ arriveAlerts: false });
    }
    if (!(await ensureNotificationPermission())) return setNote({ kind: 'warn', text: 'Permite las notificaciones para recibir el aviso.' });
    const r = await startWatching(projects);
    if (!r.ok) return setNote({ kind: 'warn', text: r.message ?? 'No se pudo activar.' });
    set({ arriveAlerts: true });
    setNote({ kind: 'ok', text: `Vigilando ${r.count} lugar(es) de trabajo.` });
  };

  const toggleReminder = async (on: boolean) => {
    if (on && !(await ensureNotificationPermission())) return setNote({ kind: 'warn', text: 'Permite las notificaciones para recibir el recordatorio.' });
    await setWeeklyReminder(on);
    set({ weeklyReminder: on });
  };

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 12 }}>
      <Card>
        <Text style={{ fontWeight: '800', fontSize: 16 }}>{me.name}</Text>
        <Text style={{ color: C.muted }}>{me.email} · {me.company.name}</Text>
        <Text style={{ marginTop: 6 }}>{me.worker_type === 'employee' ? `Empleado (${Number(me.weekly_hours ?? 40)} h por semana)` : 'Consultor'} · {me.capture_mode === 'full_day' ? 'Registras el día completo' : 'Registras desde / hasta'}</Text>
        <Text style={{ color: C.muted, fontSize: 12, marginTop: 4 }}>El modo de captura lo fija el dueño de la compañía.</Text>
      </Card>

      <Card>
        {note ? <Msg kind={note.kind} text={note.text} /> : null}
        <Row testID="decimal" label="Horas en decimal" hint="8.50 en vez de 08:30" value={decimal} onChange={(v) => set({ decimal: v })} />
        {me.capture_mode === 'from_to' && (
          <Row testID="arrive" label="Avisarme al llegar a un lugar de trabajo" hint={withPlace ? `${withPlace} proyecto(s) con lugar definido. Pide ubicación “Siempre”.` : 'Ningún proyecto tiene lugar definido todavía.'} value={arriveAlerts} onChange={toggleArrive} />
        )}
        <Row testID="weekly" label="Recordatorio semanal" hint="Viernes 4:00 p. m.: revisar y enviar la hoja" value={weeklyReminder} onChange={toggleReminder} />
      </Card>

      <Card>
        <Text style={{ fontWeight: '700', marginBottom: 4 }}>Sincronización</Text>
        <Text testID="pending">{pending === 0 ? 'Todo sincronizado.' : `${pending} cambio(s) por enviar.`}{rejected ? ` ${rejected} rechazado(s).` : ''}</Text>
        <Text style={{ color: C.muted, fontSize: 12, marginVertical: 4 }}>
          {lastSync ? `Última vez: ${new Date(lastSync).toLocaleString()}` : 'Aún no se ha sincronizado.'} · {baseUrl}
        </Text>
        {lastError && !syncing ? <Msg kind="warn" text={lastError} /> : null}
        <Btn testID="sync" kind="ghost" title={syncing ? 'Sincronizando…' : 'Sincronizar ahora'} disabled={syncing} onPress={() => void sync()} />
      </Card>

      <Btn testID="logout" kind="danger" title="Salir" onPress={() => void logout()} />
    </ScrollView>
  );
}
