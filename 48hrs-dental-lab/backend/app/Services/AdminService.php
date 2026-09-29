<?php

namespace App\Services;

use App\Domain\Permissions;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\LabService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Technician;
use App\Models\User;
use App\Support\Activity;
use App\Support\AuthSession;
use App\Support\LabSettings;
use App\Support\Present;
use App\Support\Query;
use Illuminate\Support\Facades\DB;

/** Users, roles & permissions, the service catalogue, lab settings and the activity log. */
class AdminService
{
    /* -------------------------------- Users ------------------------------- */

    public function users(array $q, array $page): array
    {
        $query = User::with('technician:id,user_id');
        if ($q['role']) {
            $query->where('role_key', $q['role']);
        }
        if ($q['active'] !== null) {
            $query->where('active', $q['active']);
        }
        if ($q['search']) {
            $like = Query::like($q['search']);
            $roleMatches = array_keys(array_filter(Permissions::ROLE_LABELS, fn ($label) => str_contains(mb_strtolower($label), mb_strtolower($q['search']))));
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhereIn('role_key', $roleMatches));
        }
        $users = $query->get()->map(fn ($u) => Present::user($u))->all();
        $keys = [
            'name' => fn ($u) => $u['name'],
            'email' => fn ($u) => $u['email'],
            'role' => fn ($u) => Permissions::ROLE_LABELS[$u['role']],
            'lastLoginAt' => fn ($u) => $u['lastLoginAt'],
            'status' => fn ($u) => $u['active'] ? 1 : 0,
        ];
        $get = $keys[$q['sort'] ?? 'name'] ?? $keys['name'];
        $m = $q['dir'] === 'asc' ? 1 : -1;
        usort($users, function ($a, $b) use ($get, $m) {
            [$va, $vb] = [$get($a), $get($b)];
            if ($va === $vb) {
                return 0;
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }

            return (is_string($va) ? strcasecmp($va, $vb) <=> 0 : ($va < $vb ? -1 : 1)) * $m;
        });
        $p = Query::paginate($page, count($users));

        return ['data' => array_slice($users, $p['offset'], $p['limit']), 'meta' => $p['meta']];
    }

    private function validateUser(User $actor, array $b, ?string $selfId = null): void
    {
        $errors = [];
        if (User::where('email', $b['email'])->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists()) {
            $errors['email'] = ['Another user already uses this email.'];
        }
        if ($b['role'] === 'super_admin' && $actor->role_key !== 'super_admin') {
            $errors['role'] = ['Only a Super Admin can grant Super Admin.'];
        }
        if ($b['role'] === 'client' && ! $b['clinicId']) {
            $errors['clinicId'] = ['Client users must be linked to a clinic.'];
        }
        if ($b['clinicId'] && ! Clinic::whereKey($b['clinicId'])->exists()) {
            $errors['clinicId'] = ['Select a valid clinic.'];
        }
        if (! $selfId && empty($b['password'])) {
            $errors['password'] = ['Set an initial password of at least 8 characters.'];
        }
        ApiException::throwIf($errors);
    }

    /** Technician logins are always linked to a technician profile (created when missing). */
    private function linkTechnician(string $userId, array $b): void
    {
        Technician::where('user_id', $userId)->when($b['role'] === 'technician', fn ($q) => $q->where('id', '!=', $b['technicianId'] ?? ''))->update(['user_id' => null]);
        if ($b['role'] !== 'technician') {
            return;
        }
        $tech = $b['technicianId'] ? Technician::find($b['technicianId']) : Technician::whereRaw('LOWER(email) = ?', [mb_strtolower($b['email'])])->first();
        if ($tech?->user_id && $tech->user_id !== $userId) {
            throw ApiException::validation(['technicianId' => ['This technician profile is linked to another login.']]);
        }
        $tech ??= Technician::create(['name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'] !== '' ? $b['phone'] : '-', 'specialty' => 'General', 'active' => $b['active']]);
        $tech->forceFill(['user_id' => $userId])->save();
    }

    private function present(string $id): array
    {
        return Present::user(User::with('technician:id,user_id')->findOrFail($id));
    }

    public function createUser(User $actor, array $b): array
    {
        $this->validateUser($actor, $b);
        $id = DB::transaction(function () use ($actor, $b) {
            $u = User::create(['name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'], 'role_key' => $b['role'], 'active' => $b['active'], 'clinic_id' => $b['role'] === 'client' ? $b['clinicId'] : null, 'password' => $b['password']]);
            $this->linkTechnician($u->id, $b);
            Activity::log($actor, 'user.create', "Created user {$u->name} (".Permissions::ROLE_LABELS[$b['role']].')', 'user', $u->id, $u->email);

            return $u->id;
        });

        return $this->present($id);
    }

    public function updateUser(User $actor, string $id, array $b): array
    {
        $u = User::find($id) ?? throw ApiException::notFound();
        if ($u->role_key === 'super_admin' && $actor->role_key !== 'super_admin') {
            throw ApiException::forbidden();
        }
        $this->validateUser($actor, $b, $id);
        if ($u->id === $actor->id && ($b['role'] !== $u->role_key || ! $b['active'])) {
            throw ApiException::validation(['role' => ['You cannot change your own role or disable yourself.']]);
        }
        DB::transaction(function () use ($actor, $u, $b) {
            $fields = ['name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'], 'role_key' => $b['role'], 'active' => $b['active'], 'clinic_id' => $b['role'] === 'client' ? $b['clinicId'] : null];
            if (! empty($b['password'])) {
                $fields['password'] = $b['password'];
            }
            $u->forceFill($fields)->save();
            // A new password or a disabled account ends that user's other sign-ins at once.
            if (! empty($b['password']) || ! $b['active']) {
                AuthSession::endAllFor($u->id, $u->id === $actor->id ? request()->session()->getId() : null);
            }
            $this->linkTechnician($u->id, $b);
            Activity::log($actor, 'user.update', "Updated user {$b['name']}", 'user', $u->id, $b['email']);
        });

        return $this->present($id);
    }

    public function setActive(User $actor, string $id, bool $active): array
    {
        $u = User::find($id) ?? throw ApiException::notFound();
        if ($u->id === $actor->id) {
            throw ApiException::unprocessable('You cannot disable your own account.');
        }
        if ($u->role_key === 'super_admin' && $actor->role_key !== 'super_admin') {
            throw ApiException::forbidden();
        }
        DB::transaction(function () use ($actor, $u, $active) {
            $u->forceFill(['active' => $active])->save();
            if (! $active) {
                AuthSession::endAllFor($u->id);
            }
            Activity::log($actor, $active ? 'user.enable' : 'user.disable', ($active ? 'Enabled' : 'Disabled')." {$u->name}", 'user', $u->id, $u->email);
        });

        return $this->present($id);
    }

    public function deleteUser(User $actor, string $id): void
    {
        $u = User::find($id) ?? throw ApiException::notFound();
        if ($u->id === $actor->id) {
            throw ApiException::unprocessable('You cannot delete your own account.');
        }
        if ($u->role_key === 'super_admin' && User::where('role_key', 'super_admin')->count() <= 1) {
            throw ApiException::unprocessable('The last Super Admin cannot be deleted.');
        }
        if ($u->role_key === 'super_admin' && $actor->role_key !== 'super_admin') {
            throw ApiException::forbidden();
        }
        $history = [['case_status_history', 'user_id'], ['cases', 'created_by_id'], ['case_notes', 'author_id'], ['case_attachments', 'uploaded_by_id'], ['quality_checks', 'checked_by_id'], ['deliveries', 'recorded_by_id'], ['payments', 'received_by_id'], ['case_assignments', 'assigned_by_id']];
        foreach ($history as [$table, $column]) {
            if (DB::table($table)->where($column, $u->id)->exists()) {
                throw ApiException::unprocessable('This user has case history. Disable the account instead so the audit trail stays intact.');
            }
        }
        DB::transaction(function () use ($actor, $u) {
            AuthSession::endAllFor($u->id);
            $u->delete();
            Activity::log($actor, 'user.delete', "Deleted user {$u->name}", 'user', null, $u->email);
        });
    }

    /* ------------------------ Roles & permissions ------------------------ */

    public function roles(): array
    {
        $grants = RolePermission::all()->groupBy('role_key');
        $roles = Role::all()->map(fn ($r) => ['key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'locked' => (bool) $r->locked, 'permissions' => $r->locked ? Permissions::allKeys() : Permissions::ordered(($grants[$r->key] ?? collect())->pluck('permission_key')->all())])->all();
        usort($roles, fn ($a, $b) => array_search($a['key'], Permissions::ROLE_ORDER) <=> array_search($b['key'], Permissions::ROLE_ORDER));

        return $roles;
    }

    public function permissions(): array
    {
        $order = array_flip(Permissions::allKeys());
        $rows = Permission::all()->map(fn ($p) => array_filter(['key' => $p->key, 'label' => $p->label, 'group' => $p->group, 'description' => $p->description], fn ($v) => $v !== null))->all();
        usort($rows, fn ($a, $b) => ($order[$a['key']] ?? 999) <=> ($order[$b['key']] ?? 999));

        return $rows;
    }

    public function updateRole(User $actor, string $key, array $permissions): array
    {
        $role = Role::find($key) ?? throw ApiException::notFound();
        if ($role->locked) {
            throw ApiException::unprocessable("{$role->name} always has full access and cannot be edited.");
        }
        $known = Permission::pluck('key')->all();
        $requested = array_values(array_unique(array_intersect($permissions, $known)));
        if ($actor->role_key === $role->key && ! in_array(Permissions::ROLES_MANAGE, $requested, true)) {
            throw ApiException::unprocessable('You cannot remove your own access to roles.');
        }
        DB::transaction(function () use ($actor, $role, $requested) {
            RolePermission::where('role_key', $role->key)->delete();
            RolePermission::insert(array_map(fn ($k) => ['role_key' => $role->key, 'permission_key' => $k], $requested));
            Activity::log($actor, 'role.update', "Changed permissions of {$role->name}", 'role', $role->key, $role->name);
        });

        return collect($this->roles())->firstWhere('key', $key);
    }

    /* ------------------------- Service catalogue ------------------------- */

    public function services(bool $includeInactive): array
    {
        return LabService::query()->when(! $includeInactive, fn ($q) => $q->where('active', true))->orderBy('case_type')->orderBy('name')->get()->map(fn ($s) => Present::service($s))->all();
    }

    private function serviceFields(array $b): array
    {
        return ['name' => $b['name'], 'case_type' => $b['caseType'], 'unit_mode' => $b['unitMode'], 'unit_price' => $b['unitPrice'], 'default_material' => $b['defaultMaterial'], 'active' => $b['active']];
    }

    public function createService(User $actor, array $b): array
    {
        $s = DB::transaction(function () use ($actor, $b) {
            $s = LabService::create($this->serviceFields($b));
            Activity::log($actor, 'service.create', "Added service {$s->name}", 'service', $s->id, $s->name);

            return $s;
        });

        return Present::service($s->fresh());
    }

    /** Prices apply to new cases only — existing cases keep the unit price they were accepted at. */
    public function updateService(User $actor, string $id, array $b): array
    {
        $s = LabService::find($id) ?? throw ApiException::notFound();
        DB::transaction(function () use ($actor, $s, $b) {
            $s->forceFill($this->serviceFields($b))->save();
            Activity::log($actor, 'service.update', "Updated service {$s->name}", 'service', $s->id, $s->name);
        });

        return Present::service($s->fresh());
    }

    public function deleteService(User $actor, string $id): void
    {
        $s = LabService::withCount('cases')->find($id) ?? throw ApiException::notFound();
        if ($s->cases_count) {
            throw ApiException::unprocessable('This service is used by existing cases. Deactivate it instead.');
        }
        DB::transaction(function () use ($actor, $s) {
            $s->delete();
            Activity::log($actor, 'service.delete', "Deleted service {$s->name}", 'service', null, $s->name);
        });
    }

    /* ------------------------------ Settings ----------------------------- */

    public function updateSettings(User $actor, array $b): array
    {
        return DB::transaction(function () use ($actor, $b) {
            $saved = LabSettings::put([...LabSettings::get(), ...$b]);
            Activity::log($actor, 'settings.update', 'Updated lab settings', 'settings');

            return $saved;
        });
    }

    /* ---------------------------- Activity log --------------------------- */

    /** The audit trail: the activity log plus every case status change (with its note), newest first. */
    public function activity(?string $search, ?string $subjectType, array $page): array
    {
        $log = DB::table('activity_log as a')
            ->selectRaw('a.id, a.user_id, a.user_name, a.action, a.description, a.subject_type, a.subject_id, a.subject_label, a.created_at')
            ->whereRaw("NOT (a.action LIKE 'case.%' AND a.action NOT IN ('case.create', 'case.update', 'case.delete') AND a.action NOT LIKE 'case.file%')");
        $history = DB::table('case_status_history as h')->join('users as u', 'u.id', '=', 'h.user_id')->join('cases as c', 'c.id', '=', 'h.case_id')
            ->selectRaw("h.id, h.user_id, u.name AS user_name, CONCAT('case.status.', h.to_status) AS action,
                CONCAT(CASE WHEN h.from_status IS NULL THEN CONCAT('Created as ', REPLACE(h.to_status, '_', ' '))
                            ELSE CONCAT('Moved from ', REPLACE(h.from_status, '_', ' '), ' to ', REPLACE(h.to_status, '_', ' ')) END,
                       COALESCE(CONCAT(' — ', h.note), '')) AS description,
                'case' AS subject_type, h.case_id AS subject_id, c.case_number AS subject_label, h.created_at");
        $feed = DB::query()->fromSub($log->unionAll($history), 'f');
        if ($subjectType) {
            $feed->where('subject_type', $subjectType);
        }
        if ($search) {
            $like = Query::like($search);
            $feed->where(fn ($w) => $w->where('user_name', 'like', $like)->orWhere('description', 'like', $like)->orWhere('subject_label', 'like', $like));
        }
        $total = (clone $feed)->count();
        $p = Query::paginate($page, $total);
        $rows = $feed->orderByDesc('created_at')->orderByDesc('id')->offset($p['offset'])->limit($p['limit'])->get();

        return ['data' => $rows->map(fn ($r) => Present::activity($r))->all(), 'meta' => $p['meta']];
    }
}
