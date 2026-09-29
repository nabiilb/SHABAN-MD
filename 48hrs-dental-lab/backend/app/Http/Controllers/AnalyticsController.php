<?php

namespace App\Http\Controllers;

use App\Domain\Analytics;
use App\Domain\Catalog;
use App\Domain\Workflow;
use App\Services\AnalyticsService;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    private function filters(Request $request): array
    {
        $q = new Query($request);

        return [
            'from' => $q->str('from') ?? '',
            'to' => $q->str('to') ?? '',
            'technicianId' => $q->str('technicianId'),
            'doctorId' => $q->str('doctorId'),
            'clinicId' => $q->str('clinicId'),
            'status' => $q->enum('status', Workflow::statuses()),
            'caseType' => $q->enum('caseType', Catalog::caseTypes()),
        ];
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->analytics->dashboard($request->user(), Analytics::parsePeriod((new Query($request))->str('period'))));
    }

    public function cases(Request $request): JsonResponse
    {
        return response()->json($this->analytics->caseReport($request->user(), $this->filters($request)));
    }

    public function production(Request $request): JsonResponse
    {
        return response()->json($this->analytics->productionReport($request->user(), $this->filters($request)));
    }

    public function technicians(Request $request): JsonResponse
    {
        return response()->json($this->analytics->technicianReport($request->user(), $this->filters($request)));
    }

    public function clinics(Request $request): JsonResponse
    {
        return response()->json($this->analytics->clinicReport($request->user(), $this->filters($request)));
    }

    public function financial(Request $request): JsonResponse
    {
        return response()->json($this->analytics->financialReport($request->user(), $this->filters($request)));
    }

    public function search(Request $request): JsonResponse
    {
        return response()->json($this->analytics->search($request->user(), (new Query($request))->str('q') ?? ''));
    }
}
