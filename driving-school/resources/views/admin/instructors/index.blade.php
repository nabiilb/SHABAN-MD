@extends('layouts.app')
@section('title', __('Instructors'))
@section('heading', __('Instructors'))

@section('content')
<x-page-header :title="__('Instructors')" :subtitle="__(':count on record', ['count' => $instructors->total()])">
    <x-slot:actions>
        <a href="{{ route('admin.instructors.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Instructor') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-3">
        <input name="search" value="{{ request('search') }}" class="input" placeholder="{{ __('Search name, number or phone') }}">
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Instructor::STATUSES as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __(ucfirst($status)) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.instructors.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Instructor') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Qualification') }}</th>
                    <th>{{ __('Joining Date') }}</th><th class="text-center">{{ __('Students') }}</th>
                    <th class="text-center">{{ __('Vehicles') }}</th><th>{{ __('Account') }}</th>
                    <th>{{ __('Status') }}</th><th class="text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($instructors as $instructor)
                    <tr>
                        <td>
                            <a href="{{ route('admin.instructors.show', $instructor) }}" class="font-semibold text-brand-700 hover:underline">{{ $instructor->full_name }}</a>
                            <p class="text-xs text-slate-400">{{ $instructor->instructor_number }}</p>
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $instructor->phone }}</td>
                        <td class="text-sm">{{ $instructor->qualification ?: '—' }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $instructor->joining_date?->format('d/m/Y') }}</td>
                        <td class="text-center font-semibold">{{ $instructor->students_count }}</td>
                        <td class="text-center">{{ $instructor->vehicles_count }}</td>
                        <td>
                            @if ($instructor->user_id)
                                <span class="badge-green">{{ __('Linked') }}</span>
                            @else
                                <span class="badge-slate">{{ __('None') }}</span>
                            @endif
                        </td>
                        <td><x-status-badge :status="$instructor->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.instructors.edit', $instructor) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.instructors.destroy', $instructor)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="9" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($instructors->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $instructors->links() }}</div>@endif
</div>
@endsection
