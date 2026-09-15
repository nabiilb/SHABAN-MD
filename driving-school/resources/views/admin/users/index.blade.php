@extends('layouts.app')
@section('title', __('Users & Permissions'))
@section('heading', __('Users & Permissions'))

@section('content')
<x-page-header :title="__('Users')">
    <x-slot:actions>
        <a href="{{ route('admin.permissions.index') }}" class="btn-secondary">{{ __('Role Permissions') }}</a>
        <a href="{{ route('admin.users.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> {{ __('New User') }}</a>
    </x-slot:actions>
</x-page-header>

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-4">
        <input name="search" value="{{ request('search') }}" class="input" placeholder="{{ __('Search name or email') }}">
        <select name="role_id" class="input">
            <option value="">{{ __('All roles') }}</option>
            @foreach ($roles as $role)
                <option value="{{ $role->id }}" @selected(request('role_id') == $role->id)>{{ __($role->label) }}</option>
            @endforeach
        </select>
        <select name="status" class="input">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active" @selected(request('status') === 'active')>{{ __('Active') }}</option>
            <option value="inactive" @selected(request('status') === 'inactive')>{{ __('Inactive') }}</option>
        </select>
        <div class="flex gap-2">
            <button class="btn-primary flex-1">{{ __('Filter') }}</button>
            <a href="{{ route('admin.users.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Email') }}</th><th>{{ __('Role') }}</th>
                <th>{{ __('Last Login') }}</th><th>{{ __('Status') }}</th><th class="text-right">{{ __('Actions') }}</th></tr></thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td><a href="{{ route('admin.users.show', $user) }}" class="font-semibold text-brand-700 hover:underline">{{ $user->name }}</a></td>
                        <td class="text-sm">{{ $user->email }}</td>
                        <td><span class="badge-blue">{{ __($user->role?->label ?? '—') }}</span></td>
                        <td class="whitespace-nowrap text-sm text-slate-500">{{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>@if ($user->is_active)<span class="badge-green">{{ __('Active') }}</span>@else<span class="badge-rose">{{ __('Inactive') }}</span>@endif</td>
                        <td class="whitespace-nowrap text-right">
                            <a href="{{ route('admin.users.edit', $user) }}" class="btn-ghost btn-sm">{{ __('Edit') }}</a>
                            @unless ($user->id === auth()->id())
                                <x-delete-form :action="route('admin.users.destroy', $user)" :label="__('Deactivate')" />
                            @endunless
                        </td>
                    </tr>
                @empty
                    <x-empty-state colspan="6" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($users->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $users->links() }}</div>@endif
</div>
@endsection
