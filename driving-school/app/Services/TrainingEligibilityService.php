<?php

namespace App\Services;

use App\Models\Instructor;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TrainingEvaluation;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Support\QueueEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE one place that decides whether a student may be put in the waiting line.
 *
 * The Add Student dialog, the POST that acts on it and the queue service that
 * writes the row all ask this same object, so what the teacher is offered, what
 * the server accepts and what the database ends up holding cannot drift apart.
 *
 * Five questions, in the order a teacher would ask them:
 *
 *   1. Is the student still an active student at all?
 *   2. Is this teacher the one responsible for them on this date?
 *   3. Are they already in this date's line?
 *   4. Are they in a live session somewhere — any teacher, any day?
 *   5. Has it been long enough since they last finished?
 *
 * Deliberately depends on no other training service: TrainingQueueService and
 * TrainingSessionService both depend on this, and a cycle between them would be
 * a container error rather than a design.
 */
class TrainingEligibilityService
{
    /**
     * The rolling gap between one finished session and the next time a student
     * may be queued. Rolling, not once-per-day: midnight is not a reset.
     */
    public const COOLDOWN_HOURS = 12;

    public function cooldownHours(): int
    {
        return max(0, (int) Setting::get('training_requeue_cooldown_hours', self::COOLDOWN_HOURS));
    }

    /**
     * The brief's own signature. An instructor is always required here, so this
     * is the call that says "this teacher, this student".
     */
    public function canQueueStudent(Instructor $instructor, Student $student, $date = null): QueueEligibility
    {
        return $this->check($instructor->id, $student, $date);
    }

    /**
     * The same question, with the instructor given as an id — or as null for an
     * admin, who queues on behalf of the floor and so is not asked to own the
     * student. Every other check still applies to them.
     */
    public function check(?int $instructorId, Student $student, $date = null): QueueEligibility
    {
        $date = Carbon::parse($date ?? today())->toDateString();

        return $this->decide(
            student: $student,
            owned: $instructorId === null || $this->owns($instructorId, $student, $date),
            openEntry: TrainingQueueEntry::query()
                ->where('student_id', $student->id)
                ->forDate($date)
                ->inLine()
                ->first(),
            live: TrainingSession::query()->live()->where('student_id', $student->id)->exists(),
            lastFinishedAt: $this->lastFinishedAt($student->id),
        );
    }

    /**
     * The same answer for a whole list, in a fixed number of queries rather
     * than five per student — what the Add Student dialog needs to render.
     *
     * @param  Collection<int, Student>  $students
     * @return Collection<int, QueueEligibility> keyed by student id
     */
    public function checkMany(?int $instructorId, Collection $students, $date = null): Collection
    {
        $date = Carbon::parse($date ?? today())->toDateString();
        $ids = $students->pluck('id')->all();

        if ($ids === []) {
            return collect();
        }

        $owned = $instructorId === null
            ? array_flip($ids)
            : array_flip(Student::query()
                ->whereIn('id', $ids)
                ->ownedByInstructorOn($instructorId, $date)
                ->pluck('id')
                ->all());

        $openEntries = TrainingQueueEntry::query()
            ->whereIn('student_id', $ids)
            ->forDate($date)
            ->inLine()
            ->get()
            ->keyBy('student_id');

        $live = TrainingSession::query()
            ->live()
            ->whereIn('student_id', $ids)
            ->pluck('student_id')
            ->flip();

        $finished = $this->lastFinishedFor($ids);

        return $students->mapWithKeys(fn (Student $student) => [$student->id => $this->decide(
            student: $student,
            owned: isset($owned[$student->id]),
            openEntry: $openEntries->get($student->id),
            live: $live->has($student->id),
            lastFinishedAt: $finished[$student->id] ?? null,
        )]);
    }

    /**
     * When a student may next be queued, or null when nothing is holding them.
     *
     * Public because the console and the reports both want to show it without
     * re-deriving the rule.
     */
    public function eligibleAt(int $studentId): ?Carbon
    {
        $finished = $this->lastFinishedAt($studentId);

        return $finished?->copy()->addHours($this->cooldownHours());
    }

    /* ----------------------------------------------------------------
     | Internals
     | ---------------------------------------------------------------- */

    /**
     * The whole decision, given facts already gathered. One code path for the
     * single check and the bulk one, so the dialog cannot be kinder than the
     * POST that follows it.
     */
    private function decide(
        Student $student,
        bool $owned,
        ?TrainingQueueEntry $openEntry,
        bool $live,
        ?Carbon $lastFinishedAt,
    ): QueueEligibility {
        if ($student->status !== 'active') {
            return QueueEligibility::blocked(
                QueueEligibility::NOT_ACTIVE,
                __(':name is not an active student.', ['name' => $student->full_name]),
            );
        }

        if (! $owned) {
            return QueueEligibility::blocked(
                QueueEligibility::NOT_YOURS,
                __('This student is assigned to another instructor.'),
            );
        }

        if ($openEntry) {
            return match ($openEntry->status) {
                TrainingQueueEntry::TRAINING_IN_PROGRESS => QueueEligibility::blocked(
                    QueueEligibility::IN_TRAINING,
                    __('This student is currently in training.'),
                ),
                TrainingQueueEntry::ATTENDANCE_PENDING => QueueEligibility::blocked(
                    QueueEligibility::ATTENDANCE_PENDING,
                    __("This student's attendance/evaluation is pending."),
                ),
                default => QueueEligibility::blocked(
                    QueueEligibility::ALREADY_WAITING,
                    __('This student is already in the waiting queue.'),
                ),
            };
        }

        // A live session with no open queue row behind it — started from
        // another day's line, or by an admin — still means they are training.
        if ($live) {
            return QueueEligibility::blocked(
                QueueEligibility::IN_TRAINING,
                __('This student is currently in training.'),
            );
        }

        $eligibleAt = $lastFinishedAt?->copy()->addHours($this->cooldownHours());

        // Rolling, to the second: 11h59m is refused and 12h00m is not. now()
        // is never "past" its equal, so gte is the boundary the brief asks for.
        if ($eligibleAt && now()->lt($eligibleAt)) {
            return QueueEligibility::blocked(
                QueueEligibility::COOLING_DOWN,
                __('This student can be added again at :time.', [
                    'time' => $eligibleAt->copy()->timezone(config('app.timezone'))->format('H:i'),
                ]),
                $eligibleAt,
            );
        }

        return QueueEligibility::allowed();
    }

    /** Whether this teacher is the one responsible for the student that day. */
    private function owns(int $instructorId, Student $student, string $date): bool
    {
        return Student::query()
            ->whereKey($student->id)
            ->ownedByInstructorOn($instructorId, $date)
            ->exists();
    }

    /**
     * THE authoritative "this student has finished their previous training".
     *
     * training_evaluations.evaluated_at, and only where the student was marked
     * present. See the class docblock on why this timestamp and not another:
     * the evaluation is the single moment the application declares a student
     * completed, it is written in the same transaction as that day's attendance
     * row, it exists exactly once per completed session and never for a
     * cancelled one, and it is a real datetime — attendance_date is a DATE with
     * no time in it at all, so a rolling twelve-hour rule cannot be built on it.
     */
    private function lastFinishedAt(int $studentId): ?Carbon
    {
        $at = TrainingEvaluation::query()
            ->where('student_id', $studentId)
            ->where('attendance_status', 'present')
            ->max('evaluated_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array<int, Carbon>
     */
    private function lastFinishedFor(array $studentIds): array
    {
        return TrainingEvaluation::query()
            ->whereIn('student_id', $studentIds)
            ->where('attendance_status', 'present')
            ->selectRaw('student_id, max(evaluated_at) as finished_at')
            ->groupBy('student_id')
            ->pluck('finished_at', 'student_id')
            ->map(fn ($at) => Carbon::parse($at))
            ->all();
    }
}
