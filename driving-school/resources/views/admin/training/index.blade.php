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

    {{-- EVERY TEACHER'S QUEUE ------------------------------------------ --}}
    <div class="mt-6 space-y-4">
        <template x-for="teacher in board.teachers" :key="teacher.instructor_id">
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">👨‍🏫 {{ __('Teacher') }}: <span x-text="teacher.instructor"></span></h3>
                    <div class="flex items-center gap-2">
                        <span class="badge-green" x-show="teacher.current">{{ __('Training') }}</span>
                        <span class="badge-amber">
                            <span x-text="teacher.queue_total"></span> {{ __('waiting') }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-5 p-5 lg:grid-cols-2">
                    {{-- Current training for this teacher --}}
                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Current Training') }}</p>

                        <template x-if="teacher.current">
                            <div class="rounded-xl border p-4"
                                 :class="teacher.current.is_overdue ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-emerald-50'">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-lg font-bold text-slate-900" x-text="teacher.current.student"></p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            <span x-text="teacher.current.status_label"></span>
                                            <template x-if="teacher.current.lesson">
                                                <span> · {{ __('Lesson') }}: <span x-text="teacher.current.lesson"></span></span>
                                            </template>
                                        </p>
                                        <p class="mt-1">
                                            <span class="badge-blue" x-show="teacher.current.ownership === 'transferred'">{{ __('Transferred') }}</span>
                                            <span class="badge-slate" x-show="teacher.current.ownership === 'permanent'">{{ __('Permanent') }}</span>
                                            <span class="badge-amber" x-show="teacher.current.ownership === 'unassigned'">{{ __('Unassigned') }}</span>
                                        </p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">⏱️ {{ __('Remaining') }}</p>
                                        <p class="font-mono text-2xl font-bold tabular-nums text-slate-900"
                                           x-text="clockFor(teacher.current)">00:00</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-xs text-slate-500">
                                    <span x-text="teacher.current.started_at_human"></span>
                                    → <span x-text="teacher.current.expected_end_at_human"></span>
                                    (<span x-text="teacher.current.total_minutes"></span> {{ __('minutes') }})
                                </p>
                            </div>
                        </template>

                        <template x-if="! teacher.current">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-400">
                                {{ __('No training in progress.') }}
                            </div>
                        </template>
                    </div>

                    {{-- That teacher's waiting line --}}
                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Waiting Queue') }}</p>
                        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                            <template x-for="item in (teacher.queue || [])" :key="item.id">
                                <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-800">
                                            <span class="text-slate-400" x-text="`#${item.display_position}`"></span>
                                            <span x-text="item.student"></span>
                                        </p>
                                        <p class="text-xs text-slate-400">
                                            <span x-text="item.waiting_minutes"></span> {{ __('min waiting') }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide"
                                          :class="item.ownership === 'permanent' ? 'text-slate-400' : 'text-brand-600'"
                                          x-text="item.ownership_label"></span>
                                </li>
                            </template>
                            <template x-if="! (teacher.queue || []).length">
                                <li class="px-4 py-6 text-center text-sm text-slate-400">{{ __('Nobody is waiting.') }}</li>
                            </template>
                        </ul>
                        <template x-if="teacher.queue_overflow > 0">
                            <p class="mt-2 text-xs text-slate-500">
                                +<span x-text="teacher.queue_overflow"></span> {{ __('more waiting') }}
                            </p>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="! board.teachers || ! board.teachers.length">
            <div class="card p-10 text-center text-sm text-slate-400">{{ __('No active teachers.') }}</div>
        </template>
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
