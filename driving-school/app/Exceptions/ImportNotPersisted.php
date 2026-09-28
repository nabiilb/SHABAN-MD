<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The import wrote without error, and the database does not hold the result.
 *
 * Raised after the transaction has committed and the rows have been read back
 * fresh. Nothing about the run can be treated as applied, so the only useful
 * thing to do is refuse to call it a success and say which rows disagree.
 */
class ImportNotPersisted extends RuntimeException
{
    /** @param  array<int, array<string, mixed>>  $rows */
    public function __construct(public readonly array $rows)
    {
        parent::__construct(sprintf(
            '%d expense row%s reported as created but the database does not hold them. '
            .'Nothing about this run can be trusted as imported.',
            count($rows),
            count($rows) === 1 ? '' : 's',
        ));
    }
}
