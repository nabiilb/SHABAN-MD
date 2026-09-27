import { useCallback } from 'react';
import { hasPermission } from '@/lib/permissions';
import type { Actor } from '@/lib/workflow';
import { useAuthStore } from '@/stores/auth-store';

export function useAuth() {
  const user = useAuthStore((s) => s.user);
  const permissions = useAuthStore((s) => s.permissions);
  const status = useAuthStore((s) => s.status);
  const can = useCallback((p: string | string[], mode: 'all' | 'any' = 'all') => hasPermission(permissions, p, mode), [permissions]);
  return { user, permissions, status, can };
}

/** The signed-in user as a workflow actor (for canPerformAction). */
export function useActor(): Actor | null {
  const user = useAuthStore((s) => s.user);
  const permissions = useAuthStore((s) => s.permissions);
  return user ? { user, permissions } : null;
}
