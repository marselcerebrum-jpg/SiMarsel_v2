<?php

namespace App\Support\TransactionAggregation;

use App\Enums\MarkazTransactionStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One stored transaction read through the transaction dates contract (FIX-02-BE-01).
 * The record only reads stored values: the latest source state and the last valid payment date
 * are written by the Markaz data sync, and synced_at is never used as an event time.
 */
final class TransactionRecord
{
    public const TIMEZONE = 'Asia/Jakarta';

    private function __construct(
        public readonly int $platformId,
        public readonly string $markazTransactionId,
        public readonly ?string $markazRefId,
        public readonly ?int $markazCustomerId,
        public readonly CarbonImmutable $markazCreatedAt,
        public readonly ?CarbonImmutable $paidAt,
        public readonly ?MarkazTransactionStatus $reportStatus,
        public readonly bool $isClaimCoupon,
        public readonly float $nominal,
        public readonly float $netNominal,
        public readonly bool $hasPaidTime = false,
    ) {}

    /**
     * @param  array<string, mixed>  $row  A row shaped like the contract transaction fields.
     */
    public static function fromArray(array $row): self
    {
        $paidDate = self::dateString($row['paid_date'] ?? null);
        $paidTime = self::timeString($row['paid_time'] ?? null);

        return new self(
            platformId: (int) $row['platform_id'],
            markazTransactionId: (string) $row['markaz_transaction_id'],
            markazRefId: self::blankToNull($row['markaz_ref_id'] ?? null),
            markazCustomerId: self::nullableInteger($row['markaz_customer_id'] ?? null),
            markazCreatedAt: self::wib($row['markaz_created_at']),
            paidAt: $paidDate === null ? null : self::wib($paidDate.' '.($paidTime ?? '00:00:00')),
            reportStatus: MarkazTransactionStatus::forReport(
                self::statusCode($row['effective_status'] ?? null),
                self::statusCode($row['source_status'] ?? null),
            ),
            isClaimCoupon: (bool) ($row['is_claim_coupon'] ?? false),
            nominal: (float) $row['nominal'],
            netNominal: (float) $row['net_nominal'],
            hasPaidTime: $paidDate !== null && $paidTime !== null,
        );
    }

    /**
     * A group member row points at its buyer with markaz_ref_id; only buyer rows feed aggregations.
     */
    public function isGroupMember(): bool
    {
        return $this->markazRefId !== null;
    }

    public function isSuccess(): bool
    {
        return $this->reportStatus === MarkazTransactionStatus::Success;
    }

    /**
     * The moment used by the paid group: the last valid payment date of a Success, otherwise none.
     */
    public function paymentAt(): ?CarbonImmutable
    {
        return $this->isSuccess() ? $this->paidAt : null;
    }

    /**
     * The payment moment of a Success whose paid date and paid time are both valid, for reports by hour of the day.
     * A payment with only a valid paid date still counts by date (paymentAt) but has no hour.
     */
    public function paymentHourAt(): ?CarbonImmutable
    {
        return $this->hasPaidTime ? $this->paymentAt() : null;
    }

    public function createdDate(): string
    {
        return $this->markazCreatedAt->toDateString();
    }

    public function paymentDate(): ?string
    {
        return $this->paymentAt()?->toDateString();
    }

    private static function wib(DateTimeInterface|string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::TIMEZONE)->setTimezone(self::TIMEZONE);
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return self::blankToNull($value);
    }

    /**
     * A paid time of the form HH:MM or HH:MM:SS within one day; anything else is not a valid time.
     */
    private static function timeString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $value = self::blankToNull($value);

        if ($value === null || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            return null;
        }

        return strlen($value) === 5 ? $value.':00' : $value;
    }

    /**
     * An empty or missing status field means unknown, while 0 is Pending.
     */
    private static function statusCode(mixed $value): ?int
    {
        $value = self::blankToNull($value);

        return $value === null ? null : (int) $value;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        $value = self::blankToNull($value);

        return $value === null ? null : (int) $value;
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
