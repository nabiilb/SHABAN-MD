@extends('layouts.app')

@section('title', __('Training'))
@section('heading', __('Training Console'))
@section('subheading', __('Select a student, run the timer, then evaluate'))

@section('content')
<div x-data="trainingBoard({
        endpoint: '{{ route('instructor.training.board') }}',
        initial: {{ Js::from($board) }},
     })">

    {{-- CURRENT TRAINING ------------------------------------------------ --}}
    <template x-if="board && board.current">
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <h3 class="card-title">
                    <span x-show="board.current.status === 'in_progress'">🟢</span>
                    <span x-show="board.current.status === 'paused'">⏸️</span>
                    <span x-show="board.current.status === 'attendance_pending'">🔵</span>
                    {{ __('Current Training') }}
                </h3>
                <span class="badge-blue" x-text="board.current.status_label"></span>
            </div>

            <div class="grid gap-6 p-5 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <p class="text-3xl font-bold text-slate-900" x-text="board.current.student"></p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <span x-text="board.current.student_number"></span>
                        <span>· {{ __('Teacher') }}: <span x-text="board.current.instructor"></span></span>
                        <template x-if="board.current.lesson">
                            <span>· {{ __('Lesson') }}: <span x-text="board.current.lesson"></span></span>
                        </template>
                        <span class="badge-blue" x-show="board.current.ownership === 'transferred'">{{ __('Transferred') }}</span>
                        <span class="badge-slate" x-show="board.current.ownership === 'permanent'">{{ __('Permanent') }}</span>
                        <span class="badge-amber" x-show="board.current.ownership === 'unassigned'">{{ __('Unassigned') }}</span>
                        <span class="badge-rose" x-show="board.current.carried_over">
                            {{ __('Started') }} <span x-text="board.current.started_on"></span>
                        </span>
                    </p>

                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-slate-500">{{ __('Duration') }}</dt>
                            <dd class="font-semibold text-slate-800">
                                <span x-text="board.current.total_minutes"></span> {{ __('minutes') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ __('Started') }}</dt>
                            <dd class="font-semibold text-slate-800" x-text="board.current.started_at_human"></dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ __('Expected End') }}</dt>
                            <dd class="font-semibold text-slate-800" x-text="board.current.expected_end_at_human"></dd>
                        </div>
                    </dl>
                </div>

                {{-- The countdown, rendered from the server's expected end. --}}
                <div class="flex flex-col items-center justify-center rounded-xl border p-5"
                     :class="isUrgent ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50'">
                    <p class="text-xs font-semibold uppercase tracking-wide"
                       :class="isUrgent ? 'text-rose-600' : 'text-slate-500'">⏱️ {{ __('Remaining') }}</p>
                    <p class="mt-1 font-mono text-5xl font-bold tabular-nums"
                       :class="isUrgent ? 'text-rose-700' : 'text-slate-900'" x-text="clock">00:00</p>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full transition-all duration-1000"
                             :class="isUrgent ? 'bg-rose-500' : 'bg-brand-500'"
                             :style="`width: ${elapsedPercent}%`"></div>
                    </div>
                </div>
            </div>

            {{-- Controls while the session is running --}}
            <template x-if="board.current.carried_over">
                <div class="border-t border-rose-200 bg-rose-50 px-5 py-3 text-sm text-rose-800">
                    {{ __('This session was started on') }} <span class="font-semibold" x-text="board.current.started_on"></span>
                    {{ __('and is still open. Finish and evaluate it, or cancel it — until then no new student can be started.') }}
                    <form method="POST" :action="`{{ url('instructor/training') }}/${board.current.id}/cancel`" class="mt-2">
                        @csrf
                        <input type="hidden" name="reason" value="{{ __('Left open from an earlier day') }}">
                        <button class="btn-ghost btn-sm text-rose-700 hover:bg-rose-100"
                                onclick="return confirm('{{ __('Cancel this session? The student goes back to the waiting queue and nothing is recorded as trained.') }}')">
                            {{ __('Cancel Session') }}
                        </button>
                    </form>
                </div>
            </template>

            <template x-if="board.current.status === 'in_progress' || board.current.status === 'paused'">
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <form method="POST" :action="`{{ url('instructor/training') }}/${board.current.id}/end`">
                        @csrf
                        <button class="btn-danger">{{ __('End Training') }}</button>
                    </form>

                    <form method="POST" :action="`{{ url('instructor/training') }}/${board.current.id}/extend`" class="flex items-center gap-2">
                        @csrf
                        <select name="minutes" class="input w-auto py-1.5 text-sm">
                            @foreach ([5, 10, 15, 30] as $minutes)
                                <option value="{{ $minutes }}">+{{ $minutes }} {{ __('min') }}</option>
                            @endforeach
                        </select>
                        <button class="btn-secondary">{{ __('Extend Time') }}</button>
                    </form>

                    <template x-if="board.current.status === 'in_progress'">
                        <form method="POST" :action="`{{ url('instructor/training') }}/${board.current.id}/pause`">
                            @csrf
                            <button class="btn-secondary">{{ __('Pause Training') }}</button>
                        </form>
                    </template>
                    <template x-if="board.current.status === 'paused'">
                        <form method="POST" :action="`{{ url('instructor/training') }}/${board.current.id}/resume`">
                            @csrf
                            <button class="btn-primary">{{ __('Resume Training') }}</button>
                        </form>
                    </template>
                </div>
            </template>

            {{-- EVALUATION — the step that completes the student ---------- --}}
            <template x-if="board.current.status === 'attendance_pending'">
                <form method="POST" :action="`{{ url('instructor/training') }}/${board.current.id}/evaluate`"
                      class="border-t border-brand-200 bg-brand-50/40 px-5 py-5">
                    @csrf
                    <h4 class="text-base font-bold text-slate-900">{{ __('Attendance & Evaluation') }}</h4>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ __('The student is completed once this is submitted.') }}
                    </p>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="label">{{ __('Attendance') }} <span class="text-rose-500">*</span></label>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach (\App\Models\Attendance::STATUSES as $index => $status)
                                    <label class="flex cursor-pointer items-center justify-center rounded-lg border border-slate-300 bg-white px-2 py-2 text-xs font-semibold
                                                  has-checked:border-brand-500 has-checked:bg-brand-50 has-checked:text-brand-700">
                                        <input type="radio" name="attendance_status" value="{{ $status }}"
                                               class="sr-only" @checked($index === 0)>
                                        {{ __(ucfirst($status)) }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label class="label">{{ __('Evaluation') }} <span class="text-rose-500">*</span></label>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (\App\Models\TrainingEvaluation::RATINGS as $rating)
                                    <label class="flex cursor-pointer items-center justify-center rounded-lg border border-slate-300 bg-white px-2 py-2 text-xs font-semibold
                                                  has-checked:border-emerald-500 has-checked:bg-emerald-50 has-checked:text-emerald-700">
                                        <input type="radio" name="evaluation" value="{{ $rating }}" class="sr-only">
                                        {{ __(ucwords(str_replace('_', ' ', $rating))) }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="lg:col-span-2">
                            <label for="comment" class="label">{{ __('Comment') }}</label>
                            <textarea id="comment" name="comment" rows="2" class="input"
                                      placeholder="{{ __('e.g. Student performed very well during the training.') }}"></textarea>
                        </div>
                    </div>

                    <button class="btn-primary mt-4">{{ __('Submit Evaluation & Complete') }}</button>
                </form>
            </template>
        </div>
    </template>

    {{-- NOTHING RUNNING — start the next student ------------------------- --}}
    <template x-if="board && ! board.current">
        <div class="card p-6">
            <h3 class="card-title">{{ __('No training in progress') }}</h3>

            <template x-if="board.next">
                <p class="mt-1 text-sm text-slate-500">
                    {{ __('Next student') }}: <span class="font-semibold text-slate-800" x-text="board.next"></span>
                </p>
            </template>

            <template x-if="(board.queue || []).length">
                <form method="POST" action="{{ route('instructor.training.start') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @csrf
                    <div class="sm:col-span-2">
                        <label class="label">{{ __('Student') }}</label>
                        <select name="training_queue_entry_id" class="input" required>
                            <template x-for="item in (board.queue || [])" :key="item.id">
                                <option :value="item.id" x-text="`#${item.display_position} — ${item.student}`"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="label">{{ __('Duration') }}</label>
                        <select name="assigned_duration_minutes" class="input" required>
                            @foreach ($durations as $minutes)
                                <option value="{{ $minutes }}" @selected($minutes === 30)>{{ $minutes }} {{ __('minutes') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">{{ __('Lesson') }}</label>
                        <select name="lesson_topic_id" class="input">
                            <option value="">{{ __('Select a lesson') }}</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-4">
                        <button class="btn-primary w-full sm:w-auto">▶ {{ __('Start Training') }}</button>
                    </div>
                </form>
            </template>

            <template x-if="! (board.queue || []).length">
                <p class="mt-2 text-sm text-slate-500">{{ __('No students waiting.') }}</p>
            </template>
        </div>
    </template>

    {{-- QUEUE + COMPLETED ------------------------------------------------ --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        {{-- The queue card carries its own state rather than reaching into the
             board component, so the Add Student dialog keeps working even when
             the compiled bundle is older than this template. --}}
        <div class="card lg:col-span-1"
             x-data="{
                 adding: false,
                 search: '',
                 selectDuration: {{ $defaultDuration }},
                 selectTopic: '',
                 results: [],
                 searching: false,
                 searched: false,
                 picked: null,
                 timer: null,
                 openAdd() { this.adding = true; this.search = ''; this.picked = null; this.results = []; this.searched = false; },
                 /*
                  * Any instructor may train any eligible student, so the dialog
                  * searches the whole school rather than listing one teacher's
                  * students. Typed straight against the server: the list is far
                  * too long to ship to the browser, and the eligibility each row
                  * shows has to be the server's answer, not a guess.
                  */
                 searchStudents() {
                     clearTimeout(this.timer);
                     const term = this.search.trim();
                     this.picked = null;

                     if (term.length < 2) {
                         this.results = []; this.searched = false; this.searching = false;
                         return;
                     }

                     this.timer = setTimeout(async () => {
                         this.searching = true;
                         try {
                             const url = '{{ route('instructor.training.students.search') }}?q=' + encodeURIComponent(term);
                             const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                             this.results = response.ok ? (await response.json()).results : [];
                         } catch (e) {
                             this.results = [];
                         }
                         this.searching = false;
                         this.searched = true;
                     }, 250);
                 },
                 get pickedName() {
                     return (this.results.find((student) => student.id === this.picked) || {}).full_name || '';
                 },
             }">
            <div class="card-header">
                <h3 class="card-title">🟡 {{ __('Waiting Queue') }}</h3>
                <div class="flex items-center gap-2">
                    <span class="badge-amber" x-text="board.queue_total ?? 0"></span>
                    <button type="button" class="btn-ghost btn-sm text-brand-700 hover:bg-brand-50"
                            @click="openAdd()">
                        + {{ __('Add Student') }}
                    </button>
                </div>
            </div>

            {{-- What a one-click Select starts the student on. --}}
            <div class="grid gap-3 border-b border-slate-100 bg-slate-50 px-5 py-3 sm:grid-cols-2">
                <div>
                    <label class="label">{{ __('Duration') }}</label>
                    <select class="input" x-model="selectDuration">
                        @foreach ($durations as $minutes)
                            <option value="{{ $minutes }}">{{ $minutes }} {{ __('minutes') }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">{{ __('Lesson') }}</label>
                    <select class="input" x-model="selectTopic">
                        <option value="">{{ __('Select a lesson') }}</option>
                        @foreach ($topics as $topic)
                            <option value="{{ $topic->id }}">{{ $topic->display_name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- If the page's JavaScript never starts — stale assets, a blocked
                 bundle — the teacher still sees who the database says is
                 waiting, rather than an empty card. Names only: the actions
                 need Alpine anyway, and a second set of buttons in the DOM
                 helps nobody. Alpine hides this on init. --}}
            <ul class="divide-y divide-slate-100" x-show="false">
                @forelse ($board['queue'] as $item)
                    <li class="px-5 py-3">
                        <p class="truncate text-sm font-semibold text-slate-800">
                            <span class="text-slate-400">#{{ $item['display_position'] }}</span>
                            {{ $item['student'] }}
                        </p>
                        <p class="text-xs text-slate-400">
                            {{ $item['status_label'] }} · {{ $item['waiting_minutes'] }} {{ __('min waiting') }}
                        </p>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-sm text-slate-400">{{ __('No students waiting.') }}</li>
                @endforelse
            </ul>

            <ul class="divide-y divide-slate-100">
                <template x-for="item in (board.queue || [])" :key="item.id">
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-800">
                                <span class="text-slate-400" x-text="`#${item.display_position}`"></span>
                                <span x-text="item.student"></span>
                            </p>
                            <p class="text-xs text-slate-400">
                                <span x-text="item.status_label"></span>
                                · <span x-text="item.waiting_minutes"></span> {{ __('min waiting') }}
                                · {{ __('no timer running') }}
                            </p>
                            <p class="text-[10px] font-semibold uppercase tracking-wide"
                               :class="item.mine ? 'text-slate-400' : 'text-brand-600'">
                                <span x-text="item.ownership_label"></span>
                                {{-- With one shared queue a teacher sees other
                                     teachers' students, so say whose. --}}
                                <template x-if="! item.mine && item.owner">
                                    <span>· <span x-text="item.owner"></span></span>
                                </template>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            {{-- The ONLY thing that starts a session. Reaching
                                 the front of the line starts nobody, and
                                 finishing the previous student starts nobody:
                                 a clock begins because somebody pressed this.
                                 The claim is still re-checked and locked
                                 server-side. --}}
                            <form method="POST" action="{{ route('instructor.training.start') }}"
                                  x-data="{ starting: false }"
                                  @submit="starting ? $event.preventDefault() : starting = true">
                                @csrf
                                <input type="hidden" name="training_queue_entry_id" :value="item.id">
                                <input type="hidden" name="assigned_duration_minutes" :value="selectDuration">
                                <input type="hidden" name="lesson_topic_id" :value="selectTopic">
                                <button class="btn-primary btn-sm"
                                        :disabled="!! board.current || starting"
                                        :class="(board.current || starting) ? 'cursor-not-allowed opacity-40' : ''">
                                    <span x-show="! starting">▶ {{ __('Start Training') }}</span>
                                    <span x-show="starting" style="display: none">{{ __('Starting…') }}</span>
                                </button>
                            </form>

                            {{-- Removes them from the line without deleting the
                                 row: the cycle is closed with a reason, no
                                 attendance is written, and no cooldown starts
                                 because no training happened. --}}
                            <template x-if="item.can_remove">
                                <form method="POST" action="/instructor/training/queue/0"
                                      :action="`{{ url('instructor/training/queue') }}/${item.id}`"
                                      @submit="if (! confirm('{{ __('Remove this student from the waiting queue?') }}')) $event.preventDefault()">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">
                                        {{ __('Remove') }}
                                    </button>
                                </form>
                            </template>
                        </div>
                    </li>
                </template>
                <template x-if="! (board.queue || []).length">
                    <li class="px-5 py-8 text-center text-sm text-slate-400">{{ __('No students waiting.') }}</li>
                </template>
            </ul>
            <template x-if="(board.queue_overflow || 0) > 0">
                <div class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">
                    <span x-text="board.queue_overflow"></span> {{ __('more waiting') }}
                </div>
            </template>

    {{-- ADD STUDENT TO QUEUE -------------------------------------------
         Adding only ever puts a student in `waiting`; the timer starts when
         somebody presses Select. The list is the students this teacher may
         take, searched in the browser over data the server already sent. --}}
    <template x-teleport="body">
        <div x-show="adding" x-cloak class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:items-center">
            <div class="absolute inset-0 bg-slate-900/50" @click="adding = false"></div>

            <div x-show="adding" x-transition
                 class="relative w-full max-w-md rounded-xl bg-white shadow-xl"
                 @keydown.escape.window="adding = false">

                <form method="POST" action="{{ route('instructor.training.queue.store') }}">
                    @csrf

                    <div class="border-b border-slate-200 px-5 py-4">
                        <h3 class="text-base font-bold text-slate-900">{{ __('Add Student to Queue') }}</h3>
                        <p class="mt-0.5 text-sm text-slate-500">{{ now()->format('d/m/Y') }}</p>
                    </div>

                    <div class="space-y-4 px-5 py-4">
                        <div>
                            <label for="student-search" class="label">{{ __('Search Student') }}</label>
                            <input id="student-search" type="search" x-model="search" @input="searchStudents()"
                                   autocomplete="off" class="input"
                                   placeholder="{{ __('Search by name, student number or phone…') }}">
                            <p class="mt-1 text-xs text-slate-400">
                                {{ __('Searches every active student in the school. Training a student does not change who they are assigned to.') }}
                            </p>
                        </div>

                        <ul class="max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                            <template x-for="student in results" :key="student.id">
                                <li>
                                    <button type="button" @click="picked = student.id" :disabled="! student.eligible"
                                            class="w-full px-3 py-2 text-left"
                                            :class="! student.eligible
                                                ? 'cursor-not-allowed opacity-50'
                                                : (picked === student.id ? 'bg-brand-50' : 'hover:bg-slate-50')">
                                        <span class="flex items-center justify-between gap-3">
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-semibold text-slate-800"
                                                      x-text="student.full_name"></span>
                                                <span class="block truncate text-xs text-slate-400"
                                                      x-text="`${student.student_number} · ${student.phone ?? ''}`"></span>
                                            </span>
                                            <span class="shrink-0 text-right">
                                                <span class="block text-xs font-semibold text-amber-600"
                                                      x-show="student.blocked_by" x-text="student.blocked_by"
                                                      :title="student.available_in
                                                          ? '{{ __('Available in') }} ' + student.available_in
                                                          : student.blocked_by"></span>
                                                <span class="block text-brand-600"
                                                      x-show="student.eligible && picked === student.id">✓</span>
                                            </span>
                                        </span>
                                        {{-- Whose student they permanently are, and how far through
                                             they are. Named so a teacher knows they are taking
                                             somebody else's student for this session only. --}}
                                        <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                            <span x-show="student.permanent_instructor">
                                                {{ __('Instructor') }}:
                                                <span class="font-semibold" x-text="student.permanent_instructor"></span>
                                            </span>
                                            <span x-show="! student.permanent_instructor" class="text-slate-400">
                                                {{ __('No instructor assigned') }}
                                            </span>
                                            {{-- Both labelled, and neither able to be
                                                 mistaken for the other when the row wraps. --}}
                                            <span x-text="`{{ __('Remaining') }}: ${student.remaining_label}`"></span>
                                            <span x-text="`{{ __('Progress') }}: ${student.progress}%`"></span>
                                            <span class="badge-slate" x-text="student.status_label"></span>
                                        </span>
                                    </button>
                                </li>
                            </template>

                            <template x-if="searching">
                                <li class="px-3 py-6 text-center text-sm text-slate-400">{{ __('Searching…') }}</li>
                            </template>
                            <template x-if="! searching && searched && ! results.length">
                                <li class="px-3 py-6 text-center text-sm text-slate-400">{{ __('No students found.') }}</li>
                            </template>
                            <template x-if="! searching && ! searched">
                                <li class="px-3 py-6 text-center text-sm text-slate-400">
                                    {{ __('Type at least two characters to search.') }}
                                </li>
                            </template>
                        </ul>

                        <input type="hidden" name="student_id" :value="picked">

                        <p class="text-sm text-slate-600">
                            {{ __('Selected') }}:
                            <span class="font-semibold text-slate-900"
                                  x-text="pickedName || '{{ __('None') }}'"></span>
                        </p>

                        <div>
                            <label for="queue-duration" class="label">{{ __('Training Duration') }}</label>
                            <select id="queue-duration" name="assigned_duration_minutes" class="input">
                                @foreach ($durations as $minutes)
                                    <option value="{{ $minutes }}" @selected($minutes === $defaultDuration)>
                                        {{ $minutes }} {{ __('minutes') }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-400">{{ __('The timer starts when the student is selected for training, not now.') }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
                        <button type="button" class="btn-ghost" @click="adding = false">{{ __('Cancel') }}</button>
                        <button class="btn-primary" :disabled="! picked"
                                :class="picked ? '' : 'cursor-not-allowed opacity-40'">
                            {{ __('Add to Queue') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </template>
        </div>

        <div class="card lg:col-span-2">
            <div class="card-header">
                <h3 class="card-title">✅ {{ __('Completed Today') }}</h3>
                <span class="badge-green">{{ $completed->count() }}</span>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>{{ __('Student') }}</th><th>{{ __('Evaluation') }}</th>
                            <th>{{ __('Duration') }}</th><th>{{ __('Time') }}</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($completed as $session)
                            <tr>
                                <td class="font-medium">{{ $session->student?->full_name }}</td>
                                <td>
                                    @if ($session->evaluation?->evaluation)
                                        <x-status-badge :status="$session->evaluation->evaluation" />
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-sm">{{ $session->actual_minutes }} {{ __('min') }}</td>
                                <td class="whitespace-nowrap text-xs text-slate-500">
                                    {{ $session->started_at?->format('g:i A') }} – {{ $session->ended_at?->format('g:i A') }}
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="4" :message="__('No training completed yet today.')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @include('partials.training-toasts')
</div>
@endsection
