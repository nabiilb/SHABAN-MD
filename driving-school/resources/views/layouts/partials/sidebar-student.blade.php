@php
    $link = fn (string $pattern) => request()->routeIs($pattern) ? 'nav-link nav-link-active' : 'nav-link';
@endphp

<div class="space-y-0.5 pt-4">
    <a href="{{ route('student.dashboard') }}" class="{{ $link('student.dashboard') }}">
        <x-icon name="dashboard" /> <span>{{ __('Dashboard') }}</span>
    </a>
    <a href="{{ route('student.profile') }}" class="{{ $link('student.profile') }}">
        <x-icon name="user" /> <span>{{ __('My Profile') }}</span>
    </a>
    <a href="{{ route('student.attendance') }}" class="{{ $link('student.attendance') }}">
        <x-icon name="check" /> <span>{{ __('My Attendance') }}</span>
    </a>
    <a href="{{ route('student.lessons') }}" class="{{ $link('student.lessons') }}">
        <x-icon name="book" /> <span>{{ __('My Lessons') }}</span>
    </a>
    <a href="{{ route('student.progress') }}" class="{{ $link('student.progress') }}">
        <x-icon name="trend" /> <span>{{ __('My Progress') }}</span>
    </a>
</div>
