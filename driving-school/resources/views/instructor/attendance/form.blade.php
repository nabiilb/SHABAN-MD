@extends('layouts.app')
@section('title', __('Edit Attendance'))
@section('heading', __('Edit Attendance'))

@section('content')
<form method="POST" action="{{ route('instructor.attendance.update', $attendance) }}">
    @csrf @method('PUT')

    <div class="card max-w-2xl">
        <div class="card-header"><h3 class="card-title">{{ __('Attendance') }}</h3></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-field name="student_id" :label="__('Student')" required class="sm:col-span-2">
                <select id="student_id" name="student_id" required class="input @error('student_id') input-error @enderror">
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}" @selected(old('student_id', $attendance->student_id) == $student->id)>
                            {{ $student->full_name }} ({{ $student->student_number }})
                        </option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="attendance_date" :label="__('Date')" required>
                <input id="attendance_date" name="attendance_date" type="date" required max="{{ now()->toDateString() }}"
                       value="{{ old('attendance_date', $attendance->attendance_date?->format('Y-m-d')) }}"
                       class="input @error('attendance_date') input-error @enderror">
            </x-field>

            <x-field name="check_in_time" :label="__('Check-in Time')">
                <input id="check_in_time" name="check_in_time" type="time"
                       value="{{ old('check_in_time', $attendance->check_in_time ? substr($attendance->check_in_time, 0, 5) : '') }}" class="input">
            </x-field>

            <x-field name="status" :label="__('Status')" required class="sm:col-span-2">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach (\App\Models\Attendance::STATUSES as $status)
                        <label class="flex cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium
                                      has-checked:border-brand-500 has-checked:bg-brand-50 has-checked:text-brand-700">
                            <input type="radio" name="status" value="{{ $status }}" class="sr-only" @checked(old('status', $attendance->status) === $status)>
                            {{ __(ucfirst($status)) }}
                        </label>
                    @endforeach
                </div>
            </x-field>

            <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
                <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $attendance->notes) }}</textarea>
            </x-field>
        </div>
    </div>

    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('instructor.attendance.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
