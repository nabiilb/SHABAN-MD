<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;

/** Appends to the audit trail (inside the caller's transaction when there is one). */
final class Activity
{
    public static function log(User $user, string $action, string $description, string $subjectType, ?string $subjectId = null, ?string $subjectLabel = null): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
        ]);
    }
}
