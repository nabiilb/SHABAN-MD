@extends('layouts.app')
@section('title', $supplier->name)
@section('heading', $supplier->name)
@section('subheading', $supplier->supplier_number.' · '.__(ucwords(str_replace('_', ' ', $supplier->supplier_type))))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="mb-5 flex flex-wrap gap-2">
    <a href="{{ route('admin.suppliers.edit', $supplier) }}" class="btn-primary">{{ __('Edit') }}</a>
    <a href="{{ route('admin.debts.create') }}" class="btn-secondary">{{ __('New Debt') }}</a>
    <a href="{{ route('admin.reports.show', ['report' => 'supplier-statement', 'supplier_id' => $supplier->id]) }}" class="btn-secondary">{{ __('Statement') }}</a>
</div>

<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Total Debts')" :value="$c.number_format($totals['debts'], 2)" icon="debt" tone="slate" />
    <x-stat-card :label="__('Total Paid')" :value="$c.number_format($totals['paid'], 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Outstanding Balance')" :value="$c.number_format($totals['outstanding'], 2)" icon="alert" tone="amber" />
    <x-stat-card :label="__('Total Expenses')" :value="$c.number_format($totals['expenses'], 2)" icon="money" tone="rose" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Contact') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Phone') => $supplier->phone ?: '—',
                __('Email') => $supplier->email ?: '—',
                __('Address') => $supplier->address ?: '—',
                __('Notes') => $supplier->notes ?: '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('Debts') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Description') }}</th><th>{{ __('Date') }}</th>
                    <th class="text-right">{{ __('Original') }}</th><th class="text-right">{{ __('Paid') }}</th>
                    <th class="text-right">{{ __('Remaining') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                    @forelse ($debts as $debt)
                        <tr>
                            <td><a href="{{ route('admin.debts.show', $debt) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $debt->debt_number }}</a></td>
                            <td class="max-w-40 truncate text-sm">{{ $debt->description }}</td>
                            <td class="whitespace-nowrap text-sm">{{ $debt->debt_date?->format('d/m/Y') }}</td>
                            <td class="text-right">{{ $c }}{{ number_format((float) $debt->original_amount, 2) }}</td>
                            <td class="text-right text-emerald-700">{{ $c }}{{ number_format((float) ($debt->paid_total ?? 0), 2) }}</td>
                            <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $debt->remaining_amount, 2) }}</td>
                            <td><x-status-badge :status="$debt->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Expenses') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Category') }}</th><th>{{ __('Description') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @forelse ($expenses as $expense)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $expense->expense_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $expense->category?->display_name }}</td>
                            <td class="max-w-40 truncate text-sm">{{ $expense->description }}</td>
                            <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $expense->amount, 2) }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Debt Payments') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Reference') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @forelse ($payments as $payment)
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
    </div>
</div>
@endsection
