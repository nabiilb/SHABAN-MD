@extends('layouts.app')
@section('title', __('Lessons'))
@section('heading', __('Lessons'))

@section('content')
<x-page-header :title="__('Lessons')" :subtitle="__(':count records', ['count' => $lessons->total()])">
    <x-slot:actions>
        <a href="{{ route('admin.lessons.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Add Lesson') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <select name="student_id" class="input">
            <option value="">{{ __('All students') }}</option>
            @foreach ($students as $student)
                <option value="{{ $student->id }}" @selected(request('student_id') == $student->id)>{{ $student->full_name }}</option>
            @endforeach
        </select>
        <select name="instructor_id" class="input">
            <option value="">{{ __('All instructors') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <select name="lesson_topic_id" class="input">
            <option value="">{{ __('All lesson types') }}</option>
            @foreach ($topics as $topic)
                <option value="{{ $topic->id }}" @selected(request('lesson_topic_id') == $topic->id)>{{ $topic->display_name }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Lesson::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucfirst($status)) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2 lg:col-span-6">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.lessons.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Instructor') }}</th>
                    <th>{{ __('Lesson Type') }}</th><th>{{ __('Topic') }}</th><th>{{ __('Vehicle') }}</th>
                    <th>{{ __('Duration') }}</th><th>{{ __('Performance') }}</th><th>{{ __('Status') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($lessons as $lesson)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $lesson->lesson_date?->format('d/m/Y') }}</td>
                        <td class="font-medium">{{ $lesson->student?->full_name }}</td>
                        <td class="text-sm">{{ $lesson->instructor?->full_name }}</td>
                        <td class="text-sm">{{ $lesson->lessonTopic?->display_name }}</td>
                        <td class="max-w-40 truncate text-sm text-slate-500">{{ $lesson->topic ?: '—' }}</td>
                        <td class="font-mono text-xs">{{ $lesson->vehicle?->plate_number ?? '—' }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $lesson->duration_minutes }} {{ __('min') }}</td>
                        <td>@if ($lesson->performance)<x-status-badge :status="$lesson->performance" />@else <span class="text-xs text-slate-400">—</span> @endif</td>
                        <td><x-status-badge :status="$lesson->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.lessons.edit', $lesson) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.lessons.destroy', $lesson)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="10" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($lessons->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $lessons->links() }}</div>@endif
</div>
@endsection
