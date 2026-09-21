<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\User;

/**
 * Keeps the lesson worked on that day attached to that day's attendance.
 *
 * The attendance row owns the link, so a day carries its own lesson and rating
 * and editing a day updates that same lesson instead of piling up new ones.
 */
class DailyLessonService
{
    /**
     * Creates or updates the lesson hanging off an attendance record.
     *
     * @param  array{lesson_topic_id?: int|null, performance?: string|null, topic?: string|null, vehicle_id?: int|null, duration_minutes?: int|null}  $data
     */
    public function sync(Attendance $attendance, array $data, User $actor): ?Lesson
    {
        $topicId = $data['lesson_topic_id'] ?? null;

        // No lesson for the day (an absence, say) — detach and drop any lesson
        // this record had, so nothing is left dangling.
        if (! $topicId) {
            return $this->detach($attendance);
        }

        $attributes = [
            'student_id' => $attendance->student_id,
            'instructor_id' => $attendance->instructor_id,
            'lesson_topic_id' => $topicId,
            'lesson_date' => $attendance->attendance_date,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'topic' => $data['topic'] ?? null,
            'performance' => $data['performance'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'status' => 'completed',
            'recorded_by' => $actor->id,
        ];

        // Reuse the row already linked to this day rather than adding another.
        $lesson = $attendance->lesson;

        if ($lesson) {
            $lesson->update($attributes);
        } else {
            $lesson = Lesson::create($attributes);
            $attendance->forceFill(['lesson_id' => $lesson->id])->save();
        }

        return $lesson->refresh();
    }

    /** Removes the lesson attached to a day, if there is one. */
    public function detach(Attendance $attendance): null
    {
        if ($lesson = $attendance->lesson) {
            $attendance->forceFill(['lesson_id' => null])->save();
            $lesson->delete();
        }

        return null;
    }
}
