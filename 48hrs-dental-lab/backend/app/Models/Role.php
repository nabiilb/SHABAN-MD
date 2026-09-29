<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends LabModel
{
    protected $primaryKey = 'key';

    protected function casts(): array
    {
        return ['locked' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function grants(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_key', 'key');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_key', 'key');
    }
}
