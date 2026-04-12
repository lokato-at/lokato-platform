const rawApiBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/$/, '');

export const apiBaseUrl = rawApiBaseUrl;

export function buildApiUrl(path: string): string {
  if (/^https?:\/\//.test(path)) {
    return path;
  }

  const normalizedPath = path.startsWith('/') ? path : `/${path}`;

  if (!rawApiBaseUrl) {
    return normalizedPath;
  }


  return rawApiBaseUrl + normalizedPath;
}

const rawRealtimeBaseUrl = (import.meta.env.VITE_REALTIME_BASE_URL ?? '').replace(/\/$/, '');

export function buildRealtimeUrl(path = '/ws'): string {
  if (/^wss?:\/\//.test(path)) {
    return path;
  }

  const normalizedPath = path.startsWith('/') ? path : `/${path}`;

  const realtimeBase = rawRealtimeBaseUrl
    ? rawRealtimeBaseUrl.replace(/^http:\/\//, 'ws://').replace(/^https:\/\//, 'wss://')
    : '';

  if (!realtimeBase) {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${protocol}//${window.location.host}${normalizedPath}`;
  }

  return realtimeBase + normalizedPath;
}
