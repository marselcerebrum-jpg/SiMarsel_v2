<?php

namespace App\Repositories;

use App\Models\PromoCode;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Promo codes used by transactions that have no master for their platform yet, one row per
 * platform + normalized code.
 */
class UnregisteredPromoCodeRepository
{
    /**
     * Usage counts come from transaction_promo_codes rows, so a code used twice in one transaction counts twice.
     *
     * @return LengthAwarePaginator<int, object{platform_id: int, platform_slug: string, platform_name: string, code: string, usages_count: int, transactions_count: int, first_used_on: string, last_used_on: string}>
     */
    public function paginate(?string $search, ?int $platformId, int $perPage): LengthAwarePaginator
    {
        $search = trim((string) $search);

        return $this->unregisteredUsages()
            ->join('transactions', 'transactions.id', '=', 'usages.transaction_id')
            ->join('platforms', 'platforms.id', '=', 'usages.platform_id_snapshot')
            ->when($platformId !== null, fn (Builder $query) => $query->where('usages.platform_id_snapshot', $platformId))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('usages.code_snapshot', 'like', '%'.PromoCode::normalizeCode($search).'%')
                ->orWhere('usages.raw_code', 'like', '%'.$search.'%')
                ->orWhere('platforms.name', 'like', '%'.$search.'%')
                ->orWhere('platforms.slug', 'like', '%'.$search.'%')))
            ->groupBy('usages.platform_id_snapshot', 'usages.code_snapshot', 'platforms.slug', 'platforms.name')
            ->selectRaw(
                'usages.platform_id_snapshot as platform_id, platforms.slug as platform_slug, platforms.name as platform_name, '
                .'usages.code_snapshot as code, COUNT(*) as usages_count, COUNT(DISTINCT usages.transaction_id) as transactions_count, '
                .'MIN(transactions.paid_date) as first_used_on, MAX(transactions.paid_date) as last_used_on'
            )
            ->orderByDesc('usages_count')
            ->orderBy('usages.code_snapshot')
            ->orderBy('usages.platform_id_snapshot')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The distinct raw values written by Markaz for the given platform + code pairs, exactly as written.
     *
     * @param  list<array{platform_id: int, code: string}>  $pairs
     * @return Collection<string, list<string>> Keyed by "platform_id|code".
     */
    public function rawCodes(array $pairs): Collection
    {
        if ($pairs === []) {
            return collect();
        }

        return $this->unregisteredUsages()
            ->where(function (Builder $query) use ($pairs) {
                foreach ($pairs as $pair) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('usages.platform_id_snapshot', $pair['platform_id'])
                        ->where('usages.code_snapshot', $pair['code']));
                }
            })
            // Grouped by the exact bytes: MySQL's case-insensitive collation would merge "CPNS26" and "cpns26".
            ->groupBy('usages.platform_id_snapshot', 'usages.code_snapshot')
            ->groupByRaw('HEX(usages.raw_code)')
            ->selectRaw('usages.platform_id_snapshot, usages.code_snapshot, MIN(usages.raw_code) as raw_code')
            ->get()
            ->groupBy(fn (object $row) => $row->platform_id_snapshot.'|'.$row->code_snapshot)
            ->map(fn (Collection $rows) => $rows->pluck('raw_code')->sort(fn (string $a, string $b) => strcmp($a, $b))->values()->all());
    }

    /**
     * Usages without a master, whose platform + code also has no master: a registered code that was
     * never used does not appear here, and neither does a code that already has a master.
     */
    private function unregisteredUsages(): Builder
    {
        return DB::table('transaction_promo_codes as usages')
            ->whereIn('usages.transaction_id', Transaction::query()->promoAnalyzable()->select('id'))
            ->whereNull('usages.promo_code_id')
            ->whereNotExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('promo_codes')
                ->whereColumn('promo_codes.platform_id', 'usages.platform_id_snapshot')
                ->whereColumn('promo_codes.code', 'usages.code_snapshot'));
    }
}
