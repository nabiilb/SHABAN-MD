<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pivot row: role_key + permission_key (composite primary key). */
class RolePermission extends Model
{
    protected $table = 'role_permissions';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];
}
