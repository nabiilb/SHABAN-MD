<?php

namespace App\Http\Controllers;

use App\Http\Requests\DoctorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DoctorController extends DirectoryController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->directory->doctors($request->user(), $this->query($request, 'asc'), $this->page($request)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->directory->doctor($request->user(), $id));
    }

    public function store(DoctorRequest $request): JsonResponse
    {
        return response()->json($this->directory->createDoctor($request->user(), $request->payload()), 201);
    }

    public function update(DoctorRequest $request, string $id): JsonResponse
    {
        return response()->json($this->directory->updateDoctor($request->user(), $id, $request->payload()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->directory->deleteDoctor($request->user(), $id);

        return response()->noContent();
    }
}
