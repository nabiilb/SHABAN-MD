@extends('layouts.app')
@section('title', __('Company Debts'))
@section('heading', $debt->debt_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="$debt->debt_number" :subtitle="$debt->description">
    <x-slot:actions>
        <a href="{{ route('instructor.company-debts.index') }}" class="btn-secondary">← {{ __('Company Debts') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="mb-4 grid gap-4 sm:grid-cols-3">
    <x-stat-card :label="__('Original')" :value="$c.number_format((float) $debt->original_amount, 2)" icon="debt" tone="slate" />
    <x-stat-card :label="__('Paid')" :value="$c.number_format($debt->paid_amount, 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining')" :value="$c.number_format((float) $debt->remaining_amount, 2)" icon="alert" tone="amber" />
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="card lg:col-span-1">
        <div class="card-header"><h3 class="card-title">{{ __('Details') }}</h3></div>
        <dl class="space-y-3 p-5 text-sm">
            <div><dt class="text-slate-500">{{ __('Supplier') }}</dt><dd class="font-semibold text-slate-800">{{ $debt->supplier?->name }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Category') }}</dt><dd class="font-semibold text-slate-800">{{ $debt->category?->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Vehicle') }}</dt><dd class="font-semibold text-slate-800">{{ $debt->vehicle?->plate_number ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Debt Date') }}</dt><dd class="font-semibold text-slate-800">{{ $debt->debt_date?->format('d/m/Y') }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Due Date') }}</dt><dd class="font-semibold text-slate-800">{{ $debt->due_date?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd><x-status-badge :status="$debt->status" /></dd></div>
        </dl>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('Payment History') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Reference') }}</th>
                    <th class="text-right">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @forelse ($debt->payments->sortByDesc('payment_date') as $payment)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                            <td class="text-xs text-slate-500">{{ $payment->reference ?? '—' }}</td>
                            <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" :message="__('No payments recorded yet.')" />
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($debt->fuelRecords->isNotEmpty())
            <div class="card-header border-t border-slate-200"><h3 class="card-title">{{ __('Fuel on this credit') }}</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Vehicle') }}</th>
                        <th class="text-right">{{ __('Amount') }}</th></tr></thead>
                    <tbody>
                        @foreach ($debt->fuelRecords as $record)
                            <tr>
                                <td class="font-mono text-xs">{{ $record->fuel_number }}</td>
                                <td class="whitespace-nowrap text-sm">{{ $record->fuel_date?->format('d/m/Y') }}</td>
                                <td class="font-mono text-xs">{{ $record->vehicle?->plate_number }}</td>
                                <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $record->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
