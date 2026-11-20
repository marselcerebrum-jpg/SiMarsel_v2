<?php

namespace App\Support\TransactionAggregation;

use App\Enums\DataAvailability;
use App\Enums\TransactionDateBasis;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Platforms, an inclusive WIB date range, the date basis, and the coverage boundary given by the caller.
 * completeUntil is the exclusive end of complete data for every selected platform (from the FIX-04
 * coverage resolver); null means coverage is not known yet.
 */
final class AggregationFilter
{
    public readonly CarbonImmutable $dateFrom;

    public readonly CarbonImmutable $dateTo;

    public readonly ?CarbonImmutable $completeUntil;

    /**
     * @param  list<int>  $platformIds  The selected platforms; must not be empty.
     */
    public function __construct(
        public readonly array $platformIds,
        CarbonImmutable|string $dateFrom,
        CarbonImmutable|string $dateTo,
        public readonly TransactionDateBasis $basis,
        CarbonImmutable|string|null $completeUntil,
    ) {
        if ($platformIds === []) {
            throw new InvalidArgumentException('An aggregation needs at least one platform.');
        }

        $this->dateFrom = self::wib($dateFrom)->startOfDay();
        $this->dateTo = self::wib($dateTo)->startOfDay();
        $this->completeUntil = $completeUntil === null ? null : self::wib($completeUntil);

        if ($this->dateTo->lt($this->dateFrom)) {
            throw new InvalidArgumentException('The aggregation date range ends before it starts.');
        }
    }

    public function periodStart(): CarbonImmutable
    {
        return $this->dateFrom;
    }

    /**
     * The exclusive end of the period: 00.00 WIB after date_to.
     */
    public function periodEnd(): CarbonImmutable
    {
        return $this->dateTo->addDay();
    }

    public function availability(): DataAvailability
    {
        return DataAvailability::forCoverage($this->completeUntil, $this->periodStart(), $this->periodEnd());
    }

    /**
     * The exclusive upper bound of counted events, or null when nothing is known.
     */
    public function cutoff(): ?CarbonImmutable
    {
        return match ($this->availability()) {
            DataAvailability::Unavailable => null,
            DataAvailability::Complete => $this->periodEnd(),
            DataAvailability::Partial => $this->completeUntil,
        };
    }

    public function forDay(CarbonImmutable $day): self
    {
        return new self($this->platformIds, $day, $day, $this->basis, $this->completeUntil);
    }

    private static function wib(CarbonImmutable|string $value): CarbonImmutable
    {
        $value = is_string($value) ? CarbonImmutable::parse($value, TransactionRecord::TIMEZONE) : $value;

        return $value->setTimezone(TransactionRecord::TIMEZONE);
    }
}
