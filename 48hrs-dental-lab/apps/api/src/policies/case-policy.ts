/**
 * Row-level access. Mirrors the shared canViewCase(): staff with cases.view_all
 * see everything, technicians their assigned cases, clinic users their clinic's.
 * Anything outside the scope is reported as 404 so its existence is not revealed.
 */
import { PERMISSIONS, hasPermission } from '@48hrs/shared/permissions';
import { canViewCase } from '@48hrs/shared/workflow';
import type { Prisma } from '../generated/prisma/client.ts';
import { forbidden, notFound } from '../lib/errors.ts';
import { actorOf, type AuthContext } from '../types/auth.ts';

const NOTHING = { id: { in: [] as string[] } };

export function caseScope(auth: AuthContext): Prisma.DentalCaseWhereInput {
  if (!hasPermission(auth.permissions, PERMISSIONS.CASES_VIEW)) return NOTHING;
  if (hasPermission(auth.permissions, PERMISSIONS.CASES_VIEW_ALL)) return {};
  if (auth.user.clinicId) return { clinicId: auth.user.clinicId };
  if (auth.user.technicianId) return { technicianId: auth.user.technicianId };
  return NOTHING;
}

/** Clinic a non-staff user is restricted to (null = no restriction, "" = nothing visible). */
export function clinicScope(auth: AuthContext): string | null {
  if (hasPermission(auth.permissions, PERMISSIONS.CASES_VIEW_ALL)) return null;
  return auth.user.clinicId ?? '';
}

export function assertCanViewCase(auth: AuthContext, c: { technicianId: string | null; clinicId: string }) {
  if (!canViewCase(actorOf(auth), c)) throw notFound();
}

export function can(auth: AuthContext, permission: string | string[], mode: 'all' | 'any' = 'all') {
  return hasPermission(auth.permissions, permission, mode);
}

export function authorizeOrThrow(auth: AuthContext, permission: string | string[], mode: 'all' | 'any' = 'all') {
  if (!can(auth, permission, mode)) throw forbidden();
}
