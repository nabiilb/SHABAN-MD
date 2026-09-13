<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Schema questions a migration can ask on any MySQL back to 5.5.
 *
 * Laravel's own Schema::hasColumn() reads information_schema.columns and asks
 * for generation_expression, a column that only exists from MySQL 5.7 (and
 * MariaDB 10.2) onwards. On an older server the inspection itself fails with
 *
 *     SQLSTATE[42S22]: Column not found: 1054
 *     Unknown column 'generation_expression' in 'field list'
 *
 * before a single line of DDL runs — so a migration that merely *guards* itself
 * with hasColumn() cannot execute there at all. SHOW COLUMNS and SHOW INDEX
 * report the same facts and have been in MySQL since long before 5.5.
 */
class LegacySchema
{
    public static function hasTable(string $table): bool
    {
        // information_schema.tables is safe on 5.5, and '=' avoids the LIKE
        // wildcards that underscores in our table names would otherwise be.
        return DB::selectOne(
            'select 1 as found from information_schema.tables
             where table_schema = database() and table_name = ?',
            [$table],
        ) !== null;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return static::column($table, $column) !== null;
    }

    /**
     * True when the column is a MySQL 5.7+ generated (virtual or stored)
     * column. SHOW COLUMNS reports that in Extra, on every server that can
     * have one.
     */
    public static function isGeneratedColumn(string $table, string $column): bool
    {
        $definition = static::column($table, $column);

        return $definition !== null
            && str_contains(strtoupper((string) ($definition->Extra ?? '')), 'GENERATED');
    }

    public static function hasIndex(string $table, string $index): bool
    {
        if (! static::hasTable($table)) {
            return false;
        }

        foreach (DB::select('SHOW INDEX FROM `'.$table.'`') as $row) {
            if (($row->Key_name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    /** The raw SHOW COLUMNS row, or null when the table or column is absent. */
    protected static function column(string $table, string $column): ?object
    {
        if (! static::hasTable($table)) {
            return null;
        }

        foreach (DB::select('SHOW COLUMNS FROM `'.$table.'`') as $row) {
            if (($row->Field ?? null) === $column) {
                return $row;
            }
        }

        return null;
    }
}
