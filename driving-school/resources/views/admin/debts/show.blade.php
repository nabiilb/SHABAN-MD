@extends('layouts.app')
@section('title', $debt->debt_number)
@section('heading', $debt->debt_number)
@section('subheading', $debt->supplier?->name.' · '.$debt->description)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Original Debt')" :value="$c.number_format((float) $debt->original_amount, 2)" icon="debt" tone="slate" />
    <x-stat-card :label="__('Total Paid')" :value="$c.number_format($debt->paid_amount, 2)" icon="check" tone="green" />
    <x-stat-card :label="__('Remaining')" :value="$c.number_format((float) $debt->remaining_amount, 2)" icon="alert" tone="amber" />
    <x-stat-card :label="__('Status')" :value="__(ucwords(str_replace('_', ' ', $debt->status)))" icon="shield"
                 :tone="$debt->status === 'paid' ? 'green' : ($debt->status === 'overdue' ? 'rose' : 'amber')" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    @if (! in_array($debt->status, ['paid', 'cancelled'], true))
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Record a Payment') }}</h3></div>
            <form method="POST" action="{{ route('admin.debts.payments.store', $debt) }}" class="space-y-4 p-5">
                @csrf
                <x-field name="amount" :label="__('Amount')" required>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                           max="{{ $debt->remaining_amount }}" value="{{ old('amount') }}"
                           class="input @error('amount') input-error @enderror">
                    <p class="mt-1 text-xs text-slate-400">{{ __('Maximum: :amount', ['amount' => $c.number_format((float) $debt->remaining_amount, 2)]) }}</p>
                </x-field>

                <x-field name="payment_date" :label="__('Payment Date')" required>
                    <input id="payment_date" name="payment_date" type="date" required
                           value="{{ old('payment_date', now()->toDateString()) }}" class="input @error('payment_date') input-error @enderror">
                </x-field>

                <x-field name="payment_method" :label="__('Payment Method')" required>
                    <select id="payment_method" name="payment_method" class="input">
                        @foreach (\App\Models\DebtPayment::METHODS as $method)
                            <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ __(ucwords(str_replace('_', ' ', $method))) }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field name="reference" :label="__('Reference')">
                    <input id="reference" name="reference" value="{{ old('reference') }}" class="input">
                </x-field>

                <x-field name="notes" :label="__('Notes')">
                    <textarea id="notes" name="notes" rows="2" class="input">{{ old('notes') }}</textarea>
                </x-field>

                <button class="btn-primary w-full">{{ __('Record Payment') }}</button>
                <p class="text-xs text-slate-400">
                    {{ __('This creates a company expense of the same amount and reduces the remaining debt — in one transaction.') }}
                </p>
            </form>
        </div>
    @endif

    <div class="card {{ in_array($debt->status, ['paid', 'cancelled'], true) ? 'lg:col-span-3' : 'lg:col-span-2' }}">
        <div class="card-header">
            <h3 class="card-title">{{ __('Payments') }}</h3>
            <div class="flex gap-2">
                <a href="{{ route('admin.debts.edit', $debt) }}" class="btn-secondary btn-sm">{{ __('Edit') }}</a>
                @if (! in_array($debt->status, ['paid', 'cancelled'], true))
                    <form method="POST" action="{{ route('admin.debts.cancel', $debt) }}" onsubmit="return confirm('{{ __('Cancel this debt?') }}')">
                        @csrf
                        <button class="btn-ghost btn-sm text-rose-600">{{ __('Cancel Debt') }}</button>
                    </form>
                @endif
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Reference') }}</th>
                        <th>{{ __('Expense Created') }}</th><th class="text-right">{{ __('Amount') }}</th>
                        <th class="text-right">{{ __('Actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                            <td class="text-sm text-slate-500">{{ $payment->reference ?: '—' }}</td>
                            <td>
                                @if ($payment->expense)
                                    <a href="{{ route('admin.expenses.show', $payment->expense) }}" class="badge-green font-mono">{{ $payment->expense->expense_number }}</a>
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                            <td class="text-right">
                                <x-delete-form :action="route('admin.debts.payments.destroy', [$debt, $payment])"
                                               :label="__('Reverse')"
                                               :confirm="__('Reverse this payment and delete its expense?')" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-state colspan="6" :message="__('No payments yet — no expense has been booked for this debt.')" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-sm">
            <span class="text-slate-500">{{ __('Total cash expensed for this debt') }}:</span>
            <strong class="text-slate-800">{{ $c }}{{ number_format((float) $expenses->sum('amount'), 2) }}</strong>
        </div>
    </div>
</div>
@endsection
