@extends('layouts.app')
@section('title', $instructor->exists ? __('Edit Instructor') : __('New Instructor'))
@section('heading', $instructor->exists ? __('Edit Instructor') : __('New Instructor'))

@section('content')
<form method="POST" action="{{ $instructor->exists ? route('admin.instructors.update', $instructor) : route('admin.instructors.store') }}">
    @csrf
    @if ($instructor->exists) @method('PUT') @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header"><h3 class="card-title">{{ __('Details') }}</h3></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-field name="full_name" :label="__('Full Name')" required class="sm:col-span-2">
                    <input id="full_name" name="full_name" value="{{ old('full_name', $instructor->full_name) }}" required class="input @error('full_name') input-error @enderror">
                </x-field>
                <x-field name="phone" :label="__('Phone')" required>
                    <input id="phone" name="phone" value="{{ old('phone', $instructor->phone) }}" required class="input @error('phone') input-error @enderror">
                </x-field>
                <x-field name="email" :label="__('Email')">
                    <input id="email" name="email" type="email" value="{{ old('email', $instructor->email) }}" class="input @error('email') input-error @enderror">
                </x-field>
                <x-field name="address" :label="__('Address')" class="sm:col-span-2">
                    <input id="address" name="address" value="{{ old('address', $instructor->address) }}" class="input">
                </x-field>
                <x-field name="qualification" :label="__('Qualification')">
                    <input id="qualification" name="qualification" value="{{ old('qualification', $instructor->qualification) }}" class="input">
                </x-field>
                <x-field name="joining_date" :label="__('Joining Date')" required>
                    <input id="joining_date" name="joining_date" type="date" required
                           value="{{ old('joining_date', $instructor->joining_date?->format('Y-m-d')) }}" class="input">
                </x-field>
                <x-field name="status" :label="__('Status')" required>
                    <select id="status" name="status" class="input">
                        @foreach (\App\Models\Instructor::STATUSES as $status)
                            <option value="{{ $status }}" @selected(old('status', $instructor->status) === $status)>{{ __(ucfirst($status)) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                    <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $instructor->notes) }}</textarea>
                </x-field>
            </div>
        </div>

        <div class="card" x-data="{ create: {{ $instructor->user_id ? 'true' : 'false' }} }">
            <div class="card-header"><h3 class="card-title">{{ __('Login Account') }}</h3></div>
            <div class="space-y-4 p-5">
                @if (! $instructor->user_id)
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="create_account" value="1" x-model="create"
                               class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        {{ __('Create a login account for this instructor') }}
                    </label>
                @else
                    <p class="text-sm text-slate-500">{{ __('This instructor signs in as :email', ['email' => $instructor->user->email]) }}</p>
                @endif

                <div x-show="create" x-cloak class="space-y-4">
                    <x-field name="account_email" :label="__('Account Email')">
                        <input id="account_email" name="account_email" type="email"
                               value="{{ old('account_email', $instructor->user?->email) }}" class="input @error('account_email') input-error @enderror">
                    </x-field>
                    <x-field name="account_password" :label="$instructor->user_id ? __('New Password (optional)') : __('Password')">
                        <input id="account_password" name="account_password" type="password" autocomplete="new-password"
                               class="input @error('account_password') input-error @enderror">
                    </x-field>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ $instructor->exists ? __('Save Changes') : __('Add Instructor') }}</button>
        <a href="{{ route('admin.instructors.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
