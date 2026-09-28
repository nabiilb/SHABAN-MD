@extends('layouts.guest')

@section('title', __('Sign in'))

@section('content')
    <div class="mb-6 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-xl bg-brand-600 text-2xl font-bold text-white">A</span>
        <h1 class="mt-4 text-2xl font-bold text-white">{{ $appSettings['school_name'] }}</h1>
        <p class="mt-1 text-sm text-slate-400">{{ __('Driving School Management System') }}</p>
    </div>

    <div class="card p-6">
        <h2 class="text-lg font-bold text-slate-900">{{ __('Sign in') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ __('Enter your credentials to continue.') }}</p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <x-field name="email" :label="__('Email')" required>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username" class="input @error('email') input-error @enderror">
            </x-field>

            <x-field name="password" :label="__('Password')" required>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="input @error('password') input-error @enderror">
            </x-field>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                {{ __('Remember me') }}
            </label>

            <button type="submit" class="btn-primary w-full">{{ __('Sign in') }}</button>
        </form>
    </div>

    <div class="mt-6 flex items-center justify-center gap-2">
        @foreach (config('app.supported_locales') as $code => $name)
            <a href="{{ route('locale.switch', $code) }}"
               class="rounded-md px-3 py-1 text-xs font-semibold {{ app()->getLocale() === $code ? 'bg-white/15 text-white' : 'text-slate-400 hover:text-white' }}">
                {{ $name }}
            </a>
        @endforeach
    </div>
@endsection
