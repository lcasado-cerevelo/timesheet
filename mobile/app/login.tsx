import React, { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, View } from 'react-native';
import { useT } from '../src/i18n';
import { loginErrorMessage } from '../src/lib/authErrors';
import { useAuth } from '../src/store/auth';
import { useBottomPad } from '../src/ui';
import { Btn, C, Card, Field, Msg, PasswordInput, useTheme } from '../src/ui';

export default function Login() {
  const th = useTheme();
  const { t } = useT();
  const bottom = useBottomPad(20);
  const { baseUrl, serverFixed, misconfigured, sessionClosed, login } = useAuth();
  const [url, setUrl] = useState(baseUrl);
  const [devOpen, setDevOpen] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  // El campo de servidor solo existe en desarrollo y sin servidor fijado al compilar.
  const showDeveloper = __DEV__ && !serverFixed;

  const submit = async () => {
    setBusy(true);
    setError('');
    try {
      await login(email.trim(), password, showDeveloper ? url : undefined);
    } catch (e) {
      setError(loginErrorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: C.bg }}>
      <ScrollView contentContainerStyle={{ padding: 20, paddingTop: 70, paddingBottom: bottom }} keyboardShouldPersistTaps="handled">
        <View style={{ alignItems: 'center', marginBottom: 24 }}>
          {th.brand.logo_url ? <Image source={{ uri: th.brand.logo_url }} style={{ width: 200, height: 60 }} resizeMode="contain" /> : <Text style={{ fontSize: 30, fontWeight: '800', color: th.primary }}>{th.brand.name}</Text>}
        </View>
        <Card>
          <Text style={{ fontSize: 20, fontWeight: '700', marginBottom: 12 }}>{t('login.title')}</Text>
          {sessionClosed && !error ? <Msg kind="warn" text={t('api.expired')} /> : null}
          {misconfigured && !showDeveloper ? <Msg kind="err" text={t('login.no_server')} /> : null}
          {error ? <Msg kind="err" text={error} /> : null}
          <Field testID="email" label={t('login.email')} value={email} onChangeText={setEmail} keyboardType="email-address" />
          <PasswordInput testID="password" label={t('login.password')} value={password} onChangeText={setPassword} />
          <Btn testID="login" title={busy ? t('login.busy') : t('login.title')} kind="pri" disabled={busy || !email || !password || (!showDeveloper && misconfigured)} onPress={submit} />
          <Text style={{ color: C.muted, fontSize: 12, marginTop: 14 }}>{t('login.help')}</Text>
        </Card>
        {showDeveloper ? (
          <Card>
            <Pressable testID="dev-toggle" accessibilityRole="button" onPress={() => setDevOpen((v) => !v)}>
              <Text style={{ fontWeight: '700', color: C.muted }}>{devOpen ? '▾ ' : '▸ '}{t('login.developer')}</Text>
            </Pressable>
            {devOpen ? (
              <View style={{ marginTop: 10 }}>
                <Text style={{ color: C.muted, fontSize: 12, marginBottom: 8 }}>{t('login.developer_hint')}</Text>
                <Field testID="url" label={t('login.server')} value={url} onChangeText={setUrl} keyboardType="email-address" placeholder="https://tiempo.tuempresa.com" />
              </View>
            ) : null}
          </Card>
        ) : null}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
