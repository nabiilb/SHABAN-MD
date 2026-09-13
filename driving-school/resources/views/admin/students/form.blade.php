@extends('layouts.app')

@section('title', $student->exists ? __('Edit Student') : __('New Student'))
@section('heading', $student->exists ? __('Edit Student') : __('New Student'))

@section('content')
<form method="POST" enctype="multipart/form-data"
      action="{{ $student->exists ? route('admin.students.update', $student) : route('admin.students.store') }}">
    @csrf
    @if ($student->exists) @method('PUT') @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header"><h3 class="card-title">{{ __('Personal Details') }}</h3></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-field name="full_name" :label="__('Full Name')" required class="sm:col-span-2">
                    <input id="full_name" name="full_name" value="{{ old('full_name', $student->full_name) }}" required class="input @error('full_name') input-error @enderror">
                </x-field>

                <x-field name="phone" :label="__('Phone')" required>
                    <input id="phone" name="phone" value="{{ old('phone', $student->phone) }}" required class="input @error('phone') input-error @enderror">
                </x-field>

                <x-field name="email" :label="__('Email')">
                    <input id="email" name="email" type="email" value="{{ old('email', $student->email) }}" class="input @error('email') input-error @enderror">
                </x-field>

                <x-field name="address" :label="__('Address')" class="sm:col-span-2">
                    <input id="address" name="address" value="{{ old('address', $student->address) }}" class="input">
                </x-field>

                <x-field name="date_of_birth" :label="__('Date of Birth')">
                    <input id="date_of_birth" name="date_of_birth" type="date"
                           value="{{ old('date_of_birth', $student->date_of_birth?->format('Y-m-d')) }}" class="input">
                </x-field>

                <x-field name="gender" :label="__('Gender')">
                    <select id="gender" name="gender" class="input">
                        <option value="">{{ __('Not specified') }}</option>
                        @foreach (['male', 'female', 'other'] as $gender)
                            <option value="{{ $gender }}" @selected(old('gender', $student->gender) === $gender)>{{ __(ucfirst($gender)) }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field name="profile_photo" :label="__('Profile Photo')" class="sm:col-span-2">
                    <input id="profile_photo" name="profile_photo" type="file" accept="image/*" class="input">
                </x-field>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Training') }}</h3></div>
                <div class="space-y-4 p-5">
                    <x-field name="license_type" :label="__('License Type')">
                        <input id="license_type" name="license_type" value="{{ old('license_type', $student->license_type) }}" class="input" placeholder="B">
                    </x-field>

                    <x-field name="start_date" :label="__('Start Date')" required>
                        <input id="start_date" name="start_date" type="date" required
                               value="{{ old('start_date', $student->start_date?->format('Y-m-d')) }}" class="input @error('start_date') input-error @enderror">
                    </x-field>

                    <x-field name="required_training_days" :label="__('Required Training Days')" required>
                        <input id="required_training_days" name="required_training_days" type="number" min="1" max="365" required
                               value="{{ old('required_training_days', $student->required_training_days ?: 24) }}" class="input">
                    </x-field>

                    <x-field name="current_instructor_id" :label="__('Current Instructor')">
                        <select id="current_instructor_id" name="current_instructor_id" class="input">
                            <option value="">{{ __('Unassigned') }}</option>
                            @foreach ($instructors as $instructor)
                                <option value="{{ $instructor->id }}" @selected(old('current_instructor_id', $student->current_instructor_id) == $instructor->id)>
                                    {{ $instructor->full_name }}
                                </option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field name="status" :label="__('Status')" required>
                        <select id="status" name="status" class="input">
                            @foreach (\App\Models\Student::STATUSES as $status)
                                <option value="{{ $status }}" @selected(old('status', $student->status) === $status)>{{ __(ucfirst($status)) }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field name="total_fee" :label="__('Total Fee')">
                        <input id="total_fee" name="total_fee" type="number" step="0.01" min="0"
                               value="{{ old('total_fee', $student->total_fee ?: '0.00') }}" class="input">
                    </x-field>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Notes') }}</h3></div>
                <div class="p-5">
                    <x-field name="notes">
                        <textarea id="notes" name="notes" rows="4" class="input">{{ old('notes', $student->notes) }}</textarea>
                    </x-field>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ $student->exists ? __('Save Changes') : __('Register Student') }}</button>
        <a href="{{ route('admin.students.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
