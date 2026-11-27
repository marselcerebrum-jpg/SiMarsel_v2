<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\TransactionExportRequest;
use App\Http\Requests\Performance\TransactionListRequest;
use App\Models\Transaction;
use App\Services\TransactionListExportService;
use App\Services\TransactionListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Daftar Transaksi (FIX-13-BE-01): the paginated list, its Filter Lainnya options, and the detail of one transaction.
 * Ekspor Daftar Transaksi (FIX-14-BE-02) writes the same filtered rows as Excel or CSV.
 */
class TransactionController extends Controller
{
    public function __construct(
        private TransactionListService $transactionListService,
        private TransactionListExportService $transactionListExportService,
    ) {}

    /**
     * Rows newest first on the active date basis, with pagination. meta.filter is the main Dashboard filter used.
     */
    public function index(TransactionListRequest $request): JsonResponse
    {
        $filter = $this->transactionListService->filter($request->filter(), $request->localFilters());
        $page = $this->transactionListService->paginate($filter, $request->perPage(), $request->page());

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'date_basis' => $filter->dateBasis->value,
                'filter' => $request->filter()->toArray(),
            ],
        ]);
    }

    /**
     * The values Filter Lainnya can take within the main period, platforms, and date basis.
     */
    public function options(TransactionListRequest $request): JsonResponse
    {
        $filter = $this->transactionListService->filter($request->filter(), [
            'dateBasis' => $request->localFilters()['dateBasis'],
        ]);

        return response()->json([
            'data' => $this->transactionListService->options($filter),
            'meta' => ['date_basis' => $filter->dateBasis->value, 'filter' => $request->filter()->toArray()],
        ]);
    }

    /**
     * Every row of the list filter as Excel or CSV (FIX-14-BE-02), without pagination.
     */
    public function export(TransactionExportRequest $request): BinaryFileResponse
    {
        $filter = $this->transactionListService->filter($request->filter(), $request->localFilters());
        $from = $request->filter()->from()->toDateString();
        $to = $request->filter()->to()->toDateString();
        $format = $request->exportFormat();

        $export = $this->transactionListExportService->export(
            $filter,
            $format,
            "daftar_transaksi_{$from}_{$to}.{$format}",
        );

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend();
    }

    /**
     * Older imports without a Markaz identity or creation time cannot be read by the transaction dates contract,
     * so they are not available (404), like an unknown id.
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        abort_if($transaction->markaz_transaction_id === null || $transaction->markaz_created_at === null, 404);

        return response()->json([
            'data' => $this->transactionListService->detail(
                $transaction,
                $request->user()->can('viewContact', $transaction),
            ),
        ]);
    }
}
