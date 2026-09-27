import { describe, expect, it } from 'vitest';
import { DEFAULT_ROLES, PERMISSIONS, hasPermission } from '@/lib/permissions';
import { availableActions, canPerformAction, canViewCase, type Actor } from '@/lib/workflow';
import type { RoleKey } from '@/types/models';
import { sha256 } from '@/mocks/sha256';

const perms = (r: RoleKey) => DEFAULT_ROLES.find((x) => x.key === r)!.permissions;
const actor = (role: RoleKey, extra: Partial<Actor['user']> = {}): Actor => ({ user: { id: 'u', role, clinicId: null, technicianId: null, ...extra }, permissions: perms(role) });

describe('permission checks', () => {
  it('hasPermission supports all / any', () => {
    expect(hasPermission(['a', 'b'], ['a', 'b'])).toBe(true);
    expect(hasPermission(['a'], ['a', 'b'])).toBe(false);
    expect(hasPermission(['a'], ['a', 'b'], 'any')).toBe(true);
  });

  it('gives Super Admin every permission and keeps reception out of admin areas', () => {
    expect(perms('super_admin')).toContain(PERMISSIONS.ROLES_MANAGE);
    expect(perms('reception')).not.toContain(PERMISSIONS.USERS_MANAGE);
    expect(perms('technician')).not.toContain(PERMISSIONS.CASES_CREATE);
  });

  it('only lets the assigned technician move production forward', () => {
    const c = { status: 'assigned' as const, technicianId: 'tec_1', clinicId: 'k1' };
    expect(canPerformAction('start_production', c, actor('technician', { technicianId: 'tec_1' }))).toBe(true);
    expect(canPerformAction('start_production', c, actor('technician', { technicianId: 'tec_2' }))).toBe(false);
    expect(canPerformAction('start_production', c, actor('lab_manager'))).toBe(true);
    expect(canPerformAction('start_production', c, actor('reception'))).toBe(false);
  });

  it('allows actions only from the right status', () => {
    const qc = { status: 'quality_control' as const, technicianId: 't', clinicId: 'k' };
    expect(availableActions(qc, actor('qc')).map((a) => a.key)).toEqual(['qc_pass', 'qc_fail']);
    expect(canPerformAction('deliver', qc, actor('delivery'))).toBe(false);
  });

  it('scopes clients to their own clinic', () => {
    const client = actor('client', { clinicId: 'k1' });
    expect(canViewCase(client, { clinicId: 'k1', technicianId: null })).toBe(true);
    expect(canViewCase(client, { clinicId: 'k2', technicianId: null })).toBe(false);
  });

  it('hashes passwords with SHA-256 (mock only)', () => {
    expect(sha256('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
  });
});
