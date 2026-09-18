@props(['title', 'subtitle' => null])

{{-- Title and actions sit side by side where there is room and stack on a
     phone, with the buttons sharing the width so neither is a sliver. --}}
<div class="mb-4 flex flex-wrap items-end justify-between gap-3 sm:mb-5">
    <div class="min-w-0 flex-1">
        <h2 class="break-words text-lg font-bold text-slate-900 sm:text-xl">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto max-sm:[&>*]:flex-1">{{ $actions }}</div>
    @endisset
</div>
