@extends('layouts.app')
@section('title', $fuel->exists ? __('Edit Fuel Record') : __('New Fuel Record'))
@section('heading', $fuel->exists ? __('Edit Fuel Record') : __('New Fuel Record'))

@section('content')
<form method="POST" action="{{ $fuel->exists ? route('admin.fuel.update', $fuel) : route('admin.fuel.store') }}"
      x-data="{ credit: {{ old('is_credit', $fuel->is_credit) ? 'true' : 'false' }}, liters: {{ old('liters', $fuel->liters ?: 0) }}, price: {{ old('price_per_liter', $fuel->price_per_liter ?: 0) }} }">
    @csrf
    @if ($fuel->exists) @method('PUT') @endif

    <div class="card max-w-3xl">
        <div class="card-header"><h3 class="card-title">{{ __('Fuel Details') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="vehicle_id" :label="__('Vehicle')" required>
                <select id="vehicle_id" name="vehicle_id" required class="input @error('vehicle_id') input-error @enderror">
                    <option value="">{{ __('Select a vehicle') }}</option>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $fuel->vehicle_id) == $vehicle->id)>{{ $vehicle->label }} — {{ $vehicle->plate_number }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="instructor_id" :label="__('Instructor')">
                <select id="instructor_id" name="instructor_id" class="input">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($instructors as $instructor)
                        <option value="{{ $instructor->id }}" @selected(old('instructor_id', $fuel->instructor_id) == $instructor->id)>{{ $instructor->full_name }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="liters" :label="__('Liters')" required>
                <input id="liters" name="liters" type="number" step="0.01" min="0.01" required x-model="liters"
                       value="{{ old('liters', $fuel->liters) }}" class="input @error('liters') input-error @enderror">
            </x-field>

            <x-field name="price_per_liter" :label="__('Price per Liter')" required>
                <input id="price_per_liter" name="price_per_liter" type="number" step="0.01" min="0.01" required x-model="price"
                       value="{{ old('price_per_liter', $fuel->price_per_liter) }}" class="input @error('price_per_liter') input-error @enderror">
            </x-field>

            <div class="sm:col-span-2 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                <span class="text-slate-500">{{ __('Total') }}:</span>
                <strong class="text-slate-800" x-text="(liters * price).toFixed(2)">0.00</strong>
            </div>

            <x-field name="odometer" :label="__('Odometer')">
                <input id="odometer" name="odometer" type="number" min="0" value="{{ old('odometer', $fuel->odometer) }}" class="input">
            </x-field>

            <x-field name="fuel_date" :label="__('Date')" required>
                <input id="fuel_date" name="fuel_date" type="date" required max="{{ now()->toDateString() }}"
                       value="{{ old('fuel_date', $fuel->fuel_date instanceof \Illuminate\Support\Carbon ? $fuel->fuel_date->format('Y-m-d') : $fuel->fuel_date) }}"
                       class="input @error('fuel_date') input-error @enderror">
            </x-field>

            @unless ($fuel->exists)
                <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                    <input type="checkbox" name="is_credit" value="1" x-model="credit"
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    {{ __('Taken on credit (creates a company debt instead of an expense)') }}
                </label>
            @endunless

            <x-field name="supplier_id" :label="__('Petrol Station')" class="sm:col-span-2">
                <select id="supplier_id" name="supplier_id" class="input @error('supplier_id') input-error @enderror" :required="credit">
                    <option value="">{{ __('None') }}</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(old('supplier_id', $fuel->supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="payment_method" :label="__('Payment Method')" required>
                <select id="payment_method" name="payment_method" class="input">
                    @foreach (\App\Models\CompanyExpense::METHODS as $method)
                        <option value="{{ $method }}" @selected(old('payment_method', $fuel->payment_method) === $method)>{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="2" class="input">{{ old('notes', $fuel->notes) }}</textarea>
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.fuel.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
