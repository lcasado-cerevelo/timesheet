import React, { useEffect, useState } from 'react';
import { Image, Text, View } from 'react-native';
import { useT } from './i18n';
import { useAuth } from './store/auth';
import { Btn, C, Msg } from './ui';

/** Pantalla de bloqueo: con la huella activada, la sesión guardada no se ve hasta que la persona se verifica. */
export function LockScreen() {
  const { t } = useT();
  const brand = useAuth((s) => s.brand);
  const unlock = useAuth((s) => s.unlock);
  const logout = useAuth((s) => s.logout);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);

  const run = async () => {
    if (busy) return;
    setBusy(true);
    setFailed(false);
    const ok = await unlock();
    setBusy(false);
    if (!ok) setFailed(true);
  };

  useEffect(() => {
    void run();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <View testID="lock" style={{ position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, zIndex: 50, backgroundColor: C.bg, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      {brand.logo_url ? <Image source={{ uri: brand.logo_url }} style={{ width: 200, height: 60, resizeMode: 'contain', marginBottom: 12 }} /> : <Text style={{ fontSize: 22, fontWeight: '800', marginBottom: 12 }}>{brand.name}</Text>}
      <Text style={{ color: C.muted, marginBottom: 20 }}>{t('bio.locked')}</Text>
      {failed ? <Msg kind="warn" text={t('bio.failed')} /> : null}
      <Btn testID="unlock" title={t('bio.unlock')} onPress={() => void run()} disabled={busy} kind="pri" style={{ alignSelf: 'stretch', marginBottom: 10 }} />
      <Btn testID="use-password" title={t('bio.use_password')} onPress={() => void logout()} kind="ghost" style={{ alignSelf: 'stretch' }} />
    </View>
  );
}
