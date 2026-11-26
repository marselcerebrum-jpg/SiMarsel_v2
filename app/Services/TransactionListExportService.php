<?php

namespace App\Services;

use App\Enums\MarkazTransactionStatus;
use App\Models\Platform;
use App\Models\Transaction;
use App\Repositories\TransactionListRepository;
use App\Support\SpreadsheetWriter;
use App\Support\TransactionList\TransactionListFilter;
use Generator;

/**
 * Ekspor Daftar Transaksi (FIX-14-BE-02): every row of the list filter, without pagination, in the 13 columns of the
 * ticket. It reads the same base query as the list (TransactionListRepository::query()) and the same financial rules
 * (TransactionListService::revenueContribution()), so the file and the list pick the same rows in the same order.
 */
class TransactionListExportService
{
    /**
     * The header of both formats, in the order of the ticket.
     */
    public const HEADERS = [
        'ID Transaksi',
        'Tanggal Dibuat',
        'Tanggal Dibayar',
        'Platform',
        'Produk',
        'Customer',
        'Metode Pembayaran',
        'Harga Paket',
        'Potongan Voucher',
        'Biaya Admin',
        'Nominal',
        'Kontribusi Omzet',
        'Status',
    ];

    public const SHEET = 'Daftar Transaksi';

    /** Text starting with one of these is read as a formula by spreadsheet programs. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private TransactionListRepository $transactionList,
        private TransactionListService $transactionListService,
        private SpreadsheetWriter $spreadsheetWriter,
    ) {}

    /**
     * @param  'xlsx'|'csv'  $format
     * @return array{path: string, filename: string}
     */
    public function export(TransactionListFilter $filter, string $format, string $filename): array
    {
        $path = tempnam(sys_get_temp_dir(), 'transaction-list-export-');

        $this->spreadsheetWriter->table($this->rows($filter, $format), $format, $path, self::SHEET);

        return ['path' => $path, 'filename' => $filename];
    }

    /**
     * The header, then one row per checkout. The cursor reads the rows one by one.
     *
     * @param  'xlsx'|'csv'  $format
     * @return Generator<int, list<string|int|float|null>>
     */
    public function rows(TransactionListFilter $filter, string $format): Generator
    {
        yield self::HEADERS;

        $platforms = Platform::query()->pluck('name', 'id');

        foreach ($this->transactionList->query($filter)->cursor() as $transaction) {
            yield $this->row($transaction, $platforms->get($transaction->platform_id), $format);
        }
    }

    /**
     * @return list<string|int|float|null>
     */
    public function row(Transaction $transaction, ?string $platformName, string $format): array
    {
        $status = $transaction->reportStatus();

        return [
            $this->text($transaction->trx_id, $format),
            $transaction->markaz_created_at->format('Y-m-d H:i:s'),
            $this->paidAt($transaction),
            $this->text($platformName, $format),
            $this->text($transaction->product, $format),
            $this->text($transaction->customer_name, $format),
            $this->text($this->blankToNull($transaction->payment_method), $format),
            $this->money($transaction->product_price),
            $this->money($transaction->voucher_discount),
            $this->money($transaction->admin_fee),
            $this->money($transaction->nominal),
            // Blank cell (not 0) when the row does not count in Omzet: Pending, claims, and group members.
            $this->money($this->transactionListService->revenueContribution($transaction)),
            MarkazTransactionStatus::labelFor($status),
        ];
    }

    /**
     * The last valid Tanggal Dibayar, or Belum tersedia when no valid paid date was ever stored.
     */
    private function paidAt(Transaction $transaction): string
    {
        if ($transaction->paid_date === null) {
            return 'Belum tersedia';
        }

        return trim($transaction->paid_date->toDateString().' '.($transaction->paid_time ?? ''));
    }

    /**
     * Keeps the value as text in CSV, where a leading = would otherwise be evaluated as a formula. XLSX cells stay
     * text on their own, so their values are written unchanged.
     */
    private function text(?string $value, string $format): ?string
    {
        if ($value === null || $format !== 'csv') {
            return $value;
        }

        return in_array(substr($value, 0, 1), self::FORMULA_PREFIXES, true) ? "'".$value : $value;
    }

    private function money(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
