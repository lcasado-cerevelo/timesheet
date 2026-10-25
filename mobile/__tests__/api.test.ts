import { setLocaleValue } from '../src/i18n';

beforeAll(() => setLocaleValue('es'));

describe('respuestas del servidor', () => {
  const { api, ApiError } = require('../src/lib/api');
  const realFetch = globalThis.fetch;
  afterEach(() => { globalThis.fetch = realFetch; });

  test('una página HTML con código 200 no se toma como respuesta válida', async () => {
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: true, status: 200, text: async () => '<!doctype html><html></html>' }) as any;
    await expect(api('/export-templates')).rejects.toBeInstanceOf(ApiError);
    await expect(api('/export-templates')).rejects.toThrow(/inesperado/);
  });

  test('un JSON normal pasa y un error de validación trae su primer mensaje', async () => {
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: true, status: 200, text: async () => '[{"id":1}]' }) as any;
    await expect(api('/x')).resolves.toEqual([{ id: 1 }]);
    globalThis.fetch = jest.fn().mockResolvedValue({ ok: false, status: 422, text: async () => '{"message":"m","errors":{"hours":["Pasa del máximo"]}}' }) as any;
    await expect(api('/x')).rejects.toThrow('Pasa del máximo');
  });
});
