@extends('layouts.app')
@section('title', __('Income & Expenses'))
@section('heading', __('Income & Expenses'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Income & Expenses')" :subtitle="__('Only cash that actually leaves the company is an expense')">
    <x-slot:actions>
        <a href="{{ route('admin.expense-categories.index') }}" class="btn-secondary">{{ __('Categories') }}</a>
        <a href="{{ route('admin.expenses.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Expense') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="mb-4 grid gap-4 sm:grid-cols-3">
    <x-stat-card :label="__('Income')" :value="$c.number_format($totals['income'], 2)" icon="money" tone="green" />
    <x-stat-card :label="__('Expenses')" :value="$c.number_format($totals['expenses'], 2)" icon="money" tone="rose" />
    <x-stat-card :label="__('Net')" :value="$c.number_format($totals['net'], 2)" icon="trend" :tone="$totals['net'] >= 0 ? 'green' : 'rose'" />
</div>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input name="search" value="{{ request('search') }}" class="input lg:col-span-2" placeholder="{{ __('Search description or number') }}">
        <select name="expense_category_id" class="input">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(request('expense_category_id') == $category->id)>{{ $category->display_name }}</option>
            @endforeach
        </select>
        <select name="vehicle_id" class="input">
            <option value="">{{ __('All vehicles') }}</option>
            @foreach ($vehicles as $vehicle)
                <option value="{{ $vehicle->id }}" @selected(request('vehicle_id') == $vehicle->id)>{{ $vehicle->plate_number }}</option>
            @endforeach
        </select>
        <select name="supplier_id" class="input">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select name="payment_method" class="input">
            <option value="">{{ __('All methods') }}</option>
            @foreach (\App\Models\CompanyExpense::METHODS as $method)
                <option value="{{ $method }}" @selected(request('payment_method') === $method)>{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2 lg:col-span-4">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.expenses.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Category') }}</th>
                    <th>{{ __('Description') }}</th><th>{{ __('Vehicle') }}</th><th>{{ __('Supplier') }}</th>
                    <th>{{ __('Method') }}</th><th class="text-right">{{ __('Amount') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($expenses as $expense)
                    <tr>
                        <td>
                            <a href="{{ route('admin.expenses.show', $expense) }}" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $expense->expense_number }}</a>
                            @if ($expense->is_system_generated)
                                <span class="ml-1 badge-blue">{{ __('Debt payment') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $expense->expense_date?->format('d/m/Y') }}</td>
                        <td class="text-sm">{{ $expense->category?->display_name }}</td>
                        <td class="max-w-56 truncate text-sm">{{ $expense->description }}</td>
                        <td class="font-mono text-xs">{{ $expense->vehicle?->plate_number ?? '—' }}</td>
                        <td class="text-sm">{{ $expense->supplier?->name ?? '—' }}</td>
                        <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $expense->payment_method))) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $expense->amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right">
                            @unless ($expense->is_system_generated)
                                <a href="{{ route('admin.expenses.edit', $expense) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                                <x-delete-form :action="route('admin.expenses.destroy', $expense)" />
                            @else
                                <span class="text-xs text-slate-400">{{ __('Locked') }}</span>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="9" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($expenses->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $expenses->links() }}</div>@endif
</div>
@endsection
