import { kv } from '../lib/storage';
import { cancelClockNotification } from '../lib/notify';
import { useClock } from './clock';
import { useEntries } from './entries';

/**
 * Los datos guardados en el teléfono pertenecen a una persona. Si entra otra (o la misma sesión venció y
 * entra alguien distinto), se borran los datos locales de la anterior para no mezclarlos.
 * Si es la misma persona (sesión vencida), se conservan: lo pendiente se envía al volver a entrar.
 */
export async function reconcileOwner(meId: number): Promise<boolean> {
  const prev = await kv.get<number | null>('owner.v1', null);
  if (prev !== null && prev !== meId) {
    await useEntries.getState().reset();
    useClock.setState({ clock: null });
    await kv.del('clock.v1');
    await kv.del('daynotes.v1');
    await kv.del('lastProject.v1');
    await cancelClockNotification();
    await kv.set('owner.v1', meId);
    return true;
  }
  if (prev === null) await kv.set('owner.v1', meId);
  return false;
}
