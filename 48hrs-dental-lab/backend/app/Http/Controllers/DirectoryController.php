<?php

namespace App\Http\Controllers;

use App\Domain\Catalog;
use App\Services\DirectoryService;
use App\Support\Query;
use Illuminate\Http\Request;

/** Shared list-query parsing for the four directory controllers. */
abstract class DirectoryController extends Controller
{
    public function __construct(protected readonly DirectoryService $directory) {}

    protected function query(Request $request, string $defaultDir): array
    {
        $q = new Query($request);

        return [
            'search' => $q->str('search'),
            'status' => $q->enum('status', Catalog::RECORD_STATUSES),
            'clinicId' => $q->str('clinicId'),
            'active' => $q->bool('active'),
            'sort' => $q->str('sort'),
            'dir' => $q->dir($defaultDir),
        ];
    }

    protected function page(Request $request): array
    {
        return (new Query($request))->page();
    }
}
