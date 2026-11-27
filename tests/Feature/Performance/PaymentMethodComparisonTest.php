<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX-10-BE-01: counts, Konversi, and Omzet per payment method for the active and the comparison period.
 */
class PaymentMethodComparisonTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/payment-methods';

    /** On 11 Nov 2026, last_7_days is active 04 - 10 Nov and comparison 28 Okt - 03 Nov. */
    private const NOW = '2026-11-11T14:30:00+07:00';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    public function test_ac01_qris_spellings_form_one_row_and_an_empty_method_has_its_own_row(): void
    {
        $this->coverage();
        $this->transaction('QRIS', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 100000);
        $this->transaction(' qris ', '2026-11-05 10:00:00', '2026-11-05 10:05:00', 50000);
        $this->transaction('QRIS', '2026-11-06 10:00:00', null, 0, MarkazTransactionStatus::Pending);
        $this->transaction(null, '2026-11-06 11:00:00', '2026-11-06 11:05:00', 70000);
        $this->transaction('   ', '2026-11-07 11:00:00', null, 0, MarkazTransactionStatus::Expired);
        $this->transaction('Bank  Transfer', '2026-11-07 12:00:00', '2026-11-07 12:05:00', 20000);

        $table = $this->table();
        $rows = $this->rowsByName($table);

        $this->assertSame(
            ['payment_method', 'total_transactions', 'success_purchases', 'pending_purchases', 'unsuccessful_purchases', 'conversion', 'revenue'],
            array_column($table['columns'], 'key'),
        );
        $this->assertSame(['QRIS', 'Bank Transfer', 'Belum Ada Metode Pembayaran'], array_column($table['rows'], 'name'));

        $this->assertSame('qris', $rows['QRIS']['key']);
        $this->assertSame(3, $rows['QRIS']['active']['total_transactions']);
        $this->assertSame(2, $rows['QRIS']['active']['success_purchases']);
        $this->assertSame(1, $rows['QRIS']['active']['pending_purchases']);
        $this->assertEquals(150000, $rows['QRIS']['active']['revenue']);

        $unassigned = $rows['Belum Ada Metode Pembayaran'];
        $this->assertNull($unassigned['key']);
        $this->assertTrue($unassigned['is_unassigned']);
        $this->assertSame(2, $unassigned['active']['total_transactions']);
        $this->assertSame(1, $unassigned['active']['unsuccessful_purchases']);
        $this->assertEquals(70000, $unassigned['active']['revenue']);
    }

    public function test_ac02_omzet_follows_the_paid_date_and_counts_follow_the_created_date(): void
    {
        $this->coverage();
        // Created in the comparison period, paid in the active period.
        $this->transaction('QRIS', '2026-11-03 22:00:00', '2026-11-05 08:00:00', 90000);
        $this->transaction('QRIS', '2026-11-06 09:00:00', '2026-11-06 09:05:00', 10000);

        $qris = $this->rowsByName($this->table())['QRIS'];

        $this->assertSame(1, $qris['active']['total_transactions']);
        $this->assertSame(1, $qris['active']['success_purchases']);
        $this->assertEquals(100000, $qris['active']['revenue']);
        $this->assertSame(2, $qris['active']['success_payments']);

        $this->assertSame(1, $qris['comparison']['total_transactions']);
        $this->assertSame(1, $qris['comparison']['success_purchases']);
        $this->assertEquals(0, $qris['comparison']['revenue']);
    }

    public function test_ac02_conversion_60_against_50_changes_by_10_percentage_points(): void
    {
        $this->coverage();

        for ($i = 0; $i < 6; $i++) {
            $this->transaction('QRIS', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 10000);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->transaction('QRIS', '2026-11-06 09:00:00', null, 0, MarkazTransactionStatus::Pending);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->transaction('QRIS', '2026-10-29 09:00:00', '2026-10-29 09:05:00', 10000);
            $this->transaction('QRIS', '2026-10-30 09:00:00', null, 0, MarkazTransactionStatus::Failed);
        }

        $qris = $this->rowsByName($this->table())['QRIS'];

        $this->assertSame(60.0, (float) $qris['active']['conversion']);
        $this->assertSame(50.0, (float) $qris['comparison']['conversion']);
        $this->assertSame(['value' => 10.0, 'unit' => 'percentage_point', 'trend' => 'up'], $this->normalized($qris['changes']['conversion']));

        // Counts change in percent; Pending and Tidak Berhasil show the direction of the count.
        $this->assertSame(['value' => 25.0, 'unit' => 'percent', 'trend' => 'up'], $this->normalized($qris['changes']['total_transactions']));
        $this->assertSame(['value' => null, 'unit' => 'percent', 'trend' => 'up'], $this->normalized($qris['changes']['pending_purchases']));
        $this->assertSame(['value' => -100.0, 'unit' => 'percent', 'trend' => 'down'], $this->normalized($qris['changes']['unsuccessful_purchases']));
        $this->assertSame(['value' => 50.0, 'unit' => 'percent', 'trend' => 'up'], $this->normalized($qris['changes']['revenue']));
        $this->assertSame(['value' => 50.0, 'unit' => 'percent', 'trend' => 'up'], $this->normalized($qris['changes']['success_purchases']));
    }

    public function test_ac03_purchase_statuses_claims_and_net_nominal_per_method(): void
    {
        $this->coverage();
        $buyer = $this->transaction('QRIS', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 180000);
        $this->transaction('QRIS', '2026-11-05 10:00:00', null, 0, MarkazTransactionStatus::Pending);
        $this->transaction('QRIS', '2026-11-05 11:00:00', '2026-11-05 11:00:00', 0, claim: true);
        // A group member of the package is never counted again.
        $this->transaction('QRIS', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 180000, refId: (string) $buyer->markaz_transaction_id);
        // Only a coupon claim: Konversi has no purchase attempt to divide by.
        $this->transaction('Coupon Activation', '2026-11-06 11:00:00', '2026-11-06 11:00:00', 0, claim: true);

        $rows = $this->rowsByName($this->table());
        $qris = $rows['QRIS']['active'];

        $this->assertSame(3, $qris['total_transactions']);
        $this->assertSame(1, $qris['success_purchases']);
        $this->assertSame(1, $qris['pending_purchases']);
        $this->assertSame(0, $qris['unsuccessful_purchases']);
        $this->assertSame(1, $qris['claim_checkouts']);
        $this->assertSame(2, $qris['purchase_attempts']);
        $this->assertSame(50.0, (float) $qris['conversion']);
        $this->assertEquals(180000, $qris['revenue']);

        $coupon = $rows['Coupon Activation']['active'];
        $this->assertSame(1, $coupon['total_transactions']);
        $this->assertSame(1, $coupon['claim_checkouts']);
        $this->assertSame(0, $coupon['purchase_attempts']);
        $this->assertNull($coupon['conversion']);
        $this->assertEquals(0, $coupon['revenue']);
    }

    public function test_unsuccessful_breakdown_adds_up_to_tidak_berhasil(): void
    {
        $this->coverage();
        $this->transaction('QRIS', '2026-11-05 09:00:00', null, 0, MarkazTransactionStatus::Expired);
        $this->transaction('QRIS', '2026-11-05 10:00:00', null, 0, MarkazTransactionStatus::Expired);
        $this->transaction('QRIS', '2026-11-05 11:00:00', null, 0, MarkazTransactionStatus::Canceled);
        $this->transaction('QRIS', '2026-11-05 12:00:00', null, 0, MarkazTransactionStatus::Failed);

        $qris = $this->rowsByName($this->table())['QRIS']['active'];

        $this->assertSame(['expired' => 2, 'canceled' => 1, 'failed' => 1], $qris['unsuccessful_breakdown']);
        $this->assertSame(4, $qris['unsuccessful_purchases']);
        $this->assertSame(array_sum($qris['unsuccessful_breakdown']), $qris['unsuccessful_purchases']);
    }

    public function test_purchases_without_a_status_make_konversi_provisional_and_stay_in_its_divisor(): void
    {
        $this->coverage();
        $this->transaction('QRIS', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 10000);
        $this->transaction('QRIS', '2026-11-05 10:00:00', null, 0, null);

        $qris = $this->rowsByName($this->table())['QRIS']['active'];

        $this->assertSame(50.0, (float) $qris['conversion']);
        $this->assertSame(1, $qris['unknown_status_count']);
        $this->assertTrue($qris['conversion_is_provisional']);
        $this->assertSame('Sementara', $qris['conversion_label']);
        $this->assertSame(2, $qris['total_transactions']);
    }

    public function test_a_missing_comparison_keeps_the_active_values_and_explains_why(): void
    {
        $this->coverage(comparison: false);
        $this->transaction('QRIS', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 10000);

        $table = $this->table();
        $qris = $this->rowsByName($table)['QRIS'];

        $this->assertFalse($table['is_empty']);
        $this->assertSame('unavailable', $table['comparison']['availability']);
        $this->assertFalse($table['comparison']['is_available']);
        $this->assertSame('Data periode pembanding belum tersedia.', $table['comparison']['message']);
        $this->assertSame(1, $qris['active']['total_transactions']);
        $this->assertNull($qris['comparison']);
        $this->assertSame(['value' => null, 'unit' => 'percent', 'trend' => null], $qris['changes']['total_transactions']);
    }

    public function test_a_method_absent_from_the_comparison_compares_with_zero_not_with_nothing(): void
    {
        $this->coverage();
        $this->transaction('QRIS', '2026-11-05 09:00:00', '2026-11-05 09:05:00', 10000);

        $qris = $this->rowsByName($this->table())['QRIS'];

        $this->assertSame(0, $qris['comparison']['total_transactions']);
        $this->assertNull($qris['comparison']['conversion']);
        $this->assertSame(['value' => null, 'unit' => 'percent', 'trend' => 'up'], $qris['changes']['total_transactions']);
        $this->assertSame(['value' => null, 'unit' => 'percentage_point', 'trend' => null], $qris['changes']['conversion']);
    }

    public function test_a_trimmed_change_uses_only_the_matched_days_and_keeps_the_full_active_value(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31T10:00:00+07:00'));
        $this->snapshot('2026-03-01', '2026-03-31', '2026-03-31 09:00:00');
        $this->snapshot('2026-02-01', '2026-02-28', '2026-03-01 00:00:00');

        $this->transaction('QRIS', '2026-03-10 08:00:00', '2026-03-10 08:05:00', 100000);
        // 29 - 31 Mar have no equivalent date in February.
        $this->transaction('QRIS', '2026-03-30 08:00:00', '2026-03-30 08:05:00', 50000);
        $this->transaction('QRIS', '2026-02-10 08:00:00', '2026-02-10 08:05:00', 80000);

        $table = $this->table('this_month');
        $qris = $this->rowsByName($table)['QRIS'];

        $this->assertTrue($table['change_basis']['is_trimmed']);
        $this->assertSame('partial', $table['active']['availability']);
        $this->assertEquals(150000, $qris['active']['revenue']);
        $this->assertEquals(80000, $qris['comparison']['revenue']);
        $this->assertSame(25.0, (float) $qris['changes']['revenue']['value']);
        $this->assertSame(0.0, (float) $qris['changes']['total_transactions']['value']);
    }

    public function test_an_equivalent_hour_comparison_is_never_rebuilt_from_the_latest_state(): void
    {
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 14:00:00');
        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 14:00:00');
        $this->transaction('QRIS', '2026-11-11 09:00:00', '2026-11-11 09:10:00', 150000);
        $this->transaction('QRIS', '2026-11-10 09:00:00', '2026-11-10 09:10:00', 100000);

        $table = $this->table('today');
        $qris = $this->rowsByName($table)['QRIS'];

        $this->assertSame('snapshot', $table['comparison']['source']);
        $this->assertFalse($table['comparison']['is_available']);
        $this->assertSame('Pembanding jam setara per metode pembayaran belum tercatat.', $table['comparison']['message']);
        $this->assertEquals(150000, $qris['active']['revenue']);
        $this->assertNull($qris['comparison']);
        $this->assertNull($qris['changes']['revenue']['value']);
    }

    public function test_an_empty_filter_result_returns_the_empty_table_state(): void
    {
        $this->coverage();
        $this->transaction('QRIS', '2026-10-29 09:00:00', '2026-10-29 09:05:00', 10000);

        $table = $this->table();

        $this->assertTrue($table['is_empty']);
        $this->assertSame('Tidak ada data metode pembayaran untuk filter yang dipilih.', $table['message']);
        $this->assertSame([], $table['rows']);
    }

    public function test_the_table_is_only_built_for_the_shared_period_resolver(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-11-04&date_to=2026-11-10')
            ->assertOk()
            ->assertJsonPath('data.method_comparison', null)
            ->assertJsonStructure(['data' => ['payment_methods', 'totals']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function table(string $preset = 'last_7_days'): array
    {
        return $this->actingAs($this->manager)
            ->getJson(self::URL.'?platforms[]=app-a&preset='.$preset)
            ->assertOk()
            ->json('data.method_comparison');
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array<string, array<string, mixed>>
     */
    private function rowsByName(array $table): array
    {
        return array_column($table['rows'], null, 'name');
    }

    /**
     * @param  array{value: int|float|null, unit: string, trend: string|null}  $change
     * @return array{value: float|null, unit: string, trend: string|null}
     */
    private function normalized(array $change): array
    {
        return [...$change, 'value' => $change['value'] === null ? null : (float) $change['value']];
    }

    private function coverage(bool $comparison = true): void
    {
        $this->snapshot('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');

        if ($comparison) {
            $this->snapshot('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
        }
    }

    private function snapshot(string $from, string $to, string $cutoffAt): void
    {
        DashboardSnapshot::factory()->for($this->platform)->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'payload' => [],
            'recorded_at' => $cutoffAt,
        ]);
    }

    private function transaction(
        ?string $method,
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->forPlatform($this->platform)->create([
            'payment_method' => $method,
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $netNominal + 2000,
            'admin_fee' => 2000,
            'net_nominal' => $netNominal,
        ]);
    }
}
