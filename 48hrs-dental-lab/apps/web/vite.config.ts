/// <reference types="vitest/config" />
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';
import { resolveApiBase } from './src/config/api-base';

export default defineConfig(({ mode }) => {
  // /api is proxied to the Node API in dev and preview, so the SPA and the API
  // share one origin and the session cookies stay first-party.
  const fileEnv = loadEnv(mode, process.cwd(), '');
  const apiTarget = fileEnv.API_PROXY_TARGET || 'http://localhost:4000';
  // Fail the dev server / build on a VITE_API_URL that does not end in /api.
  resolveApiBase(process.env.VITE_API_URL ?? fileEnv.VITE_API_URL);
  const proxy = { '/api': { target: apiTarget, changeOrigin: false } };

  return {
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
