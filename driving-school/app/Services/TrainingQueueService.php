<?php

namespace App\Services;

use App\Events\TrainingBoardChanged;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\User;
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
    /**
     * Puts a student in a day's line.
     *
     * The row is locked before anything is decided and the table has a unique
     * key on (student_id, queue_date), so two people adding the same student at
     * the same moment cannot produce two entries: the second either sees the
     * first inside the transaction or is refused by the index.
     *
     * Being in the line is not being in training — this only ever writes
     * `waiting`, never a session.
     */
    public function add(Student $student, User $actor, array $data = [], $date = null): TrainingQueueEntry
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        if ($student->status !== 'active') {
            throw new RuntimeException(__(':name is not an active student.', ['name' => $student->full_name]));
        }

        $entry = DB::transaction(function () use ($student, $actor, $data, $date) {
            $existing = TrainingQueueEntry::where('student_id', $student->id)
                ->whereDate('queue_date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertCanRejoin($existing);
            }

            $position = $this->nextPosition($date);

            // A student who finished earlier can rejoin the same day; their
            // old entry is reused so the unique key still holds.
            $entry = $existing
                ? tap($existing)->update([
                    'status' => TrainingQueueEntry::WAITING,
                    'position' => $position,
                    'joined_at' => now(),
                    'preferred_instructor_id' => $data['preferred_instructor_id'] ?? null,
                    'assigned_duration_minutes' => $data['assigned_duration_minutes'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ])
                : TrainingQueueEntry::create([
                    'student_id' => $student->id,
                    'queue_date' => $date,
                    'position' => $position,
                    'status' => TrainingQueueEntry::WAITING,
                    'preferred_instructor_id' => $data['preferred_instructor_id'] ?? null,
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
     * Why a student already in today's line cannot simply be added again.
     *
     * A student who finished earlier may rejoin — the centre runs repeat
     * training on the same day, and their completed session stays in the
     * history either way — so only the three open states refuse.
     */
    protected function assertCanRejoin(TrainingQueueEntry $existing): void
    {
        $message = match ($existing->status) {
            TrainingQueueEntry::WAITING => __('This student is already in the waiting queue.'),
            TrainingQueueEntry::TRAINING_IN_PROGRESS => __('This student is currently in training.'),
            TrainingQueueEntry::ATTENDANCE_PENDING => __("This student's attendance/evaluation is pending."),
            default => null,
        };

        if ($message !== null) {
            throw new RuntimeException($message);
        }
    }

    /**
     * The active students a teacher may put in the line for a date — the same
     * reach they have over the line itself, so they cannot queue somebody they
     * would then be unable to see or take.
     *
     * @return Collection<int, Student>
     */
    public function addableFor(int $instructorId, $date = null): Collection
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        return Student::query()
            ->select('students.*')
            // Where each of them already stands in today's line, so the dialog
            // can say so instead of letting somebody pick a student the server
            // is about to refuse. One correlated sub-select, not a query a row.
            ->addSelect(['queue_status' => TrainingQueueEntry::query()
                ->select('status')
                ->whereColumn('training_queue_entries.student_id', 'students.id')
                ->whereDate('queue_date', $date)
                ->limit(1),
            ])
            ->where('students.status', 'active')
            ->when(
                ! Setting::flag('training_shared_queue'),
                fn ($query) => $query->where(fn ($q) => $q
                    ->ownedByInstructorOn($instructorId, $date)
                    ->orWhere(fn ($open) => $open->unassignedOn($date))),
            )
            ->orderBy('full_name')
            ->get();
    }

    /** Whether this teacher is allowed to queue this student for the date. */
    public function canAdd(int $instructorId, Student $student, $date = null): bool
    {
        return $this->addableFor($instructorId, $date)->contains('id', $student->id);
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
     * Makes sure every student this teacher owns on this date has a queue row.
     *
     * A teacher's queue is their permanent students plus anyone transferred to
     * them for the date, so membership follows ownership rather than a list
     * somebody has to maintain by hand. Students transferred away for the date
     * are left alone — they belong to the receiving teacher's queue instead.
     *
     * @return int how many rows were created
     */
    public function ensureQueuedFor(int $instructorId, $date = null, ?User $actor = null): int
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        $owned = Student::query()
            ->ownedByInstructorOn($instructorId, $date)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get();

        $alreadyQueued = TrainingQueueEntry::query()
            ->whereDate('queue_date', $date)
            ->whereIn('student_id', $owned->pluck('id'))
            ->pluck('student_id')
            ->all();

        $created = 0;

        foreach ($owned as $student) {
            if (in_array($student->id, $alreadyQueued, true)) {
                continue;
            }

            TrainingQueueEntry::create([
                'student_id' => $student->id,
                'queue_date' => $date,
                'position' => $this->nextPosition($date),
                'status' => TrainingQueueEntry::WAITING,
                'joined_at' => now(),
                'created_by' => $actor?->id,
            ]);

            $created++;
        }

        return $created;
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
