@extends('layouts.app')
@section('title', __('Reports'))
@section('heading', __('Reports'))

@php
    $icons = [
        'students' => 'users', 'attendance' => 'check', 'instructors' => 'user', 'vehicles' => 'car',
        'expenses' => 'money', 'debts' => 'debt', 'debt-payments' => 'money', 'supplier-statement' => 'store',
        'student-progress' => 'trend', 'transfers' => 'swap',
    ];
@endphp

@section('content')
<x-page-header :title="__('Reports')" :subtitle="__('Filter, print or export any report to CSV or PDF')" />

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach (\App\Services\ReportService::ADMIN_REPORTS as $report)
        <a href="{{ route('admin.reports.show', $report) }}"
           class="card flex items-center gap-4 p-5 transition hover:border-brand-300 hover:shadow-md">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                <x-icon :name="$icons[$report] ?? 'report'" class="h-6 w-6" />
            </span>
            <div>
                <p class="font-semibold text-slate-800">{{ $titles[$report] }}</p>
                <p class="text-xs text-slate-400">{{ __('Open report') }}</p>
            </div>
        </a>
    @endforeach
</div>
@endsection
