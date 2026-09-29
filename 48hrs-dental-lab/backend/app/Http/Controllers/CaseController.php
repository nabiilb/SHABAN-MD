<?php

namespace App\Http\Controllers;

use App\Domain\Catalog;
use App\Domain\Workflow;
use App\Http\Requests\CaseAssignRequest;
use App\Http\Requests\CaseDeliveryRequest;
use App\Http\Requests\CaseNoteRequest;
use App\Http\Requests\CaseQcRequest;
use App\Http\Requests\CaseReworkRequest;
use App\Http\Requests\CaseStatusRequest;
use App\Http\Requests\CreateCaseRequest;
use App\Http\Requests\UpdateCaseRequest;
use App\Services\AttachmentService;
use App\Services\CaseQuery;
use App\Services\CaseService;
use App\Services\CaseWorkflowService;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaseController extends Controller
{
    private const WORKFLOW_REQUESTS = [
        'status' => CaseStatusRequest::class,
        'assign' => CaseAssignRequest::class,
        'qc' => CaseQcRequest::class,
        'rework' => CaseReworkRequest::class,
        'delivery' => CaseDeliveryRequest::class,
    ];

    public function __construct(private readonly CaseService $cases, private readonly CaseWorkflowService $workflow, private readonly AttachmentService $files) {}

    public function index(Request $request): JsonResponse
    {
        $q = new Query($request);
        $filters = [
            'search' => $q->str('search'),
            'status' => $q->enumList('status', Workflow::statuses()),
            'priority' => $q->enumList('priority', Catalog::priorities()),
            'technicianId' => $q->str('technicianId'),
            'doctorId' => $q->str('doctorId'),
            'clinicId' => $q->str('clinicId'),
            'patientId' => $q->str('patientId'),
            'caseType' => $q->enum('caseType', Catalog::caseTypes()),
            'paymentStatus' => $q->enum('paymentStatus', Catalog::PAYMENT_STATUSES),
            'sla' => $q->enum('sla', CaseQuery::SLA_FILTERS),
            'from' => $q->day('from'),
            'to' => $q->day('to'),
            'dueFrom' => $q->day('dueFrom'),
            'dueTo' => $q->day('dueTo'),
            'openOnly' => $q->bool('openOnly'),
        ];
        $sort = $q->enum('sort', CaseQuery::SORTS) ?? 'receivedAt';

        return response()->json($this->cases->list($request->user(), $filters, $q->page(), $sort, $q->dir()));
    }

    public function counts(Request $request): JsonResponse
    {
        return response()->json($this->cases->counts($request->user()));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->cases->get($request->user(), $id));
    }

    public function store(CreateCaseRequest $request): JsonResponse
    {
        return response()->json($this->cases->create($request->user(), $request->payload()), 201);
    }

    public function update(UpdateCaseRequest $request, string $id): JsonResponse
    {
        return response()->json($this->cases->update($request->user(), $id, $request->payload()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->cases->remove($request->user(), $id);

        return response()->noContent();
    }

    /** POST /cases/{id}/status | assign | qc | rework | delivery */
    public function workflow(Request $request, string $id, string $endpoint): JsonResponse
    {
        /** @var \App\Http\Requests\ApiRequest $form */
        $form = app(self::WORKFLOW_REQUESTS[$endpoint]);

        return response()->json($this->workflow->perform($request->user(), $id, $endpoint, $form->payload()));
    }

    public function addNote(CaseNoteRequest $request, string $id): JsonResponse
    {
        return response()->json($this->cases->addNote($request->user(), $id, $request->payload()['text']), 201);
    }

    public function upload(Request $request, string $id): JsonResponse
    {
        return response()->json($this->files->upload($request->user(), $id, $request->file('file'), $request->input('category')), 201);
    }

    public function removeAttachment(Request $request, string $id, string $attachmentId): Response
    {
        $this->files->remove($request->user(), $id, $attachmentId);

        return response()->noContent();
    }

    public function download(Request $request, string $id, string $attachmentId): StreamedResponse
    {
        return $this->files->download($request->user(), $id, $attachmentId);
    }
}
