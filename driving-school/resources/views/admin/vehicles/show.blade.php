@extends('layouts.app')
@section('title', $vehicle->label)
@section('heading', $vehicle->label)
@section('subheading', $vehicle->vehicle_number.' · '.$vehicle->plate_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="mb-5"><a href="{{ route('admin.vehicles.edit', $vehicle) }}" class="btn-primary">{{ __('Edit') }}</a></div>

<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Mileage')" :value="number_format($vehicle->mileage).' km'" icon="car" tone="slate" />
    <x-stat-card :label="__('Total Expenses')" :value="$c.number_format($totalExpenses, 2)" icon="money" tone="rose" />
    <x-stat-card :label="__('Lessons')" :value="$lessonCount" icon="book" tone="brand" />
    <x-stat-card :label="__('Status')" :value="__(ucwords(str_replace('_', ' ', $vehicle->status)))" icon="shield"
                 :tone="$vehicle->status === 'maintenance' ? 'amber' : 'green'" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Details') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Make') => $vehicle->make,
                __('Model') => $vehicle->model,
                __('Year') => $vehicle->year ?? '—',
                __('Color') => $vehicle->color ?? '—',
                __('Assigned Instructor') => $vehicle->instructor?->full_name ?? '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('Expenses') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Category') }}</th><th>{{ __('Description') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @forelse ($expenses as $expense)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $expense->expense_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $expense->category?->display_name }}</td>
                            <td class="text-sm">{{ $expense->description }}</td>
                            <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $expense->amount, 2) }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-6">
    <div class="card-header"><h3 class="card-title">{{ __('Fuel Records') }}</h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Liters') }}</th><th>{{ __('Price/L') }}</th><th>{{ __('Odometer') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @forelse ($fuelRecords as $fuel)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $fuel->fuel_date?->format('d/m/Y') }}</td>
                        <td class="text-sm">{{ number_format((float) $fuel->liters, 2) }}</td>
                        <td class="text-sm">{{ $c }}{{ number_format((float) $fuel->price_per_liter, 2) }}</td>
                        <td class="text-sm">{{ $fuel->odometer ? number_format($fuel->odometer).' km' : '—' }}</td>
                        <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $fuel->amount, 2) }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="5" />
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
