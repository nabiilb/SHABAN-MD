<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * The schema must install on MySQL 5.5.
 *
 * The training queue originally guarded a teacher's single live session with a
 * generated column, and guarded the migration itself with Schema::hasColumn().
 * Both need MySQL 5.7: the first for GENERATED ALWAYS, the second because
 * Laravel's column inspection selects information_schema.columns.generation_expression,
 * which does not exist before 5.7. On a 5.5 server the migration died with
 * "1054 Unknown column 'generation_expression' in 'field list'" before running
 * any DDL at all.
 *
 * These tests keep it that way by two means. The static checks below read the
 * migrations as text, so a future migration reaching for a 5.7-only feature
 * fails here rather than on somebody's XAMPP. The emulation test then runs
 * `migrate` for real with a hook that refuses exactly what 5.5 refuses, so the
 * whole schema is proved installable rather than merely inspected.
 */
class LegacyMysqlCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Column inspection that reads generation_expression, so MySQL 5.7 and up
     * only. Written to miss App\Support\LegacySchema, whose whole purpose is
     * to answer the same questions without it.
     */
    private const INSPECTION_CALLS = [
        '/(?<!\w)Schema::(hasColumn|hasColumns|getColumns|getColumnListing|getColumnType|whenTableHasColumn|whenTableDoesntHaveColumn)\s*\(/',
        '/->(hasColumn|getColumnListing|getColumnType)\s*\(/',
    ];

    /** DDL features MySQL 5.5 does not have. */
    private const MODERN_DDL = [
        '/GENERATED\s+ALWAYS/i',
        '/->(virtualAs|storedAs|generatedAs|json|jsonb|fullText)\s*\(/',
    ];

    public function test_no_migration_inspects_columns_through_laravels_schema_builder(): void
    {
        foreach ($this->migrations() as $name => $code) {
            foreach (self::INSPECTION_CALLS as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, sprintf(
                    '%s matches %s. Laravel column inspection reads '
                    .'information_schema.columns.generation_expression, which does not exist before '
                    .'MySQL 5.7, so the check itself fails with error 1054 on 5.5. '
                    .'Use App\Support\LegacySchema instead.',
                    $name,
                    $pattern,
                ));
            }
        }
    }

    public function test_no_migration_uses_ddl_newer_than_mysql_5_5(): void
    {
        foreach ($this->migrations() as $name => $code) {
            foreach (self::MODERN_DDL as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, sprintf(
                    '%s matches %s, which MySQL 5.5 does not support.',
                    $name,
                    $pattern,
                ));
            }
        }
    }

    /**
     * The guarantee itself has to survive the rewrite: losing the generated
     * columns must not mean losing the unique indexes that enforce one live
     * session per student and one per teacher.
     */
    public function test_both_concurrency_guards_are_plain_columns_under_unique_indexes(): void
    {
        foreach (['active_student_id', 'active_instructor_id'] as $guard) {
            $column = collect(DB::select('SHOW COLUMNS FROM `training_sessions`'))
                ->firstWhere('Field', $guard);

            $this->assertNotNull($column, "training_sessions.{$guard} is missing.");
            $this->assertStringNotContainsStringIgnoringCase('generated', (string) $column->Extra);
            $this->assertSame('YES', $column->Null, "{$guard} must be nullable — NULLs are what let finished sessions share the index.");
        }

        $unique = collect(DB::select('SHOW INDEX FROM `training_sessions`'))
            ->where('Non_unique', 0)
            ->pluck('Column_name')
            ->all();

        $this->assertContains('active_student_id', $unique);
        $this->assertContains('active_instructor_id', $unique);
    }

    /**
     * Runs the real `php artisan migrate` into a scratch database, with every
     * query first passed through a hook that fails the way MySQL 5.5 fails.
     *
     * This is emulation, not a 5.5 server: it proves the migrations never ask
     * for anything 5.5 lacks, which is precisely the class of bug that broke
     * the training queue. It cannot prove anything about 5.5's own quirks.
     */
    public function test_every_migration_runs_against_a_mysql_5_5_shaped_server(): void
    {
        $default = config('database.default');
        $scratch = config('database.connections.mysql.database').'_legacy_check';

        // A server-level connection, so creating and dropping the scratch
        // database cannot implicitly commit the test's own transaction.
        config([
            'database.connections.legacy_root' => array_merge(config('database.connections.mysql'), ['database' => null]),
            'database.connections.legacy_check' => array_merge(config('database.connections.mysql'), ['database' => $scratch]),
        ]);

        $root = DB::connection('legacy_root');

        try {
            $root->statement("DROP DATABASE IF EXISTS `{$scratch}`");
            $root->statement("CREATE DATABASE `{$scratch}` DEFAULT CHARACTER SET utf8mb4");
        } catch (QueryException $e) {
            $this->markTestSkipped('The test database user cannot create databases: '.$e->getMessage());
        }

        $this->refuseWhatMysql55Refuses();

        try {
            $status = Artisan::call('migrate', ['--database' => 'legacy_check', '--force' => true]);

            $this->assertSame(0, $status, 'migrate failed: '.Artisan::output());

            $tables = DB::connection('legacy_check')->select(
                'select table_name as name from information_schema.tables where table_schema = ?',
                [$scratch],
            );

            $this->assertContains('training_sessions', array_column($tables, 'name'));
            $this->assertContains('audit_logs', array_column($tables, 'name'));
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('legacy_check');
            $root->statement("DROP DATABASE IF EXISTS `{$scratch}`");
            DB::purge('legacy_root');
        }
    }

    /**
     * A harness that let everything through would make the test above prove
     * nothing, so check it still rejects the two statements that actually broke
     * the training queue on the user's server.
     */
    public function test_the_mysql_5_5_emulation_rejects_what_broke_the_original_migration(): void
    {
        config(['database.connections.legacy_check' => config('database.connections.mysql')]);

        $this->refuseWhatMysql55Refuses();

        $connection = DB::connection('legacy_check');

        try {
            foreach ([
                // What Schema::hasColumn() asks MySQL for.
                'select column_name, generation_expression as `expression` from information_schema.columns',
                // What the original teacher guard tried to create.
                'ALTER TABLE training_sessions ADD COLUMN active_instructor_id BIGINT UNSIGNED '
                    ."GENERATED ALWAYS AS (CASE WHEN status IN ('in_progress') THEN instructor_id END) STORED",
                // The audit log's original column type.
                'CREATE TABLE t (`old_values` json null)',
            ] as $statement) {
                try {
                    $connection->statement($statement);
                    $this->fail('A MySQL 5.5 server would have refused: '.$statement);
                } catch (QueryException $e) {
                    $this->assertMatchesRegularExpression('/1054|1064/', $e->getMessage());
                }
            }
        } finally {
            DB::purge('legacy_check');
        }
    }

    /**
     * MySQL 5.5 has no generation_expression column, no JSON type and no
     * generated columns. Refuse all three the way it would.
     */
    private function refuseWhatMysql55Refuses(): void
    {
        DB::connection('legacy_check')->beforeExecuting(function (string $query): void {
            $lowered = strtolower($query);

            if (str_contains($lowered, 'generation_expression') || str_contains($lowered, 'is_generated')) {
                throw $this->serverError(
                    $query,
                    "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'generation_expression' in 'field list'",
                );
            }

            if (str_contains($lowered, 'generated always')) {
                throw $this->serverError($query, 'SQLSTATE[42000]: Syntax error: 1064 near "GENERATED ALWAYS"');
            }

            if (preg_match('/`\w+`\s+json\b/i', $query)) {
                throw $this->serverError($query, 'SQLSTATE[42000]: Syntax error: 1064 near "json"');
            }

            // InnoDB's 767-byte index prefix, which a utf8mb4 VARCHAR(256+)
            // blows past the moment it is indexed.
            if (preg_match('/varchar\((\d+)\)/i', $query, $match)
                && (int) $match[1] > 191
                && preg_match('/\b(primary key|unique)\b/i', $query)) {
                throw $this->serverError($query, 'SQLSTATE[42000]: 1071 Specified key was too long; max key length is 767 bytes');
            }
        });
    }

    private function serverError(string $query, string $message): QueryException
    {
        return new QueryException('legacy_check', $query, [], new PDOException($message));
    }

    /**
     * Every migration's source with comments removed, keyed by file name — the
     * checks above are about what the code does, and this file's own prose
     * names the very constructs it forbids.
     *
     * @return array<string, string>
     */
    private function migrations(): array
    {
        $files = glob(database_path('migrations').'/*.php');

        $this->assertNotEmpty($files);

        $sources = [];

        foreach ($files as $file) {
            $code = '';

            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            $sources[basename($file)] = $code;
        }

        return $sources;
    }
}
