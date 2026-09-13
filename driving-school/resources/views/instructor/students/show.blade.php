@extends('layouts.app')
@section('title', $student->full_name)
@section('heading', $student->full_name)
@section('subheading', $student->student_number)

@section('content')
<div class="mb-5 flex flex-wrap gap-2">
    <a href="{{ route('instructor.attendance.create', ['search' => $student->full_name]) }}" class="btn-primary">{{ __('Check In') }}</a>
    <a href="{{ route('instructor.lessons.create', ['student_id' => $student->id]) }}" class="btn-secondary">{{ __('Add Lesson') }}</a>
    <a href="{{ route('instructor.transfers.create', ['student_id' => $student->id]) }}" class="btn-secondary">{{ __('Transfer') }}</a>
</div>

<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Required Days')" :value="$student->required_training_days" icon="clock" tone="slate" />
    <x-stat-card :label="__('Completed Days')" :value="$student->completed_days" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining Days')" :value="$student->remaining_days" icon="clock" tone="amber" />
    <x-stat-card :label="__('Progress')" :value="$student->progress_percentage.'%'" icon="trend" tone="brand" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Profile') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Phone') => $student->phone,
                __('Email') => $student->email ?: '—',
                __('Address') => $student->address ?: '—',
                __('License Type') => $student->license_type ?: '—',
                __('Start Date') => $student->start_date?->format('d/m/Y'),
                __('Completion Date') => $student->completion_date?->format('d/m/Y') ?? '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd><x-status-badge :status="$student->status" /></dd>
            </div>
        </dl>
        <div class="mt-4 border-t border-slate-200 pt-4"><x-progress-bar :value="$student->progress_percentage" /></div>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('Attendance History') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th><th>{{ __('Notes') }}</th></tr></thead>
                <tbody>
                    @forelse ($attendance as $record)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $record->attendance_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $record->check_in_time ? substr($record->check_in_time, 0, 5) : '—' }}</td>
                            <td><x-status-badge :status="$record->status" /></td>
                            <td class="max-w-40 truncate text-sm text-slate-500">{{ $record->notes }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Lesson History') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Lesson Type') }}</th><th>{{ __('Performance') }}</th></tr></thead>
                <tbody>
                    @forelse ($lessons as $lesson)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $lesson->lesson_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $lesson->lessonTopic?->display_name }}</td>
                            <td>@if ($lesson->performance)<x-status-badge :status="$lesson->performance" />@else <span class="text-xs text-slate-400">—</span> @endif</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Instructor History') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Instructor') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th></tr></thead>
                <tbody>
                    @forelse ($assignments as $assignment)
                        <tr>
                            <td class="text-sm font-medium">{{ $assignment->instructor?->full_name }}</td>
                            <td class="whitespace-nowrap text-sm">{{ $assignment->assigned_from?->format('d/m/Y') }}</td>
                            <td class="whitespace-nowrap text-sm">
                                @if ($assignment->is_current)<span class="badge-green">{{ __('Current') }}</span>
                                @else {{ $assignment->assigned_to?->format('d/m/Y') ?? '—' }} @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
