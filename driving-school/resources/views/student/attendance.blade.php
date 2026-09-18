@extends('layouts.app')
@section('title', __('My Attendance'))
@section('heading', __('My Attendance'))

@section('content')
<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-4">
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
            <a href="{{ route('student.attendance') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Instructor') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Status') }}</th><th>{{ __('Notes') }}</th></tr></thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $record->attendance_date?->format('d/m/Y') }}</td>
                        <td class="text-sm">{{ $record->instructor?->full_name }}</td>
                        <td class="text-sm">{{ $record->check_in_time ? substr($record->check_in_time, 0, 5) : '—' }}</td>
                        <td><x-status-badge :status="$record->status" /></td>
                        <td class="max-w-56 truncate text-sm text-slate-500">{{ $record->notes }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="5" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($records->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>@endif
</div>
@endsection
