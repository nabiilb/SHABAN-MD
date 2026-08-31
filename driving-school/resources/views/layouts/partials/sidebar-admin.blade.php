@php
    $link = fn (string $pattern) => request()->routeIs($pattern) ? 'nav-link nav-link-active' : 'nav-link';
@endphp

<div class="space-y-0.5 pt-4">
    <a href="{{ route('admin.dashboard') }}" class="{{ $link('admin.dashboard') }}">
        <x-icon name="dashboard" /> <span>{{ __('Dashboard') }}</span>
    </a>
    <a href="{{ route('admin.students.index') }}" class="{{ $link('admin.students.*') }}">
        <x-icon name="users" /> <span>{{ __('Students') }}</span>
    </a>
    <a href="{{ route('admin.instructors.index') }}" class="{{ $link('admin.instructors.*') }}">
        <x-icon name="user" /> <span>{{ __('Instructors') }}</span>
    </a>
    <a href="{{ route('admin.vehicles.index') }}" class="{{ $link('admin.vehicles.*') }}">
        <x-icon name="car" /> <span>{{ __('Vehicles') }}</span>
    </a>
    <a href="{{ route('admin.attendance.index') }}" class="{{ $link('admin.attendance.*') }}">
        <x-icon name="check" /> <span>{{ __('Attendance') }}</span>
    </a>
    <a href="{{ route('admin.lessons.index') }}" class="{{ $link('admin.lessons.*') }}">
        <x-icon name="book" /> <span>{{ __('Lessons') }}</span>
    </a>
    <a href="{{ route('admin.transfers.index') }}" class="{{ $link('admin.transfers.*') }}">
        <x-icon name="swap" /> <span>{{ __('Transfers') }}</span>
    </a>
</div>

<p class="nav-heading">{{ __('Finance') }}</p>
<div class="space-y-0.5">
    <a href="{{ route('admin.fuel.index') }}" class="{{ $link('admin.fuel.*') }}">
        <x-icon name="fuel" /> <span>{{ __('Fuel') }}</span>
    </a>
    <a href="{{ route('admin.expenses.index') }}" class="{{ $link('admin.expenses.*') }}">
        <x-icon name="money" /> <span>{{ __('Income & Expenses') }}</span>
    </a>
    <a href="{{ route('admin.student-payments.index') }}" class="{{ $link('admin.student-payments.*') }}">
        <x-icon name="money" /> <span>{{ __('Student Payments') }}</span>
    </a>
    <a href="{{ route('admin.loans.index') }}" class="{{ $link('admin.loans.*') }}">
        <x-icon name="money" /> <span>{{ __('Loans & Credit') }}</span>
    </a>
    <a href="{{ route('admin.debts.index') }}" class="{{ $link('admin.debts.*') }}">
        <x-icon name="debt" /> <span>{{ __('Company Debts') }}</span>
    </a>
    <a href="{{ route('admin.suppliers.index') }}" class="{{ $link('admin.suppliers.*') }}">
        <x-icon name="store" /> <span>{{ __('Suppliers') }}</span>
    </a>
</div>

<p class="nav-heading">{{ __('Reports') }}</p>
<div class="space-y-0.5">
    <a href="{{ route('admin.reports.index') }}" class="{{ $link('admin.reports.*') }}">
        <x-icon name="report" /> <span>{{ __('Reports') }}</span>
    </a>
</div>

<p class="nav-heading">{{ __('System') }}</p>
<div class="space-y-0.5">
    <a href="{{ route('admin.users.index') }}" class="{{ $link('admin.users.*') }} {{ request()->routeIs('admin.permissions.*') ? 'nav-link-active' : '' }}">
        <x-icon name="shield" /> <span>{{ __('Users & Permissions') }}</span>
    </a>
    <a href="{{ route('admin.audit-logs.index') }}" class="{{ $link('admin.audit-logs.*') }}">
        <x-icon name="history" /> <span>{{ __('Audit Logs') }}</span>
    </a>
    <a href="{{ route('admin.settings.edit') }}" class="{{ $link('admin.settings.*') }}">
        <x-icon name="cog" /> <span>{{ __('Settings') }}</span>
    </a>
</div>
