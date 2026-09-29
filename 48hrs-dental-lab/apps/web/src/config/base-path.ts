/**
 * The URL path the web app is served from: "/" (production: the root of
 * https://lab.sooryoscan.com) or, for a deployment under a URL sub-path, e.g.
 * "/lab/". This is a URL, never a folder name on the server's disk. Set at build
 * time with VITE_BASE_PATH; Vite rewrites asset URLs and the router uses it as its basename.
 */
export function resolveBasePath(raw: string | undefined): string {
  const value = (raw ?? '').trim();
  if (!value || value === '/') return '/';
  if (!/^\/[A-Za-z0-9._~\-/]*$/.test(value) || value.includes('//')) {
    throw new Error(`VITE_BASE_PATH "${raw}" must be an absolute URL path such as / or /lab/.`);
  }
  return `${value.replace(/\/+$/, '')}/`;
}

/** React Router basename: the base path without its trailing slash ("" at the root). */
export function routerBasename(base: string): string {
  return base === '/' ? '' : base.replace(/\/$/, '');
}
