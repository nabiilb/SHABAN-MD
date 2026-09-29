<?php

namespace App\Http\Controllers;

use App\Domain\Catalog;
use App\Models\Delivery;
use App\Models\QualityCheck;
use App\Support\Present;
use App\Support\Query;
use App\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Quality-control and delivery history (the live queues are case lists filtered by status). */
class LabController extends Controller
{
    private function caseScope(Request $request): \Closure
    {
        return fn ($c) => Scope::cases($c, $request->user());
    }

    public function qualityChecks(Request $request): JsonResponse
    {
        $q = new Query($request);
        $result = $q->enum('result', ['passed', 'failed']);
        $technicianId = $q->str('technicianId');
        $search = $q->str('search');
        $query = QualityCheck::query()->whereHas('dentalCase', $this->caseScope($request));
        if ($result) {
            $query->where('quality_checks.result', $result);
        }
        if ($technicianId) {
            $query->whereHas('dentalCase', fn ($c) => $c->where('technician_id', $technicianId));
        }
        if ($search) {
            $like = Query::like($search);
            $query->where(fn ($w) => $w->where('quality_checks.notes', 'like', $like)
                ->orWhereHas('checkedBy', fn ($u) => $u->where('name', 'like', $like))
                ->orWhereHas('dentalCase', fn ($c) => $c->where('case_number', 'like', $like)->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like))->orWhereHas('clinic', fn ($k) => $k->where('name', 'like', $like))));
        }
        $total = (clone $query)->count();
        $p = Query::paginate($q->page(), $total);
        $dir = $q->dir();
        $query->select('quality_checks.*');
        match ($q->str('sort') ?? 'checkedAt') {
            'caseNumber' => $query->join('cases as sc', 'sc.id', '=', 'quality_checks.case_id')->orderBy('sc.case_number', $dir)->orderByDesc('quality_checks.checked_at'),
            'result' => $query->orderBy('quality_checks.result', $dir)->orderByDesc('quality_checks.checked_at'),
            default => $query->orderBy('quality_checks.checked_at', $dir),
        };
        $rows = $query->with(['issues', 'checkedBy:id,name', 'dentalCase:id,case_number,status,technician_id,patient_id,clinic_id', 'dentalCase.technician:id,name', 'dentalCase.patient:id,name', 'dentalCase.clinic:id,name'])->offset($p['offset'])->limit($p['limit'])->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            ...Present::qualityCheck($r),
            'caseNumber' => $r->dentalCase->case_number,
            'caseStatus' => $r->dentalCase->status,
            'patientName' => $r->dentalCase->patient->name,
            'clinicName' => $r->dentalCase->clinic->name,
            'technicianName' => $r->dentalCase->technician?->name,
        ])->all(), 'meta' => $p['meta']]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $q = new Query($request);
        $status = $q->enum('status', Catalog::DELIVERY_STATUSES);
        $method = $q->enum('method', Catalog::deliveryMethods());
        $search = $q->str('search');
        $query = Delivery::query()->whereHas('dentalCase', $this->caseScope($request));
        if ($status) {
            $query->where('deliveries.status', $status);
        }
        if ($method) {
            $query->where('deliveries.method', $method);
        }
        if ($search) {
            $like = Query::like($search);
            $query->where(fn ($w) => $w->where('deliveries.received_by', 'like', $like)->orWhere('deliveries.courier_name', 'like', $like)
                ->orWhereHas('dentalCase', fn ($c) => $c->where('case_number', 'like', $like)->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like))->orWhereHas('clinic', fn ($k) => $k->where('name', 'like', $like))));
        }
        $total = (clone $query)->count();
        $p = Query::paginate($q->page(), $total);
        $dir = $q->dir();
        $query->select('deliveries.*');
        match ($q->str('sort') ?? 'deliveredAt') {
            'caseNumber' => $query->join('cases as sc', 'sc.id', '=', 'deliveries.case_id')->orderBy('sc.case_number', $dir),
            'clinic' => $query->join('cases as sc', 'sc.id', '=', 'deliveries.case_id')->join('clinics as sk', 'sk.id', '=', 'sc.clinic_id')->orderBy('sk.name', $dir)->orderByDesc('deliveries.created_at'),
            default => $query->orderByRaw('deliveries.delivered_at IS NULL ASC')->orderBy('deliveries.delivered_at', $dir)->orderByRaw('deliveries.dispatched_at IS NULL ASC')->orderBy('deliveries.dispatched_at', $dir)->orderBy('deliveries.created_at', $dir),
        };
        $rows = $query->with(['recordedBy:id,name', 'dentalCase:id,case_number,patient_id,clinic_id', 'dentalCase.patient:id,name', 'dentalCase.clinic:id,name'])->offset($p['offset'])->limit($p['limit'])->get();

        return response()->json(['data' => $rows->map(fn ($d) => [...Present::delivery($d), 'caseNumber' => $d->dentalCase->case_number, 'patientName' => $d->dentalCase->patient->name, 'clinicName' => $d->dentalCase->clinic->name])->all(), 'meta' => $p['meta']]);
    }
}
