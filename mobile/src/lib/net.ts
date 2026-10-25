import NetInfo from '@react-native-community/netinfo';

/** ¿Hay señal ahora? Si no se puede saber, se asume que sí (así se pide la contraseña, lo más seguro). */
export async function isOnline(): Promise<boolean> {
  try {
    const st = await NetInfo.fetch();
    return !!st.isConnected && st.isInternetReachable !== false;
  } catch {
    return true;
  }
}
