<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\MarkazTransactionStatus;
use App\Enums\TransactionDateBasis;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;
use App\Support\TransactionAggregation\TransactionRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriodImmutable;
use InvalidArgumentException;

/**
 * Shared transaction aggregations for Analisis Performa, on the transaction dates contract.
 * The created group (markaz_created_at) gives Total, Konversi, and the status breakdown;
 * the paid group (last valid paid_date + paid_time of a Success) gives the payment metrics.
 */
class TransactionAggregationService
{
    /**
     * Buyer rows only: group members never add checkouts, buyers, or revenue.
     *
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @return list<TransactionRecord>
     */
    public function buyerRows(iterable $rows): array
    {
        return array_values(array_filter(
            $this->records($rows),
            fn (TransactionRecord $record) => ! $record->isGroupMember(),
        ));
    }

    /**
     * Group members of a buyer: markaz_ref_id equals the buyer's markaz_transaction_id on the same platform.
     *
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @return list<TransactionRecord>
     */
    public function membersOf(TransactionRecord $buyer, iterable $rows): array
    {
        return array_values(array_filter(
            $this->records($rows),
            fn (TransactionRecord $record) => $record->platformId === $buyer->platformId
                && $record->markazRefId === $buyer->markazTransactionId,
        ));
    }

    /**
     * The buyer rows inside the filter's platforms, date basis, and data cutoff, for a component that groups them
     * itself (for example by status or product type). Null when coverage is not known, so it is never read as 0.
     *
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @return list<TransactionRecord>|null
     */
    public function scopedRecords(iterable $rows, AggregationFilter $filter): ?array
    {
        if ($filter->availability() === DataAvailability::Unavailable) {
            return null;
        }

        return $this->inScope($this->buyerRows($rows), $filter);
    }

    /**
     * Aggregates the filter's whole range. Unknown coverage returns null metrics, never 0.
     *
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function aggregate(iterable $rows, AggregationFilter $filter): array
    {
        $availability = $filter->availability();
        $records = $availability === DataAvailability::Unavailable ? [] : $this->inScope($this->buyerRows($rows), $filter);

        $metrics = match ($filter->basis) {
            TransactionDateBasis::Created => $this->createdMetrics($records),
            TransactionDateBasis::Paid => $this->paidMetrics($records),
        };

        if ($availability === DataAvailability::Unavailable) {
            array_walk_recursive($metrics, function (&$value) {
                $value = null;
            });
        }

        return [
            'basis' => $filter->basis->value,
            'platform_ids' => $filter->platformIds,
            'date_from' => $filter->dateFrom->toDateString(),
            'date_to' => $filter->dateTo->toDateString(),
            'availability' => $availability->value,
            'as_of' => $filter->cutoff()?->toIso8601String(),
            'metrics' => $metrics,
        ];
    }

    /**
     * The metrics of several non-overlapping windows on the same date basis, counted as one scope, so a
     * buyer paying in two windows stays one Pembeli Unik. Null when any window is unavailable.
     *
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @param  non-empty-list<AggregationFilter>  $filters
     * @return array<string, mixed>|null
     */
    public function aggregateWindows(iterable $rows, array $filters): ?array
    {
        $buyers = $this->buyerRows($rows);
        $records = [];

        foreach ($filters as $filter) {
            if ($filter->basis !== $filters[0]->basis) {
                throw new InvalidArgumentException('Every window of one aggregation needs the same date basis.');
            }

            if ($filter->availability() === DataAvailability::Unavailable) {
                return null;
            }

            array_push($records, ...$this->inScope($buyers, $filter));
        }

        return match ($filters[0]->basis) {
            TransactionDateBasis::Created => $this->createdMetrics($records),
            TransactionDateBasis::Paid => $this->paidMetrics($records),
        };
    }

    /**
     * One aggregate per day of the range, each with its own availability.
     *
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function aggregateDaily(iterable $rows, AggregationFilter $filter): array
    {
        $records = $this->records($rows);
        $days = [];

        foreach (CarbonPeriodImmutable::create($filter->dateFrom, $filter->dateTo) as $day) {
            $days[] = $this->aggregate($records, $filter->forDay($day));
        }

        return $days;
    }

    /**
     * Success payments and revenue per WIB hour of the payment (slot 0 = 00.00-00.59 ... 23 = 23.00-23.59) over the
     * given days, on the paid group rules: buyer rows only, claims excluded, revenue from net_nominal. Only payments
     * with a valid paid date and paid time have an hour; a payment with only a paid date is left out of every slot.
     *
     * Each slot covers the same hour on every given day. It is complete when that hour is covered on every day,
     * unavailable (null values, never 0) when it is covered on none, and partial otherwise; a partial slot counts
     * only the payments before the complete-data cutoff.
     *
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @param  list<int>  $platformIds
     * @param  list<CarbonImmutable>  $days  The WIB days counted, e.g. only the Fridays of the period.
     * @param  CarbonImmutable|null  $completeUntil  The exclusive end of complete data; null when unknown.
     * @return list<array{hour: int, availability: string, as_of: string|null, success_payments: int|null, revenue: float|null}>
     */
    public function paymentsByHour(iterable $rows, array $platformIds, array $days, ?CarbonImmutable $completeUntil): array
    {
        $days = array_map(fn (CarbonImmutable $day) => $day->setTimezone(TransactionRecord::TIMEZONE)->startOfDay(), $days);
        $dayKeys = array_flip(array_map(fn (CarbonImmutable $day) => $day->toDateString(), $days));
        $counts = array_fill(0, 24, 0);
        $revenue = array_fill(0, 24, 0.0);

        foreach ($this->buyerRows($rows) as $record) {
            $paidAt = $record->paymentHourAt();

            if ($paidAt === null
                || $record->isClaimCoupon
                || ! in_array($record->platformId, $platformIds, true)
                || ! isset($dayKeys[$paidAt->toDateString()])
                || $completeUntil === null
                || $paidAt->gte($completeUntil)) {
                continue;
            }

            $counts[$paidAt->hour]++;
            $revenue[$paidAt->hour] += $record->netNominal;
        }

        $slots = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $availability = $this->hourAvailability($days, $hour, $completeUntil);
            $known = $availability !== DataAvailability::Unavailable;

            $slots[] = [
                'hour' => $hour,
                'availability' => $availability->value,
                'as_of' => $known ? $this->hourAsOf($days, $hour, $completeUntil)->toIso8601String() : null,
                'success_payments' => $known ? $counts[$hour] : null,
                'revenue' => $known ? round($revenue[$hour], 2) : null,
            ];
        }

        return $slots;
    }

    /**
     * @param  list<CarbonImmutable>  $days
     */
    private function hourAvailability(array $days, int $hour, ?CarbonImmutable $completeUntil): DataAvailability
    {
        $states = array_unique(array_map(
            fn (CarbonImmutable $day) => DataAvailability::forCoverage(
                $completeUntil,
                $day->setTime($hour, 0),
                $day->setTime($hour, 0)->addHour(),
            )->value,
            $days,
        ));

        return match (true) {
            $states === [] || $states === [DataAvailability::Unavailable->value] => DataAvailability::Unavailable,
            $states === [DataAvailability::Complete->value] => DataAvailability::Complete,
            default => DataAvailability::Partial,
        };
    }

    /**
     * The exclusive end of the counted data of a slot: the end of its hour on the last day, or the cutoff before it.
     *
     * @param  non-empty-list<CarbonImmutable>  $days
     */
    private function hourAsOf(array $days, int $hour, CarbonImmutable $completeUntil): CarbonImmutable
    {
        $lastEnd = max(array_map(fn (CarbonImmutable $day) => $day->setTime($hour, 0)->addHour(), $days));

        return $lastEnd->min($completeUntil);
    }

    /**
     * @param  list<TransactionRecord>  $records
     * @return list<TransactionRecord>
     */
    private function inScope(array $records, AggregationFilter $filter): array
    {
        $start = $filter->periodStart();
        $cutoff = $filter->cutoff();

        return array_values(array_filter($records, function (TransactionRecord $record) use ($filter, $start, $cutoff) {
            if (! in_array($record->platformId, $filter->platformIds, true)) {
                return false;
            }

            $at = $filter->basis === TransactionDateBasis::Created ? $record->markazCreatedAt : $record->paymentAt();

            return $at !== null && $at->gte($start) && $at->lt($cutoff);
        }));
    }

    /**
     * Total = purchases of every status (unknown included) + coupon claims.
     * Konversi = Success purchases ÷ all purchases; claims are outside both sides.
     *
     * @param  list<TransactionRecord>  $records
     * @return array<string, mixed>
     */
    private function createdMetrics(array $records): array
    {
        $breakdown = [];
        foreach (MarkazTransactionStatus::cases() as $status) {
            $breakdown[$this->statusKey($status)] = ['count' => 0, 'nominal' => 0.0];
        }

        $claims = $purchases = $unknown = 0;
        $unknownNominal = 0.0;

        foreach ($records as $record) {
            if ($record->isClaimCoupon) {
                $claims++;

                continue;
            }

            $purchases++;

            if ($record->reportStatus === null) {
                $unknown++;
                $unknownNominal += $record->nominal;

                continue;
            }

            $breakdown[$this->statusKey($record->reportStatus)]['count']++;
            $breakdown[$this->statusKey($record->reportStatus)]['nominal'] += $record->nominal;
        }

        $success = $breakdown['success']['count'];

        return [
            'total_checkouts' => $purchases + $claims,
            'purchase_attempts' => $purchases,
            'claim_checkouts' => $claims,
            'success_purchases' => $success,
            'unsuccessful_purchases' => $breakdown['expired']['count'] + $breakdown['failed']['count'] + $breakdown['canceled']['count'],
            'unknown_status_count' => $unknown,
            'unknown_status_nominal' => $unknownNominal,
            'conversion_percentage' => Ratio::percentage($success, $purchases),
            'is_provisional' => $unknown > 0,
            'status_breakdown' => $breakdown,
        ];
    }

    /**
     * Revenue and AOV use net_nominal; nominal_total is the billed amount. Claims are not payments.
     * Pembeli Unik deduplicates (platform_id, markaz_customer_id) over the whole range.
     *
     * @param  list<TransactionRecord>  $records
     * @return array<string, mixed>
     */
    private function paidMetrics(array $records): array
    {
        $payments = array_filter($records, fn (TransactionRecord $record) => ! $record->isClaimCoupon);
        $revenue = (float) array_sum(array_map(fn (TransactionRecord $record) => $record->netNominal, $payments));
        $buyers = array_unique(array_map(
            fn (TransactionRecord $record) => $record->platformId.':'.$record->markazCustomerId,
            array_filter($payments, fn (TransactionRecord $record) => $record->markazCustomerId !== null),
        ));

        return [
            'success_payments' => count($payments),
            'revenue' => $revenue,
            'nominal_total' => (float) array_sum(array_map(fn (TransactionRecord $record) => $record->nominal, $payments)),
            'average_order_value' => Ratio::average($revenue, count($payments)),
            'unique_buyers' => count($buyers),
        ];
    }

    private function statusKey(MarkazTransactionStatus $status): string
    {
        return strtolower($status->name);
    }

    /**
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @return list<TransactionRecord>
     */
    private function records(iterable $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $records[] = $row instanceof TransactionRecord ? $row : TransactionRecord::fromArray($row);
        }

        return $records;
    }
}
