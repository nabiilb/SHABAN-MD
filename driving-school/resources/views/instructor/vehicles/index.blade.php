@extends('layouts.app')
@section('title', __('My Vehicles'))
@section('heading', __('My Vehicles'))
@section('subheading', __('Only the vehicles assigned to you'))

@section('content')
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @forelse ($vehicles as $vehicle)
        <a href="{{ route('instructor.vehicles.show', $vehicle) }}" class="card p-5 transition hover:border-brand-300 hover:shadow-md">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-lg font-bold text-slate-900">{{ $vehicle->label }}</p>
                    <p class="font-mono text-sm text-slate-500">{{ $vehicle->plate_number }}</p>
                </div>
                <x-status-badge :status="$vehicle->status" />
            </div>
            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Number') }}</dt><dd class="font-medium">{{ $vehicle->vehicle_number }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Year') }}</dt><dd class="font-medium">{{ $vehicle->year ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Color') }}</dt><dd class="font-medium">{{ $vehicle->color ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Mileage') }}</dt><dd class="font-medium">{{ number_format($vehicle->mileage) }} km</dd></div>
            </dl>
        </a>
    @empty
        <div class="card p-10 text-center text-sm text-slate-400 lg:col-span-3">{{ __('No vehicles are assigned to you.') }}</div>
    @endforelse
</div>

@if ($vehicles->hasPages())<div class="mt-4">{{ $vehicles->links() }}</div>@endif
@endsection
