@props([
    'label',
    'value',
    'icon' => 'dot',
    'tone' => 'brand',
    'hint' => null,
    'href' => null,
])

@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600',
        'green' => 'bg-emerald-50 text-emerald-600',
        'amber' => 'bg-amber-50 text-amber-600',
        'rose' => 'bg-rose-50 text-rose-600',
        'slate' => 'bg-slate-100 text-slate-600',
        'violet' => 'bg-violet-50 text-violet-600',
    ];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    class="card flex items-start gap-4 p-5 {{ $href ? 'transition hover:border-brand-300 hover:shadow-md' : '' }}">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $tones[$tone] ?? $tones['brand'] }}">
        <x-icon :name="$icon" class="h-6 w-6" />
    </span>
    <div class="min-w-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
        <p class="mt-1 truncate text-2xl font-bold text-slate-900">{{ $value }}</p>
        @if ($hint)
            <p class="mt-0.5 text-xs text-slate-400">{{ $hint }}</p>
        @endif
    </div>
</{{ $tag }}>
