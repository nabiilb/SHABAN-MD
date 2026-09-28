@extends('layouts.app')
@section('title', __('Audit Logs'))
@section('heading', __('Audit Logs'))

@section('content')
<x-page-header :title="__('Audit Logs')" :subtitle="__('Every meaningful change is recorded here')" />

<div class="card mb-4 p-4">
    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <input name="search" value="{{ request('search') }}" class="input lg:col-span-2" placeholder="{{ __('Search description or user') }}">
        <select name="action" class="input">
            <option value="">{{ __('All actions') }}</option>
            @foreach ($actions as $action)
                <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
            @endforeach
        </select>
        <select name="user_id" class="input">
            <option value="">{{ __('All users') }}</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected(request('user_id') == $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="input">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="input">
        <div class="flex gap-2 lg:col-span-6">
            <button class="btn-primary">{{ __('Filter') }}</button>
            <a href="{{ route('admin.audit-logs.index') }}" class="btn-secondary">{{ __('Reset') }}</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('When') }}</th><th>{{ __('User') }}</th><th>{{ __('Action') }}</th>
                <th>{{ __('Description') }}</th><th>{{ __('IP') }}</th><th class="text-right">{{ __('Details') }}</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="whitespace-nowrap text-xs text-slate-500">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                        <td class="text-sm font-medium">{{ $log->user_name ?? $log->user?->name ?? __('System') }}</td>
                        <td><span class="badge-slate font-mono">{{ $log->action }}</span></td>
                        <td class="max-w-72 truncate text-sm">{{ $log->description }}</td>
                        <td class="font-mono text-xs text-slate-400">{{ $log->ip_address }}</td>
                        <td class="text-right"><a href="{{ route('admin.audit-logs.show', $log) }}" class="btn-ghost btn-sm">{{ __('View') }}</a></td>
                    </tr>
                @empty
                    <x-empty-state colspan="6" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($logs->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $logs->links() }}</div>@endif
</div>
@endsection
