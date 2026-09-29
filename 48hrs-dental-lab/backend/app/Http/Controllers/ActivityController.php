<?php

namespace App\Http\Controllers;

use App\Services\AdminService;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    public function index(Request $request): JsonResponse
    {
        $q = new Query($request);

        return response()->json($this->admin->activity($q->str('search'), $q->str('subjectType'), $q->page()));
    }
}
