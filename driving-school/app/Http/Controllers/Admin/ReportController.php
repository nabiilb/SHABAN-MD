<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\Instructor;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(): View
    {
        $this->authorize('access-admin');

        return view('admin.reports.index', ['titles' => $this->reports->titles()]);
    }

    public function show(Request $request, string $report): View|StreamedResponse|Response
    {
        $this->authorize('access-admin');

        abort_unless(in_array($report, ReportService::ADMIN_REPORTS, true), 404);

        $filters = $this->filters($request);
        $result = $this->reports->build($report, $filters, $request->user());
        $title = $this->reports->titles()[$report] ?? __('Report');

        return match ($request->string('export')->toString()) {
            'csv' => $this->csv($report, $result),
            'pdf' => $this->pdf($title, $result, $filters),
            default => view('admin.reports.show', [
                'report' => $report,
                'title' => $title,
                'result' => $result,
                'filters' => $filters,
                'instructors' => Instructor::orderBy('full_name')->get(['id', 'full_name']),
                'students' => Student::orderBy('full_name')->get(['id', 'full_name']),
                'vehicles' => Vehicle::orderBy('vehicle_number')->get(['id', 'vehicle_number', 'plate_number']),
                'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
                'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            ]),
        };
    }

    protected function filters(Request $request): array
    {
        return array_filter($request->only([
            'date_from', 'date_to', 'instructor_id', 'student_id',
            'vehicle_id', 'supplier_id', 'status', 'expense_category_id',
        ]), fn ($value) => $value !== null && $value !== '');
    }

    protected function csv(string $report, array $result): StreamedResponse
    {
        $filename = $report.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($result) {
            $handle = fopen('php://output', 'w');

            // BOM so Excel reads UTF-8 (Somali names) correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $result['columns']);

            foreach ($result['rows'] as $row) {
                fputcsv($handle, array_values((array) $row));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function pdf(string $title, array $result, array $filters): Response
    {
        $pdf = Pdf::loadView('admin.reports.pdf', [
            'title' => $title,
            'result' => $result,
            'filters' => $filters,
        ])->setPaper('a4', 'landscape');

        return $pdf->download(str($title)->slug().'-'.now()->format('Ymd-His').'.pdf');
    }
}
