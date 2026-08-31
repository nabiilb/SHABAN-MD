@extends('layouts.app')
@section('title', __('Fuel'))
@section('heading', __('Fuel'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Fuel Records')">
    <x-slot:actions>
        <a href="{{ route('admin.fuel.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Fuel Record') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="mb-4 grid gap-4 sm:grid-cols-2">
    <x-stat-card :label="__('Total Fuel Cost')" :value="$c.number_format($totals['amount'], 2)" icon="fuel" tone="rose" />
    <x-stat-card :label="__('Total Liters')" :value="number_format($totals['liters'], 2)" icon="fuel" tone="slate" />
</div>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <select name="vehicle_id" class="input">
            <option value="">{{ __('All vehicles') }}</option>
            @foreach ($vehicles as $vehicle)
                <option value="{{ $vehicle->id }}" @selected(request('vehicle_id') == $vehicle->id)>{{ $vehicle->plate_number }}</option>
            @endforeach
        </select>
        <select name="instructor_id" class="input">
            <option value="">{{ __('All instructors') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.fuel.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Vehicle') }}</th><th>{{ __('Instructor') }}</th>
                    <th>{{ __('Liters') }}</th><th>{{ __('Price/L') }}</th><th>{{ __('Paid') }}</th>
                    <th class="text-right">{{ __('Amount') }}</th><th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td><a href="{{ route('admin.fuel.show', $record) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $record->fuel_number }}</a></td>
                        <td class="whitespace-nowrap text-sm">{{ $record->fuel_date?->format('d/m/Y') }}</td>
                        <td class="font-mono text-xs">{{ $record->vehicle?->plate_number }}</td>
                        <td class="text-sm">{{ $record->instructor?->full_name ?? '—' }}</td>
                        <td class="text-sm">{{ number_format((float) $record->liters, 2) }}</td>
                        <td class="text-sm">{{ $c }}{{ number_format((float) $record->price_per_liter, 2) }}</td>
                        <td>
                            @if ($record->is_credit)
                                <span class="badge-amber">{{ __('On credit') }}</span>
                            @else
                                <span class="badge-green">{{ __('Cash') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $record->amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.fuel.edit', $record) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.fuel.destroy', $record)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="9" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($records->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>@endif
</div>
@endsection
