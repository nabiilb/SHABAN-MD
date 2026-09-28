{{--
    Instructor navigation. Nothing from the admin area appears here — no
    company finance, no users, no audit logs, no settings.
--}}
@php
    $link = fn (string $pattern) => request()->routeIs($pattern) ? 'nav-link nav-link-active' : 'nav-link';
@endphp

<p class="nav-heading">{{ __('My Work') }}</p>
<div class="space-y-0.5">
    <a href="{{ route('instructor.dashboard') }}" class="{{ $link('instructor.dashboard') }}">
        <x-icon name="dashboard" /> <span>{{ __('My Dashboard') }}</span>
    </a>
    <a href="{{ route('instructor.students.index') }}" class="{{ $link('instructor.students.*') }}">
        <x-icon name="users" /> <span>{{ __('My Students') }}</span>
    </a>
    <a href="{{ route('instructor.training.index') }}" class="{{ $link('instructor.training.*') }}">
        <x-icon name="clock" /> <span>{{ __('Training Console') }}</span>
    </a>
    <a href="{{ route('instructor.attendance.index') }}" class="{{ $link('instructor.attendance.*') }}">
        <x-icon name="check" /> <span>{{ __('Attendance') }}</span>
    </a>
    <a href="{{ route('instructor.lessons.index') }}" class="{{ $link('instructor.lessons.*') }}">
        <x-icon name="book" /> <span>{{ __('Lessons') }}</span>
    </a>
    <a href="{{ route('instructor.vehicles.index') }}" class="{{ $link('instructor.vehicles.*') }}">
        <x-icon name="car" /> <span>{{ __('My Vehicles') }}</span>
    </a>
    <a href="{{ route('instructor.transfers.index') }}" class="{{ $link('instructor.transfers.*') }}">
        <x-icon name="swap" /> <span>{{ __('Transfers') }}</span>
    </a>
    <a href="{{ route('instructor.reports.index') }}" class="{{ $link('instructor.reports.*') }}">
        <x-icon name="report" /> <span>{{ __('My Reports') }}</span>
    </a>
</div>

<p class="nav-heading">{{ __('My Finance') }}</p>
<div class="space-y-0.5">
    {{-- Each entry is gated on the same policy its page is, so a link never
         appears for something the instructor would then be refused. --}}
    @can('viewAny', \App\Models\FuelRecord::class)
        <a href="{{ route('instructor.fuel.index') }}" class="{{ $link('instructor.fuel.*') }}">
            <x-icon name="fuel" /> <span>{{ __('Fuel') }}</span>
        </a>
    @endcan
    @can('viewAny', \App\Models\CompanyDebt::class)
        <a href="{{ route('instructor.company-debts.index') }}" class="{{ $link('instructor.company-debts.*') }}">
            <x-icon name="debt" /> <span>{{ __('Company Debts') }}</span>
        </a>
    @endcan
    @can('viewAny', \App\Models\InstructorLoan::class)
        <a href="{{ route('instructor.loans.index') }}" class="{{ $link('instructor.loans.*') }}">
            <x-icon name="money" /> <span>{{ __('My Loan & Credit') }}</span>
        </a>
    @endcan
</div>
