import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Platform, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useT } from '../../src/i18n';
import { ProjectSheet } from '../../src/pickers';
import { elapsedMs, fmtElapsed } from '../../src/lib/clock';
import { dayButtonRows, DAY_BUTTON_LABEL, DayButtonId } from '../../src/lib/dayButtons';
import { checkStop, hmAligned, minimumMinutes, roundedNowHM, stepMinutes } from '../../src/lib/increment';
import { minimumDialog } from '../../src/lib/minimumDialog';
import { stepProject } from '../../src/lib/projectSearch';
import { taskName } from '../../src/lib/tasks';
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

/** Lupa dibujada con vistas (sin librerías de íconos). */
function SearchIcon() {
  return (
    <View style={{ width: 24, height: 24 }}>
      <View style={{ position: 'absolute', left: 2, top: 2, width: 14, height: 14, borderRadius: 7, borderWidth: 2.5, borderColor: '#fff' }} />
      <View style={{ position: 'absolute', left: 13, top: 17, width: 9, height: 3, borderRadius: 2, backgroundColor: '#fff', transform: [{ rotate: '45deg' }] }} />
    </View>
  );
}

export default function Day() {
  const router = useRouter();
  const th = useTheme();
  const { t } = useT();
  const bottom = useBottomPad(10);
  const decimal = useSettings((s) => s.decimal);
  const { entries, projects, selectedDate, setDate, remove, retry, discard, save, syncing, lastError } = useEntries();
  const { clock, start, stop, discard: discardClock } = useClock();
  const sel = useSelection();
  const cur = useCurrent();
  const limited = useAuth((s) => s.limited);
  const [search, setSearch] = useState(false);
  const [svcOpen, setSvcOpen] = useState(false);
  const [taskOpen, setTaskOpen] = useState(false);
  const [tmpl, setTmpl] = useState(false);
  const [ask, setAsk] = useState<null | { kind: 'in' | 'out'; initial: string }>(null);
  const [now, setNow] = useState(Date.now());
  const [notes, setNotes] = useState<Record<string, string>>({});
  const [err, setErr] = useState('');

  const me = useAuth((s) => s.me);
  const inc = me?.rules.increment ?? 0.25;
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
  const taskOf = (e: { project_id: number; task_id?: number | null }) => taskName(project(e.project_id), e.task_id);
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
    if (cur.tasks.length > 0 && !cur.task) {
      fail(t('rules.task_required'));
      return false;
    }
    fail(null);
    return true;
  };

  const askIn = () => {
    if (ready()) setAsk({ kind: 'in', initial: roundedNowHM(inc) });
  };
  const askOut = () => setAsk({ kind: 'out', initial: roundedNowHM(inc) });

  /** Pasó menos del mínimo: se pide permiso para registrarlo como una entrada del mínimo. */
  const askBelowMinimum = (elapsed: number, minimum: number, at: Date) => {
    const d = minimumDialog(elapsed, minimum);
    const register = () => fail(stop(at, { registerMinimum: true }));
    if (Platform.OS === 'web') {
      if (typeof window !== 'undefined' && window.confirm(d.message)) register();
      return;
    }
    Alert.alert(d.title, d.message, [
      { text: d.register, onPress: register },
      { text: d.keep, style: 'cancel' },
      { text: d.discard, style: 'destructive', onPress: () => { discardClock(); fail(null); } },
    ]);
  };

  const confirmClock = (hm: string) => {
    const kind = ask?.kind;
    setAsk(null);
    if (!hmAligned(hm, inc)) return fail(t('rules.not_aligned', { n: stepMinutes(inc) }));
    const [h, m] = hm.split(':').map(Number);
    const at = new Date();
    at.setHours(h, m, 0, 0);
    if (kind === 'in' && cur.project) fail(start(cur.project.id, cur.service?.id ?? null, '', at, cur.task?.id ?? null));
    if (kind === 'out') {
      if (!clock || !me) return fail(stop(at));
      const c = checkStop(new Date(clock.started_at), at, minimumMinutes(me.rules));
      if (c.kind === 'below' && at.getTime() <= Date.now() + 60_000) return askBelowMinimum(c.elapsed, c.minimum, at);
      fail(stop(at));
    }
  };

  const recent = useMemo(() => {
    const seen = new Set<string>();
    const out: Entry[] = [];
    for (const e of [...entries].filter((x) => x.sync !== 'deleted').reverse()) {
      const k = `${e.project_id}|${e.service_id ?? ''}|${e.task_id ?? ''}|${e.start_time}|${e.end_time}|${e.start_time ? '' : e.hours}|${e.note}`;
      if (!seen.has(k)) {
        seen.add(k);
        out.push(e);
      }
      if (out.length >= 8) break;
    }
    return out;
  }, [entries]);

  const copyOf = (e: Entry) => ({
    project_id: e.project_id, service_id: e.service_id ?? null, task_id: e.task_id ?? null, work_date: selectedDate,
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

  // Escoger en el buscador, o con las flechas (uno a uno, en orden por código).
  const pickProject = (id: number) => {
    setSearch(false);
    sel.setProject(id);
    fail(null);
  };

  const stepTo = (dir: -1 | 1) => {
    const next = stepProject(cur.projects, cur.project?.id, dir);
    if (next) {
      sel.setProject(next.id, false);
      fail(null);
    }
  };

  const run = (id: DayButtonId) => {
    if (id === 'check-in') askIn();
    else if (id === 'check-out') askOut();
    else if (id === 'add-entry' || id === 'add-hours') router.push({ pathname: '/entry', params: { date: selectedDate } });
    else if (id === 'template') setTmpl(true);
    else if (id === 'copy-prev') copyPrevious();
    else setDate(today());
  };

  const chooseService = (serviceId: number) => {
    if (cur.project) sel.setService(cur.project.id, serviceId);
    setSvcOpen(false);
    fail(null);
  };

  const chooseTask = (taskId: number | null) => {
    if (cur.project) sel.setTask(cur.project.id, taskId);
    setTaskOpen(false);
    fail(null);
  };

  const clockProject = clock ? project(clock.project_id) : undefined;
  const clockService = clock?.service_id ? clockProject?.services?.find((s) => s.id === clock.service_id) : undefined;

  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      {/* 1. El día */}
      <View style={{ backgroundColor: th.primary, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingVertical: 10 }}>
        <Pressable testID="prev" onPress={() => setDate(addDays(selectedDate, -1))} style={{ padding: 8 }}><Text style={{ color: '#fff', fontSize: 22 }}>‹</Text></Pressable>
        <Pressable style={{ flex: 1, minWidth: 0 }} onPress={() => setDate(today())}><Text testID="date" numberOfLines={1} adjustsFontSizeToFit style={{ color: '#fff', fontSize: 17, fontWeight: '700', textAlign: 'center' }}>{longDate(selectedDate)}</Text></Pressable>
        <Pressable testID="next" onPress={() => setDate(addDays(selectedDate, 1))} style={{ padding: 8 }}><Text style={{ color: '#fff', fontSize: 22 }}>›</Text></Pressable>
      </View>

      {/* 2. El proyecto: igual que la fecha, con flechas (uno a uno, por código) y la lupa del buscador */}
      {cur.projects.length === 0 ? (
        <Text style={{ color: C.muted, paddingHorizontal: 12, paddingVertical: 8 }}>{t('day.no_projects')}</Text>
      ) : (
        <View testID="project-bar" style={{ backgroundColor: th.primary, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingVertical: 10, borderTopWidth: StyleSheet.hairlineWidth, borderColor: 'rgba(255,255,255,0.35)' }}>
          <Pressable testID="prev-project" accessibilityLabel={t('day.prev_project')} onPress={() => stepTo(-1)} style={{ padding: 8, opacity: cur.projects.length > 1 ? 1 : 0.4 }}><Text style={{ color: '#fff', fontSize: 22 }}>‹</Text></Pressable>
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text testID="project-name" numberOfLines={1} ellipsizeMode="tail" style={{ color: '#fff', fontSize: 17, fontWeight: '700', textAlign: 'center' }}>
              {cur.project ? `${cur.project.code} · ${cur.project.name}` : ''}
            </Text>
          </View>
          <Pressable testID="next-project" accessibilityLabel={t('day.next_project')} onPress={() => stepTo(1)} style={{ padding: 8, opacity: cur.projects.length > 1 ? 1 : 0.4 }}><Text style={{ color: '#fff', fontSize: 22 }}>›</Text></Pressable>
          <Pressable testID="project-search-open" accessibilityRole="button" accessibilityLabel={t('day.search')} hitSlop={6} onPress={() => setSearch(true)} style={{ padding: 8, marginLeft: 2 }}><SearchIcon /></Pressable>
        </View>
      )}

      {/* 3. El servicio del proyecto escogido (con uno solo queda escogido y se muestra discreto) */}
      {cur.project && cur.services.length > 1 ? (
        <View style={{ paddingHorizontal: 12, paddingTop: 8, alignItems: 'flex-start', maxWidth: '100%' }}>
          <Chip testID="service-pill" on={!!cur.service} label={cur.service ? `${t('day.service')}: ${cur.service.name}` : t('day.pick_service')} onPress={() => setSvcOpen(true)} />
        </View>
      ) : cur.project && cur.services.length === 1 ? (
        <Text testID="service-single" numberOfLines={1} style={{ color: C.muted, fontSize: 12, paddingHorizontal: 14, paddingTop: 6 }}>{t('day.service')}: {cur.services[0].name}</Text>
      ) : null}

      {/* La tarea es obligatoria si el proyecto escogido tiene tareas; si no tiene, no se muestra nada */}
      {cur.project && cur.tasks.length > 0 ? (
        <View style={{ paddingHorizontal: 12, paddingTop: 8, alignItems: 'flex-start', maxWidth: '100%' }}>
          <Chip testID="task-pill" on={!!cur.task} label={cur.task ? `${t('day.task')}: ${cur.task.name}` : t('day.pick_task')} onPress={() => setTaskOpen(true)} />
        </View>
      ) : null}

      {clock && (
        <View testID="running" style={{ backgroundColor: '#e6f4ea', borderBottomWidth: 1, borderColor: '#9bd1ac', padding: 10, marginTop: 8, flexDirection: 'row', alignItems: 'center' }}>
          <View style={{ flex: 1, minWidth: 0 }}>
            <Text numberOfLines={1} style={{ fontWeight: '700' }}>
              {clockProject ? `${clockProject.code} · ${clockProject.name}` : t('day.clock_running')}{clockService ? ` · ${clockService.name}` : ''}{taskName(clockProject, clock.task_id) ? ` · ${taskName(clockProject, clock.task_id)}` : ''}
            </Text>
            <Text style={{ color: C.muted }}>{t('day.since', { time: to12h(nowHM(new Date(clock.started_at))) })}</Text>
          </View>
          <Text testID="elapsed" style={{ fontSize: 24, fontWeight: '800', fontVariant: ['tabular-nums'] }}>{fmtElapsed(elapsedMs(clock, new Date(now)))}</Text>
        </View>
      )}

      {/* 4. Botones (2 columnas): ver dayButtonRows */}
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', borderTopWidth: 1, borderBottomWidth: 1, borderColor: C.line, marginTop: 8 }}>
        {dayButtonRows({ fromTo: fromTo || !!clock, repeat: dayActionAllowed('repeat', limited), copyPrev: dayActionAllowed('copy_prev', limited) })
          .flat()
          .map((b) => (
            <GridBtn key={b.id} wide={b.wide} testID={b.id} title={t(DAY_BUTTON_LABEL[b.id])} disabled={b.id === 'check-in' ? !!clock : b.id === 'check-out' ? !clock : false} onPress={() => run(b.id)} />
          ))}
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
                    {project(e.project_id)?.code ?? '?'}{serviceName(e) ? ' · ' + serviceName(e) : ''}{taskOf(e) ? ' · ' + taskOf(e) : ''}{e.note ? ' · ' + e.note : ''}
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

      <ProjectSheet visible={search} currentId={cur.project?.id} onPick={pickProject} onClose={() => setSearch(false)} />

      <Sheet visible={svcOpen} title={t('day.choose_service')} onClose={() => setSvcOpen(false)}>
        {cur.services.map((sv) => (
          <Pressable key={sv.id} testID={`pick-svc-${sv.id}`} onPress={() => chooseService(sv.id)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
            <Text style={{ fontSize: 16, fontWeight: cur.service?.id === sv.id ? '800' : '600' }}>{sv.name}{cur.service?.id === sv.id ? '  ✓' : ''}</Text>
          </Pressable>
        ))}
      </Sheet>

      <Sheet visible={taskOpen} title={t('day.choose_task')} onClose={() => setTaskOpen(false)}>
        {cur.tasks.map((x) => (
          <Pressable key={x.id} testID={`pick-task-${x.id}`} onPress={() => chooseTask(x.id)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
            <Text style={{ fontSize: 16, fontWeight: cur.task?.id === x.id ? '800' : '600' }}>{x.name}{cur.task?.id === x.id ? '  ✓' : ''}</Text>
          </Pressable>
        ))}
      </Sheet>

      <Sheet visible={tmpl} title={t('day.repeat_title')} onClose={() => setTmpl(false)}>
        {recent.length === 0 && <Text style={{ color: C.muted }}>{t('day.repeat_empty')}</Text>}
        {recent.map((e) => (
          <Pressable key={e.uuid} onPress={() => repeat(e)} style={{ paddingVertical: 12, borderBottomWidth: 1, borderColor: '#eee' }}>
            <Text style={{ fontSize: 16, fontWeight: '600' }}>
              {project(e.project_id)?.code}{serviceName(e) ? ' · ' + serviceName(e) : ''}{taskOf(e) ? ' · ' + taskOf(e) : ''} · {e.start_time ? `${to12h(e.start_time)} – ${to12h(e.end_time ?? '00:00')}` : fmtHours(e.hours, decimal) + ' h'}
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
