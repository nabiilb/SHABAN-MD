@extends('layouts.app')
@section('title', __('Role Permissions'))
@section('heading', __('Role Permissions'))

@section('content')
<x-page-header :title="__('Role Permissions')"
               :subtitle="__('Administrators always hold every permission. Fine-grained record access is enforced by policies.')" />

<div class="grid gap-4 lg:grid-cols-{{ min($roles->count(), 3) }}">
    @foreach ($roles as $role)
        <form method="POST" action="{{ route('admin.permissions.update', $role) }}" class="card">
            @csrf @method('PUT')
            <div class="card-header">
                <h3 class="card-title">{{ __($role->label) }}</h3>
                <span class="badge-blue">{{ $role->permissions->count() }}</span>
            </div>
            <div class="max-h-96 space-y-4 overflow-y-auto p-5">
                @foreach ($permissions as $group => $groupPermissions)
                    <div>
                        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ __($group) }}</p>
                        <div class="space-y-1.5">
                            @foreach ($groupPermissions as $permission)
                                <label class="flex items-start gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                           @checked($role->permissions->contains($permission->id))
                                           @disabled($role->name === 'admin')
                                           class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    {{ __($permission->label) }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            @unless ($role->name === 'admin')
                <div class="border-t border-slate-200 p-4">
                    <button class="btn-primary btn-sm w-full">{{ __('Save') }}</button>
                </div>
            @endunless
        </form>
    @endforeach
</div>
@endsection
