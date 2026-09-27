import type { ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { createMemoryRouter, RouterProvider, type RouteObject } from 'react-router-dom';
import { render } from '@testing-library/react';
import { createQueryClient } from '@/lib/query-client';
import { authService } from '@/services/authService';
import { tokenStore } from '@/services/api/token-store';
import { useAuthStore } from '@/stores/auth-store';

export const DEMO_PASSWORD = '48hrs-demo';

/** Signs in through the real auth service (mock API) and stores the token. */
export async function loginAs(email: string) {
  const s = await authService.login({ email, password: DEMO_PASSWORD });
  tokenStore.set({ token: s.token, expiresAt: s.expiresAt });
  useAuthStore.setState({ status: 'authenticated', user: s.user, permissions: s.permissions, expiresAt: s.expiresAt, endedReason: null });
  return s;
}

export function renderRoutes(routes: RouteObject[], initialPath: string) {
  const router = createMemoryRouter(routes, { initialEntries: [initialPath] });
  const client = createQueryClient();
  client.setDefaultOptions({ queries: { retry: false } });
  const utils = render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { ...utils, router };
}

export function withQuery(children: ReactNode) {
  const client = createQueryClient();
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
