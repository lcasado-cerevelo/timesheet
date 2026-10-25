export type CaptureMode = 'full_day' | 'from_to';
/** Cómo permite el servicio entrar las horas: detalle (desde/hasta), total del día, o ambos (manda la preferencia de la persona). */
export type ServiceCapture = 'detail' | 'total' | 'both';
export type Locale = 'es' | 'en';
export type WorkerType = 'consultant' | 'employee';
export type SheetStatus = 'draft' | 'submitted' | 'approved' | 'returned';

export interface Brand {
  name: string;
  logo_url: string | null;
  logo_dark_url: string | null;
  color_primary: string;
  color_accent: string;
  email_footer: string | null;
}

export interface Rules {
  weeks_back: number;
  increment: number;
  max_day_hours: number;
  deadline_dow: number;
  deadline_time: string;
}

export interface Me {
  id: number;
  name: string;
  email: string;
  role: 'owner' | 'member';
  worker_type: WorkerType;
  capture_mode: CaptureMode;
  /** Idioma en uso (el de la persona o, si no tiene, el de la compañía). */
  locale?: Locale;
  company_locale?: Locale;
  weekly_hours: string | null;
  company: { id: number; name: string; timezone: string };
  brand: Brand;
  rules: Rules;
  /** Regla de la compañía: días que se recuerda la sesión desde el último uso. 0 = pedir contraseña cada vez que se abre la app. */
  remember_days?: number;
}

export interface Service {
  id: number;
  project_id: number;
  name: string;
  billable: boolean;
  capture_mode: ServiceCapture;
  active: boolean;
  sort: number;
  /** Cómo entra las horas ESTA persona en este servicio, calculado por el servidor. */
  mode?: CaptureMode;
}

export interface Project {
  id: number;
  code: string;
  name: string;
  billable: boolean;
  active: boolean;
  invoice_detail: 'hours_total' | 'by_day' | 'with_times';
  geo_lat: string | number | null;
  geo_lng: string | number | null;
  geo_radius_m: number | null;
  /** Servicios del proyecto (sin tarifas). Proyectos guardados antes de que existieran los servicios no lo traen. */
  services?: Service[];
  /** Cliente del proyecto, si el servidor lo envía. */
  customer?: { id?: number; name: string } | null;
}

/** 'synced' = igual que el servidor; 'dirty' = por enviar; 'deleted' = borrada localmente, por enviar;
 *  'rejected' = el servidor la rechazó (hoja aprobada o regla incumplida). */
export type SyncState = 'synced' | 'dirty' | 'deleted' | 'rejected';

export interface Entry {
  uuid: string;
  id?: number;
  project_id: number;
  /** Las entradas viejas no lo tienen. */
  service_id?: number | null;
  work_date: string;
  start_time: string | null;
  end_time: string | null;
  hours: number;
  note: string;
  billable: boolean;
  running: boolean;
  sync: SyncState;
  reject_reason?: string;
}

export interface ServerEntry {
  id: number;
  client_uuid: string | null;
  project_id: number;
  service_id?: number | null;
  service?: { id: number; name: string } | null;
  work_date: string;
  start_time: string | null;
  end_time: string | null;
  running: boolean;
  hours: number;
  note: string | null;
  billable: boolean;
}

export interface SyncResult {
  client_uuid: string;
  status: 'ok' | 'locked' | 'invalid';
  id?: number;
  message?: string;
  errors?: Record<string, string[]>;
}

export interface Sheet {
  id: number;
  week_starting: string;
  week_ending: string;
  status: SheetStatus;
  locked: boolean;
  version: number;
  total_hours: number;
  late: boolean;
  return_comment: string | null;
  warnings: { code: string; message: string }[];
}
