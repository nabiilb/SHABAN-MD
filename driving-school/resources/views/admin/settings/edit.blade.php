@extends('layouts.app')
@section('title', __('Settings'))
@section('heading', __('Settings'))

@php
    $get = fn (string $key, $default = '') => old($key, \App\Models\Setting::get($key, $default));
@endphp

@section('content')
<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf @method('PUT')

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('School') }}</h3></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-field name="school_name" :label="__('School Name')" required class="sm:col-span-2">
                    <input id="school_name" name="school_name" required value="{{ $get('school_name', config('app.name')) }}" class="input">
                </x-field>
                <x-field name="school_phone" :label="__('Phone')">
                    <input id="school_phone" name="school_phone" value="{{ $get('school_phone') }}" class="input">
                </x-field>
                <x-field name="school_email" :label="__('Email')">
                    <input id="school_email" name="school_email" type="email" value="{{ $get('school_email') }}" class="input">
                </x-field>
                <x-field name="school_address" :label="__('Address')" class="sm:col-span-2">
                    <input id="school_address" name="school_address" value="{{ $get('school_address') }}" class="input">
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Operations') }}</h3></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-field name="currency" :label="__('Currency')" required>
                    <input id="currency" name="currency" required value="{{ $get('currency', 'USD') }}" class="input">
                </x-field>
                <x-field name="currency_symbol" :label="__('Currency Symbol')" required>
                    <input id="currency_symbol" name="currency_symbol" required value="{{ $get('currency_symbol', '$') }}" class="input">
                </x-field>
                <x-field name="default_training_days" :label="__('Default Training Days')" required>
                    <input id="default_training_days" name="default_training_days" type="number" min="1" max="365" required
                           value="{{ $get('default_training_days', 24) }}" class="input">
                </x-field>
                <x-field name="near_completion_threshold" :label="__('Near Completion Threshold (%)')" required>
                    <input id="near_completion_threshold" name="near_completion_threshold" type="number" min="1" max="100" required
                           value="{{ $get('near_completion_threshold', 80) }}" class="input">
                </x-field>
                <x-field name="default_locale" :label="__('Default Language')" required class="sm:col-span-2">
                    <select id="default_locale" name="default_locale" class="input">
                        @foreach (config('app.supported_locales') as $code => $name)
                            <option value="{{ $code }}" @selected($get('default_locale', config('app.locale')) === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </x-field>
                <label class="flex items-start gap-2 text-sm text-slate-700 sm:col-span-2">
                    <input type="checkbox" name="allow_duplicate_attendance" value="1"
                           @checked(\App\Models\Setting::flag('allow_duplicate_attendance'))
                           class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>
                        {{ __('Allow duplicate attendance') }}
                        <span class="block text-xs text-slate-400">{{ __('By default a student can only be checked in once per day.') }}</span>
                    </span>
                </label>
            </div>
        </div>
    </div>

    <div class="mt-6"><button class="btn-primary">{{ __('Save Settings') }}</button></div>
</form>
@endsection
