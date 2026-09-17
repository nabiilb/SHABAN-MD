<?php

namespace App\Services;

use App\Events\TrainingBoardChanged;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\User;
use App\Support\QueueEligibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The waiting line for a day.
 *
 * Positions are kept contiguous (1, 2, 3 …) after every change, so "next in
 * line" is always simply the lowest-positioned waiting entry.
 */
class TrainingQueueService
{
    public function __construct(
        private readonly TrainingEligibilityService $eligibility,
    ) {}

    /**
     * Puts a student in a day's line.
     *
     * Only ever called from a deliberate action — a teacher's Add Student, or
     * an admin queueing on somebody's behalf. Nothing in this class enrols a
     * student on its own; reading the board creates no rows.
     *
     * Eligibility is asked of TrainingEligibilityService, the same object the
     * Add Student dialog and the controller ask, and it is asked again *inside*
     * the transaction with the student row locked — so two clicks a millisecond
     * apart cannot both pass a check made before either of them wrote anything.
     * The unique index over (active_student_id, queue_date) is the backstop
     * behind that lock.
     *
     * Being in the line is not being in training — this only ever writes
     * `waiting`, never a session.
     */
    public function add(
        Student $student,
        User $actor,
        array $data = [],
        $date = null,
        ?int $instructorId = null,
    ): TrainingQueueEntry {
        $date = Carbon::parse($date ?? today())->toDateString();

        $entry = DB::transaction(function () use ($student, $actor, $data, $date, $instructorId) {
            // Serialises concurrent adds for this one student without taking a
            // lock on a queue row that may not exist yet.
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $verdict = $this->eligibility->check($student, $date);

            if (! $verdict->eligible) {
                throw new RuntimeException($verdict->reason);
            }

            $position = $this->nextPosition($date);

            // A new cycle is a new row. The finished one keeps its own joined_at,
            // its own status and its own sessions — this morning's training is
            // still there after this evening's is added.
            $entry = TrainingQueueEntry::create([
                'student_id' => $student->id,
                'queue_date' => $date,
                'position' => $position,
                'status' => TrainingQueueEntry::WAITING,
                // Whoever added the student is who will train them: the entry
                // goes on THEIR console, not the console of whoever the student
                // permanently belongs to. Ownership is not changed by this —
                // the student's own teacher stays their own teacher.
                'preferred_instructor_id' => $data['preferred_instructor_id'] ?? $instructorId,
                'assigned_duration_minutes' => $data['assigned_duration_minutes'] ?? null,
                'joined_at' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            AuditLogger::log('training.queued', $entry, __(':name joined the training queue at position :position', [
                'name' => $student->full_name,
                'position' => $position,
            ]));

            return $entry;
        });

        // Both dashboards read the same board payload, so one signal updates
        // the teacher who added and every screen watching the floor.
        event(new TrainingBoardChanged('queue.added', ['entry_id' => $entry->id]));

        return $entry;
    }

    /**
     * Students a teacher may put in the line, found by searching the school.
     *
     * Any instructor may train any eligible student, so this searches every
     * active student rather than one teacher's list — by student number, name
     * or phone, the three things somebody at the desk actually has. A short
     * search term would return most of the school, so a term is required and
     * the result is capped.
     *
     * Each student comes back carrying their QueueEligibility and their
     * permanent instructor, so the dialog can say whose student this is and why
     * they cannot be picked rather than offering somebody the server is about
     * to refuse.
     *
     * @return Collection<int, Student>
     */
    public function searchFor(string $term, $date = null, int $limit = 20): Collection
    {
        $term = trim($term);

        // A one-character search would return most of the school, which is not
        // a search. Same Eloquent collection type as the real result.
        if (mb_strlen($term) < 2) {
            return Student::query()->whereRaw('1 = 0')->get();
        }

        $date = Carbon::parse($date ?? today())->toDateString();
        $like = '%'.$term.'%';
        $digits = preg_replace('/\D+/', '', $term);

        $students = Student::query()
            ->with('currentInstructor')
            ->where('students.status', 'active')
            ->where(fn ($q) => $q
                ->where('full_name', 'like', $like)
                ->orWhere('student_number', 'like', $like)
                ->orWhere('phone', 'like', $like)
                // A number typed without its punctuation still finds the
                // student whose stored number carries some.
                ->when($digits !== '' && mb_strlen($digits) >= 3, fn ($p) => $p
                    ->orWhereRaw(
                        "replace(replace(replace(replace(phone, '+', ''), '-', ''), ' ', ''), '(', '') like ?",
                        ['%'.$digits.'%'],
                    )))
            ->orderBy('full_name')
            ->limit($limit)
            ->get();

        $verdicts = $this->eligibility->checkMany($students, $date);

        return $students->each(fn (Student $student) => $student->setAttribute(
            'queue_eligibility',
            $verdicts->get($student->id),
        ));
    }

    /** Whether this student may be queued for the date, and why not. */
    public function eligibilityFor(Student $student, $date = null): QueueEligibility
    {
        return $this->eligibility->check($student, $date);
    }

    /** Whether this student is allowed into the line for the date. */
    public function canAdd(Student $student, $date = null): bool
    {
        return $this->eligibilityFor($student, $date)->eligible;
    }

    /** Takes a student out of the line and closes the gap behind them. */
    public function remove(TrainingQueueEntry $entry, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($entry, $reason) {
            if ($entry->status === TrainingQueueEntry::TRAINING_IN_PROGRESS) {
                throw new RuntimeException(__('End the training session before removing this student from the queue.'));
            }

            $entry->update([
                'status' => TrainingQueueEntry::CANCELLED,
                'notes' => $reason ?: $entry->notes,
            ]);

            $this->compact($entry->queue_date);

            AuditLogger::log('training.queue_removed', $entry, __(':name was removed from the queue', [
                'name' => $entry->student?->full_name,
            ]));
        });

        event(new TrainingBoardChanged('queue.removed'));
    }

    /**
     * Moves an entry to a new position, sliding the others along.
     */
    public function moveTo(TrainingQueueEntry $entry, int $position, User $actor): void
    {
        DB::transaction(function () use ($entry, $position) {
            $line = $this->lineFor($entry->queue_date, lock: true)
                ->reject(fn (TrainingQueueEntry $item) => $item->is($entry))
                ->values();

            $target = max(0, min($position - 1, $line->count()));
            $line->splice($target, 0, [$entry]);

            $line->each(function (TrainingQueueEntry $item, int $index) {
                $item->update(['position' => $index + 1]);
            });
        });

        AuditLogger::log('training.queue_reordered', $entry, __(':name moved to position :position', [
            'name' => $entry->student?->full_name,
            'position' => $position,
        ]));

        event(new TrainingBoardChanged('queue.reordered'));
    }

    /** Applies a full ordering, as sent by the drag-free reorder controls. */
    public function reorder(array $orderedIds, $date, User $actor): void
    {
        DB::transaction(function () use ($orderedIds, $date) {
            $entries = TrainingQueueEntry::whereIn('id', $orderedIds)
                ->whereDate('queue_date', Carbon::parse($date)->toDateString())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $position = 1;

            foreach ($orderedIds as $id) {
                if ($entry = $entries->get($id)) {
                    $entry->update(['position' => $position++]);
                }
            }
        });

        event(new TrainingBoardChanged('queue.reordered'));
    }

    /**
     * The next student due to train for a teacher, in FIFO order.
     *
     * Ownership is resolved for the date, so a student transferred in today is
     * picked up exactly like a permanent one — and a student transferred away
     * is not.
     */
    public function nextWaitingFor(int $instructorId, $date = null): ?TrainingQueueEntry
    {
        return $this->waitingFor($instructorId, $date)->first();
    }

    /**
     * A single teacher's waiting line for a date, oldest first.
     *
     * Membership is TrainingQueueEntry::claimableBy() — the same rule the claim
     * itself asks — so a student the teacher can see is a student the teacher
     * can take, and a waiting student the admin counts always shows up on at
     * least one console.
     *
     * @return Collection<int, TrainingQueueEntry>
     */
    public function waitingFor(int $instructorId, $date = null, ?int $limit = null): Collection
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        return TrainingQueueEntry::query()
            ->whereDate('queue_date', $date)
            ->waiting()
            ->claimableBy($instructorId, $date)
            ->ordered()
            ->with('student.currentInstructor')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    /** Every open entry for a teacher on a date, whatever its status. */
    public function lineForInstructor(int $instructorId, $date = null): Collection
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        return TrainingQueueEntry::query()
            ->whereDate('queue_date', $date)
            ->claimableBy($instructorId, $date)
            ->ordered()
            ->with('student.currentInstructor')
            ->get();
    }

    /** The next student due to train anywhere, used by the admin overview. */
    public function nextWaiting($date = null, ?int $instructorId = null): ?TrainingQueueEntry
    {
        if ($instructorId) {
            return $this->nextWaitingFor($instructorId, $date);
        }

        return TrainingQueueEntry::query()
            ->forDate($date)
            ->waiting()
            ->ordered()
            ->with('student')
            ->first();
    }

    /** @return Collection<int, TrainingQueueEntry> */
    public function waitingList($date = null, ?int $limit = null): Collection
    {
        return TrainingQueueEntry::query()
            ->forDate($date)
            ->waiting()
            ->ordered()
            ->with(['student', 'preferredInstructor'])
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    /** Renumbers the open entries so positions stay 1..n with no gaps. */
    public function compact($date): void
    {
        $this->lineFor($date, lock: true)
            ->values()
            ->each(function (TrainingQueueEntry $entry, int $index) {
                if ($entry->position !== $index + 1) {
                    $entry->update(['position' => $index + 1]);
                }
            });
    }

    /** @return Collection<int, TrainingQueueEntry> */
    protected function lineFor($date, bool $lock = false): Collection
    {
        return TrainingQueueEntry::query()
            ->whereDate('queue_date', Carbon::parse($date)->toDateString())
            ->inLine()
            ->ordered()
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    protected function nextPosition(string $date): int
    {
        return (int) TrainingQueueEntry::whereDate('queue_date', $date)->max('position') + 1;
    }
}
