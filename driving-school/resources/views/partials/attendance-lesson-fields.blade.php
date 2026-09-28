@props(['topics', 'vehicles', 'lesson' => null, 'idSuffix' => '', 'compact' => false])

{{--
    The lesson worked on that day, saved as part of that day's attendance
    record. Shown only while the student is marked present, since that is when
    a lesson actually happened.
--}}
<div x-show="status === 'present'" x-cloak class="{{ $compact ? 'space-y-2' : 'grid gap-4 sm:grid-cols-2' }}">
    <div>
        <label for="lesson_topic_id{{ $idSuffix }}" class="label">
            {{ __('Lesson') }} <span class="text-rose-500">*</span>
        </label>
        <select id="lesson_topic_id{{ $idSuffix }}" name="lesson_topic_id"
                class="input @error('lesson_topic_id') input-error @enderror">
            <option value="">{{ __('Select a lesson') }}</option>
            @foreach ($topics as $topic)
                <option value="{{ $topic->id }}" @selected(old('lesson_topic_id', $lesson?->lesson_topic_id) == $topic->id)>
                    {{ $topic->display_name }}
                </option>
            @endforeach
        </select>
        @error('lesson_topic_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="performance{{ $idSuffix }}" class="label">{{ __('Performance') }}</label>
        <select id="performance{{ $idSuffix }}" name="performance" class="input @error('performance') input-error @enderror">
            <option value="">{{ __('Not rated') }}</option>
            @foreach (\App\Models\Lesson::PERFORMANCES as $performance)
                <option value="{{ $performance }}" @selected(old('performance', $lesson?->performance) === $performance)>
                    {{ __(ucwords(str_replace('_', ' ', $performance))) }}
                </option>
            @endforeach
        </select>
        @error('performance')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    @unless ($compact)
        <div>
            <label for="topic{{ $idSuffix }}" class="label">{{ __('Topic') }}</label>
            <input id="topic{{ $idSuffix }}" name="topic" value="{{ old('topic', $lesson?->topic) }}"
                   class="input" placeholder="{{ __('e.g. Reverse parking on a slope') }}">
        </div>

        <div>
            <label for="duration_minutes{{ $idSuffix }}" class="label">{{ __('Duration (minutes)') }}</label>
            <input id="duration_minutes{{ $idSuffix }}" name="duration_minutes" type="number" min="5" max="600"
                   value="{{ old('duration_minutes', $lesson?->duration_minutes ?: 60) }}" class="input">
        </div>

        <div class="sm:col-span-2">
            <label for="vehicle_id{{ $idSuffix }}" class="label">{{ __('Vehicle') }}</label>
            <select id="vehicle_id{{ $idSuffix }}" name="vehicle_id" class="input">
                <option value="">{{ __('None') }}</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $lesson?->vehicle_id) == $vehicle->id)>
                        {{ $vehicle->label }} — {{ $vehicle->plate_number }}
                    </option>
                @endforeach
            </select>
        </div>
    @endunless
</div>
