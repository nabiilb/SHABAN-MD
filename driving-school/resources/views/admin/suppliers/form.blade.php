@extends('layouts.app')
@section('title', $supplier->exists ? __('Edit Supplier') : __('New Supplier'))
@section('heading', $supplier->exists ? __('Edit Supplier') : __('New Supplier'))

@section('content')
<form method="POST" action="{{ $supplier->exists ? route('admin.suppliers.update', $supplier) : route('admin.suppliers.store') }}">
    @csrf
    @if ($supplier->exists) @method('PUT') @endif

    <div class="card max-w-2xl">
        <div class="card-header"><h3 class="card-title">{{ __('Supplier Details') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="name" :label="__('Name')" required class="sm:col-span-2">
                <input id="name" name="name" required value="{{ old('name', $supplier->name) }}" class="input @error('name') input-error @enderror">
            </x-field>
            <x-field name="supplier_type" :label="__('Supplier Type')" required>
                <select id="supplier_type" name="supplier_type" class="input">
                    @foreach (\App\Models\Supplier::TYPES as $type)
                        <option value="{{ $type }}" @selected(old('supplier_type', $supplier->supplier_type) === $type)>{{ __(ucwords(str_replace('_', ' ', $type))) }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="status" :label="__('Status')" required>
                <select id="status" name="status" class="input">
                    <option value="active" @selected(old('status', $supplier->status) === 'active')>{{ __('Active') }}</option>
                    <option value="inactive" @selected(old('status', $supplier->status) === 'inactive')>{{ __('Inactive') }}</option>
                </select>
            </x-field>
            <x-field name="phone" :label="__('Phone')">
                <input id="phone" name="phone" value="{{ old('phone', $supplier->phone) }}" class="input">
            </x-field>
            <x-field name="email" :label="__('Email')">
                <input id="email" name="email" type="email" value="{{ old('email', $supplier->email) }}" class="input @error('email') input-error @enderror">
            </x-field>
            <x-field name="address" :label="__('Address')" class="sm:col-span-2">
                <input id="address" name="address" value="{{ old('address', $supplier->address) }}" class="input">
            </x-field>
            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $supplier->notes) }}</textarea>
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.suppliers.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
