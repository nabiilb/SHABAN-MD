@extends('layouts.app')

@section('title', __('No Attendance'))
@section('heading', __('No Attendance'))

@section('content')
<x-page-header :title="__('No Attendance')"
               :subtitle="__(':count active student(s) have a finished day with no attendance record, up to :date', [
                   'count' => $total,
                   'date' => $lastFinishedDay->format('d/m/Y'),
               ])">
    <x-slot:actions>
        <a href="{{ route('admin.attendance.index') }}" class="btn-secondary">{{ __('Attendance Register') }}</a>
        <a href="{{ route('admin.students.index') }}" class="btn-secondary">{{ __('All Students') }}</a>
    </x-slot:actions>
</x-page-header>

{{-- Nothing on this page writes to the register. A day counts here only
     because no attendance row exists for it; a day the school marked absent
     has a record, so it is not counted. --}}
<div class="card mb-4 p-4">
    {{-- One column on a phone, inline once there is room. Every control is
         labelled, because a bare date box says nothing about which end it is. --}}
    <form method="GET" class="filter-bar sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <label for="f-search" class="label">{{ __('Search') }}</label>
            <input id="f-search" name="search" value="{{ request('search') }}" class="input"
                   placeholder="{{ __('Search name, number or phone') }}">
        </div>
        <div>
            <label for="f-instructor" class="label">{{ __('Instructor') }}</label>
            <select id="f-instructor" name="instructor_id" class="input">
                <option value="">{{ __('All instructors') }}</option>
                @foreach ($instructors as $instructor)
                    <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-min" class="label">{{ __('Min. days') }}</label>
            <input id="f-min" name="min_days" type="number" min="1" inputmode="numeric"
                   value="{{ request('min_days') }}" class="input" placeholder="1">
        </div>
        <div>
            <label for="f-from" class="label">{{ __('From') }}</label>
            <input id="f-from" name="date_from" type="date" value="{{ request('date_from') }}" class="input">
        </div>
        <div>
            <label for="f-to" class="label">{{ __('To') }}</label>
            <input id="f-to" name="date_to" type="date" value="{{ request('date_to') }}" class="input">
        </div>
        <div class="filter-actions lg:col-span-5">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.students.no-attendance') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

{{-- One table, read two ways: columns on a desktop, a card per student on a
     phone (see `table-cards` in app.css). Nothing is hidden on the small
     screen — every column a desktop shows is here, stacked with its heading
     beside it — so no horizontal scrolling is needed to read a row. --}}
<div class="card max-md:border-0 max-md:bg-transparent max-md:shadow-none">
    <div class="table-wrap max-md:overflow-visible">
        <table class="table table-cards">
            <thead>
                <tr>
                    <th>{{ __('Student') }}</th>
                    <th>{{ __('Phone') }}</th>
                    <th>{{ __('Instructor') }}</th>
                    <th>{{ __('Start Date') }}</th>
                    <th class="text-center">{{ __('No Attendance Days') }}</th>
                    <th>{{ __('Last Attendance') }}</th>
                    <th class="text-right">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    @php $dates = $missingDates->get($student->id, collect()); @endphp
                    <tr x-data="{ open: false }">
                        <td class="cell-title">
                            <a href="{{ route('admin.students.show', $student) }}"
                               class="font-medium text-brand-700 hover:underline max-md:text-base">{{ $student->full_name }}</a>
                            <span class="block text-xs text-slate-400">{{ $student->student_number }}</span>
                        </td>
                        <td data-label="{{ __('Phone') }}" class="whitespace-nowrap text-sm">{{ $student->phone }}</td>
                        <td data-label="{{ __('Instructor') }}" class="text-sm">
                            {{-- Never invented: a student with no instructor says so. --}}
                            {{ $student->currentInstructor?->full_name ?? __('Unassigned') }}
                        </td>
                        <td data-label="{{ __('Start Date') }}" class="whitespace-nowrap text-sm">
                            {{ $student->start_date?->format('d/m/Y') }}
                        </td>
                        <td data-label="{{ __('No Attendance') }}" class="text-center max-md:text-right">
                            <span class="badge-amber">
                                {{ trans_choice('{1}:count day|[2,*]:count days', (int) $student->missing_days, ['count' => (int) $student->missing_days]) }}
                            </span>
                        </td>
                        <td data-label="{{ __('Last Attendance') }}" class="whitespace-nowrap text-sm">
                            @if ($student->last_attendance)
                                {{ \Illuminate\Support\Carbon::parse($student->last_attendance)->format('d/m/Y') }}
                            @else
                                <span class="text-slate-400">{{ __('Never') }}</span>
                            @endif
                        </td>
                        <td class="cell-actions whitespace-nowrap text-right text-sm">
                            {{-- Which days are missing, under the row on a desktop
                                 and inside the card on a phone. --}}
                            <button type="button" @click="open = ! open"
                                    class="btn-secondary btn-sm max-sm:w-full"
                                    :aria-expanded="open.toString()">
                                <span x-text="open ? '{{ __('Hide Missing Dates') }}' : '{{ __('Show Missing Dates') }}'"></span>
                            </button>
                            <a href="{{ route('admin.students.show', $student) }}"
                               class="btn-secondary btn-sm max-sm:w-full">{{ __('View Student') }}</a>

                            <div x-show="open" x-cloak
                                 class="mt-2 w-full rounded-lg bg-slate-50 p-3 text-left">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                    {{ __('Missing Dates') }}
                                </p>
                                <ul class="grid max-h-48 grid-cols-2 gap-x-4 gap-y-0.5 overflow-y-auto text-xs text-slate-600 sm:grid-cols-3">
                                    @foreach ($dates as $date)
                                        <li>{{ $date->format('d/m/Y') }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="stack-card px-5 py-10 text-center text-sm text-slate-400 max-md:block">
                            {{ __('Every active student has a record for every finished day.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($students->hasPages())
        <div class="border-t border-slate-100 px-4 py-3 max-md:rounded-xl max-md:border max-md:border-slate-200 max-md:bg-white sm:px-5">
            {{ $students->links() }}
        </div>
    @endif
</div>
@endsection
