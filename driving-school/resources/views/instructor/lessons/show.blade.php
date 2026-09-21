@extends('layouts.app')
@section('title', __('Lesson'))
@section('heading', __('Lesson'))

@section('content')
<div class="card max-w-xl p-5">
    <dl class="space-y-3 text-sm">
        @foreach ([
            __('Student') => $lesson->student?->full_name,
            __('Date') => $lesson->lesson_date?->format('d/m/Y'),
            __('Lesson Type') => $lesson->lessonTopic?->display_name,
            __('Topic') => $lesson->topic ?: '—',
            __('Vehicle') => $lesson->vehicle?->plate_number ?? '—',
            __('Duration') => $lesson->duration_minutes.' '.__('min'),
            __('Notes') => $lesson->notes ?: '—',
        ] as $label => $value)
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ $label }}</dt>
                <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
            </div>
        @endforeach
        <div class="flex justify-between gap-3">
            <dt class="text-slate-500">{{ __('Status') }}</dt>
            <dd><x-status-badge :status="$lesson->status" /></dd>
        </div>
    </dl>
    <div class="mt-5 flex gap-2">
        <a href="{{ route('instructor.lessons.edit', $lesson) }}" class="btn-primary btn-sm">{{ __('Edit') }}</a>
        <a href="{{ route('instructor.lessons.index') }}" class="btn-secondary btn-sm">{{ __('Back') }}</a>
    </div>
</div>
@endsection
