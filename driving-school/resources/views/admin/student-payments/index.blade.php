@extends('layouts.app')
@section('title', __('Student Payments'))
@section('heading', __('Student Payments'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Student Payments')" :subtitle="__('Total: :amount', ['amount' => $c.number_format($total, 2)])">
    <x-slot:actions>
        <a href="{{ route('admin.student-payments.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('Record Payment') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <select name="student_id" class="input">
            <option value="">{{ __('All students') }}</option>
            @foreach ($students as $student)
                <option value="{{ $student->id }}" @selected(request('student_id') == $student->id)>{{ $student->full_name }}</option>
            @endforeach
        </select>
        <select name="payment_method" class="input">
            <option value="">{{ __('All methods') }}</option>
            @foreach (\App\Models\StudentPayment::METHODS as $method)
                <option value="{{ $method }}" @selected(request('payment_method') === $method)>{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.student-payments.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Method') }}</th>
                <th>{{ __('Reference') }}</th><th class="text-right">{{ __('Amount') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td class="font-mono text-xs">{{ $payment->payment_number }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                        <td class="text-sm font-medium">{{ $payment->student?->full_name }}</td>
                        <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                        <td class="text-sm text-slate-500">{{ $payment->reference ?: '—' }}</td>
                        <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.student-payments.edit', $payment) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.student-payments.destroy', $payment)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="7" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($payments->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $payments->links() }}</div>@endif
</div>
@endsection
