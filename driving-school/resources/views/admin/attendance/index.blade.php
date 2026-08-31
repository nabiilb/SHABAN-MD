@extends('layouts.app')
@section('title', __('Attendance'))
@section('heading', __('Attendance'))

@section('content')
<x-page-header :title="__('Attendance')" :subtitle="__(':count records', ['count' => $records->total()])">
    <x-slot:actions>
        <a href="{{ route('admin.attendance.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Record Attendance') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input name="search" value="{{ request('search') }}" class="input lg:col-span-2" placeholder="{{ __('Search student') }}">
        <select name="instructor_id" class="input">
            <option value="">{{ __('All instructors') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Attendance::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucfirst($status)) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input" title="{{ __('Date From') }}">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input" title="{{ __('Date To') }}">
        <div class="flex gap-2 lg:col-span-6">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.attendance.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Instructor') }}</th>
                    <th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th><th>{{ __('Notes') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $record->attendance_date?->format('d/m/Y') }}</td>
                        <td class="font-medium">{{ $record->student?->full_name }}</td>
                        <td class="text-sm">{{ $record->instructor?->full_name }}</td>
                        <td class="text-sm">{{ $record->check_in_time ? substr($record->check_in_time, 0, 5) : '—' }}</td>
                        <td><x-status-badge :status="$record->status" /></td>
                        <td class="max-w-48 truncate text-sm text-slate-500">{{ $record->notes }}</td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.attendance.edit', $record) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.attendance.destroy', $record)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="7" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($records->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>@endif
</div>
@endsection
