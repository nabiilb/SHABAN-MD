<div class="card max-w-2xl">
    <div class="card-header"><h3 class="card-title">{{ __('Transfer Details') }}</h3></div>
    <div class="grid gap-4 p-5 sm:grid-cols-2">
        <x-field name="student_id" :label="__('Student')" required class="sm:col-span-2">
            <select id="student_id" name="student_id" required class="input @error('student_id') input-error @enderror">
                <option value="">{{ __('Select a student') }}</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}" @selected(old('student_id', $selectedStudent) == $student->id)>
                        {{ $student->full_name }} — {{ __('currently with') }} {{ $student->currentInstructor?->full_name ?? __('nobody') }}
                    </option>
                @endforeach
            </select>
        </x-field>

        <x-field name="to_instructor_id" :label="__('Transfer To')" required>
            <select id="to_instructor_id" name="to_instructor_id" required class="input @error('to_instructor_id') input-error @enderror">
                <option value="">{{ __('Select an instructor') }}</option>
                @foreach ($instructors as $instructor)
                    <option value="{{ $instructor->id }}" @selected(old('to_instructor_id') == $instructor->id)>{{ $instructor->full_name }}</option>
                @endforeach
            </select>
        </x-field>

        <x-field name="transfer_date" :label="__('Transfer Date')" required>
            <input id="transfer_date" name="transfer_date" type="date" required
                   value="{{ old('transfer_date', now()->toDateString()) }}" class="input @error('transfer_date') input-error @enderror">
        </x-field>

        <x-field name="reason" :label="__('Reason')" required class="sm:col-span-2">
            <input id="reason" name="reason" required value="{{ old('reason') }}" class="input @error('reason') input-error @enderror"
                   placeholder="{{ __('e.g. Instructor unavailable') }}">
        </x-field>

        <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
            <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes') }}</textarea>
        </x-field>
    </div>
    <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-500">
        {{ __('The previous assignment is closed and kept in the student\'s history — nothing is deleted.') }}
    </div>
</div>
