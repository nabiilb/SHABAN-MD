<?php

namespace App\Http\Controllers;

use App\Http\Requests\PatientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PatientController extends DirectoryController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->directory->patients($request->user(), $this->query($request, 'desc'), $this->page($request)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->directory->patient($request->user(), $id));
    }

    public function store(PatientRequest $request): JsonResponse
    {
        return response()->json($this->directory->createPatient($request->user(), $request->payload()), 201);
    }

    public function update(PatientRequest $request, string $id): JsonResponse
    {
        return response()->json($this->directory->updatePatient($request->user(), $id, $request->payload()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->directory->deletePatient($request->user(), $id);

        return response()->noContent();
    }
}
