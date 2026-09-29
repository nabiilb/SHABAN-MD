<?php

namespace Database\Seeders;

use App\Domain\Catalog;
use App\Domain\Permissions;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\LabSettings;
use App\Support\Sequences;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Production-safe and idempotent: the permission catalogue (refreshed), default
 * roles (created once — later edits are kept), lab settings and number sequences.
 * Never touches business data.
 */
class BaseSeeder extends Seeder
{
    public function run(array $settings = Catalog::DEFAULT_LAB_SETTINGS): void
    {
        DB::transaction(function () use ($settings) {
            foreach (Permissions::catalogue() as $p) {
                Permission::updateOrCreate(['key' => $p['key']], ['label' => $p['label'], 'group' => $p['group']]);
            }
            foreach (Permissions::defaultRoles() as $r) {
                $role = Role::find($r['key']);
                if (! $role) {
                    Role::create(['key' => $r['key'], 'name' => $r['name'], 'description' => $r['description'], 'locked' => $r['locked']]);
                    RolePermission::insert(array_map(fn ($k) => ['role_key' => $r['key'], 'permission_key' => $k], $r['permissions']));
                } elseif ($r['locked']) {
                    // A locked role always holds the full catalogue, including permissions added later.
                    RolePermission::insertOrIgnore(array_map(fn ($k) => ['role_key' => $r['key'], 'permission_key' => $k], Permissions::allKeys()));
                }
            }
            if (! DB::table('settings')->where('key', 'lab')->exists()) {
                LabSettings::put($settings);
            }
            foreach ([Sequences::CASE, Sequences::INVOICE, Sequences::PATIENT] as $name) {
                DB::table('sequences')->insertOrIgnore(['name' => $name, 'value' => 0]);
            }
        });
    }
}
