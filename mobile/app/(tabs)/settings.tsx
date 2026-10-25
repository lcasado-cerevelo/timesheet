import React, { useState } from 'react';
import { ScrollView, Switch, Text, View } from 'react-native';
import { ensureNotificationPermission, setWeeklyReminder } from '../../src/lib/notify';
import { startWatching, stopWatching } from '../../src/lib/geofence';
import { useT } from '../../src/i18n';
import { api } from '../../src/lib/api';
import { MIN_PASSWORD, passwordServerErrors, validatePasswordChange } from '../../src/lib/authErrors';
import { useAuth } from '../../src/store/auth';
import { useEntries } from '../../src/store/entries';
import { useSettings } from '../../src/store/settings';
import { Btn, C, Card, Chip, Msg, PasswordInput, useBottomPad, useTheme } from '../../src/ui';

function Row(props: { label: string; hint?: string; value: boolean; onChange: (v: boolean) => void; testID?: string }) {
  const th = useTheme();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', paddingVertical: 10 }}>
      <View style={{ flex: 1, paddingRight: 10 }}>
        <Text style={{ fontWeight: '600' }}>{props.label}</Text>
        {props.hint ? <Text style={{ color: C.muted, fontSize: 12 }}>{props.hint}</Text> : null}
      </View>
      <Switch testID={props.testID} value={props.value} onValueChange={props.onChange} trackColor={{ true: th.accent }} />
    </View>
  );
}

function ChangePassword() {
  const { t } = useT();
  const [open, setOpen] = useState(false);
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [errs, setErrs] = useState<{ current?: string; next?: string; general?: string }>({});
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setDone(false);
    const local = validatePasswordChange({ current, next });
    setErrs(local);
    if (local.current || local.next) return;
    setBusy(true);
    try {
      await api('/password/change', { method: 'POST', body: { current_password: current, password: next } });
      setCurrent('');
      setNext('');
      setOpen(false);
      setDone(true);
    } catch (e) {
      setErrs(passwordServerErrors(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <Text style={{ fontWeight: '700', marginBottom: 4 }}>{t('password.title')}</Text>
      {done ? <Msg kind="ok" text={t('password.done')} /> : null}
      {!open ? (
        <Btn testID="pw-open" kind="ghost" title={t('password.title')} onPress={() => { setOpen(true); setDone(false); }} />
      ) : (
        <View>
          {errs.general ? <Msg kind="err" text={errs.general} /> : null}
          <PasswordInput testID="pw-current" label={t('password.current')} value={current} onChangeText={setCurrent} error={errs.current} />
          <PasswordInput testID="pw-new" kind="new" label={t('password.new')} value={next} onChangeText={setNext} error={errs.next} hint={t('password.new_hint', { n: MIN_PASSWORD })} />
          <Text style={{ color: C.muted, fontSize: 12, marginBottom: 10 }}>{t('password.forgot')}</Text>
          <Btn testID="pw-save" kind="pri" title={busy ? t('password.saving') : t('password.save')} disabled={busy || !current || !next} onPress={submit} />
        </View>
      )}
    </Card>
  );
}

export default function Settings() {
  const me = useAuth((s) => s.me)!;
  const { t, locale } = useT();
  const bottom = useBottomPad(12);
  const setLanguage = useAuth((s) => s.setLanguage);
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
    if (!(await ensureNotificationPermission())) return setNote({ kind: 'warn', text: t('settings.allow_notifications') });
    const r = await startWatching(projects);
    if (!r.ok) return setNote({ kind: 'warn', text: r.message ?? t('settings.arrive_fail') });
    set({ arriveAlerts: true });
    setNote({ kind: 'ok', text: t('settings.watching', { n: r.count }) });
  };

  const toggleReminder = async (on: boolean) => {
    if (on && !(await ensureNotificationPermission())) return setNote({ kind: 'warn', text: t('settings.allow_notifications_reminder') });
    await setWeeklyReminder(on);
    set({ weeklyReminder: on });
  };

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 12, paddingBottom: bottom }}>
      <Card>
        <Text style={{ fontWeight: '800', fontSize: 16 }}>{me.name}</Text>
        <Text style={{ color: C.muted }}>{me.email} · {me.company.name}</Text>
        <Text style={{ marginTop: 6 }}>{me.worker_type === 'employee' ? t('settings.employee', { n: Number(me.weekly_hours ?? 40) }) : t('settings.consultant')}</Text>
        <Text style={{ color: C.muted, fontSize: 12, marginTop: 4 }}>
          {t('settings.pref', { mode: me.capture_mode === 'full_day' ? t('settings.pref_total') : t('settings.pref_detail') })} {t('settings.pref_note')}
        </Text>
      </Card>

      <Card>
        <Text style={{ fontWeight: '700', marginBottom: 8 }}>{t('settings.language')}</Text>
        <View style={{ flexDirection: 'row' }}>
          <Chip testID="lang-es" label="Español" on={locale === 'es'} onPress={() => void setLanguage('es')} />
          <Chip testID="lang-en" label="English" on={locale === 'en'} onPress={() => void setLanguage('en')} />
        </View>
      </Card>

      <Card>
        {note ? <Msg kind={note.kind} text={note.text} /> : null}
        <Row testID="decimal" label={t('settings.decimal')} hint={t('settings.decimal_hint')} value={decimal} onChange={(v) => set({ decimal: v })} />
        <Row testID="arrive" label={t('settings.arrive')} hint={withPlace ? t('settings.arrive_hint', { n: withPlace }) : t('settings.arrive_none')} value={arriveAlerts} onChange={toggleArrive} />
        <Row testID="weekly" label={t('settings.weekly')} hint={t('settings.weekly_hint')} value={weeklyReminder} onChange={toggleReminder} />
      </Card>

      <Card>
        <Text style={{ fontWeight: '700', marginBottom: 4 }}>{t('settings.sync')}</Text>
        <Text testID="pending">{pending === 0 ? t('settings.all_synced') : t('settings.pending', { n: pending })}{rejected ? ' ' + t('settings.rejected', { n: rejected }) : ''}</Text>
        <Text style={{ color: C.muted, fontSize: 12, marginVertical: 4 }}>
          {lastSync ? t('settings.last_sync', { when: new Date(lastSync).toLocaleString(locale) }) : t('settings.never_synced')}
        </Text>
        {lastError && !syncing ? <Msg kind="warn" text={lastError} /> : null}
        <Btn testID="sync" kind="ghost" title={syncing ? t('settings.syncing') : t('settings.sync_now')} disabled={syncing} onPress={() => void sync()} />
      </Card>

      <ChangePassword />

      <Btn testID="logout" kind="danger" title={t('settings.logout')} onPress={() => void logout()} />
    </ScrollView>
  );
}
