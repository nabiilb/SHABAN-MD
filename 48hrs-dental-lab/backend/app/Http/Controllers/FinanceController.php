<?php

namespace App\Http\Controllers;

use App\Domain\Catalog;
use App\Http\Requests\CreateInvoiceRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Services\FinanceService;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function invoices(Request $request): JsonResponse
    {
        $q = new Query($request);

        return response()->json($this->finance->invoices($request->user(), [
            'search' => $q->str('search'), 'status' => $q->enum('status', Catalog::PAYMENT_STATUSES), 'clinicId' => $q->str('clinicId'), 'doctorId' => $q->str('doctorId'),
            'from' => $q->day('from'), 'to' => $q->day('to'), 'sort' => $q->str('sort'), 'dir' => $q->dir(),
        ], $q->page()));
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        return response()->json($this->finance->invoice($request->user(), $id));
    }

    public function createInvoice(CreateInvoiceRequest $request): JsonResponse
    {
        return response()->json($this->finance->createInvoice($request->user(), $request->payload()['caseId']), 201);
    }

    public function payments(Request $request): JsonResponse
    {
        $q = new Query($request);

        return response()->json($this->finance->payments($request->user(), [
            'search' => $q->str('search'), 'method' => $q->enum('method', Catalog::paymentMethods()), 'clinicId' => $q->str('clinicId'),
            'from' => $q->day('from'), 'to' => $q->day('to'), 'sort' => $q->str('sort'), 'dir' => $q->dir(),
        ], $q->page()));
    }

    /** Returns the updated invoice so the client shows the new balance straight away. */
    public function recordPayment(RecordPaymentRequest $request): JsonResponse
    {
        return response()->json($this->finance->recordPayment($request->user(), $request->payload()), 201);
    }
}
