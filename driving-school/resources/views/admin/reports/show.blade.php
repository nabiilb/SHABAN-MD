@extends('layouts.app')
@section('title', $title)
@section('heading', $title)

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3 no-print">
    <a href="{{ route('admin.reports.index') }}" class="btn-secondary btn-sm">← {{ __('All reports') }}</a>
    <div class="flex flex-wrap gap-2">
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-secondary btn-sm">{{ __('Export CSV') }}</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn-secondary btn-sm">{{ __('Export PDF') }}</a>
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
            <label class="label">{{ __('Instructor') }}</label>
            <select name="instructor_id" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach ($instructors as $instructor)
                    <option value="{{ $instructor->id }}" @selected(($filters['instructor_id'] ?? null) == $instructor->id)>{{ $instructor->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">{{ __('Student') }}</label>
            <select name="student_id" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}" @selected(($filters['student_id'] ?? null) == $student->id)>{{ $student->full_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">{{ __('Vehicle') }}</label>
            <select name="vehicle_id" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}" @selected(($filters['vehicle_id'] ?? null) == $vehicle->id)>{{ $vehicle->plate_number }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">{{ __('Supplier') }}</label>
            <select name="supplier_id" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? null) == $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">{{ __('Category') }}</label>
            <select name="expense_category_id" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(($filters['expense_category_id'] ?? null) == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">{{ __('Status') }}</label>
            <input name="status" value="{{ $filters['status'] ?? '' }}" class="input" placeholder="{{ __('e.g. active') }}">
        </div>
        <div class="flex items-end gap-2 lg:col-span-4">
            <button class="btn-primary">{{ __('Run Report') }}</button>
            <a href="{{ route('admin.reports.show', $report) }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

@include('partials.report-table', ['title' => $title, 'result' => $result])
@endsection
