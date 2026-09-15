<div class="card max-w-3xl">
    <div class="card-header"><h3 class="card-title">{{ __('Lesson Details') }}</h3></div>
    <div class="grid gap-4 p-5 sm:grid-cols-2">
        <x-field name="student_id" :label="__('Student')" required>
            <select id="student_id" name="student_id" required class="input @error('student_id') input-error @enderror">
                <option value="">{{ __('Select a student') }}</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}" @selected(old('student_id', $lesson->student_id) == $student->id)>
                        {{ $student->full_name }} ({{ $student->student_number }})
                    </option>
                @endforeach
            </select>
        </x-field>

        <x-field name="lesson_date" :label="__('Date')" required>
            <input id="lesson_date" name="lesson_date" type="date" required
                   value="{{ old('lesson_date', $lesson->lesson_date instanceof \Illuminate\Support\Carbon ? $lesson->lesson_date->format('Y-m-d') : $lesson->lesson_date) }}"
                   class="input @error('lesson_date') input-error @enderror">
        </x-field>

        <x-field name="lesson_topic_id" :label="__('Lesson Type')" required>
            <select id="lesson_topic_id" name="lesson_topic_id" required class="input @error('lesson_topic_id') input-error @enderror">
                <option value="">{{ __('Select a lesson type') }}</option>
                @foreach ($topics as $topic)
                    <option value="{{ $topic->id }}" @selected(old('lesson_topic_id', $lesson->lesson_topic_id) == $topic->id)>{{ $topic->display_name }}</option>
                @endforeach
            </select>
        </x-field>

        <x-field name="topic" :label="__('Topic')">
            <input id="topic" name="topic" value="{{ old('topic', $lesson->topic) }}" class="input" placeholder="{{ __('e.g. Reverse parking on a slope') }}">
        </x-field>

        <x-field name="vehicle_id" :label="__('Vehicle')">
            <select id="vehicle_id" name="vehicle_id" class="input @error('vehicle_id') input-error @enderror">
                <option value="">{{ __('None') }}</option>
                @foreach ($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}" @selected(old('vehicle_id', $lesson->vehicle_id) == $vehicle->id)>
                        {{ $vehicle->label }} — {{ $vehicle->plate_number }}
                    </option>
                @endforeach
            </select>
        </x-field>

        <x-field name="duration_minutes" :label="__('Duration (minutes)')" required>
            <input id="duration_minutes" name="duration_minutes" type="number" min="5" max="600" required
                   value="{{ old('duration_minutes', $lesson->duration_minutes ?: 60) }}" class="input">
        </x-field>

        <x-field name="performance" :label="__('Performance')">
            <select id="performance" name="performance" class="input">
                <option value="">{{ __('Not rated') }}</option>
                @foreach (\App\Models\Lesson::PERFORMANCES as $performance)
                    <option value="{{ $performance }}" @selected(old('performance', $lesson->performance) === $performance)>
                        {{ __(ucwords(str_replace('_', ' ', $performance))) }}
                    </option>
                @endforeach
            </select>
        </x-field>

        <x-field name="status" :label="__('Status')" required>
            <select id="status" name="status" class="input">
                @foreach (\App\Models\Lesson::STATUSES as $status)
                    <option value="{{ $status }}" @selected(old('status', $lesson->status) === $status)>{{ __(ucfirst($status)) }}</option>
                @endforeach
            </select>
        </x-field>

        <x-field name="notes" :label="__('Notes')" class="sm:col-span-2">
            <textarea id="notes" name="notes" rows="3" class="input">{{ old('notes', $lesson->notes) }}</textarea>
        </x-field>
    </div>
</div>
