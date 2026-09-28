/**
 * The REST API, mounted at /api. Every route below `authenticate` requires a
 * session, and each one declares the permission it needs; row-level scope
 * (own clinic / assigned technician) is enforced again inside the services.
 */
import { Router } from 'express';
import { PERMISSIONS as P } from '@48hrs/shared/permissions';
import { activityController, catalogueController, roleController, settingsController, userController } from '../controllers/admin-controller.ts';
import { authController } from '../controllers/auth-controller.ts';
import { caseController } from '../controllers/case-controller.ts';
import { clinicController, doctorController, patientController, technicianController } from '../controllers/directory-controller.ts';
import { financeController } from '../controllers/finance-controller.ts';
import { analyticsController, labController, notificationController } from '../controllers/lab-controller.ts';
import { prisma } from '../lib/prisma.ts';
import { authenticate, authorize } from '../middleware/auth.ts';
import { loginLimiter, passwordResetLimiter } from '../middleware/rate-limit.ts';
import { singleFileUpload } from '../middleware/upload.ts';

export function apiRouter() {
  const r = Router();

  /* ---- Public ---------------------------------------------------------- */
  r.get('/health', (_req, res) => void res.json({ status: 'ok' }));
  r.get('/health/ready', async (_req, res) => {
    await prisma.$queryRaw`SELECT 1`;
    res.json({ status: 'ready' });
  });

  r.post('/auth/login', loginLimiter, authController.login);
  r.post('/auth/refresh', authController.refresh);
  r.post('/auth/logout', authController.logout);
  r.post('/auth/forgot-password', passwordResetLimiter, authController.forgotPassword);
  r.post('/auth/reset-password', passwordResetLimiter, authController.resetPassword);

  /* ---- Everything else needs a session ---------------------------------- */
  r.use(authenticate);
  r.get('/auth/me', authController.me);

  // Cases
  r.get('/cases', authorize(P.CASES_VIEW), caseController.list);
  r.get('/cases/counts', caseController.counts);
  r.post('/cases', authorize([P.CASES_CREATE, P.CASES_SUBMIT], 'any'), caseController.create);
  r.get('/cases/:id', authorize(P.CASES_VIEW), caseController.get);
  r.patch('/cases/:id', authorize(P.CASES_EDIT), caseController.update);
  r.delete('/cases/:id', authorize(P.CASES_DELETE), caseController.remove);
  // Workflow steps: permission, assignment and clinic ownership are checked per step (shared canPerformAction).
  r.post('/cases/:id/status', authorize(P.CASES_VIEW), caseController.workflow('status'));
  r.post('/cases/:id/assign', authorize(P.CASES_VIEW), caseController.workflow('assign'));
  r.post('/cases/:id/qc', authorize(P.CASES_VIEW), caseController.workflow('qc'));
  r.post('/cases/:id/rework', authorize(P.CASES_VIEW), caseController.workflow('rework'));
  r.post('/cases/:id/delivery', authorize(P.CASES_VIEW), caseController.workflow('delivery'));
  r.post('/cases/:id/notes', authorize([P.CASES_EDIT, P.CASES_UPDATE_STATUS, P.QC_PERFORM, P.CASES_ASSIGN, P.DELIVERY_MANAGE], 'any'), caseController.addNote);
  r.post('/cases/:id/attachments', authorize([P.FILES_VIEW, P.FILES_UPLOAD]), caseController.loadCaseForUpload, singleFileUpload, caseController.upload);
  r.delete('/cases/:id/attachments/:attachmentId', authorize(P.CASES_VIEW), caseController.removeAttachment);
  r.get('/cases/:id/attachments/:attachmentId/download', authorize(P.FILES_VIEW), caseController.download);

  // Directory
  r.get('/patients', authorize([P.PATIENTS_VIEW, P.CASES_SUBMIT], 'any'), patientController.list);
  r.post('/patients', authorize(P.PATIENTS_CREATE), patientController.create);
  r.get('/patients/:id', authorize(P.PATIENTS_VIEW), patientController.get);
  r.put('/patients/:id', authorize(P.PATIENTS_EDIT), patientController.update);
  r.delete('/patients/:id', authorize(P.PATIENTS_DELETE), patientController.remove);

  r.get('/doctors', authorize([P.DOCTORS_VIEW, P.CASES_SUBMIT], 'any'), doctorController.list);
  r.post('/doctors', authorize(P.DOCTORS_CREATE), doctorController.create);
  r.get('/doctors/:id', authorize(P.DOCTORS_VIEW), doctorController.get);
  r.put('/doctors/:id', authorize(P.DOCTORS_EDIT), doctorController.update);
  r.delete('/doctors/:id', authorize(P.DOCTORS_DELETE), doctorController.remove);

  r.get('/clinics', authorize([P.CLINICS_VIEW, P.CASES_SUBMIT], 'any'), clinicController.list);
  r.post('/clinics', authorize(P.CLINICS_CREATE), clinicController.create);
  r.get('/clinics/:id', authorize(P.CLINICS_VIEW), clinicController.get);
  r.put('/clinics/:id', authorize(P.CLINICS_EDIT), clinicController.update);
  r.delete('/clinics/:id', authorize(P.CLINICS_DELETE), clinicController.remove);

  r.get('/technicians', authorize([P.TECHNICIANS_VIEW, P.CASES_ASSIGN], 'any'), technicianController.list);
  r.post('/technicians', authorize(P.TECHNICIANS_CREATE), technicianController.create);
  r.get('/technicians/:id', technicianController.get); // own profile, or technicians.view (checked in the service)
  r.put('/technicians/:id', authorize(P.TECHNICIANS_EDIT), technicianController.update);
  r.delete('/technicians/:id', authorize(P.TECHNICIANS_DELETE), technicianController.remove);

  // Finance
  r.get('/invoices', authorize(P.INVOICES_VIEW), financeController.listInvoices);
  r.post('/invoices', authorize(P.PAYMENTS_RECORD), financeController.createInvoice);
  r.get('/invoices/:id', authorize(P.INVOICES_VIEW), financeController.getInvoice);
  r.get('/payments', authorize(P.PAYMENTS_VIEW), financeController.listPayments);
  r.post('/payments', authorize(P.PAYMENTS_RECORD), financeController.recordPayment);

  // Lab floor history
  r.get('/quality-control', authorize(P.QC_VIEW), labController.qualityChecks);
  r.get('/deliveries', authorize(P.DELIVERY_VIEW), labController.deliveries);

  // Dashboard, reports, search
  r.get('/dashboard', authorize(P.DASHBOARD_VIEW), analyticsController.dashboard);
  r.get('/reports/cases', authorize(P.REPORTS_VIEW), analyticsController.cases);
  r.get('/reports/production', authorize(P.REPORTS_VIEW), analyticsController.production);
  r.get('/reports/technicians', authorize(P.REPORTS_VIEW), analyticsController.technicians);
  r.get('/reports/clinics', authorize(P.REPORTS_VIEW), analyticsController.clinics);
  r.get('/reports/financial', authorize([P.REPORTS_VIEW, P.REPORTS_FINANCIAL]), analyticsController.financial);
  r.get('/search', analyticsController.search);

  // Notifications (always the caller's own)
  r.get('/notifications', notificationController.list);
  r.post('/notifications/read-all', notificationController.markAllRead);
  r.post('/notifications/:id/read', notificationController.markRead);

  // Administration
  r.get('/users', authorize(P.USERS_VIEW), userController.list);
  r.post('/users', authorize(P.USERS_MANAGE), userController.create);
  r.put('/users/:id', authorize(P.USERS_MANAGE), userController.update);
  r.patch('/users/:id/status', authorize(P.USERS_MANAGE), userController.setStatus);
  r.delete('/users/:id', authorize(P.USERS_MANAGE), userController.remove);
  r.get('/roles', authorize([P.USERS_VIEW, P.ROLES_MANAGE], 'any'), roleController.list);
  r.put('/roles/:key', authorize(P.ROLES_MANAGE), roleController.update);
  r.get('/permissions', roleController.permissions);
  r.get('/services', catalogueController.list);
  r.post('/services', authorize(P.SERVICES_MANAGE), catalogueController.create);
  r.put('/services/:id', authorize(P.SERVICES_MANAGE), catalogueController.update);
  r.delete('/services/:id', authorize(P.SERVICES_MANAGE), catalogueController.remove);
  r.get('/settings', settingsController.get);
  r.put('/settings', authorize(P.SETTINGS_MANAGE), settingsController.update);
  r.get('/activity', authorize(P.AUDIT_VIEW), activityController.list);

  return r;
}
