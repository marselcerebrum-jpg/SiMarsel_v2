<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promo\PromoAnalysisExportRequest;
use App\Services\PromoAnalysisExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PromoAnalysisExportController extends Controller
{
    public function __construct(private PromoAnalysisExportService $promoAnalysisExportService) {}

    /**
     * Download every Analisis Promo component for the active filter as one Excel file.
     */
    public function __invoke(PromoAnalysisExportRequest $request): BinaryFileResponse
    {
        $export = $this->promoAnalysisExportService->export(
            $request->promoFilter(),
            $request->period(),
            $request->sort(),
            $request->direction(),
        );

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend();
    }
}
