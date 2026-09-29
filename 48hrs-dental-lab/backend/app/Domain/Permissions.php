<?php

namespace App\Domain;

/**
 * Permission catalogue and default role matrix (packages/shared/src/permissions.ts).
 * The keys are the contract with the web app; the matrix is editable at runtime
 * (roles / role_permissions tables) — these are only the defaults seeded once.
 */
final class Permissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const CASES_VIEW = 'cases.view';

    public const CASES_VIEW_ALL = 'cases.view_all';

    public const CASES_CREATE = 'cases.create';

    public const CASES_SUBMIT = 'cases.submit';

    public const CASES_EDIT = 'cases.edit';

    public const CASES_DELETE = 'cases.delete';

    public const CASES_ACCEPT = 'cases.accept';

    public const CASES_ASSIGN = 'cases.assign';

    public const CASES_UPDATE_STATUS = 'cases.update_status';

    public const CASES_CANCEL = 'cases.cancel';

    public const CASES_CONFIRM_RECEIPT = 'cases.confirm_receipt';

    public const FILES_VIEW = 'files.view';

    public const FILES_UPLOAD = 'files.upload';

    public const FILES_DELETE = 'files.delete';

    public const PATIENTS_VIEW = 'patients.view';

    public const PATIENTS_CREATE = 'patients.create';

    public const PATIENTS_EDIT = 'patients.edit';

    public const PATIENTS_DELETE = 'patients.delete';

    public const DOCTORS_VIEW = 'doctors.view';

    public const DOCTORS_CREATE = 'doctors.create';

    public const DOCTORS_EDIT = 'doctors.edit';

    public const DOCTORS_DELETE = 'doctors.delete';

    public const CLINICS_VIEW = 'clinics.view';

    public const CLINICS_CREATE = 'clinics.create';

    public const CLINICS_EDIT = 'clinics.edit';

    public const CLINICS_DELETE = 'clinics.delete';

    public const TECHNICIANS_VIEW = 'technicians.view';

    public const TECHNICIANS_CREATE = 'technicians.create';

    public const TECHNICIANS_EDIT = 'technicians.edit';

    public const TECHNICIANS_DELETE = 'technicians.delete';

    public const PRODUCTION_VIEW = 'production.view';

    public const QC_VIEW = 'qc.view';

    public const QC_PERFORM = 'qc.perform';

    public const DELIVERY_VIEW = 'delivery.view';

    public const DELIVERY_MANAGE = 'delivery.manage';

    public const INVOICES_VIEW = 'invoices.view';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_RECORD = 'payments.record';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_FINANCIAL = 'reports.financial';

    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const SERVICES_MANAGE = 'services.manage';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const AUDIT_VIEW = 'audit.view';

    /** @return list<array{key: string, label: string, group: string}> */
    public static function catalogue(): array
    {
        return [
            ['key' => self::DASHBOARD_VIEW, 'label' => 'View dashboard', 'group' => 'General'],
            ['key' => self::CASES_VIEW, 'label' => 'View cases', 'group' => 'Cases'],
            ['key' => self::CASES_VIEW_ALL, 'label' => 'View every case (not only own)', 'group' => 'Cases'],
            ['key' => self::CASES_CREATE, 'label' => 'Register case at intake', 'group' => 'Cases'],
            ['key' => self::CASES_SUBMIT, 'label' => 'Submit case (client portal)', 'group' => 'Cases'],
            ['key' => self::CASES_EDIT, 'label' => 'Edit case details', 'group' => 'Cases'],
            ['key' => self::CASES_DELETE, 'label' => 'Delete case', 'group' => 'Cases'],
            ['key' => self::CASES_ACCEPT, 'label' => 'Accept / reject submissions', 'group' => 'Cases'],
            ['key' => self::CASES_ASSIGN, 'label' => 'Assign technician', 'group' => 'Cases'],
            ['key' => self::CASES_UPDATE_STATUS, 'label' => 'Update production stage', 'group' => 'Cases'],
            ['key' => self::CASES_CANCEL, 'label' => 'Cancel case', 'group' => 'Cases'],
            ['key' => self::CASES_CONFIRM_RECEIPT, 'label' => 'Confirm receipt', 'group' => 'Cases'],
            ['key' => self::FILES_VIEW, 'label' => 'View case files', 'group' => 'Case files'],
            ['key' => self::FILES_UPLOAD, 'label' => 'Upload case files', 'group' => 'Case files'],
            ['key' => self::FILES_DELETE, 'label' => 'Delete case files', 'group' => 'Case files'],
            ['key' => self::PATIENTS_VIEW, 'label' => 'View patients', 'group' => 'Directory'],
            ['key' => self::PATIENTS_CREATE, 'label' => 'Create patients', 'group' => 'Directory'],
            ['key' => self::PATIENTS_EDIT, 'label' => 'Edit patients', 'group' => 'Directory'],
            ['key' => self::PATIENTS_DELETE, 'label' => 'Delete patients', 'group' => 'Directory'],
            ['key' => self::DOCTORS_VIEW, 'label' => 'View doctors', 'group' => 'Directory'],
            ['key' => self::DOCTORS_CREATE, 'label' => 'Create doctors', 'group' => 'Directory'],
            ['key' => self::DOCTORS_EDIT, 'label' => 'Edit doctors', 'group' => 'Directory'],
            ['key' => self::DOCTORS_DELETE, 'label' => 'Delete doctors', 'group' => 'Directory'],
            ['key' => self::CLINICS_VIEW, 'label' => 'View clinics', 'group' => 'Directory'],
            ['key' => self::CLINICS_CREATE, 'label' => 'Create clinics', 'group' => 'Directory'],
            ['key' => self::CLINICS_EDIT, 'label' => 'Edit clinics', 'group' => 'Directory'],
            ['key' => self::CLINICS_DELETE, 'label' => 'Delete clinics', 'group' => 'Directory'],
            ['key' => self::TECHNICIANS_VIEW, 'label' => 'View technicians', 'group' => 'Directory'],
            ['key' => self::TECHNICIANS_CREATE, 'label' => 'Create technicians', 'group' => 'Directory'],
            ['key' => self::TECHNICIANS_EDIT, 'label' => 'Edit technicians', 'group' => 'Directory'],
            ['key' => self::TECHNICIANS_DELETE, 'label' => 'Delete technicians', 'group' => 'Directory'],
            ['key' => self::PRODUCTION_VIEW, 'label' => 'View production board', 'group' => 'Lab floor'],
            ['key' => self::QC_VIEW, 'label' => 'View quality control', 'group' => 'Lab floor'],
            ['key' => self::QC_PERFORM, 'label' => 'Perform quality control', 'group' => 'Lab floor'],
            ['key' => self::DELIVERY_VIEW, 'label' => 'View deliveries', 'group' => 'Lab floor'],
            ['key' => self::DELIVERY_MANAGE, 'label' => 'Dispatch & deliver', 'group' => 'Lab floor'],
            ['key' => self::INVOICES_VIEW, 'label' => 'View invoices', 'group' => 'Finance'],
            ['key' => self::PAYMENTS_VIEW, 'label' => 'View payments', 'group' => 'Finance'],
            ['key' => self::PAYMENTS_RECORD, 'label' => 'Record payments', 'group' => 'Finance'],
            ['key' => self::REPORTS_VIEW, 'label' => 'View reports', 'group' => 'Reports'],
            ['key' => self::REPORTS_FINANCIAL, 'label' => 'View revenue & income', 'group' => 'Reports'],
            ['key' => self::USERS_VIEW, 'label' => 'View users', 'group' => 'Administration'],
            ['key' => self::USERS_MANAGE, 'label' => 'Manage users', 'group' => 'Administration'],
            ['key' => self::ROLES_MANAGE, 'label' => 'Manage roles & permissions', 'group' => 'Administration'],
            ['key' => self::SERVICES_MANAGE, 'label' => 'Manage services & prices', 'group' => 'Administration'],
            ['key' => self::SETTINGS_VIEW, 'label' => 'View settings', 'group' => 'Administration'],
            ['key' => self::SETTINGS_MANAGE, 'label' => 'Change settings', 'group' => 'Administration'],
            ['key' => self::AUDIT_VIEW, 'label' => 'View activity log', 'group' => 'Administration'],
        ];
    }

    /** @return list<string> */
    public static function allKeys(): array
    {
        return array_column(self::catalogue(), 'key');
    }

    /** Keys in catalogue order (the order the web app lists them in), unknown keys dropped. */
    public static function ordered(iterable $keys): array
    {
        $set = array_flip(is_array($keys) ? $keys : iterator_to_array($keys, false));

        return array_values(array_filter(self::allKeys(), fn ($k) => isset($set[$k])));
    }

    public const ROLE_LABELS = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'lab_manager' => 'Lab Manager',
        'reception' => 'Reception',
        'technician' => 'Technician',
        'qc' => 'Quality Control',
        'delivery' => 'Delivery',
        'client' => 'Client',
    ];

    public const ROLE_ORDER = ['super_admin', 'admin', 'lab_manager', 'reception', 'technician', 'qc', 'delivery', 'client'];

    /** Default role → permission matrix. */
    public static function defaultRoles(): array
    {
        $all = self::allKeys();
        $directoryRead = [self::PATIENTS_VIEW, self::DOCTORS_VIEW, self::CLINICS_VIEW, self::TECHNICIANS_VIEW];

        return [
            ['key' => 'super_admin', 'name' => 'Super Admin', 'description' => 'Full access to every module and setting.', 'locked' => true, 'permissions' => $all],
            ['key' => 'admin', 'name' => 'Admin', 'description' => 'Runs the lab: cases, directory, finance and reports.', 'locked' => false,
                'permissions' => array_values(array_filter($all, fn ($k) => ! in_array($k, [self::ROLES_MANAGE, self::CASES_SUBMIT, self::CASES_CONFIRM_RECEIPT], true)))],
            ['key' => 'lab_manager', 'name' => 'Lab Manager', 'description' => 'Assignment, production oversight and quality control.', 'locked' => false, 'permissions' => [
                self::DASHBOARD_VIEW, self::CASES_VIEW, self::CASES_VIEW_ALL, self::CASES_EDIT, self::CASES_ASSIGN, self::CASES_UPDATE_STATUS,
                self::FILES_VIEW, self::FILES_UPLOAD, ...$directoryRead, self::TECHNICIANS_CREATE, self::TECHNICIANS_EDIT,
                self::PRODUCTION_VIEW, self::QC_VIEW, self::QC_PERFORM, self::DELIVERY_VIEW, self::REPORTS_VIEW,
            ]],
            ['key' => 'reception', 'name' => 'Reception', 'description' => 'Intake, patients, doctors & clinics, payments and hand-over.', 'locked' => false, 'permissions' => [
                self::DASHBOARD_VIEW, self::CASES_VIEW, self::CASES_VIEW_ALL, self::CASES_CREATE, self::CASES_EDIT, self::CASES_ACCEPT,
                self::FILES_VIEW, self::FILES_UPLOAD, self::PATIENTS_VIEW, self::PATIENTS_CREATE, self::PATIENTS_EDIT,
                self::DOCTORS_VIEW, self::DOCTORS_CREATE, self::DOCTORS_EDIT, self::CLINICS_VIEW, self::CLINICS_CREATE, self::CLINICS_EDIT,
                self::TECHNICIANS_VIEW, self::DELIVERY_VIEW, self::DELIVERY_MANAGE, self::INVOICES_VIEW, self::PAYMENTS_VIEW, self::PAYMENTS_RECORD,
            ]],
            ['key' => 'technician', 'name' => 'Technician', 'description' => 'Works only on cases assigned to them.', 'locked' => false, 'permissions' => [
                self::DASHBOARD_VIEW, self::CASES_VIEW, self::CASES_UPDATE_STATUS, self::FILES_VIEW, self::FILES_UPLOAD, self::PRODUCTION_VIEW,
            ]],
            ['key' => 'qc', 'name' => 'Quality Control', 'description' => 'Inspects finished work, passes or returns it for rework.', 'locked' => false, 'permissions' => [
                self::DASHBOARD_VIEW, self::CASES_VIEW, self::CASES_VIEW_ALL, self::FILES_VIEW, self::FILES_UPLOAD, self::QC_VIEW, self::QC_PERFORM, self::TECHNICIANS_VIEW,
            ]],
            ['key' => 'delivery', 'name' => 'Delivery', 'description' => 'Dispatches finished cases and records hand-over.', 'locked' => false, 'permissions' => [
                self::DASHBOARD_VIEW, self::CASES_VIEW, self::CASES_VIEW_ALL, self::DELIVERY_VIEW, self::DELIVERY_MANAGE, self::CLINICS_VIEW,
            ]],
            ['key' => 'client', 'name' => 'Client', 'description' => 'Clinic portal: submits cases and follows its own cases only.', 'locked' => false, 'permissions' => [
                self::DASHBOARD_VIEW, self::CASES_VIEW, self::CASES_SUBMIT, self::CASES_CONFIRM_RECEIPT, self::FILES_VIEW, self::FILES_UPLOAD, self::INVOICES_VIEW,
            ]],
        ];
    }

    /** @param  list<string>  $granted  @param  string|list<string>  $required */
    public static function has(array $granted, string|array $required, string $mode = 'all'): bool
    {
        $list = (array) $required;
        $in = fn (string $k) => in_array($k, $granted, true);

        return $mode === 'all' ? array_reduce($list, fn ($ok, $k) => $ok && $in($k), true) : array_reduce($list, fn ($ok, $k) => $ok || $in($k), false);
    }
}
