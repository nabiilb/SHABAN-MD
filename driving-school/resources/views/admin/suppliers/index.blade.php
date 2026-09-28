@extends('layouts.app')
@section('title', __('Suppliers'))
@section('heading', __('Suppliers'))

@php $c = $appSettings['currency_symbol']; @endphp

@section('content')
<x-page-header :title="__('Suppliers')">
    <x-slot:actions>
        <a href="{{ route('admin.suppliers.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New Supplier') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-4">
        <input name="search" value="{{ request('search') }}" class="input" placeholder="{{ __('Search name, number or phone') }}">
        <select name="supplier_type" class="input">
            <option value="">{{ __('All types') }}</option>
            @foreach (\App\Models\Supplier::TYPES as $type)
                <option value="{{ $type }}" @selected(request('supplier_type') === $type)>{{ __(ucwords(str_replace('_', ' ', $type))) }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active" @selected(request('status') === 'active')>{{ __('Active') }}</option>
            <option value="inactive" @selected(request('status') === 'inactive')>{{ __('Inactive') }}</option>
        </select>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.suppliers.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>{{ __('Supplier') }}</th><th>{{ __('Type') }}</th><th>{{ __('Phone') }}</th>
                    <th class="text-right">{{ __('Total Expenses') }}</th><th class="text-right">{{ __('Outstanding') }}</th>
                    <th>{{ __('Status') }}</th><th class="text-right">{{ __('Actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $supplier)
                    <tr>
                        <td>
                            <a href="{{ route('admin.suppliers.show', $supplier) }}" class="font-semibold text-brand-700 hover:underline">{{ $supplier->name }}</a>
                            <p class="text-xs text-slate-400">{{ $supplier->supplier_number }}</p>
                        </td>
                        <td class="text-sm">{{ __(ucwords(str_replace('_', ' ', $supplier->supplier_type))) }}</td>
                        <td class="whitespace-nowrap text-sm">{{ $supplier->phone ?: '—' }}</td>
                        <td class="whitespace-nowrap text-right">{{ $c }}{{ number_format((float) $supplier->expenses_total, 2) }}</td>
                        <td class="whitespace-nowrap text-right font-semibold {{ $supplier->outstanding_total > 0 ? 'text-amber-700' : '' }}">
                            {{ $c }}{{ number_format((float) $supplier->outstanding_total, 2) }}
                        </td>
                        <td><x-status-badge :status="$supplier->status" /></td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.suppliers.edit', $supplier) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            <x-delete-form :action="route('admin.suppliers.destroy', $supplier)" />
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="7" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($suppliers->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $suppliers->links() }}</div>@endif
</div>
@endsection
