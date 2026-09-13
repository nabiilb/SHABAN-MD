<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TrainingEvaluation;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Running a training session from the queue through to a completed evaluation.
 *
 * The workflow is:
 *   waiting → training_in_progress → attendance_pending → completed
 *
 * A student only becomes completed once the teacher has actually submitted the
 * evaluation; the clock running out just moves them to attendance_pending.
 */
class TrainingSessionService
{
    public function __construct(
        private readonly TrainingQueueService $queue,
        private readonly DailyLessonService $lessons,
        private readonly StudentProgressService $progress,
    ) {}

    /**
     * Claims a waiting student and starts their session.
     *
     * The queue row is locked and re-checked inside the transaction, so two
     * teachers pressing Start at the same moment cannot both win: the second
     * finds the student no longer waiting. The unique index on the sessions
     * table is the backstop if anything slips past that.
     */
    public function start(
        TrainingQueueEntry $entry,
        Instructor $instructor,
        User $actor,
        array $options = [],
    ): TrainingSession {
        return DB::transaction(function () use ($entry, $instructor, $actor, $options) {
            $entry = TrainingQueueEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->status === TrainingQueueEntry::TRAINING_IN_PROGRESS) {
                throw new RuntimeException(__('This student is already in training with another teacher.'));
            }

            if ($entry->status === TrainingQueueEntry::COMPLETED) {
                throw new RuntimeException(__('This student has already completed training today.'));
            }

            if ($entry->status !== TrainingQueueEntry::WAITING) {
                throw new RuntimeException(__('This student is not waiting to train.'));
            }

            // A student cannot be live in two sessions, whatever the queue says.
            if (TrainingSession::where('student_id', $entry->student_id)->live()->exists()) {
                throw new RuntimeException(__('This student is already in training with another teacher.'));
            }

            // Nor can a teacher run two at once — one student at a time.
            if (TrainingSession::where('instructor_id', $instructor->id)->live()->exists()) {
                throw new RuntimeException(__('You already have a training session in progress.'));
            }

            // The student must be in this teacher's line for this date —
            // theirs permanently, transferred to them for the day, asked for
            // by name, or in the open pool nobody owns. Exactly the rule the
            // waiting list is built from, so what a teacher can see is what a
            // teacher can take.
            if (! $entry->isClaimableBy($instructor->id)) {
                throw new RuntimeException(__('This student is not in your queue for this date.'));
            }

            $minutes = (int) ($options['assigned_duration_minutes']
                ?? $entry->assigned_duration_minutes
                ?? $this->defaultDuration());

            $startedAt = now();

            try {
                $session = TrainingSession::create([
                    'student_id' => $entry->student_id,
                    'instructor_id' => $instructor->id,
                    'training_queue_entry_id' => $entry->id,
                    'lesson_topic_id' => $options['lesson_topic_id'] ?? null,
                    'vehicle_id' => $options['vehicle_id'] ?? null,
                    'status' => TrainingSession::IN_PROGRESS,
                    'assigned_duration_minutes' => $minutes,
                    'started_at' => $startedAt,
                    'expected_end_at' => $startedAt->copy()->addMinutes($minutes),
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                // A unique index caught a race the lock somehow missed: either
                // this student or this teacher already has a live session.
                throw new RuntimeException(
                    str_contains($e->getMessage(), 'active_instructor')
                        ? __('You already have a training session in progress.')
                        : __('This student is already in training with another teacher.'),
                    0,
                    $e,
                );
            }

            $entry->update([
                'status' => TrainingQueueEntry::TRAINING_IN_PROGRESS,
                'assigned_duration_minutes' => $minutes,
            ]);

            AuditLogger::log('training.started', $session, __(':student started training with :instructor for :minutes minutes', [
                'student' => $session->student?->full_name,
                'instructor' => $instructor->full_name,
                'minutes' => $minutes,
            ]), null, $session->getAttributes());

            return $session->load(['student', 'instructor', 'lessonTopic']);
        });
    }

    /** Starts the next student in this teacher's own FIFO queue. */
    public function startNext(Instructor $instructor, User $actor, array $options = [], $date = null): ?TrainingSession
    {
        $next = $this->queue->nextWaitingFor($instructor->id, $date);

        return $next ? $this->start($next, $instructor, $actor, $options) : null;
    }

    /**
     * Ends a session — early by the teacher, or because the clock ran out.
     * The student moves to attendance_pending, never straight to completed.
     */
    public function end(TrainingSession $session, User $actor, bool $automatic = false): TrainingSession
    {
        return DB::transaction(function () use ($session, $actor, $automatic) {
            $session = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, [TrainingSession::IN_PROGRESS, TrainingSession::PAUSED], true)) {
                // Already ended — treat as a no-op so a double click is safe.
                return $session;
            }

            $endedAt = now();

            $session->update([
                'status' => TrainingSession::ATTENDANCE_PENDING,
                'ended_at' => $endedAt,
                'ended_early' => ! $automatic && $endedAt->lt($session->expected_end_at),
                'ended_by' => $actor->id,
                'paused_seconds' => $session->status === TrainingSession::PAUSED
                    ? $session->paused_seconds + $session->paused_at->diffInSeconds($endedAt)
                    : $session->paused_seconds,
                'paused_at' => null,
            ]);

            $session->queueEntry?->update(['status' => TrainingQueueEntry::ATTENDANCE_PENDING]);

            AuditLogger::log('training.ended', $session, __(':student\'s training ended — attendance and evaluation required', [
                'student' => $session->student?->full_name,
            ]));

            return $session->fresh(['student', 'instructor', 'lessonTopic']);
        });
    }

    /** Adds time to a running session. */
    public function extend(TrainingSession $session, int $minutes, User $actor): TrainingSession
    {
        if ($minutes < 1 || $minutes > 120) {
            throw new RuntimeException(__('Extensions must be between 1 and 120 minutes.'));
        }

        return DB::transaction(function () use ($session, $minutes) {
            $session = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, [TrainingSession::IN_PROGRESS, TrainingSession::PAUSED], true)) {
                throw new RuntimeException(__('Only a running session can be extended.'));
            }

            $session->update([
                'extended_minutes' => $session->extended_minutes + $minutes,
                'expected_end_at' => $session->expected_end_at->copy()->addMinutes($minutes),
            ]);

            AuditLogger::log('training.extended', $session, __('Training extended by :minutes minutes', ['minutes' => $minutes]));

            return $session->fresh();
        });
    }

    public function pause(TrainingSession $session, User $actor): TrainingSession
    {
        return DB::transaction(function () use ($session) {
            $session = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== TrainingSession::IN_PROGRESS) {
                throw new RuntimeException(__('Only a running session can be paused.'));
            }

            $session->update(['status' => TrainingSession::PAUSED, 'paused_at' => now()]);
            AuditLogger::log('training.paused', $session, __('Training paused'));

            return $session->fresh();
        });
    }

    /**
     * Resumes a paused session, pushing the expected end out by however long
     * it was held, so the student still gets their full time.
     */
    public function resume(TrainingSession $session, User $actor): TrainingSession
    {
        return DB::transaction(function () use ($session) {
            $session = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== TrainingSession::PAUSED) {
                throw new RuntimeException(__('Only a paused session can be resumed.'));
            }

            $heldSeconds = (int) $session->paused_at->diffInSeconds(now());

            $session->update([
                'status' => TrainingSession::IN_PROGRESS,
                'paused_seconds' => $session->paused_seconds + $heldSeconds,
                'expected_end_at' => $session->expected_end_at->copy()->addSeconds($heldSeconds),
                'paused_at' => null,
            ]);

            AuditLogger::log('training.resumed', $session, __('Training resumed'));

            return $session->fresh();
        });
    }

    /**
     * Records the teacher's evaluation, which is what actually completes the
     * student. Also writes the day's attendance and lesson, so the training
     * centre's daily register and this queue stay one system rather than two.
     */
    public function evaluate(TrainingSession $session, array $data, User $actor): TrainingEvaluation
    {
        return DB::transaction(function () use ($session, $data, $actor) {
            $session = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status === TrainingSession::COMPLETED) {
                throw new RuntimeException(__('This session has already been evaluated.'));
            }

            if ($session->status !== TrainingSession::ATTENDANCE_PENDING) {
                throw new RuntimeException(__('End the training session before evaluating it.'));
            }

            $attendanceStatus = $data['attendance_status'] ?? 'present';
            $attendance = $this->recordAttendance($session, $attendanceStatus, $data, $actor);

            $evaluation = TrainingEvaluation::create([
                'training_session_id' => $session->id,
                'student_id' => $session->student_id,
                'instructor_id' => $session->instructor_id,
                'attendance_id' => $attendance?->id,
                'attendance_status' => $attendanceStatus,
                'evaluation' => $data['evaluation'] ?? null,
                'comment' => $data['comment'] ?? null,
                'evaluated_at' => now(),
                'created_by' => $actor->id,
            ]);

            $session->update(['status' => TrainingSession::COMPLETED]);
            $session->queueEntry?->update(['status' => TrainingQueueEntry::COMPLETED]);

            $this->queue->compact($session->queueEntry?->queue_date ?? today());

            AuditLogger::log('training.evaluated', $session, __(':student completed training — :rating', [
                'student' => $session->student?->full_name,
                'rating' => $evaluation->rating_label,
            ]), null, $evaluation->getAttributes());

            // The teacher should not have to pick the next student: the first
            // student still waiting in their queue starts straight away, with
            // a fresh timer of their own.
            $this->advanceQueue($session, $actor);

            return $evaluation->load(['student', 'instructor', 'session']);
        });
    }

    /**
     * Starts the next waiting student for the teacher who just finished one.
     *
     * Only the student who reaches "training now" gets a clock — everybody
     * behind them is still only waiting, with no started_at and nothing
     * counting down.
     */
    public function advanceQueue(TrainingSession $finished, User $actor): ?TrainingSession
    {
        if (! Setting::flag('training_auto_start_next', true)) {
            return null;
        }

        $date = $finished->queueEntry?->queue_date ?? $finished->started_at?->copy()->startOfDay() ?? today();
        $next = $this->queue->nextWaitingFor($finished->instructor_id, $date);

        if (! $next) {
            return null;
        }

        return $this->start($next, $finished->instructor, $actor, [
            // The next student keeps whatever duration was set for them,
            // falling back to the one just used.
            'assigned_duration_minutes' => $next->assigned_duration_minutes ?: $finished->assigned_duration_minutes,
            'lesson_topic_id' => null,
        ]);
    }

    /**
     * Ends any session whose clock has run out. Called on every board read, so
     * the countdown reaching zero moves the student on even if nobody is
     * looking at the teacher's screen.
     */
    public function closeExpired(?User $actor = null): int
    {
        $expired = TrainingSession::query()
            ->where('status', TrainingSession::IN_PROGRESS)
            ->where('expected_end_at', '<=', now())
            ->get();

        foreach ($expired as $session) {
            $this->end($session, $actor ?? $session->starter ?? new User(['id' => null]), automatic: true);
        }

        return $expired->count();
    }

    /* ----------------------------------------------------------------
     | Internals
     | ---------------------------------------------------------------- */

    /**
     * Writes the day's attendance for the session, reusing the existing daily
     * register. The evaluation doubles as the lesson's performance rating.
     */
    protected function recordAttendance(
        TrainingSession $session,
        string $attendanceStatus,
        array $data,
        User $actor,
    ): ?Attendance {
        $student = $session->student;
        $date = $session->started_at->copy()->startOfDay();

        $attendance = Attendance::firstOrNew([
            'student_id' => $student->id,
            'attendance_date' => $date->toDateString(),
        ]);

        $attendance->fill([
            'instructor_id' => $session->instructor_id,
            'check_in_time' => $session->started_at->format('H:i:s'),
            'status' => $attendanceStatus,
            'notes' => $data['comment'] ?? $attendance->notes,
            'recorded_by' => $actor->id,
        ])->save();

        // Only a day the student actually attended carries a lesson.
        if ($attendanceStatus === 'present' && $session->lesson_topic_id) {
            $this->lessons->sync($attendance->refresh(), [
                'lesson_topic_id' => $session->lesson_topic_id,
                'performance' => $data['evaluation'] ?? null,
                'vehicle_id' => $session->vehicle_id,
                'duration_minutes' => $session->actual_minutes ?: $session->total_minutes,
                'topic' => $data['comment'] ?? null,
            ], $actor);
        }

        $this->progress->recalculate($student);

        return $attendance->refresh();
    }

    protected function defaultDuration(): int
    {
        return (int) Setting::get('default_training_minutes', 30);
    }
}
