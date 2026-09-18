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
    <form method="GET" class="grid gap-3 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <input name="search" value="{{ request('search') }}" class="input"
                   placeholder="{{ __('Search name, number or phone') }}">
        </div>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.students.unpaid') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
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
                        <td>
                            <a href="{{ route('admin.students.show', $student) }}"
                               class="font-medium text-brand-700 hover:underline">{{ $student->full_name }}</a>
                            <span class="block text-xs text-slate-400">
                                {{ $student->student_number }}
                                @if ($student->currentInstructor)
                                    · {{ $student->currentInstructor->full_name }}
                                @endif
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $student->phone }}</td>
                        <td class="text-right text-sm">{{ $c }}{{ number_format((float) $student->total_fee, 2) }}</td>
                        <td class="text-right text-sm">{{ $c }}{{ number_format((float) $student->paid, 2) }}</td>
                        <td class="text-right font-semibold text-amber-600">
                            {{ $c }}{{ number_format((float) $student->remaining_amount, 2) }}
                        </td>
                        <td><x-status-badge :status="$student->status" /></td>
                        <td class="whitespace-nowrap text-right text-sm">
                            <a href="{{ route('admin.student-payments.create', ['student_id' => $student->id]) }}"
                               class="font-medium text-brand-700 hover:underline">{{ __('Record Payment') }}</a>
                            <span class="text-slate-300">·</span>
                            <a href="{{ route('admin.student-payments.index', ['student_id' => $student->id]) }}"
                               class="text-slate-500 hover:underline">{{ __('Payments') }}</a>
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
                <tfoot>
                    <tr class="bg-slate-50 font-semibold">
                        <td colspan="4" class="text-right text-sm text-slate-500">
                            {{ request('search') ? __('Matching total') : __('Total owed') }}
                        </td>
                        <td class="text-right text-amber-700">{{ $c }}{{ number_format($filtered, 2) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($students->hasPages())
        <div class="border-t border-slate-100 px-5 py-3">{{ $students->links() }}</div>
    @endif
</div>
@endsection
