@extends('layouts.app')
@section('title', $fuel->fuel_number)
@section('heading', $fuel->fuel_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="card max-w-2xl p-5">
    <dl class="space-y-3 text-sm">
        @foreach ([
            __('Vehicle') => $fuel->vehicle?->label.' — '.$fuel->vehicle?->plate_number,
            __('Instructor') => $fuel->instructor?->full_name ?? '—',
            __('Petrol Station') => $fuel->supplier?->name ?? '—',
            __('Date') => $fuel->fuel_date?->format('d/m/Y'),
            __('Liters') => number_format((float) $fuel->liters, 2),
            __('Price per Liter') => $c.number_format((float) $fuel->price_per_liter, 2),
            __('Amount') => $c.number_format((float) $fuel->amount, 2),
            __('Odometer') => $fuel->odometer ? number_format($fuel->odometer).' km' : '—',
            __('Expense') => $fuel->expense?->expense_number ?? '—',
            __('Debt') => $fuel->debt?->debt_number ?? '—',
        ] as $label => $value)
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ $label }}</dt>
                <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
    <div class="mt-5 flex gap-2">
        <a href="{{ route('admin.fuel.edit', $fuel) }}" class="btn-primary btn-sm">{{ __('Edit') }}</a>
        <a href="{{ route('admin.fuel.index') }}" class="btn-secondary btn-sm">{{ __('Back') }}</a>
    </div>
</div>
@endsection
