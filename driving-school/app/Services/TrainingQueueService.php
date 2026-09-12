<?php

namespace App\Services;

use App\Events\TrainingBoardChanged;
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
    public function add(Student $student, User $actor, array $data = [], $date = null): TrainingQueueEntry
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        return DB::transaction(function () use ($student, $actor, $data, $date) {
            $existing = TrainingQueueEntry::where('student_id', $student->id)
                ->whereDate('queue_date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing && in_array($existing->status, TrainingQueueEntry::OPEN_STATUSES, true)) {
                throw new RuntimeException(__(':name is already in today\'s queue.', ['name' => $student->full_name]));
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
     * @return Collection<int, TrainingQueueEntry>
     */
    public function waitingFor(int $instructorId, $date = null, ?int $limit = null): Collection
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        return TrainingQueueEntry::query()
            ->whereDate('queue_date', $date)
            ->waiting()
            ->whereHas('student', fn ($q) => $q->ownedByInstructorOn($instructorId, $date))
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
            ->whereHas('student', fn ($q) => $q->ownedByInstructorOn($instructorId, $date))
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
