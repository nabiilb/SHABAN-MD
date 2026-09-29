<?php

namespace App\Support;

use App\Domain\Permissions;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Row-level scope (the shared canViewCase rule in SQL): staff with cases.view_all
 * see every case, technicians their assigned cases, clinic users their clinic's.
 * Anything outside is reported as 404 so its existence is not revealed.
 */
final class Scope
{
    public static function cases(Builder $q, User $u, string $table = 'cases'): Builder
    {
        if (! $u->hasPermission(Permissions::CASES_VIEW)) {
            return $q->whereRaw('1 = 0');
        }
        if ($u->hasPermission(Permissions::CASES_VIEW_ALL)) {
            return $q;
        }
        if ($u->clinic_id) {
            return $q->where("{$table}.clinic_id", $u->clinic_id);
        }
        $tech = $u->technicianId();
        if ($tech) {
            return $q->where("{$table}.technician_id", $tech);
        }

        return $q->whereRaw('1 = 0');
    }

    /** The clinic a non-staff user is restricted to: null = no restriction, '' = nothing visible. */
    public static function clinic(User $u): ?string
    {
        if ($u->hasPermission(Permissions::CASES_VIEW_ALL)) {
            return null;
        }

        return $u->clinic_id ?? '';
    }

    public static function whereClinic(Builder $q, User $u, string $column): Builder
    {
        $clinic = self::clinic($u);
        if ($clinic === null) {
            return $q;
        }

        return $clinic === '' ? $q->whereRaw('1 = 0') : $q->where($column, $clinic);
    }
}
