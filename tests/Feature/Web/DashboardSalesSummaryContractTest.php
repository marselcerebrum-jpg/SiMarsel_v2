<?php

namespace Tests\Feature\Web;

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
 * The Ringkasan Sales cards (FIX-05-FE-01) read `data.sales_summary` of `GET /api/performance/summary` with the query
 * the global filter builds. The scenarios are the ones in resources/js/pages/performance/fixtures/sales-summary.js.
 */
class DashboardSalesSummaryContractTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/summary?platforms%5B%5D=app-a&preset=last_7_days';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
    }

    public function test_the_summary_returns_every_field_the_cards_read_in_their_order(): void
    {
        $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00');

        $summary = $this->actingAs($this->manager)->getJson(self::URL)->assertOk()
            ->assertJsonStructure(['data' => ['sales_summary' => [
                'metrics' => [['key', 'unit', 'active' => ['value', 'availability'], 'comparison', 'change' => ['value', 'unit', 'trend']]],
            ]]])
            ->json('data.sales_summary');
        $metrics = array_column($summary['metrics'], null, 'key');

        $this->assertSame(
            ['revenue', 'success_payments', 'total_checkouts', 'conversion', 'average_order_value', 'unique_buyers', 'downloaders'],
            array_column($summary['metrics'], 'key'),
        );
        $this->assertSame(
            ['currency', 'count', 'count', 'percentage', 'currency', 'count', 'count'],
            array_column($summary['metrics'], 'unit'),
        );
        $this->assertSame(['Hasil Penjualan', 'Aktivitas Transaksi'], array_slice(array_column($summary['groups'], 'label'), 0, 2));
        $this->assertSame(['Berdasarkan tanggal pembayaran', 'Berdasarkan tanggal transaksi dibuat'], array_slice(array_column($summary['groups'], 'date_basis_label'), 0, 2));
        $this->assertSame('percentage_point', $metrics['conversion']['change']['unit']);
        $this->assertSame('percent', $metrics['revenue']['change']['unit']);
        $this->assertArrayHasKey('purchase_attempts', $metrics['total_checkouts']['active']['breakdown']);
        $this->assertArrayHasKey('claim_checkouts', $metrics['total_checkouts']['active']['breakdown']);
    }

    public function test_the_claim_fixture_scenario_matches_the_endpoint(): void
    {
        $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00');
        $this->transaction('2026-11-05 10:00:00', null, MarkazTransactionStatus::Pending);
        $this->transaction('2026-11-05 11:00:00', '2026-11-05 11:00:00', claim: true);

        $metrics = $this->metrics();

        $this->assertSame(3, $metrics['total_checkouts']['active']['value']);
        $this->assertSame(2, $metrics['total_checkouts']['active']['breakdown']['purchase_attempts']);
        $this->assertSame(1, $metrics['total_checkouts']['active']['breakdown']['claim_checkouts']);
        $this->assertEquals(50, $metrics['conversion']['active']['value']);
        $this->assertSame([1, 2, false], [
            $metrics['conversion']['active']['success_purchases'],
            $metrics['conversion']['active']['purchase_attempts'],
            $metrics['conversion']['active']['is_provisional'],
        ]);
        $this->assertEquals(180000, $metrics['revenue']['active']['value']);
        $this->assertEquals(182000, $metrics['revenue']['active']['nominal_total']);
        $this->assertEquals(180000, $metrics['average_order_value']['active']['value']);
        $this->assertSame(1, $metrics['unique_buyers']['active']['value']);
    }

    public function test_the_provisional_fixture_scenario_matches_the_endpoint(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->transaction('2026-11-05 09:00:00', '2026-11-05 09:10:00');
        }
        for ($i = 0; $i < 3; $i++) {
            $this->transaction('2026-11-06 09:00:00', null, MarkazTransactionStatus::Pending);
        }
        $this->transaction('2026-11-07 09:00:00', null, null);

        $conversion = $this->metrics()['conversion']['active'];

        $this->assertSame(10, $this->metrics()['total_checkouts']['active']['value']);
        $this->assertEquals(60, $conversion['value']);
        $this->assertSame([true, 'Sementara', 1], [$conversion['is_provisional'], $conversion['provisional_label'], $conversion['unknown_status_count']]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function metrics(): array
    {
        $summary = $this->actingAs($this->manager)->getJson(self::URL)->assertOk()->json('data.sales_summary');

        return array_column($summary['metrics'], null, 'key');
    }

    private function transaction(
        string $createdAt,
        ?string $paidAt,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->forPlatform($this->platform)->create([
            'markaz_customer_id' => fake()->unique()->numberBetween(1, 999999),
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $claim ? 0 : 182000,
            'admin_fee' => $claim ? 0 : 2000,
            'net_nominal' => $claim ? 0 : 180000,
        ]);
    }

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
