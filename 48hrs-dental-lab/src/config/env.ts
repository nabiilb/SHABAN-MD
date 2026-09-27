/** Typed access to Vite environment variables. Nothing secret belongs here — it ships to the browser. */
function bool(v: string | undefined, fallback: boolean) {
  if (v === undefined || v === '') return fallback;
  return v === 'true' || v === '1';
}

function num(v: string | undefined, fallback: number) {
  const n = Number(v);
  return v !== undefined && v !== '' && Number.isFinite(n) ? n : fallback;
}

export const env = {
  apiUrl: (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? '',
  useMocks: import.meta.env.VITE_USE_MOCKS !== 'false',
  snakeCase: bool(import.meta.env.VITE_API_SNAKE_CASE, false),
  withCredentials: bool(import.meta.env.VITE_API_WITH_CREDENTIALS, false),
  mockLatencyMs: num(import.meta.env.VITE_MOCK_LATENCY_MS, 250),
  sessionTimeoutMinutes: num(import.meta.env.VITE_SESSION_TIMEOUT_MINUTES, 480),
} as const;
