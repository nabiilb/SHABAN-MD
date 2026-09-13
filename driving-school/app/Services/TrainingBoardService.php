<?php

namespace App\Services;

use App\Models\Instructor;
use App\Models\TrainingEvaluation;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Assembles what the dashboards show. The Blade pages render this once and the
 * polling endpoint returns the same shape, so the first paint and every live
 * update agree with each other.
 */
class TrainingBoardService
{
    public function __construct(
        private readonly TrainingQueueService $queue,
        private readonly TrainingSessionService $sessions,
    ) {}

    /**
     * One teacher's board: the student they are training now, and their own
     * FIFO waiting line. A teacher never sees another teacher's queue.
     */
    public function board(?User $viewer = null, $date = null, int $queueLimit = 5): array
    {
        $date = Carbon::parse($date ?? today());

        // Anything whose clock ran out moves on before we read the board, so a
        // finished session never lingers as "in progress" on someone's screen.
        $this->sessions->closeExpired($viewer);

        $instructorId = $viewer?->isInstructor() ? $viewer->instructorId() : null;

        if ($instructorId) {
            // The teacher's queue is their students for the day, so anyone
            // transferred in appears without anybody adding them by hand.
            $this->queue->ensureQueuedFor($instructorId, $date, $viewer);

            return $this->instructorBoard($instructorId, $date, $queueLimit) + [
                'generated_at' => now()->toIso8601String(),
            ];
        }

        // Admin: the whole floor. Every teacher's line is materialised first,
        // so the headline count and the per-teacher panels below it are read
        // from the same day — otherwise the first load of a new day counts only
        // whoever an admin queued by hand.
        $this->ensureDayQueued($date, $viewer);

        $current = TrainingSession::query()
            ->live()
            ->whereDate('started_at', $date)
            ->with(['student', 'instructor', 'lessonTopic'])
            ->orderByDesc('started_at')
            ->first();

        $waiting = $this->queue->waitingList($date, $queueLimit);
        $waitingTotal = TrainingQueueEntry::forDate($date)->waiting()->count();

        return [
            'date' => $date->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'current' => $current?->toBoardArray(),
            'current_session_id' => $current?->id,
            'needs_evaluation' => $current?->status === TrainingSession::ATTENDANCE_PENDING,
            'queue' => $waiting->values()->map(fn (TrainingQueueEntry $entry, int $index) => $this->entryArray($entry, $index))->all(),
            'queue_total' => $waitingTotal,
            'queue_overflow' => max(0, $waitingTotal - $queueLimit),
            'next' => $this->queue->nextWaiting($date)?->student?->full_name,
            'stats' => $this->stats($date),
            'teachers' => $this->teacherBoards($date, $queueLimit),
        ];
    }

    /** The board for a single teacher. */
    public function instructorBoard(int $instructorId, $date = null, int $queueLimit = 5): array
    {
        $date = Carbon::parse($date ?? today());

        $current = TrainingSession::query()
            ->live()
            ->where('instructor_id', $instructorId)
            ->whereDate('started_at', $date)
            ->with(['student', 'instructor', 'lessonTopic', 'queueEntry.student'])
            ->orderByDesc('started_at')
            ->first();

        $waiting = $this->queue->waitingFor($instructorId, $date);

        return [
            'date' => $date->toDateString(),
            'instructor_id' => $instructorId,
            'instructor' => Instructor::find($instructorId)?->full_name,
            'current' => $current ? $current->toBoardArray() + [
                'ownership' => $current->queueEntry?->ownershipOn()
                    ?? ($current->student?->current_instructor_id === $instructorId ? 'permanent' : 'transferred'),
            ] : null,
            'current_session_id' => $current?->id,
            'needs_evaluation' => $current?->status === TrainingSession::ATTENDANCE_PENDING,
            'queue' => $waiting->take($queueLimit)->values()
                ->map(fn (TrainingQueueEntry $entry, int $index) => $this->entryArray($entry, $index, $instructorId))->all(),
            'queue_total' => $waiting->count(),
            'queue_overflow' => max(0, $waiting->count() - $queueLimit),
            'next' => $waiting->first()?->student?->full_name,
            'stats' => $this->stats($date, $instructorId),
        ];
    }

    /** Every active teacher's board, for the admin overview. */
    public function teacherBoards($date = null, int $queueLimit = 5): array
    {
        $date = Carbon::parse($date ?? today());

        $this->ensureDayQueued($date);

        return Instructor::active()
            ->orderBy('full_name')
            ->get()
            ->map(fn (Instructor $instructor) => $this->instructorBoard($instructor->id, $date, $queueLimit))
            ->all();
    }

    /**
     * Gives every active teacher their line for the day before anything is
     * counted. Idempotent — ensureQueuedFor only creates what is missing.
     */
    protected function ensureDayQueued($date, ?User $actor = null): void
    {
        Instructor::active()->pluck('id')->each(
            fn (int $instructorId) => $this->queue->ensureQueuedFor($instructorId, $date, $actor),
        );
    }

    /**
     * One waiting-list row. `display_position` is this student's live number in
     * the line, counted over the waiting entries alone — a completed, training
     * or cancelled student never holds a place, and `position` (the stored
     * ordering key an admin's reorder writes to) is never shown as if it were
     * one.
     */
    protected function entryArray(TrainingQueueEntry $entry, int $index, ?int $instructorId = null): array
    {
        $ownership = $entry->ownershipOn();
        $owner = $entry->ownerIdOn();

        return [
            'id' => $entry->id,
            'position' => $entry->position,
            'display_position' => $index + 1,
            'student' => $entry->student?->full_name,
            'student_number' => $entry->student?->student_number,
            'status' => $entry->status,
            'status_label' => $entry->status_label,
            'status_icon' => $entry->status_icon,
            'waiting_minutes' => $entry->waiting_minutes,
            'ownership' => $ownership,
            // False when a shared queue is showing this teacher somebody
            // else's student, so the row can say whose they are.
            'mine' => $instructorId === null || $owner === $instructorId,
            'owner' => $entry->student?->currentInstructor?->full_name,
            'ownership_label' => match ($ownership) {
                'permanent' => __('Permanent'),
                'unassigned' => __('Unassigned'),
                default => __('Transferred'),
            },
            'permanent_instructor' => $entry->student?->currentInstructor?->full_name,
        ];
    }

    /** Headline numbers for the dashboard cards. */
    public function stats($date = null, ?int $instructorId = null): array
    {
        $date = Carbon::parse($date ?? today());

        $completed = TrainingSession::query()
            ->where('status', TrainingSession::COMPLETED)
            ->whereDate('started_at', $date)
            ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId));

        $averageMinutes = (clone $completed)
            ->whereNotNull('ended_at')
            ->get()
            ->avg(fn (TrainingSession $session) => $session->actual_minutes);

        return [
            'completed_today' => (clone $completed)->count(),
            'in_training' => TrainingSession::query()->running()->whereDate('started_at', $date)
                ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId))->count(),
            'awaiting_evaluation' => TrainingSession::query()->where('status', TrainingSession::ATTENDANCE_PENDING)
                ->whereDate('started_at', $date)
                ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId))->count(),
            'waiting' => $instructorId
                ? $this->queue->waitingFor($instructorId, $date)->count()
                : TrainingQueueEntry::forDate($date)->waiting()->count(),
            'average_minutes' => $averageMinutes ? (int) round($averageMinutes) : null,
        ];
    }

    /** Sessions finished today, newest first. */
    public function completedToday(?int $instructorId = null, $date = null, int $limit = 10)
    {
        return TrainingSession::query()
            ->where('status', TrainingSession::COMPLETED)
            ->whereDate('started_at', Carbon::parse($date ?? today()))
            ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId))
            ->with(['student', 'instructor', 'evaluation', 'lessonTopic'])
            ->orderByDesc('ended_at')
            ->limit($limit)
            ->get();
    }

    /** How the day's ratings were distributed. */
    public function evaluationBreakdown($date = null, ?int $instructorId = null): array
    {
        $rows = TrainingEvaluation::query()
            ->whereDate('evaluated_at', Carbon::parse($date ?? today()))
            ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId))
            ->selectRaw('evaluation, count(*) as total')
            ->groupBy('evaluation')
            ->pluck('total', 'evaluation');

        return collect(TrainingEvaluation::RATINGS)
            ->mapWithKeys(fn (string $rating) => [$rating => (int) ($rows[$rating] ?? 0)])
            ->all();
    }
}
