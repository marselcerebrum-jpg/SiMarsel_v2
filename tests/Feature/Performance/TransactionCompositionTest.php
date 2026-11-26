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
 * FIX-09-BE-01: the Status Transaksi dataset (created group, claims included) and the Perdana vs Perpanjangan
 * dataset (paid group for payments and Omzet, created group for Konversi, claims and group members excluded).
 */
class TransactionCompositionTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS_URL = '/api/performance/transaction-statuses';

    private const TYPE_URL = '/api/performance/product-types';

    /** last_7_days on 11 Nov 2026: active 04 - 10 Nov, comparison 28 Okt - 03 Nov. */
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

    public function test_ac01_five_statuses_in_order_with_count_share_nominal_and_the_unsuccessful_subtotal(): void
    {
        $this->lastSevenDaysCoverage();

        foreach ([
            [MarkazTransactionStatus::Success, 70],
            [MarkazTransactionStatus::Pending, 10],
            [MarkazTransactionStatus::Expired, 10],
            [MarkazTransactionStatus::Canceled, 6],
            [MarkazTransactionStatus::Failed, 4],
        ] as [$status, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $paid = $status === MarkazTransactionStatus::Success ? '2026-11-05 09:10:00' : null;
                $this->transaction('2026-11-05 09:00:00', $paid, 100000, $status, nominal: 102000);
            }
        }

        $data = $this->statuses();

        $this->assertSame(['success', 'pending', 'expired', 'canceled', 'failed'], array_column($data['statuses'], 'key'));
        $this->assertSame(['Success', 'Pending', 'Expired', 'Canceled', 'Failed'], array_column($data['statuses'], 'label'));
        $this->assertSame([70, 10, 10, 6, 4], array_column(array_column($data['statuses'], 'active'), 'count'));
        $this->assertEquals([70.0, 10.0, 10.0, 6.0, 4.0], array_column(array_column($data['statuses'], 'active'), 'share_percentage'));
        $this->assertEquals(612000.0, $data['statuses'][3]['active']['nominal']);
        $this->assertSame('Total Nominal Transaksi', $data['nominal_label']);
        $this->assertSame('created', $data['date_basis']);

        $this->assertSame('Tidak Berhasil', $data['unsuccessful']['label']);
        $this->assertSame(20, $data['unsuccessful']['active']['count']);
        $this->assertEquals(20.0, $data['unsuccessful']['active']['share_percentage']);
        $this->assertEquals(2040000.0, $data['unsuccessful']['active']['nominal']);

        $this->assertSame(100, $data['active']['totals']['total_checkouts']);
        $this->assertSame('complete', $data['active']['availability']);
        $this->assertSame(0, $data['unknown_status']['active']['count']);
        $this->assertFalse($data['is_empty']);
    }

    public function test_ac02_type_payments_and_revenue_follow_the_paid_date_and_conversion_the_created_date(): void
    {
        $this->lastSevenDaysCoverage();

        // Perdana: 4 payments of Rp2 juta (Rp8 juta); one with a promo, one created before the active period.
        for ($i = 0; $i < 3; $i++) {
            $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 2000000, type: 'Perdana', promo: $i === 0 ? ['HEMAT10'] : null);
        }
        $this->transaction('2026-11-03 22:00:00', '2026-11-04 08:00:00', 2000000, type: 'Perdana');

        // Perpanjangan: 1 payment of Rp12 juta and one Pending attempt.
        $this->transaction('2026-11-06 09:00:00', '2026-11-06 09:10:00', 12000000, type: 'Perpanjangan');
        $this->transaction('2026-11-06 10:00:00', null, 12000000, MarkazTransactionStatus::Pending, type: 'Perpanjangan');

        // An empty type without a promo, created in the active period and paid on the next day.
        $this->transaction('2026-11-07 23:30:00', '2026-11-08 00:30:00', 0, type: '  ');
        // Another stored type keeps its own group.
        $this->transaction('2026-11-07 10:00:00', null, 0, MarkazTransactionStatus::Expired, type: 'Bundling');

        $payments = $this->types();
        $types = array_column($payments['product_types'], null, 'name');

        $this->assertSame('success_payments', $payments['metric']);
        $this->assertSame(['Perdana', 'Perpanjangan', 'Bundling', 'Tidak diketahui'], array_column($payments['product_types'], 'name'));
        $this->assertSame(['first_purchase', 'renewal', 'other', 'unknown'], array_column($payments['product_types'], 'key'));
        $this->assertSame(4, $types['Perdana']['active']['value']);
        $this->assertSame(1, $types['Perpanjangan']['active']['value']);
        $this->assertSame(1, $types['Tidak diketahui']['active']['value']);
        $this->assertSame(0, $types['Bundling']['active']['value']);
        $this->assertSame(6, $payments['active']['totals']['value']);

        // Konversi is on the created date: the Perdana paid on 04 Nov but created on 03 Nov is outside it.
        $this->assertEquals(100.0, $types['Perdana']['active']['conversion']['value']);
        $this->assertSame(3, $types['Perdana']['active']['conversion']['purchase_attempts']);
        $this->assertEquals(50.0, $types['Perpanjangan']['active']['conversion']['value']);
        $this->assertEquals(100.0, $types['Tidak diketahui']['active']['conversion']['value']);
        $this->assertEquals(0.0, $types['Bundling']['active']['conversion']['value']);
        // ... and counts in the comparison period, where it was created.
        $this->assertSame(1, $types['Perdana']['comparison']['conversion']['purchase_attempts']);
        $this->assertSame(0, $types['Perdana']['comparison']['value']);

        $revenue = $this->types('&metric=revenue');
        $revenueTypes = array_column($revenue['product_types'], null, 'name');

        $this->assertSame('revenue', $revenue['metric']);
        $this->assertEquals(8000000.0, $revenueTypes['Perdana']['active']['value']);
        $this->assertEquals(40.0, $revenueTypes['Perdana']['active']['share_percentage']);
        $this->assertEquals(60.0, $revenueTypes['Perpanjangan']['active']['share_percentage']);

        // The conversion of every type does not change with the bar metric.
        $this->assertSame(
            array_column(array_column($payments['product_types'], 'active'), 'conversion'),
            array_column(array_column($revenue['product_types'], 'active'), 'conversion'),
        );
    }

    public function test_ac02_scenario_80_vs_20_payments_become_40_vs_60_with_revenue(): void
    {
        $this->lastSevenDaysCoverage();

        for ($i = 0; $i < 8; $i++) {
            $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 1000000, type: 'Perdana');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 6000000, type: 'Perpanjangan');
        }

        $shares = fn (array $data) => array_column(array_column($data['product_types'], 'active'), 'share_percentage');

        $this->assertEquals([80.0, 20.0], $shares($this->types()));
        $this->assertEquals([40.0, 60.0], $shares($this->types('&metric=revenue')));
    }

    public function test_ac03_claims_join_the_status_bars_but_never_the_type_metrics(): void
    {
        $this->lastSevenDaysCoverage();

        $buyer = $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000, type: 'Perdana');
        $this->transaction('2026-11-05 10:00:00', null, 100000, MarkazTransactionStatus::Pending, type: 'Perdana');
        $this->transaction('2026-11-05 11:00:00', '2026-11-05 11:00:00', 0, claim: true, type: 'Perdana', nominal: 0);
        // A claim on another status lands only in that bar.
        $this->transaction('2026-11-05 12:00:00', null, 0, MarkazTransactionStatus::Expired, claim: true, type: 'Perdana', nominal: 0);
        // A group member never adds a checkout, a payment, or an attempt.
        $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000, refId: (string) $buyer->markaz_transaction_id, type: 'Perdana');

        $statuses = array_column($this->statuses()['statuses'], 'active', 'key');

        $this->assertSame(['count' => 2, 'purchase_count' => 1, 'claim_count' => 1], array_intersect_key($statuses['success'], array_flip(['count', 'purchase_count', 'claim_count'])));
        $this->assertSame(1, $statuses['pending']['count']);
        $this->assertSame(0, $statuses['pending']['claim_count']);
        $this->assertSame(['count' => 1, 'purchase_count' => 0, 'claim_count' => 1], array_intersect_key($statuses['expired'], array_flip(['count', 'purchase_count', 'claim_count'])));
        $this->assertSame(4, array_sum(array_column($statuses, 'count')));

        $perdana = $this->types()['product_types'][0]['active'];

        $this->assertSame(1, $perdana['success_payments']);
        $this->assertEquals(100000.0, $perdana['revenue']);
        $this->assertEquals(50.0, $perdana['conversion']['value']);
        $this->assertSame(2, $perdana['conversion']['purchase_attempts']);
    }

    public function test_unknown_statuses_are_reported_under_the_chart_and_mark_the_conversion_provisional(): void
    {
        $this->lastSevenDaysCoverage();

        $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000, type: 'Perpanjangan');
        $this->transaction('2026-11-05 10:00:00', null, 100000, null, type: 'Perpanjangan');

        $data = $this->statuses();

        $this->assertSame(1, $data['unknown_status']['active']['count']);
        $this->assertSame('Status belum tersedia', $data['unknown_status']['label']);
        $this->assertSame(2, $data['active']['totals']['total_checkouts']);
        $this->assertEquals(50.0, $data['statuses'][0]['active']['share_percentage']);

        $conversion = $this->types()['product_types'][1]['active']['conversion'];

        $this->assertEquals(50.0, $conversion['value']);
        $this->assertSame(1, $conversion['unknown_status_count']);
        $this->assertTrue($conversion['is_provisional']);
        $this->assertSame('Sementara', $conversion['provisional_label']);
    }

    public function test_the_comparison_uses_the_global_comparison_range_and_gives_the_share_change(): void
    {
        $this->lastSevenDaysCoverage();

        // Active: 3 Success, 1 Expired. Comparison: 1 Success, 1 Expired.
        for ($i = 0; $i < 3; $i++) {
            $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000, type: 'Perdana');
        }
        $this->transaction('2026-11-05 10:00:00', null, 100000, MarkazTransactionStatus::Expired, type: 'Perdana');
        $this->transaction('2026-10-29 09:00:00', '2026-10-29 09:10:00', 100000, type: 'Perpanjangan');
        $this->transaction('2026-10-29 10:00:00', null, 100000, MarkazTransactionStatus::Expired, type: 'Perpanjangan');

        $data = $this->statuses();

        $this->assertSame('2026-10-28', $data['comparison']['date_from']);
        $this->assertSame('2026-11-03', $data['comparison']['date_to']);
        $this->assertEquals(75.0, $data['statuses'][0]['active']['share_percentage']);
        $this->assertEquals(50.0, $data['statuses'][0]['comparison']['share_percentage']);
        $this->assertEquals(25.0, $data['statuses'][0]['share_change_percentage_point']);
        $this->assertEquals(-25.0, $data['unsuccessful']['share_change_percentage_point']);

        $types = $this->types()['product_types'];

        $this->assertEquals(100.0, $types[0]['active']['share_percentage']);
        $this->assertEquals(0.0, $types[0]['comparison']['share_percentage']);
        $this->assertEquals(100.0, $types[0]['share_change_percentage_point']);
        $this->assertSame(1, $types[1]['comparison']['success_payments']);
    }

    public function test_a_missing_comparison_coverage_keeps_the_active_values_and_is_not_zero(): void
    {
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000, type: 'Perdana');

        $status = $this->statuses();
        $type = $this->types();

        $this->assertSame(1, $status['statuses'][0]['active']['count']);
        $this->assertSame('unavailable', $status['comparison']['availability']);
        $this->assertNull($status['statuses'][0]['comparison']['count']);
        $this->assertNull($status['statuses'][0]['share_change_percentage_point']);

        $this->assertSame(1, $type['product_types'][0]['active']['value']);
        $this->assertNull($type['product_types'][0]['comparison']['value']);
        $this->assertNull($type['product_types'][0]['comparison']['conversion']['is_provisional']);
    }

    public function test_an_equivalent_hour_comparison_is_unavailable_instead_of_rebuilt_from_the_latest_state(): void
    {
        $this->coverage('2026-11-11', '2026-11-11', '2026-11-11 14:00:00');
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-10 14:00:00');
        $this->transaction('2026-11-11 09:00:00', '2026-11-11 09:10:00', 100000, type: 'Perdana');
        $this->transaction('2026-11-10 09:00:00', '2026-11-10 09:10:00', 100000, type: 'Perdana');

        $status = $this->statuses('today');

        $this->assertSame('partial', $status['active']['availability']);
        $this->assertSame(1, $status['statuses'][0]['active']['count']);
        $this->assertSame('snapshot', $status['comparison']['source']);
        $this->assertSame('unavailable', $status['comparison']['availability']);
        $this->assertNull($status['statuses'][0]['comparison']['count']);
        $this->assertNull($this->types('', 'today')['product_types'][0]['comparison']['value']);
    }

    public function test_unknown_coverage_gives_null_values_and_a_complete_empty_period_gives_zero(): void
    {
        $unknown = $this->statuses();

        $this->assertSame('unavailable', $unknown['active']['availability']);
        $this->assertNull($unknown['statuses'][0]['active']['count']);
        $this->assertNull($unknown['active']['totals']['total_checkouts']);
        $this->assertNull($this->types()['product_types'][0]['active']['value']);

        $this->lastSevenDaysCoverage();
        $empty = $this->statuses();
        $emptyTypes = $this->types();

        $this->assertTrue($empty['is_empty']);
        $this->assertSame(0, $empty['statuses'][0]['active']['count']);
        $this->assertNull($empty['statuses'][0]['active']['share_percentage']);
        $this->assertSame(['Perdana', 'Perpanjangan'], array_column($emptyTypes['product_types'], 'name'));
        $this->assertSame(0, $emptyTypes['product_types'][0]['active']['value']);
        $this->assertNull($emptyTypes['product_types'][0]['active']['share_percentage']);
        $this->assertNull($emptyTypes['product_types'][0]['active']['conversion']['value']);
    }

    public function test_a_preset_is_required_and_the_metric_is_validated(): void
    {
        $this->actingAs($this->manager)->getJson(self::STATUS_URL)->assertUnprocessable()->assertJsonValidationErrors('preset');
        $this->actingAs($this->manager)->getJson(self::TYPE_URL.'?preset=last_7_days&metric=nominal')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metric');
    }

    public function test_guests_cannot_read_the_datasets(): void
    {
        $this->getJson(self::STATUS_URL.'?preset=last_7_days')->assertUnauthorized();
        $this->getJson(self::TYPE_URL.'?preset=last_7_days')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function statuses(string $preset = 'last_7_days'): array
    {
        return $this->actingAs($this->manager)
            ->getJson(self::STATUS_URL.'?platforms[]=app-a&preset='.$preset)
            ->assertOk()
            ->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function types(string $query = '', string $preset = 'last_7_days'): array
    {
        return $this->actingAs($this->manager)
            ->getJson(self::TYPE_URL.'?platforms[]=app-a&preset='.$preset.$query)
            ->assertOk()
            ->json('data');
    }

    private function lastSevenDaysCoverage(): void
    {
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
    }

    /**
     * @param  list<string>|null  $promo
     */
    private function transaction(
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
        ?string $type = null,
        ?float $nominal = null,
        ?array $promo = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->forPlatform($this->platform)->create([
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $nominal ?? $netNominal + 2000,
            'admin_fee' => 2000,
            'net_nominal' => $netNominal,
            'product_type' => $type,
            'promo_code' => $promo,
        ]);
    }

    /**
     * A complete transactions snapshot, which also gives the period resolver the complete-data cutoff.
     */
    private function coverage(string $from, string $to, string $cutoffAt): void
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
}
