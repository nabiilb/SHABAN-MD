@extends('layouts.app')
@section('title', __('My Lessons'))
@section('heading', __('My Lessons'))

@section('content')
<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-3">
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('student.lessons') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Lesson Type') }}</th><th>{{ __('Topic') }}</th><th>{{ __('Instructor') }}</th>
                <th>{{ __('Vehicle') }}</th><th>{{ __('Duration') }}</th><th>{{ __('Performance') }}</th></tr></thead>
            <tbody>
                @forelse ($lessons as $lesson)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $lesson->lesson_date?->format('d/m/Y') }}</td>
                        <td class="text-sm font-medium">{{ $lesson->lessonTopic?->display_name }}</td>
                        <td class="max-w-40 truncate text-sm text-slate-500">{{ $lesson->topic ?: '—' }}</td>
                        <td class="text-sm">{{ $lesson->instructor?->full_name }}</td>
                        <td class="font-mono text-xs">{{ $lesson->vehicle?->plate_number ?? '—' }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $lesson->duration_minutes }} {{ __('min') }}</td>
                        <td>@if ($lesson->performance)<x-status-badge :status="$lesson->performance" />@else <span class="text-xs text-slate-400">—</span> @endif</td>
                    </tr>
                @empty
                    <x-empty-state colspan="7" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($lessons->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $lessons->links() }}</div>@endif
</div>
@endsection
