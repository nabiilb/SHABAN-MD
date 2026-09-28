@props(['value' => 0, 'showLabel' => true])

@php
    $value = max(0, min(100, (float) $value));
    $tone = match (true) {
        $value >= 100 => 'bg-emerald-500',
        $value >= 75 => 'bg-brand-500',
        $value >= 40 => 'bg-amber-500',
        default => 'bg-slate-400',
    };
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    <div class="h-2 w-full min-w-16 overflow-hidden rounded-full bg-slate-200">
        <div class="h-full rounded-full {{ $tone }}" style="width: {{ $value }}%"></div>
    </div>
    @if ($showLabel)
        <span class="w-11 shrink-0 text-right text-xs font-semibold text-slate-600">{{ rtrim(rtrim(number_format($value, 1), '0'), '.') }}%</span>
    @endif
</div>
