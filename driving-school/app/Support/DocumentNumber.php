<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentNumber
{
    /**
     * Builds the next sequential document number for a model, e.g. STD-0007.
     * Zero padding keeps the numbers lexicographically ordered, so the newest
     * row can be found with a plain ORDER BY. Call inside a transaction: the
     * row lock stops two concurrent requests minting the same number.
     */
    public static function next(string $modelClass, string $column, string $prefix, int $pad = 4): string
    {
        /** @var Model $model */
        $model = new $modelClass;

        $latest = DB::table($model->getTable())
            ->where($column, 'like', $prefix.'-%')
            ->orderByDesc($column)
            ->lockForUpdate()
            ->value($column);

        $sequence = $latest ? (int) substr((string) $latest, strlen($prefix) + 1) : 0;

        return $prefix.'-'.str_pad((string) ($sequence + 1), $pad, '0', STR_PAD_LEFT);
    }
}
