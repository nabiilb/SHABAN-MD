@props(['title', 'subtitle' => null])

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-slate-900">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
