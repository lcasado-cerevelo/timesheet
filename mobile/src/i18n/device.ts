import { NativeModules, Platform } from 'react-native';
import { Locale, normalizeLocale } from './index';

/** Idioma del dispositivo sin dependencias extra: Intl, o los módulos nativos. 'en' por omisión. */
export function deviceLocale(): Locale {
  const candidates: unknown[] = [];
  try {
    candidates.push(Intl.DateTimeFormat().resolvedOptions().locale);
  } catch {
    /* sin Intl */
  }
  try {
    if (Platform.OS === 'ios') {
      const s = NativeModules.SettingsManager?.settings;
      candidates.push(s?.AppleLocale, s?.AppleLanguages?.[0]);
    } else if (Platform.OS === 'android') {
      candidates.push(NativeModules.I18nManager?.localeIdentifier);
    }
  } catch {
    /* nada */
  }
  for (const c of candidates) {
    const l = normalizeLocale(c);
    if (l) return l;
  }
  return 'en';
}
