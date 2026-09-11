@extends('layouts.app')

@section('title', __('Training'))
@section('heading', __('Training Board'))
@section('subheading', __('Live view of the training centre'))

@section('content')
<div x-data="trainingBoard({
        endpoint: '{{ route('admin.training.board') }}',
        initial: {{ Js::from($board) }},
     })">

    <div class="mb-5 flex flex-wrap gap-2">
        <a href="{{ route('admin.training.queue') }}" class="btn-primary">{{ __('Manage Queue') }}</a>
        <a href="{{ route('admin.training.history') }}" class="btn-secondary">{{ __('Training History') }}</a>
    </div>

    {{-- Headline numbers --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat-card :label="__('Completed Today')" :value="$board['stats']['completed_today']" icon="check" tone="green" />
        <x-stat-card :label="__('Currently Training')" :value="$board['stats']['in_training']" icon="clock" tone="brand" />
        <x-stat-card :label="__('Awaiting Evaluation')" :value="$board['stats']['awaiting_evaluation']" icon="alert" tone="violet" />
        <x-stat-card :label="__('Currently Waiting')" :value="$board['stats']['waiting']" icon="users" tone="amber" />
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        {{-- CURRENT TRAINING --}}
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h3 class="card-title">{{ __('Current Training') }}</h3>
                <span class="text-xs text-slate-400">{{ __('Updates automatically') }}</span>
            </div>

            <template x-if="board.current">
                <div class="grid gap-5 p-5 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <p class="text-2xl font-bold text-slate-900">
                            👨‍🎓 <span x-text="board.current.student"></span>
                        </p>
                        <p class="mt-1 text-sm">
                            <span x-show="board.current.status === 'in_progress'" class="badge-green">🟢 <span x-text="board.current.status_label"></span></span>
                            <span x-show="board.current.status === 'paused'" class="badge-amber">⏸️ <span x-text="board.current.status_label"></span></span>
                            <span x-show="board.current.status === 'attendance_pending'" class="badge-blue">🔵 <span x-text="board.current.status_label"></span></span>
                        </p>
                        <dl class="mt-4 space-y-2 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">👨‍🏫 {{ __('Teacher') }}</dt>
                                <dd class="font-medium text-slate-800" x-text="board.current.instructor"></dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">⏰ {{ __('Started') }}</dt>
                                <dd class="font-medium text-slate-800" x-text="board.current.started_at_human"></dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">⏰ {{ __('Expected End') }}</dt>
                                <dd class="font-medium text-slate-800" x-text="board.current.expected_end_at_human"></dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">{{ __('Duration') }}</dt>
                                <dd class="font-medium text-slate-800">
                                    <span x-text="board.current.total_minutes"></span> {{ __('minutes') }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex flex-col items-center justify-center rounded-xl border p-4"
                         :class="isUrgent ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50'">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">⏱️ {{ __('Remaining') }}</p>
                        <p class="mt-1 font-mono text-4xl font-bold tabular-nums"
                           :class="isUrgent ? 'text-rose-700' : 'text-slate-900'" x-text="clock">00:00</p>
                    </div>
                </div>
            </template>

            <template x-if="! board.current">
                <div class="p-10 text-center text-sm text-slate-400">{{ __('No training in progress.') }}</div>
            </template>
        </div>

        {{-- WAITING QUEUE --}}
        <div class="card">
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
            <div class="border-t border-slate-200 px-5 py-3">
                <a href="{{ route('admin.training.queue') }}" class="btn-secondary btn-sm w-full">
                    {{ __('View Full Queue') }}
                    <template x-if="board.queue_overflow > 0">
                        <span>(+<span x-text="board.queue_overflow"></span>)</span>
                    </template>
                </a>
            </div>
        </div>
    </div>

    {{-- All live sessions across teachers --}}
    @if ($liveSessions->isNotEmpty())
        <div class="card mt-6">
            <div class="card-header"><h3 class="card-title">{{ __('All Active Sessions') }}</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>{{ __('Student') }}</th><th>{{ __('Teacher') }}</th><th>{{ __('Status') }}</th>
                            <th>{{ __('Started') }}</th><th>{{ __('Expected End') }}</th><th>{{ __('Remaining') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($liveSessions as $session)
                            <tr>
                                <td class="font-medium">
                                    <a href="{{ route('admin.training.show', $session) }}" class="text-brand-700 hover:underline">
                                        {{ $session->student?->full_name }}
                                    </a>
                                </td>
                                <td class="text-sm">{{ $session->instructor?->full_name }}</td>
                                <td><span class="badge-blue">{{ $session->status_label }}</span></td>
                                <td class="whitespace-nowrap text-sm">{{ $session->started_at?->format('g:i A') }}</td>
                                <td class="whitespace-nowrap text-sm">{{ $session->expected_end_at?->format('g:i A') }}</td>
                                <td class="whitespace-nowrap font-mono text-sm">{{ $session->remaining_for_humans }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- COMPLETED + BREAKDOWN --}}
    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h3 class="card-title">✅ {{ __('Completed Today') }}</h3>
                <a href="{{ route('admin.training.history') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>{{ __('Student') }}</th><th>{{ __('Evaluation') }}</th><th>{{ __('Teacher') }}</th>
                            <th>{{ __('Duration') }}</th><th>{{ __('Time') }}</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($completed as $session)
                            <tr>
                                <td class="font-medium">
                                    <a href="{{ route('admin.training.show', $session) }}" class="text-brand-700 hover:underline">
                                        {{ $session->student?->full_name }}
                                    </a>
                                </td>
                                <td>
                                    @if ($session->evaluation?->evaluation)
                                        <x-status-badge :status="$session->evaluation->evaluation" />
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="text-sm">{{ $session->instructor?->full_name }}</td>
                                <td class="whitespace-nowrap text-sm">{{ $session->actual_minutes }} {{ __('min') }}</td>
                                <td class="whitespace-nowrap text-xs text-slate-500">
                                    {{ $session->started_at?->format('g:i A') }} – {{ $session->ended_at?->format('g:i A') }}
                                </td>
                            </tr>
                        @empty
                            <x-empty-state colspan="5" :message="__('No training completed yet today.')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-5">
            <h3 class="card-title mb-4">{{ __('Evaluation Distribution') }}</h3>
            @php $totalRatings = array_sum($breakdown) ?: 1; @endphp
            <ul class="space-y-3">
                @foreach ($breakdown as $rating => $count)
                    <li>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">{{ __(ucwords(str_replace('_', ' ', $rating))) }}</span>
                            <span class="font-semibold text-slate-800">{{ $count }}</span>
                        </div>
                        <x-progress-bar :value="$count / $totalRatings * 100" :show-label="false" class="mt-1" />
                    </li>
                @endforeach
            </ul>
            @if ($board['stats']['average_minutes'])
                <div class="mt-5 border-t border-slate-200 pt-4 text-sm">
                    <span class="text-slate-500">{{ __('Average duration') }}:</span>
                    <strong class="text-slate-800">{{ $board['stats']['average_minutes'] }} {{ __('minutes') }}</strong>
                </div>
            @endif
        </div>
    </div>

    @include('partials.training-toasts')
</div>
@endsection
