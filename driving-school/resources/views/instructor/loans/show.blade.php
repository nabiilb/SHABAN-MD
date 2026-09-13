@extends('layouts.app')
@section('title', $loan->loan_number)
@section('heading', $loan->loan_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Total Loan')" :value="$c.number_format((float) $loan->amount, 2)" icon="money" tone="slate" />
    <x-stat-card :label="__('Paid')" :value="$c.number_format($loan->paid_amount, 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining')" :value="$c.number_format((float) $loan->remaining_amount, 2)" icon="alert" tone="amber" />
    <x-stat-card :label="__('Status')" :value="__(ucwords(str_replace('_', ' ', $loan->status)))" icon="shield"
                 :tone="$loan->status === 'paid' ? 'green' : 'amber'" />
</div>

<div class="card mt-6">
    <div class="card-header"><h3 class="card-title">{{ __('Repayments') }}</h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Reference') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @forelse ($loan->payments->sortByDesc('payment_date') as $payment)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                        <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                        <td class="text-sm text-slate-500">{{ $payment->reference ?: '—' }}</td>
                        <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="4" />
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-200 p-5">
        <a href="{{ route('instructor.loans.index') }}" class="btn-secondary btn-sm">{{ __('Back') }}</a>
    </div>
</div>
@endsection
