{{-- Visual notifications for training events. --}}
<div class="fixed bottom-4 right-4 z-50 w-80 space-y-2 no-print">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition
             class="rounded-lg border px-4 py-3 text-sm shadow-lg"
             :class="{
                'bg-emerald-50 border-emerald-200 text-emerald-800': toast.tone === 'success',
                'bg-amber-50 border-amber-200 text-amber-800': toast.tone === 'warning',
                'bg-brand-50 border-brand-200 text-brand-800': toast.tone === 'info',
             }"
             x-text="toast.message"></div>
    </template>
</div>
