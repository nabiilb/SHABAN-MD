@extends('layouts.app')
@section('title', __('Vehicles'))
@section('heading', __('Vehicles'))

@section('content')
<x-page-header :title="__('Vehicles')" :subtitle="__(':count in the fleet', ['count' => $vehicles->total()])">
    <x-slot:actions>
        <a href="{{ route('admin.vehicles.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Vehicle') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <input name="search" value="{{ request('search') }}" class="input" placeholder="{{ __('Search plate, number, make or model') }}">
        <select name="instructor_id" class="input">
            <option value="">{{ __('All instructors') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Vehicle::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucwords(str_replace('_', ' ', $status))) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.vehicles.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Vehicle') }}</th><th>{{ __('Plate') }}</th><th>{{ __('Year') }}</th><th>{{ __('Color') }}</th>
                    <th>{{ __('Mileage') }}</th><th>{{ __('Assigned Instructor') }}</th><th>{{ __('Status') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vehicles as $vehicle)
                    <tr>
                        <td>
                            <a href="{{ route('admin.vehicles.show', $vehicle) }}" class="font-semibold text-brand-700 hover:underline">{{ $vehicle->label }}</a>
                            <p class="text-xs text-slate-400">{{ $vehicle->vehicle_number }}</p>
                        </td>
                        <td class="font-mono text-sm">{{ $vehicle->plate_number }}</td>
                        <td class="text-sm">{{ $vehicle->year ?? '—' }}</td>
                        <td class="text-sm">{{ $vehicle->color ?? '—' }}</td>
                        <td class="whitespace-nowrap text-sm">{{ number_format($vehicle->mileage) }} km</td>
                        <td class="text-sm">{{ $vehicle->instructor?->full_name ?? '—' }}</td>
                        <td><x-status-badge :status="$vehicle->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.vehicles.edit', $vehicle) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.vehicles.destroy', $vehicle)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="8" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($vehicles->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $vehicles->links() }}</div>@endif
</div>
@endsection
