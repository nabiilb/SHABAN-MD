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

{{-- A clickable card is a link, so the whole card is the touch target rather
     than a word inside it. `min-h-20` keeps that target comfortably past a
     thumb's width even when the card holds one short number. --}}
<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    class="card flex min-h-20 items-start gap-3 p-4 sm:gap-4 sm:p-5 {{ $href ? 'transition hover:border-brand-300 hover:shadow-md' : '' }}">
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg sm:h-11 sm:w-11 {{ $tones[$tone] ?? $tones['brand'] }}">
        <x-icon :name="$icon" class="h-5 w-5 sm:h-6 sm:w-6" />
    </span>
    <div class="min-w-0 flex-1">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:text-xs">{{ $label }}</p>
        {{-- Wraps rather than truncating: a currency figure with its label cut
             off is worse than a figure on two lines. --}}
        <p class="mt-1 break-words text-xl font-bold leading-tight text-slate-900 sm:text-2xl">{{ $value }}</p>
        @if ($hint)
            <p class="mt-0.5 text-xs text-slate-400">{{ $hint }}</p>
        @endif
    </div>
</{{ $tag }}>
