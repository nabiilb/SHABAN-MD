<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Services\AdminService;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServiceCatalogueController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->admin->services((new Query($request))->bool('includeInactive') ?? false));
    }

    public function store(ServiceRequest $request): JsonResponse
    {
        return response()->json($this->admin->createService($request->user(), $request->payload()), 201);
    }

    public function update(ServiceRequest $request, string $id): JsonResponse
    {
        return response()->json($this->admin->updateService($request->user(), $id, $request->payload()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->admin->deleteService($request->user(), $id);

        return response()->noContent();
    }
}
