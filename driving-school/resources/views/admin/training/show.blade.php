@extends('layouts.app')

@section('title', __('Training Session'))
@section('heading', $session->student?->full_name)
@section('subheading', $session->started_at?->format('d/m/Y g:i A'))

@section('content')
<div class="mb-5"><a href="{{ route('admin.training.history') }}" class="btn-secondary btn-sm">← {{ __('Training History') }}</a></div>

<div class="grid gap-4 lg:grid-cols-2">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Session') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Student') => $session->student?->full_name,
                __('Teacher') => $session->instructor?->full_name,
                __('Lesson') => $session->lessonTopic?->display_name ?? '—',
                __('Vehicle') => $session->vehicle?->plate_number ?? '—',
                __('Assigned Duration') => $session->assigned_duration_minutes.' '.__('minutes'),
                __('Extended') => $session->extended_minutes ? '+'.$session->extended_minutes.' '.__('min') : '—',
                __('Started') => $session->started_at?->format('d/m/Y g:i A'),
                __('Expected End') => $session->expected_end_at?->format('g:i A'),
                __('Actually Ended') => $session->ended_at?->format('g:i A') ?? '—',
                __('Actual Duration') => $session->actual_minutes ? $session->actual_minutes.' '.__('min') : '—',
                __('Ended Early') => $session->ended_early ? __('Yes') : __('No'),
                __('Started By') => $session->starter?->name ?? '—',
                __('Ended By') => $session->ender?->name ?? '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd><span class="badge-blue">{{ $session->status_label }}</span></dd>
            </div>
        </dl>
    </div>

    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Attendance & Evaluation') }}</h3>
        @if ($session->evaluation)
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ __('Attendance') }}</dt>
                    <dd><x-status-badge :status="$session->evaluation->attendance_status" /></dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ __('Evaluation') }}</dt>
                    <dd>
                        @if ($session->evaluation->evaluation)
                            <x-status-badge :status="$session->evaluation->evaluation" />
                        @else — @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ __('Evaluated At') }}</dt>
                    <dd class="font-medium text-slate-800">{{ $session->evaluation->evaluated_at?->format('d/m/Y g:i A') }}</dd>
                </div>
            </dl>
            @if ($session->evaluation->comment)
                <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
                    {{ $session->evaluation->comment }}
                </div>
            @endif
        @else
            <p class="py-8 text-center text-sm text-slate-400">{{ __('Not evaluated yet.') }}</p>
        @endif
    </div>
</div>
@endsection
