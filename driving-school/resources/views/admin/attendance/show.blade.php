@extends('layouts.app')
@section('title', __('Attendance'))
@section('heading', __('Attendance Record'))

@section('content')
<div class="card max-w-xl p-5">
    <dl class="space-y-3 text-sm">
        @foreach ([
            __('Student') => $attendance->student?->full_name,
            __('Instructor') => $attendance->instructor?->full_name,
            __('Date') => $attendance->attendance_date?->format('d/m/Y'),
            __('Check-in') => $attendance->check_in_time ? substr($attendance->check_in_time, 0, 5) : '—',
            __('Recorded By') => $attendance->recorder?->name ?? '—',
            __('Notes') => $attendance->notes ?: '—',
        ] as $label => $value)
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ $label }}</dt>
                <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
            </div>
        @endforeach
        <div class="flex justify-between gap-3">
            <dt class="text-slate-500">{{ __('Status') }}</dt>
            <dd><x-status-badge :status="$attendance->status" /></dd>
        </div>
    </dl>
    <div class="mt-5 flex gap-2">
        <a href="{{ route('admin.attendance.edit', $attendance) }}" class="btn-primary btn-sm">{{ __('Edit') }}</a>
        <a href="{{ route('admin.attendance.index') }}" class="btn-secondary btn-sm">{{ __('Back') }}</a>
    </div>
</div>
@endsection
