@extends('layouts.app')
@section('title', __('Audit Log'))
@section('heading', __('Audit Log Entry'))

@section('content')
<div class="card max-w-3xl">
    <div class="card-header"><h3 class="card-title">{{ $log->action }}</h3></div>
    <div class="space-y-3 p-5 text-sm">
        @foreach ([
            __('When') => $log->created_at?->format('d/m/Y H:i:s'),
            __('User') => $log->user_name ?? __('System'),
            __('Record') => $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—',
            __('Description') => $log->description,
            __('IP Address') => $log->ip_address ?: '—',
            __('User Agent') => $log->user_agent ?: '—',
        ] as $label => $value)
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">{{ $label }}</dt>
                <dd class="max-w-md text-right font-medium break-words text-slate-800">{{ $value }}</dd>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 border-t border-slate-200 p-5 sm:grid-cols-2">
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Before') }}</p>
            <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—' }}</pre>
        </div>
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('After') }}</p>
            <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—' }}</pre>
        </div>
    </div>

    <div class="border-t border-slate-200 p-5">
        <a href="{{ route('admin.audit-logs.index') }}" class="btn-secondary btn-sm">{{ __('Back') }}</a>
    </div>
</div>
@endsection
