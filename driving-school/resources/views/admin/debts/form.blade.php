@extends('layouts.app')
@section('title', $debt->exists ? __('Edit Debt') : __('New Debt'))
@section('heading', $debt->exists ? __('Edit Debt') : __('New Company Debt'))

@section('content')
<form method="POST" action="{{ $debt->exists ? route('admin.debts.update', $debt) : route('admin.debts.store') }}">
    @csrf
    @if ($debt->exists) @method('PUT') @endif

    <div class="card max-w-3xl">
        <div class="card-header"><h3 class="card-title">{{ __('Debt Details') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="supplier_id" :label="__('Supplier')" required>
                <select id="supplier_id" name="supplier_id" required class="input @error('supplier_id') input-error @enderror">
                    <option value="">{{ __('Select a supplier') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id', $debt->supplier_id) == $supplier->id)>
                            {{ $supplier->name }} ({{ __(ucwords(str_replace('_', ' ', $supplier->supplier_type))) }})
                        </option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="expense_category_id" :label="__('Expense Category')">
                <select id="expense_category_id" name="expense_category_id" class="input">
                    <option value="">{{ __('Uncategorised') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('expense_category_id', $debt->expense_category_id) == $category->id)>{{ $category->display_name }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="description" :label="__('Description')" required class="sm:col-span-2">
                <input id="description" name="description" required value="{{ old('description', $debt->description) }}"
                       class="input @error('description') input-error @enderror" placeholder="{{ __('e.g. Garage service — engine repair') }}">
            </x-field>

            <x-field name="vehicle_id" :label="__('Vehicle')">
                <select id="vehicle_id" name="vehicle_id" class="input">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $debt->vehicle_id) == $vehicle->id)>{{ $vehicle->label }} — {{ $vehicle->plate_number }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="original_amount" :label="__('Amount')" required>
                <input id="original_amount" name="original_amount" type="number" step="0.01" min="0.01" required
                       value="{{ old('original_amount', $debt->original_amount) }}" class="input @error('original_amount') input-error @enderror">
            </x-field>

            <x-field name="debt_date" :label="__('Debt Date')" required>
                <input id="debt_date" name="debt_date" type="date" required
                       value="{{ old('debt_date', $debt->debt_date instanceof \Illuminate\Support\Carbon ? $debt->debt_date->format('Y-m-d') : $debt->debt_date) }}"
                       class="input @error('debt_date') input-error @enderror">
            </x-field>

            <x-field name="due_date" :label="__('Due Date')">
                <input id="due_date" name="due_date" type="date"
                       value="{{ old('due_date', $debt->due_date?->format('Y-m-d')) }}" class="input @error('due_date') input-error @enderror">
            </x-field>

            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $debt->notes) }}</textarea>
            </x-field>
        </div>
        <div class="border-t border-amber-200 bg-amber-50 px-5 py-3 text-xs text-amber-800">
            {{ __('Recording a debt does NOT create a company expense. The expense is booked when a payment is made.') }}
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.debts.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
