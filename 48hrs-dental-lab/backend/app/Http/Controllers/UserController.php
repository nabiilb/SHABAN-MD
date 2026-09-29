<?php

namespace App\Http\Controllers;

use App\Domain\Permissions;
use App\Http\Requests\UserRequest;
use App\Http\Requests\UserStatusRequest;
use App\Services\AdminService;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    public function index(Request $request): JsonResponse
    {
        $q = new Query($request);

        return response()->json($this->admin->users(['search' => $q->str('search'), 'role' => $q->enum('role', Permissions::ROLE_ORDER), 'active' => $q->bool('active'), 'sort' => $q->str('sort'), 'dir' => $q->dir('asc')], $q->page()));
    }

    public function store(UserRequest $request): JsonResponse
    {
        return response()->json($this->admin->createUser($request->user(), $request->payload()), 201);
    }

    public function update(UserRequest $request, string $id): JsonResponse
    {
        return response()->json($this->admin->updateUser($request->user(), $id, $request->payload()));
    }

    public function setStatus(UserStatusRequest $request, string $id): JsonResponse
    {
        return response()->json($this->admin->setActive($request->user(), $id, $request->payload()['active']));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->admin->deleteUser($request->user(), $id);

        return response()->noContent();
    }
}
