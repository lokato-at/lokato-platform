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

  // Stream-Pfade liegen unter /api/, nicht unter /api/v1/. Path-Boundary
  // regex matcht /stream, /stream/, /stream?…, aber NICHT /stream-foo o.ae.
  if (/^\/stream(?:\/|$|\?)/.test(normalizedPath)) {
    return rawApiBaseUrl.replace(/\/v1$/, '') + normalizedPath;
  }

  return rawApiBaseUrl + normalizedPath;
}
