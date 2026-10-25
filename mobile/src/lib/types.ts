export type CaptureMode = 'full_day' | 'from_to';
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
  weekly_hours: string | null;
  company: { id: number; name: string; timezone: string };
  brand: Brand;
  rules: Rules;
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
}

/** 'synced' = igual que el servidor; 'dirty' = por enviar; 'deleted' = borrada localmente, por enviar;
 *  'rejected' = el servidor la rechazó (hoja aprobada o regla incumplida). */
export type SyncState = 'synced' | 'dirty' | 'deleted' | 'rejected';

export interface Entry {
  uuid: string;
  id?: number;
  project_id: number;
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
