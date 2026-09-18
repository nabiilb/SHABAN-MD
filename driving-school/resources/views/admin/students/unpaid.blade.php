@extends('layouts.app')

@section('title', __('Unpaid Student Fees'))
@section('heading', __('Unpaid Student Fees'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Unpaid Student Fees')"
               :subtitle="__(':count student(s) owe :amount in total', [
                   'count' => $students->total(),
                   'amount' => $c.number_format($outstanding, 2),
               ])">
    <x-slot:actions>
        <a href="{{ route('admin.student-payments.create') }}" class="btn-primary">
            <x-icon name="plus" class="h-4 w-4" /> {{ __('Record Payment') }}
        </a>
        <a href="{{ route('admin.students.index') }}" class="btn-secondary">{{ __('All Students') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="filter-bar sm:grid-cols-3">
        <div class="sm:col-span-2">
            <label for="f-search" class="label">{{ __('Search') }}</label>
            <input id="f-search" name="search" value="{{ request('search') }}" class="input"
                   placeholder="{{ __('Search name, number or phone') }}">
        </div>
        <div class="filter-actions sm:items-end">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.students.unpaid') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

{{-- Columns on a desktop, a card per student on a phone. --}}
<div class="card max-md:border-0 max-md:bg-transparent max-md:shadow-none">
    <div class="table-wrap max-md:overflow-visible">
        <table class="table table-cards">
            <thead>
                <tr>
                    <th>{{ __('Student') }}</th>
                    <th>{{ __('Phone') }}</th>
                    <th class="text-right">{{ __('Total Fee') }}</th>
                    <th class="text-right">{{ __('Paid') }}</th>
                    <th class="text-right">{{ __('Remaining') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    <tr>
                        <td class="cell-title">
                            <a href="{{ route('admin.students.show', $student) }}"
                               class="font-medium text-brand-700 hover:underline max-md:text-base">{{ $student->full_name }}</a>
                            <span class="block text-xs text-slate-400">
                                {{ $student->student_number }}
                                @if ($student->currentInstructor)
                                    · {{ $student->currentInstructor->full_name }}
                                @endif
                            </span>
                        </td>
                        <td data-label="{{ __('Phone') }}" class="whitespace-nowrap text-sm">{{ $student->phone }}</td>
                        <td data-label="{{ __('Total Fee') }}" class="text-right text-sm">{{ $c }}{{ number_format((float) $student->total_fee, 2) }}</td>
                        <td data-label="{{ __('Paid') }}" class="text-right text-sm">{{ $c }}{{ number_format((float) $student->paid, 2) }}</td>
                        <td data-label="{{ __('Remaining') }}" class="text-right font-semibold text-amber-600">
                            {{ $c }}{{ number_format((float) $student->remaining_amount, 2) }}
                        </td>
                        <td data-label="{{ __('Status') }}"><x-status-badge :status="$student->status" /></td>
                        <td class="cell-actions whitespace-nowrap text-right text-sm">
                            <a href="{{ route('admin.student-payments.create', ['student_id' => $student->id]) }}"
                               class="btn-secondary btn-sm">{{ __('Record Payment') }}</a>
                            <a href="{{ route('admin.student-payments.index', ['student_id' => $student->id]) }}"
                               class="btn-secondary btn-sm">{{ __('Payments') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">
                            {{ __('Every student has paid their fees in full.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($students->isNotEmpty())
                {{-- The running total, which on a phone becomes its own card
                     rather than a row of empty cells. --}}
                <tfoot class="max-md:block">
                    <tr class="bg-slate-50 font-semibold max-md:mt-3 max-md:flex max-md:items-center max-md:justify-between max-md:rounded-xl max-md:border max-md:border-slate-200 max-md:bg-white max-md:p-4">
                        <td colspan="4" class="text-right text-sm text-slate-500 max-md:px-0 max-md:text-left">
                            {{ request('search') ? __('Matching total') : __('Total owed') }}
                        </td>
                        <td class="text-right text-amber-700 max-md:px-0">{{ $c }}{{ number_format($filtered, 2) }}</td>
                        <td colspan="2" class="max-md:hidden"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($students->hasPages())
        <div class="border-t border-slate-100 px-4 py-3 max-md:rounded-xl max-md:border max-md:border-slate-200 max-md:bg-white sm:px-5">{{ $students->links() }}</div>
    @endif
</div>
@endsection
