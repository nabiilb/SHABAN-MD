@extends('layouts.app')
@section('title', $user->exists ? __('Edit User') : __('New User'))
@section('heading', $user->exists ? __('Edit User') : __('New User'))

@section('content')
<form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
    @csrf
    @if ($user->exists) @method('PUT') @endif

    <div class="card max-w-2xl">
        <div class="card-header"><h3 class="card-title">{{ __('Account') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="name" :label="__('Name')" required>
                <input id="name" name="name" required value="{{ old('name', $user->name) }}" class="input @error('name') input-error @enderror">
            </x-field>
            <x-field name="email" :label="__('Email')" required>
                <input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}" class="input @error('email') input-error @enderror">
            </x-field>
            <x-field name="phone" :label="__('Phone')">
                <input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="input">
            </x-field>
            <x-field name="role_id" :label="__('Role')" required>
                <select id="role_id" name="role_id" required class="input @error('role_id') input-error @enderror">
                    <option value="">{{ __('Select a role') }}</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected(old('role_id', $user->role_id) == $role->id)>{{ __($role->label) }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="locale" :label="__('Language')" required>
                <select id="locale" name="locale" class="input">
                    @foreach (config('app.supported_locales') as $code => $name)
                        <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </x-field>
            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active ?? true))
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    {{ __('Active') }}
                </label>
            </div>
            <x-field name="password" :label="$user->exists ? __('New Password (leave blank to keep)') : __('Password')" :required="! $user->exists">
                <input id="password" name="password" type="password" autocomplete="new-password" class="input @error('password') input-error @enderror">
            </x-field>
            <x-field name="password_confirmation" :label="__('Confirm Password')">
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="input">
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.users.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
