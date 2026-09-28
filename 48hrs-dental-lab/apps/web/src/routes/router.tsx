import { lazy, type ReactNode } from 'react';
import { createBrowserRouter, Navigate } from 'react-router-dom';
import { PERMISSIONS as P } from '@48hrs/shared/permissions';
import { AppLayout } from '@/layouts/app-layout';
import { AuthLayout } from '@/layouts/auth-layout';
import { GuestOnly, RequireAuth, RequirePermission } from './guards';

const LoginPage = lazy(() => import('@/pages/auth/login-page'));
const ForgotPasswordPage = lazy(() => import('@/pages/auth/forgot-password-page'));
const ResetPasswordPage = lazy(() => import('@/pages/auth/reset-password-page'));
const LogoutPage = lazy(() => import('@/pages/auth/logout-page'));
const DashboardPage = lazy(() => import('@/pages/dashboard/dashboard-page'));
const CasesListPage = lazy(() => import('@/pages/cases/cases-list-page'));
const NewCasePage = lazy(() => import('@/pages/cases/new-case-page'));
const CaseDetailPage = lazy(() => import('@/pages/cases/case-detail-page'));
const PatientsPage = lazy(() => import('@/pages/patients/patients-page'));
const PatientDetailPage = lazy(() => import('@/pages/patients/patient-detail-page'));
const DoctorsPage = lazy(() => import('@/pages/doctors/doctors-page'));
const DoctorDetailPage = lazy(() => import('@/pages/doctors/doctor-detail-page'));
const ClinicsPage = lazy(() => import('@/pages/clinics/clinics-page'));
const ClinicDetailPage = lazy(() => import('@/pages/clinics/clinic-detail-page'));
const TechniciansPage = lazy(() => import('@/pages/technicians/technicians-page'));
const TechnicianDetailPage = lazy(() => import('@/pages/technicians/technician-detail-page'));
const ProductionPage = lazy(() => import('@/pages/production/production-page'));
const QualityControlPage = lazy(() => import('@/pages/qc/quality-control-page'));
const DeliveryPage = lazy(() => import('@/pages/delivery/delivery-page'));
const InvoicesPage = lazy(() => import('@/pages/payments/invoices-page'));
const InvoiceDetailPage = lazy(() => import('@/pages/payments/invoice-detail-page'));
const PaymentsPage = lazy(() => import('@/pages/payments/payments-page'));
const ReportsPage = lazy(() => import('@/pages/reports/reports-page'));
const NotificationsPage = lazy(() => import('@/pages/notifications/notifications-page'));
const UsersPage = lazy(() => import('@/pages/settings/users-page'));
const RolesPage = lazy(() => import('@/pages/settings/roles-page'));
const ActivityPage = lazy(() => import('@/pages/settings/activity-page'));
const SettingsPage = lazy(() => import('@/pages/settings/settings-page'));
const NotFoundPage = lazy(() => import('@/pages/errors/not-found-page'));

const guard = (anyOf: string[], el: ReactNode) => <RequirePermission anyOf={anyOf}>{el}</RequirePermission>;

export const routes = [
  {
    element: <GuestOnly />,
    children: [
      {
        element: <AuthLayout />,
        children: [
          { path: '/login', element: <LoginPage /> },
          { path: '/forgot-password', element: <ForgotPasswordPage /> },
          { path: '/reset-password', element: <ResetPasswordPage /> },
        ],
      },
    ],
  },
  { path: '/logout', element: <LogoutPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: '/', element: <Navigate to="/dashboard" replace /> },
          { path: '/dashboard', element: guard([P.DASHBOARD_VIEW], <DashboardPage />) },
          { path: '/cases', element: guard([P.CASES_VIEW], <CasesListPage />) },
          { path: '/cases/new', element: guard([P.CASES_CREATE, P.CASES_SUBMIT], <NewCasePage />) },
          { path: '/cases/:id', element: guard([P.CASES_VIEW], <CaseDetailPage />) },
          { path: '/patients', element: guard([P.PATIENTS_VIEW], <PatientsPage />) },
          { path: '/patients/:id', element: guard([P.PATIENTS_VIEW], <PatientDetailPage />) },
          { path: '/doctors', element: guard([P.DOCTORS_VIEW], <DoctorsPage />) },
          { path: '/doctors/:id', element: guard([P.DOCTORS_VIEW], <DoctorDetailPage />) },
          { path: '/clinics', element: guard([P.CLINICS_VIEW], <ClinicsPage />) },
          { path: '/clinics/:id', element: guard([P.CLINICS_VIEW], <ClinicDetailPage />) },
          { path: '/technicians', element: guard([P.TECHNICIANS_VIEW], <TechniciansPage />) },
          { path: '/technicians/:id', element: guard([P.TECHNICIANS_VIEW, P.PRODUCTION_VIEW], <TechnicianDetailPage />) },
          { path: '/production', element: guard([P.PRODUCTION_VIEW], <ProductionPage />) },
          { path: '/quality-control', element: guard([P.QC_VIEW], <QualityControlPage />) },
          { path: '/delivery', element: guard([P.DELIVERY_VIEW], <DeliveryPage />) },
          { path: '/invoices', element: guard([P.INVOICES_VIEW], <InvoicesPage />) },
          { path: '/invoices/:id', element: guard([P.INVOICES_VIEW], <InvoiceDetailPage />) },
          { path: '/payments', element: guard([P.PAYMENTS_VIEW], <PaymentsPage />) },
          { path: '/reports', element: guard([P.REPORTS_VIEW], <ReportsPage />) },
          { path: '/notifications', element: <NotificationsPage /> },
          { path: '/users', element: guard([P.USERS_VIEW], <UsersPage />) },
          { path: '/roles', element: guard([P.ROLES_MANAGE], <RolesPage />) },
          { path: '/activity', element: guard([P.AUDIT_VIEW], <ActivityPage />) },
          { path: '/settings', element: guard([P.SETTINGS_VIEW], <SettingsPage />) },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
];

export const router = createBrowserRouter(routes);
