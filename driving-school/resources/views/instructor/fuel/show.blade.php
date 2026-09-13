@extends('layouts.app')
@section('title', __('Fuel'))
@section('heading', $fuel->fuel_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="$fuel->fuel_number" :subtitle="$fuel->fuel_date?->format('d/m/Y')">
    <x-slot:actions>
        <a href="{{ route('instructor.fuel.index') }}" class="btn-secondary">← {{ __('Fuel') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card">
    <div class="card-header"><h3 class="card-title">{{ __('Fuel Record') }}</h3></div>
    <dl class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
        <div><dt class="text-sm text-slate-500">{{ __('Vehicle') }}</dt>
            <dd class="font-semibold text-slate-800">{{ $fuel->vehicle?->plate_number }} · {{ $fuel->vehicle?->label }}</dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Instructor') }}</dt>
            <dd class="font-semibold text-slate-800">{{ $fuel->instructor?->full_name ?? '—' }}</dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Supplier') }}</dt>
            <dd class="font-semibold text-slate-800">{{ $fuel->supplier?->name ?? '—' }}</dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Liters') }}</dt>
            <dd class="font-semibold text-slate-800">{{ number_format((float) $fuel->liters, 2) }}</dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Price/L') }}</dt>
            <dd class="font-semibold text-slate-800">{{ $c }}{{ number_format((float) $fuel->price_per_liter, 2) }}</dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Amount') }}</dt>
            <dd class="text-lg font-bold text-slate-900">{{ $c }}{{ number_format((float) $fuel->amount, 2) }}</dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Odometer') }}</dt>
            <dd class="font-semibold text-slate-800">{{ $fuel->odometer ? number_format($fuel->odometer) : '—' }}</dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Status') }}</dt>
            <dd>
                <x-status-badge :status="$fuel->status" />
                @if ($fuel->status === \App\Models\FuelRecord::PENDING)
                    <span class="block text-xs text-slate-400">{{ __('Waiting for an admin to approve it.') }}</span>
                @elseif ($fuel->rejection_reason)
                    <span class="block text-xs text-slate-400">{{ $fuel->rejection_reason }}</span>
                @endif
            </dd></div>
        <div><dt class="text-sm text-slate-500">{{ __('Paid') }}</dt>
            <dd>
                @if ($fuel->is_credit)
                    <span class="badge-amber">{{ __('On credit') }}</span>
                @else
                    <span class="badge-green">{{ __('Cash') }}</span>
                @endif
            </dd></div>
        @if ($fuel->debt)
            <div><dt class="text-sm text-slate-500">{{ __('Company Debt') }}</dt>
                <dd class="font-semibold">
                    <a href="{{ route('instructor.company-debts.show', $fuel->debt) }}"
                       class="font-mono text-xs text-brand-700 hover:underline">{{ $fuel->debt->debt_number }}</a>
                </dd></div>
        @endif
        @if ($fuel->notes)
            <div class="sm:col-span-2 lg:col-span-3"><dt class="text-sm text-slate-500">{{ __('Notes') }}</dt>
                <dd class="text-sm text-slate-700">{{ $fuel->notes }}</dd></div>
        @endif
    </dl>
</div>
@endsection
