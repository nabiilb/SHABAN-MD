/**
 * Where the web app is served from: "/" or a sub-folder such as "/48hrs_lab/"
 * (Hostinger: public_html/48hrs_lab). Set at build time with VITE_BASE_PATH;
 * Vite rewrites asset URLs and the router uses it as its basename.
 */
export function resolveBasePath(raw: string | undefined): string {
  const value = (raw ?? '').trim();
  if (!value || value === '/') return '/';
  if (!/^\/[A-Za-z0-9._~\-/]*$/.test(value) || value.includes('//')) {
    throw new Error(`VITE_BASE_PATH "${raw}" must be an absolute path such as /48hrs_lab/.`);
  }
  return `${value.replace(/\/+$/, '')}/`;
}

/** React Router basename: the base path without its trailing slash ("" at the root). */
export function routerBasename(base: string): string {
  return base === '/' ? '' : base.replace(/\/$/, '');
}
