@extends('layouts.app')
@section('title', $loan->exists ? __('Edit Loan') : __('New Loan'))
@section('heading', $loan->exists ? __('Edit Loan') : __('New Loan'))

@section('content')
<form method="POST" action="{{ $loan->exists ? route('admin.loans.update', $loan) : route('admin.loans.store') }}">
    @csrf
    @if ($loan->exists) @method('PUT') @endif

    <div class="card max-w-2xl">
        <div class="card-header"><h3 class="card-title">{{ __('Loan Details') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="instructor_id" :label="__('Instructor')" required class="sm:col-span-2">
                <select id="instructor_id" name="instructor_id" required class="input @error('instructor_id') input-error @enderror">
                    <option value="">{{ __('Select an instructor') }}</option>
                    @foreach ($instructors as $instructor)
                        <option value="{{ $instructor->id }}" @selected(old('instructor_id', $loan->instructor_id) == $instructor->id)>{{ $instructor->full_name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="amount" :label="__('Amount')" required>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                       value="{{ old('amount', $loan->amount) }}" class="input @error('amount') input-error @enderror">
            </x-field>
            <x-field name="loan_date" :label="__('Loan Date')" required>
                <input id="loan_date" name="loan_date" type="date" required
                       value="{{ old('loan_date', $loan->loan_date instanceof \Illuminate\Support\Carbon ? $loan->loan_date->format('Y-m-d') : $loan->loan_date) }}"
                       class="input @error('loan_date') input-error @enderror">
            </x-field>
            <x-field name="due_date" :label="__('Due Date')">
                <input id="due_date" name="due_date" type="date" value="{{ old('due_date', $loan->due_date?->format('Y-m-d')) }}" class="input">
            </x-field>
            <x-field name="reason" :label="__('Reason')">
                <input id="reason" name="reason" value="{{ old('reason', $loan->reason) }}" class="input">
            </x-field>
            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $loan->notes) }}</textarea>
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.loans.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
