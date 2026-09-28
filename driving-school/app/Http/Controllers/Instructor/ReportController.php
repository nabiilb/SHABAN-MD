<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(): View
    {
        return view('instructor.reports.index', [
            'titles' => collect($this->reports->titles())
                ->only(ReportService::INSTRUCTOR_REPORTS)
                ->all(),
        ]);
    }

    /**
     * An instructor can only run the four "my" reports, and every one of them
     * is built through the visibleTo() scope, so no other instructor's rows
     * can appear even if the filters are tampered with.
     */
    public function show(Request $request, string $report): View|StreamedResponse
    {
        abort_unless(in_array($report, ReportService::INSTRUCTOR_REPORTS, true), 404);

        $filters = array_filter($request->only(['date_from', 'date_to', 'student_id', 'status']));
        $result = $this->reports->build($report, $filters, $request->user());
        $title = $this->reports->titles()[$report] ?? __('Report');

        if ($request->string('export')->toString() === 'csv') {
            return response()->streamDownload(function () use ($result) {
                $handle = fopen('php://output', 'w');
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $result['columns']);

                foreach ($result['rows'] as $row) {
                    fputcsv($handle, array_values((array) $row));
                }

                fclose($handle);
            }, $report.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('instructor.reports.show', [
            'report' => $report,
            'title' => $title,
            'result' => $result,
            'filters' => $filters,
            'students' => Student::visibleTo($request->user())->orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }
}
