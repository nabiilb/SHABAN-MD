# Alpha Driving School — Management System

A complete **PHP 8 / Laravel 13 / MySQL** driving-school management system with
three roles (Admin, Instructor, Student), strict backend-enforced data
isolation, and a debt-versus-expense accounting model.

---

## Technology

| Layer | Stack |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13, Eloquent ORM, Form Requests, Policies, Gates, Middleware, DB transactions |
| Database | MySQL 8 / MariaDB 10.4+ (InnoDB, foreign keys, indexes, soft deletes) |
| Frontend | Blade, Tailwind CSS v4, Alpine.js, Chart.js, Vite |
| Reports | Server-side filters, CSV export, PDF export (dompdf), print stylesheet |
| Languages | English and Somali (`lang/so.json`, per-user preference) |

---

## Installation

```bash
git clone <repository-url>
cd driving-school

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create the database and point `.env` at it:

```sql
CREATE DATABASE driving_school CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=driving_school
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

Then:

```bash
php artisan migrate --seed
php artisan storage:link
npm run build          # or: npm run dev
php artisan serve
```

Open <http://localhost:8000>.

---

## Development accounts

Seeded by `database/seeders/DrivingSchoolSeeder.php`. **Change these before
deploying anywhere real.**

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@example.com` | `Admin@12345` |
| Instructor A — Xasan Maxamuud | `xasan@example.com` | `Instructor@12345` |
| Instructor B — Nasteexo Aadan | `nasteexo@example.com` | `Instructor@12345` |
| Instructor C — Cabdi Yuusuf | `cabdi@example.com` | `Instructor@12345` |
| Student — Ilyas Maxamed | `student@example.com` | `Student@12345` |

---

## Roles

### Admin
Full access: dashboard, students, instructors, vehicles, attendance, lessons,
transfers, fuel, expenses, student payments, instructor loans, company debts,
debt payments, suppliers, reports, users and permissions, audit logs, settings.

### Instructor — strictly isolated
A separate **"My Dashboard"**, never the admin one. An instructor reaches only:

* his currently assigned students
* his own attendance, lessons and transfers
* the vehicles assigned to him
* his own loan / credit balance

He can reach **none** of: other instructors' students, attendance, lessons,
vehicles or loans; company income, expenses, profit, debts, supplier balances
or financial reports; fuel records; audit logs; user management; settings.

### Student — read only
Profile, current instructor, training progress, attendance history and lesson
history. Own records only.

---

## How the isolation is enforced

Isolation is **never** a frontend concern. Four layers back each other up:

1. **Route middleware** — `role:admin`, `role:instructor`, `role:student`
   separate the three route groups; `instructor.profile` guarantees a live
   instructor row behind the session.
2. **Query scopes** — every listing goes through `visibleTo($user)`
   (`app/Models/Student.php`, `Attendance`, `Lesson`, `Vehicle`,
   `StudentTransfer`, `InstructorLoan`), which derives the instructor id from
   the authenticated user:

   ```php
   Student::query()->visibleTo($request->user());
   // instructor → where('current_instructor_id', $user->instructorId())
   ```
3. **Policies** — `StudentPolicy`, `AttendancePolicy`, `LessonPolicy`,
   `VehiclePolicy`, `StudentTransferPolicy`, `InstructorLoanPolicy`,
   `CompanyDebtPolicy`, `DebtPaymentPolicy`, `CompanyExpensePolicy`,
   `InstructorPolicy`, `SupplierPolicy`, `FuelRecordPolicy`,
   `StudentPaymentPolicy`, `ExpenseCategoryPolicy`, `AuditLogPolicy`,
   `UserPolicy`. Every controller action authorizes before it acts, so editing
   an id in the URL returns **403**, not data.
4. **Form Requests** — `AttendanceRequest`, `LessonRequest` and
   `StudentTransferRequest` re-check ownership of the posted `student_id`
   before validation passes.

`instructor_id` is **never** read from the request body. It always comes from
`auth()->user()->instructorId()` or from the student's current instructor.

---

## Company debt vs. company expense

The central accounting rule of the system:

> A debt is an **obligation**. An expense is **cash that has actually left the
> company**. Creating a debt books no expense.

A $500 garage service taken on credit and settled in two instalments:

| Step | Debt | Payment | Expense | Remaining | Status |
| --- | --- | --- | --- | --- | --- |
| Debt recorded | $500 | — | **$0** | $500 | Outstanding |
| Pay $200 | $500 | $200 | $200 | $300 | Partially Paid |
| Pay $300 | $500 | $300 | $300 | $0 | Paid |
| **Total** | **$500** | **$500** | **$500** | **$0** | **Paid** |

`App\Services\DebtService::recordPayment()` performs all of it inside one
`DB::transaction()` — create the payment, write the company expense, reduce the
balance, move the status — with the debt row locked (`lockForUpdate`) so two
concurrent payments cannot overdraw it. Any failure rolls the whole thing back.
Expenses generated this way are locked against direct editing; reverse the
payment instead, which removes the expense and restores the balance.

---

## Student progress

```
completed_days = distinct dates marked "present"
remaining      = max(required_days - completed_days, 0)
progress       = required_days > 0 ? completed_days / required_days * 100 : 0
```

When `completed_days >= required_days` the student is promoted to **Completed**
with `completion_date` set to the final qualifying training day. Deleting
attendance below the requirement reopens the student.

---

## Modules

**Operations** — Students, Instructors, Vehicles, Attendance, Lessons,
Student Transfers (with full, never-deleted assignment history).

**Finance (admin only)** — Fuel (cash books an expense, credit books a debt),
Company Expenses, Expense Categories, Student Payments, Instructor Loans and
repayments, Company Debts, Debt Payments, Suppliers with a full statement.

**Reports** — Student, Attendance, Instructor, Vehicle, Expense, Debt, Debt
Payment, Supplier Statement, Student Progress and Transfer reports, all with
date/instructor/student/vehicle/supplier/status filters and CSV, PDF and print
output. Instructors get only *My Students*, *My Attendance*, *My Lessons* and
*My Progress* — each built through `visibleTo()`.

**System** — Users, role permissions, audit logs (logins, logouts and every
create/update/delete on the domain models), settings.

---

## Testing

```bash
php artisan test
```

107 tests / 424 assertions, run against MySQL (`driving_school_test`; see
`phpunit.xml`). Coverage includes:

| Suite | What it proves |
| --- | --- |
| `AuthenticationTest` | Login, lockout of deactivated users, per-role landing, audit entries |
| `InstructorDataIsolationTest` | The mandated scenario — Xasan sees Ilyas and Maryan, never Ahmed or Fatima; every admin route returns 403; ids in the URL cannot be swapped; `instructor_id` cannot be spoofed |
| `StudentAuthorizationTest` | Students see only their own records and are read-only |
| `StudentTransferTest` | Transfer authorization, list movement, preserved assignment history |
| `CompanyDebtAccountingTest` | The mandated $500 / $200 / $300 example, transaction rollback, overpayment rejection, payment reversal |
| `AttendanceAndLessonTest` | Duplicate-check-in prevention (with the admin override), validation, vehicle ownership |
| `StudentProgressTest` | The 24/18/6/75% example, capping, completion and reopening |
| `AdminCrudTest` | Real create/read/update/delete against MySQL, validation, search and filters |
| `DashboardAndReportTest` | Dashboard figures computed from the database; reports and exports |
| `PageRendersTest` | Every GET route renders for its role, in English and Somali |

Create the test database once:

```sql
CREATE DATABASE driving_school_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## Project layout

```
app/
  Http/Controllers/{Auth,Admin,Instructor,Student}
  Http/Middleware/    EnsureUserHasRole, EnsureInstructorProfile, EnsureStudentProfile, SetLocale
  Http/Requests/      Form Requests with authorization + validation
  Models/             19 Eloquent models with relationships and visibleTo() scopes
  Policies/           16 policies
  Services/           DebtService, LoanService, StudentTransferService,
                      StudentProgressService, DashboardService, ReportService, AuditLogger
database/
  migrations/         25 migrations
  seeders/            Roles, reference data, school data, finance data
resources/views/
  layouts/            App shell with a per-role sidebar
  components/         Reusable Blade components
  admin/ instructor/ student/
lang/so.json          Somali translations
```
