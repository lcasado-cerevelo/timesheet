import { create } from 'zustand';
import { kv } from '../lib/storage';

interface SettingsState {
  loaded: boolean;
  decimal: boolean;      // horas como 8.50 en vez de 08:30
  arriveAlerts: boolean; // avisar al llegar a un lugar de trabajo
  weeklyReminder: boolean;
  load: () => Promise<void>;
  set: (patch: Partial<Omit<SettingsState, 'loaded' | 'load' | 'set'>>) => void;
}

export const useSettings = create<SettingsState>((set, get) => ({
  loaded: false,
  decimal: true,
  arriveAlerts: false,
  weeklyReminder: false,
  async load() {
    const s = await kv.get('settings.v1', { decimal: true, arriveAlerts: false, weeklyReminder: false });
    set({ ...s, loaded: true });
  },
  set(patch) {
    set(patch);
    const { decimal, arriveAlerts, weeklyReminder } = get();
    void kv.set('settings.v1', { decimal, arriveAlerts, weeklyReminder });
  },
}));
