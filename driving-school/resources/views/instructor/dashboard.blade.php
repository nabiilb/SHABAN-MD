@extends('layouts.app')

@section('title', __('My Dashboard'))
@section('heading', __('My Dashboard'))
@section('subheading', $instructor->full_name.' · '.$instructor->instructor_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
{{--
    Everything on this page is scoped to the signed-in instructor. No company
    income, expenses, profit or debt figures appear anywhere.
--}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card :label="__('My Students')" :value="$metrics['my_students']" icon="users" tone="brand"
                 :href="route('instructor.students.index')" />
    <x-stat-card :label="__('Present Today')" :value="$metrics['present_today']" icon="check" tone="green" />
    <x-stat-card :label="__('Absent Today')" :value="$metrics['absent_today']" icon="alert" tone="rose" />
    <x-stat-card :label="__('Lessons Today')" :value="$metrics['lessons_today']" icon="book" tone="violet"
                 :href="route('instructor.lessons.index')" />
</div>

<div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <x-stat-card :label="__('Near Completion')" :value="$metrics['near_completion']" icon="trend" tone="amber" />
    <x-stat-card :label="__('My Vehicles')" :value="$metrics['my_vehicles']" icon="car" tone="slate"
                 :href="route('instructor.vehicles.index')" />
    <x-stat-card :label="__('My Outstanding Loan/Credit')" :value="$c.number_format($metrics['outstanding_loan'], 2)"
                 icon="money" tone="amber" :href="route('instructor.loans.index')"
                 :hint="__('Total issued: :amount', ['amount' => $c.number_format($metrics['total_loan'], 2)])" />
</div>

<div class="mt-6 flex flex-wrap gap-2">
    <a href="{{ route('instructor.attendance.create') }}" class="btn-primary">
        <x-icon name="check" class="h-4 w-4" /> {{ __('Check In a Student') }}
    </a>
    <a href="{{ route('instructor.lessons.create') }}" class="btn-secondary">
        <x-icon name="plus" class="h-4 w-4" /> {{ __('Add Lesson') }}
    </a>
    <a href="{{ route('instructor.transfers.create') }}" class="btn-secondary">
        <x-icon name="swap" class="h-4 w-4" /> {{ __('Transfer Student') }}
    </a>
</div>

{{-- MY STUDENTS --}}
<div class="card mt-6">
    <div class="card-header">
        <h3 class="card-title">{{ __('My Students') }}</h3>
        <a href="{{ route('instructor.students.index') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Student') }}</th>
                    <th>{{ __('Start Date') }}</th>
                    <th class="text-center">{{ __('Completed') }}</th>
                    <th class="text-center">{{ __('Remaining') }}</th>
                    <th class="w-48">{{ __('Progress') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    <tr>
                        <td>
                            <a href="{{ route('instructor.students.show', $student) }}" class="font-semibold text-brand-700 hover:underline">
                                {{ $student->full_name }}
                            </a>
                            <p class="text-xs text-slate-400">{{ $student->student_number }} · {{ $student->phone }}</p>
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $student->start_date?->format('d/m/Y') }}</td>
                        <td class="text-center font-semibold">{{ $student->completed_days }}</td>
                        <td class="text-center">{{ $student->remaining_days }}</td>
                        <td><x-progress-bar :value="$student->progress_percentage" /></td>
                        <td><x-status-badge :status="$student->status" /></td>
                    </tr>
                @empty
                    <x-empty-state colspan="6" :message="__('No students are currently assigned to you.')" />
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    {{-- TODAY'S ATTENDANCE --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __("Today's My Attendance") }}</h3>
            <a href="{{ route('instructor.attendance.create') }}" class="btn-primary btn-sm">{{ __('Check In') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Student') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($todaysAttendance as $record)
                        <tr>
                            <td class="font-medium">{{ $record->student?->full_name }}</td>
                            <td class="text-sm">{{ $record->check_in_time ? substr($record->check_in_time, 0, 5) : '—' }}</td>
                            <td><x-status-badge :status="$record->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" :message="__('No check-ins recorded today.')" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- RECENT LESSONS --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('My Recent Lessons') }}</h3>
            <a href="{{ route('instructor.lessons.index') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Lesson Type') }}</th><th>{{ __('Performance') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($recentLessons as $lesson)
                        <tr>
                            <td class="whitespace-nowrap text-xs">{{ $lesson->lesson_date?->format('d/m/Y') }}</td>
                            <td class="text-sm font-medium">{{ $lesson->student?->full_name }}</td>
                            <td class="text-sm">{{ $lesson->lessonTopic?->display_name }}</td>
                            <td>
                                @if ($lesson->performance)
                                    <x-status-badge :status="$lesson->performance" />
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- MY VEHICLES --}}
<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('My Vehicles') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Vehicle') }}</th><th>{{ __('Plate') }}</th><th>{{ __('Mileage') }}</th><th>{{ __('Status') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($vehicles as $vehicle)
                        <tr>
                            <td>
                                <a href="{{ route('instructor.vehicles.show', $vehicle) }}" class="font-semibold text-brand-700 hover:underline">
                                    {{ $vehicle->label }}
                                </a>
                                <p class="text-xs text-slate-400">{{ $vehicle->vehicle_number }}</p>
                            </td>
                            <td class="font-mono text-sm">{{ $vehicle->plate_number }}</td>
                            <td class="text-sm">{{ number_format($vehicle->mileage) }} km</td>
                            <td><x-status-badge :status="$vehicle->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" :message="__('No vehicles are assigned to you.')" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card p-5">
        <h3 class="card-title">{{ __('Attendance Trend') }}</h3>
        <p class="mb-4 text-xs text-slate-400">{{ __('My check-ins, last 14 days') }}</p>
        <div class="h-48">
            <canvas data-chart="attendance" data-label="{{ __('Present') }}" data-series='@json($attendanceTrend)'></canvas>
        </div>
    </div>
</div>
@endsection
