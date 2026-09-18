<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Dashboard')) — {{ $appSettings['school_name'] }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100">
{{--
    One sidebar, read two ways. On a large screen it is a column of the page;
    below `lg` the same markup becomes an off-canvas drawer, so there is no
    second copy of the navigation to keep in step.

    While the drawer is open the page behind it does not scroll — `drawer-open`
    on <body> — and it closes on the backdrop, on Escape, and on any navigation
    link, because tapping a link and finding the menu still covering the page
    is the thing that makes a mobile drawer feel broken.
--}}
<div x-data="{
         sidebarOpen: false,
         open() { this.sidebarOpen = true; document.body.classList.add('drawer-open'); },
         close() { this.sidebarOpen = false; document.body.classList.remove('drawer-open'); },
     }"
     @keydown.escape.window="close()"
     class="min-h-screen lg:flex">

    {{-- Mobile backdrop --}}
    <div x-show="sidebarOpen" x-cloak x-transition.opacity
         @click="close()"
         class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-72 max-w-[85vw] shrink-0 flex-col bg-shell-900 transition-transform duration-200 lg:static lg:w-64 lg:max-w-none lg:translate-x-0 no-print"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-lg font-bold text-white">A</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold text-white">{{ $appSettings['school_name'] }}</p>
                <p class="truncate text-[11px] uppercase tracking-wider text-slate-400">{{ __(ucfirst(auth()->user()->role?->name ?? '')) }}</p>
            </div>

            {{-- A way out that does not depend on finding the backdrop. --}}
            <button type="button" @click="close()"
                    class="-mr-2 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-slate-300 hover:bg-white/10 hover:text-white lg:hidden"
                    aria-label="{{ __('Close menu') }}">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        {{-- Any link inside closes the drawer; on desktop close() is a no-op. --}}
        <nav class="flex-1 overflow-y-auto px-3 pb-6" @click="if ($event.target.closest('a')) close()">
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
        <header class="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-slate-200 bg-white px-3 sm:gap-4 sm:px-6 no-print">
            <button type="button" @click="open()"
                    class="btn-ghost -ml-2 h-11 w-11 shrink-0 p-0 lg:hidden"
                    aria-label="{{ __('Open menu') }}">
                <x-icon name="menu" class="h-5 w-5" />
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-lg font-bold text-slate-900">@yield('heading', __('Dashboard'))</h1>
                @hasSection('subheading')
                    <p class="truncate text-xs text-slate-500">@yield('subheading')</p>
                @endif
            </div>

            {{-- Language switcher: the Somali/English toggle from the design --}}
            <div class="flex shrink-0 items-center gap-1 rounded-lg bg-slate-100 p-1">
                @foreach (config('app.supported_locales') as $code => $name)
                    <a href="{{ route('locale.switch', $code) }}"
                       class="rounded-md px-2.5 py-1 text-xs font-semibold transition
                              {{ app()->getLocale() === $code ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                        {{ strtoupper($code) }}
                    </a>
                @endforeach
            </div>

            <div x-data="{ open: false }" class="relative shrink-0">
                <button @click="open = !open" type="button"
                        class="flex min-h-11 items-center gap-2 rounded-lg px-1.5 py-1.5 hover:bg-slate-100 sm:px-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700">
                        {{ Str::of(auth()->user()->name)->substr(0, 1)->upper() }}
                    </span>
                    {{-- The name is desktop-only and capped: a long one must not
                         push the header past the edge of a phone. --}}
                    <span class="hidden max-w-32 truncate text-sm font-medium text-slate-700 lg:block">{{ auth()->user()->name }}</span>
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

        <main class="min-w-0 flex-1 p-3 sm:p-6">
            <x-alerts />
            @yield('content')
        </main>

        <footer class="px-4 pb-6 text-center text-xs text-slate-400 sm:px-6 no-print">
            {{ $appSettings['school_name'] }} — {{ __('Driving School Management System') }}
        </footer>
    </div>
</div>
</body>
</html>
