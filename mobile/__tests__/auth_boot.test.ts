jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
const mockSecure: Record<string, string> = {};
jest.mock('expo-secure-store', () => ({
  getItemAsync: async (k: string) => mockSecure[k] ?? null,
  setItemAsync: async (k: string, v: string) => { mockSecure[k] = v; },
  deleteItemAsync: async (k: string) => { delete mockSecure[k]; },
}));
jest.mock('expo-notifications', () => ({}));
const mockNet = jest.fn();
jest.mock('@react-native-community/netinfo', () => ({ __esModule: true, default: { fetch: (...a: any[]) => mockNet(...a), addEventListener: jest.fn() } }));

import AsyncStorage from '@react-native-async-storage/async-storage';
import { useAuth } from '../src/store/auth';

const meJson = (rd: number | undefined) =>
  JSON.stringify({ id: 1, name: 'A', email: 'a@x.com', remember_days: rd, brand: { name: 'X', color_primary: '#000', color_accent: '#000' } });

async function boot(rd: number | undefined, online: boolean, token: string | null, withMe = true) {
  await AsyncStorage.clear();
  if (withMe) await AsyncStorage.setItem('me', meJson(rd));
  for (const k of Object.keys(mockSecure)) delete mockSecure[k];
  if (token) mockSecure.token = token;
  mockNet.mockResolvedValue({ isConnected: online, isInternetReachable: online });
  useAuth.setState({ token: null, limited: false, reconnected: false, me: null, ready: false });
  globalThis.fetch = jest.fn().mockRejectedValue(new Error('offline')) as any;
  await useAuth.getState().init();
  return useAuth.getState();
}

describe('arranque en frío (init)', () => {
  test('remember_days 0 + señal: borra el token y exige login', async () => {
    const s = await boot(0, true, 'tok');
    expect(s.token).toBeNull();
    expect(s.limited).toBe(false);
    expect(mockSecure.token).toBeUndefined();
  });
  test('remember_days 0 + sin señal: modo limitado, sin token', async () => {
    const s = await boot(0, false, 'tok');
    expect(s.token).toBeNull();
    expect(s.limited).toBe(true);
    expect(s.me?.id).toBe(1);
  });
  test('remember_days 0 + sin señal + nunca hubo sesión: login', async () => {
    const s = await boot(0, false, null, false);
    expect(s.limited).toBe(false);
    expect(s.token).toBeNull();
  });
  test('remember_days 30: reutiliza el token, con o sin señal', async () => {
    for (const online of [true, false]) {
      const s = await boot(30, online, 'tok');
      expect(s.token).toBe('tok');
      expect(s.limited).toBe(false);
    }
  });
  test('al volver la señal sale del modo limitado hacia el login', async () => {
    await boot(0, false, 'tok');
    useAuth.getState().leaveLimited();
    expect(useAuth.getState().limited).toBe(false);
    expect(useAuth.getState().reconnected).toBe(true);
  });
});
