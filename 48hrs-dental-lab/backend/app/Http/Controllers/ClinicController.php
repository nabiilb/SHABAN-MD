<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClinicRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClinicController extends DirectoryController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->directory->clinics($request->user(), $this->query($request, 'asc'), $this->page($request)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->directory->clinic($request->user(), $id));
    }

    public function store(ClinicRequest $request): JsonResponse
    {
        return response()->json($this->directory->createClinic($request->user(), $request->payload()), 201);
    }

    public function update(ClinicRequest $request, string $id): JsonResponse
    {
        return response()->json($this->directory->updateClinic($request->user(), $id, $request->payload()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->directory->deleteClinic($request->user(), $id);

        return response()->noContent();
    }
}
