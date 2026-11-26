<?php

namespace Tests\Unit;

use App\Enums\MarkazTransactionStatus;
use App\Services\TransactionAggregationService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * FIX-12-BE-01: Success payments and revenue per WIB payment hour on the shared transaction rules.
 */
class PaymentsByHourTest extends TestCase
{
    private TransactionAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TransactionAggregationService;
    }

    public function test_ac01_a_payment_at_19_20_lands_in_19_00_19_59_even_when_created_at_08_00(): void
    {
        $slots = $this->slots([$this->payment('TRX-1', '2026-11-06', '19:20:00', 150000, ['markaz_created_at' => '2026-11-06T08:00:00+07:00'])]);

        $this->assertSame(1, $slots[19]['success_payments']);
        $this->assertSame(150000.0, $slots[19]['revenue']);
        $this->assertSame(0, $slots[8]['success_payments']);
        $this->assertSame(1, array_sum(array_column($slots, 'success_payments')));
        $this->assertSame(range(0, 23), array_column($slots, 'hour'));
    }

    public function test_payments_around_wib_midnight_land_on_their_own_day_and_hour(): void
    {
        $rows = [
            $this->payment('LATE', '2026-11-06', '23:59:59', 100000),
            $this->payment('MIDNIGHT', '2026-11-07', '00:00:00', 200000),
            // Created before midnight, paid after it: the payment day and hour count.
            $this->payment('CROSS-DAY', '2026-11-07', '00:10:00', 300000, ['markaz_created_at' => '2026-11-06T23:50:00+07:00']),
        ];

        $friday = $this->slots($rows, ['2026-11-06'], '2026-11-08 00:00:00');
        $both = $this->slots($rows, ['2026-11-06', '2026-11-07'], '2026-11-08 00:00:00');

        $this->assertSame(1, $friday[23]['success_payments']);
        $this->assertSame(0, $friday[0]['success_payments']);
        $this->assertSame(1, $both[23]['success_payments']);
        $this->assertSame(2, $both[0]['success_payments']);
        $this->assertSame(500000.0, $both[0]['revenue']);
    }

    public function test_ac03_one_group_buyer_row_counts_with_its_net_nominal_and_claims_failed_and_payments_without_a_time_do_not(): void
    {
        $group = $this->payment('GRP-1', '2026-11-06', '19:20:00', 300000, ['nominal' => 305000]);
        $others = [
            $this->payment('MEMBER-1', '2026-11-06', '19:20:00', 0, ['markaz_ref_id' => 'GRP-1']),
            $this->payment('MEMBER-2', '2026-11-06', '19:20:00', 0, ['markaz_ref_id' => 'GRP-1']),
            $this->payment('CLAIM-1', '2026-11-06', '19:25:00', 0, ['is_claim_coupon' => true]),
            $this->payment('FAILED-1', '2026-11-06', '19:30:00', 90000, ['source_status' => MarkazTransactionStatus::Failed->value]),
            $this->payment('NO-DATE', null, null, 80000),
            $this->payment('NO-TIME', '2026-11-06', null, 70000),
        ];

        $slots = $this->slots([$group, ...$others]);

        $this->assertSame(1, $slots[19]['success_payments']);
        $this->assertSame(300000.0, $slots[19]['revenue']);
        $this->assertSame(0, $slots[0]['success_payments'], 'A payment without a paid time is not put at 00.00.');
        $this->assertSame(1, array_sum(array_column($slots, 'success_payments')));

        // The paid time moves to 20.20: the old slot is empty and the new slot has the payment.
        $moved = $this->slots([array_merge($group, ['paid_time' => '20:20:00']), ...$others]);
        $this->assertSame(0, $moved[19]['success_payments']);
        $this->assertSame(1, $moved[20]['success_payments']);
        $this->assertSame(300000.0, $moved[20]['revenue']);

        // The payment becomes Failed: it leaves the new slot.
        $failed = $this->slots([array_merge($group, ['paid_time' => '20:20:00', 'effective_status' => MarkazTransactionStatus::Failed->value]), ...$others]);
        $this->assertSame(0, $failed[20]['success_payments']);
        $this->assertSame(0, array_sum(array_column($failed, 'success_payments')));
    }

    public function test_an_invalid_paid_time_has_no_hour(): void
    {
        $slots = $this->slots([$this->payment('BAD-TIME', '2026-11-06', '25:10:00', 100000)]);

        $this->assertSame(0, array_sum(array_column($slots, 'success_payments')));
    }

    public function test_ac04_hours_after_the_cutoff_are_unavailable_while_complete_hours_without_payments_are_zero(): void
    {
        $rows = [
            $this->payment('MORNING', '2026-11-11', '09:15:00', 100000),
            $this->payment('AFTER-CUTOFF', '2026-11-11', '15:00:00', 100000),
        ];

        $slots = $this->slots($rows, ['2026-11-11'], '2026-11-11 14:00:00');

        $this->assertSame('complete', $slots[11]['availability']);
        $this->assertSame(0, $slots[11]['success_payments']);
        $this->assertSame(0.0, $slots[11]['revenue']);
        $this->assertSame(1, $slots[9]['success_payments']);
        $this->assertSame('2026-11-11T14:00:00+07:00', $slots[13]['as_of']);

        foreach (range(14, 23) as $hour) {
            $this->assertSame('unavailable', $slots[$hour]['availability']);
            $this->assertNull($slots[$hour]['success_payments']);
            $this->assertNull($slots[$hour]['revenue']);
            $this->assertNull($slots[$hour]['as_of']);
        }
    }

    public function test_a_cutoff_within_an_hour_makes_that_slot_partial_and_counts_only_the_payments_before_it(): void
    {
        $rows = [
            $this->payment('BEFORE', '2026-11-11', '14:10:00', 100000),
            $this->payment('AFTER', '2026-11-11', '14:40:00', 100000),
        ];

        $slots = $this->slots($rows, ['2026-11-11'], '2026-11-11 14:30:00');

        $this->assertSame('partial', $slots[14]['availability']);
        $this->assertSame(1, $slots[14]['success_payments']);
        $this->assertSame('2026-11-11T14:30:00+07:00', $slots[14]['as_of']);
    }

    public function test_over_several_days_an_hour_covered_on_only_some_days_is_partial(): void
    {
        $rows = [
            $this->payment('DAY-1', '2026-11-10', '15:20:00', 100000),
            $this->payment('DAY-2', '2026-11-11', '13:20:00', 100000),
        ];

        $slots = $this->slots($rows, ['2026-11-10', '2026-11-11'], '2026-11-11 14:00:00');

        $this->assertSame('complete', $slots[13]['availability']);
        $this->assertSame('partial', $slots[15]['availability']);
        $this->assertSame(1, $slots[15]['success_payments']);
    }

    public function test_unknown_coverage_or_no_days_gives_only_unavailable_slots(): void
    {
        $rows = [$this->payment('TRX-1', '2026-11-06', '19:20:00', 100000)];

        foreach ([$this->slots($rows, ['2026-11-06'], null), $this->slots($rows, [], '2026-11-08 00:00:00')] as $slots) {
            $this->assertSame(['unavailable'], array_values(array_unique(array_column($slots, 'availability'))));
            $this->assertSame([null], array_values(array_unique(array_column($slots, 'success_payments'), SORT_REGULAR)));
        }
    }

    public function test_other_platforms_are_left_out(): void
    {
        $slots = $this->slots([$this->payment('OTHER', '2026-11-06', '19:20:00', 100000, ['platform_id' => 2])]);

        $this->assertSame(0, $slots[19]['success_payments']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $days
     * @return list<array<string, mixed>>
     */
    private function slots(array $rows, array $days = ['2026-11-06'], ?string $completeUntil = '2026-11-08 00:00:00'): array
    {
        return $this->service->paymentsByHour(
            $rows,
            [1],
            array_map(fn (string $day) => CarbonImmutable::parse($day, 'Asia/Jakarta'), $days),
            $completeUntil === null ? null : CarbonImmutable::parse($completeUntil, 'Asia/Jakarta'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payment(string $id, ?string $paidDate, ?string $paidTime, float $netNominal, array $overrides = []): array
    {
        return array_merge([
            'platform_id' => 1,
            'markaz_transaction_id' => $id,
            'markaz_ref_id' => null,
            'markaz_customer_id' => 501,
            'markaz_created_at' => '2026-11-06T08:00:00+07:00',
            'paid_date' => $paidDate,
            'paid_time' => $paidTime,
            'source_status' => MarkazTransactionStatus::Success->value,
            'effective_status' => null,
            'is_claim_coupon' => false,
            'nominal' => $netNominal,
            'net_nominal' => $netNominal,
        ], $overrides);
    }
}
