@extends('layouts.app')
@section('title', __('Transfers'))
@section('heading', __('My Transfers'))
@section('subheading', __('Transfers you sent or received'))

@section('content')
<x-page-header :title="__('Transfers')">
    <x-slot:actions>
        <a href="{{ route('instructor.transfers.create') }}" class="btn-primary"><x-icon name="swap" class="h-4 w-4" /> {{ __('Transfer a Student') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Student') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Reason') }}</th></tr></thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $record->transfer_date?->format('d/m/Y') }}</td>
                        <td class="font-medium">{{ $record->student?->full_name }}</td>
                        <td class="text-sm text-slate-500">{{ $record->fromInstructor?->full_name ?? '—' }}</td>
                        <td class="text-sm font-medium text-brand-700">{{ $record->toInstructor?->full_name }}</td>
                        <td class="max-w-56 truncate text-sm">{{ $record->reason }}</td>
                    </tr>
                @empty
                    <x-empty-state colspan="5" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($records->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>@endif
</div>
@endsection
