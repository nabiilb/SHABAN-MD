<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Services\AdminService;
use App\Support\LabSettings;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    public function show(): JsonResponse
    {
        return response()->json(LabSettings::get());
    }

    public function update(SettingsRequest $request): JsonResponse
    {
        return response()->json($this->admin->updateSettings($request->user(), $request->payload()));
    }
}
