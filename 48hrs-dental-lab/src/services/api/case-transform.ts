/** camelCase <-> snake_case key conversion for Laravel-style APIs. */
const toSnake = (s: string) => s.replace(/[A-Z]/g, (c) => `_${c.toLowerCase()}`);
const toCamel = (s: string) => s.replace(/_([a-z0-9])/g, (_, c: string) => c.toUpperCase());

function transform(value: unknown, fn: (k: string) => string): unknown {
  if (Array.isArray(value)) return value.map((v) => transform(v, fn));
  if (value && typeof value === 'object' && !(value instanceof Blob) && !(value instanceof Date)) {
    return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([k, v]) => [fn(k), transform(v, fn)]));
  }
  return value;
}

export const snakeKeys = (v: unknown) => transform(v, toSnake);
export const camelKeys = (v: unknown) => transform(v, toCamel);
export const snakeKey = toSnake;
