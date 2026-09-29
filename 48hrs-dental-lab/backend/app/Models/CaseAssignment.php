<?php

namespace App\Models;

class CaseAssignment extends LabModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    }
}
