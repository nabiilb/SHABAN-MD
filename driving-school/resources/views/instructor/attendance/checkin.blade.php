@extends('layouts.app')
@section('title', __('Check In'))
@section('heading', __('Check In a Student'))
@section('subheading', __('Search your students, choose a status and save'))

@section('content')
<div class="card mb-4 p-4">
    <form method="GET" class="flex gap-2">
        <input name="search" value="{{ request('search') }}" class="input" autofocus
               placeholder="{{ __('Search my students by name or number') }}">
        <button class="btn-primary"><x-icon name="search" class="h-4 w-4" /> {{ __('Search') }}</button>
        @if (request('search'))
            <a href="{{ route('instructor.attendance.create') }}" class="btn-secondary">{{ __('Clear') }}</a>
        @endif
    </form>
</div>

<div class="grid gap-4 lg:grid-cols-2">
    @forelse ($students as $student)
        @php $alreadyIn = in_array($student->id, $checkedInIds, true); @endphp
        <div class="card p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <a href="{{ route('instructor.students.show', $student) }}" class="font-semibold text-slate-900 hover:text-brand-700">
                        {{ $student->full_name }}
                    </a>
                    <p class="text-xs text-slate-400">{{ $student->student_number }} · {{ $student->phone }}</p>
                </div>
                @if ($alreadyIn)
                    <span class="badge-green">{{ __('Recorded today') }}</span>
                @endif
            </div>

            <x-progress-bar :value="$student->progress_percentage" class="mt-3" />
            <p class="mt-1 text-xs text-slate-400">
                {{ __(':completed of :required days · :remaining remaining', [
                    'completed' => $student->completed_days,
                    'required' => $student->required_training_days,
                    'remaining' => $student->remaining_days,
                ]) }}
            </p>

            @unless ($alreadyIn)
                <form method="POST" action="{{ route('instructor.attendance.store') }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="hidden" name="student_id" value="{{ $student->id }}">
                    <input type="hidden" name="attendance_date" value="{{ now()->toDateString() }}">
                    <input type="hidden" name="check_in_time" value="{{ now()->format('H:i') }}">

                    <div class="grid grid-cols-4 gap-2">
                        @foreach (\App\Models\Attendance::STATUSES as $index => $status)
                            <label class="flex cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-2 py-2 text-xs font-semibold
                                          has-checked:border-brand-500 has-checked:bg-brand-50 has-checked:text-brand-700">
                                <input type="radio" name="status" value="{{ $status }}" class="sr-only" @checked($index === 0)>
                                {{ __(ucfirst($status)) }}
                            </label>
                        @endforeach
                    </div>

                    <input name="notes" class="input" placeholder="{{ __('Notes (optional)') }}">
                    <button class="btn-primary w-full"><x-icon name="check" class="h-4 w-4" /> {{ __('Save Check-in') }}</button>
                </form>
            @else
                <a href="{{ route('instructor.attendance.index', ['student_id' => $student->id]) }}" class="btn-secondary btn-sm mt-4">
                    {{ __('View attendance') }}
                </a>
            @endunless
        </div>
    @empty
        <div class="card p-10 text-center text-sm text-slate-400 lg:col-span-2">
            {{ request('search') ? __('No matching students among yours.') : __('No students are currently assigned to you.') }}
        </div>
    @endforelse
</div>
@endsection
