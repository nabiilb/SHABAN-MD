<?php

use App\Support\LegacySchema;
use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two active students cannot share a phone number — enforced by the database,
 * not only by the form in front of it.
 *
 * A double-clicked New Student form sends two identical POSTs. A validation
 * rule that queries for an existing student passes in both of them, because
 * neither has committed when the other looks, and the school ends up with the
 * same person registered twice, each copy holding its own perfectly valid
 * initial payment. Only a unique index can refuse the second one.
 *
 * It cannot simply go on `students.phone`:
 *
 *   - The column is NOT NULL and soft-deleted rows keep their value, so a
 *     removed student would hold their number for ever and a genuine
 *     re-registration would be impossible.
 *   - A student who completed or cancelled has finished with the school; the
 *     same person coming back for another licence is a new registration, not a
 *     duplicate.
 *   - "0611000111" and "+252611000111" are the same number written two ways,
 *     and a unique index over the raw column would not know it.
 *
 * So `active_phone_key` is a guard column, like `active_student_id` on the
 * training tables: it carries PhoneNumber::normalize($phone) while the student
 * is active and not deleted, and NULL once they are neither. NULLs do not
 * collide in a MySQL unique index, so any number of finished or removed
 * students may share a number while at most one active student holds it.
 *
 * Additive and idempotent. No row's real data is touched — the guard is derived
 * from `phone`, `status` and `deleted_at`, which the row already has.
 *
 * On any pre-existing pair of active students who do share a number, only the
 * FIRST keeps the guard; the later one is left NULL and listed in the output.
 * Those are real people already on the books: they are not merged, not edited
 * and not deleted, and the Student model keeps them editable by declining to
 * claim a key another active student already holds.
 */
return new class extends Migration
{
    protected const TABLE = 'students';

    protected const INDEX = 'students_active_phone_unique';

    public function up(): void
    {
        if (! LegacySchema::hasTable(self::TABLE)) {
            return;
        }

        if (! LegacySchema::hasColumn(self::TABLE, 'active_phone_key')) {
            DB::statement('ALTER TABLE students ADD COLUMN active_phone_key VARCHAR(30) NULL');
        }

        $this->backfill();

        if (! LegacySchema::hasIndex(self::TABLE, self::INDEX)) {
            DB::statement('ALTER TABLE students ADD UNIQUE KEY '.self::INDEX.' (active_phone_key)');
        }
    }

    public function down(): void
    {
        if (! LegacySchema::hasTable(self::TABLE)) {
            return;
        }

        if (LegacySchema::hasIndex(self::TABLE, self::INDEX)) {
            DB::statement('ALTER TABLE students DROP INDEX '.self::INDEX);
        }

        if (LegacySchema::hasColumn(self::TABLE, 'active_phone_key')) {
            DB::statement('ALTER TABLE students DROP COLUMN active_phone_key');
        }
    }

    /**
     * Normalising is done in PHP rather than SQL so that the migration and the
     * running application agree to the character — there is one implementation
     * of the rule, in App\Support\PhoneNumber, and this uses it.
     */
    protected function backfill(): void
    {
        DB::statement('UPDATE students SET active_phone_key = NULL');

        $claimed = [];
        $clashes = [];

        DB::table('students')
            ->select('id', 'phone', 'full_name')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$claimed, &$clashes) {
                foreach ($rows as $row) {
                    $key = PhoneNumber::normalize($row->phone);

                    if ($key === null) {
                        continue;
                    }

                    // First one on the books keeps the number; a later active
                    // student holding the same one is left alone and reported.
                    if (isset($claimed[$key])) {
                        $clashes[] = "{$row->full_name} (#{$row->id}) shares {$key} with #{$claimed[$key]}";

                        continue;
                    }

                    $claimed[$key] = $row->id;

                    DB::table('students')->where('id', $row->id)->update(['active_phone_key' => $key]);
                }
            });

        if ($clashes !== []) {
            echo PHP_EOL.'  Active students already sharing a phone number — left exactly as they are, '
                .'and still editable; review them by hand:'.PHP_EOL;

            foreach ($clashes as $clash) {
                echo '    - '.$clash.PHP_EOL;
            }

            echo PHP_EOL;
        }
    }
};
