@extends('layouts.app')
@section('title', __('Add Fuel'))
@section('heading', __('Add Fuel'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Add Fuel')"
               :subtitle="__('Your submission waits for an admin to approve it before it reaches the company ledger.')">
    <x-slot:actions>
        <a href="{{ route('instructor.fuel.index') }}" class="btn-secondary">← {{ __('Fuel') }}</a>
    </x-slot:actions>
</x-page-header>


<form method="POST" action="{{ route('instructor.fuel.store') }}" class="card p-5"
      x-data="{
          liters: {{ old('liters', 0) ?: 0 }},
          price: {{ old('price_per_liter', 0) ?: 0 }},
          credit: {{ old('is_credit') ? 'true' : 'false' }},
          submitting: false,
          get total() { return (this.liters * this.price).toFixed(2); },
      }"
      @submit="submitting = true">
    @csrf
    {{-- Idempotency: the same token cannot be recorded twice, so a refresh or
         a second click is refused by a unique index rather than posting
         another expense. --}}
    <input type="hidden" name="submission_token" value="{{ old('submission_token', $submissionToken) }}">

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-field name="vehicle_id" :label="__('Vehicle')" required>
            <select id="vehicle_id" name="vehicle_id" required class="input">
                <option value="">{{ __('Select a vehicle') }}</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>
                        {{ $vehicle->plate_number }} — {{ $vehicle->label }}
                    </option>
                @endforeach
            </select>
            @if ($vehicles->isEmpty())
                <p class="mt-1 text-xs text-amber-600">{{ __('No vehicle is assigned to you yet.') }}</p>
            @endif
        </x-field>

        <x-field name="fuel_date" :label="__('Fuel Date')" required>
            <input id="fuel_date" type="date" name="fuel_date" required max="{{ today()->toDateString() }}"
                   value="{{ old('fuel_date', $defaults->fuel_date?->toDateString()) }}" class="input">
        </x-field>

        <x-field name="odometer" :label="__('Odometer')">
            <input id="odometer" type="number" name="odometer" min="0" step="1"
                   value="{{ old('odometer') }}" class="input">
        </x-field>

        <x-field name="liters" :label="__('Liters')" required>
            <input id="liters" type="number" name="liters" required min="0.01" step="0.01"
                   x-model.number="liters" value="{{ old('liters') }}" class="input">
        </x-field>

        <x-field name="price_per_liter" :label="__('Price/L')" required>
            <input id="price_per_liter" type="number" name="price_per_liter" required min="0.01" step="0.01"
                   x-model.number="price" value="{{ old('price_per_liter') }}" class="input">
        </x-field>

        <div>
            <label class="label">{{ __('Amount') }}</label>
            <p class="input flex items-center bg-slate-50 font-bold text-slate-900">
                {{ $c }}<span x-text="total">0.00</span>
            </p>
            <p class="mt-1 text-xs text-slate-400">{{ __('Liters × price, calculated on the server.') }}</p>
        </div>

        <x-field name="payment_method" :label="__('Payment Method')" required>
            <select id="payment_method" name="payment_method" required class="input">
                @foreach (['cash', 'bank_transfer', 'mobile_money', 'cheque', 'other'] as $method)
                    <option value="{{ $method }}" @selected(old('payment_method', $defaults->payment_method) === $method)>
                        {{ __(ucwords(str_replace('_', ' ', $method))) }}
                    </option>
                @endforeach
            </select>
        </x-field>

        <x-field name="supplier_id" :label="__('Fuel Station')">
            <select id="supplier_id" name="supplier_id" class="input" :required="credit">
                <option value="">{{ __('Select a supplier') }}</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-400" x-show="credit">{{ __('Required for fuel taken on credit.') }}</p>
        </x-field>

        <label class="flex items-start gap-2 self-end pb-2 text-sm text-slate-700">
            <input type="checkbox" name="is_credit" value="1" x-model="credit" @checked(old('is_credit'))
                   class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>
                {{ __('Taken on credit') }}
                <span class="block text-xs text-slate-400">{{ __('Once approved this becomes a company debt rather than an expense.') }}</span>
            </span>
        </label>

        <x-field name="notes" :label="__('Notes')" class="sm:col-span-2 lg:col-span-3">
            <textarea id="notes" name="notes" rows="2" class="input">{{ old('notes') }}</textarea>
        </x-field>
    </div>

    <div class="mt-5 flex items-center gap-2">
        <button class="btn-primary" :disabled="submitting" :class="submitting ? 'cursor-not-allowed opacity-50' : ''">
            {{ __('Submit for Approval') }}
        </button>
        <a href="{{ route('instructor.fuel.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
