jest.mock('@react-native-async-storage/async-storage', () => require('@react-native-async-storage/async-storage/jest/async-storage-mock'));
const mockSecure: Record<string, string> = {};
jest.mock('expo-secure-store', () => ({
  getItemAsync: async (k: string) => mockSecure[k] ?? null,
  setItemAsync: async (k: string, v: string) => { mockSecure[k] = v; },
  deleteItemAsync: async (k: string) => { delete mockSecure[k]; },
}));
jest.mock('expo-notifications', () => ({}));
jest.mock('@react-native-community/netinfo', () => ({ __esModule: true, default: { fetch: jest.fn(), addEventListener: jest.fn() } }));

import { useAuth } from '../src/store/auth';

const user = { id: 1, name: 'Luis', email: 'luis@x.com', locale: 'es', brand: { name: 'X', color_primary: '#000', color_accent: '#000' } };
const reply = (status: number, body: unknown) => ({ ok: status < 400, status, headers: { get: () => 'application/json' }, json: async () => body, text: async () => JSON.stringify(body) });

describe('código por correo al entrar (dueño y oficina)', () => {
  beforeEach(() => {
    for (const k of Object.keys(mockSecure)) delete mockSecure[k];
    useAuth.setState({ token: null, me: null, baseUrl: 'https://t.example.com', serverFixed: true, ready: true });
  });

  test('el servidor pide el código: no hay sesión hasta escribirlo, y el equipo queda recordado', async () => {
    const f = jest.fn()
      .mockResolvedValueOnce(reply(202, { step: 'code', challenge: 'ch1', email_hint: 'l***@x.com', expires_in: 900, remember_days: 7 }))
      .mockResolvedValueOnce(reply(200, { token: 'tok', user, device_token: 'dev1' }));
    globalThis.fetch = f as any;
    const step = await useAuth.getState().login('luis@x.com', 'secret', undefined);
    expect(step?.challenge).toBe('ch1');
    expect(useAuth.getState().token).toBeNull();

    await useAuth.getState().loginCode('ch1', '123456');
    const body = JSON.parse(f.mock.calls[1][1].body);
    expect(body).toMatchObject({ challenge: 'ch1', code: '123456', remember: true });
    expect(useAuth.getState().token).toBe('tok');
    expect(mockSecure.device_token).toBe('dev1');
  });

  test('con el equipo recordado el siguiente login manda el token del equipo y entra directo', async () => {
    mockSecure.device_token = 'dev1';
    const f = jest.fn().mockResolvedValueOnce(reply(200, { token: 'tok2', user }));
    globalThis.fetch = f as any;
    const step = await useAuth.getState().login('luis@x.com', 'secret', undefined);
    expect(step).toBeNull();
    expect(JSON.parse(f.mock.calls[0][1].body).device_token).toBe('dev1');
    expect(useAuth.getState().token).toBe('tok2');
  });
});
