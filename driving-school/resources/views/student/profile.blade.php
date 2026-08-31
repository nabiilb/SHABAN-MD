@extends('layouts.app')
@section('title', __('My Profile'))
@section('heading', __('My Profile'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="grid gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Personal Details') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Student Number') => $student->student_number,
                __('Full Name') => $student->full_name,
                __('Phone') => $student->phone,
                __('Email') => $student->email ?: '—',
                __('Address') => $student->address ?: '—',
                __('Date of Birth') => $student->date_of_birth?->format('d/m/Y') ?? '—',
                __('Gender') => $student->gender ? __(ucfirst($student->gender)) : '—',
                __('License Type') => $student->license_type ?: '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Training') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Current Instructor') => $student->currentInstructor?->full_name ?? '—',
                __('Start Date') => $student->start_date?->format('d/m/Y'),
                __('Required Days') => $student->required_training_days,
                __('Completed Days') => $student->completed_days,
                __('Remaining Days') => $student->remaining_days,
                __('Completion Date') => $student->completion_date?->format('d/m/Y') ?? '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd><x-status-badge :status="$student->status" /></dd>
            </div>
        </dl>
        <div class="mt-4 border-t border-slate-200 pt-4"><x-progress-bar :value="$student->progress_percentage" /></div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Instructor History') }}</h3></div>
        <ul class="divide-y divide-slate-100">
            @forelse ($assignments as $assignment)
                <li class="px-5 py-3 text-sm">
                    <p class="font-semibold text-slate-800">{{ $assignment->instructor?->full_name }}</p>
                    <p class="text-xs text-slate-500">
                        {{ $assignment->assigned_from?->format('d/m/Y') }} →
                        {{ $assignment->is_current ? __('Current') : ($assignment->assigned_to?->format('d/m/Y') ?? '—') }}
                    </p>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-slate-400">{{ __('No records found.') }}</li>
            @endforelse
        </ul>
    </div>
</div>

<div class="card mt-6">
    <div class="card-header"><h3 class="card-title">{{ __('My Payments') }}</h3></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th class="text-right">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td class="font-mono text-xs">{{ $payment->payment_number }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                        <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $payment->payment_method))) }}</td>
                        <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="4" />
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-sm">
        <span class="text-slate-500">{{ __('Total paid') }}:</span>
        <strong class="text-slate-800">{{ $c }}{{ number_format($student->total_paid, 2) }}</strong>
        · <span class="text-slate-500">{{ __('Balance') }}:</span>
        <strong class="text-slate-800">{{ $c }}{{ number_format($student->balance, 2) }}</strong>
    </div>
</div>
@endsection
