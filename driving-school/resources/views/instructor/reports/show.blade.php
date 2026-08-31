@extends('layouts.app')
@section('title', $title)
@section('heading', $title)

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3 no-print">
    <a href="{{ route('instructor.reports.index') }}" class="btn-secondary btn-sm">← {{ __('All reports') }}</a>
    <div class="flex gap-2">
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary btn-sm">{{ __('Export CSV') }}</a>
        <button onclick="window.print()" class="btn-secondary btn-sm">{{ __('Print') }}</button>
    </div>
</div>

<div class="card mb-4 p-4 no-print">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="label">{{ __('Date From') }}</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="input">
        </div>
        <div>
            <label class="label">{{ __('Date To') }}</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="input">
        </div>
        <div>
            <label class="label">{{ __('Student') }}</label>
            <select name="student_id" class="input">
                <option value="">{{ __('All my students') }}</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}" @selected(($filters['student_id'] ?? null) == $student->id)>{{ $student->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button class="btn-primary">{{ __('Run Report') }}</button>
            <a href="{{ route('instructor.reports.show', $report) }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

@include('partials.report-table', ['title' => $title, 'result' => $result])
@endsection
