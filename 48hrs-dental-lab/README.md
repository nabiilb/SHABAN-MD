# 48HRS Dental Lab Management System

Operations system for **48HRS Dental Lab** — case intake, production, quality control, delivery, invoicing and reporting, built around a live **48-hour commitment** on every case. Built from the `48hrs lab.zip` prototype (design tokens, screenshots, logo, tooth-numbering chart).

A full-stack TypeScript system:

```
React 19 SPA (apps/web)                       Vite · Tailwind 4 · React Router 7 · TanStack Query/Table · React Hook Form · Zod
        │  REST + JSON, HTTP-only cookie session
        ▼
Node.js API (apps/api)                        Express 5 · Zod · jose (JWT) · helmet · multer · pino
        │  route → controller → service → repository
        ▼
Prisma ORM 7 (prisma/)  ──►  PostgreSQL       migrations · seed · transactions · row locks
        ▲
Deadline worker (apps/api/src/jobs)           at-risk / overdue alerts, credential purge

Shared domain (packages/shared)               types · enums · permissions · workflow · SLA · billing · analytics · Zod schemas
```

The browser, the API and the in-browser mock backend use **the same** types, permission catalogue, workflow rules, SLA engine, billing rules, validation schemas and report aggregations from `packages/shared`, so the three can never disagree. **PostgreSQL behind the Node API is the source of truth**; the mock backend is an optional development tool.

---

## 1. Quick start (development)

Prerequisites: **Node.js 22+** and **PostgreSQL 14+** (16 recommended).

```bash
cd 48hrs-dental-lab
cp .env.example .env                    # backend settings — replace every CHANGE_ME
cp apps/web/.env.example apps/web/.env  # frontend settings (public, no secrets)

# create a database user and the three databases (dev, tests, e2e)
createuser --createdb --pwprompt lab
createdb -O lab dental_lab && createdb -O lab dental_lab_test && createdb -O lab dental_lab_e2e

npm install            # installs every workspace and generates the Prisma client
npm run db:migrate     # applies prisma/migrations to DATABASE_URL
npm run db:seed        # demo lab data; every demo account gets SEED_USER_PASSWORD
npm run dev            # API :4000 + worker + web :5173 (the web dev server proxies /api)
```

Open http://localhost:5173 and sign in with a demo account below and your `SEED_USER_PASSWORD`.

| Role | Demo account |
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

### Scripts (repository root)

| Script | What it does |
| --- | --- |
| `npm run dev` | API (watch) + deadline worker + web dev server, one terminal |
| `npm run dev:api` / `npm run dev:web` / `npm run worker` | One process each |
| `npm run build` | Web (`tsc -b` + Vite → `apps/web/dist`) and API (esbuild → `apps/api/dist/server.js`, `worker.js`) |
| `npm start` / `npm run start:worker` | Run the built API / worker |
| `npm run start:worker:once` (dev: `npm run worker:once`) | One worker pass, then exit (cron / systemd timer) |
| `npm run typecheck` · `npm run lint` | TypeScript in every workspace · ESLint for the whole repo |
| `npm test` | Unit and integration tests of every workspace (see §9) |
| `npm run test:e2e` | Full-stack browser tests (web + API + PostgreSQL) |
| `npm run db:migrate` · `db:deploy` · `db:seed` · `db:reset` · `db:generate` | Prisma: dev migration · apply migrations · seed · reset · regenerate client |

## 2. Project structure

```
48hrs-dental-lab/
├── apps/
│   ├── web/                     React SPA (unchanged UI; talks to the API through services/)
│   │   ├── src/
│   │   │   ├── services/        the only code that talks to the backend
│   │   │   │   └── api/         client (refresh-and-retry on 401), http-transport (cookies + CSRF header), server-clock
│   │   │   ├── mocks/           optional in-browser backend with the same REST contract (VITE_USE_MOCKS=true)
│   │   │   ├── hooks/api/       TanStack Query hooks per domain
│   │   │   ├── components/ pages/ layouts/ routes/ stores/ …
│   │   │   └── test/            Vitest (UI + mock backend)
│   │   └── e2e/                 Playwright full-stack tests
│   └── api/                     Node.js REST API
│       ├── src/
│       │   ├── config/          validated environment (refuses to start with weak or missing settings)
│       │   ├── routes/          every endpoint and its permission guard
│       │   ├── controllers/     parse request → call service → respond
│       │   ├── services/        business rules, transactions (cases, workflow, SLA, payments, …)
│       │   ├── repositories/    Prisma queries, SQL filters, row → DTO mappers
│       │   ├── policies/        row-level scope (own clinic / assigned technician)
│       │   ├── middleware/      authentication, authorization, CSRF, validation, rate limits, uploads, errors
│       │   ├── notifications/   recipient resolution + notification writes
│       │   ├── jobs/            deadline scan + worker process
│       │   ├── lib/             prisma, password hashing, tokens, cookies, storage, mailer, logger
│       │   ├── db/seed.ts       base + demo seeding (used by prisma/seed.ts and the tests)
│       │   └── generated/       Prisma client (generated, not committed)
│       └── test/                Vitest + Supertest against a real PostgreSQL database
├── packages/shared/src/         types, permissions, workflow, sla, billing, analytics, notifications,
│                                case-requests (REST contract), schemas (Zod), dates, demo-data
├── prisma/                      schema.prisma · migrations/ · seed.ts
├── deploy/                      staging / production environment templates
└── scripts/dev.mjs              runs the whole stack for development
```

## 3. Environment variables

Backend variables live in the root `.env` (template: `.env.example`; staging/production templates in `deploy/`). **Never commit `.env` files**, database passwords, JWT secrets or real credentials — `.gitignore` excludes them. The API validates its configuration at start-up and refuses to run with a short `JWT_SECRET`, an unknown time zone or, in production, non-secure cookies, no allowed origin or no SMTP.

| Variable | Default | Purpose |
| --- | --- | --- |
| `DATABASE_URL` | — | PostgreSQL connection (API, worker, Prisma) |
| `TEST_DATABASE_URL`, `E2E_DATABASE_URL` | — | Disposable databases for `npm test` / `npm run test:e2e` (wiped each run) |
| `PORT` | `4000` | API port |
| `CORS_ORIGINS` | — | Web origins allowed to call the API with cookies (comma-separated) |
| `TRUST_PROXY` | `0` | Reverse proxies in front of the API (client IPs, secure cookies) |
| `JWT_SECRET` | — | ≥ 32 characters (`openssl rand -base64 48`) |
| `ACCESS_TOKEN_TTL_MINUTES` | `15` | Access-token lifetime (silently refreshed) |
| `SESSION_TTL_MINUTES` | `480` | Absolute session length (8 h); refresh never extends it |
| `COOKIE_SECURE` / `COOKIE_SAMESITE` / `COOKIE_DOMAIN` | `true` / `lax` / — | Cookie attributes (`false` only for http://localhost) |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCK_MINUTES` | `5` / `15` | Per-account lockout after wrong passwords |
| `AUTH_RATE_LIMIT` | `100` | Sign-in requests per IP per 15 min |
| `LAB_TIMEZONE` | `UTC` | The lab's working day (due today, reports, dashboard periods) |
| `UPLOAD_DIR` / `MAX_UPLOAD_MB` | `./storage/uploads` / `50` | Case-file storage (relative to `apps/api`) and size limit |
| `APP_URL` | `http://localhost:5173` | Web URL used in password-reset links |
| `MAIL_TRANSPORT` / `SMTP_URL` / `MAIL_FROM` | `log` | `smtp` in staging/production; `log` prints links (development only) |
| `WEB_DIST_DIR` | — | Serve the built SPA from the API (single origin), e.g. `../web/dist` |
| `WORKER_INTERVAL_SECONDS` | `60` | Deadline-scan interval |
| `LOG_LEVEL` | `info` | pino log level |
| `SEED_MODE`, `SEED_USER_PASSWORD`, `SEED_ADMIN_*`, `ALLOW_DEMO_SEED` | — | Seeding (§4) |

Frontend variables (`apps/web/.env`, compiled into the public bundle — no secrets):

| Variable | Default | Purpose |
| --- | --- | --- |
| `VITE_API_URL` | `/api` | API base URL (same origin by default; the dev server proxies `/api` → `API_PROXY_TARGET`, default `http://localhost:4000`) |
| `VITE_USE_MOCKS` | `false` | `true` runs the UI against the in-browser mock backend, without API or database |
| `VITE_MOCK_LATENCY_MS`, `VITE_SESSION_TIMEOUT_MINUTES` | `250`, `480` | Mock backend only |

## 4. Database

- **Schema:** `prisma/schema.prisma` — `users, roles, permissions, role_permissions, sessions, password_reset_tokens, clinics, doctors, patients, technicians, lab_services, settings, sequences, cases, case_notes, case_status_history, case_assignments, case_attachments, quality_checks, quality_issues, deliveries, invoices, payments, notifications, activity_log`. Foreign keys everywhere (`RESTRICT` for business records, `CASCADE` for a case's own history/files/QC/deliveries/invoice), indexes on case number, patient, doctor, clinic, technician, status, priority, `due_at`, `received_at`, `created_at` and invoice number; money is `DECIMAL(12,2)`; timestamps are `timestamptz`.
- **Migrations:** `npm run db:migrate` (development: creates and applies), `npm run db:deploy` (staging/production: applies committed migrations only).
- **Seeding:** `npm run db:seed`
  - `SEED_MODE=demo` (default outside production) replaces all data with the demo lab — the same dataset the mock backend uses, with sample files written to storage.
  - `SEED_MODE=base` (default in production) creates the permission catalogue, default roles, lab settings and number sequences, and the first Super Admin from `SEED_ADMIN_EMAIL` / `SEED_ADMIN_NAME` / `SEED_ADMIN_PASSWORD`. It is idempotent and never touches existing business data.
- Case, invoice and patient numbers come from row-locked sequences, so concurrent requests never collide.

## 5. Authentication and security

- **Passwords** are hashed with scrypt (salted, memory-hard, parameters stored with the hash). Nothing stores or logs plain-text passwords.
- **Sessions:** `POST /api/auth/login` creates a `sessions` row with an absolute expiry and sets two **HTTP-only** cookies: `lab_access` (HS256 JWT, 15 min, path `/api`, carries the session id) and `lab_refresh` (opaque random token, stored only as a SHA-256 hash, path `/api/auth`). JavaScript never sees either token.
- **Refresh:** on a 401 the web client calls `POST /api/auth/refresh` once (shared by concurrent requests) and retries. The refresh token is **rotated** on every use (a replayed old token is refused); the session's end never moves.
- **Expiry and revocation:** every request re-checks the session row — logout, a password reset, disabling a user or reaching the 8-hour end kills all of that session's tokens at once; role changes apply on the next request. The UI signs out at the session end and returns the user to the same page after signing in again (`/login?next=…`, same-origin paths only).
- **Clock:** deadlines and session ends are server time. Each response carries `X-Server-Time`; the web app corrects its countdowns and session checks with it, so a wrong device clock can neither fake an SLA state nor end a session early.
- **Protections:** helmet security headers (strict CSP for the API and, when served, the SPA), CORS allow-list with credentials, CSRF guard (state-changing requests need `X-Requested-With`; a foreign `Origin` is refused), per-account lockout in the database plus per-IP rate limits on sign-in and reset, 1 MB JSON limit, Zod validation of every body and query filter, uploads limited by size/extension **and content signature** (renamed executables or HTML are rejected), files stored under random keys outside the web root and served with `Content-Disposition: attachment` and `nosniff`, SQL only through Prisma or parameterised queries.
- **Password reset:** `POST /api/auth/forgot-password` answers identically whether or not the account exists; the one-hour token is stored as a hash, single-use, and resetting ends every session of that user.

## 6. Roles and permissions

Eight roles — Super Admin, Admin, Lab Manager, Reception, Technician, Quality Control, Delivery, Client (clinic portal) — over a granular permission catalogue (`packages/shared/src/permissions.ts`, stored in the `permissions` table). Defaults:

| Permission | SA | Adm | Mgr | Rec | Tech | QC | Del | Cli |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| `dashboard.view` | ● | ● | ● | ● | ● | ● | ● | ● |
| `cases.view` | ● | ● | ● | ● | ● | ● | ● | ● |
| `cases.view_all` | ● | ● | ● | ● |  | ● | ● |  |
| `cases.create` | ● | ● |  | ● |  |  |  |  |
| `cases.submit` | ● |  |  |  |  |  |  | ● |
| `cases.edit` | ● | ● | ● | ● |  |  |  |  |
| `cases.delete` | ● | ● |  |  |  |  |  |  |
| `cases.accept` | ● | ● |  | ● |  |  |  |  |
| `cases.assign` | ● | ● | ● |  |  |  |  |  |
| `cases.update_status` | ● | ● | ● |  | ● |  |  |  |
| `cases.cancel` | ● | ● |  |  |  |  |  |  |
| `cases.confirm_receipt` | ● |  |  |  |  |  |  | ● |
| `files.view` | ● | ● | ● | ● | ● | ● |  | ● |
| `files.upload` | ● | ● | ● | ● | ● | ● |  | ● |
| `files.delete` | ● | ● |  |  |  |  |  |  |
| `patients.view` | ● | ● | ● | ● |  |  |  |  |
| `patients.create` | ● | ● |  | ● |  |  |  |  |
| `patients.edit` | ● | ● |  | ● |  |  |  |  |
| `patients.delete` | ● | ● |  |  |  |  |  |  |
| `doctors.view` | ● | ● | ● | ● |  |  |  |  |
| `doctors.create` | ● | ● |  | ● |  |  |  |  |
| `doctors.edit` | ● | ● |  | ● |  |  |  |  |
| `doctors.delete` | ● | ● |  |  |  |  |  |  |
| `clinics.view` | ● | ● | ● | ● |  |  | ● |  |
| `clinics.create` | ● | ● |  | ● |  |  |  |  |
| `clinics.edit` | ● | ● |  | ● |  |  |  |  |
| `clinics.delete` | ● | ● |  |  |  |  |  |  |
| `technicians.view` | ● | ● | ● | ● |  | ● |  |  |
| `technicians.create` | ● | ● | ● |  |  |  |  |  |
| `technicians.edit` | ● | ● | ● |  |  |  |  |  |
| `technicians.delete` | ● | ● |  |  |  |  |  |  |
| `production.view` | ● | ● | ● |  | ● |  |  |  |
| `qc.view` | ● | ● | ● |  |  | ● |  |  |
| `qc.perform` | ● | ● | ● |  |  | ● |  |  |
| `delivery.view` | ● | ● | ● | ● |  |  | ● |  |
| `delivery.manage` | ● | ● |  | ● |  |  | ● |  |
| `invoices.view` | ● | ● |  | ● |  |  |  | ● |
| `payments.view` | ● | ● |  | ● |  |  |  |  |
| `payments.record` | ● | ● |  | ● |  |  |  |  |
| `reports.view` | ● | ● | ● |  |  |  |  |  |
| `reports.financial` | ● | ● |  |  |  |  |  |  |
| `users.view` | ● | ● |  |  |  |  |  |  |
| `users.manage` | ● | ● |  |  |  |  |  |  |
| `roles.manage` | ● |  |  |  |  |  |  |  |
| `services.manage` | ● | ● |  |  |  |  |  |  |
| `settings.view` | ● | ● |  |  |  |  |  |  |
| `settings.manage` | ● | ● |  |  |  |  |  |  |
| `audit.view` | ● | ● |  |  |  |  |  |  |

- **The backend enforces everything.** Each route declares its permission (`apps/api/src/routes/index.ts`); services add **row-level scope** (technicians see only cases assigned to them, clinic users only their clinic's cases, invoices and directory records — anything else answers 404). The UI uses the same keys only to hide what the API would refuse.
- Workflow steps additionally require the right person: production steps only the assigned technician (or someone who can assign), client steps only the owning clinic — one function, `canPerformAction()`, shared by UI and API.
- **Roles & Permissions** (Super Admin) edits the matrix live; Super Admin is locked to the full catalogue.

## 7. The 48-hour workflow

```
Client portal:  Submitted ⇄ Correction requested  (→ Rejected)
Lab:            Received → Review → Assigned → In production → Quality control → Ready → Out for delivery → Delivered → Completed
                                                   ↑── Rework required ←── QC fail
Any open stage → Cancelled
```

- **The server starts the clock:** `received_at` is the server time when reception registers a case or accepts a portal submission; `due_at = received_at + SLA hours` (48 by default, set in Settings). `CaseSlaService` classifies cases as on time, at risk, overdue, completed on time or completed late — from stored timestamps at server time, never the browser clock.
- **Every transition is validated on the backend:** a step that is not valid from the current status is `409 Invalid workflow transition.`; the status update is conditional on the status that was validated, so two people acting at once cannot both succeed (the second gets 409, and the UI reloads the case). Input rules (QC issues and notes, courier, receiver, payment) come from the shared `validateActionInput()`; nothing is written when any rule fails.
- Each step writes, in one transaction: the status and its timestamp, `case_status_history` (from, to, user, role, note), the assignment log, QC checks with their issues, delivery records (who delivered, when, to whom, received by, notes), the invoice on receipt, an optional deposit, the activity log and the notifications.
- **Deadline worker** (`npm run worker`, or `npm run start:worker` in production): every `WORKER_INTERVAL_SECONDS` it finds cases entering the at-risk window or passing their deadline and notifies the technician, lab managers and admins (plus reception when overdue). Each alert is claimed with a conditional update, so it is sent **exactly once per case** even with several workers running. `npm run start:worker:once` (development: `npm run worker:once`) runs a single pass and exits, for cron-style scheduling.

## 8. REST API

Base path `/api`. JSON in, JSON out, camelCase. Lists are paginated: `?page=1&perPage=20` → `{ data, meta: { page, perPage, total, lastPage } }`; sort with `?sort=<key>&dir=asc|desc`; array filters repeat the key (`?status=received&status=assigned`); dates are `YYYY-MM-DD` in the lab's time zone.

| Area | Endpoints |
| --- | --- |
| Health | `GET /health` · `GET /health/ready` (checks the database) |
| Auth | `POST /auth/login` · `GET /auth/me` · `POST /auth/logout` · `POST /auth/refresh` · `POST /auth/forgot-password` · `POST /auth/reset-password` |
| Cases | `GET /cases` (search, status[], priority[], technicianId, doctorId, clinicId, patientId, caseType, paymentStatus, sla, from, to, dueFrom, dueTo, openOnly, sort, dir, page, perPage) · `GET /cases/counts` · `POST /cases` · `GET /cases/:id` · `PATCH /cases/:id` · `DELETE /cases/:id` |
| Workflow | `POST /cases/:id/status` `{ status, note?, payment? }` · `POST /cases/:id/assign` `{ technicianId, note? }` · `POST /cases/:id/qc` `{ result: pass\|fail, issues, notes }` · `POST /cases/:id/rework` `{ note? }` · `POST /cases/:id/delivery` `{ status: out_for_delivery\|delivered, method, courierName?, deliveredTo?, receivedBy?, notes? }` |
| Case notes & files | `POST /cases/:id/notes` · `POST /cases/:id/attachments` (multipart `file`, `category`) · `GET /cases/:id/attachments/:attachmentId/download` · `DELETE /cases/:id/attachments/:attachmentId` |
| Directory | `GET/POST /patients`, `GET/PUT/DELETE /patients/:id` — same for `/doctors`, `/clinics`, `/technicians` |
| Finance | `GET /invoices` · `POST /invoices` `{ caseId }` · `GET /invoices/:id` · `GET /payments` · `POST /payments` `{ invoiceId, amount, method, reference?, notes?, paidAt? }` |
| Lab history | `GET /quality-control` · `GET /deliveries` |
| Dashboard & reports | `GET /dashboard?period=today\|7d\|30d\|month` · `GET /reports/cases` · `/reports/production` · `/reports/technicians` · `/reports/clinics` · `/reports/financial` (all take from, to, technicianId, doctorId, clinicId, status, caseType) |
| Search | `GET /search?q=` — cases, patients, doctors, clinics (phone numbers match on digits), invoices, within the caller's scope |
| Notifications | `GET /notifications?unreadOnly=` · `POST /notifications/:id/read` · `POST /notifications/read-all` |
| Administration | `GET/POST /users`, `PUT/DELETE /users/:id`, `PATCH /users/:id/status` · `GET /roles`, `PUT /roles/:key`, `GET /permissions` · `GET/POST /services`, `PUT/DELETE /services/:id` · `GET/PUT /settings` · `GET /activity` |

**Errors** always have the same shape:

| Status | Body |
| --- | --- |
| 401 | `{ "message": "Please sign in to continue." }` (or "Your session has expired…") |
| 403 | `{ "message": "Access restricted." }` |
| 404 | `{ "message": "Resource not found." }` |
| 409 | `{ "message": "Invalid workflow transition.", "currentStatus": "review", "errors": { "status": ["The case is currently \"In review\"."] } }` |
| 422 | `{ "message": "Some fields need attention.", "errors": { "field": ["…"] } }` (business rules without a field: `errors: {}`) |
| 429 | `{ "message": "Too many attempts. Wait a minute and try again." }` |

**Payments:** `remaining = total − paid`; status is `unpaid`, `partial`, `paid` or `overdue` (money owed after the due date), computed in one place. Every payment goes through one ledger function that locks the invoice row, validates the amount against the current balance (shared `validatePaymentAmount`, reference required for non-cash) and updates the paid total in the same transaction — two cashiers can never over-collect.

### Web app ↔ API

Components never call `fetch`: **page → TanStack Query hook → service (`apps/web/src/services`) → API client → transport**. With `VITE_USE_MOCKS=false` (the default) the transport sends credentials-included requests to `VITE_API_URL`; workflow actions are mapped to their REST sub-resource and 422 keys are mapped back to the form fields. With `VITE_USE_MOCKS=true` the same requests are answered by `src/mocks` (same contract, same shared rules) — useful for UI work without a database. API-mode production builds contain no mock code or demo credentials.

## 9. Testing

| Command | Suite | Tests |
| --- | --- | --- |
| `npm test -w @48hrs/shared` | dates in any time zone, the REST request contract, analytics, schemas, deadline alerts | 15 |
| `npm test -w @48hrs/web` | UI (login, guards, tooth chart, forms, tables, breadcrumbs), transport, and the mock backend end to end | 94 |
| `npm test -w @48hrs/api` | **Vitest + Supertest on a real PostgreSQL database** (`TEST_DATABASE_URL`, migrated and re-seeded per suite) | 77 |
| `npm run test:e2e` | **Playwright: built web app + API + PostgreSQL** (`E2E_DATABASE_URL`) | 6 |

API tests: authentication (login, lockout, disabled accounts, CSRF, refresh rotation and replay, access-token expiry, absolute session end, logout revocation, disabling a user, live permission changes, password reset), every workflow action × every role (allowed ⇔ shared rules), the complete flow with QC fail → rework → pass and persisted history, reassignment log, 409 on invalid and on concurrent transitions, 422 input rules with nothing written, the SLA clock (server time; on track → at risk → overdue; delivered late), case CRUD, pricing, validation, every list filter, sorting, pagination and row scope, invoices and payments (unpaid → partial → paid, overdue, invalid payments, a concurrent over-payment race, scope), attachments (bytes round-trip, content sniffing, ownership, scope), directory rules, QC/delivery history, notifications and the worker (alerts once per case, even with two workers at once), dashboard and report figures against the database, financial gating, search, users/roles/services/settings/activity. Status codes asserted throughout: 401, 403, 404, 409, 422, 429.

End-to-end: login → dashboard → create case → assign → production → QC fail → rework → QC pass → ready → invalid then valid payments → dispatch → delivery → completed (history and delivery records checked in the database, no console errors); deadline exceeded → overdue from the server clock and the worker; a device clock three days fast changes nothing; a technician blocked in the UI and by the API; a stale page gets 409 and shows the real state; the access token silently refreshed, then the session ending with sign-in and return.

Browser audits (Playwright + axe-core, WCAG 2 A/AA), run against the real API: every page for all 8 roles (109 visits, 0 errors, 0 axe violations), 387 permission checks on sidebar, pages, buttons and direct URLs, 57 form checks, 20 keyboard checks, and the full journeys — all passing. The crawl and the full journey also pass in mock mode.

## 10. Deployment

1. **Provision** PostgreSQL (backups enabled), a Node.js 22 runtime and TLS in front of the API (nginx, Caddy or a load balancer).
2. **Configure** the environment from `deploy/production.env.example` (staging: `deploy/staging.env.example`) in the platform's secret store. Generate a unique `JWT_SECRET` per environment.
3. **Build** (CI or the server): `npm ci && npm run build`.
4. **Migrate:** `npm run db:deploy`. **First deployment only:** `npm run db:seed` with `SEED_MODE=base` and `SEED_ADMIN_*` set, then remove `SEED_ADMIN_PASSWORD` from the environment and change that password after the first sign-in.
5. **Run two processes** under a supervisor (systemd, PM2, the platform's process manager), restarting on failure:
   - `npm start` — the API (`node apps/api/dist/server.js`), with `WEB_DIST_DIR=../web/dist` to serve the SPA from the same origin, or host `apps/web/dist` on a CDN/web server and route `/api` to the API;
   - `npm run start:worker` — the deadline worker (one or more; alerts are never duplicated).
6. Behind a proxy set `TRUST_PROXY` to the number of proxies and keep `COOKIE_SECURE=true`. Health checks: `GET /api/health` (liveness) and `GET /api/health/ready` (database).
7. Back up the database and `UPLOAD_DIR` together.

Example systemd unit for the API (the worker is identical with `start:worker`):

```ini
[Service]
WorkingDirectory=/srv/48hrs-dental-lab
EnvironmentFile=/etc/48hrs/api.env
ExecStart=/usr/bin/npm start
Restart=always
User=lab
```

## 11. Known limitations

- Rate-limit counters are per API process; with several API instances put a shared store (e.g. Redis) behind `express-rate-limit`. Account lockout is already database-backed.
- Case files are stored on the API host's disk (`UPLOAD_DIR`); multiple API hosts need shared storage or an object store (`apps/api/src/lib/storage.ts` is the single place to change).
- E-mail is sent only for password resets; other notifications are in-app.
- Directory lists (doctors, clinics, technicians, users) are sorted in memory — right for a lab's tens-to-hundreds of records; cases, patients, invoices and payments are filtered, sorted and paginated in PostgreSQL.

## 12. Design notes

Tokens come from the prototype's design-system files (navy `#0a1424`–`#17498a`, gold accent `#d9b53f`, neutral surfaces, 10 px radius, Plus Jakarta Sans + Playfair Display wordmark). Status colours follow the prototype; green, amber and tertiary-text tokens are one step darker so every label meets 4.5:1 contrast. Chart colours (navy + deep gold) were validated for colour-vision deficiency. Accessibility: labelled fields with `aria-describedby` errors, focus rings, focus-trapped dialogs with focus return, keyboard-operable tooth chart, comboboxes and segmented controls, skip link, `prefers-reduced-motion` respected. The universal-numbering reference image from the ZIP is available from the tooth chart ("Numbering guide").
