@extends('layouts.app')
@section('title', __('Fuel'))
@section('heading', __('Fuel'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Fuel Records')"
               :subtitle="__('Fill-ups recorded against you, and fuel bought for the vehicles assigned to you.')">
    @can('create', \App\Models\FuelRecord::class)
        <x-slot:actions>
            <a href="{{ route('instructor.fuel.create') }}" class="btn-primary">
                <x-icon name="plus" class="h-4 w-4" /> {{ __('Add Fuel') }}
            </a>
        </x-slot:actions>
    @endcan
</x-page-header>

<div class="mb-4 grid gap-4 sm:grid-cols-2">
    <x-stat-card :label="__('Total Fuel Cost')" :value="$c.number_format($totals['amount'], 2)" icon="fuel" tone="rose" />
    <x-stat-card :label="__('Total Liters')" :value="number_format($totals['liters'], 2)" icon="fuel" tone="slate" />
</div>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <select name="vehicle_id" class="input">
            <option value="">{{ __('All vehicles') }}</option>
            @foreach ($vehicles as $vehicle)
                <option value="{{ $vehicle->id }}" @selected(request('vehicle_id') == $vehicle->id)>{{ $vehicle->plate_number }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('instructor.fuel.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Vehicle') }}</th>
                    <th>{{ __('Liters') }}</th><th>{{ __('Price/L') }}</th><th>{{ __('Paid') }}</th>
                    <th>{{ __('Status') }}</th><th class="text-right">{{ __('Amount') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td><a href="{{ route('instructor.fuel.show', $record) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $record->fuel_number }}</a></td>
                        <td class="whitespace-nowrap text-sm">{{ $record->fuel_date?->format('d/m/Y') }}</td>
                        <td class="font-mono text-xs">{{ $record->vehicle?->plate_number }}</td>
                        <td class="text-sm">{{ number_format((float) $record->liters, 2) }}</td>
                        <td class="text-sm">{{ $c }}{{ number_format((float) $record->price_per_liter, 2) }}</td>
                        <td>
                            @if ($record->is_credit)
                                <span class="badge-amber">{{ __('On credit') }}</span>
                            @else
                                <span class="badge-green">{{ __('Cash') }}</span>
                            @endif
                        </td>
                        <td>
                            <x-status-badge :status="$record->status" />
                            @if ($record->status === \App\Models\FuelRecord::REJECTED && $record->rejection_reason)
                                <span class="block text-xs text-slate-400">{{ $record->rejection_reason }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $record->amount, 2) }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="8" :message="__('No fuel records found.')" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($records->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>@endif
</div>
@endsection
