@extends('layouts.app')

@section('title', __('Training Queue'))
@section('heading', __('Training Queue'))
@section('subheading', \Illuminate\Support\Carbon::parse($date)->format('l, d/m/Y'))

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('admin.training.index') }}" class="btn-secondary btn-sm">← {{ __('Training Board') }}</a>
    <form method="GET" class="flex items-center gap-2">
        <input type="date" name="date" value="{{ $date }}" class="input w-auto">
        <button class="btn-secondary btn-sm">{{ __('Show') }}</button>
    </form>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    {{-- Add to the queue --}}
    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Add to Queue') }}</h3></div>
        <form method="POST" action="{{ route('admin.training.queue.store') }}" class="space-y-4 p-5">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">

            <x-field name="student_id" :label="__('Student')" required>
                <select id="student_id" name="student_id" required class="input @error('student_id') input-error @enderror">
                    <option value="">{{ __('Select a student') }}</option>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}">{{ $student->full_name }} ({{ $student->student_number }})</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="preferred_instructor_id" :label="__('Preferred Teacher')">
                <select id="preferred_instructor_id" name="preferred_instructor_id" class="input">
                    <option value="">{{ __('Any teacher') }}</option>
                    @foreach ($instructors as $instructor)
                        <option value="{{ $instructor->id }}">{{ $instructor->full_name }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="assigned_duration_minutes" :label="__('Duration')">
                <select id="assigned_duration_minutes" name="assigned_duration_minutes" class="input">
                    <option value="">{{ __('Default') }}</option>
                    @foreach ($durations as $minutes)
                        <option value="{{ $minutes }}">{{ $minutes }} {{ __('minutes') }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="notes" :label="__('Notes')">
                <input id="notes" name="notes" class="input">
            </x-field>

            <button class="btn-primary w-full">{{ __('Add to Queue') }}</button>
        </form>
    </div>

    {{-- The line --}}
    <div class="card lg:col-span-2">
        <div class="card-header">
            <h3 class="card-title">{{ __('Full Queue') }}</h3>
            <span class="badge-slate">{{ $entries->count() }}</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Position') }}</th><th>{{ __('Student') }}</th><th>{{ __('Status') }}</th>
                        <th>{{ __('Waiting Time') }}</th><th>{{ __('Preferred Teacher') }}</th>
                        <th class="text-right">{{ __('Actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="font-mono font-semibold">
                                @if (in_array($entry->status, \App\Models\TrainingQueueEntry::OPEN_STATUSES, true))
                                    #{{ $entry->position }}
                                @else
                                    {{-- A finished or cancelled student no longer holds a place in line. --}}
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td>
                                <p class="font-medium text-slate-800">{{ $entry->student?->full_name }}</p>
                                <p class="text-xs text-slate-400">{{ $entry->student?->student_number }}</p>
                            </td>
                            <td><span class="badge-slate">{{ $entry->status_icon }} {{ $entry->status_label }}</span></td>
                            <td class="whitespace-nowrap text-sm">
                                @if ($entry->status === \App\Models\TrainingQueueEntry::WAITING)
                                    {{ $entry->waiting_minutes }} {{ __('min') }}
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="text-sm">{{ $entry->preferredInstructor?->full_name ?? __('Any teacher') }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($entry->status === \App\Models\TrainingQueueEntry::WAITING)
                                    <form method="POST" action="{{ route('admin.training.queue.move', $entry) }}" class="inline">
                                        @csrf <input type="hidden" name="direction" value="up">
                                        <button class="btn-ghost btn-sm" title="{{ __('Move up') }}">↑</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.training.queue.move', $entry) }}" class="inline">
                                        @csrf <input type="hidden" name="direction" value="down">
                                        <button class="btn-ghost btn-sm" title="{{ __('Move down') }}">↓</button>
                                    </form>
                                    <x-delete-form :action="route('admin.training.queue.destroy', $entry)"
                                                   :label="__('Remove')"
                                                   :confirm="__('Remove this student from the queue?')" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" :message="__('The queue is empty for this date.')" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
