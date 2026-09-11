<?php

namespace App\Services;

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
     * @param  User|null  $viewer  scopes "current training" to one teacher
     */
    public function board(?User $viewer = null, $date = null, int $queueLimit = 5): array
    {
        $date = Carbon::parse($date ?? today());

        // Anything whose clock ran out moves on before we read the board, so a
        // finished session never lingers as "in progress" on someone's screen.
        $this->sessions->closeExpired($viewer);

        $instructorId = $viewer?->isInstructor() ? $viewer->instructorId() : null;

        $current = TrainingSession::query()
            ->live()
            ->when($instructorId, fn ($q) => $q->where('instructor_id', $instructorId))
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
            'queue' => $waiting->map(fn (TrainingQueueEntry $entry, int $index) => [
                'id' => $entry->id,
                'position' => $entry->position,
                'display_position' => $index + 1,
                'student' => $entry->student?->full_name,
                'student_number' => $entry->student?->student_number,
                'status' => $entry->status,
                'status_label' => $entry->status_label,
                'status_icon' => $entry->status_icon,
                'waiting_minutes' => $entry->waiting_minutes,
                'preferred_instructor' => $entry->preferredInstructor?->full_name,
            ])->values()->all(),
            'queue_total' => $waitingTotal,
            'queue_overflow' => max(0, $waitingTotal - $queueLimit),
            'next' => $this->queue->nextWaiting($date, $instructorId)?->student?->full_name,
            'stats' => $this->stats($date, $instructorId),
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
            'waiting' => TrainingQueueEntry::forDate($date)->waiting()->count(),
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
