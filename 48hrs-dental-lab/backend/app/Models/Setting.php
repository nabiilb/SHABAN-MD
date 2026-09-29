<?php

namespace App\Models;

class Setting extends LabModel
{
    protected $primaryKey = 'key';

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return ['value' => 'array', 'updated_at' => 'datetime'];
    }
}
