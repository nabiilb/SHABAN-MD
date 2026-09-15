<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public static function log(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        $user = Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $oldValues ? self::clean($oldValues) : null,
            'new_values' => $newValues ? self::clean($newValues) : null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }

    public static function created(Model $subject, string $description): AuditLog
    {
        return self::log(
            self::actionFor($subject, 'created'),
            $subject,
            $description,
            null,
            $subject->getAttributes(),
        );
    }

    public static function updated(Model $subject, string $description, array $original): AuditLog
    {
        return self::log(
            self::actionFor($subject, 'updated'),
            $subject,
            $description,
            array_intersect_key($original, $subject->getChanges()),
            $subject->getChanges(),
        );
    }

    public static function deleted(Model $subject, string $description): AuditLog
    {
        return self::log(
            self::actionFor($subject, 'deleted'),
            $subject,
            $description,
            $subject->getAttributes(),
        );
    }

    protected static function actionFor(Model $subject, string $verb): string
    {
        return strtolower(class_basename($subject)).'.'.$verb;
    }

    /** Never persist secrets into the audit trail. */
    protected static function clean(array $values): array
    {
        return collect($values)
            ->except(['password', 'remember_token'])
            ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value)
            ->all();
    }
}
