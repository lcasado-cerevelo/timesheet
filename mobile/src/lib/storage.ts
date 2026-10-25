import AsyncStorage from '@react-native-async-storage/async-storage';
import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

export const kv = {
  async get<T>(key: string, fallback: T): Promise<T> {
    try {
      const raw = await AsyncStorage.getItem(key);
      return raw ? (JSON.parse(raw) as T) : fallback;
    } catch {
      return fallback;
    }
  },
  async set(key: string, value: unknown): Promise<void> {
    try {
      await AsyncStorage.setItem(key, JSON.stringify(value));
    } catch {
      /* sin espacio o sin permiso: la app sigue funcionando en memoria */
    }
  },
  async del(key: string): Promise<void> {
    try {
      await AsyncStorage.removeItem(key);
    } catch {
      /* nada */
    }
  },
};

/** El token va en el almacén seguro del teléfono; en la web no existe y se usa el almacenamiento normal. */
export const secure = {
  async get(key: string): Promise<string | null> {
    if (Platform.OS === 'web') return AsyncStorage.getItem(key);
    try {
      return await SecureStore.getItemAsync(key);
    } catch {
      return null;
    }
  },
  async set(key: string, value: string): Promise<void> {
    if (Platform.OS === 'web') return AsyncStorage.setItem(key, value);
    await SecureStore.setItemAsync(key, value);
  },
  async del(key: string): Promise<void> {
    if (Platform.OS === 'web') return AsyncStorage.removeItem(key);
    try {
      await SecureStore.deleteItemAsync(key);
    } catch {
      /* nada */
    }
  },
};
