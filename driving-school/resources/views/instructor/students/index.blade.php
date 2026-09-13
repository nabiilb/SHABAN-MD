@extends('layouts.app')
@section('title', __('My Students'))
@section('heading', __('My Students'))
@section('subheading', __('Only students currently assigned to you'))

@section('content')
<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-3">
        <input name="search" value="{{ request('search') }}" class="input" placeholder="{{ __('Search name, number or phone') }}">
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Student::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucfirst($status)) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Search') }}</button>
            <a href="{{ route('instructor.students.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Student') }}</th><th>{{ __('Start Date') }}</th><th class="text-center">{{ __('Completed') }}</th>
                    <th class="text-center">{{ __('Remaining') }}</th><th class="w-48">{{ __('Progress') }}</th>
                    <th>{{ __('Status') }}</th><th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    <tr>
                        <td>
                            <a href="{{ route('instructor.students.show', $student) }}" class="font-semibold text-brand-700 hover:underline">{{ $student->full_name }}</a>
                            <p class="text-xs text-slate-400">{{ $student->student_number }} · {{ $student->phone }}</p>
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $student->start_date?->format('d/m/Y') }}</td>
                        <td class="text-center font-semibold">{{ $student->completed_days }}</td>
                        <td class="text-center">{{ $student->remaining_days }}</td>
                        <td><x-progress-bar :value="$student->progress_percentage" /></td>
                        <td><x-status-badge :status="$student->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('instructor.attendance.create', ['search' => $student->full_name]) }}" class="btn-ghost btn-sm">{{ __('Check In') }}</a>
                            <a href="{{ route('instructor.lessons.create', ['student_id' => $student->id]) }}" class="btn-ghost btn-sm">{{ __('Lesson') }}</a>
                            <a href="{{ route('instructor.transfers.create', ['student_id' => $student->id]) }}" class="btn-ghost btn-sm">{{ __('Transfer') }}</a>
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="7" :message="__('No students are currently assigned to you.')" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($students->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $students->links() }}</div>@endif
</div>
@endsection
