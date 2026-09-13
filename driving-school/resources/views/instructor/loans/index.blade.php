@extends('layouts.app')
@section('title', __('My Loan & Credit'))
@section('heading', __('My Loan & Credit'))
@section('subheading', __('Your own balance only'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <x-stat-card :label="__('Total Loan')" :value="$c.number_format($totals['total'], 2)" icon="money" tone="slate" />
    <x-stat-card :label="__('Paid')" :value="$c.number_format($totals['paid'], 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining')" :value="$c.number_format($totals['remaining'], 2)" icon="alert" tone="amber" />
</div>

<div class="card mt-6">
    <div class="card-header"><h3 class="card-title">{{ __('My Loans') }}</h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Reason') }}</th>
                <th class="text-right">{{ __('Amount') }}</th><th class="text-right">{{ __('Paid') }}</th>
                <th class="text-right">{{ __('Remaining') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
                @forelse ($loans as $loan)
                    <tr>
                        <td><a href="{{ route('instructor.loans.show', $loan) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $loan->loan_number }}</a></td>
                        <td class="whitespace-nowrap text-sm">{{ $loan->loan_date?->format('d/m/Y') }}</td>
                        <td class="max-w-40 truncate text-sm text-slate-500">{{ $loan->reason ?: '—' }}</td>
                        <td class="whitespace-nowrap text-right">{{ $c }}{{ number_format((float) $loan->amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right text-emerald-700">{{ $c }}{{ number_format($loan->paid_amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $loan->remaining_amount, 2) }}</td>
                        <td><x-status-badge :status="$loan->status" /></td>
                    </tr>
                @empty
                    <x-empty-state colspan="7" :message="__('You have no loans on record.')" />
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-500">
        {{ __('Loans are issued and repayments recorded by the administration. This page is read-only.') }}
    </div>
</div>
@endsection
