@extends('layouts.app')
@section('title', __('Company Debts'))
@section('heading', __('Company Debts'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Company Debts')" :subtitle="__('Amounts owed to suppliers. A debt is not an expense until it is paid.')">
    <x-slot:actions>
        <a href="{{ route('admin.debt-payments.index') }}" class="btn-secondary">{{ __('Payment History') }}</a>
        <a href="{{ route('admin.debts.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Debt') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="mb-4 grid gap-4 sm:grid-cols-3">
    <x-stat-card :label="__('Original Total')" :value="$c.number_format($totals['original'], 2)" icon="debt" tone="slate" />
    <x-stat-card :label="__('Paid')" :value="$c.number_format($totals['original'] - $totals['remaining'], 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Outstanding')" :value="$c.number_format($totals['remaining'], 2)" icon="alert" tone="amber" />
</div>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input name="search" value="{{ request('search') }}" class="input lg:col-span-2" placeholder="{{ __('Search description or number') }}">
        <select name="supplier_id" class="input">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\CompanyDebt::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucwords(str_replace('_', ' ', $status))) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="due_before" value="{{ request('due_before') }}" class="input" title="{{ __('Due before') }}">
        <div class="flex gap-2 lg:col-span-6">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.debts.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Number') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Description') }}</th>
                    <th>{{ __('Debt Date') }}</th><th>{{ __('Due Date') }}</th>
                    <th class="text-right">{{ __('Original') }}</th><th class="text-right">{{ __('Paid') }}</th>
                    <th class="text-right">{{ __('Remaining') }}</th><th>{{ __('Status') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($debts as $debt)
                    <tr>
                        <td><a href="{{ route('admin.debts.show', $debt) }}" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $debt->debt_number }}</a></td>
                        <td class="text-sm font-medium">{{ $debt->supplier?->name }}</td>
                        <td class="max-w-48 truncate text-sm">{{ $debt->description }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $debt->debt_date?->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap text-sm {{ $debt->is_overdue ? 'font-semibold text-rose-600' : '' }}">{{ $debt->due_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="whitespace-nowrap text-right">{{ $c }}{{ number_format((float) $debt->original_amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right text-emerald-700">{{ $c }}{{ number_format((float) ($debt->paid_total ?? 0), 2) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $debt->remaining_amount, 2) }}</td>
                        <td><x-status-badge :status="$debt->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.debts.show', $debt) }}" class="btn-ghost btn-sm">{{ __('Pay') }}</a>
                            <a href="{{ route('admin.debts.edit', $debt) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="10" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($debts->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $debts->links() }}</div>@endif
</div>
@endsection
