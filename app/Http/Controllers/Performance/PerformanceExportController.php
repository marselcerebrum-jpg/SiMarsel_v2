<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PerformanceExportRequest;
use App\Services\PerformanceExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PerformanceExportController extends Controller
{
    public function __construct(private PerformanceExportService $performanceExportService) {}

    /**
     * Download the Ringkasan Performa data for the active filter as Excel or CSV.
     */
    public function __invoke(PerformanceExportRequest $request): BinaryFileResponse
    {
        $export = $this->performanceExportService->export($request->filter(), $request->validated('format'));

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend();
    }
}
