@extends('layouts.app')
@section('title', $instructor->full_name)
@section('heading', $instructor->full_name)
@section('subheading', $instructor->instructor_number)

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<div class="mb-5 flex flex-wrap gap-2">
    <a href="{{ route('admin.instructors.edit', $instructor) }}" class="btn-primary">{{ __('Edit') }}</a>
    <a href="{{ route('admin.loans.create') }}" class="btn-secondary">{{ __('Issue Loan') }}</a>
</div>

<div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-stat-card :label="__('Students')" :value="$students->count()" icon="users" tone="brand" />
    <x-stat-card :label="__('Vehicles')" :value="$instructor->vehicles->count()" icon="car" tone="slate" />
    <x-stat-card :label="__('Outstanding Loan')" :value="$c.number_format($instructor->outstanding_loan, 2)" icon="money" tone="amber" />
    <x-stat-card :label="__('Status')" :value="__(ucfirst($instructor->status))" icon="shield"
                 :tone="$instructor->status === 'active' ? 'green' : 'rose'" />
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Details') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Phone') => $instructor->phone,
                __('Email') => $instructor->email ?: '—',
                __('Address') => $instructor->address ?: '—',
                __('Qualification') => $instructor->qualification ?: '—',
                __('Joining Date') => $instructor->joining_date?->format('d/m/Y'),
                __('Login Account') => $instructor->user?->email ?? __('None'),
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('Assigned Students') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Student') }}</th><th class="w-44">{{ __('Progress') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                    @forelse ($students as $student)
                        <tr>
                            <td><a href="{{ route('admin.students.show', $student) }}" class="font-medium text-brand-700 hover:underline">{{ $student->full_name }}</a></td>
                            <td><x-progress-bar :value="$student->progress_percentage" /></td>
                            <td><x-status-badge :status="$student->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-6 grid gap-4 lg:grid-cols-2">
    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Loans & Credit') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th class="text-right">{{ __('Amount') }}</th><th class="text-right">{{ __('Remaining') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                    @forelse ($loans as $loan)
                        <tr>
                            <td><a href="{{ route('admin.loans.show', $loan) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $loan->loan_number }}</a></td>
                            <td class="whitespace-nowrap text-sm">{{ $loan->loan_date?->format('d/m/Y') }}</td>
                            <td class="text-right">{{ $c }}{{ number_format((float) $loan->amount, 2) }}</td>
                            <td class="text-right font-semibold">{{ $c }}{{ number_format((float) $loan->remaining_amount, 2) }}</td>
                            <td><x-status-badge :status="$loan->status" /></td>
                        </tr>
                    @empty
                        <x-empty-state colspan="5" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ __('Recent Lessons') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('Lesson Type') }}</th></tr></thead>
                <tbody>
                    @forelse ($recentLessons as $lesson)
                        <tr>
                            <td class="whitespace-nowrap text-sm">{{ $lesson->lesson_date?->format('d/m/Y') }}</td>
                            <td class="text-sm">{{ $lesson->student?->full_name }}</td>
                            <td class="text-sm">{{ $lesson->lessonTopic?->display_name }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
