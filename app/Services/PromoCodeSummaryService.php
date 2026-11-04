<?php

namespace App\Services;

use App\Models\Platform;
use App\Models\PromoCode;
use App\Repositories\PromoAnalysisRepository;
use App\Repositories\PromoCodeSummaryRepository;
use App\Support\PromoAnalysisFilter;
use App\Support\PromoCodeGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PromoCodeSummaryService
{
    public const TOP_CODES = 5;

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
            'detail' => [
                'platform_id' => (int) $row->platform_id,
                'code' => $row->code,
                'group' => PromoCodeGroup::encode([
                    'funnel_id' => $row->funnel_id === null ? null : (int) $row->funnel_id,
                    'funnel_name' => $row->funnel_name,
                    'source_id' => $row->source_id === null ? null : (int) $row->source_id,
                    'source_name' => $row->source_name,
                ]),
            ],
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
     * The five most used codes with their credited revenue as a companion metric. Empty when no promo code is
     * used, also when the filter has transactions without promo.
     *
     * @return array<string, mixed>
     */
    public function topCodes(PromoAnalysisFilter $filter): array
    {
        $codes = $this->promoCodeSummaryRepository->topCodes($filter, self::TOP_CODES);
        $transactions = $this->promoAnalysisRepository->transactionTotals($filter);
        $totalRevenue = round($transactions['promo_revenue'] + $transactions['non_promo_revenue'], 2);

        $rawCodes = $this->promoCodeSummaryRepository->rawCodes($filter, $codes
            ->map(fn (object $row) => ['platform_id' => (int) $row->platform_id, 'code' => $row->code])
            ->all());

        return [
            'filter' => $filter->toArray(),
            'is_empty' => $codes->isEmpty(),
            'message' => match (true) {
                $transactions['transactions'] === 0 => 'Data tidak tersedia untuk filter yang dipilih.',
                $codes->isEmpty() => 'Tidak ada penggunaan kode promo pada filter yang dipilih.',
                default => null,
            },
            'codes' => $codes->values()->map(fn (object $row, int $index) => [
                'rank' => $index + 1,
                'code' => $row->code,
                'raw_codes' => $rawCodes->get($row->platform_id.'|'.$row->code, []),
                'is_registered' => $row->promo_code_id !== null,
                'promo_code_id' => $row->promo_code_id === null ? null : (int) $row->promo_code_id,
                'platform' => ['id' => (int) $row->platform_id, 'slug' => $row->platform_slug, 'name' => $row->platform_name],
                'usages' => (int) $row->usages,
                'revenue' => round((float) $row->revenue, 2),
                'contribution_percentage' => $this->percentage((float) $row->revenue, $totalRevenue),
                'detail' => ['platform_id' => (int) $row->platform_id, 'code' => $row->code],
            ])->all(),
        ];
    }

    /**
     * The transactions that used one code, newest first, grouped per paid date. Each date carries the totals of
     * the whole day, also when the day continues on the next page; the pagination counts transactions.
     * With a snapshot group (from the clicked rekap row) only that group's usages are shown, so the totals equal
     * that row; without one, every usage of the code.
     *
     * @param  array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null  $group
     * @return array{days: LengthAwarePaginator, filter: array<string, mixed>, code: array<string, mixed>, totals: array<string, int|float>, is_empty: bool, message: string|null}
     */
    public function usages(PromoAnalysisFilter $filter, int $platformId, string $code, ?array $group, int $perPage): array
    {
        $code = PromoCode::normalizeCode($code);
        $platform = Platform::query()->findOrFail($platformId);

        $transactions = $this->promoCodeSummaryRepository->usageTransactions($filter, $platformId, $code, $group, $perPage);
        $page = collect($transactions->items());

        $usages = $this->promoCodeSummaryRepository
            ->usagesOfTransactions($filter, $platformId, $code, $group, $page->pluck('transaction_id')->map(fn ($id) => (int) $id)->all())
            ->groupBy('transaction_id');
        $days = $this->promoCodeSummaryRepository
            ->dayTotals($filter, $platformId, $code, $group, $page->pluck('paid_date')->unique()->values()->all())
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

        $totals = $this->promoCodeSummaryRepository->usageTotals($filter, $platformId, $code, $group);
        $isEmpty = $totals['usages'] === 0;

        return [
            'days' => $transactions,
            'filter' => $filter->toArray(),
            'code' => [
                'code' => $code,
                'is_registered' => PromoCode::query()->where('platform_id', $platformId)->where('code', $code)->exists(),
                'platform' => $platformData,
                'funnel' => $group === null ? null : $this->metadata($group['funnel_id'], $group['funnel_name']),
                'source' => $group === null ? null : $this->metadata($group['source_id'], $group['source_name']),
                'group' => $group === null ? null : PromoCodeGroup::encode($group),
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
