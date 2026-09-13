@extends('layouts.app')

@section('title', __('Dashboard'))
@section('heading', __('Dashboard'))
@section('subheading', __('Company-wide overview'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
{{-- Financial headline cards --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card :label="__('Total Income')" :value="$c.number_format($metrics['total_income'], 2)" icon="money" tone="green" />
    <x-stat-card :label="__('Total Expenses')" :value="$c.number_format($metrics['total_expenses'], 2)" icon="money" tone="rose" />
    <x-stat-card :label="__('Net Profit')" :value="$c.number_format($metrics['net_profit'], 2)"
                 icon="trend" :tone="$metrics['net_profit'] >= 0 ? 'green' : 'rose'" />
    <x-stat-card :label="__('Outstanding Debt')" :value="$c.number_format($metrics['outstanding_debt'], 2)"
                 icon="debt" tone="amber" :href="route('admin.debts.index')" />
</div>

{{-- Operational cards --}}
<div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card :label="__('Active Students')" :value="$metrics['active_students']" icon="users" tone="brand"
                 :href="route('admin.students.index', ['status' => 'active'])" />
    <x-stat-card :label="__('Check-ins Today')" :value="$metrics['checkins_today']" icon="check" tone="green"
                 :href="route('admin.attendance.index')" />
    <x-stat-card :label="__('Registrations This Month')" :value="$metrics['registrations_this_month']" icon="plus" tone="violet" />
    <x-stat-card :label="__('Near Completion')" :value="$metrics['near_completion']" icon="trend" tone="amber" />
</div>

{{-- Charts --}}
<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card p-5">
        <h3 class="card-title">{{ __('Daily Attendance') }}</h3>
        <p class="mb-4 text-xs text-slate-400">{{ __('Present check-ins, last 14 days') }}</p>
        <div class="h-64">
            <canvas data-chart="attendance" data-label="{{ __('Present') }}"
                    data-series='@json($attendanceTrend)'></canvas>
        </div>
    </div>

    <div class="card p-5">
        <h3 class="card-title">{{ __('Income vs Expenses') }}</h3>
        <p class="mb-4 text-xs text-slate-400">{{ __('Last 6 months') }}</p>
        <div class="h-64">
            <canvas data-chart="income"
                    data-label-income="{{ __('Income') }}" data-label-expenses="{{ __('Expenses') }}"
                    data-series='@json($incomeTrend)'></canvas>
        </div>
    </div>
</div>

{{-- Student overview --}}
<div class="mt-6 grid gap-4 xl:grid-cols-3">
    <div class="card xl:col-span-2">
        <div class="card-header">
            <h3 class="card-title">{{ __('Student Overview') }}</h3>
            <a href="{{ route('admin.students.index') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Student') }}</th>
                        <th>{{ __('Instructor') }}</th>
                        <th>{{ __('Progress') }}</th>
                        <th>{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($students as $student)
                        <tr>
                            <td>
                                <a href="{{ route('admin.students.show', $student) }}" class="font-semibold text-brand-700 hover:underline">
                                    {{ $student->full_name }}
                                </a>
                                <p class="text-xs text-slate-400">{{ $student->student_number }}</p>
                            </td>
                            <td class="text-sm">{{ $student->currentInstructor?->full_name ?? '—' }}</td>
                            <td class="min-w-40"><x-progress-bar :value="$student->progress_percentage" /></td>
                            <td><x-status-badge :status="$student->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Near Completion') }}</h3></div>
        <ul class="divide-y divide-slate-100">
            @forelse ($nearCompletion as $student)
                <li class="px-5 py-3">
                    <div class="flex items-center justify-between gap-2">
                        <a href="{{ route('admin.students.show', $student) }}" class="truncate text-sm font-semibold text-slate-800 hover:text-brand-700">
                            {{ $student->full_name }}
                        </a>
                        <span class="badge-amber">{{ $student->remaining_days }} {{ __('left') }}</span>
                    </div>
                    <x-progress-bar :value="$student->progress_percentage" class="mt-2" />
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-slate-400">{{ __('No records found.') }}</li>
            @endforelse
        </ul>
    </div>
</div>

{{-- Finance tables --}}
<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('Recent Expenses') }}</h3>
            <a href="{{ route('admin.expenses.index') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Date') }}</th><th>{{ __('Category') }}</th><th>{{ __('Description') }}</th><th class="text-right">{{ __('Amount') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($recentExpenses as $expense)
                        <tr>
                            <td class="whitespace-nowrap text-xs">{{ $expense->expense_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $expense->category?->display_name }}</td>
                            <td class="max-w-48 truncate text-sm">{{ $expense->description }}</td>
                            <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $expense->amount, 2) }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('Outstanding Debts') }}</h3>
            <a href="{{ route('admin.debts.index') }}" class="btn-secondary btn-sm">{{ __('View all') }}</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Supplier') }}</th><th>{{ __('Due Date') }}</th><th>{{ __('Status') }}</th><th class="text-right">{{ __('Remaining') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($outstandingDebts as $debt)
                        <tr>
                            <td>
                                <a href="{{ route('admin.debts.show', $debt) }}" class="text-sm font-semibold text-brand-700 hover:underline">
                                    {{ $debt->supplier?->name }}
                                </a>
                                <p class="text-xs text-slate-400">{{ $debt->debt_number }}</p>
                            </td>
                            <td class="whitespace-nowrap text-xs">{{ $debt->due_date?->format('d/m/Y') ?? '—' }}</td>
                            <td><x-status-badge :status="$debt->status" /></td>
                            <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $debt->remaining_amount, 2) }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="4" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
