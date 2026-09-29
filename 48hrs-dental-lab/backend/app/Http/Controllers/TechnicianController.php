<?php

namespace App\Http\Controllers;

use App\Http\Requests\TechnicianRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TechnicianController extends DirectoryController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->directory->technicians($this->query($request, 'asc'), $this->page($request)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->directory->technician($request->user(), $id));
    }

    public function store(TechnicianRequest $request): JsonResponse
    {
        return response()->json($this->directory->createTechnician($request->user(), $request->payload()), 201);
    }

    public function update(TechnicianRequest $request, string $id): JsonResponse
    {
        return response()->json($this->directory->updateTechnician($request->user(), $id, $request->payload()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->directory->deleteTechnician($request->user(), $id);

        return response()->noContent();
    }
}
