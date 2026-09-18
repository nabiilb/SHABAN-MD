@extends('layouts.app')

@section('title', __('Students'))
@section('heading', __('Students'))

@section('content')
<x-page-header :title="__('Students')" :subtitle="__(':count registered', ['count' => $students->total()])">
    <x-slot:actions>
        <a href="{{ route('admin.students.create') }}" class="btn-primary">
            <x-icon name="plus" class="h-4 w-4" /> {{ __('New Student') }}
        </a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="filter-bar sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <input name="search" value="{{ request('search') }}" class="input"
                   placeholder="{{ __('Search name, number, phone or email') }}">
        </div>
        <select name="instructor_id" class="input">
            <option value="">{{ __('All instructors') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Student::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucfirst($status)) }}</option>
            @endforeach
        </select>
        <div class="filter-actions">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.students.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

{{-- Columns on a desktop, a card per student on a phone. --}}
<div class="card max-md:border-0 max-md:bg-transparent max-md:shadow-none">
    <div class="table-wrap max-md:overflow-visible">
        <table class="table table-cards">
            <thead>
                <tr>
                    <th>{{ __('Student') }}</th>
                    <th>{{ __('Phone') }}</th>
                    <th>{{ __('Instructor') }}</th>
                    <th>{{ __('Start Date') }}</th>
                    <th class="text-center">{{ __('Completed') }}</th>
                    <th class="text-center">{{ __('Remaining') }}</th>
                    <th class="w-44">{{ __('Progress') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    <tr>
                        <td class="cell-title">
                            <a href="{{ route('admin.students.show', $student) }}" class="font-semibold text-brand-700 hover:underline max-md:text-base">
                                {{ $student->full_name }}
                            </a>
                            <p class="text-xs text-slate-400">{{ $student->student_number }}</p>
                        </td>
                        <td data-label="{{ __('Phone') }}" class="whitespace-nowrap text-sm">{{ $student->phone }}</td>
                        <td data-label="{{ __('Instructor') }}" class="text-sm">{{ $student->currentInstructor?->full_name ?? '—' }}</td>
                        <td data-label="{{ __('Start Date') }}" class="whitespace-nowrap text-sm">{{ $student->start_date?->format('d/m/Y') }}</td>
                        <td data-label="{{ __('Completed') }}" class="text-center font-semibold max-md:text-right">{{ $student->effective_completed_days }}</td>
                        <td data-label="{{ __('Remaining') }}" class="text-center max-md:text-right">{{ $student->remaining_days }}</td>
                        <td data-label="{{ __('Progress') }}" class="max-md:block">
                            <x-progress-bar :value="$student->progress_percentage" class="max-md:mt-1" />
                        </td>
                        <td data-label="{{ __('Status') }}"><x-status-badge :status="$student->status" /></td>
                        <td class="cell-actions whitespace-nowrap text-right">
                            <a href="{{ route('admin.students.edit', $student) }}" class="btn-secondary btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.students.destroy', $student)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="9" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($students->hasPages())
        <div class="border-t border-slate-200 px-4 py-3 max-md:rounded-xl max-md:border max-md:border-slate-200 max-md:bg-white sm:px-5">{{ $students->links() }}</div>
    @endif
</div>
@endsection
