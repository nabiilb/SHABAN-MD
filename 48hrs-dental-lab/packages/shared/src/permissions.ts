import type { Permission, Role, RoleKey } from './models';

/**
 * Permission catalogue. Keys are the contract with the backend: the API must
 * check the same keys (the Laravel permission middleware, gates and policies). The UI only uses them to
 * hide what the server refuses anyway.
 */
export const PERMISSIONS = {
  DASHBOARD_VIEW: 'dashboard.view',

  CASES_VIEW: 'cases.view',
  CASES_VIEW_ALL: 'cases.view_all',
  CASES_CREATE: 'cases.create',
  CASES_SUBMIT: 'cases.submit',
  CASES_EDIT: 'cases.edit',
  CASES_DELETE: 'cases.delete',
  CASES_ACCEPT: 'cases.accept',
  CASES_ASSIGN: 'cases.assign',
  CASES_UPDATE_STATUS: 'cases.update_status',
  CASES_CANCEL: 'cases.cancel',
  CASES_CONFIRM_RECEIPT: 'cases.confirm_receipt',

  FILES_VIEW: 'files.view',
  FILES_UPLOAD: 'files.upload',
  FILES_DELETE: 'files.delete',

  PATIENTS_VIEW: 'patients.view',
  PATIENTS_CREATE: 'patients.create',
  PATIENTS_EDIT: 'patients.edit',
  PATIENTS_DELETE: 'patients.delete',

  DOCTORS_VIEW: 'doctors.view',
  DOCTORS_CREATE: 'doctors.create',
  DOCTORS_EDIT: 'doctors.edit',
  DOCTORS_DELETE: 'doctors.delete',
  CLINICS_VIEW: 'clinics.view',
  CLINICS_CREATE: 'clinics.create',
  CLINICS_EDIT: 'clinics.edit',
  CLINICS_DELETE: 'clinics.delete',

  TECHNICIANS_VIEW: 'technicians.view',
  TECHNICIANS_CREATE: 'technicians.create',
  TECHNICIANS_EDIT: 'technicians.edit',
  TECHNICIANS_DELETE: 'technicians.delete',

  PRODUCTION_VIEW: 'production.view',
  QC_VIEW: 'qc.view',
  QC_PERFORM: 'qc.perform',
  DELIVERY_VIEW: 'delivery.view',
  DELIVERY_MANAGE: 'delivery.manage',

  INVOICES_VIEW: 'invoices.view',
  PAYMENTS_VIEW: 'payments.view',
  PAYMENTS_RECORD: 'payments.record',

  REPORTS_VIEW: 'reports.view',
  REPORTS_FINANCIAL: 'reports.financial',

  USERS_VIEW: 'users.view',
  USERS_MANAGE: 'users.manage',
  ROLES_MANAGE: 'roles.manage',
  SERVICES_MANAGE: 'services.manage',
  SETTINGS_VIEW: 'settings.view',
  SETTINGS_MANAGE: 'settings.manage',
  AUDIT_VIEW: 'audit.view',
} as const;

export type PermissionKey = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

const P = PERMISSIONS;

export const PERMISSION_CATALOGUE: Permission[] = [
  { key: P.DASHBOARD_VIEW, label: 'View dashboard', group: 'General' },

  { key: P.CASES_VIEW, label: 'View cases', group: 'Cases' },
  { key: P.CASES_VIEW_ALL, label: 'View every case (not only own)', group: 'Cases' },
  { key: P.CASES_CREATE, label: 'Register case at intake', group: 'Cases' },
  { key: P.CASES_SUBMIT, label: 'Submit case (client portal)', group: 'Cases' },
  { key: P.CASES_EDIT, label: 'Edit case details', group: 'Cases' },
  { key: P.CASES_DELETE, label: 'Delete case', group: 'Cases' },
  { key: P.CASES_ACCEPT, label: 'Accept / reject submissions', group: 'Cases' },
  { key: P.CASES_ASSIGN, label: 'Assign technician', group: 'Cases' },
  { key: P.CASES_UPDATE_STATUS, label: 'Update production stage', group: 'Cases' },
  { key: P.CASES_CANCEL, label: 'Cancel case', group: 'Cases' },
  { key: P.CASES_CONFIRM_RECEIPT, label: 'Confirm receipt', group: 'Cases' },

  { key: P.FILES_VIEW, label: 'View case files', group: 'Case files' },
  { key: P.FILES_UPLOAD, label: 'Upload case files', group: 'Case files' },
  { key: P.FILES_DELETE, label: 'Delete case files', group: 'Case files' },

  { key: P.PATIENTS_VIEW, label: 'View patients', group: 'Directory' },
  { key: P.PATIENTS_CREATE, label: 'Create patients', group: 'Directory' },
  { key: P.PATIENTS_EDIT, label: 'Edit patients', group: 'Directory' },
  { key: P.PATIENTS_DELETE, label: 'Delete patients', group: 'Directory' },
  { key: P.DOCTORS_VIEW, label: 'View doctors', group: 'Directory' },
  { key: P.DOCTORS_CREATE, label: 'Create doctors', group: 'Directory' },
  { key: P.DOCTORS_EDIT, label: 'Edit doctors', group: 'Directory' },
  { key: P.DOCTORS_DELETE, label: 'Delete doctors', group: 'Directory' },
  { key: P.CLINICS_VIEW, label: 'View clinics', group: 'Directory' },
  { key: P.CLINICS_CREATE, label: 'Create clinics', group: 'Directory' },
  { key: P.CLINICS_EDIT, label: 'Edit clinics', group: 'Directory' },
  { key: P.CLINICS_DELETE, label: 'Delete clinics', group: 'Directory' },
  { key: P.TECHNICIANS_VIEW, label: 'View technicians', group: 'Directory' },
  { key: P.TECHNICIANS_CREATE, label: 'Create technicians', group: 'Directory' },
  { key: P.TECHNICIANS_EDIT, label: 'Edit technicians', group: 'Directory' },
  { key: P.TECHNICIANS_DELETE, label: 'Delete technicians', group: 'Directory' },

  { key: P.PRODUCTION_VIEW, label: 'View production board', group: 'Lab floor' },
  { key: P.QC_VIEW, label: 'View quality control', group: 'Lab floor' },
  { key: P.QC_PERFORM, label: 'Perform quality control', group: 'Lab floor' },
  { key: P.DELIVERY_VIEW, label: 'View deliveries', group: 'Lab floor' },
  { key: P.DELIVERY_MANAGE, label: 'Dispatch & deliver', group: 'Lab floor' },

  { key: P.INVOICES_VIEW, label: 'View invoices', group: 'Finance' },
  { key: P.PAYMENTS_VIEW, label: 'View payments', group: 'Finance' },
  { key: P.PAYMENTS_RECORD, label: 'Record payments', group: 'Finance' },

  { key: P.REPORTS_VIEW, label: 'View reports', group: 'Reports' },
  { key: P.REPORTS_FINANCIAL, label: 'View revenue & income', group: 'Reports' },

  { key: P.USERS_VIEW, label: 'View users', group: 'Administration' },
  { key: P.USERS_MANAGE, label: 'Manage users', group: 'Administration' },
  { key: P.ROLES_MANAGE, label: 'Manage roles & permissions', group: 'Administration' },
  { key: P.SERVICES_MANAGE, label: 'Manage services & prices', group: 'Administration' },
  { key: P.SETTINGS_VIEW, label: 'View settings', group: 'Administration' },
  { key: P.SETTINGS_MANAGE, label: 'Change settings', group: 'Administration' },
  { key: P.AUDIT_VIEW, label: 'View activity log', group: 'Administration' },
];

export const ALL_PERMISSION_KEYS = PERMISSION_CATALOGUE.map((p) => p.key);

const DIRECTORY_READ = [P.PATIENTS_VIEW, P.DOCTORS_VIEW, P.CLINICS_VIEW, P.TECHNICIANS_VIEW];

export const ROLE_LABELS: Record<RoleKey, string> = {
  super_admin: 'Super Admin',
  admin: 'Admin',
  lab_manager: 'Lab Manager',
  reception: 'Reception',
  technician: 'Technician',
  qc: 'Quality Control',
  delivery: 'Delivery',
  client: 'Client',
};

export const ROLE_ORDER: RoleKey[] = [
  'super_admin',
  'admin',
  'lab_manager',
  'reception',
  'technician',
  'qc',
  'delivery',
  'client',
];

/** Default role → permission matrix. Editable at runtime under Roles & Permissions. */
export const DEFAULT_ROLES: Role[] = [
  {
    key: 'super_admin',
    name: ROLE_LABELS.super_admin,
    description: 'Full access to every module and setting.',
    locked: true,
    permissions: ALL_PERMISSION_KEYS,
  },
  {
    key: 'admin',
    name: ROLE_LABELS.admin,
    description: 'Runs the lab: cases, directory, finance and reports.',
    locked: false,
    permissions: ALL_PERMISSION_KEYS.filter(
      (k) => k !== P.ROLES_MANAGE && k !== P.CASES_SUBMIT && k !== P.CASES_CONFIRM_RECEIPT,
    ),
  },
  {
    key: 'lab_manager',
    name: ROLE_LABELS.lab_manager,
    description: 'Assignment, production oversight and quality control.',
    locked: false,
    permissions: [
      P.DASHBOARD_VIEW,
      P.CASES_VIEW,
      P.CASES_VIEW_ALL,
      P.CASES_EDIT,
      P.CASES_ASSIGN,
      P.CASES_UPDATE_STATUS,
      P.FILES_VIEW,
      P.FILES_UPLOAD,
      ...DIRECTORY_READ,
      P.TECHNICIANS_CREATE,
      P.TECHNICIANS_EDIT,
      P.PRODUCTION_VIEW,
      P.QC_VIEW,
      P.QC_PERFORM,
      P.DELIVERY_VIEW,
      P.REPORTS_VIEW,
    ],
  },
  {
    key: 'reception',
    name: ROLE_LABELS.reception,
    description: 'Intake, patients, doctors & clinics, payments and hand-over.',
    locked: false,
    permissions: [
      P.DASHBOARD_VIEW,
      P.CASES_VIEW,
      P.CASES_VIEW_ALL,
      P.CASES_CREATE,
      P.CASES_EDIT,
      P.CASES_ACCEPT,
      P.FILES_VIEW,
      P.FILES_UPLOAD,
      P.PATIENTS_VIEW,
      P.PATIENTS_CREATE,
      P.PATIENTS_EDIT,
      P.DOCTORS_VIEW,
      P.DOCTORS_CREATE,
      P.DOCTORS_EDIT,
      P.CLINICS_VIEW,
      P.CLINICS_CREATE,
      P.CLINICS_EDIT,
      P.TECHNICIANS_VIEW,
      P.DELIVERY_VIEW,
      P.DELIVERY_MANAGE,
      P.INVOICES_VIEW,
      P.PAYMENTS_VIEW,
      P.PAYMENTS_RECORD,
    ],
  },
  {
    key: 'technician',
    name: ROLE_LABELS.technician,
    description: 'Works only on cases assigned to them.',
    locked: false,
    permissions: [
      P.DASHBOARD_VIEW,
      P.CASES_VIEW,
      P.CASES_UPDATE_STATUS,
      P.FILES_VIEW,
      P.FILES_UPLOAD,
      P.PRODUCTION_VIEW,
    ],
  },
  {
    key: 'qc',
    name: ROLE_LABELS.qc,
    description: 'Inspects finished work, passes or returns it for rework.',
    locked: false,
    permissions: [P.DASHBOARD_VIEW, P.CASES_VIEW, P.CASES_VIEW_ALL, P.FILES_VIEW, P.FILES_UPLOAD, P.QC_VIEW, P.QC_PERFORM, P.TECHNICIANS_VIEW],
  },
  {
    key: 'delivery',
    name: ROLE_LABELS.delivery,
    description: 'Dispatches finished cases and records hand-over.',
    locked: false,
    permissions: [P.DASHBOARD_VIEW, P.CASES_VIEW, P.CASES_VIEW_ALL, P.DELIVERY_VIEW, P.DELIVERY_MANAGE, P.CLINICS_VIEW],
  },
  {
    key: 'client',
    name: ROLE_LABELS.client,
    description: 'Clinic portal: submits cases and follows its own cases only.',
    locked: false,
    permissions: [P.DASHBOARD_VIEW, P.CASES_VIEW, P.CASES_SUBMIT, P.CASES_CONFIRM_RECEIPT, P.FILES_VIEW, P.FILES_UPLOAD, P.INVOICES_VIEW],
  },
];

export function hasPermission(granted: readonly string[] | undefined, required: string | string[], mode: 'all' | 'any' = 'all') {
  if (!granted) return false;
  const list = Array.isArray(required) ? required : [required];
  return mode === 'all' ? list.every((k) => granted.includes(k)) : list.some((k) => granted.includes(k));
}
