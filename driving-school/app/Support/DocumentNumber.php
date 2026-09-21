<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentNumber
{
    /**
     * Builds the next sequential document number for a model, e.g. STD-0007.
     *
     * The highest number is found by reading the suffix as a number, not by
     * sorting the strings: padding is a display convention, and one row that
     * escaped it — an import, a hand-typed correction, STD-901 beside
     * STD-0011 — would otherwise sort to the top and hand out a number that is
     * already taken. Call inside a transaction: the row lock stops two
     * concurrent requests minting the same one.
     */
    public static function next(string $modelClass, string $column, string $prefix, int $pad = 4): string
    {
        /** @var Model $model */
        $model = new $modelClass;

        $offset = strlen($prefix) + 2; // SUBSTRING is 1-based, and skips the dash.

        $highest = DB::table($model->getTable())
            ->where($column, 'like', $prefix.'-%')
            ->lockForUpdate()
            ->selectRaw("max(cast(substring({$column}, {$offset}) as unsigned)) as sequence")
            ->value('sequence');

        return $prefix.'-'.str_pad((string) ((int) $highest + 1), $pad, '0', STR_PAD_LEFT);
    }
}
