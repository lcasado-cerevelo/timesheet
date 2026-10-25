jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
jest.mock('expo-secure-store', () => ({}));
jest.mock('expo-notifications', () => ({}));

import React from 'react';
import { TextInput } from 'react-native';
const TestRenderer = require('react-test-renderer');
const { act } = TestRenderer;
import { DICTS, setLocaleValue } from '../src/i18n';
import { ApiError, api, configureApi } from '../src/lib/api';
import { loginErrorMessage, passwordServerErrors, validatePasswordChange } from '../src/lib/authErrors';
import { normalizeServerUrl, resolveServer } from '../src/lib/server';
import { PasswordInput, passwordFieldProps } from '../src/ui';
import { useAuth } from '../src/store/auth';

beforeEach(() => setLocaleValue('es'));

describe('resolución del servidor', () => {
  const base = { fixed: '', stored: '', devDefault: '', dev: false };

  test('el servidor fijo al compilar manda sobre lo guardado y no se muestra', () => {
    const r = resolveServer({ ...base, fixed: 'https://tiempo.acme.com/', stored: 'https://otro.com' });
    expect(r).toEqual({ url: 'https://tiempo.acme.com', fixed: true, misconfigured: false });
  });
  test('en producción se rechaza http:// (fijo) y no se usa lo guardado', () => {
    expect(resolveServer({ ...base, fixed: 'http://tiempo.acme.com' })).toEqual({ url: '', fixed: true, misconfigured: true });
    expect(resolveServer({ ...base, stored: 'https://otro.com' })).toEqual({ url: '', fixed: false, misconfigured: true });
  });
  test('en desarrollo sin fijo se usa lo guardado o el de desarrollo, y http:// se acepta', () => {
    expect(resolveServer({ ...base, dev: true, stored: 'http://192.168.1.5:8000/' }).url).toBe('http://192.168.1.5:8000');
    expect(resolveServer({ ...base, dev: true, devDefault: 'http://10.0.2.2:8000' })).toEqual({ url: 'http://10.0.2.2:8000', fixed: false, misconfigured: false });
    expect(resolveServer({ ...base, dev: true, fixed: 'http://localhost:8000' }).url).toBe('http://localhost:8000');
  });
  test('normalizeServerUrl', () => {
    expect(normalizeServerUrl(' https://a.com/// ', false)).toBe('https://a.com');
    expect(normalizeServerUrl('http://a.com', false)).toBeNull();
    expect(normalizeServerUrl('a.com', true)).toBeNull();
    expect(normalizeServerUrl('', true)).toBeNull();
    expect(normalizeServerUrl('ftp://a.com', true)).toBeNull();
  });
});

describe('respuestas de login y cambio de contraseña', () => {
  const realFetch = globalThis.fetch;
  afterEach(() => { globalThis.fetch = realFetch; configureApi({ baseUrl: '', token: null, onUnauthorized: undefined }); });
  const reply = (status: number, body: unknown) => { globalThis.fetch = jest.fn().mockResolvedValue({ ok: status < 400, status, text: async () => JSON.stringify(body) }) as any; };

  test('403 approver_web_only muestra el aviso bilingüe, sin importar el texto del servidor', async () => {
    reply(403, { message: 'x', code: 'approver_web_only' });
    const e = await api('/login', { method: 'POST', body: {} }).catch((x) => x);
    expect(e).toBeInstanceOf(ApiError);
    expect(e.code).toBe('approver_web_only');
    expect(loginErrorMessage(e)).toBe('Las cuentas de aprobador entran por la web.');
    setLocaleValue('en');
    expect(loginErrorMessage(e)).toBe('Approver accounts sign in on the web.');
  });
  test('credenciales malas: mensaje del servidor o uno claro por omisión', () => {
    expect(loginErrorMessage(new ApiError('Contraseña incorrecta', 422))).toBe('Contraseña incorrecta');
    expect(loginErrorMessage(new ApiError('Error 401', 401))).toBe('Correo o contraseña incorrectos.');
  });
  test('401 con sesión: avisa al almacén y lanza el mensaje de sesión cerrada', async () => {
    const onUnauthorized = jest.fn();
    configureApi({ baseUrl: 'https://x.com', token: 't', onUnauthorized });
    reply(401, { message: 'Unauthenticated.' });
    await expect(api('/entries')).rejects.toThrow('Tu sesión se cerró. Entra de nuevo.');
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });
  test('401 sin sesión (login) no dispara el cierre forzado', async () => {
    const onUnauthorized = jest.fn();
    configureApi({ baseUrl: 'https://x.com', token: null, onUnauthorized });
    reply(401, { message: 'Credenciales inválidas' });
    await expect(api('/login', { method: 'POST', body: {} })).rejects.toThrow('Credenciales inválidas');
    expect(onUnauthorized).not.toHaveBeenCalled();
  });
  test('cambio de contraseña: validación local y errores 422 por campo', () => {
    expect(validatePasswordChange({ current: '', next: 'corta' })).toEqual({ current: 'Escribe tu contraseña actual.', next: 'La contraseña nueva debe tener al menos 10 caracteres.' });
    expect(validatePasswordChange({ current: 'abc', next: 'abc' }).next).toMatch(/10 caracteres/);
    expect(validatePasswordChange({ current: 'abcdefghijk', next: 'abcdefghijk' }).next).toBe('La contraseña nueva debe ser distinta de la actual.');
    expect(validatePasswordChange({ current: 'vieja-clave', next: 'nueva-clave-larga' })).toEqual({});
    const e = new ApiError('m', 422, { current_password: ['La contraseña actual es incorrecta.'] });
    expect(passwordServerErrors(e)).toEqual({ current: 'La contraseña actual es incorrecta.', next: undefined });
    expect(passwordServerErrors(new Error('boom'))).toEqual({ general: 'boom' });
  });
});

describe('cierre de sesión forzado', () => {
  test('conserva las entradas guardadas y deja el aviso para el login', async () => {
    const AsyncStorage = require('@react-native-async-storage/async-storage');
    await AsyncStorage.setItem('entries.v1', '[{"uuid":"u1"}]');
    useAuth.setState({ token: 't', me: { id: 1 } as any });
    await useAuth.getState().sessionExpired();
    const s = useAuth.getState();
    expect(s.token).toBeNull();
    expect(s.sessionClosed).toBe(true);
    expect(await AsyncStorage.getItem('entries.v1')).toBe('[{"uuid":"u1"}]');
  });
});

describe('contraseña: ojito y textos', () => {
  test('el ojito alterna secureTextEntry y usa autocompletado correcto', () => {
    expect(passwordFieldProps(false).secureTextEntry).toBe(true);
    expect(passwordFieldProps(true).secureTextEntry).toBe(false);
    expect(passwordFieldProps(false, 'new').autoComplete).toBe('new-password');
    expect(passwordFieldProps(false).autoComplete).toBe('current-password');
  });
  test('PasswordInput: botón accesible traducido que muestra y oculta', () => {
    let r: any;
    act(() => { r = TestRenderer.create(<PasswordInput testID="pw" label="L" value="abc" onChangeText={() => undefined} />); });
    expect(r.root.findByType(TextInput).props.secureTextEntry).toBe(true);
    const eye = r.root.findByProps({ testID: 'pw-eye' });
    expect(eye.props.accessibilityLabel).toBe('Mostrar contraseña');
    act(() => { eye.props.onPress(); });
    expect(r.root.findByType(TextInput).props.secureTextEntry).toBe(false);
    expect(r.root.findByProps({ testID: 'pw-eye' }).props.accessibilityLabel).toBe('Ocultar contraseña');
  });
  test('textos nuevos en es y en', () => {
    for (const k of ['login.approver_web_only', 'login.help', 'password.forgot', 'password.show', 'password.hide', 'password.done', 'api.expired']) {
      expect((DICTS.es as any)[k]).toBeTruthy();
      expect((DICTS.en as any)[k]).toBeTruthy();
    }
    expect(DICTS.es['password.forgot']).toBe('Si no recuerdas la actual, llama a tu administrador para que te la restablezca.');
    expect(DICTS.es['login.help']).toMatch(/administrador/);
    expect(DICTS.es['login.help']).toMatch(/correo/);
  });
});
