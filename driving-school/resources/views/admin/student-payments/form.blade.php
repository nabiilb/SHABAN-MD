@extends('layouts.app')
@section('title', $payment->exists ? __('Edit Payment') : __('Record Payment'))
@section('heading', $payment->exists ? __('Edit Payment') : __('Record Payment'))

@section('content')
<form method="POST" action="{{ $payment->exists ? route('admin.student-payments.update', $payment) : route('admin.student-payments.store') }}">
    @csrf
    @if ($payment->exists) @method('PUT') @endif

    <div class="card max-w-2xl">
        <div class="card-header"><h3 class="card-title">{{ __('Payment Details') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="student_id" :label="__('Student')" required class="sm:col-span-2">
                <select id="student_id" name="student_id" required class="input @error('student_id') input-error @enderror">
                    <option value="">{{ __('Select a student') }}</option>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}" @selected(old('student_id', $payment->student_id) == $student->id)>
                            {{ $student->full_name }} ({{ $student->student_number }})
                        </option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="amount" :label="__('Amount')" required>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                       value="{{ old('amount', $payment->amount) }}" class="input @error('amount') input-error @enderror">
            </x-field>
            <x-field name="payment_date" :label="__('Payment Date')" required>
                <input id="payment_date" name="payment_date" type="date" required max="{{ now()->toDateString() }}"
                       value="{{ old('payment_date', $payment->payment_date instanceof \Illuminate\Support\Carbon ? $payment->payment_date->format('Y-m-d') : $payment->payment_date) }}"
                       class="input @error('payment_date') input-error @enderror">
            </x-field>
            <x-field name="payment_method" :label="__('Payment Method')" required>
                <select id="payment_method" name="payment_method" class="input">
                    @foreach (\App\Models\StudentPayment::METHODS as $method)
                        <option value="{{ $method }}" @selected(old('payment_method', $payment->payment_method) === $method)>{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="reference" :label="__('Reference')">
                <input id="reference" name="reference" value="{{ old('reference', $payment->reference) }}" class="input">
            </x-field>
            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="2" class="input">{{ old('notes', $payment->notes) }}</textarea>
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.student-payments.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
