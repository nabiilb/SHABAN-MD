@extends('layouts.app')
@section('title', __('Transfer Student'))
@section('heading', __('Transfer Student'))

@section('content')
<form method="POST" action="{{ route('admin.transfers.store') }}">
    @csrf
    @include('partials.transfer-fields', ['students' => $students, 'instructors' => $instructors, 'selectedStudent' => $selectedStudent])
    <div class="mt-6 flex gap-2">
        <button class="btn-primary">{{ __('Transfer Student') }}</button>
        <a href="{{ route('admin.transfers.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
