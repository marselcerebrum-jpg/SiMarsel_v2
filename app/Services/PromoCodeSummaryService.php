<?php

namespace App\Services;

use App\Models\Platform;
use App\Models\PromoCode;
use App\Repositories\PromoAnalysisRepository;
use App\Repositories\PromoCodeSummaryRepository;
use App\Support\PromoAnalysisFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PromoCodeSummaryService
{
    public function __construct(
        private PromoCodeSummaryRepository $promoCodeSummaryRepository,
        private PromoAnalysisRepository $promoAnalysisRepository,
    ) {}

    /**
     * Usages, credited revenue, and revenue share per code, as a page of rows plus the totals of the whole filter.
     * The share uses the revenue of every filtered transaction, so codes do not have to add up to 100%.
     *
     * @return array{codes: LengthAwarePaginator, filter: array<string, mixed>, totals: array<string, int|float>, is_empty: bool, message: string|null}
     */
    public function summary(PromoAnalysisFilter $filter, string $sort, string $direction, int $perPage): array
    {
        $codes = $this->promoCodeSummaryRepository->paginate($filter, $sort, $direction, $perPage);
        $transactions = $this->promoAnalysisRepository->transactionTotals($filter);
        $totalRevenue = round($transactions['promo_revenue'] + $transactions['non_promo_revenue'], 2);
        $codeTotals = $this->promoCodeSummaryRepository->totals($filter);

        $rawCodes = $this->promoCodeSummaryRepository->rawCodes($filter, collect($codes->items())
            ->map(fn (object $row) => ['platform_id' => (int) $row->platform_id, 'code' => $row->code])
            ->unique()
            ->values()
            ->all());

        $codes->setCollection($codes->getCollection()->map(fn (object $row) => [
            'code' => $row->code,
            'raw_codes' => $rawCodes->get($row->platform_id.'|'.$row->code, []),
            'is_registered' => $row->promo_code_id !== null,
            'promo_code_id' => $row->promo_code_id === null ? null : (int) $row->promo_code_id,
            'platform' => ['id' => (int) $row->platform_id, 'slug' => $row->platform_slug, 'name' => $row->platform_name],
            'funnel' => $this->metadata($row->funnel_id, $row->funnel_name),
            'source' => $this->metadata($row->source_id, $row->source_name),
            'usages' => (int) $row->usages,
            'revenue' => round((float) $row->revenue, 2),
            'contribution_percentage' => $this->percentage((float) $row->revenue, $totalRevenue),
            'detail' => ['platform_id' => (int) $row->platform_id, 'code' => $row->code],
        ]));

        $isEmpty = $transactions['transactions'] === 0;

        return [
            'codes' => $codes,
            'filter' => $filter->toArray(),
            'totals' => [
                'transactions' => $transactions['transactions'],
                'revenue' => $totalRevenue,
                'usages' => $codeTotals['usages'],
                'code_revenue' => round($codeTotals['revenue'], 2),
            ],
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
        ];
    }

    /**
     * The transactions that used one code, newest first, grouped per paid date. Each date carries the totals of
     * the whole day, also when the day continues on the next page; the pagination counts transactions.
     *
     * @return array{days: LengthAwarePaginator, filter: array<string, mixed>, code: array<string, mixed>, totals: array<string, int|float>, is_empty: bool, message: string|null}
     */
    public function usages(PromoAnalysisFilter $filter, int $platformId, string $code, int $perPage): array
    {
        $code = PromoCode::normalizeCode($code);
        $platform = Platform::query()->findOrFail($platformId);

        $transactions = $this->promoCodeSummaryRepository->usageTransactions($filter, $platformId, $code, $perPage);
        $page = collect($transactions->items());

        $usages = $this->promoCodeSummaryRepository
            ->usagesOfTransactions($filter, $platformId, $code, $page->pluck('transaction_id')->map(fn ($id) => (int) $id)->all())
            ->groupBy('transaction_id');
        $days = $this->promoCodeSummaryRepository
            ->dayTotals($filter, $platformId, $code, $page->pluck('paid_date')->unique()->values()->all())
            ->keyBy(fn (object $day) => $this->date($day->paid_date));
        $platformData = ['id' => $platform->id, 'slug' => $platform->slug, 'name' => $platform->name];

        $transactions->setCollection($page
            ->groupBy(fn (object $row) => $this->date($row->paid_date))
            ->map(fn (Collection $rows, string $date) => [
                'date' => $date,
                'transactions_count' => (int) $days[$date]->transactions,
                'usages' => (int) $days[$date]->usages,
                'revenue' => round((float) $days[$date]->revenue, 2),
                'transactions' => $rows->map(function (object $row) use ($usages, $platformData) {
                    $codeUsages = $usages->get($row->transaction_id, collect());
                    $first = $codeUsages->first();

                    return [
                        'trx_id' => $row->trx_id,
                        'paid_date' => $this->date($row->paid_date),
                        'paid_time' => $row->paid_time,
                        'platform' => $platformData,
                        'code_positions' => $codeUsages->pluck('code_position')->map(fn ($position) => (int) $position)->all(),
                        'raw_codes' => $codeUsages->pluck('raw_code')->all(),
                        'funnel' => $first ? $this->metadata($first->funnel_id, $first->funnel_name) : null,
                        'source' => $first ? $this->metadata($first->source_id, $first->source_name) : null,
                        'usages' => (int) $row->usages,
                        'revenue' => round((float) $row->revenue, 2),
                        'transaction_nominal' => round((float) $row->nominal, 2),
                    ];
                })->values()->all(),
            ])
            ->values());

        $totals = $this->promoCodeSummaryRepository->usageTotals($filter, $platformId, $code);
        $isEmpty = $totals['usages'] === 0;

        return [
            'days' => $transactions,
            'filter' => $filter->toArray(),
            'code' => [
                'code' => $code,
                'is_registered' => PromoCode::query()->where('platform_id', $platformId)->where('code', $code)->exists(),
                'platform' => $platformData,
            ],
            'totals' => [...$totals, 'revenue' => round($totals['revenue'], 2)],
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
        ];
    }

    /**
     * A master funnel or source by its current name, a removed one by its snapshot name, or null when there is none.
     *
     * @return array{id: int|null, name: string}|null
     */
    private function metadata(int|string|null $id, ?string $name): ?array
    {
        if ($id === null && blank($name)) {
            return null;
        }

        return ['id' => $id === null ? null : (int) $id, 'name' => $name];
    }

    private function percentage(float $value, float $total): float|int
    {
        return $total > 0 ? round($value / $total * 100, 2) : 0;
    }

    /**
     * paid_date comes back as "Y-m-d" or "Y-m-d H:i:s" depending on the database driver.
     */
    private function date(string $value): string
    {
        return substr($value, 0, 10);
    }
}
