@extends('layouts.app')
@section('title', __('Dashboard'))
@section('heading', __('Welcome, :name', ['name' => $student->full_name]))
@section('subheading', $student->student_number)

@section('content')
<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Required Days')" :value="$metrics['required_days']" icon="clock" tone="slate" />
    <x-stat-card :label="__('Completed Days')" :value="$metrics['completed_days']" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining Days')" :value="$metrics['remaining_days']" icon="clock" tone="amber" />
    <x-stat-card :label="__('Progress')" :value="$metrics['progress'].'%'" icon="trend" tone="brand" />
</div>

<div class="card mt-6 p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="card-title">{{ __('My Progress') }}</h3>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('Current instructor: :name', ['name' => $student->currentInstructor?->full_name ?? __('Unassigned')]) }}
                · {{ __('Started :date', ['date' => $student->start_date?->format('d/m/Y')]) }}
            </p>
        </div>
        <x-status-badge :status="$student->status" />
    </div>
    <x-progress-bar :value="$metrics['progress']" class="mt-4" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('Recent Attendance') }}</h3>
            <a href="{{ route('student.attendance') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                    @forelse ($attendance as $record)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $record->attendance_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $record->check_in_time ? substr($record->check_in_time, 0, 5) : '—' }}</td>
                            <td><x-status-badge :status="$record->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('Recent Lessons') }}</h3>
            <a href="{{ route('student.lessons') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
        </div>
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
</div>
@endsection
