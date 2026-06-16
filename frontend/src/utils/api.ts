// Same-Origin-Default: funktioniert mit Compose/nginx und auch im Vite-Dev-Proxy.
// Falls VITE_API_BASE_URL nicht gesetzt ist, fallen wir auf /api/v1 zurück,
// damit REST-Calls und SSE nicht auf /movement-log bzw. /stream am Frontend landen.
const rawApiBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? '/api/v1').replace(/\/$/, '');

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
