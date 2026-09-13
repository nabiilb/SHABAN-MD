@extends('layouts.app')
@section('title', $category->exists ? __('Edit Category') : __('New Category'))
@section('heading', $category->exists ? __('Edit Category') : __('New Category'))

@section('content')
<form method="POST" action="{{ $category->exists ? route('admin.expense-categories.update', $category) : route('admin.expense-categories.store') }}">
    @csrf
    @if ($category->exists) @method('PUT') @endif

    <div class="card max-w-xl">
        <div class="card-header"><h3 class="card-title">{{ __('Category') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="name" :label="__('Name (English)')" required>
                <input id="name" name="name" required value="{{ old('name', $category->name) }}" class="input @error('name') input-error @enderror">
            </x-field>
            <x-field name="name_so" :label="__('Name (Somali)')">
                <input id="name_so" name="name_so" value="{{ old('name_so', $category->name_so) }}" class="input">
            </x-field>
            <x-field name="code" :label="__('Code')" required>
                <input id="code" name="code" required value="{{ old('code', $category->code) }}" class="input @error('code') input-error @enderror" placeholder="garage_service">
            </x-field>
            <x-field name="sort_order" :label="__('Sort Order')">
                <input id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $category->sort_order ?: 0) }}" class="input">
            </x-field>
            <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true))
                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                {{ __('Active') }}
            </label>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.expense-categories.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
