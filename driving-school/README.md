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

## Daily attendance, lesson and hand-over

Attendance is a **daily** record. Each day carries its own instructor, status,
lesson and rating:

```
Student → attendance for a DATE → instructor for that date
                                 → lesson worked on that day → performance
```

`attendance.lesson_id` points at the lesson taught that day, so the lesson and
its rating belong to that day's record rather than to the student's profile.
Marking a student **present** requires a lesson; an absent, excused or
cancelled day has none. Editing a day updates that same lesson row instead of
adding another, and clearing the lesson removes it.

### Handing a day over

**Transfer** on the attendance screen hands **one date** to another instructor.
It writes an `attendance_transfers` row for (student, date) and, in one
`DB::transaction()`, re-points that date's attendance and the lesson taught on
it. It deliberately does **not** touch the student's permanent instructor, so
the next day they are back on their usual list with nothing to undo.

| Date | Instructor | Lesson | Performance |
| --- | --- | --- | --- |
| 08/09/2026 | Teacher A | Parking | Excellent |
| 09/09/2026 | **Teacher B** (handed over) | Highway Driving | Good |
| 10/09/2026 | Teacher A (back automatically) | City Driving | Very Good |

The three days stand independently — handing over 09/09 changes neither 08/09's
lesson and rating nor 10/09's ownership.

Ownership for a date is *permanent instructor, overridden by a hand-over for
that date*. `Student::ownedByInstructorOn()` builds the check-in list from it
and `Student::instructorIdOn()` answers it for a single day, which is what
`StudentPolicy::recordFor` authorizes against. Attendance and lessons are both
visible by the instructor recorded **on the row**, so a handed-over day moves
whole and every other day stays put. A day handed away is read-only for both
instructors — `AttendancePolicy::update` and `LessonPolicy::update` require the
row's instructor *and* that date's owner to be you.

Rows are updated in place, never copied, so a student appears exactly once per
date; the unique key on `(student_id, attendance_date)` in `attendance_transfers`
keeps ownership for a day unambiguous.

This is distinct from the **permanent** reassignment on the admin Transfers
page (`student_transfers`), which moves a student for good and closes their
assignment history. That still carries its own date's attendance and lesson
across.

There is no class/section concept in this system — an instructor *is* the
class. If sections are added later, a `section_id` on the attendance row would
move alongside `instructor_id` in `AttendanceTransferService::moveAttendance()`
and be validated in `AttendanceTransferRequest`.

## Training queue and sessions

A training-centre workflow layered on the same students and instructors:

```
waiting → training_in_progress → attendance_pending → completed
```

### Each teacher runs their own queue

A teacher's queue is **their permanent students plus anyone transferred to them
for that date** — membership follows daily ownership, so nobody maintains a
list by hand. `Student::ownedByInstructorOn()` resolves it, and the queue rows
are created on demand when a board is read. The order is FIFO by `joined_at`;
`position` only breaks ties and is what an admin's manual reorder writes to.
A student who has finished is no longer waiting, so they drop out of the
numbering rather than holding a place.

**Only the student in training has a clock.** Waiting students have no
`training_sessions` row at all — no `started_at`, nothing counting down. The
clock begins at the moment `Start Training` is pressed and stops when the
session ends.

Two unique indexes make the concurrency rules the database's job rather than
the application's: `active_student_id` (no student in two live sessions) and
`active_instructor_id` (no teacher running two at once). Both are generated
columns holding the id only while a session is live, so NULLs let finished
sessions pile up freely.

When the evaluation is submitted the student is completed and the first student
still waiting in that teacher's queue **starts automatically**, with a fresh
timer of their own — nobody picks them. Set `training_auto_start_next` to `0`
to turn that off.

A teacher opens **Training Console**, picks the next student from the queue and
a duration, and the countdown starts. `started_at` and `expected_end_at` are
written to the database, and every screen renders the clock from those — so a
refresh, a second tab, or the admin's board all show the same number.

The teacher can **End Training** early, **Extend Time**, or **Pause** (pausing
holds the clock and resuming pushes the expected end out by however long it was
held, so the student keeps their full time). When the clock runs out the session
ends by itself — but only as far as **attendance_pending**. A student is
completed *only* once the teacher submits the evaluation, which records the
rating (Excellent / Very Good / Good / Needs Improvement), an optional comment,
who taught it, the real start and end times, the assigned duration and the
evaluation timestamp. Submitting it also writes the day's attendance and lesson
into the ordinary register, so the queue and the daily register are one system
rather than two.

### Two teachers cannot take the same student

Starting a session locks the queue row and re-checks it inside the transaction,
so the second teacher to press Start is told *"This student is already in
training with another teacher."* Behind that, a generated column on
`training_sessions` holds the student id only while a session is live, under a
unique index — so even a race that slipped past the lock cannot produce two live
sessions for one student.

### A note on timestamp columns

The training tables use `DATETIME` rather than `TIMESTAMP` for the times the
application sets itself (`started_at`, `expected_end_at`, `ended_at`,
`paused_at`, `evaluated_at`). With MySQL's `explicit_defaults_for_timestamp`
OFF — the default under XAMPP — a `TIMESTAMP NOT NULL` column with no explicit
default is given one implicitly: the first such column in a table gets
`ON UPDATE CURRENT_TIMESTAMP`, and any later one gets
`DEFAULT '0000-00-00 00:00:00'`, which strict mode rejects outright. `DATETIME`
needs no default, which is correct here because the service always supplies
these values. Laravel's own `created_at` / `updated_at` are nullable and
unaffected.

### Staying current without refreshing

The dashboards poll a small JSON board endpoint (`…/training/board`) every few
seconds and tick the countdown locally from the server's timestamps. That needs
no extra infrastructure. Every state change also fires `TrainingBoardChanged`,
which implements `ShouldBroadcast` on a `training-board` channel — so moving to
real push (Reverb, Pusher, Ably) is a `BROADCAST_CONNECTION` change and a
subscription, not a rewrite.

### Admin

**Training Board** shows **every teacher** side by side: who each is training
with their own live countdown, that teacher's waiting line, and whether each
student is **Permanent** or **Transferred** for the day. Below it sit the
day's completions, the rating distribution and the average duration. **Manage Queue** adds, removes and
reorders students and shows how long each has been waiting. **Training History**
filters by student, teacher, date, evaluation and status.

The five statuses live on the queue entry and the session — deliberately not on
`students.status`, which already means something else (Active / Completed /
Suspended / Cancelled for the training programme as a whole).

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

195 tests / 793 assertions, run against MySQL (`driving_school_test`; see
`phpunit.xml`). Coverage includes:

| Suite | What it proves |
| --- | --- |
| `AuthenticationTest` | Login, lockout of deactivated users, per-role landing, audit entries |
| `InstructorDataIsolationTest` | The mandated scenario — Xasan sees Ilyas and Maryan, never Ahmed or Fatima; every admin route returns 403; ids in the URL cannot be swapped; `instructor_id` cannot be spoofed |
| `StudentAuthorizationTest` | Students see only their own records and are read-only |
| `StudentTransferTest` | Transfer authorization, list movement, preserved assignment history |
| `AttendanceTransferTest` | The 09/09 example — today's attendance moves to Teacher B, 08/09 stays with Teacher A, visibility swaps for that day only, no duplicate row, reloading pages never recreates the old record, a mid-transfer failure rolls everything back, authorization and validation |
| `TrainingSessionTest` | Start, the stored countdown, early and automatic ending, extend, pause and resume, evaluation completing the student and writing the register, and the database refusing two live sessions |
| `TeacherQueueTest` | The twenty queue rules — per-teacher membership, transfers in and back out the next day, one session per teacher (service and database), waiting students having no clock, 30 and 40 minute countdowns, automatic advance, FIFO numbering, transferred students training identically, two teachers at once, and cross-teacher isolation |
| `TrainingQueueTest` | Queue ordering, moving, removal and waiting time; teacher and admin permissions; the conflict message; the live board endpoint |
| `DailyLessonAttendanceTest` | The lesson and rating belong to the day's attendance — the full 08/09 → 09/09 hand-over → 10/09 flow, editing a day reuses its lesson, the student returns to their permanent instructor the next day, each instructor sees only the lessons they taught |
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
