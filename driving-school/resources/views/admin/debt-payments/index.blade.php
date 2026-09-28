@extends('layouts.app')
@section('title', __('Debt Payments'))
@section('heading', __('Debt Payments'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Debt Payments')" :subtitle="__('Every payment also books a company expense')" />

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-4">
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <select name="payment_method" class="input">
            <option value="">{{ __('All methods') }}</option>
            @foreach (\App\Models\DebtPayment::METHODS as $method)
                <option value="{{ $method }}" @selected(request('payment_method') === $method)>{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.debt-payments.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Debt') }}</th><th>{{ __('Supplier') }}</th>
                    <th>{{ __('Method') }}</th><th>{{ __('Expense') }}</th><th>{{ __('By') }}</th>
                    <th class="text-right">{{ __('Amount') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                        <td><a href="{{ route('admin.debts.show', $payment->companyDebt) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $payment->companyDebt?->debt_number }}</a></td>
                        <td class="text-sm">{{ $payment->companyDebt?->supplier?->name }}</td>
                        <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                        <td class="font-mono text-xs">{{ $payment->expense?->expense_number ?? '—' }}</td>
                        <td class="text-sm text-slate-500">{{ $payment->creator?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="7" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($payments->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $payments->links() }}</div>@endif
</div>
@endsection
