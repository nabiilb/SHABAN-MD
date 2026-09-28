@extends('layouts.app')
@section('title', __('Expense Categories'))
@section('heading', __('Expense Categories'))

@section('content')
<x-page-header :title="__('Expense Categories')">
    <x-slot:actions>
        <a href="{{ route('admin.expense-categories.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Category') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Somali') }}</th><th>{{ __('Code') }}</th><th class="text-center">{{ __('Expenses') }}</th><th>{{ __('Status') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr>
                        <td class="font-medium">{{ $category->name }}</td>
                        <td class="text-sm text-slate-500">{{ $category->name_so ?: '—' }}</td>
                        <td class="font-mono text-xs">{{ $category->code }}</td>
                        <td class="text-center">{{ $category->expenses_count }}</td>
                        <td>@if ($category->is_active)<span class="badge-green">{{ __('Active') }}</span>@else<span class="badge-slate">{{ __('Inactive') }}</span>@endif</td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.expense-categories.edit', $category) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.expense-categories.destroy', $category)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="6" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($categories->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $categories->links() }}</div>@endif
</div>
@endsection
