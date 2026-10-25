import React, { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { useAuth } from '../src/store/auth';
import { Btn, C, Card, Field, Msg, useTheme } from '../src/ui';

export default function Login() {
  const t = useTheme();
  const { baseUrl, login } = useAuth();
  const [url, setUrl] = useState(baseUrl);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError('');
    try {
      await login(url, email.trim(), password);
    } catch (e: any) {
      setError(e?.message ?? 'No se pudo entrar.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: C.bg }}>
      <ScrollView contentContainerStyle={{ padding: 20, paddingTop: 70 }} keyboardShouldPersistTaps="handled">
        <View style={{ alignItems: 'center', marginBottom: 24 }}>
          {t.brand.logo_url ? <Image source={{ uri: t.brand.logo_url }} style={{ width: 200, height: 60 }} resizeMode="contain" /> : <Text style={{ fontSize: 30, fontWeight: '800', color: t.primary }}>{t.brand.name}</Text>}
        </View>
        <Card>
          <Text style={{ fontSize: 20, fontWeight: '700', marginBottom: 12 }}>Entrar</Text>
          {error ? <Msg kind="err" text={error} /> : null}
          <Field testID="url" label="Servidor" value={url} onChangeText={setUrl} keyboardType="email-address" placeholder="https://tiempo.tuempresa.com" />
          <Field testID="email" label="Correo" value={email} onChangeText={setEmail} keyboardType="email-address" />
          <Field testID="password" label="Contraseña" value={password} onChangeText={setPassword} secure />
          <Btn testID="login" title={busy ? 'Entrando…' : 'Entrar'} kind="pri" disabled={busy || !url || !email || !password} onPress={submit} />
        </Card>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
