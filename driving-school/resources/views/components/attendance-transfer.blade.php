@props([
    'student',
    'instructors',
    'action',
    'label' => null,
    'compact' => false,
])

{{--
    Transfers a student — and today's attendance for that student — to another
    instructor. The date is decided on the server, so this action can only ever
    move today; earlier days stay with the instructor who taught them.
--}}
<div x-data="{
        open: false,
        target: '',
        instructors: {{ Js::from($instructors->mapWithKeys(fn ($i) => [$i->id => $i->full_name])) }},
        get targetName() { return this.instructors[this.target] ?? ''; },
     }"
     class="inline-block">

    <button type="button" @click="open = true; target = ''"
            class="btn-ghost {{ $compact ? 'btn-sm' : '' }} text-brand-700 hover:bg-brand-50">
        <x-icon name="swap" class="h-4 w-4" />
        {{ $label ?? __('Transfer') }}
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-slate-900/50" @click="open = false"></div>

            <div x-show="open" x-transition
                 class="relative w-full max-w-md rounded-xl bg-white shadow-xl"
                 @keydown.escape.window="open = false">

                <form method="POST" action="{{ $action }}">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student->id }}">

                    <div class="border-b border-slate-200 px-5 py-4">
                        <h3 class="text-base font-bold text-slate-900">{{ __("Transfer for today's attendance") }}</h3>
                        <p class="mt-0.5 text-sm text-slate-500">
                            {{ $student->full_name }} · {{ now()->format('d/m/Y') }}
                        </p>
                    </div>

                    <div class="space-y-4 px-5 py-4">
                        <div>
                            <label for="to_instructor_id_{{ $student->id }}" class="label">
                                {{ __('Transfer To') }} <span class="text-rose-500">*</span>
                            </label>
                            <select id="to_instructor_id_{{ $student->id }}" name="to_instructor_id"
                                    x-model="target" required class="input">
                                <option value="">{{ __('Select an instructor') }}</option>
                                @foreach ($instructors as $instructor)
                                    <option value="{{ $instructor->id }}">{{ $instructor->full_name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="reason_{{ $student->id }}" class="label">{{ __('Reason') }}</label>
                            <input id="reason_{{ $student->id }}" name="reason" class="input"
                                   placeholder="{{ __('e.g. Instructor unavailable') }}">
                        </div>

                        <div x-show="target" x-cloak
                             class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            <p x-text="@js(__("Are you sure you want to transfer this student to :instructor for today's attendance?"))
                                        .replace(':instructor', targetName)"></p>
                            <p class="mt-2 text-xs text-amber-700">
                                {{ __("Only today's attendance moves. Earlier days stay with the current instructor.") }}
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                        <button type="button" @click="open = false" class="btn-secondary">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn-primary" :disabled="! target">{{ __('Confirm Transfer') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>
