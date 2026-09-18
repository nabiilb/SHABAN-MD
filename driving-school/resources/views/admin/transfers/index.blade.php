@extends('layouts.app')
@section('title', __('Student Transfers'))
@section('heading', __('Student Transfers'))

@section('content')
<x-page-header :title="__('Student Transfers')" :subtitle="__('Full history — records are never deleted')">
    <x-slot:actions>
        <a href="{{ route('admin.transfers.create') }}" class="btn-primary"><x-icon name="swap" class="h-4 w-4" /> {{ __('New Transfer') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
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
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.transfers.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Reason') }}</th><th>{{ __('By') }}</th></tr></thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $record->transfer_date?->format('d/m/Y') }}</td>
                        <td class="font-medium">{{ $record->student?->full_name }}</td>
                        <td class="text-sm text-slate-500">{{ $record->fromInstructor?->full_name ?? '—' }}</td>
                        <td class="text-sm font-medium text-brand-700">{{ $record->toInstructor?->full_name }}</td>
                        <td class="max-w-56 truncate text-sm">{{ $record->reason }}</td>
                        <td class="text-sm text-slate-500">{{ $record->transferredBy?->name ?? '—' }}</td>
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
