<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Base for every domain model: string primary keys (lower-case ULIDs for new rows,
 * fixed ids for seeded ones), millisecond UTC timestamps, no mass-assignment guard
 * (services build the attribute arrays explicitly from validated input).
 */
abstract class LabModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Model $m) {
            if (! $m->getKey() && ! $m->getIncrementing()) {
                $m->setAttribute($m->getKeyName(), strtolower((string) Str::ulid()));
            }
        });
    }
}
