@extends('layouts.app')
@section('title', $expense->exists ? __('Edit Expense') : __('New Expense'))
@section('heading', $expense->exists ? __('Edit Expense') : __('New Expense'))

@section('content')
<form method="POST" action="{{ $expense->exists ? route('admin.expenses.update', $expense) : route('admin.expenses.store') }}">
    @csrf
    @if ($expense->exists) @method('PUT') @endif

    <div class="card max-w-3xl">
        <div class="card-header"><h3 class="card-title">{{ __('Expense Details') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="expense_category_id" :label="__('Category')" required>
                <select id="expense_category_id" name="expense_category_id" required class="input @error('expense_category_id') input-error @enderror">
                    <option value="">{{ __('Select a category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('expense_category_id', $expense->expense_category_id) == $category->id)>{{ $category->display_name }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="amount" :label="__('Amount')" required>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                       value="{{ old('amount', $expense->amount) }}" class="input @error('amount') input-error @enderror">
            </x-field>

            <x-field name="description" :label="__('Description')" required class="sm:col-span-2">
                <input id="description" name="description" required value="{{ old('description', $expense->description) }}" class="input @error('description') input-error @enderror">
            </x-field>

            <x-field name="vehicle_id" :label="__('Vehicle')">
                <select id="vehicle_id" name="vehicle_id" class="input">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $expense->vehicle_id) == $vehicle->id)>{{ $vehicle->label }} — {{ $vehicle->plate_number }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="supplier_id" :label="__('Supplier')">
                <select id="supplier_id" name="supplier_id" class="input">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id', $expense->supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="expense_date" :label="__('Expense Date')" required>
                <input id="expense_date" name="expense_date" type="date" required max="{{ now()->toDateString() }}"
                       value="{{ old('expense_date', $expense->expense_date instanceof \Illuminate\Support\Carbon ? $expense->expense_date->format('Y-m-d') : $expense->expense_date) }}"
                       class="input @error('expense_date') input-error @enderror">
            </x-field>

            <x-field name="payment_method" :label="__('Payment Method')" required>
                <select id="payment_method" name="payment_method" class="input">
                    @foreach (\App\Models\CompanyExpense::METHODS as $method)
                        <option value="{{ $method }}" @selected(old('payment_method', $expense->payment_method) === $method)>{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $expense->notes) }}</textarea>
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.expenses.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
