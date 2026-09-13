@extends('layouts.app')
@section('title', $lesson->exists ? __('Edit Lesson') : __('Add Lesson'))
@section('heading', $lesson->exists ? __('Edit Lesson') : __('Add Lesson'))

@section('content')
<form method="POST" action="{{ $lesson->exists ? route('admin.lessons.update', $lesson) : route('admin.lessons.store') }}">
    @csrf
    @if ($lesson->exists) @method('PUT') @endif
    @include('partials.lesson-fields', ['lesson' => $lesson, 'students' => $students, 'topics' => $topics, 'vehicles' => $vehicles])
    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Save') }}</button>
        <a href="{{ route('admin.lessons.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
