/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL?: string;
  readonly VITE_USE_MOCKS?: string;
  readonly VITE_API_SNAKE_CASE?: string;
  readonly VITE_API_WITH_CREDENTIALS?: string;
  readonly VITE_MOCK_LATENCY_MS?: string;
  readonly VITE_SESSION_TIMEOUT_MINUTES?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
