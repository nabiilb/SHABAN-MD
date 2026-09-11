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
    <template x-if="board.current">
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
                    <p class="mt-1 text-sm text-slate-500">
                        <span x-text="board.current.student_number"></span>
                        · {{ __('Teacher') }}: <span x-text="board.current.instructor"></span>
                        <template x-if="board.current.lesson">
                            <span> · {{ __('Lesson') }}: <span x-text="board.current.lesson"></span></span>
                        </template>
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
    <template x-if="! board.current">
        <div class="card p-6">
            <h3 class="card-title">{{ __('No training in progress') }}</h3>

            <template x-if="board.queue.length">
                <form method="POST" action="{{ route('instructor.training.start') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @csrf
                    <div class="sm:col-span-2">
                        <label class="label">{{ __('Student') }}</label>
                        <select name="training_queue_entry_id" class="input" required>
                            <template x-for="item in board.queue" :key="item.id">
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

            <template x-if="! board.queue.length">
                <p class="mt-2 text-sm text-slate-500">{{ __('The waiting queue is empty.') }}</p>
            </template>
        </div>
    </template>

    {{-- QUEUE + COMPLETED ------------------------------------------------ --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="card lg:col-span-1">
            <div class="card-header">
                <h3 class="card-title">🟡 {{ __('Waiting Queue') }}</h3>
                <span class="badge-amber" x-text="board.queue_total"></span>
            </div>
            <ul class="divide-y divide-slate-100">
                <template x-for="item in board.queue" :key="item.id">
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-800">
                                <span class="text-slate-400" x-text="`#${item.display_position}`"></span>
                                <span x-text="item.student"></span>
                            </p>
                            <p class="text-xs text-slate-400">
                                <span x-text="item.waiting_minutes"></span> {{ __('min waiting') }}
                            </p>
                        </div>
                        <span class="badge-amber" x-text="item.status_label"></span>
                    </li>
                </template>
                <template x-if="! board.queue.length">
                    <li class="px-5 py-8 text-center text-sm text-slate-400">{{ __('Nobody is waiting.') }}</li>
                </template>
            </ul>
            <template x-if="board.queue_overflow > 0">
                <div class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">
                    <span x-text="board.queue_overflow"></span> {{ __('more waiting') }}
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
