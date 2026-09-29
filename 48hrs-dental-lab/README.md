# 48HRS Dental Lab Management System

**48HRS Dental Lab** is the operations system for a dental lab, built around a live **48-hour commitment** on every case. It covers case intake, production, quality control, delivery, invoicing and reporting. The design comes from the `48hrs lab.zip` prototype: design tokens, screenshots, logo and the tooth-numbering chart.

```
React 19 SPA (apps/web)                 Vite · Tailwind 4 · React Router 7 · TanStack Query/Table · React Hook Form · Zod
        │  REST + JSON · HTTP-only session cookie · XSRF token
        ▼
Laravel 13 API (backend/)               Eloquent · form requests · session guard · gates & policies · queue · scheduler
        │  controllers → services (transactions, row locks) → Eloquent
        ▼
MySQL 8                                  31 tables · foreign keys · unique indexes · InnoDB · utf8mb4

Shared domain (packages/shared)          types · permissions · workflow · SLA · billing · analytics · Zod schemas
```

Hosting target: **Hostinger shared hosting**, which provides PHP, MySQL, git and cron, but no Node.js and no daemons. Production: **https://lab.sooryoscan.com** (the app at `/`, the API at `/api`).
- The React build is uploaded as static files.
- Laravel runs from a folder outside `public_html`.
- One cron entry drives the scheduler and the queue.

The browser app and the optional in-browser mock backend share the TypeScript rules in `packages/shared`. The Laravel API implements the same rules in PHP (`backend/app/Domain`). Parity tests run the PHP rules against fixtures generated from the TypeScript ones, so the three can't silently disagree. **MySQL behind Laravel is the source of truth.**

---

## 1. Local development

Prerequisites:
- **PHP 8.3+** with `pdo_mysql`, `mbstring`, `xml`, `curl`, `intl`, `bcmath` and `zip`;
- **Composer 2**;
- **MySQL 8**;
- **Node.js 22+**, for the React app and the web tests only.

```bash
cd 48hrs-dental-lab
npm install                                   # web + shared workspaces

# MySQL: a user and three databases (development, PHP tests, end-to-end tests)
mysql -uroot -e "CREATE USER 'lab'@'localhost' IDENTIFIED BY '…'; CREATE USER 'lab'@'127.0.0.1' IDENTIFIED BY '…';
  CREATE DATABASE dental_lab; CREATE DATABASE dental_lab_test; CREATE DATABASE dental_lab_e2e;
  GRANT ALL ON dental_lab.* TO 'lab'@'localhost', 'lab'@'127.0.0.1';
  GRANT ALL ON dental_lab_test.* TO 'lab'@'localhost', 'lab'@'127.0.0.1';
  GRANT ALL ON dental_lab_e2e.* TO 'lab'@'localhost', 'lab'@'127.0.0.1';"

cd backend
composer install
cp .env.example .env                          # set DB_PASSWORD and SEED_USER_PASSWORD (replace every CHANGE_ME)
php artisan key:generate
php artisan migrate                           # every table, from an empty database
SEED_MODE=demo php artisan db:seed            # the demo lab; every demo account gets SEED_USER_PASSWORD
cd ..

cp apps/web/.env.example apps/web/.env        # public front-end settings (no secrets)
npm run dev                                   # Laravel :8000 + queue worker + scheduler + web :5173
```

Open http://localhost:5173 and sign in with a demo account below, using your `SEED_USER_PASSWORD`. The Vite dev server proxies `/api` to `php artisan serve`, so everything stays on one origin.

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

Demo accounts exist only after an explicit `SEED_MODE=demo` seed. The default (`base`) seed never creates them, and production refuses demo data unless `ALLOW_DEMO_SEED=true`.

### Scripts (repository root)

| Script | What it does |
| --- | --- |
| `npm run dev` | `php artisan serve` + `queue:work` + `schedule:work` + the Vite dev server |
| `npm run build` | Production web build (`apps/web/dist`) |
| `npm run release` | Hostinger release for https://lab.sooryoscan.com: the web build (served at `/`, API `/api`) with `.htaccess` and `laravel.php` for the document root `public_html/48hrs_lab`, and Laravel with a `--no-dev` vendor for `48hrs_lab_app`, packed as `release/48hrs-lab-release.tar.gz` (§8) |
| `npm run typecheck` · `npm run lint` | TypeScript in every workspace · ESLint |
| `npm test` | shared + web unit tests (Vitest) |
| `npm run test:api` | Laravel tests (PHPUnit on MySQL) |
| `npm run test:e2e` | Playwright, running browser → Apache (Hostinger layout) → Laravel → MySQL |
| `npm run contract:export` | Regenerates `backend/database/data/demo.json` and `backend/tests/Fixtures/shared-rules.json` from `packages/shared` |

## 2. Project structure

```
apps/web/                 React app (pages, components, services, mock backend, Vitest, Playwright e2e)
  src/services/api/       HTTP transport: credentials, XSRF header, 419 retry, server clock
  e2e/                    full-stack Playwright suite; serve-apache.mjs builds the Hostinger layout
backend/                  Laravel API — see backend/README.md (every endpoint)
  app/Domain/             business rules (PHP ports of packages/shared)
  app/Services/           use cases: workflow, payments, files, analytics, notifications…
  database/migrations/    MySQL schema · database/seeders/ base + demo seeds
  routes/api.php          the REST API · routes/console.php commands + schedule
  tests/Unit|Feature|Http parity, API and real-server tests
packages/shared/          TypeScript domain shared by the web app and the mock backend
deploy/hostinger/         document-root templates (public_html/48hrs_lab): .htaccess, .user.ini, laravel.php
deploy/production.env.example   Laravel .env template for Hostinger
scripts/                  dev.mjs (local stack) · build-release.mjs (Hostinger release)
```

## 3. Environment variables

The Laravel settings are in `backend/.env`; templates are `backend/.env.example` (development) and `deploy/production.env.example` (Hostinger). **Never commit `.env`.** In production the app refuses to boot or run commands while any of these holds:
- a `CHANGE_ME` placeholder;
- `APP_DEBUG=true`;
- an `http://` or localhost `APP_URL`/`FRONTEND_URL`;
- non-Secure cookies;
- the log mailer or a sync queue;
- an empty `DB_PASSWORD`;
- an unknown time zone.

`php artisan lab:check-config` lists every problem. Only the setup commands (`key:generate`, `lab:check-config`, `config:clear`…) run while the configuration is invalid.

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | — | Laravel basics (`APP_KEY` via `php artisan key:generate`) |
| `FRONTEND_URL` | `APP_URL` | The React app's address (password-reset links) |
| `LAB_TIMEZONE` | `UTC` | The lab's working day: due today, reports, dashboard periods, case-number year |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | — | MySQL 8 |
| `SESSION_DRIVER` | `database` | Revocable sessions (required in production) |
| `SESSION_TTL_MINUTES` | 480 | Absolute end of a sign-in (activity never extends it) |
| `SESSION_LIFETIME` | = TTL | Idle limit |
| `SESSION_PATH` | `/` | Cookie path: `/` (production is the root of lab.sooryoscan.com). Must match `APP_URL` |
| `SESSION_SECURE_COOKIE` | — | `true` in production (HTTPS) |
| `SESSION_SAME_SITE` | `lax` | |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES` | 5, 15 | Per-account lockout |
| `AUTH_RATE_LIMIT`, `PASSWORD_RESET_RATE_LIMIT`, `API_RATE_LIMIT` | 100 / 15 min, 10 / h, 600 / min | Per-IP limits |
| `TRUSTED_PROXIES` | — | Proxies whose `X-Forwarded-*` headers count (`*` behind a CDN) |
| `CORS_ALLOWED_ORIGINS` | — | Extra browser origins allowed to call the API with cookies |
| `UPLOAD_DIR`, `MAX_UPLOAD_MB` | `storage/app/private/cases`, 50 | Private case files |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `database` | Queue and cache in MySQL |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` | SMTP for password-reset mail |
| `SEED_MODE`, `SEED_USER_PASSWORD`, `SEED_ADMIN_EMAIL`, `SEED_ADMIN_NAME`, `SEED_ADMIN_PASSWORD`, `ALLOW_DEMO_SEED` | `base` | Seeding (§4). Pass them on the command line, not in `.env` |

The front-end settings (`apps/web/.env`) are public and baked in at build time:
- `VITE_API_URL`: `/api` (production too).
- `VITE_BASE_PATH`: `/` (production too). Only a deployment under a URL sub-path would change it.
- `VITE_USE_MOCKS`: `true` runs the in-browser mock backend instead of the API.

## 4. Database

- **Schema:** `backend/database/migrations` builds all 31 tables from an empty database. Details:
  - lower-case ULID string keys;
  - `DATETIME(3)` in UTC;
  - enums in workflow order;
  - foreign keys with explicit `ON DELETE` rules;
  - unique indexes on case, invoice and patient numbers, e-mails, storage keys and **payment references**;
  - indexes for every list filter.
- **Rollback:** `php artisan migrate:reset` removes everything.
- **Seeding:** `php artisan db:seed` with `SEED_MODE`:
  - `base` (the default) creates the permission catalogue, the default roles, lab settings, number sequences and the first Super Admin from `SEED_ADMIN_EMAIL` / `SEED_ADMIN_NAME` / `SEED_ADMIN_PASSWORD`. It is idempotent, and it refuses weak or placeholder passwords. The price list (Services) is managed in the app afterwards, as with the Node version.
  - `demo` replaces **all** data with the demo lab: the same dataset the mock backend uses, with sample files written to `UPLOAD_DIR`.

## 5. Authentication and security

- **Sessions:** Laravel's session guard with the database driver. The session id travels in an HTTP-only, SameSite=Lax cookie that is Secure in production and scoped to `SESSION_PATH`; no token is readable from JavaScript.
  - Each sign-in ends `SESSION_TTL_MINUTES` after it started (absolute).
  - Logout, a password reset or disabling the user deletes the session rows.
  - Role and permission changes apply on the next request.
  - The UI signs out at the session end and returns to the same page after signing in again.
- **CSRF:** every state-changing request carries `X-XSRF-TOKEN`, echoed from Laravel's `XSRF-TOKEN` cookie. Without it the API answers 419; the web app fetches a new token (`GET /api/auth/csrf`) and retries once. A cross-site `Origin` is refused.
- **Passwords:** bcrypt, with a policy of at least 8 characters, letters and numbers. After repeated wrong passwords the account is locked, and the lock is stored in the database.
- **Rate limits:** per IP on sign-in, password reset and the whole API. Every 429 carries `Retry-After`.
- **Password reset:** the same answer whether or not the account exists. The token is hashed, single-use and expires after 60 minutes, and the mail is sent through the queue.
- **Clock:** deadlines and session ends use server time. Responses carry `X-Server-Time`, and the web app corrects its countdowns with it, so a wrong device clock can neither fake an SLA state nor end a session early.
- **Protections:**
  - security headers on the API and the SPA (strict CSP, `nosniff`, `DENY` framing, HSTS over HTTPS);
  - `no-store` API responses;
  - validation of every body and query filter, with the web app's messages;
  - SQL only through Eloquent or bound parameters, with LIKE wildcards escaped;
  - errors never expose internals (`APP_DEBUG=false`);
  - logs carry ids and paths, not request bodies or search terms.
- **Uploads:**
  - an allow-list of extensions, `MAX_UPLOAD_MB`, and the content signature (renamed executables or HTML are refused; `.stl` must be a real ASCII or binary STL); the client's MIME type is ignored;
  - files are stored under random keys on a private disk outside any web root;
  - files are served only through the authorised download endpoint, as attachments.

## 6. Roles and permissions

There are eight roles: Super Admin, Admin, Lab Manager, Reception, Technician, Quality Control, Delivery and Client (the clinic portal). They share a granular permission catalogue (`packages/shared/src/permissions.ts`, stored in the `permissions` table). Defaults:

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

- **The backend enforces everything.** Each route declares its permission (`permission:` middleware backed by gates, `backend/routes/api.php`).
  - Policies and `App\Support\Scope` add **row-level scope**: technicians see only cases assigned to them; clinic users see only their clinic's cases, invoices and directory records. Anything else answers 404.
  - The UI uses the same keys only to hide what the API would refuse.
- Workflow steps also require the right person: production steps need the assigned technician (or someone who can assign), and client steps the owning clinic. One rule decides this, `canPerformAction()`, in TypeScript and PHP, and it is parity-tested.
- **Roles & Permissions** (Super Admin) edits the matrix live. Super Admin is locked to the full catalogue.

## 7. The 48-hour workflow

```
Client portal:  Submitted ⇄ Correction requested  (→ Rejected)
Lab:            Received → Review → Assigned → In production → Quality control → Ready → Out for delivery → Delivered → Completed
                                                   ↑── Rework required ←── QC fail
Any open stage → Cancelled
```

- **The server starts the clock.** `received_at` is the server time when reception registers a case or accepts a portal submission. `due_at = received_at + SLA hours` (48 by default, set in Settings). States come from the stored timestamps at server time: on track, at risk, overdue, completed on time or late.
- **Every transition is validated on the server.**
  - A step that is not valid from the current status gets `409 Invalid workflow transition.`, with the current status.
  - The status update is conditional on the validated status, so two people acting at once can't both succeed.
  - The input rules (QC issues and notes, courier, receiver, payment) are the shared `validateActionInput()`. When any rule fails, nothing is written.
- **Each step writes everything in one transaction:**
  - the status and its timestamp;
  - `case_status_history` (from, to, user, role, note);
  - the assignment log;
  - QC checks and their issues;
  - delivery records (who, when, to whom, received by, notes);
  - the invoice on receipt, plus an optional deposit;
  - the activity log and the notifications.
- **Payments:**
  - Partial and final payments are recorded against the invoice, which is locked with `SELECT … FOR UPDATE`, so concurrent payments can never exceed the total.
  - Transaction references are required for non-cash payments and can be recorded only once, enforced by a locking check plus a unique index.
  - `paid`, `remaining` and the invoice status are computed on the server.
- **Deadline alerts** (the scheduler, every minute) notify the technician, lab managers and admins when a case enters the at-risk window or passes its deadline; reception is added when overdue. Each alert is claimed with a conditional update, so it is sent **exactly once per case**.

## 8. Deploying to Hostinger

**Production:** **https://lab.sooryoscan.com**. The app is at `/`, the API at `/api` (for example `https://lab.sooryoscan.com/api/health`).

The subdomain `lab.sooryoscan.com` already exists in hPanel. Its **document root** is the folder `public_html/48hrs_lab`, which is only a location on disk and never appears in a URL. There is no `https://sooryoscan.com/48hrs_lab` and no `/48hrs_lab/api`.

```
/home/u501147781/domains/sooryoscan.com/
├── public_html/
│   └── 48hrs_lab/            ← document root of https://lab.sooryoscan.com
│       ├── index.html, assets/     React build (VITE_BASE_PATH=/, VITE_API_URL=/api)
│       ├── .htaccess               SPA fallback · /api → laravel.php · dotfiles denied · headers
│       ├── .user.ini               upload limits for PHP
│       └── laravel.php             front controller: boots Laravel from ../../48hrs_lab_app
└── 48hrs_lab_app/            ← Laravel (backend/): code, vendor, .env, storage, case files
                                outside public_html: never reachable by URL
```

`https://lab.sooryoscan.com/api/*` is rewritten to `laravel.php`, which runs Laravel exactly as its own `public/index.php` would. The session and `XSRF-TOKEN` cookies are host-only, with path `/`, so `sooryoscan.com` and other subdomains never receive them.

### First deployment

1. **hPanel** (settings in the Hostinger control panel):
   - **Advanced → PHP Configuration:** PHP **8.3** or newer, with the `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `fileinfo` and `zip` extensions.
   - **Databases → MySQL:** create a database and a user (for example `u501147781_dental_lab` / `u501147781_lab`) and note the password.
   - **Emails:** create `no-reply@sooryoscan.com` for the reset mails.
   - **SSL:** make sure `lab.sooryoscan.com` has a certificate and forces HTTPS.
   - **Subdomains:** `lab.sooryoscan.com` → document root `public_html/48hrs_lab` (already set up).
2. **Build the release** on any machine with Node 22 and Composer 2. The server needs neither:
   ```bash
   npm ci && npm run release        # → release/48hrs-lab-release.tar.gz  (app at /, API at /api)
   ```
3. **Upload and unpack** over SSH (hPanel → Advanced → SSH access):
   ```bash
   scp -P 65002 release/48hrs-lab-release.tar.gz u501147781@<server-ip>:~/
   ssh -p 65002 u501147781@<server-ip>
   cd /home/u501147781/domains/sooryoscan.com
   tar -xzf ~/48hrs-lab-release.tar.gz          # → public_html/48hrs_lab/  and  48hrs_lab_app/
   rm ~/48hrs-lab-release.tar.gz
   ```
   If hPanel created a placeholder page in the document root (`public_html/48hrs_lab/default.php` or `index.php`), delete it; the app's `index.html` must be the page served at `/`.
4. **Configure Laravel:**
   ```bash
   cd /home/u501147781/domains/sooryoscan.com/48hrs_lab_app
   nano .env            # paste deploy/production.env.example, then set DB_PASSWORD and MAIL_PASSWORD
   chmod 600 .env
   php artisan key:generate --force
   php artisan lab:check-config                  # must say: Configuration OK for production.
   ```
   The important values:

   | Variable | Value |
   | --- | --- |
   | `APP_URL` | `https://lab.sooryoscan.com` |
   | `FRONTEND_URL` | `https://lab.sooryoscan.com` |
   | `SESSION_PATH` | `/` |
   | `SESSION_DOMAIN` | empty |
   | `SESSION_SECURE_COOKIE` | `true` |

   The configuration check refuses a `SESSION_PATH` that doesn't match `APP_URL`, such as `/48hrs_lab/`.
5. **Create the database and the first Super Admin.** The password is typed, not stored in `.env` or the shell history:
   ```bash
   php artisan migrate --force
   read -rsp 'Admin password: ' SEED_ADMIN_PASSWORD; echo; export SEED_ADMIN_PASSWORD
   SEED_MODE=base SEED_ADMIN_EMAIL=owner@sooryoscan.com SEED_ADMIN_NAME="Lab Owner" php artisan db:seed --force
   unset SEED_ADMIN_PASSWORD
   ```
6. **Optimise and protect:**
   ```bash
   php artisan config:cache && php artisan route:cache && php artisan event:cache
   chmod -R u+rwX storage bootstrap/cache
   ```
7. **Cron** (hPanel → Advanced → Cron Jobs → Custom, every minute). One entry runs everything:
   ```
   * * * * * cd /home/u501147781/domains/sooryoscan.com/48hrs_lab_app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   The scheduler runs:

   | Task | When |
   | --- | --- |
   | Deadline scan (queued job) | every minute |
   | `queue:work --stop-when-empty --max-time=50` | every minute; sends reset mails and runs queued jobs |
   | `lab:purge-expired`, `auth:clear-resets` | hourly |
   | `queue:prune-failed` | daily |

   Use the PHP binary that `which php` shows over SSH, with the same version as the site. If the plan does not allow the scheduler to start sub-processes (`proc_open` disabled), use these two entries instead:
   ```
   * * * * * cd /home/u501147781/domains/sooryoscan.com/48hrs_lab_app && /usr/bin/php artisan lab:scan-deadlines >> /dev/null 2>&1
   * * * * * cd /home/u501147781/domains/sooryoscan.com/48hrs_lab_app && /usr/bin/php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
   ```
8. **Verify:**
   ```bash
   curl -fsS https://lab.sooryoscan.com/api/health         # {"status":"ok"}
   curl -fsS https://lab.sooryoscan.com/api/health/ready   # {"status":"ready"}  (database reachable)
   curl -sI https://lab.sooryoscan.com/.htaccess | head -1 # 403
   php artisan schedule:list && php artisan queue:failed   # the schedule; failed jobs (should be none)
   ```
   Then open **https://lab.sooryoscan.com/** and sign in as the Super Admin. Add the price list (Services), clinics, doctors, technicians and users.

### Updates

1. Build a new release (`npm run release`).
2. `cd /home/u501147781/domains/sooryoscan.com/48hrs_lab_app && php artisan down`.
3. Unpack it in `/home/u501147781/domains/sooryoscan.com` over the two folders. `.env` and `storage/` are not part of the archive, so they are kept.
4. Run `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan up`.

### Backups

Back up the MySQL database (hPanel → Backups, or `mysqldump`) **together** with `48hrs_lab_app/storage/app/private/cases` (the case files).

### Notes

- **Behind a CDN or proxy** (Cloudflare, Hostinger CDN) that sets `X-Forwarded-*`, set `TRUSTED_PROXIES=*`, so rate limits see the real client IP and HTTPS is detected.
- **Other folder names:** `npm run release -- --folder=<docroot folder> --app=<laravel folder>` changes only the disk layout; URLs stay at `/`. Only a site served under a URL sub-path needs `--base=/path/`, with `APP_URL`, `FRONTEND_URL` and `SESSION_PATH` set to match.
- **Tested locally:** this layout was served by Apache 2.4 with mod_php 8.3, with `public_html/48hrs_lab` as the document root, `.htaccess`, `laravel.php` and the app folder beside `public_html`. The full browser suite and a production-mode smoke test ran against it (§9). Hostinger serves `.htaccess` with LiteSpeed, which reads the same directives but was not available for testing.

## 9. Testing

```bash
npm run typecheck && npm run lint          # TypeScript · ESLint
npm test                                   # packages/shared + apps/web (Vitest)
npm run test:api                           # backend: PHPUnit on MySQL (dental_lab_test, wiped)
npm run test:e2e                           # Playwright: browser → Apache (Hostinger layout) → Laravel → MySQL (dental_lab_e2e, wiped)
```

| Suite | What it covers |
| --- | --- |
| `packages/shared` (Vitest) | Dates in any time zone, the REST request contract, analytics, schemas, deadline alerts, and staleness of the generated backend fixtures |
| `apps/web` (Vitest) | UI (login, guards, tooth chart, forms, tables), the mock backend end to end, the HTTP transport (credentials, XSRF token, 419 retry, base path), and the **API contract**: every service call must match a route in `backend/routes/api-manifest.json` |
| `backend/tests/Unit` | **Parity:** the PHP workflow, SLA, billing, analytics, permissions, notification texts and request validation give the same results as the TypeScript rules (fixtures generated from `packages/shared`). Also the production configuration guard and the route manifest. |
| `backend/tests/Feature` | See the breakdown below |
| `backend/tests/Http` | A real `php -S` server with 8 workers and real artisan processes. See the breakdown below. |
| `apps/web/e2e` (Playwright) | See the breakdown below |

**Feature tests** (in-process HTTP on MySQL, fixed clock):
- **Authentication:** cookies, session regeneration, lockout, disabled accounts, the absolute session end, logout, disabling a user, live permission changes, password reset and its expiry, queued reset mail.
- **RBAC:** an RBAC matrix of every role against representative endpoints, and every workflow action against every role.
- **Tampering:** scope-widening query parameters, server-owned body fields, privilege escalation, spoofed payment actor.
- **Workflow:** the full lifecycle, QC fail → rework → pass, 409 and 422 rules, the SLA clock.
- **Payments:** payments, overdue invoices, duplicate references, rollback.
- **Files:** STL/PDF/PNG round trips, spoofed content, oversize files, path traversal, outsiders; storage is never served.
- **Other areas:** directory, search, reports, dashboard, notifications and deadline scans, administration, SQL injection attempts, safe errors.

**Http tests** (real server and artisan processes):
- CSRF (419);
- cookie flags;
- the per-IP sign-in limit, including a spoofed `X-Forwarded-For`;
- **truly parallel requests:** two transitions, two assignments, two QC results, concurrent payments, the same reference twice on one invoice and on two invoices, eight payments racing for one balance, and a test that holds the invoice row lock to prove payments wait for it;
- the scheduler draining the queue;
- concurrent deadline scans;
- a failing job landing in `failed_jobs`.

**Playwright end-to-end** (the production build, served by Apache from the document root `public_html/48hrs_lab` through `.htaccess` and `laravel.php`, at `/`, as on lab.sooryoscan.com):
- **The full journey:** client-portal submission with an STL upload → acceptance with a deposit → assignment → production → QC fail → rework → QC pass → ready → dispatch → delivered → final payment → completed. The file bytes are checked on disk, and the history, QC and ledger in MySQL.
- **Deadlines:** a deadline passes → overdue, via the scheduled scan, with no duplicate alert.
- **Device clock:** 9 h ahead and 9 h behind.
- **Permissions:** blocked in the UI and by the API.
- **Stale page:** a stale page gets 409 and refetches.
- **Tokens:** CSRF token loss → renewed, and a forged request → 419.
- **Session end:** sign-out, then return after signing in.
- **Search:** global search within scope.
- **Root serving:** deep links and reloads at `/`, `/api/health`, headers, and no disk folder name (`48hrs_lab`, `48hrs_lab_app`) in any URL the browser requests.

## 10. Known limitations

- Hostinger's web server is LiteSpeed. The `.htaccess` and `laravel.php` layout was verified on Apache 2.4 with mod_php, but not on LiteSpeed itself.
- MySQL 8 is the tested database. MariaDB (which some Hostinger plans offer) is untested; the schema uses `DATETIME(3)`, JSON columns and `REGEXP_REPLACE`.
- The queue is drained by cron once a minute, so a password-reset mail can take up to about a minute to leave.
- E-mail is sent only for password resets; other notifications are in-app.
- Case files live on the hosting account's disk (`UPLOAD_DIR`): back them up with the database.

## 11. Design notes

- **Colours:** tokens come from the prototype's design-system files: navy `#0a1424`–`#17498a`, gold accent `#d9b53f`, neutral surfaces, 10 px radius, Plus Jakarta Sans + Playfair Display wordmark.
  - Status colours follow the prototype. The green, amber and tertiary-text tokens are one step darker, so every label meets 4.5:1 contrast.
  - Chart colours (navy + deep gold) were validated for colour-vision deficiency.
- **Accessibility:**
  - labelled fields with `aria-describedby` errors;
  - focus rings, and focus-trapped dialogs with focus return;
  - a keyboard-operable tooth chart, comboboxes and segmented controls;
  - a skip link;
  - `prefers-reduced-motion` is respected.
