import { t } from '../i18n';

export interface MinimumDialog {
  title: string;
  message: string;
  register: string;
  keep: string;
  discard: string;
}

/** El permiso que se pide en Check out now cuando pasó menos del mínimo. Botones: Registrar / Seguir con el reloj / Descartar. */
export function minimumDialog(elapsed: number, minimum: number): MinimumDialog {
  return {
    title: t('clock.below_min_title'),
    message: t('clock.below_min_ask', { n: elapsed, m: minimum }),
    register: t('clock.register_min', { m: minimum }),
    keep: t('clock.keep_going'),
    discard: t('clock.discard'),
  };
}
