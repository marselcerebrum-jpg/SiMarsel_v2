<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\PackageDetailRequest;
use App\Http\Resources\PackageDetailResource;
use App\Services\PackageDetailService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PackageDetailController extends Controller
{
    public function __construct(private PackageDetailService $packageDetailService) {}

    public function index(PackageDetailRequest $request): PackageDetailResource
    {
        return PackageDetailResource::make($this->detail($request));
    }

    public function export(PackageDetailRequest $request): StreamedResponse
    {
        $detail = $this->detail($request);
        $rows = $this->packageDetailService->exportRows($detail['packages']);

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\u{FEFF}");
            fputcsv($output, PackageDetailService::EXPORT_HEADERS, escape: '');

            foreach ($rows as $row) {
                fputcsv($output, $row, escape: '');
            }

            fclose($output);
        }, $this->packageDetailService->exportFileName($detail['filter']), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PackageDetailRequest $request): array
    {
        $month = $request->validated('month');

        return $this->packageDetailService->detail(
            $request->validated('platform'),
            (int) $request->validated('year'),
            $month === null ? null : (int) $month,
            $request->validated('search'),
        );
    }
}
