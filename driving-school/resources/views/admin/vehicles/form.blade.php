@extends('layouts.app')
@section('title', $vehicle->exists ? __('Edit Vehicle') : __('New Vehicle'))
@section('heading', $vehicle->exists ? __('Edit Vehicle') : __('New Vehicle'))

@section('content')
<form method="POST" action="{{ $vehicle->exists ? route('admin.vehicles.update', $vehicle) : route('admin.vehicles.store') }}">
    @csrf
    @if ($vehicle->exists) @method('PUT') @endif

    <div class="card max-w-3xl">
        <div class="card-header"><h3 class="card-title">{{ __('Vehicle Details') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="plate_number" :label="__('Plate Number')" required>
                <input id="plate_number" name="plate_number" value="{{ old('plate_number', $vehicle->plate_number) }}" required class="input @error('plate_number') input-error @enderror">
            </x-field>
            <x-field name="status" :label="__('Status')" required>
                <select id="status" name="status" class="input">
                    @foreach (\App\Models\Vehicle::STATUSES as $status)
                        <option value="{{ $status }}" @selected(old('status', $vehicle->status) === $status)>{{ __(ucwords(str_replace('_', ' ', $status))) }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="make" :label="__('Make')" required>
                <input id="make" name="make" value="{{ old('make', $vehicle->make) }}" required class="input @error('make') input-error @enderror" placeholder="Toyota">
            </x-field>
            <x-field name="model" :label="__('Model')" required>
                <input id="model" name="model" value="{{ old('model', $vehicle->model) }}" required class="input @error('model') input-error @enderror" placeholder="Corolla">
            </x-field>
            <x-field name="year" :label="__('Year')">
                <input id="year" name="year" type="number" min="1950" max="{{ date('Y') + 1 }}" value="{{ old('year', $vehicle->year) }}" class="input">
            </x-field>
            <x-field name="color" :label="__('Color')">
                <input id="color" name="color" value="{{ old('color', $vehicle->color) }}" class="input">
            </x-field>
            <x-field name="mileage" :label="__('Mileage')">
                <input id="mileage" name="mileage" type="number" min="0" value="{{ old('mileage', $vehicle->mileage ?: 0) }}" class="input">
            </x-field>
            <x-field name="instructor_id" :label="__('Assigned Instructor')">
                <select id="instructor_id" name="instructor_id" class="input">
                    <option value="">{{ __('Unassigned') }}</option>
                    @foreach ($instructors as $instructor)
                        <option value="{{ $instructor->id }}" @selected(old('instructor_id', $vehicle->instructor_id) == $instructor->id)>{{ $instructor->full_name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $vehicle->notes) }}</textarea>
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ $vehicle->exists ? __('Save Changes') : __('Add Vehicle') }}</button>
        <a href="{{ route('admin.vehicles.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
