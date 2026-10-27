import React, { useMemo, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useT } from '../src/i18n';
import { ProjectSheet } from '../src/pickers';
import { computeHours } from '../src/lib/rules';
import { activeServices, needsService, resolveService, serviceMode } from '../src/lib/services';
import { roundedNowHM } from '../src/lib/increment';
import { activeTasks, resolveTask } from '../src/lib/tasks';
import { fmtHours, longDate } from '../src/lib/time';
import { useAuth } from '../src/store/auth';
import { useEntries } from '../src/store/entries';
import { computeCurrent, useSelection } from '../src/store/selection';
import { Btn, C, Card, Chip, Field, Msg, TimeField, useBottomPad, useTheme } from '../src/ui';

/**
 * Agregar o editar una entrada. Muestra el proyecto actual y sus servicios; lo que se pide (desde/hasta con reloj,
 * o las horas del día) lo decide el servicio escogido (y, si permite ambos, la preferencia de la persona).
 */
export default function EntryScreen() {
  const router = useRouter();
  const th = useTheme();
  const { t } = useT();
  const bottom = useBottomPad(24);
  const params = useLocalSearchParams<{ uuid?: string; date?: string }>();
  const me = useAuth((s) => s.me)!;
  const { entries, projects, selectedDate, save } = useEntries();
  const sel = useSelection();
  const editing = params.uuid ? entries.find((e) => e.uuid === params.uuid) : undefined;
  const current = computeCurrent(projects, sel.projectId, sel.services, me.capture_mode, sel.tasks);

  const [projectId, setProjectId] = useState<number | null>(editing?.project_id ?? current.project?.id ?? null);
  const [serviceId, setServiceId] = useState<number | null>(editing ? editing.service_id ?? null : current.service?.id ?? null);
  const [taskId, setTaskId] = useState<number | null>(editing ? editing.task_id ?? null : current.task?.id ?? null);
  const [pickProject, setPickProject] = useState(false);
  const [date] = useState(editing?.work_date ?? params.date ?? selectedDate);
  const [start, setStart] = useState<string | null>(editing?.start_time ?? roundedNowHM(me.rules.increment));
  const [end, setEnd] = useState<string | null>(editing?.end_time ?? null);
  const [hours, setHours] = useState(editing && !editing.start_time ? String(editing.hours) : '');
  const [note, setNote] = useState(editing?.note ?? '');
  const [errors, setErrors] = useState<string[]>([]);

  const project = projects.find((p) => p.id === projectId);
  const choices = useMemo(() => {
    const list = activeServices(project);
    const keep = editing && editing.project_id === projectId && editing.service_id ? project?.services?.find((s) => s.id === editing.service_id) : undefined;
    return keep && !list.some((s) => s.id === keep.id) ? [...list, keep] : list;
  }, [project, editing, projectId]);
  // Tareas (opcionales): solo se muestran si el proyecto tiene; una desactivada se conserva únicamente en la entrada que ya la tenía.
  const taskChoices = useMemo(() => {
    const list = activeTasks(project);
    const keep = editing && editing.project_id === projectId && editing.task_id ? project?.tasks?.find((x) => x.id === editing.task_id) : undefined;
    return keep && !list.some((x) => x.id === keep.id) ? [...list, keep] : list;
  }, [project, editing, projectId]);
  const task = resolveTask(project, taskId, editing?.task_id);
  // Con un solo servicio no se pide escoger; con varios hay que escoger uno.
  const service = resolveService(project, serviceId, editing?.service_id);
  const mustChoose = needsService(project) && !service;
  const mode = service ? serviceMode(service, me.capture_mode) : me.capture_mode;
  const fromTo = mode === 'from_to';

  const calc = useMemo(
    () => computeHours({ project_id: projectId, work_date: date, start_time: start, end_time: end, hours: Number(hours.replace(',', '.')) || 0 }, mode, me.rules.increment),
    [projectId, date, start, end, hours, mode, me],
  );

  const chooseProject = (id: number) => {
    setPickProject(false);
    setProjectId(id);
    sel.setProject(id);
    const next = computeCurrent(projects, id, sel.services, me.capture_mode, sel.tasks);
    setServiceId(next.service?.id ?? null);
    setTaskId(next.task?.id ?? null);
  };

  const chooseTask = (id: number | null) => {
    setTaskId(id);
    if (projectId) sel.setTask(projectId, id);
  };

  const chooseService = (id: number) => {
    setServiceId(id);
    if (projectId) sel.setService(projectId, id);
  };

  const submit = () => {
    const r = save(
      {
        project_id: projectId, service_id: service?.id ?? serviceId, task_id: task?.id ?? null, work_date: date,
        start_time: fromTo ? start : null, end_time: fromTo ? end : null, hours: fromTo ? null : Number(hours.replace(',', '.')) || 0, note,
      },
      editing?.uuid,
    );
    if (r.errors.length) return setErrors(r.errors);
    router.back();
  };

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 14, paddingBottom: bottom }} keyboardShouldPersistTaps="handled">
      <Card>
        <Text style={{ fontWeight: '700', marginBottom: 10 }}>{editing ? t('entry.edit') : fromTo ? t('day.add_entry_title') : t('day.add_hours_title')} · {longDate(date)}</Text>
        {errors.map((e) => <Msg key={e} kind="err" text={e} />)}
        {editing?.sync === 'rejected' && editing.reject_reason ? <Msg kind="warn" text={editing.reject_reason} /> : null}

        <Text style={{ fontSize: 12, color: C.muted, marginBottom: 4 }}>{t('entry.project')}</Text>
        <Pressable testID="entry-project" onPress={() => setPickProject(true)} style={{ paddingVertical: 10, paddingHorizontal: 12, borderRadius: 8, marginBottom: 12, borderWidth: 1, borderColor: th.accent, backgroundColor: '#eef7f1', flexDirection: 'row', alignItems: 'center' }}>
          <Text style={{ flex: 1, fontWeight: '700' }}>{project ? `${project.code} · ${project.name}` : t('entry.no_project')}</Text>
          <Text style={{ color: th.primary, fontWeight: '700' }}>{t('entry.change')}</Text>
        </Pressable>

        {choices.length > 1 ? (
          <>
            <Text style={{ fontSize: 12, color: C.muted, marginBottom: 4 }}>{t('entry.service')}</Text>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', rowGap: 8, marginBottom: 12 }}>
              {choices.map((s) => <Chip key={s.id} testID={`svc-${s.id}`} label={s.name} on={service?.id === s.id} onPress={() => chooseService(s.id)} />)}
            </View>
          </>
        ) : choices.length === 1 ? (
          <Text testID="svc-single" style={{ color: C.muted, marginBottom: 12 }}>{t('entry.service')}: {choices[0].name}</Text>
        ) : null}

        {taskChoices.length > 0 ? (
          <>
            <Text style={{ fontSize: 12, color: C.muted, marginBottom: 4 }}>{t('entry.task')}</Text>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', rowGap: 8, marginBottom: 12 }}>
              <Chip testID="task-none" label={t('entry.no_task')} on={!task} onPress={() => chooseTask(null)} />
              {taskChoices.map((x) => <Chip key={x.id} testID={`task-${x.id}`} label={x.name} on={task?.id === x.id} onPress={() => chooseTask(x.id)} />)}
            </View>
          </>
        ) : null}

        {mustChoose ? (
          <Text style={{ color: C.muted, marginBottom: 12 }}>{t('entry.pick_service')}</Text>
        ) : fromTo ? (
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <TimeField testID="start" label={t('entry.from')} value={start} onChange={setStart} />
            <TimeField testID="end" label={t('entry.to')} value={end} onChange={setEnd} />
          </View>
        ) : (
          <Field testID="hours" label={t('entry.hours_of_day')} value={hours} onChangeText={setHours} keyboardType="decimal-pad" placeholder="8" />
        )}
        <Text style={{ color: C.muted, marginBottom: 10 }}>{t('entry.total')}: <Text style={{ fontWeight: '800', color: C.text }}>{fmtHours(calc, true)} h</Text></Text>
        <Field testID="note" label={t('entry.note')} value={note} onChangeText={setNote} multiline autoCapitalize="sentences" />
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <Btn testID="save" title={t('common.save')} kind="pri" onPress={submit} />
          <Btn title={t('common.cancel')} kind="ghost" onPress={() => router.back()} />
        </View>
      </Card>
      <ProjectSheet visible={pickProject} currentId={projectId} onPick={chooseProject} onClose={() => setPickProject(false)} />
    </ScrollView>
  );
}
