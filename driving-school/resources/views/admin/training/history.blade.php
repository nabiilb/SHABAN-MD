@extends('layouts.app')

@section('title', __('Training History'))
@section('heading', __('Training History'))

@section('content')
<div class="mb-5"><a href="{{ route('admin.training.index') }}" class="btn-secondary btn-sm">← {{ __('Training Board') }}</a></div>

<div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-stat-card :label="__('Completed Today')" :value="$stats['completed_today']" icon="check" tone="green" />
    <x-stat-card :label="__('Currently Training')" :value="$stats['in_training']" icon="clock" tone="brand" />
    <x-stat-card :label="__('Currently Waiting')" :value="$stats['waiting']" icon="users" tone="amber" />
    <x-stat-card :label="__('Average Duration')"
                 :value="$stats['average_minutes'] ? $stats['average_minutes'].' '.__('min') : '—'" icon="trend" tone="slate" />
</div>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <select name="student_id" class="input">
            <option value="">{{ __('All students') }}</option>
            @foreach ($students as $student)
                <option value="{{ $student->id }}" @selected(request('student_id') == $student->id)>{{ $student->full_name }}</option>
            @endforeach
        </select>
        <select name="instructor_id" class="input">
            <option value="">{{ __('All teachers') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <select name="evaluation" class="input">
            <option value="">{{ __('All evaluations') }}</option>
            @foreach ($ratings as $rating)
                <option value="{{ $rating }}" @selected(request('evaluation') === $rating)>{{ __(ucwords(str_replace('_', ' ', $rating))) }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\TrainingSession::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucwords(str_replace('_', ' ', $status))) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2 lg:col-span-6">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.training.history') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Teacher') }}</th>
                    <th>{{ __('Lesson') }}</th><th>{{ __('Evaluation') }}</th><th>{{ __('Duration') }}</th>
                    <th>{{ __('Time') }}</th><th>{{ __('Status') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $session->started_at?->format('d/m/Y') }}</td>
                        <td>
                            <a href="{{ route('admin.training.show', $session) }}" class="font-medium text-brand-700 hover:underline">
                                {{ $session->student?->full_name }}
                            </a>
                        </td>
                        <td class="text-sm">{{ $session->instructor?->full_name }}</td>
                        <td class="text-sm">{{ $session->lessonTopic?->display_name ?? '—' }}</td>
                        <td>
                            @if ($session->evaluation?->evaluation)
                                <x-status-badge :status="$session->evaluation->evaluation" />
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-sm">
                            {{ $session->actual_minutes ?? $session->total_minutes }} {{ __('min') }}
                        </td>
                        <td class="whitespace-nowrap text-xs text-slate-500">
                            {{ $session->started_at?->format('g:i A') }}
                            @if ($session->ended_at) – {{ $session->ended_at->format('g:i A') }} @endif
                        </td>
                        <td><span class="badge-slate">{{ $session->status_label }}</span></td>
                    </tr>
                @empty
                    <x-empty-state colspan="8" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($sessions->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $sessions->links() }}</div>@endif
</div>
@endsection
