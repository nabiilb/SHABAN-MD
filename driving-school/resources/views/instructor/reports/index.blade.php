@extends('layouts.app')
@section('title', __('My Reports'))
@section('heading', __('My Reports'))

@php $icons = ['my-students' => 'users', 'my-attendance' => 'check', 'my-lessons' => 'book', 'my-progress' => 'trend']; @endphp

@section('content')
<x-page-header :title="__('My Reports')" :subtitle="__('Reports covering your own work only')" />

<div class="grid gap-4 sm:grid-cols-2">
    @foreach ($titles as $report => $title)
        <a href="{{ route('instructor.reports.show', $report) }}" class="card flex items-center gap-4 p-5 transition hover:border-brand-300 hover:shadow-md">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                <x-icon :name="$icons[$report] ?? 'report'" class="h-6 w-6" />
            </span>
            <div>
                <p class="font-semibold text-slate-800">{{ $title }}</p>
                <p class="text-xs text-slate-400">{{ __('Open report') }}</p>
            </div>
        </a>
    @endforeach
</div>
@endsection
