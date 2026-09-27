import type { LucideIcon } from 'lucide-react';
import {
  Activity,
  BarChart3,
  Bell,
  Building2,
  ClipboardCheck,
  FilePlus2,
  FileText,
  Factory,
  FolderKanban,
  LayoutDashboard,
  Settings,
  ShieldCheck,
  Stethoscope,
  Truck,
  UserRound,
  Users,
  Wallet,
  Wrench,
} from 'lucide-react';
import { PERMISSIONS as P } from '@/lib/permissions';
import type { NavCounts } from '@/types/api';

export interface NavItem {
  label: string;
  to: string;
  icon: LucideIcon;
  /** Any one of these grants access. */
  permissions: string[];
  count?: keyof NavCounts;
  countTone?: 'default' | 'danger';
  end?: boolean;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

export const NAV: NavGroup[] = [
  {
    label: 'Workspace',
    items: [
      { label: 'Dashboard', to: '/dashboard', icon: LayoutDashboard, permissions: [P.DASHBOARD_VIEW] },
      { label: 'Cases', to: '/cases', icon: FolderKanban, permissions: [P.CASES_VIEW], count: 'awaitingAcceptance', end: true },
      { label: 'New Case', to: '/cases/new', icon: FilePlus2, permissions: [P.CASES_CREATE, P.CASES_SUBMIT] },
    ],
  },
  {
    label: 'Lab floor',
    items: [
      { label: 'Production', to: '/production', icon: Factory, permissions: [P.PRODUCTION_VIEW], count: 'inProduction' },
      { label: 'Quality Control', to: '/quality-control', icon: ClipboardCheck, permissions: [P.QC_VIEW], count: 'pendingQc' },
      { label: 'Delivery', to: '/delivery', icon: Truck, permissions: [P.DELIVERY_VIEW], count: 'readyForDelivery' },
    ],
  },
  {
    label: 'Directory',
    items: [
      { label: 'Patients', to: '/patients', icon: UserRound, permissions: [P.PATIENTS_VIEW] },
      { label: 'Doctors', to: '/doctors', icon: Stethoscope, permissions: [P.DOCTORS_VIEW] },
      { label: 'Clinics', to: '/clinics', icon: Building2, permissions: [P.CLINICS_VIEW] },
      { label: 'Technicians', to: '/technicians', icon: Wrench, permissions: [P.TECHNICIANS_VIEW] },
    ],
  },
  {
    label: 'Finance',
    items: [
      { label: 'Invoices', to: '/invoices', icon: FileText, permissions: [P.INVOICES_VIEW] },
      { label: 'Payments', to: '/payments', icon: Wallet, permissions: [P.PAYMENTS_VIEW] },
    ],
  },
  {
    label: 'Insights',
    items: [
      { label: 'Reports', to: '/reports', icon: BarChart3, permissions: [P.REPORTS_VIEW] },
      { label: 'Notifications', to: '/notifications', icon: Bell, permissions: [], count: 'unreadNotifications' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Users', to: '/users', icon: Users, permissions: [P.USERS_VIEW] },
      { label: 'Roles & Permissions', to: '/roles', icon: ShieldCheck, permissions: [P.ROLES_MANAGE] },
      { label: 'Activity Log', to: '/activity', icon: Activity, permissions: [P.AUDIT_VIEW] },
      { label: 'Settings', to: '/settings', icon: Settings, permissions: [P.SETTINGS_VIEW] },
    ],
  },
];

export function visibleNav(can: (p: string[], mode: 'any') => boolean): NavGroup[] {
  return NAV.map((g) => ({ ...g, items: g.items.filter((i) => i.permissions.length === 0 || can(i.permissions, 'any')) })).filter((g) => g.items.length > 0);
}
