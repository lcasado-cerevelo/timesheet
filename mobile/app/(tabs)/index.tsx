import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useT } from '../../src/i18n';
import { ProjectSheet } from '../../src/pickers';
import { elapsedMs, fmtElapsed } from '../../src/lib/clock';
import { activeServices } from '../../src/lib/services';
import { dayActionAllowed, visibleEntries } from '../../src/lib/access';
import { kv } from '../../src/lib/storage';
import { addDays, fmtHours, longDate, nowHM, to12h, today } from '../../src/lib/time';
import { useAuth } from '../../src/store/auth';
import { useClock } from '../../src/store/clock';
import { useEntries } from '../../src/store/entries';
import { useCurrent, useSelection } from '../../src/store/selection';
import { useSettings } from '../../src/store/settings';
import { api } from '../../src/lib/api';
import { Badge, Btn, C, Chip, ClockPicker, GridBtn, Msg, Sheet, useBottomPad, useTheme } from '../../src/ui';
import type { Entry } from '../../src/lib/types';

type List = null | 'choose' | 'switch';

export default function Day() {
  const router = useRouter();
  const th = useTheme();
  const { t } = useT();
  const bottom = useBottomPad(10);
  const decimal = useSettings((s) => s.decimal);
  const { entries, projects, selectedDate, setDate, remove, retry, discard, save, syncing, lastError } = useEntries();
  const { clock, start, stop, switchTo } = useClock();
  const sel = useSelection();
  const cur = useCurrent();
  const limited = useAuth((s) => s.limited);
  const [list, setList] = useState<List>(null);
  const [switchTarget, setSwitchTarget] = useState<number | null>(null);
  const [tmpl, setTmpl] = useState(false);
  const [ask, setAsk] = useState<null | { kind: 'in' | 'out'; initial: string }>(null);
  const [now, setNow] = useState(Date.now());
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [err, setErr] = useState('');

  const fromTo = cur.mode === 'from_to';

  useEffect(() => {
    void kv.get<Record<string, string>>('daynotes.v1', {}).then(setNotes);
  }, []);
  useEffect(() => {
    if (!clock) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [clock]);

  const day = useMemo(
    () => visibleEntries(entries, limited).filter((e) => e.work_date === selectedDate && e.sync !== 'deleted').sort((a, b) => (a.start_time ?? '').localeCompare(b.start_time ?? '')),
    [entries, selectedDate, limited],
  );
  const total = day.reduce((a, e) => a + e.hours, 0);
  const project = (id: number) => projects.find((p) => p.id === id);
  const serviceName = (e: { project_id: number; service_id?: number | null }) =>
    e.service_id ? project(e.project_id)?.services?.find((s) => s.id === e.service_id)?.name : undefined;
  const fail = (m: string | null) => setErr(m ?? '');

  /** Revisa que haya proyecto y servicio escogidos antes de abrir el reloj. */
  const ready = (): boolean => {
    if (!cur.project) {
      fail(t('rules.pick_project'));
      return false;
    }
    if (cur.services.length > 0 && !cur.service) {
      fail(t('rules.service_required'));
      return false;
    }
    fail(null);
    return true;
  };

  const askIn = () => {
    if (ready()) setAsk({ kind: 'in', initial: nowHM() });
  };
  const askOut = () => setAsk({ kind: 'out', initial: nowHM() });

  const confirmClock = (hm: string) => {
    const kind = ask?.kind;
    setAsk(null);
    const [h, m] = hm.split(':').map(Number);
    const at = new Date();
    at.setHours(h, m, 0, 0);
    if (kind === 'in' && cur.project) fail(start(cur.project.id, cur.service?.id ?? null, '', at));
    if (kind === 'out') fail(stop(at));
  };

  const recent = useMemo(() => {
    const seen = new Set<string>();
    const out: Entry[] = [];
    for (const e of [...entries].filter((x) => x.sync !== 'deleted').reverse()) {
      const k = `${e.project_id}|${e.service_id ?? ''}|${e.start_time}|${e.end_time}|${e.start_time ? '' : e.hours}|${e.note}`;
      if (!seen.has(k)) {
        seen.add(k);
        out.push(e);
      }
      if (out.length >= 8) break;
    }
    return out;
  }, [entries]);

  const copyOf = (e: Entry) => ({
    project_id: e.project_id, service_id: e.service_id ?? null, work_date: selectedDate,
    start_time: e.start_time, end_time: e.end_time, hours: e.hours, note: e.note,
  });

  const repeat = (e: Entry) => {
    const r = save(copyOf(e));
    if (r.errors.length) Alert.alert(t('day.repeat_fail'), r.errors[0]);
    else setTmpl(false);
  };

  const copyPrevious = () => {
    for (let i = 1; i <= 7; i++) {
      const d = addDays(selectedDate, -i);
      const prev = entries.filter((e) => e.work_date === d && e.sync !== 'deleted');
      if (prev.length) {
        const errors = prev.map((e) => save(copyOf(e)).errors[0]).filter(Boolean);
        return fail(errors[0] ?? null);
      }
    }
    fail(t('day.no_previous'));
  };

  const saveNote = (text: string) => {
    const next = { ...notes, [selectedDate]: text };
    setNotes(next);
    void kv.set('daynotes.v1', next);
    api('/day-notes', { method: 'PUT', body: { work_date: selectedDate, note: text } }).catch(() => undefined);
  };

  const confirmDelete = (e: Entry) => {
    const go = () => remove(e.uuid);
    if (Platform.OS === 'web') return go();
    Alert.alert(t('day.delete_title'), t('day.delete_ask'), [{ text: t('common.cancel') }, { text: t('common.delete'), style: 'destructive', onPress: go }]);
  };

  // Elegir proyecto en la lista. Con el reloj corriendo, "Cambiar proyecto" cierra el tramo y abre el nuevo.
  const pickProject = (id: number) => {
    const mode = list;
    setList(null);
    sel.setProject(id);
    fail(null);
    if (mode !== 'switch' || !clock) return;
    const services = activeServices(project(id));
    if (services.length > 1) {
      setSwitchTarget(id); // hay que escoger servicio antes de cambiar
      return;
    }
    fail(switchTo(id, services[0]?.id ?? null));
  };

  const pickSwitchService = (serviceId: number) => {
    const id = switchTarget;
    setSwitchTarget(null);
    if (!id) return;
    sel.setService(id, serviceId);
    fail(switchTo(id, serviceId));
  };

  const chooseService = (serviceId: number) => {
    if (cur.project) sel.setService(cur.project.id, serviceId);
    fail(null);
  };

  const clockProject = clock ? project(clock.project_id) : undefined;
  const clockService = clock?.service_id ? clockProject?.services?.find((s) => s.id === clock.service_id) : undefined;

  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      {/* 1. El día */}
      <View style={{ backgroundColor: th.primary, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingVertical: 10 }}>
        <Pressable testID="prev" onPress={() => setDate(addDays(selectedDate, -1))} style={{ padding: 8 }}><Text style={{ color: '#fff', fontSize: 22 }}>‹</Text></Pressable>
        <Pressable style={{ flex: 1 }} onPress={() => setDate(today())}><Text testID="date" style={{ color: '#fff', fontSize: 17, fontWeight: '700', textAlign: 'center' }}>{longDate(selectedDate)}</Text></Pressable>
        <Pressable testID="next" onPress={() => setDate(addDays(selectedDate, 1))} style={{ padding: 8 }}><Text style={{ color: '#fff', fontSize: 22 }}>›</Text></Pressable>
      </View>

      {clock && (
        <View testID="running" style={{ backgroundColor: '#e6f4ea', borderBottomWidth: 1, borderColor: '#9bd1ac', padding: 10, flexDirection: 'row', alignItems: 'center' }}>
          <View style={{ flex: 1 }}>
            <Text style={{ fontWeight: '700' }}>
              {clockProject ? `${clockProject.code} · ${clockProject.name}` : t('day.clock_running')}{clockService ? ` · ${clockService.name}` : ''}
            </Text>
            <Text style={{ color: C.muted }}>{t('day.since', { time: to12h(nowHM(new Date(clock.started_at))) })}</Text>
          </View>
          <Text testID="elapsed" style={{ fontSize: 24, fontWeight: '800', fontVariant: ['tabular-nums'] }}>{fmtElapsed(elapsedMs(clock, new Date(now)))}</Text>
        </View>
      )}

      {/* 2. El proyecto actual: tira que se desliza hacia los lados; tocar el actual abre la lista */}
      <View style={{ paddingTop: 8 }}>
        {cur.projects.length === 0 ? (
          <Text style={{ color: C.muted, paddingHorizontal: 12, paddingBottom: 6 }}>{t('day.no_projects')}</Text>
        ) : (
          <ScrollView testID="project-strip" horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 12, paddingBottom: 6 }}>
            {cur.projects.map((p) => (
              <Chip
                key={p.id}
                testID={`proj-${p.code}`}
                label={`${p.code} · ${p.name}`}
                on={cur.project?.id === p.id}
                onPress={() => {
                  if (cur.project?.id === p.id) return setList('choose');
                  sel.setProject(p.id);
                  fail(null);
                }}
              />
            ))}
          </ScrollView>
        )}

        {/* 3. Los servicios del proyecto actual (con uno solo queda escogido y se muestra discreto) */}
        {cur.services.length > 1 ? (
          <ScrollView testID="service-strip" horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 12, paddingBottom: 8 }}>
            {cur.services.map((s) => (
              <Chip key={s.id} small testID={`svc-${s.id}`} label={s.name} on={cur.service?.id === s.id} onPress={() => chooseService(s.id)} />
            ))}
          </ScrollView>
        ) : cur.services.length === 1 ? (
          <Text testID="service-single" style={{ color: C.muted, fontSize: 12, paddingHorizontal: 14, paddingBottom: 8 }}>{t('day.service')}: {cur.services[0].name}</Text>
        ) : null}
      </View>

      <View style={{ flexDirection: 'row', flexWrap: 'wrap', borderTopWidth: 1, borderBottomWidth: 1, borderColor: C.line }}>
        {/* 4. Entrar ahora / Salir ahora (solo servicios por detalle) y 5. Agregar entrada */}
        {fromTo && (
          <>
            <GridBtn testID="check-in" title={t('day.check_in')} disabled={!!clock} onPress={askIn} />
            <GridBtn testID="check-out" title={t('day.check_out')} disabled={!clock} onPress={askOut} />
            <GridBtn wide testID="add-entry" title={t('day.add_entry')} onPress={() => router.push({ pathname: '/entry', params: { date: selectedDate } })} />
          </>
        )}
        {/* 6. Agregar horas (total del día; solo servicios por total) */}
        {!fromTo && <GridBtn wide testID="add-hours" title={t('day.add_hours')} onPress={() => router.push({ pathname: '/entry', params: { date: selectedDate } })} />}
        {/* 7. Cambiar proyecto y Repetir */}
        <GridBtn testID="switch" title={t('day.change_project')} onPress={() => setList(clock ? 'switch' : 'choose')} />
        {dayActionAllowed('repeat', limited) && <GridBtn testID="template" title={t('day.repeat')} onPress={() => setTmpl(true)} />}
        {dayActionAllowed('copy_prev', limited) && <GridBtn testID="copy-prev" title={t('day.copy_prev')} onPress={copyPrevious} />}
        <GridBtn testID="today" title={t('day.go_today')} onPress={() => setDate(today())} />
      </View>

      <ScrollView style={{ flex: 1 }} contentContainerStyle={{ padding: 12 }}>
        {limited ? <Msg kind="warn" text={t('limited.banner')} /> : null}
        {err ? <Msg kind="err" text={err} /> : null}
        {lastError && !syncing ? <Msg kind="warn" text={lastError} /> : null}
        {day.length === 0 ? (
          <Text style={{ color: '#9ca3af', fontSize: 16 }}>{t('day.empty')}</Text>
        ) : (
          <>
            <View style={{ flexDirection: 'row', paddingBottom: 4 }}>
              <Text style={[hd, { width: 82 }]}>{t('day.col_in')}</Text>
              <Text style={[hd, { width: 82 }]}>{t('day.col_out')}</Text>
              <Text style={[hd, { width: 62 }]}>{t('day.col_total')}</Text>
              <Text style={[hd, { flex: 1 }]}>{t('day.col_project')}</Text>
            </View>
            {day.map((e) => (
              <View key={e.uuid} style={{ borderBottomWidth: 1, borderColor: '#e0e0e0', paddingVertical: 8 }}>
                <Pressable testID="row" onPress={() => router.push({ pathname: '/entry', params: { uuid: e.uuid } })} style={{ flexDirection: 'row', alignItems: 'center' }}>
                  <Text style={[cell, { width: 82 }]}>{e.running ? '—' : e.start_time ? to12h(e.start_time) : ''}</Text>
                  <Text style={[cell, { width: 82, color: '#0b84c6' }]}>{e.end_time ? to12h(e.end_time) : ''}</Text>
                  <Text style={[cell, { width: 62, color: '#0b84c6' }]}>{fmtHours(e.hours, decimal)}</Text>
                  <Text style={[cell, { flex: 1, color: C.muted }]} numberOfLines={1}>
                    {project(e.project_id)?.code ?? '?'}{serviceName(e) ? ' · ' + serviceName(e) : ''}{e.note ? ' · ' + e.note : ''}
                  </Text>
                  <Pressable onPress={() => confirmDelete(e)} hitSlop={8} style={{ paddingHorizontal: 6 }}><Text style={{ color: C.danger, fontSize: 18 }}>✕</Text></Pressable>
                </Pressable>
                {e.sync === 'dirty' && <Badge text={t('day.pending')} bg="#fff4d6" color="#7a5200" />}
                {e.sync === 'rejected' && (
                  <View style={{ marginTop: 4 }}>
                    <Msg kind="err" text={e.reject_reason ?? t('day.rejected')} />
                    <View style={{ flexDirection: 'row', gap: 8 }}>
                      <Btn small kind="ghost" title={t('day.retry')} onPress={() => retry(e.uuid)} />
                      <Btn small kind="danger" title={t('day.discard')} onPress={() => discard(e.uuid)} />
                    </View>
                  </View>
                )}
              </View>
            ))}
            <Text testID="day-total" style={{ fontWeight: '800', fontSize: 16, marginTop: 8 }}>{t('day.total')}: {fmtHours(total, decimal)}</Text>
          </>
        )}
      </ScrollView>

      <View style={{ backgroundColor: '#eeee99', marginHorizontal: 10, marginTop: 10, marginBottom: bottom, borderRadius: 4, paddingHorizontal: 12 }}>
        <TextInput testID="day-note" placeholder={t('day.note')} value={notes[selectedDate] ?? ''} onChangeText={saveNote} style={{ height: 44 }} multiline={false} />
      </View>

      <ProjectSheet visible={list !== null} currentId={cur.project?.id} onPick={pickProject} onClose={() => setList(null)} />

      <Sheet visible={switchTarget !== null} title={t('day.choose_service')} onClose={() => setSwitchTarget(null)}>
        {activeServices(switchTarget ? project(switchTarget) : undefined).map((s) => (
          <Pressable key={s.id} testID={`switch-svc-${s.id}`} onPress={() => pickSwitchService(s.id)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
            <Text style={{ fontSize: 16, fontWeight: '600' }}>{s.name}</Text>
          </Pressable>
        ))}
      </Sheet>

      <Sheet visible={tmpl} title={t('day.repeat_title')} onClose={() => setTmpl(false)}>
        {recent.length === 0 && <Text style={{ color: C.muted }}>{t('day.repeat_empty')}</Text>}
        {recent.map((e) => (
          <Pressable key={e.uuid} onPress={() => repeat(e)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
            <Text style={{ fontSize: 16, fontWeight: '600' }}>
              {project(e.project_id)?.code}{serviceName(e) ? ' · ' + serviceName(e) : ''} · {e.start_time ? `${to12h(e.start_time)} – ${to12h(e.end_time ?? '00:00')}` : fmtHours(e.hours, decimal) + ' h'}
            </Text>
            {e.note ? <Text style={{ color: C.muted }}>{e.note}</Text> : null}
          </Pressable>
        ))}
      </Sheet>

      {/* Reloj de entrada / salida (como Time Recording): ya puesto en la hora actual */}
      <ClockPicker
        visible={ask !== null}
        title={ask?.kind === 'in' ? t('day.check_in_time') : t('day.check_out_time')}
        initial={ask?.initial ?? nowHM()}
        confirmLabel={ask?.kind === 'in' ? t('day.check_in_confirm') : t('day.check_out_confirm')}
        onConfirm={confirmClock}
        onCancel={() => setAsk(null)}
      />
    </View>
  );
}

const hd = { fontSize: 13, fontWeight: '700' as const, color: '#222' };
const cell = { fontSize: 15, color: '#222' };
