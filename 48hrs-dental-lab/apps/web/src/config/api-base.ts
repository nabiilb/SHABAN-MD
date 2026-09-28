/**
 * The API is mounted at /api (apps/api). VITE_API_URL may point at another
 * origin, but its path must still end in /api — otherwise every call would go
 * to e.g. https://api.example.com/cases and fail. Checked when Vite starts or
 * builds (vite.config.ts) and again at runtime.
 */
export const DEFAULT_API_BASE = '/api';

export function resolveApiBase(raw: string | undefined): string {
  const value = (raw ?? '').trim();
  if (!value) return DEFAULT_API_BASE;
  const base = value.replace(/\/+$/, '');
  let path: string;
  try {
    path = new URL(base, 'http://relative.invalid').pathname;
  } catch {
    throw new Error(`VITE_API_URL "${raw}" is not a valid URL.`);
  }
  if (!/\/api$/.test(path)) throw new Error(`VITE_API_URL "${raw}" must end with /api (the API's mount point), e.g. /api or https://api.example.com/api.`);
  if (/^[a-z]+:\/\//i.test(base) && !/^https?:\/\//i.test(base)) throw new Error(`VITE_API_URL "${raw}" must be http(s).`);
  return base;
}
