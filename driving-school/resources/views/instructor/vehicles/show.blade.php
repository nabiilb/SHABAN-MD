@extends('layouts.app')
@section('title', $vehicle->label)
@section('heading', $vehicle->label)
@section('subheading', $vehicle->vehicle_number.' · '.$vehicle->plate_number)

@section('content')
<div class="grid gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Details') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Make') => $vehicle->make,
                __('Model') => $vehicle->model,
                __('Year') => $vehicle->year ?? '—',
                __('Color') => $vehicle->color ?? '—',
                __('Mileage') => number_format($vehicle->mileage).' km',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd><x-status-badge :status="$vehicle->status" /></dd>
            </div>
        </dl>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('My Lessons in This Vehicle') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Duration') }}</th></tr></thead>
                <tbody>
                    @forelse ($lessons as $lesson)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $lesson->lesson_date?->format('d/m/Y') }}</td>
                            <td class="text-sm font-medium">{{ $lesson->student?->full_name }}</td>
                            <td class="text-sm">{{ $lesson->duration_minutes }} {{ __('min') }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
