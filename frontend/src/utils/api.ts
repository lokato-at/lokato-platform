const rawApiBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? "").replace(/\/$/, "");
const rawSseBaseUrl = (import.meta.env.VITE_SSE_BASE_URL ?? "").replace(/\/$/, "");

export const apiBaseUrl = rawApiBaseUrl;

export function buildApiUrl(path: string): string {
  if (/^https?:\/\//.test(path)) {
    return path;
  }

  const normalizedPath = path.startsWith("/") ? path : `/${path}`;

  if (!rawApiBaseUrl) {
    return normalizedPath;
  }

  if (normalizedPath.startsWith("/stream/")) {
    if (rawSseBaseUrl) {
      return rawSseBaseUrl + normalizedPath;
    }

    return rawApiBaseUrl.replace(/\/v1$/, "") + normalizedPath;
  }

  return rawApiBaseUrl + normalizedPath;
}
