import { Platform } from 'react-native';

/**
 * Huella o rostro del teléfono para desbloquear la sesión guardada. El módulo nativo se carga solo al usarlo
 * (en la web y en las pruebas no existe). Si el sensor falla, el sistema ofrece el PIN o patrón del teléfono.
 */
function native(): any | null {
  if (Platform.OS === 'web') return null;
  try {
    return require('expo-local-authentication');
  } catch {
    return null;
  }
}

/** El teléfono tiene sensor y la persona tiene una huella (o rostro) registrada. */
export async function biometricAvailable(): Promise<boolean> {
  const la = native();
  if (!la) return false;
  try {
    return (await la.hasHardwareAsync()) && (await la.isEnrolledAsync());
  } catch {
    return false;
  }
}

/** Pide la huella. Devuelve true solo si la persona se verificó. */
export async function biometricPrompt(message: string, cancel: string): Promise<boolean> {
  const la = native();
  if (!la) return false;
  try {
    const r = await la.authenticateAsync({ promptMessage: message, cancelLabel: cancel });
    return !!r.success;
  } catch {
    return false;
  }
}
