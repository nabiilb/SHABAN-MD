@extends('layouts.app')
@section('title', __('Company Debts'))
@section('heading', __('Company Debts'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Company Debts')"
               :subtitle="__('Debts raised against your vehicles, and fuel you took on credit. A debt is not an expense until it is paid.')" />

<div class="mb-4 grid gap-4 sm:grid-cols-3">
    <x-stat-card :label="__('Original Total')" :value="$c.number_format($totals['original'], 2)" icon="debt" tone="slate" />
    <x-stat-card :label="__('Paid')" :value="$c.number_format($totals['original'] - $totals['remaining'], 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Outstanding')" :value="$c.number_format($totals['remaining'], 2)" icon="alert" tone="amber" />
</div>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucwords(str_replace('_', ' ', $status))) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('instructor.company-debts.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Number') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Description') }}</th>
                    <th>{{ __('Vehicle') }}</th><th>{{ __('Debt Date') }}</th><th>{{ __('Due Date') }}</th>
                    <th class="text-right">{{ __('Original') }}</th><th class="text-right">{{ __('Remaining') }}</th>
                    <th>{{ __('Status') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($debts as $debt)
                    <tr>
                        <td><a href="{{ route('instructor.company-debts.show', $debt) }}" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $debt->debt_number }}</a></td>
                        <td class="text-sm font-medium">{{ $debt->supplier?->name }}</td>
                        <td class="max-w-48 truncate text-sm">{{ $debt->description }}</td>
                        <td class="font-mono text-xs">{{ $debt->vehicle?->plate_number ?? '—' }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $debt->debt_date?->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap text-sm {{ $debt->is_overdue ? 'font-semibold text-rose-600' : '' }}">{{ $debt->due_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="whitespace-nowrap text-right">{{ $c }}{{ number_format((float) $debt->original_amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $debt->remaining_amount, 2) }}</td>
                        <td><x-status-badge :status="$debt->status" /></td>
                    </tr>
                @empty
                    <x-empty-state colspan="9" :message="__('No company debts found.')" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($debts->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $debts->links() }}</div>@endif
</div>
@endsection
