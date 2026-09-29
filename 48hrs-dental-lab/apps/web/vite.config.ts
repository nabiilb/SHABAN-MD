/// <reference types="vitest/config" />
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';
import { resolveApiBase } from './src/config/api-base';
import { resolveBasePath } from './src/config/base-path';

export default defineConfig(({ mode }) => {
  // /api is proxied to the Laravel API (php artisan serve) in dev and preview, so
  // the SPA and the API share one origin and the session cookie stays first-party.
  const fileEnv = loadEnv(mode, process.cwd(), '');
  const apiTarget = fileEnv.API_PROXY_TARGET || 'http://127.0.0.1:8000';
  // Fail the dev server / build on a VITE_API_URL that does not end in /api, or a bad base path.
  const apiBase = resolveApiBase(process.env.VITE_API_URL ?? fileEnv.VITE_API_URL);
  const base = resolveBasePath(process.env.VITE_BASE_PATH ?? fileEnv.VITE_BASE_PATH);
  // Under a URL sub-path (e.g. /lab/api) the proxy strips the prefix: Laravel serves /api.
  const apiPath = apiBase.startsWith('/') ? apiBase : '/api';
  const proxy = { [apiPath]: { target: apiTarget, changeOrigin: false, rewrite: (p: string) => p.replace(apiPath, '/api') } };

  return {
    base,
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: { port: 5173, proxy },
    preview: { port: 4173, proxy },
    build: {
      chunkSizeWarningLimit: 900,
      rollupOptions: {
        output: {
          manualChunks: {
            react: ['react', 'react-dom', 'react-router-dom'],
            data: ['@tanstack/react-query', '@tanstack/react-table', 'zod', 'react-hook-form'],
            charts: ['recharts'],
          },
        },
      },
    },
    test: {
      globals: true,
      environment: 'jsdom',
      setupFiles: ['../../packages/shared/test/fixed-clock.ts', './src/test/setup.ts'],
      include: ['src/**/*.test.{ts,tsx}'],
      css: false,
      env: { VITE_USE_MOCKS: 'true', VITE_MOCK_LATENCY_MS: '0' },
    },
  };
});
