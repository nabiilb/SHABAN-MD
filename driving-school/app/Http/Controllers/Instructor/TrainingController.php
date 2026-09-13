<?php

namespace App\Http\Controllers\Instructor;

use App\Events\TrainingBoardChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\EvaluateTrainingRequest;
use App\Http\Requests\StartTrainingRequest;
use App\Models\LessonTopic;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Models\Vehicle;
use App\Services\TrainingBoardService;
use App\Services\TrainingQueueService;
use App\Services\TrainingSessionService;
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
        ]);
    }

    /** Polled by the dashboards so nobody has to press refresh. */
    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingSession::class);

        return response()->json($this->board->board($request->user()));
    }

    public function start(StartTrainingRequest $request): RedirectResponse
    {
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
