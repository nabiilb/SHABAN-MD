@extends('layouts.app')
@section('title', $user->name)
@section('heading', $user->name)
@section('subheading', $user->email)

@section('content')
<div class="grid gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="card-title mb-4">{{ __('Account') }}</h3>
        <dl class="space-y-3 text-sm">
            @foreach ([
                __('Role') => __($user->role?->label ?? '—'),
                __('Phone') => $user->phone ?: '—',
                __('Language') => config('app.supported_locales')[$user->locale] ?? $user->locale,
                __('Last Login') => $user->last_login_at?->format('d/m/Y H:i') ?? '—',
                __('Instructor Profile') => $user->instructor?->full_name ?? '—',
                __('Student Profile') => $user->student?->full_name ?? '—',
            ] as $label => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
        <a href="{{ route('admin.users.edit', $user) }}" class="btn-primary btn-sm mt-5">{{ __('Edit') }}</a>
    </div>

    <div class="card lg:col-span-2">
        <div class="card-header"><h3 class="card-title">{{ __('Recent Activity') }}</h3></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('When') }}</th><th>{{ __('Action') }}</th><th>{{ __('Description') }}</th></tr></thead>
                <tbody>
                    @forelse ($recentActivity as $log)
                        <tr>
                            <td class="whitespace-nowrap text-xs text-slate-500">{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                            <td><span class="badge-slate font-mono">{{ $log->action }}</span></td>
                            <td class="text-sm">{{ $log->description }}</td>
                        </tr>
                    @empty
                        <x-empty-state colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
