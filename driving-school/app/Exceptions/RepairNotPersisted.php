<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The repair saved without error, and the database does not hold the result.
 *
 * Raised after the transaction has committed and the rows have been read back
 * fresh. It is not a failure the repair can put right — whatever swallowed the
 * write is outside it — so the only useful thing to do is refuse to call the
 * run a success, and say exactly which students and which columns disagree.
 */
class RepairNotPersisted extends RuntimeException
{
    /** @param  array<int, array<string, mixed>>  $rows */
    public function __construct(public readonly array $rows)
    {
        parent::__construct(sprintf(
            '%d student%s reported as corrected but the database does not hold the new values. '
            .'Nothing about this run can be trusted as applied.',
            count($rows),
            count($rows) === 1 ? '' : 's',
        ));
    }
}
