# 48HRS Dental Lab Management System

Internal operations system for **48HRS Dental Lab** — case intake, production, quality control, delivery, invoicing and reporting, built around a live **48-hour commitment** on every case.

Built from the `48hrs lab.zip` prototype (HTML prototype, design tokens, screenshots, logo, tooth-numbering chart). The prototype's visual identity, terminology, workflow, tooth chart geometry, service price list, roles and demo personas are carried over; everything is rebuilt as a typed React application.

**Stack:** React 19 · TypeScript (strict) · Vite 7 · Tailwind CSS 4 · Radix UI primitives (shadcn-style components) · Lucide icons · React Router 7 · React Hook Form + Zod · TanStack Query + TanStack Table · Zustand · Recharts · Vitest + Testing Library.

---

## 1. Setup & run

```bash
cd 48hrs-dental-lab
cp .env.example .env      # already done once; edit as needed
npm install
npm run dev               # http://localhost:5173
```

| Script | What it does |
| --- | --- |
| `npm run dev` | Dev server with HMR |
| `npm run build` | Type-check (`tsc -b`) + production build to `dist/` |
| `npm run preview` | Serve the production build |
| `npm run typecheck` | TypeScript only |
| `npm run lint` | ESLint (typescript-eslint + react-hooks) |
| `npm test` | Vitest suite (95 tests) |

### Demo accounts (mock mode only)

Password for every account: **`48hrs-demo`** — the login page lists them and fills the form on click.

| Role | Email |
| --- | --- |
| Super Admin | khalid@48hrs.lab |
| Admin | hodan@48hrs.lab |
| Lab Manager | omar@48hrs.lab |
| Reception | sagal@48hrs.lab |
| Technician | fatima@48hrs.lab (also ahmed@, ali@, maryan@) |
| Quality Control | idil@48hrs.lab |
| Delivery | bashir@48hrs.lab |
| Client (Smile Dental Clinic) | amina@smiledental.so |
| Client — disabled account | layla@horizondental.so |

The demo panel and the mock backend are **excluded from the bundle** when `VITE_USE_MOCKS=false` (verified: an API-mode build contains no mock code or demo credentials).

## 2. Environment variables

Everything in `.env` is compiled into the browser bundle — never put secrets there.

| Variable | Default | Purpose |
| --- | --- | --- |
| `VITE_API_URL` | `http://localhost:8000/api/v1` | Backend base URL |
| `VITE_USE_MOCKS` | `true` | `true` = in-browser mock API, `false` = real HTTP API |
| `VITE_API_SNAKE_CASE` | `false` | Convert keys camelCase ⇄ snake_case (Laravel resources) |
| `VITE_API_WITH_CREDENTIALS` | `false` | Send cookies (Laravel Sanctum SPA auth) |
| `VITE_MOCK_LATENCY_MS` | `250` | Simulated latency of the mock API |
| `VITE_SESSION_TIMEOUT_MINUTES` | `480` | Session lifetime issued by the mock API |

## 3. Project structure

```
src/
├── app.tsx, main.tsx          # providers (Query, Router, Tooltip, Toaster) + auth bootstrap
├── config/env.ts              # typed env access
├── types/                     # models.ts (entities), api.ts (payloads, filters, responses)
├── lib/                       # pure domain logic — shared by UI and mock backend
│   ├── permissions.ts         # permission catalogue + default role matrix
│   ├── workflow.ts            # statuses, stages, workflow actions, canPerformAction(), validateActionInput()
│   ├── sla.ts                 # 48-hour deadline engine (due_at, states, formatting)
│   ├── billing.ts             # pricing, invoice status, payment validation (Total − Paid = Remaining)
│   ├── teeth.ts               # universal-numbering arch geometry (from the prototype)
│   ├── constants.ts, validation.ts, query-client.ts, query-keys.ts
├── services/                  # API layer — the only place that talks to the backend
│   ├── api/client.ts          # api.get/post/…/upload/download, 401 → logout
│   ├── api/http-transport.ts  # fetch/XHR transport (bearer token, upload progress, 422 parsing)
│   ├── api/token-store.ts     # session token + expiry
│   └── authService, caseService, patientService, doctorService, clinicService,
│       technicianService, paymentService (invoices+payments), labService (QC, deliveries),
│       reportService (reports, dashboard, search), notificationService, adminService
├── mocks/                     # removable mock backend (see §6)
├── hooks/                     # use-auth, use-now (shared clock), use-sla, use-list-state (URL-backed table state),
│                              # use-url-state, use-debounce, use-page-title, use-unsaved-changes (route + tab-close guard),
│                              # use-return-focus (focus back to the opener after a dialog)…
│   └── api/                   # TanStack Query hooks per domain
├── stores/                    # Zustand: auth-store (session), ui-store (drawer, search, page title)
├── components/
│   ├── ui/                    # Button, Input/Select/Textarea, Badge, ActiveBadge, Card/Field, Dialog, ConfirmDialog,
│   │                          # Menu, Tabs, SegmentedControl, Checkbox/Switch, Popover, Tooltip, SimpleTable,
│   │                          # PageToolbar, QueryError (403/404/retry), useConfirmedDelete,
│   │                          # feedback (Spinner, Skeleton, EmptyState, ErrorState, Alert, ProgressBar)
│   ├── forms/                 # FormField, Text/Select/Date/Checkbox/Switch fields, Combobox, MultiSelect
│   ├── tables/                # DataTable (sorting, pagination, selection, column toggle, states), RowActions,
│   │                          # toolbar (SearchInput, FilterSelect, DateFilter, CollapsibleFilters), Pagination
│   ├── files/                 # dropzone, upload queue with progress, preview dialog
│   ├── cases/                 # badges, SLA countdowns, tooth chart, timeline, attachments, useCaseWorkflow
│   │                          # (CaseActions buttons + CaseRowActions menu share one runner and dialog)
│   ├── dashboard/, directory/, payments/, notifications/
│   ├── layout/                # Sidebar, Header, Breadcrumbs, GlobalSearch, brand wordmark
├── layouts/                   # AppLayout (sidebar + header), AuthLayout (split login)
├── routes/                    # router, guards (RequireAuth, RequirePermission, Can), nav config
├── pages/                     # auth, dashboard, cases, patients, doctors, clinics, technicians,
│                              # production, qc, delivery, payments, reports, notifications, settings, errors
├── utils/                     # formatting, dates, CSV/Blob download, reference numbers
└── test/                      # Vitest suites
```

### Routes

`/login` `/forgot-password` `/reset-password` `/logout` · `/dashboard` · `/cases` `/cases/new` `/cases/:id` · `/patients` `/patients/:id` · `/doctors` `/doctors/:id` · `/clinics` `/clinics/:id` · `/technicians` `/technicians/:id` · `/production` · `/quality-control` · `/delivery` · `/invoices` `/invoices/:id` · `/payments` · `/reports` · `/notifications` · `/users` · `/roles` · `/activity` · `/settings`

Every page is lazy-loaded, wrapped in an error boundary, and guarded by permission. Breadcrumbs are derived from the route and the nav config; detail pages contribute their record title (e.g. the case number), and section links are only rendered when the user may open that section.

## 4. Authentication

- `authService.login()` returns `{ token, expiresAt, user, permissions }`. The token is kept in `tokenStore` (localStorage, with expiry); the user and permissions live in the Zustand `auth-store`.
- On app start `bootstrap()` re-validates the stored token with `GET /auth/me`.
- **Automatic logout:** a timer fires at `expiresAt`; any `401` from any request triggers logout (registered via `setUnauthorizedHandler`); the login page then shows "Your session expired".
- On window focus the session is refreshed, so role/permission changes by an admin apply without re-login.
- `RequireAuth` redirects anonymous users to `/login?next=…` (only same-origin paths are accepted after login — no open redirects). `GuestOnly` keeps signed-in users away from auth pages. On logout the query cache is cleared.
- Forgot/reset password: `POST /auth/forgot-password` (same response whether the email exists) and `POST /auth/reset-password`. The mock returns the reset link on screen because it cannot send e-mail.
- Mock backend stores salted SHA-256 hashes only (no plain-text passwords) and rate-limits failed logins. A real backend should use bcrypt/argon2 (Laravel's default `Hash`).
- For **Laravel Sanctum cookie auth** set `VITE_API_WITH_CREDENTIALS=true`; the bearer header is simply ignored.

## 5. Roles & permissions

Roles: **Super Admin, Admin, Lab Manager, Reception, Technician, Quality Control, Delivery, Client** (the prototype's five roles plus the QC/Delivery/Admin roles you requested).

- Granular keys in `src/lib/permissions.ts`: `cases.view`, `cases.view_all`, `cases.create`, `cases.submit`, `cases.edit`, `cases.delete`, `cases.accept`, `cases.assign`, `cases.update_status`, `cases.cancel`, `files.*`, `patients.*`, `doctors.*`, `clinics.*`, `technicians.*`, `qc.perform`, `delivery.manage`, `invoices.view`, `payments.record`, `reports.view`, `reports.financial`, `users.manage`, `roles.manage`, `services.manage`, `settings.manage`, `audit.view`, …
- The matrix is editable at **Roles & Permissions** (Super Admin is locked to full access). Changes apply immediately — buttons disappear and the API refuses the action.
- **Enforcement is server-side**: every mock handler calls `authenticate()` + `authorize()` and applies row-level scope (technicians see only their cases, clients only their clinic's). The UI uses the same keys purely to hide what the server would refuse (`useAuth().can()`, `<Can>`, `RequirePermission`).
- Workflow rules live in one function — `canPerformAction(action, case, actor)` in `lib/workflow.ts` — used by both the UI and the backend, so they cannot drift.

## 6. API integration

Components never call `fetch`. The chain is **page → TanStack Query hook (`hooks/api`) → service (`services/*`) → `api` client → transport**.

- `VITE_USE_MOCKS=true`: the transport is `src/mocks/transport.ts` — an in-browser REST backend with routing, auth, permissions, validation (422 with `{ message, errors }`), 404/409 responses, persistence (localStorage + IndexedDB for uploaded files) and a deadline scheduler.
- `VITE_USE_MOCKS=false`: `http-transport.ts` sends real requests to `VITE_API_URL` with `Authorization: Bearer`, array params as `status[]=…`, XHR uploads with progress, optional snake_case conversion, and Laravel-style error parsing.
- **Removing the mocks** later: set `VITE_USE_MOCKS=false` and delete `src/mocks/` (plus `settingsService.resetDemo` and `test/api.test.ts`, which targets the mock backend). No page or component imports from `mocks/` (the login page loads demo accounts lazily in mock mode only).

### Endpoint contract (implement these in Laravel)

| Method & path | Purpose |
| --- | --- |
| `POST /auth/login` · `POST /auth/logout` · `GET /auth/me` | Session |
| `POST /auth/forgot-password` · `POST /auth/reset-password` | Password reset |
| `GET /cases` (filters: `search, status[], priority[], technicianId, doctorId, clinicId, patientId, caseType, paymentStatus, sla, from, to, dueFrom, dueTo, openOnly, sort, dir, page, perPage`) | Paginated list `{ data, meta: { page, perPage, total, lastPage } }` |
| `GET /cases/counts` | Sidebar badges |
| `POST /cases` · `GET/PATCH/DELETE /cases/:id` | CRUD |
| `POST /cases/:id/actions` `{ action, note, technicianId?, payment?, qc?, delivery? }` | **All status changes** (accept, request_correction, resubmit, reject, start_review, assign, start_production, submit_qc, qc_pass, qc_fail, start_rework, dispatch, deliver, confirm_receipt, cancel) |
| `POST /cases/:id/notes` | Production notes |
| `POST /cases/:id/attachments` (multipart `file`, `category`) · `DELETE …/:attachmentId` · `GET …/:attachmentId/download` | Files |
| `GET/POST /patients`, `GET/PUT/DELETE /patients/:id` (same for `/doctors`, `/clinics`, `/technicians`; delete returns 422 while records are linked) | Directory |
| `GET /invoices` · `GET /invoices/:id` · `POST /invoices/:id/payments` · `GET /payments` | Finance |
| `GET /quality-checks` · `GET /deliveries` | QC & delivery history |
| `GET /dashboard?period=today\|7d\|30d\|month` · `GET /reports` · `GET /search?q=` | Aggregates & global search |
| `GET /notifications` · `POST /notifications/:id/read` · `POST /notifications/read-all` | Notification center |
| `GET/POST /users`, `PUT/DELETE /users/:id`, `PATCH /users/:id/status` · `GET /roles`, `PUT /roles/:key`, `GET /permissions` | Access control |
| `GET/POST /services`, `PUT/DELETE /services/:id` · `GET/PUT /settings` · `GET /activity` | Administration |

The data model (`src/types/models.ts`) maps 1:1 to tables: `users, roles, permissions, clinics, doctors, patients, technicians, services, cases, case_status_history, case_notes, case_attachments, quality_checks, deliveries, invoices, payments, notifications, activity_log, settings`.

## 7. The 48-hour workflow

```
Client portal:  Submitted ⇄ Correction requested  (→ Rejected)
Lab:            Received → Review → Assigned → In Production → Quality Control → Ready → (Out for delivery) → Delivered → Completed
                                                    ↑  Rework required  ←── QC fail
Any open stage → Cancelled
```

- **Clock start:** `received_at` is set when Reception accepts a client submission, or immediately when Reception registers a case at intake (as in the prototype: "The 48-hour countdown starts only when Reception accepts the case"). `due_at = received_at + slaHours` (48 by default, configurable in Settings; a custom due date can be set at intake).
- **No fake countdowns:** every timer is computed from stored timestamps (`getSlaInfo(case, now)`) on a shared clock (`useNow`) — one interval per tick rate for the whole page.
- **States:** On track (> 12 h) · At risk (≤ 12 h) · Critical (≤ 4 h) · Overdue · Delivered on time / late (turnaround frozen at `delivered_at`) · Not started. Thresholds are configurable.
- **Alerts:** a deadline scanner raises "Deadline approaching" and "Case overdue" notifications once per case (the mock runs it per request; on Laravel run it from the scheduler every minute).
- **History:** every transition writes `case_status_history` (from, to, user, role, time, note); the case timeline shows the time spent between steps.
- **Guards:** transitions are validated against the current status (409 when stale), the actor's permission and scope (the assigned technician, the owning clinic).
- Accepting a case creates the invoice (and optionally records a deposit); QC fail requires issues + notes and increments `rework_count`; delivery requires the receiver's name.

## 8. Feature map

Dashboard (role-specific: operations, technician, client; period filter today / 7 days / 30 days / this month, operations KPIs link to their filtered lists) · Cases list (search, 10 filters, sort, pagination, column toggle, bulk CSV export, mobile cards) · New case (clinic/doctor/patient comboboxes, service cards, interactive tooth chart, shade, material, files with progress, priority/emergency fee, live summary, sticky mobile total) · Case detail (live countdown, stepper, information, production + notes, QC, delivery, payment, attachments with preview/download/delete, audit timeline, edit, print) · Production board · QC queue & history · Delivery queue & history · Patients / Doctors / Clinics / Technicians with detail pages and statistics · Invoices (printable) & payments · Reports with filters, charts and CSV exports · Notification center · Global search (Ctrl/⌘ K) · Users · Roles matrix · Activity log · Settings (lab profile, SLA rules, price list, demo reset).

## 9. Testing

```bash
npm test          # 95 tests, ~6 s
```

| Suite | Covers |
| --- | --- |
| `sla.test.ts` | due_at calculation, on-track/at-risk/critical/overdue, delivered on time vs late, formatting |
| `billing.test.ts` | per-tooth pricing, emergency fee, invoice paid/partial/overdue, payment validation |
| `permissions.test.ts` | permission checks, assigned-technician rule, status guards, client scoping, hashing |
| `api.test.ts` | login/logout/expiry/disabled accounts/reset password, API-side 403 & scoping, create-case validation, workflow + history, QC fail → rework → pass, delivery, notifications, payments, search, filtering, pagination |
| `http.test.ts` | real HTTP transport: bearer token, query arrays, 422 parsing, network errors, key casing |
| `ui.test.tsx` | login form, redirect after login, protected route, permission gate, tooth chart, create-case validation |
| `layout.test.tsx` | breadcrumbs (incl. permission-aware links), row actions, simple table, due-date filter |
| `audit.test.ts` | time-travel SLA through the API (on track → at risk → overdue, listed, counted, notified; delivered on time vs late), every workflow action × role permission matrix, persisted history, accept integrity (a rejected payment leaves the case untouched), action input validation, unpaid → partial → paid → overdue, dashboard periods, financial KPIs hidden without `reports.financial`, session expiry, technician delete guards |
| `modules.test.ts` | report filters (date, status, technician, doctor, clinic, case type), directory search/filter/sort/pagination/delete guards, global search for case ID, patient, doctor, clinic, phone and invoice with scope, notification read/unread and ownership, invoice scope |

### Browser audit

Checked in Chromium with Playwright and axe-core (WCAG 2 A/AA):

- **Route crawl:** signed in as each of the 8 roles and followed every nav and in-page link — 109 page visits at 1366 px, 28 at 390 px. It found 0 console errors, 0 crashes, 0 broken links, 0 unlabeled controls and 0 axe violations.
- **Workflow run** (client → reception → manager → technician → QC fail → rework → QC pass → dispatch → deliver → client confirm → final payment), plus logout, filters, sorting, cancel with reason, revoking a permission live, user creation and first sign-in, clinic creation, SLA settings validation and notifications.
- **Tablet widths** (768 / 1024 px): no horizontal page overflow.
- **Responsive:** login, dashboard, cases, case detail, patients, payments and reports at 390 / 768 / 1366 px — no page overflow, no element outside the viewport, mobile drawer opens, navigates and closes.
- **Permissions:** 387 checks across all roles — sidebar, breadcrumbs, page and row actions, and direct URLs (blocked with "Access restricted"; the API answers 403).
- **Keyboard:** 20 checks — skip link, keyboard sign-in, focus trap and focus return for dialogs and row menus, arrow keys in segmented controls and menus, tooth chart with Space.
- **Forms:** 57 checks — required/invalid errors wired with `aria-invalid` + `aria-describedby`, busy state, success toast, server 422 mapped to fields, Cancel, unsaved-changes prompt.
- **Deadline run:** the browser clock is fast-forwarded 49 h — the session expires and returns to the same page after sign-in, the case shows Overdue, appears under the overdue filter and a "Case overdue" notification arrives.

The full UI workflow (client submits → reception accepts with deposit → manager assigns → technician produces → QC fails → rework → QC passes → dispatch → deliver → client confirms → remaining payment) was also verified end-to-end in Chromium at desktop and 390 px mobile widths with no console errors.

## 10. Design notes

Tokens come from the prototype's design-system files (navy `#0a1424`–`#17498a`, gold accent `#d9b53f`, neutral surfaces, 10 px radius, navy-tinted shadows, Plus Jakarta Sans + Playfair Display wordmark). Status colours follow the prototype (green on track, amber attention, red critical, navy overdue); the green, amber and tertiary-text tokens are one step darker than the prototype values so every label meets 4.5:1 contrast. Chart colours (navy + deep gold) were validated for colour-vision deficiency and 3:1 contrast. Accessibility: labelled fields with `aria-describedby` errors, focus rings, Radix dialogs with focus trapping, keyboard-operable tooth chart, comboboxes and segmented controls (arrow keys), focusable scroll regions, breadcrumb `aria-current`, skip link, `prefers-reduced-motion` respected. The universal-numbering reference image from the ZIP is available from the tooth chart (“Numbering guide”).
