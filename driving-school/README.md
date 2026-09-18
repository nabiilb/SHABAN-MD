# Alpha Driving School — Management System

A complete **PHP 8 / Laravel 13 / MySQL** driving-school management system with
three roles (Admin, Instructor, Student), strict backend-enforced data
isolation, and a debt-versus-expense accounting model.

---

## Technology

| Layer | Stack |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13, Eloquent ORM, Form Requests, Policies, Gates, Middleware, DB transactions |
| Database | MySQL 5.5.62+ / MariaDB 5.5+, including MySQL 8 and MariaDB 10.x (InnoDB, foreign keys, indexes, soft deletes) |
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

## Importing the school's register

The school's own spreadsheet — `ALPHA SCHOOL.xlsx` — is read straight into
students and payments. Put it at `storage/app/imports/ALPHA SCHOOL.xlsx` (or
pass `--file=`) and look before you leap:

```bash
php artisan alpha-school:import --dry-run    # reads everything, writes nothing
php artisan alpha-school:import              # applies it
```

The dry run is the point. The register is kept by hand, so it reports the rows
that need a pen first: section totals, a student with no phone number, two rows
sharing one, amounts written as words, and dates whose year looks like a slip.
Nothing is invented — a row the sheet cannot answer for is skipped and named.

| Excel column | Goes to |
| --- | --- |
| `TAARIIKHDA` | `students.start_date`, and the payment's date |
| `MAGACA SEDDEXEN` | `students.full_name` |
| `LAMBARKA` | `students.phone`, normalised to `+252…` |
| `DEGMADA` | `students.address` |
| `LACAGTA BAXSHEY` | a `student_payments` row, when it is more than zero |
| `LACAGTA HARAA` | with the paid amount, `students.total_fee` (paid + owed) |
| `Mudadda` | `students.required_training_days`, with the words kept in `notes` |
| `Column1` | `students.status` — "complate" is completed |

No email and no date of birth are invented; both stay NULL. Reading the file
needs no package: `App\Support\XlsxReader` opens the .xlsx with PHP's own zip
and SimpleXML, so a production box needs no `composer install` to import.

**Rerunning is safe.** A student is matched on their normalised phone, and each
payment carries the row it came from (`ALPHA-IMPORT:42`), so a second run
creates nothing and takes nothing away — it only fills fields still empty.

## Seeding

`php artisan db:seed` installs roles, permissions and the reference lists, and
stops there. The demo school — invented students, instructors, vehicles,
attendance and finance — is `DemoDataSeeder`, which must be asked for by name
and refuses to run outside `local` and `testing`:

```bash
php artisan db:seed --class=DemoDataSeeder   # development only
```

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
* **My Finance → Fuel**: fill-ups recorded against him, and fuel bought for a
  vehicle assigned to him — read only
* **My Finance → Company Debts**: debts raised against one of his vehicles, and
  fuel he took on credit — read only

He can reach **none** of: other instructors' students, attendance, lessons,
vehicles, loans or fuel; company income, expenses, profit, supplier balances,
financial reports or any debt that does not touch his own work; audit logs;
user management; settings. Raising, editing, paying, cancelling and deleting
stay with the admin for both modules — an instructor reads, and that is all.

**Adding fuel.** An instructor records a fill-up for one of their own vehicles
from **+ Add Fuel**. Recording fuel is not a note — it writes into the company
ledger, an expense for cash or a debt for credit — so a submission waits as
`pending` and posts **nothing** until an admin approves it from the admin Fuel
page. Approving posts the ledger entry in the same transaction as the approval,
so the two can never come apart; rejecting posts nothing at all, and approving
twice is refused rather than charging the company twice. The admin's own entries
are unchanged: `status` defaults to `approved`, so an admin's record posts on
save exactly as it always did.

The accounting itself moved out of `Admin\FuelController` into `FuelService`,
which both roles now call — the only difference between them is the
`requiresApproval` flag. `FuelService` delegates credit purchases to the
existing `DebtService`, so there is one definition of "a debt is not an expense
until it is paid" rather than two.

The vehicle is validated against `Vehicle::visibleTo()`, not against the fleet,
so a foreign `vehicle_id` posted by hand fails validation before it reaches the
service; `instructor_id` comes from the signed-in user and is never read from
the request; and `status` and `amount` are force-filled by the service, never
mass-assigned, so a request cannot post itself approved or name its own total.
Each form carries a `submission_token` under a unique index, so a double click,
a refresh or a retried POST cannot record the same fuel twice.

There is no instructor path to raise an arbitrary company debt: the only
instructor-originated debt is fuel taken on credit, and it appears through the
approval above.

Both pages are filtered by `FuelRecord::visibleTo()` and
`CompanyDebt::visibleTo()`, the same scopes the admin lists use (they simply
return everything for an admin), and the record-level policy asks those same
scopes — so swapping an id in the address bar returns 403 rather than somebody
else's fill-up. A company debt names no instructor and never should; it reaches
one only through `company_debts.vehicle_id` or through
`fuel_records.company_debt_id`. No migration was needed for either module.

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

### Completed students stay in the records

A completed student is still one of the school's students. They remain in
**Admin → Students** with no filter applied, under `status=completed`, in a
search, under their instructor's filter, in both the Students and Student
Progress reports, and on their own instructor's list — nothing scopes them out
anywhere, and nothing archives them.

The dashboard carries a **Completed Students** card counting
`status = completed` (never inferred from attendance, which the register import
does not bring), with this month's dated completions underneath it, linking to
`/admin/students?status=completed` exactly as the Active Students card links to
`?status=active`. Both numbers come from `DashboardService::adminMetrics()`; no
count is queried from a Blade template.

The waiting queue is the one place they do not appear, and that is deliberate:
it is operational rather than a record list, and `TrainingEligibilityService`
refuses any student who is not active. Completed students are neither offered
under Add Student nor accepted if their id is posted directly.

### Students who arrived mid-course

The register import brings in students the school has been teaching for weeks,
with no attendance in this system at all. Counting days would say their whole
course is still to run, so the register's own Column1 answers it instead:

| Column1 | Meaning |
|---|---|
| `complate` (and complete / completed / compleated / dhameystiray) | finished — remaining 0, progress 100% |
| a whole number | that many training days still to run |
| anything else | reported by the import, never guessed |

A number is stored as an **opening balance**, not turned into attendance:

```
students.opening_remaining_days   the number the register gave
students.opening_remaining_from   the date it was true (the import date)
```

Remaining days then become that number less the training days recorded from
that date onwards. Days before it are already inside the opening number, so
counting them again would take the same training off twice. Nothing historical
is fabricated and `completed_days` still reports the real attendance count —
which for a freshly imported student is zero.

Both columns are nullable, and null means "no opening balance", which is every
student registered through the application: they keep the attendance-based
calculation exactly as it was.

Progress is one formula for both kinds of student:

```
progress = (required_training_days - remaining_days) / required_training_days * 100
```

For an ordinary student `required - remaining` is exactly the days attended, so
this is the old calculation unchanged; for an imported one it is the days the
register says are behind them. Clamped to 0–100, so an opening balance longer
than the course reads 0% rather than a negative.

The **Completed** figure the screens show follows the same rule, for every
student:

```
effective_completed_days = min(max(required_training_days - remaining_days, 0), required_training_days)
```

Capped at the course, so nine days of a five-day course reads **5 of 5, 100%** —
the number now agrees with the progress bar beside it. A finished student reads
as the whole course. A course with no length reports 0 and divides by nothing.

It is a display figure derived from the remaining count, never a claim about
how many attendance rows exist: `completed_days` still holds the real count,
all nine days are still on file, and the register and the training history go
on showing exactly what happened.

### Completed overrides the count

A student whose status is **Completed** always reads as finished:

```
remaining = 0
progress  = 100%
```

whatever the attendance ledger holds. The status is the school's own statement
about the student and it outranks a count of rows — which matters most for the
register import, where the school finished with a student long before this
system existed and there is no attendance to count. Without this, the Students
list reported people the school considers done as 0% complete with a full
course still to run.

Nothing is written to make it true. `completed_days` still reports the real
number of days attended, no attendance is invented, and the student's course
length is untouched — so changing the status back to Active reveals the real
figures again:

```
required 30, attended 12, status completed  →  remaining 0,  progress 100%
required 30, attended 12, status active     →  remaining 18, progress 40%
```

The rule lives in two accessors on `Student` (`remaining_days` and
`progress_percentage`) because every screen reads those: the Students list,
Student details, both instructor views, the student's own pages, the
dashboards, `StudentProgressService::summarise()` and both progress reports.
There is one calculation and no screen has its own.

**"Remaining" here is training days, never money.** The financial balance is
`balance`, derived from payments alone (see *A fee is not income*), and a
student who has finished training may still owe the whole fee.

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

### The queue is manual

**Nothing enrols a student.** A queue row exists because a teacher pressed
**+ Add Student**, or an admin queued somebody from the admin queue page — and
for no other reason. Opening the console, refreshing it, polling the board
endpoint, loading a dashboard or letting the day roll over all create exactly
zero rows. (An earlier version enrolled every student a teacher owned the moment
a board was read; `TrainingQueueService::ensureQueuedFor()` is gone, and a test
asserts it stays gone.)

The order is FIFO by `joined_at`; `position` only breaks ties and is what an
admin's manual reorder writes to. A student who has finished is no longer
waiting, so they drop out of the numbering rather than holding a place.

### Any instructor may train any student

The Training Console searches the **whole school**. Add Student is a search box
over student number, name and phone rather than a list of one teacher's
students, and any instructor may take any eligible student:

```
STD-0105  Ahmed Ali  +252…
Instructor: Cabdi · Remaining: 12 · 60% · Active      [Add to Queue]
```

**Training somebody is not taking them.** The queue entry records the adding
instructor as who will train the student, so it lands on *their* console and
not the owner's, and that is the only thing it changes. The student's permanent
assignment, their instructor history and every earlier record stay exactly as
they were; no transfer is created and no assignment is rewritten. The session,
its evaluation and the day's attendance all name the instructor who actually
did the training, and the admin's training history shows both — *Trained By*,
with the permanent instructor named beneath when they differ.

The instructor is always resolved from the authenticated user: an
`instructor_id` in the request is ignored, so nobody can record somebody else
as the trainer.

This widening is scoped to the Training Console. Instructor student lists,
attendance and every other module keep `Student::visibleTo()` — an instructor
still sees and manages only their own students there.

### One open cycle, and nothing stays open for ever

A student may hold **one open training cycle at a time, school-wide** — not per
teacher and not per day. `training_queue_entries.status` has five values; three
are open (`waiting`, `training_in_progress`, `attendance_pending`) and two are
terminal (`completed`, `cancelled`). `TrainingQueueEntry::scopeOpen()` is the
one definition, and `TrainingEligibilityService::hasOpenCycle()` the one
question. A unique index over `active_student_id` — no longer paired with
`queue_date` — makes it the database's rule too, so yesterday's unfinished entry
still blocks today.

Which means nothing may stay open indefinitely, or a forgotten entry would lock
a student out for good. Twelve hours after the **current unresolved state**
began, the cycle closes:

| Status | Measured from |
|---|---|
| `waiting` | `joined_at` |
| `training_in_progress` | the session's `started_at` |
| `attendance_pending` | the session's `ended_at` — the evaluation has been due since training ended, not since the student joined the queue |

There is no exception for a session left running overnight. Crossing midnight
is not a reason to stay open: such a session is shown on the console as carried
over while it is under its twelve hours, and closed the moment it passes them
like any other unresolved state.

`TrainingStaleCycleService::expireStale()` does it, and runs **at request time**
before any Training Console decision — the board, the search, Add to Queue and
Start Training all call it — so a stale entry never blocks a student because
cron did not run. `php artisan training:expire-stale` (with `--dry-run`) is the
same thing for the hours when nobody is looking at a console; hourly is the
right cadence. It is idempotent, and cleanup only ever *removes* work: it can
close a cycle, and it can never queue a student, start a session or write
attendance.

Closing keeps the row. `closed_at`, `closed_by` and `close_reason` say what
happened — *Auto-expired after 12 hours*, *Auto-expired: evaluation/attendance
not completed within 12 hours*, or *Removed from queue by instructor* — and no
attendance, evaluation or lesson is written, because the student did not train.

**The two twelve-hour rules are different rules.** A cycle that expired means
nothing happened, so it starts no cooldown and the student may be queued again
at once. The cooldown below applies only after a session that really was
completed.

### Queue is not training

Adding a student to the queue creates no session, no `started_at` and no clock.
A student may wait all morning. **Only Start Training begins a session**, and
nothing else ever does — not reaching the front of the line, not the previous
student finishing, not polling, refreshing or opening the console. (An earlier
version auto-started the next student on evaluation; that is gone.) Start locks
the entry, expires stale cycles, re-checks eligibility and creates exactly one
session, with `started_at` set to the moment the button was pressed. Reloading
resumes that session from server state rather than starting another.

An instructor may **Remove** a waiting student — their own console's entry, or
any as an admin. It closes the cycle rather than deleting the row, and being
allowed to *train* anyone is deliberately not being allowed to *cancel* anyone's
work. Once a session has started the simple Remove is refused; the session's own
cancellation is the way, so its timestamps stay honest.

### The twelve-hour rule

After a student finishes, they cannot be queued again for **twelve rolling
hours** — not "once a day". Finish at 08:00 and 19:59 is refused while 20:00 is
allowed; finish at 23:00 and the wait runs to 11:00 the next morning. Midnight
resets nothing.

The authoritative moment is **`training_evaluations.evaluated_at`, for an
evaluation that marked the student present**. That is the single point at which
the application declares a student completed: it is written in the same
transaction as that day's attendance row, it exists exactly once per completed
session and never for a cancelled one, and it is a real datetime —
`attendance.attendance_date` is a DATE with no time in it at all, so a rolling
rule cannot be built on it. A student marked absent never trained, so nothing
counts down for them. The gap is `training_requeue_cooldown_hours` in Settings,
defaulting to 12.

`TrainingEligibilityService` is the one place that decides any of this. The Add
Student list, the POST behind it and `TrainingQueueService::add()` all ask it,
and it returns a `QueueEligibility` carrying the verdict, a reason, the time the
student comes back and how long is left — so the dialog disables a student with
*Available at 20:00* rather than offering somebody the server is about to
refuse.

**Only the student in training has a clock.** Waiting students have no
`training_sessions` row at all — no `started_at`, nothing counting down. The
clock begins at the moment `Start Training` is pressed and stops when the
session ends.

Two unique indexes make the concurrency rules the database's job rather than
the application's: `active_student_id` (no student in two live sessions) and
`active_instructor_id` (no teacher running two at once). Both are plain columns
holding the id only while a session is live, so NULLs let finished sessions pile
up freely.

`training_queue_entries` carries the same kind of guard. Because a student may
legitimately be queued twice on one date, uniqueness is not `(student_id,
queue_date)` — that allowed one row per day, and the old code lived with it by
resetting the finished row back to `waiting`, which erased the morning cycle the
moment the evening one began. It is now `(active_student_id, queue_date)`, where
`active_student_id` holds the student id only while the entry is waiting,
training or awaiting evaluation. Two simultaneous open entries are still
impossible; a second **cycle** is a second row, and the first keeps its own
status, its own `joined_at` and its own sessions.

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
training with another teacher."* Behind that sit two guard columns on
`training_sessions`, `active_student_id` and `active_instructor_id`. Each one
carries its id while the session is live and `NULL` once it is not, and each has
a unique index over it. NULLs do not collide in a MySQL unique index, so any
number of finished sessions can share a student while a second *live* one is
impossible — a race that slipped past the row lock is refused by the database
itself, for the student and for the teacher alike.

The two columns are ordinary columns, kept in step with `status` by a `saving`
hook on the `TrainingSession` model, so no caller can change a status and forget
them. `php artisan training:check-guards` prints the server version, confirms
both columns and indexes exist, and reports any row whose guard values disagree
with its status.

### Supported database versions

The schema installs on **MySQL 5.5.62 and newer**, and on MariaDB 5.5 and newer.
Three things were needed for that, and they are worth knowing before adding a
migration:

* **No generated columns.** `GENERATED ALWAYS AS (…) STORED` arrived in MySQL
  5.7.6 / MariaDB 10.2. The guard columns above used to be generated; they are
  now plain columns the model maintains.
* **No `Schema::hasColumn()` and friends in migrations.** Laravel's column
  inspection selects `information_schema.columns.generation_expression`, which
  does not exist before MySQL 5.7 — so the *guard around* a migration fails with
  `1054 Unknown column 'generation_expression' in 'field list'` before any DDL
  runs. `App\Support\LegacySchema` answers the same questions with
  `SHOW COLUMNS` / `SHOW INDEX`.
* **No `JSON` columns, and indexed strings capped at 191 characters.** The JSON
  type is 5.7+, so `audit_logs` stores its before/after snapshots in `TEXT`
  (the model still casts them to arrays). InnoDB before 5.7 caps an index entry
  at 767 bytes, which a `utf8mb4 VARCHAR(255)` exceeds, so
  `AppServiceProvider` sets `Schema::defaultStringLength(191)`.

`LegacyMysqlCompatibilityTest` enforces all three: it reads every migration and
fails on any of these constructs, and it runs `php artisan migrate` into a
scratch database behind a hook that rejects exactly what MySQL 5.5 rejects.

### A fee is not income

A student's **Total Fee** is what they owe, not what the school has earned.
Income is the cash a student actually hands over, recorded as a student
payment — the same rule the ledger already applies on the other side, where a
company debt is not an expense until it is paid. Setting a fee therefore moves
**Unpaid Student Fees** on the dashboard, never **Total Income**, and paying it
moves the money across:

    Total Fee $120, nothing paid  →  income $0,   unpaid fees $120
    $50 paid                      →  income $50,  unpaid fees $70
    $70 more                      →  income $120, unpaid fees $0

`DashboardService::outstandingFees()` sums it in one query — fee minus payments,
floored at zero per student so an overpayment cannot cancel out somebody else's
arrears, and cancelled students excluded.

### Who owes the school money

The dashboard's **Unpaid Student Fees** card opens
`/admin/students/unpaid`, which lists every student in arrears with their fee,
what they have paid, what is left, and links through to their record and to
recording a payment.

The card's total and the page's rows are the same query asked twice —
`DashboardService::owingStudents()`, summed by `outstandingFees()` and listed by
`unpaidStudentsQuery()` — so the figure a manager clicks and the rows they land
on cannot drift apart. Fee less payments, per student: cancelled students have
left and are excluded, a fully paid student is not a row with 0.00 in it, and no
student's overpayment offsets another's arrears.

### Closing off the day's register

The register is kept by check-in, so a student who does not train leaves no row
at all — and "did not attend" ends up indistinguishable from "nobody wrote
anything down". Once a day is **over**, every active student with no record for
it is marked `absent`:

```
php artisan attendance:mark-absent                      # yesterday
php artisan attendance:mark-absent --date=2026-09-18    # a day that was missed
php artisan attendance:mark-absent --dry-run            # count, write nothing
```

Yesterday means yesterday in the school's own timezone. The day in progress is
refused outright: a student who has not arrived by lunchtime has not missed the
day yet. A student who already has **any** record for the date is skipped —
present, absent, excused and cancelled are all somebody's record of that day and
none is overwritten — so running it twice writes nothing the second time, and
each row is written inside a transaction that re-checks under a lock.

A normal run does yesterday alone. It never sweeps back over history: filling in
months of absences behind the school's back would rewrite what it knows about
its own students, so an older date has to be asked for by name.

`attendance.instructor_id` is nullable for this. An absence has no teacher to
name, and a great many students have no permanent instructor at all.

### A student payment *is* the income entry

There is no income-transaction table, and deliberately so. **Income & Expenses**,
the dashboard and the income trend all read company income straight out of
`student_payments`:

```php
$income = StudentPayment::whereDate('payment_date', …)->sum('amount');
```

So a payment row and the income it represents are the same fact stored once.
One payment is exactly one income entry because there is nothing else to be
counted — income cannot drift from the payment behind it, be double-posted, or
outlive a payment that was removed. Editing a payment moves income; soft-deleting
one takes it out of income while the row stays on file for audit.

Expenses work the other way round only because they have their own table: paying
a company debt writes a `DebtPayment` **and** a mirroring `CompanyExpense`,
linked by `company_debt_id` / `debt_payment_id` and marked `is_system_generated`
so it cannot be edited apart from the payment. There is no matching income table
for such a mirror to live in, and adding one would create a second source of
truth for the same money.

### One student, one phone number

The school identifies a student by their phone number: the register is kept by
it, and the import rejects a row without one and matches existing students on
it. So **two active students may not share a number**, and a double-clicked
New Student form cannot register the same person twice.

A validation rule alone could not do it — a second request that arrives while
the first is still in flight passes the check, because the first has not
committed when the second looks. So `students.active_phone_key` is a guard
column, like `active_student_id` on the training tables: it carries
`PhoneNumber::normalize($phone)` while the student is active and not deleted,
NULL once they are neither, and a unique index over it refuses the second
registration outright. The 1062 is caught in `StudentController::store()` and
turned back into the same message validation would have given.

`App\Support\PhoneNumber` is the one place the rule lives, used by the form
and by the import alike, so `0611000111`, `252611000111` and
`+252 611 000 111` are recognised as one number. Normalising is not rewriting:
`students.phone` still holds exactly what was typed.

Because the key is only held while a student is **active and undeleted**,
somebody who completed, cancelled or was removed frees their number for a
genuine re-registration, and a soft-deleted record keeps its history. Two
genuinely different students are never merged.

Any pair of active students already sharing a number — an older database may
hold some — is left exactly as it is: the migration gives the key to the first
and reports the rest by name, and the Student model lets such a row go on being
edited rather than turning every future save into a unique-key error. The
grandfathering is narrow: it applies only to a row already on the books, already
holding no key, whose phone, status and deletion this save does not touch. A new
student always claims their key.

The form disables Register Student on submit and shows *Registering…*, which is
comfort for the admin rather than the protection.

### Money taken at registration

The New Student form has **Amount Paid** beside Total Fee, with a payment method
and an optional receipt reference, and shows the remaining balance as you type.
Registering a student with `Total Fee 100` and `Amount Paid 40` writes the
student, their first instructor assignment and one `student_payments` row of 40
**in a single transaction** — if any part fails, none of it happened, so there is
no window in which money is banked against a student who was never created.
`Amount Paid` of 0 (or left blank) writes no payment at all and the student
simply owes the whole fee.

`StudentPaymentService` is the only thing that records a payment: the
registration form and the Student Payments screen both go through it, so a
student's first payment and their fifth are banked by the same code.
`recordRegistrationPayment()` is idempotent per student — it locks the student
row and returns the payment they already have rather than taking the money
twice, so a retry cannot double the income.

**Editing a student never records a payment.** The payment fields are only
accepted when registering; an `amount_paid` posted to the update route is
ignored outright, and raising the Total Fee from 100 to 120 leaves the 40
already paid, and the 40 already in income, exactly where they are — only the
balance moves, to 80. The balance is always `total_fee` minus the sum of
recorded payments, never read back from an income figure.

### The school's timezone

`config/app.php` reads `APP_TIMEZONE`, defaulting to `UTC`. Production sets

```
APP_TIMEZONE=Africa/Mogadishu
```

so that `today()`, `now()` and every `whereDate()` mean the school's day rather
than one three hours behind it — between 00:00 and 03:00 local, UTC is still on
yesterday, which put a queue opened at 00:30 on the wrong date and inflated the
"waiting N minutes" figures. **No stored timestamp is converted when this
changes.** Rows written before the switch keep the wall-clock characters they
already hold, so nothing can be double-shifted and rolling back is the same one
line in reverse. `DATE` columns carry no offset and cannot move at all.

Clearing and re-caching config is what makes the change take effect
(`config:clear` then `config:cache` — and the `.env` line must be in place
before the cache is written, since a cached config no longer consults `env()`).

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

### One queue, two views

A teacher's line and the admin's headline count are the same set, asked the same
way: `TrainingQueueEntry::claimableBy()`. An entry is in a teacher's line when
that teacher was asked for by name, or owns the student for the date — and an
entry **nobody** owns for the date is in *every* teacher's line, so it can
actually be taken. Without that last clause a student queued from the admin's own
Add to Queue form (which lists every active student, including those with no
permanent instructor) was counted by the admin board and shown on no console at
all. `TrainingSessionService::start()` asks the same rule before claiming, so
what a teacher can see is exactly what a teacher can take.

A centre that runs one line rather than a line per teacher turns on **One shared
training queue** in Settings: every teacher then sees the whole waiting list and
may take anyone from it, with each row naming whose student they are. A named
preferred teacher still narrows an entry. `php artisan training:check-queue`
prints, for a date, the admin's count, every teacher's line as the board service
builds it, and any waiting student no console can reach.

Teachers add to the line themselves, and only that way: **+ Add Student** on the
Waiting Queue card opens a dialog that searches every active student in the
school by name, student number or phone — any instructor may train anyone (see
*Any instructor may train any student*), and each result names whose student
they permanently are. Students who cannot be picked say why — *already in the
waiting queue*, *currently in training*,
*attendance/evaluation is pending*, or *Available at 20:00* for one still inside
the twelve-hour cooldown. Adding only ever writes `waiting` — the countdown
still starts when somebody presses Select, and the duration (defaulting to the
centre's `default_training_minutes`) is carried into the session then. Every one
of those checks is made again server-side at submission, inside the transaction
with the student row locked, so a double click cannot slip a second entry past
an answer given before either write happened. Teachers gain the `addToQueue`
ability only — reordering, moving and removing the line stay with the admin.

Queue numbers are counted over the students still waiting, never read off the
stored `position` — that column is only an ordering key for an admin's manual
reorder, and two rows can legitimately hold the same value. A student who is
training, finished or cancelled holds no place in the line.

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

282 tests / 1,338 assertions, run against MySQL (`driving_school_test`; see
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
| `TeacherQueueVisibilityTest` | The admin board and the teacher console describe one queue — two waiting students are counted by the admin and offered to the teacher, order and live positions match, a completed student keeps no position, claiming one promotes the next, two teachers cannot claim the same student, and no waiting student is invisible to every console |
| `AlphaSchoolImportTest` | The register imports — a dry run writes nothing, the real run creates the students and their payments, a second run duplicates neither, "complate" becomes completed, "NONE" becomes nothing owed, and unusable rows are reported rather than fatal |
| `InstructorAccountProvisioningTest` | An instructor account arrives with exactly one linked profile and can open its dashboard; editing the account moves the profile with it; a promotion creates one and a demotion stands it down rather than deleting it; a student needs neither email nor birthday; and assignment history keeps exactly one current row |
| `StudentFeeIncomeTest` | A student's fee is owed, not earned — a new fee moves Unpaid Student Fees and not income, paying it moves the money across, an overpayment cannot offset another student's arrears, and a cancelled student drops out |
| `InstructorFuelEntryTest` | An instructor records fuel — pending, own vehicle only, nothing posted until approval; approving writes the expense (cash) or the debt (credit); a failed ledger write rolls the approval back; foreign vehicle ids, bad numbers and future dates refused; a resubmitted form records nothing twice; the resulting debt visible to its instructor and the admin alone; and the admin's save-and-pay flow unchanged |
| `InstructorFinanceAccessTest` | Fuel and Company Debts for instructors — own records only, another instructor's refused by id, every write still admin-only, the menu carries both entries and highlights the open one, empty states, and the admin's own pages unchanged |
| `TeacherAddToQueueTest` | A teacher adds a student to the line — waiting not training, correct position, visible to the admin, refused when already waiting, training or pending, a finished student may rejoin, the unique key stops a double add, positions stay right once somebody completes, and a teacher gains no admin queue controls |
| `LegacyMysqlCompatibilityTest` | The schema installs on MySQL 5.5 — no migration reaches for generated columns, JSON columns or Laravel's 5.7-only column inspection, both guards are plain columns under unique indexes, and `migrate` completes behind a hook that refuses what 5.5 refuses |
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
