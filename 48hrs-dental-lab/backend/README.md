# 48HRS Dental Lab — API (Laravel + MySQL)

The REST API behind the React app in `apps/web`. It replaced the former Node.js/Prisma/PostgreSQL API with the same routes, JSON shapes, status codes, business rules and permissions.
- Laravel 13 on PHP 8.3+, with a MySQL 8 database.
- It runs on Hostinger shared hosting: PHP, MySQL and cron. No Node.js, no daemons.

- **Code layout** (`app/`):

  | Part | Contents |
  | --- | --- |
  | `Domain/` | Pure business rules: workflow, SLA, billing, analytics, permissions, notification texts, dates. They are ports of `packages/shared` and parity-tested against it. |
  | `Services/` | Use cases on Eloquent, with transactions and row locks. |
  | `Http/Requests` | Validation, with the web app's exact messages. |
  | `Http/Middleware` | Session, CSRF, permissions and security headers. |
  | `Policies` | Case and attachment authorization. |
  | `Jobs`, `Notifications` | Queue and alerts. |

- **Database:** `database/migrations` (7 migrations, 31 tables, InnoDB, utf8mb4); `database/seeders` (base and demo).
- **Contract checks:**
  - `routes/api-manifest.json` lists every route. The web app's contract test checks each of its calls against it; `php artisan lab:route-manifest` regenerates it, and a PHP test fails when it is stale.
  - `tests/Fixtures/shared-rules.json` and `database/data/demo.json` are generated from `packages/shared` (`npm run contract:export`). The PHP rules must produce identical results.

## Commands

```bash
composer install
cp .env.example .env && php artisan key:generate      # then set DB_* and SEED_USER_PASSWORD
php artisan migrate                                    # every table, from an empty database
SEED_MODE=demo php artisan db:seed                     # the demo lab (development)
php artisan serve                                      # http://127.0.0.1:8000/api
php artisan queue:work                                 # mail and background jobs
php artisan schedule:work                              # the scheduler, every minute
php vendor/bin/phpunit                                 # Unit + Feature + Http suites (MySQL: dental_lab_test)
php artisan lab:check-config                           # production configuration check
php artisan lab:scan-deadlines                         # raise at-risk / overdue alerts now
php artisan lab:purge-expired                          # remove sessions that ended over a week ago
php artisan lab:route-manifest                         # rewrite routes/api-manifest.json
```

## Conventions

- **Base path:** `/api`. Production: `https://lab.sooryoscan.com/api` (the subdomain's document root is the folder `public_html/48hrs_lab`, which never appears in a URL).
- **Format:** JSON in, JSON out, camelCase keys.
- **Lists:**
  - pagination: `?page=1&perPage=20` (max 200) returns `{ data, meta: { page, perPage, total, lastPage } }`;
  - sorting: `?sort=<key>&dir=asc|desc`;
  - array filters repeat the key (`?status=received&status=assigned`) or use a comma list;
  - dates are `YYYY-MM-DD` in the lab's time zone (`LAB_TIMEZONE`).
- **Errors:** always `{ message, errors? }`, where `errors` maps each field to a list of messages.

  | Status | Meaning |
  | --- | --- |
  | 400 | Malformed JSON. |
  | 401 | No session, or it ended (`Your session has expired…`). |
  | 403 | `Access restricted.` (missing permission). |
  | 404 | `Resource not found.`; also any record outside the caller's scope. |
  | 409 | `Invalid workflow transition.` with `currentStatus` and `errors.status`. |
  | 413 | The request is too large. |
  | 419 | CSRF token missing, expired or wrong. |
  | 422 | `Some fields need attention.` with `errors`. |
  | 429 | Too many attempts; carries `Retry-After`. |
  | 500 | `Unexpected server error.` Details are only logged, never sent. |

- **Every response** carries:
  - `X-Server-Time` (ms);
  - `Cache-Control: no-store, private`;
  - `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`;
  - a `default-src 'none'` CSP;
  - HSTS when served over HTTPS.

## Endpoints

"Session" means a signed-in session is required. Permissions: `a, b` means all of them; `any: a, b` means at least one. Row scope applies throughout: technicians see only the cases assigned to them, clinic users only their clinic.

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/health` | public | Liveness |
| GET | `/health/ready` | public | Also checks the database |
| GET | `/auth/csrf` | public | 204; sets the `XSRF-TOKEN` cookie |
| POST | `/auth/login` | public | `{email, password}` → `{expiresAt, user, permissions}`; sets the session cookie; throttled per IP; the account locks after `LOGIN_MAX_ATTEMPTS` failures |
| POST | `/auth/refresh` | cookie | Returns the session while it is alive (the absolute end never moves); 401 otherwise |
| POST | `/auth/logout` | cookie | 204; destroys the session |
| POST | `/auth/forgot-password` | public | Same answer whether the account exists or not; the mail is queued; throttled |
| POST | `/auth/reset-password` | public | `{token, email, password, passwordConfirmation}`; ends every session of that user |
| GET | `/auth/me` | session | Current session |
| GET | `/cases` | `cases.view` | Filters: `status`, `priority`, `technicianId`, `doctorId`, `clinicId`, `patientId`, `caseType`, `paymentStatus`, `sla`, `from`/`to`, `dueFrom`/`dueTo`, `openOnly`, `search` |
| GET | `/cases/counts` | session | Navigation counters + unread notifications |
| POST | `/cases` | any: `cases.create`, `cases.submit` | Reception intake starts the 48-hour clock; clinic users submit for their own clinic (no clock) |
| GET | `/cases/{id}` | `cases.view` | By id or case number (`DL-2026-00123`) |
| PATCH | `/cases/{id}` | `cases.edit` | Open cases only; re-prices the invoice |
| DELETE | `/cases/{id}` | `cases.delete` | Only without payments |
| POST | `/cases/{id}/status` | `cases.view` + the action's permission | `{status, note?, payment?}`: accept (optional deposit), review, production, submit for QC, request correction, resubmit, reject, confirm receipt, cancel |
| POST | `/cases/{id}/assign` | `cases.view` + `cases.assign` | `{technicianId, note?}`; reassignment closes the previous assignment |
| POST | `/cases/{id}/qc` | `cases.view` + `qc.perform` | `{result: pass\|fail, issues, notes}`; fail → rework |
| POST | `/cases/{id}/rework` | `cases.view` + `cases.update_status` | Assigned technician (or a manager) |
| POST | `/cases/{id}/delivery` | `cases.view` + `delivery.manage` | `{status: out_for_delivery\|delivered, method, courierName?, deliveredTo?, receivedBy?, notes?}` |
| POST | `/cases/{id}/notes` | any: `cases.edit`, `cases.update_status`, `qc.perform`, `cases.assign`, `delivery.manage` | |
| POST | `/cases/{id}/attachments` | `files.view`, `files.upload` | multipart `file` + optional `category`; checks extension, size and content signature (STL ASCII/binary, PDF, images, DICOM, Word) |
| GET | `/cases/{id}/attachments/{attachmentId}/download` | `files.view` | Streams from the private disk; `Content-Disposition: attachment` |
| DELETE | `/cases/{id}/attachments/{attachmentId}` | `cases.view` + (`files.delete` or the uploader) | |
| GET | `/patients` | any: `patients.view`, `cases.submit` | `search`, `clinicId`, `sort`… |
| POST | `/patients` | `patients.create` | |
| GET | `/patients/{id}` | `patients.view` | Detail with stats and recent cases |
| PUT | `/patients/{id}` | `patients.edit` | |
| DELETE | `/patients/{id}` | `patients.delete` | Only without cases |
| GET | `/doctors` | any: `doctors.view`, `cases.submit` | |
| POST | `/doctors` | `doctors.create` | |
| GET | `/doctors/{id}` | `doctors.view` | |
| PUT | `/doctors/{id}` | `doctors.edit` | |
| DELETE | `/doctors/{id}` | `doctors.delete` | Only without cases |
| GET | `/clinics` | any: `clinics.view`, `cases.submit` | |
| POST | `/clinics` | `clinics.create` | Unique name |
| GET | `/clinics/{id}` | `clinics.view` | |
| PUT | `/clinics/{id}` | `clinics.edit` | |
| DELETE | `/clinics/{id}` | `clinics.delete` | Only without doctors/cases |
| GET | `/technicians` | any: `technicians.view`, `cases.assign` | Workload columns |
| POST | `/technicians` | `technicians.create` | |
| GET | `/technicians/{id}` | `technicians.view`, or the technician's own profile | |
| PUT | `/technicians/{id}` | `technicians.edit` | Cannot deactivate with active cases |
| DELETE | `/technicians/{id}` | `technicians.delete` | Only without cases |
| GET | `/invoices` | `invoices.view` | `status`, `clinicId`, `doctorId`, `from`/`to`, `search` |
| POST | `/invoices` | `payments.record` | `{caseId}`: issues a missing invoice once |
| GET | `/invoices/{id}` | `invoices.view` | With payments and line items; by id or number |
| GET | `/payments` | `payments.view` | `method`, `clinicId`, `from`/`to`, `search` |
| POST | `/payments` | `payments.record` | `{invoiceId, amount, method, reference?, notes?, paidAt?}`. Amount must be > 0 and ≤ the remaining balance; the reference is required for non-cash payments and unique across all payments. Duplicate protection is a locking read plus a unique index; the invoice row is locked (`SELECT … FOR UPDATE`). |
| GET | `/quality-control` | `qc.view` | QC history |
| GET | `/deliveries` | `delivery.view` | Delivery history |
| GET | `/dashboard` | `dashboard.view` | `period=today\|7d\|30d\|month`; money figures only with `reports.financial` |
| GET | `/reports/cases` | `reports.view` | `from`, `to` (required) + `technicianId`, `doctorId`, `clinicId`, `status`, `caseType` |
| GET | `/reports/production` | `reports.view` | Average hours per stage |
| GET | `/reports/technicians` | `reports.view` | |
| GET | `/reports/clinics` | `reports.view` | |
| GET | `/reports/financial` | `reports.view`, `reports.financial` | |
| GET | `/search` | session | `q` (min 2 chars): cases, patients, doctors, clinics, phone numbers, invoices, within the caller's scope |
| GET | `/notifications` | session | Own notifications; `unreadOnly`; `unreadCount` |
| POST | `/notifications/{id}/read` | session | Own only (others → 404) |
| POST | `/notifications/read-all` | session | |
| GET | `/users` | `users.view` | |
| POST | `/users` | `users.manage` | Password policy; only a Super Admin grants Super Admin; a technician login gets a linked profile |
| PUT | `/users/{id}` | `users.manage` | Cannot demote oneself |
| PATCH | `/users/{id}/status` | `users.manage` | Cannot disable oneself; disabling ends the user's sessions |
| DELETE | `/users/{id}` | `users.manage` | Only without history |
| GET | `/roles` | any: `users.view`, `roles.manage` | |
| PUT | `/roles/{key}` | `roles.manage` | Super Admin is locked; unknown keys are ignored; applies to live sessions on their next request |
| GET | `/permissions` | session | The catalogue |
| GET | `/services` | session | Price list |
| POST | `/services` | `services.manage` | |
| PUT | `/services/{id}` | `services.manage` | |
| DELETE | `/services/{id}` | `services.manage` | Only when unused |
| GET | `/settings` | session | Lab profile, SLA hours, thresholds, fees |
| PUT | `/settings` | `settings.manage` | |
| GET | `/activity` | `audit.view` | Audit log: `subjectType`, `search` |

## Authentication

- **Sessions:** Laravel's session guard, with the database session driver, puts the session id in an HTTP-only, `SameSite=Lax` cookie. It is `Secure` in production.
  - It is host-only (no `SESSION_DOMAIN`) with path `SESSION_PATH` (`/` in production), so `sooryoscan.com` and its other subdomains never receive it.
  - JavaScript never sees a session token.
- **Absolute end:** each sign-in ends `SESSION_TTL_MINUTES` after it started (8 h), whatever the activity. `Authenticate` checks it on every request.
  - Logout, a password reset or disabling the user deletes the session rows.
  - Permission changes apply on the next request.
- **CSRF:** `ValidateCsrfToken` runs on every API request. Laravel sets a readable `XSRF-TOKEN` cookie, and the web app echoes it in `X-XSRF-TOKEN` on writes. A missing or stale token gets 419; the app then fetches a new one from `/auth/csrf` and retries once.
  - A cross-site `Origin` is refused outright (`OriginGuard`).
- **Passwords:** bcrypt (`BCRYPT_ROUNDS`).
  - Policy: at least 8 characters, with letters and numbers.
  - Lockout: after `LOGIN_MAX_ATTEMPTS` wrong passwords, the account is locked for `LOGIN_LOCK_MINUTES`. This is stored on the user row.
- **Rate limits** (per IP):

  | Limit | Scope |
  | --- | --- |
  | `AUTH_RATE_LIMIT` | Sign-ins per 15 min |
  | `PASSWORD_RESET_RATE_LIMIT` | Reset requests per hour |
  | `API_RATE_LIMIT` | All API calls per minute |

  Behind a proxy or CDN, set `TRUSTED_PROXIES` so the real client IP is used.
- **Password reset:** Laravel's password broker.
  - The token is stored hashed, expires after 60 minutes and can be used once.
  - The mail is queued (`ResetPasswordNotification`).
  - The link points to `FRONTEND_URL/reset-password`.

## Authorization

- Every permission key is a gate (`Gate::before` → `User::hasPermission`), and roles are editable in the database.
  - The `permission:` middleware guards each route.
  - `DentalCasePolicy` (view / perform the workflow step) and `CaseAttachmentPolicy` (delete) add the per-record rules.
  - `App\Support\Scope` restricts every query to the caller's rows.
- Workflow steps are checked by `Workflow::canPerformAction`, in this order:
  1. not found → 404;
  2. step not valid from the current status → 409;
  3. not allowed for this user → 403;
  4. invalid input → 422.

  The status update is conditional on the status that was validated, inside a transaction. So when two people act at once, one wins and the other gets 409.

## Queue and scheduler

`QUEUE_CONNECTION=database` stores jobs in `jobs`; failures land in `failed_jobs` (`php artisan queue:failed`, `queue:retry`). One cron entry runs `php artisan schedule:run` every minute (`routes/console.php`):

| Task | When | What |
| --- | --- | --- |
| `ScanDeadlines` job | every minute | At-risk and overdue alerts. Each is claimed with a conditional update, so it is sent **once per case** however many scans run; the job is also `ShouldBeUnique`. |
| `queue:work --stop-when-empty --max-time=50` | every minute (no overlap) | Drains the queue: reset e-mails, the deadline scan. There is no resident worker on shared hosting. |
| `lab:purge-expired` | hourly | Sessions that ended over a week ago |
| `auth:clear-resets` | hourly | Expired reset tokens |
| `queue:prune-failed --hours=720` | daily | Failed jobs older than 30 days |

## Files

Case files are stored on the private `cases` disk (`UPLOAD_DIR`, default `storage/app/private/cases`), outside any web root.
- Keys are random (`YYYY/MM/<random>.<ext>`); user file names never reach the filesystem.
- Files are served only through the authorised download endpoint, with `Content-Disposition: attachment`, `nosniff` and `no-store`.
- Checks: an allow-list of extensions, `MAX_UPLOAD_MB`, and the content signature. Executables and HTML are refused whatever their name, and `.stl` must be ASCII (`solid …`) or a well-formed binary STL.
- The client's declared MIME type is ignored.
