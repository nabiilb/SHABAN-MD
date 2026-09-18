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
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <div class="lg:col-span-2">
            <input name="search" value="{{ request('search') }}" class="input"
                   placeholder="{{ __('Search name, number or phone') }}">
        </div>
        <select name="instructor_id" class="input">
            <option value="">{{ __('All instructors') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <input name="min_days" type="number" min="1" value="{{ request('min_days') }}" class="input"
               placeholder="{{ __('Min. days') }}">
        <input name="date_from" type="date" value="{{ request('date_from') }}" class="input"
               title="{{ __('From') }}">
        <div class="flex gap-2">
            <input name="date_to" type="date" value="{{ request('date_to') }}" class="input"
                   title="{{ __('To') }}">
        </div>
        <div class="flex gap-2 lg:col-span-6">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.students.no-attendance') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
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
                        <td>
                            <a href="{{ route('admin.students.show', $student) }}"
                               class="font-medium text-brand-700 hover:underline">{{ $student->full_name }}</a>
                            <span class="block text-xs text-slate-400">{{ $student->student_number }}</span>
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $student->phone }}</td>
                        <td class="text-sm">
                            {{-- Never invented: a student with no instructor says so. --}}
                            {{ $student->currentInstructor?->full_name ?? __('Unassigned') }}
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $student->start_date?->format('d/m/Y') }}</td>
                        <td class="text-center">
                            <button type="button" @click="open = ! open"
                                    class="badge-amber hover:bg-amber-200"
                                    :aria-expanded="open.toString()">
                                {{ trans_choice('{1}:count day|[2,*]:count days', (int) $student->missing_days, ['count' => (int) $student->missing_days]) }}
                                <span x-text="open ? '▴' : '▾'"></span>
                            </button>

                            {{-- The dates themselves, so the office can see which
                                 days are missing rather than only how many. --}}
                            <div x-show="open" x-cloak style="display: none"
                                 class="mt-2 max-h-40 overflow-y-auto rounded-lg bg-slate-50 p-2 text-left">
                                <ul class="space-y-0.5 text-xs text-slate-600">
                                    @foreach ($dates as $date)
                                        <li>{{ $date->format('d/m/Y') }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-sm">
                            @if ($student->last_attendance)
                                {{ \Illuminate\Support\Carbon::parse($student->last_attendance)->format('d/m/Y') }}
                            @else
                                <span class="text-slate-400">{{ __('Never') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right text-sm">
                            <a href="{{ route('admin.students.show', $student) }}"
                               class="font-medium text-brand-700 hover:underline">{{ __('View') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">
                            {{ __('Every active student has a record for every finished day.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($students->hasPages())
        <div class="border-t border-slate-100 px-5 py-3">{{ $students->links() }}</div>
    @endif
</div>
@endsection
