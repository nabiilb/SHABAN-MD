@extends('layouts.app')

@section('title', $student->full_name)
@section('heading', $student->full_name)
@section('subheading', $student->student_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="mb-5 flex flex-wrap gap-2">
    <a href="{{ route('admin.students.edit', $student) }}" class="btn-primary">{{ __('Edit') }}</a>
    <a href="{{ route('admin.attendance.create', ['student_id' => $student->id]) }}" class="btn-secondary">{{ __('Record Attendance') }}</a>
    <a href="{{ route('admin.lessons.create', ['student_id' => $student->id]) }}" class="btn-secondary">{{ __('Add Lesson') }}</a>
    <a href="{{ route('admin.transfers.create', ['student_id' => $student->id]) }}" class="btn-secondary">{{ __('Transfer') }}</a>
    <a href="{{ route('admin.student-payments.create', ['student_id' => $student->id]) }}" class="btn-secondary">{{ __('Record Payment') }}</a>
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
                __('Date of Birth') => $student->date_of_birth?->format('d/m/Y') ?? '—',
                __('Gender') => $student->gender ? __(ucfirst($student->gender)) : '—',
                __('License Type') => $student->license_type ?: '—',
                __('Start Date') => $student->start_date?->format('d/m/Y'),
                __('Completion Date') => $student->completion_date?->format('d/m/Y') ?? '—',
                __('Current Instructor') => $student->currentInstructor?->full_name ?? '—',
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
        <div class="mt-4 border-t border-slate-200 pt-4">
            <x-progress-bar :value="$student->progress_percentage" />
        </div>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('Instructor History') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Instructor') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Reason') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($student->assignments->sortByDesc('assigned_from') as $assignment)
                        <tr>
                            <td class="font-medium">{{ $assignment->instructor?->full_name }}</td>
                            <td class="whitespace-nowrap text-sm">{{ $assignment->assigned_from?->format('d/m/Y') }}</td>
                            <td class="whitespace-nowrap text-sm">
                                @if ($assignment->is_current)
                                    <span class="badge-green">{{ __('Current') }}</span>
                                @else
                                    {{ $assignment->assigned_to?->format('d/m/Y') ?? '—' }}
                                @endif
                            </td>
                            <td class="text-sm text-slate-500">{{ $assignment->reason }}</td>
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
        <div class="card-header"><h3 class="card-title">{{ __('Attendance History') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Instructor') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                    @forelse ($attendance as $record)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $record->attendance_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $record->instructor?->full_name }}</td>
                            <td class="text-sm">{{ $record->check_in_time ? substr($record->check_in_time, 0, 5) : '—' }}</td>
                            <td><x-status-badge :status="$record->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Lesson History') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Lesson Type') }}</th><th>{{ __('Instructor') }}</th><th>{{ __('Performance') }}</th></tr></thead>
                <tbody>
                    @forelse ($lessons as $lesson)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $lesson->lesson_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $lesson->lessonTopic?->display_name }}</td>
                            <td class="text-sm">{{ $lesson->instructor?->full_name }}</td>
                            <td>@if ($lesson->performance)<x-status-badge :status="$lesson->performance" />@else <span class="text-xs text-slate-400">—</span> @endif</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-6">
    <div class="card-header">
        <h3 class="card-title">{{ __('Payments') }}</h3>
        <span class="text-sm text-slate-500">
            {{ __('Paid') }}: <strong class="text-slate-800">{{ $c }}{{ number_format($student->total_paid, 2) }}</strong>
            · {{ __('Balance') }}: <strong class="text-slate-800">{{ $c }}{{ number_format($student->balance, 2) }}</strong>
        </span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td class="font-mono text-xs">{{ $payment->payment_number }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                        <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                        <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="4" />
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
