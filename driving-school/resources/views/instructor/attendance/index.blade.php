@extends('layouts.app')
@section('title', __('Attendance'))
@section('heading', __('My Attendance'))

@section('content')
<x-page-header :title="__('My Attendance')" :subtitle="__(':count records', ['count' => $records->total()])">
    <x-slot:actions>
        <a href="{{ route('instructor.attendance.create') }}" class="btn-primary"><x-icon name="check" class="h-4 w-4" /> {{ __('Check In') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <select name="student_id" class="input">
            <option value="">{{ __('All my students') }}</option>
            @foreach ($students as $student)
                <option value="{{ $student->id }}" @selected(request('student_id') == $student->id)>{{ $student->full_name }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Attendance::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucfirst($status)) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('instructor.attendance.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Check-in') }}</th>
                <th>{{ __('Status') }}</th><th>{{ __('Notes') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $record->attendance_date?->format('d/m/Y') }}</td>
                        <td class="font-medium">{{ $record->student?->full_name }}</td>
                        <td class="text-sm">{{ $record->check_in_time ? substr($record->check_in_time, 0, 5) : '—' }}</td>
                        <td><x-status-badge :status="$record->status" /></td>
                        <td class="max-w-48 truncate text-sm text-slate-500">{{ $record->notes }}</td>
                        <td class="whitespace-nowrap text-right">
                            @can('update', $record)
                                <a href="{{ route('instructor.attendance.edit', $record) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            @endcan
                            @if ($record->attendance_date?->isToday() && $transferTargets->isNotEmpty() && $record->student)
                                @can('transfer', $record)
                                    <x-attendance-transfer
                                        :student="$record->student"
                                        :instructors="$transferTargets"
                                        :action="route('instructor.attendance.transfer')"
                                        compact />
                                @endcan
                            @endif
                            @can('delete', $record)
                                <x-delete-form :action="route('instructor.attendance.destroy', $record)" />
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="6" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($records->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>@endif
</div>
@endsection
