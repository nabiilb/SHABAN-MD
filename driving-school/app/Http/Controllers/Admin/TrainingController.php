<?php

namespace App\Http\Controllers\Admin;

use App\Events\TrainingBoardChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\TrainingQueueEntryRequest;
use App\Models\Instructor;
use App\Models\Student;
use App\Models\TrainingEvaluation;
use App\Models\TrainingQueueEntry;
use App\Models\TrainingSession;
use App\Services\TrainingBoardService;
use App\Services\TrainingQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The admin's overview: what is happening now, who is waiting, who has
 * finished, and the history behind it.
 */
class TrainingController extends Controller
{
    public function __construct(
        private readonly TrainingBoardService $board,
        private readonly TrainingQueueService $queue,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainingSession::class);

        return view('admin.training.index', [
            'board' => $this->board->board(null, $request->input('date')),
            'completed' => $this->board->completedToday(null, $request->input('date')),
            'breakdown' => $this->board->evaluationBreakdown($request->input('date')),
            'liveSessions' => TrainingSession::query()
                ->live()
                ->with(['student', 'instructor'])
                ->orderBy('started_at')
                ->get(),
        ]);
    }

    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingSession::class);

        return response()->json($this->board->board(null, $request->input('date')));
    }

    /* ----------------------------------------------------------------
     | Queue management
     | ---------------------------------------------------------------- */

    public function queue(Request $request): View
    {
        $this->authorize('viewAny', TrainingQueueEntry::class);

        $date = $request->input('date', today()->toDateString());

        $entries = TrainingQueueEntry::forDate($date)
            ->with(['student', 'preferredInstructor'])
            ->ordered()
            ->get();

        // Queue numbers are counted over the students still waiting, not read
        // off the stored `position` — that is only an ordering key, and once a
        // student finishes or starts training it no longer describes a place in
        // the line (two rows can quite legitimately hold the same value).
        $livePositions = $entries
            ->where('status', TrainingQueueEntry::WAITING)
            ->values()
            ->mapWithKeys(fn (TrainingQueueEntry $entry, int $index) => [$entry->id => $index + 1]);

        return view('admin.training.queue', [
            'date' => $date,
            'entries' => $entries,
            'livePositions' => $livePositions,
            'students' => Student::where('status', 'active')->orderBy('full_name')->get(['id', 'full_name', 'student_number']),
            'instructors' => Instructor::active()->orderBy('full_name')->get(['id', 'full_name']),
            'durations' => TrainingSession::DURATION_OPTIONS,
        ]);
    }

    public function storeQueueEntry(TrainingQueueEntryRequest $request): RedirectResponse
    {
        $student = Student::findOrFail($request->integer('student_id'));

        try {
            $this->queue->add($student, $request->user(), $request->validated(), $request->input('date'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['student_id' => $e->getMessage()]);
        }

        event(new TrainingBoardChanged('queue.added'));

        return back()->with('status', __(':name added to the queue.', ['name' => $student->full_name]));
    }

    public function destroyQueueEntry(Request $request, TrainingQueueEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);

        try {
            $this->queue->remove($entry, $request->user(), $request->input('reason'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['queue' => $e->getMessage()]);
        }

        return back()->with('status', __('Student removed from the queue.'));
    }

    /** Moves one entry up, down or to an explicit position. */
    public function moveQueueEntry(Request $request, TrainingQueueEntry $entry): RedirectResponse
    {
        $this->authorize('update', $entry);

        $data = $request->validate([
            'direction' => ['nullable', 'in:up,down'],
            'position' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $target = match ($data['direction'] ?? null) {
            'up' => max(1, $entry->position - 1),
            'down' => $entry->position + 1,
            default => $data['position'] ?? $entry->position,
        };

        $this->queue->moveTo($entry, $target, $request->user());

        return back()->with('status', __('Queue updated.'));
    }

    /* ----------------------------------------------------------------
     | History
     | ---------------------------------------------------------------- */

    public function history(Request $request): View
    {
        $this->authorize('viewAny', TrainingSession::class);

        $sessions = TrainingSession::query()
            ->with(['student', 'instructor', 'evaluation', 'lessonTopic'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('instructor_id', $request->integer('instructor_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('started_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('started_at', '<=', $request->date('date_to')))
            ->when($request->filled('evaluation'), fn ($q) => $q->whereHas('evaluation',
                fn ($e) => $e->where('evaluation', $request->string('evaluation'))))
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.training.history', [
            'sessions' => $sessions,
            'students' => Student::orderBy('full_name')->get(['id', 'full_name']),
            'instructors' => Instructor::orderBy('full_name')->get(['id', 'full_name']),
            'ratings' => TrainingEvaluation::RATINGS,
            'stats' => $this->board->stats($request->input('date_from') ?: today()),
            'breakdown' => $this->board->evaluationBreakdown($request->input('date_from') ?: today()),
        ]);
    }

    public function show(TrainingSession $session): View
    {
        $this->authorize('view', $session);

        return view('admin.training.show', [
            'session' => $session->load(['student', 'instructor', 'evaluation', 'lessonTopic', 'vehicle', 'starter', 'ender']),
        ]);
    }
}
