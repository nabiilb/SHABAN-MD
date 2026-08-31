@extends('layouts.app')

@section('title', __('My Account'))
@section('heading', __('My Account'))

@section('content')
<div class="grid gap-6 lg:grid-cols-2">
    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Profile') }}</h3></div>
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4 p-5">
            @csrf @method('PUT')

            <x-field name="name" :label="__('Full Name')" required>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required class="input">
            </x-field>

            <x-field name="email" :label="__('Email')" required>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required class="input">
            </x-field>

            <x-field name="phone" :label="__('Phone')">
                <input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="input">
            </x-field>

            <x-field name="locale" :label="__('Language')" required>
                <select id="locale" name="locale" class="input">
                    @foreach (config('app.supported_locales') as $code => $name)
                        <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </x-field>

            <button class="btn-primary">{{ __('Save') }}</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Change Password') }}</h3></div>
        <form method="POST" action="{{ route('profile.password') }}" class="space-y-4 p-5">
            @csrf @method('PUT')

            <x-field name="current_password" :label="__('Current Password')" required>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="input">
            </x-field>

            <x-field name="password" :label="__('New Password')" required>
                <input id="password" name="password" type="password" required autocomplete="new-password" class="input">
            </x-field>

            <x-field name="password_confirmation" :label="__('Confirm New Password')" required>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input">
            </x-field>

            <button class="btn-primary">{{ __('Update Password') }}</button>
        </form>
    </div>
</div>
@endsection
