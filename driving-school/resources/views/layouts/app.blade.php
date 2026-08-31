<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Dashboard')) — {{ $appSettings['school_name'] }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100">
<div x-data="{ sidebarOpen: false }" class="min-h-screen lg:flex">

    {{-- Mobile backdrop --}}
    <div x-show="sidebarOpen" x-cloak x-transition.opacity
         @click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col bg-shell-900 transition-transform duration-200 lg:static lg:translate-x-0 no-print"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-lg font-bold text-white">A</span>
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-white">{{ $appSettings['school_name'] }}</p>
                <p class="truncate text-[11px] uppercase tracking-wider text-slate-400">{{ __(ucfirst(auth()->user()->role?->name ?? '')) }}</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 pb-6">
            @if (auth()->user()->isAdmin())
                @include('layouts.partials.sidebar-admin')
            @elseif (auth()->user()->isInstructor())
                @include('layouts.partials.sidebar-instructor')
            @else
                @include('layouts.partials.sidebar-student')
            @endif
        </nav>

        <div class="border-t border-white/10 p-3">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-link w-full">
                    <x-icon name="logout" class="h-5 w-5" />
                    <span>{{ __('Sign out') }}</span>
                </button>
            </form>
        </div>
    </aside>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white px-4 sm:px-6 no-print">
            <button type="button" @click="sidebarOpen = true" class="btn-ghost -ml-2 p-2 lg:hidden">
                <x-icon name="menu" class="h-5 w-5" />
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-lg font-bold text-slate-900">@yield('heading', __('Dashboard'))</h1>
                @hasSection('subheading')
                    <p class="truncate text-xs text-slate-500">@yield('subheading')</p>
                @endif
            </div>

            {{-- Language switcher: the Somali/English toggle from the design --}}
            <div class="flex items-center gap-1 rounded-lg bg-slate-100 p-1">
                @foreach (config('app.supported_locales') as $code => $name)
                    <a href="{{ route('locale.switch', $code) }}"
                       class="rounded-md px-2.5 py-1 text-xs font-semibold transition
                              {{ app()->getLocale() === $code ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                        {{ strtoupper($code) }}
                    </a>
                @endforeach
            </div>

            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" type="button" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-100">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700">
                        {{ Str::of(auth()->user()->name)->substr(0, 1)->upper() }}
                    </span>
                    <span class="hidden text-sm font-medium text-slate-700 sm:block">{{ auth()->user()->name }}</span>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" x-transition
                     class="absolute right-0 mt-2 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('My Account') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="block w-full px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">{{ __('Sign out') }}</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            <x-alerts />
            @yield('content')
        </main>

        <footer class="px-6 pb-6 text-center text-xs text-slate-400 no-print">
            {{ $appSettings['school_name'] }} — {{ __('Driving School Management System') }}
        </footer>
    </div>
</div>
</body>
</html>
