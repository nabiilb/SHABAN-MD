import type { ReactNode } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { PageLoader } from '@/components/ui/feedback';
import { useAuth } from '@/hooks/use-auth';
import { ForbiddenPage } from '@/pages/errors/forbidden-page';

/** Only signed-in users pass; others go to /login and come back afterwards. */
export function RequireAuth() {
  const { status } = useAuth();
  const location = useLocation();
  if (status === 'booting') return <PageLoader label="Checking your session…" />;
  if (status !== 'authenticated') {
    const next = `${location.pathname}${location.search}`;
    return <Navigate to={`/login${next !== '/' ? `?next=${encodeURIComponent(next)}` : ''}`} replace />;
  }
  return <Outlet />;
}

/** Signed-in users skip the login screens. */
export function GuestOnly() {
  const { status } = useAuth();
  if (status === 'booting') return <PageLoader />;
  if (status === 'authenticated') return <Navigate to="/dashboard" replace />;
  return <Outlet />;
}

/**
 * Page-level permission gate. The API enforces the same rule; this only avoids
 * rendering a page whose every request would be refused.
 */
export function RequirePermission({ anyOf, children }: { anyOf: string[]; children: ReactNode }) {
  const { can } = useAuth();
  if (anyOf.length && !can(anyOf, 'any')) return <ForbiddenPage />;
  return <>{children}</>;
}

/** Renders children only when the user has the permission(s). */
export function Can({ permission, mode = 'all', children, fallback = null }: { permission: string | string[]; mode?: 'all' | 'any'; children: ReactNode; fallback?: ReactNode }) {
  const { can } = useAuth();
  return <>{can(permission, mode) ? children : fallback}</>;
}
