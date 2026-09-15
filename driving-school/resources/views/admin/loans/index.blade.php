@extends('layouts.app')
@section('title', __('Loans & Credit'))
@section('heading', __('Instructor Loans & Credit'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Loans & Credit')">
    <x-slot:actions>
        <a href="{{ route('admin.loans.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Loan') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="mb-4 grid gap-4 sm:grid-cols-3">
    <x-stat-card :label="__('Total Issued')" :value="$c.number_format($totals['issued'], 2)" icon="money" tone="slate" />
    <x-stat-card :label="__('Repaid')" :value="$c.number_format($totals['issued'] - $totals['remaining'], 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Outstanding')" :value="$c.number_format($totals['remaining'], 2)" icon="alert" tone="amber" />
</div>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <select name="instructor_id" class="input">
            <option value="">{{ __('All instructors') }}</option>
            @foreach ($instructors as $instructor)
                <option value="{{ $instructor->id }}" @selected(request('instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\InstructorLoan::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucwords(str_replace('_', ' ', $status))) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.loans.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Number') }}</th><th>{{ __('Instructor') }}</th><th>{{ __('Date') }}</th><th>{{ __('Reason') }}</th>
                    <th class="text-right">{{ __('Amount') }}</th><th class="text-right">{{ __('Paid') }}</th>
                    <th class="text-right">{{ __('Remaining') }}</th><th>{{ __('Status') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($loans as $loan)
                    <tr>
                        <td><a href="{{ route('admin.loans.show', $loan) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $loan->loan_number }}</a></td>
                        <td class="text-sm font-medium">{{ $loan->instructor?->full_name }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $loan->loan_date?->format('d/m/Y') }}</td>
                        <td class="max-w-40 truncate text-sm text-slate-500">{{ $loan->reason ?: '—' }}</td>
                        <td class="whitespace-nowrap text-right">{{ $c }}{{ number_format((float) $loan->amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right text-emerald-700">{{ $c }}{{ number_format($loan->paid_amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $loan->remaining_amount, 2) }}</td>
                        <td><x-status-badge :status="$loan->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.loans.show', $loan) }}" class="btn-ghost btn-sm">{{ __('Repay') }}</a>
                            <a href="{{ route('admin.loans.edit', $loan) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="9" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($loans->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $loans->links() }}</div>@endif
</div>
@endsection
