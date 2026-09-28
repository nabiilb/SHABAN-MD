<?php

namespace App\Services;

use App\Models\Instructor;
use App\Models\Role;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;

/**
 * Keeps a user account and its instructor profile in step.
 *
 * /instructor/dashboard is guarded by the instructor.profile middleware, which
 * refuses an instructor with no `instructors` row — correctly, because every
 * isolation scope in the app derives from `instructors.id`, not from the user.
 * Creating the account without the profile therefore produced an instructor who
 * could sign in and reach nothing: a data problem, not an authorization one.
 *
 * So the profile is now created with the account, in the same transaction: if
 * the profile cannot be written the account is not written either, and there is
 * never an instructor login without somewhere to work.
 */
class InstructorProfileService
{
    /**
     * Gives a user the instructor profile their role implies, or updates the
     * one they already have. Returns null for a user who is not an instructor.
     *
     * Call inside the caller's transaction — creating a user and its profile is
     * one write, not two.
     */
    public function sync(User $user, array $extra = []): ?Instructor
    {
        if (! $user->hasRole(Role::INSTRUCTOR)) {
            $this->standDown($user);

            return null;
        }

        $profile = Instructor::withTrashed()->where('user_id', $user->id)->first();

        if ($profile) {
            return $this->refresh($profile, $user, $extra);
        }

        return $this->create($user, $extra);
    }

    protected function create(User $user, array $extra): Instructor
    {
        $instructor = Instructor::create([
            'instructor_number' => DocumentNumber::next(Instructor::class, 'instructor_number', 'INS'),
            'user_id' => $user->id,
            'full_name' => $user->name,
            // The instructors table requires a phone; the user's is optional,
            // so fall back to something obviously provisional rather than
            // failing the whole account over a blank field.
            'phone' => $user->phone ?: ($extra['phone'] ?? '—'),
            'email' => $user->email,
            'address' => $extra['address'] ?? null,
            'qualification' => $extra['qualification'] ?? null,
            'joining_date' => Carbon::parse($extra['joining_date'] ?? now())->toDateString(),
            'status' => 'active',
            'notes' => $extra['notes'] ?? null,
        ]);

        AuditLogger::created($instructor, "Instructor profile {$instructor->instructor_number} created for {$user->email}");

        return $instructor;
    }

    /**
     * Mirrors the account's name, phone and email onto the existing profile,
     * and brings back one that was stood down when the role was taken away.
     */
    protected function refresh(Instructor $instructor, User $user, array $extra): Instructor
    {
        $original = $instructor->getOriginal();

        if ($instructor->trashed()) {
            $instructor->restore();
        }

        $instructor->fill([
            'full_name' => $user->name,
            'phone' => $user->phone ?: $instructor->phone,
            'email' => $user->email,
            'status' => $instructor->status === 'inactive' ? 'active' : $instructor->status,
        ]);

        foreach (['address', 'qualification', 'notes'] as $optional) {
            if (array_key_exists($optional, $extra) && $extra[$optional] !== null) {
                $instructor->{$optional} = $extra[$optional];
            }
        }

        if ($instructor->isDirty()) {
            $instructor->save();
            AuditLogger::updated($instructor, "Instructor profile {$instructor->instructor_number} synchronised", $original);
        }

        return $instructor;
    }

    /**
     * A user who is no longer an instructor keeps their profile and its
     * history — attendance, lessons and loans all point at it — but stops
     * being an active teacher. Deleting it would take the school's records
     * with it.
     */
    protected function standDown(User $user): void
    {
        $instructor = Instructor::where('user_id', $user->id)->where('status', '!=', 'inactive')->first();

        if (! $instructor) {
            return;
        }

        $original = $instructor->getOriginal();
        $instructor->update(['status' => 'inactive']);

        AuditLogger::updated(
            $instructor,
            "Instructor profile {$instructor->instructor_number} set inactive — {$user->email} is no longer an instructor",
            $original,
        );
    }
}
