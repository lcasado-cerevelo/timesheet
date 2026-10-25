import type { Project } from './types';

export interface Region {
  identifier: string;
  latitude: number;
  longitude: number;
  radius: number;
  notifyOnEnter: boolean;
  notifyOnExit: boolean;
}

export function haversineM(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const R = 6371000;
  const rad = (d: number) => (d * Math.PI) / 180;
  const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(a));
}

/**
 * Regiones a vigilar. iPhone permite 20 a la vez: si hay más, se eligen las más cercanas a la posición actual
 * (y se vuelven a elegir cuando la persona se mueve o abre la app).
 */
export function pickRegions(projects: Project[], here: { latitude: number; longitude: number } | null, max = 20): Region[] {
  const withGeo = projects
    .filter((p) => p.active && p.geo_lat != null && p.geo_lng != null)
    .map((p) => ({ p, lat: Number(p.geo_lat), lng: Number(p.geo_lng) }))
    .filter((x) => Number.isFinite(x.lat) && Number.isFinite(x.lng));
  if (here) withGeo.sort((a, b) => haversineM(here.latitude, here.longitude, a.lat, a.lng) - haversineM(here.latitude, here.longitude, b.lat, b.lng));
  return withGeo.slice(0, max).map(({ p, lat, lng }) => ({
    identifier: String(p.id),
    latitude: lat,
    longitude: lng,
    radius: Math.min(5000, Math.max(50, p.geo_radius_m ?? 150)),
    notifyOnEnter: true,
    notifyOnExit: false,
  }));
}
