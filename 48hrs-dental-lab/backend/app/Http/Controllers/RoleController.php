<?php

namespace App\Http\Controllers;

use App\Http\Requests\RolePermissionsRequest;
use App\Services\AdminService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    public function index(): JsonResponse
    {
        return response()->json($this->admin->roles());
    }

    public function permissions(): JsonResponse
    {
        return response()->json($this->admin->permissions());
    }

    public function update(RolePermissionsRequest $request, string $key): JsonResponse
    {
        return response()->json($this->admin->updateRole($request->user(), $key, $request->payload()['permissions']));
    }
}
