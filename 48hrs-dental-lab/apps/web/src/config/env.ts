/** Typed access to Vite environment variables. Nothing secret belongs here — it ships to the browser. */
function num(v: string | undefined, fallback: number) {
  const n = Number(v);
  return v !== undefined && v !== '' && Number.isFinite(n) ? n : fallback;
}

export const env = {
  /** Base URL of the Node API including its /api prefix, e.g. https://api.lab.example/api. */
  apiUrl: (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? '',
  /** The in-browser mock backend is opt-in; by default every request goes to the Node API. */
  useMocks: import.meta.env.VITE_USE_MOCKS === 'true',
  mockLatencyMs: num(import.meta.env.VITE_MOCK_LATENCY_MS, 250),
  /** Mock backend only: session lifetime. The real API sets its own (SESSION_TTL_MINUTES). */
  sessionTimeoutMinutes: num(import.meta.env.VITE_SESSION_TIMEOUT_MINUTES, 480),
} as const;
