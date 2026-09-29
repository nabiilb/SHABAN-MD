<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An in-app notification row (written by App\Notifications\Channels\LabChannel). */
class UserNotification extends LabModel
{
    protected $table = 'notifications';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function dentalCase(): BelongsTo
    {
        return $this->belongsTo(DentalCase::class, 'case_id');
    }
}
