jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
const mockSecure: Record<string, string> = {};
jest.mock('expo-secure-store', () => ({
  getItemAsync: async (k: string) => mockSecure[k] ?? null,
  setItemAsync: async (k: string, v: string) => { mockSecure[k] = v; },
  deleteItemAsync: async (k: string) => { delete mockSecure[k]; },
}));
jest.mock('expo-notifications', () => ({}));
jest.mock('@react-native-community/netinfo', () => ({ __esModule: true, default: { fetch: jest.fn(), addEventListener: jest.fn() } }));
const mockBio = { available: true, ok: true };
jest.mock('../src/lib/biometric', () => ({
  biometricAvailable: async () => mockBio.available,
  biometricPrompt: async () => mockBio.ok,
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import { useAuth } from '../src/store/auth';

const me = { id: 1, name: 'Luis', email: 'l@x.com', locale: 'es', remember_days: 30, brand: { name: 'X', color_primary: '#000', color_accent: '#000' } };

describe('entrar con huella: desbloquea la sesión guardada', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
    for (const k of Object.keys(mockSecure)) delete mockSecure[k];
    mockBio.available = true;
    mockBio.ok = true;
    useAuth.setState({ token: null, me: null, biometric: false, locked: false, ready: false, baseUrl: 'https://t.example.com', serverFixed: true });
  });

  test('con la huella activada y una sesión guardada, la app abre bloqueada', async () => {
    await AsyncStorage.setItem('biometric.v1', 'true');
    await AsyncStorage.setItem('me', JSON.stringify(me));
    mockSecure.token = 'tok';
    await useAuth.getState().init();
    expect(useAuth.getState().token).toBe('tok');
    expect(useAuth.getState().locked).toBe(true);
  });

  test('sin la huella activada la app abre como siempre', async () => {
    await AsyncStorage.setItem('me', JSON.stringify(me));
    mockSecure.token = 'tok';
    await useAuth.getState().init();
    expect(useAuth.getState().locked).toBe(false);
  });

  test('sin sesión guardada no hay nada que bloquear', async () => {
    await AsyncStorage.setItem('biometric.v1', 'true');
    await useAuth.getState().init();
    expect(useAuth.getState().locked).toBe(false);
  });

  test('la huella correcta desbloquea y la incorrecta no', async () => {
    useAuth.setState({ token: 'tok', locked: true });
    mockBio.ok = false;
    expect(await useAuth.getState().unlock()).toBe(false);
    expect(useAuth.getState().locked).toBe(true);
    mockBio.ok = true;
    expect(await useAuth.getState().unlock()).toBe(true);
    expect(useAuth.getState().locked).toBe(false);
  });

  test('activarla exige sensor y una huella válida; desactivarla siempre se puede', async () => {
    const f = jest.fn().mockResolvedValue({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ enabled: true }), text: async () => '{}' });
    globalThis.fetch = f as any;
    useAuth.setState({ token: 'tok' });
    mockBio.available = false;
    expect(await useAuth.getState().setBiometric(true)).toBe(false);
    expect(useAuth.getState().biometric).toBe(false);

    mockBio.available = true;
    mockBio.ok = false;
    expect(await useAuth.getState().setBiometric(true)).toBe(false);
    expect(useAuth.getState().biometric).toBe(false);

    mockBio.ok = true;
    expect(await useAuth.getState().setBiometric(true)).toBe(true);
    expect(useAuth.getState().biometric).toBe(true);
    expect(await AsyncStorage.getItem('biometric.v1')).toBe('true');
    expect(JSON.parse(f.mock.calls[0][1].body)).toEqual({ enabled: true });   // el servidor marca esta sesión

    expect(await useAuth.getState().setBiometric(false)).toBe(true);
    expect(useAuth.getState().biometric).toBe(false);
  });

  test('cerrar la sesión olvida la huella y el bloqueo', async () => {
    await AsyncStorage.setItem('biometric.v1', 'true');
    useAuth.setState({ token: 'tok', me: me as any, biometric: true, locked: true });
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({}), text: async () => '{}' }) as any;
    await useAuth.getState().logout();
    expect(useAuth.getState().biometric).toBe(false);
    expect(useAuth.getState().locked).toBe(false);
    expect(await AsyncStorage.getItem('biometric.v1')).toBeNull();
  });
  test('con la regla "recordar" en 0 y la huella activada, la app conserva la sesión y abre bloqueada', async () => {
    await AsyncStorage.setItem('biometric.v1', 'true');
    await AsyncStorage.setItem('me', JSON.stringify({ ...me, remember_days: 0 }));
    mockSecure.token = 'tok';
    await useAuth.getState().init();
    expect(useAuth.getState().token).toBe('tok');
    expect(mockSecure.token).toBe('tok');
    expect(useAuth.getState().locked).toBe(true);
  });

  test('con la regla en 0 y sin huella, la sesión guardada se borra al abrir (como siempre)', async () => {
    await AsyncStorage.setItem('me', JSON.stringify({ ...me, remember_days: 0 }));
    mockSecure.token = 'tok';
    globalThis.fetch = jest.fn() as any;
    await useAuth.getState().init();
    expect(useAuth.getState().token).toBeNull();
    expect(mockSecure.token).toBeUndefined();
  });

  test('sin conexión no se puede activar la huella: el servidor tiene que marcar la sesión', async () => {
    useAuth.setState({ token: 'tok' });
    globalThis.fetch = jest.fn().mockRejectedValue(new TypeError('Network request failed')) as any;
    expect(await useAuth.getState().setBiometric(true)).toBe(false);
    expect(useAuth.getState().biometric).toBe(false);
  });
});
