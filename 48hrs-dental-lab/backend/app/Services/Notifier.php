<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\User;
use App\Notifications\LabNotification;
use Illuminate\Support\Facades\Notification;

/** Resolves recipient rules to users and sends in-app notifications — once per user, never to the actor. */
class Notifier
{
    /** Active users of the rule's roles whose role currently grants the permission. */
    public static function recipients(array $rule): array
    {
        return User::query()->where('active', true)->whereIn('role_key', $rule['roles'])
            ->whereExists(fn ($q) => $q->from('role_permissions')->whereColumn('role_permissions.role_key', 'users.role_key')->where('role_permissions.permission_key', $rule['permission']))
            ->pluck('id')->all();
    }

    /** The login linked to a technician profile, when active. */
    public static function technician(?string $technicianId): array
    {
        if (! $technicianId) {
            return [];
        }
        $user = Technician::find($technicianId)?->user;

        return $user?->active ? [$user->id] : [];
    }

    /** Active clinic-portal users of a clinic. */
    public static function clinic(string $clinicId): array
    {
        return User::where('active', true)->where('role_key', 'client')->where('clinic_id', $clinicId)->pluck('id')->all();
    }

    public static function send(array $userIds, array $content, ?string $caseId = null, ?string $exceptUserId = null): int
    {
        $ids = array_values(array_filter(array_unique($userIds), fn ($id) => $id !== $exceptUserId));
        if (! $ids) {
            return 0;
        }
        Notification::send(User::whereIn('id', $ids)->get(), new LabNotification($content, $caseId));

        return count($ids);
    }
}
