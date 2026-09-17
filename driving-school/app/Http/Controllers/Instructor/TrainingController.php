<?php

namespace App\Http\Controllers\Instructor;

use App\Events\TrainingBoardChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddToTrainingQueueRequest;
use App\Http\Requests\EvaluateTrainingRequest;
use App\Http\Requests\StartTrainingRequest;
use App\Models\LessonTopic;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\Vehicle;
use App\Services\TrainingBoardService;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
use App\Services\TrainingStaleCycleService;
use App\Support\QueueEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The teacher's console: one student at a time, a countdown, and the
 * evaluation that completes them.
 */
class TrainingController extends Controller
{
    public function __construct(
        private readonly TrainingBoardService $board,
        private readonly TrainingSessionService $sessions,
        private readonly TrainingQueueService $queue,
        private readonly TrainingStaleCycleService $stale,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainingSession::class);

        $user = $request->user();

        return view('instructor.training.index', [
            'board' => $this->board->board($user),
            'completed' => $this->board->completedToday($user->instructorId()),
            'topics' => LessonTopic::where('is_active', true)->orderBy('sort_order')->get(),
            'vehicles' => Vehicle::visibleTo($user)->orderBy('vehicle_number')->get(),
            'durations' => TrainingSession::DURATION_OPTIONS,
            'defaultDuration' => (int) Setting::get('default_training_minutes', 30),
        ]);
    }

    /**
     * Searches the school for a student to train.
     *
     * Any instructor may train any eligible student, so this searches every
     * active student by number, name or phone — not one teacher's list. Each
     * result says whose student they permanently are and whether they can be
     * taken right now, and the answer is re-asked when Add to Queue is
     * actually submitted.
     */
    public function searchStudents(Request $request): JsonResponse
    {
        $this->authorize('addToQueue', TrainingQueueEntry::class);

        // Stale cycles are closed before eligibility is judged, so a student
        // held by an entry nobody finished yesterday shows as available today.
        $this->stale->expireStale($request->user());

        $students = $this->queue->searchFor((string) $request->query('q', ''));

        return response()->json([
            'results' => $students->map(fn (Student $student) => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'full_name' => $student->full_name,
                'phone' => $student->phone,
                'permanent_instructor' => $student->currentInstructor?->full_name,
                'status_label' => __(ucfirst($student->status)),
                'remaining_days' => $student->remaining_days,
                'progress' => $student->progress_percentage,
                'eligible' => $student->queue_eligibility?->eligible ?? false,
                'blocked_by' => $student->queue_eligibility?->eligible === false
                    ? ($student->queue_eligibility->code === QueueEligibility::COOLING_DOWN
                        ? __('Available at :time', ['time' => $student->queue_eligibility->availableAt()])
                        : $student->queue_eligibility->reason)
                    : null,
                'available_in' => $student->queue_eligibility?->remainingLabel(),
            ])->values(),
        ]);
    }

    /**
     * Puts a student in today's waiting line. Never starts training: the
     * student waits their turn like anybody else.
     *
     * The only thing that ever creates a queue entry for a teacher. Every check
     * is made again here, server-side. The student may belong to any teacher —
     * that is the point — but the rules about the student still hold, and the
     * instructor who will train them is the one in the session, never a value
     * from the browser.
     */
    public function addToQueue(AddToTrainingQueueRequest $request): RedirectResponse
    {
        $student = Student::findOrFail($request->integer('student_id'));
        $instructorId = $request->user()->instructorId() ?? 0;

        $this->stale->expireStale($request->user());

        $verdict = $this->queue->eligibilityFor($student);

        if (! $verdict->eligible) {
            return back()->withErrors(['student_id' => $verdict->reason]);
        }

        try {
            // Re-checked inside the transaction, with the student locked, so a
            // double click cannot slip a second entry past the answer above.
            $this->queue->add($student, $request->user(), $request->queueData(), null, $instructorId);
        } catch (RuntimeException $e) {
            return back()->withErrors(['student_id' => $e->getMessage()]);
        }

        return back()->with('status', __(':name added to the waiting queue.', ['name' => $student->full_name]));
    }

    /**
     * Removes a student from the waiting queue, keeping the row.
     *
     * The cycle is closed, not deleted: the entry stays in the history saying
     * who removed it and why. No attendance, evaluation or lesson is written —
     * the student did not train — and no cooldown is started, so they can be
     * queued again straight away.
     */
    public function removeFromQueue(Request $request, TrainingQueueEntry $entry): RedirectResponse
    {
        $this->authorize('removeFromQueue', $entry);

        try {
            $closed = $this->queue->closeCycle(
                $entry,
                TrainingQueueService::REMOVED_BY_INSTRUCTOR,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['queue' => $e->getMessage()]);
        }

        return back()->with('status', $closed
            ? __(':name was removed from the waiting queue.', ['name' => $entry->student?->full_name])
            : __('That student had already left the waiting queue.'));
    }

    /** Polled by the dashboards so nobody has to press refresh. */
    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingSession::class);

        return response()->json($this->board->board($request->user()));
    }

    /**
     * Starts training for a waiting student — and nothing else ever does.
     *
     * Reaching the front of the queue starts nobody; finishing the previous
     * student starts nobody; polling, refreshing and opening the console start
     * nobody. A session exists because an instructor pressed this button, and
     * started_at is the moment they pressed it.
     */
    public function start(StartTrainingRequest $request): RedirectResponse
    {
        $this->stale->expireStale($request->user());

        $entry = TrainingQueueEntry::findOrFail($request->integer('training_queue_entry_id'));
        $this->authorize('claim', $entry);

        try {
            $session = $this->sessions->start(
                $entry,
                $request->user()->instructor,
                $request->user(),
                $request->options(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['training' => $e->getMessage()]);
        }

        event(new TrainingBoardChanged('training.started', ['session_id' => $session->id]));

        return back()->with('status', __('Training started for :name.', [
            'name' => $session->student?->full_name,
        ]));
    }

    /**
     * Abandons the session — for one left running overnight, or started by
     * mistake. The row is kept; the student goes back to the line.
     */
    public function cancel(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('update', $session);

        try {
            $this->sessions->cancel($session, $request->user(), $request->input('reason'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['training' => $e->getMessage()]);
        }

        event(new TrainingBoardChanged('training.cancelled', ['session_id' => $session->id]));

        return back()->with('status', __('Training session cancelled. :student is back in the waiting queue.', [
            'student' => $session->student?->full_name,
        ]));
    }

    public function end(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('manage', $session);

        $this->sessions->end($session, $request->user());
        event(new TrainingBoardChanged('training.ended', ['session_id' => $session->id]));

        return back()->with('status', __('Training ended. Please record attendance and evaluation.'));
    }

    public function extend(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('manage', $session);

        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:120']]);

        try {
            $this->sessions->extend($session, $data['minutes'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['training' => $e->getMessage()]);
        }

        event(new TrainingBoardChanged('training.extended', ['session_id' => $session->id]));

        return back()->with('status', __('Training extended by :minutes minutes.', ['minutes' => $data['minutes']]));
    }

    public function pause(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('manage', $session);

        try {
            $this->sessions->pause($session, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['training' => $e->getMessage()]);
        }

        event(new TrainingBoardChanged('training.paused', ['session_id' => $session->id]));

        return back()->with('status', __('Training paused.'));
    }

    public function resume(Request $request, TrainingSession $session): RedirectResponse
    {
        $this->authorize('manage', $session);

        try {
            $this->sessions->resume($session, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['training' => $e->getMessage()]);
        }

        event(new TrainingBoardChanged('training.resumed', ['session_id' => $session->id]));

        return back()->with('status', __('Training resumed.'));
    }

    /** The evaluation is what actually completes the student. */
    public function evaluate(EvaluateTrainingRequest $request, TrainingSession $session): RedirectResponse
    {
        try {
            $evaluation = $this->sessions->evaluate($session, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['training' => $e->getMessage()]);
        }

        event(new TrainingBoardChanged('training.evaluated', ['session_id' => $session->id]));

        // Whoever the evaluation pulled in is this teacher's live session now,
        // so report them rather than the student queued behind them.
        $started = TrainingSession::query()
            ->live()
            ->where('instructor_id', $request->user()->instructorId())
            ->with('student')
            ->orderByDesc('started_at')
            ->first();

        return back()->with('status', $started
            ? __(':student completed training. Now training: :next', [
                'student' => $evaluation->student?->full_name,
                'next' => $started->student?->full_name,
            ])
            : __(':student completed training. The queue is now empty.', [
                'student' => $evaluation->student?->full_name,
            ]));
    }
}
