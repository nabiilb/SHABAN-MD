@extends('layouts.app')
@section('title', $payment->payment_number)
@section('heading', $payment->payment_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="card max-w-xl p-5">
    <dl class="space-y-3 text-sm">
        @foreach ([
            __('Student') => $payment->student?->full_name,
            __('Amount') => $c.number_format((float) $payment->amount, 2),
            __('Date') => $payment->payment_date?->format('d/m/Y'),
            __('Method') => __(ucwords(str_replace('_', ' ', $payment->payment_method))),
            __('Reference') => $payment->reference ?: '—',
            __('Created By') => $payment->creator?->name ?? '—',
            __('Notes') => $payment->notes ?: '—',
        ] as $label => $value)
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ $label }}</dt>
                <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
    <div class="mt-5 flex gap-2">
        <a href="{{ route('admin.student-payments.edit', $payment) }}" class="btn-primary btn-sm">{{ __('Edit') }}</a>
        <a href="{{ route('admin.student-payments.index') }}" class="btn-secondary btn-sm">{{ __('Back') }}</a>
    </div>
</div>
@endsection
