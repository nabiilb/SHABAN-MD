@extends('layouts.app')
@section('title', $loan->loan_number)
@section('heading', $loan->loan_number)
@section('subheading', $loan->instructor?->full_name)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Total Loan')" :value="$c.number_format((float) $loan->amount, 2)" icon="money" tone="slate" />
    <x-stat-card :label="__('Paid')" :value="$c.number_format($loan->paid_amount, 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining')" :value="$c.number_format((float) $loan->remaining_amount, 2)" icon="alert" tone="amber" />
    <x-stat-card :label="__('Status')" :value="__(ucwords(str_replace('_', ' ', $loan->status)))" icon="shield"
                 :tone="$loan->status === 'paid' ? 'green' : 'amber'" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    @if (! in_array($loan->status, ['paid', 'cancelled'], true))
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Record a Repayment') }}</h3></div>
            <form method="POST" action="{{ route('admin.loans.payments.store', $loan) }}" class="space-y-4 p-5">
                @csrf
                <x-field name="amount" :label="__('Amount')" required>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" max="{{ $loan->remaining_amount }}" required
                           value="{{ old('amount') }}" class="input @error('amount') input-error @enderror">
                </x-field>
                <x-field name="payment_date" :label="__('Payment Date')" required>
                    <input id="payment_date" name="payment_date" type="date" required value="{{ old('payment_date', now()->toDateString()) }}" class="input">
                </x-field>
                <x-field name="payment_method" :label="__('Payment Method')" required>
                    <select id="payment_method" name="payment_method" class="input">
                        @foreach (\App\Models\InstructorLoanPayment::METHODS as $method)
                            <option value="{{ $method }}">{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field name="reference" :label="__('Reference')">
                    <input id="reference" name="reference" value="{{ old('reference') }}" class="input">
                </x-field>
                <button class="btn-primary w-full">{{ __('Record Repayment') }}</button>
            </form>
        </div>
    @endif

    <div class="card {{ in_array($loan->status, ['paid', 'cancelled'], true) ? 'lg:col-span-3' : 'lg:col-span-2' }}">
        <div class="card-header"><h3 class="card-title">{{ __('Repayments') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Reference') }}</th><th>{{ __('By') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                            <td class="text-sm text-slate-500">{{ $payment->reference ?: '—' }}</td>
                            <td class="text-sm text-slate-500">{{ $payment->creator?->name ?? '—' }}</td>
                            <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
