<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\DashboardReportRequest;
use App\Services\PerformanceExportService;
use App\Support\Period\PeriodSelection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardReportController extends Controller
{
    public function __construct(private PerformanceExportService $performanceExportService) {}

    /**
     * Ekspor Laporan Dashboard: one Excel workbook with the nine dashboard sheets for the active filter.
     */
    public function __invoke(DashboardReportRequest $request): BinaryFileResponse
    {
        $export = $this->performanceExportService->dashboardReport(
            $request->filter(),
            $request->filterForDataset(PeriodSelection::DATASET_DOWNLOADERS),
            $request->granularity(),
            $request->dayOfWeek(),
        );

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend();
    }
}
