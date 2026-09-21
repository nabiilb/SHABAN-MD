@extends('layouts.app')
@section('title', __('My Progress'))
@section('heading', __('My Progress'))

@section('content')
<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Required Days')" :value="$metrics['required_days']" icon="clock" tone="slate" />
    <x-stat-card :label="__('Completed Days')" :value="$metrics['completed_days']" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining Days')" :value="$metrics['remaining_days']" icon="clock" tone="amber" />
    <x-stat-card :label="__('Progress')" :value="$metrics['progress'].'%'" icon="trend" tone="brand" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="card p-5 lg:col-span-2">
        <h3 class="card-title">{{ __('My Attendance') }}</h3>
        <p class="mb-4 text-xs text-slate-400">{{ __('Last 21 days') }}</p>
        <div class="h-56">
            <canvas data-chart="attendance" data-label="{{ __('Present') }}" data-series='@json($attendanceTrend)'></canvas>
        </div>
    </div>

    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Overall') }}</h3>
        <x-progress-bar :value="$metrics['progress']" />
        <dl class="mt-5 space-y-3 text-sm">
            @foreach ([
                __('Present Days') => $metrics['present_days'],
                __('Absent Days') => $metrics['absent_days'],
                __('Lessons Taken') => $metrics['lessons'],
                __('Current Instructor') => $student->currentInstructor?->full_name ?? '—',
                __('Completion Date') => $student->completion_date?->format('d/m/Y') ?? '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>

<div class="card mt-6">
    <div class="card-header"><h3 class="card-title">{{ __('Lessons by Type') }}</h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Lesson Type') }}</th><th class="text-right">{{ __('Lessons') }}</th></tr></thead>
            <tbody>
                @forelse ($topicBreakdown as $row)
                    <tr>
                        <td class="text-sm font-medium">{{ $row->lessonTopic?->display_name }}</td>
                        <td class="text-right font-semibold">{{ $row->total }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="2" />
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
