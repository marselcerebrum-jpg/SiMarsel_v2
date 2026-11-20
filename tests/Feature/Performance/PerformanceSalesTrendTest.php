<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Enums\TransactionDateBasis;
use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use App\Repositories\DashboardTransactionRepository;
use App\Services\TransactionAggregationService;
use App\Support\TransactionAggregation\AggregationFilter;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX-06-BE-01: Omzet, Transaksi Berhasil, and Total Transaksi buckets per hour, day, week, and month,
 * with the original active and comparison times and the completeness of every bucket.
 */
class PerformanceSalesTrendTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/trend/sales';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a']);
    }

    public function test_ac01_bucket_sums_match_the_period_aggregate_on_the_same_date_basis(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');

        $this->transaction('2026-11-09 23:50:00', '2026-11-10 00:10:00', 180000);
        $this->transaction('2026-11-05 08:00:00', '2026-11-05 08:30:00', 100000);
        $this->transaction('2026-11-03 23:00:00', '2026-11-04 01:00:00', 70000);
        $this->transaction('2026-11-06 09:00:00', null, 50000, MarkazTransactionStatus::Pending);
        $this->transaction('2026-10-29 10:00:00', '2026-10-29 10:05:00', 90000);

        $trend = $this->trend('preset=last_7_days');
        $buckets = $trend['buckets'];

        $this->assertSame('daily', $trend['granularity']);
        $this->assertCount(7, $buckets);
        $this->assertSame('2026-11-04T00:00:00+07:00', $buckets[0]['active']['start_at']);
        $this->assertSame('2026-11-05T00:00:00+07:00', $buckets[0]['active']['end_at']);
        $this->assertSame('04 Nov 2026', $buckets[0]['active']['label']);
        $this->assertSame('2026-10-28T00:00:00+07:00', $buckets[0]['comparison']['start_at']);
        $this->assertSame('28 Okt 2026', $buckets[0]['comparison']['label']);

        $this->assertEquals(['revenue' => 0.0, 'success_payments' => 0, 'total_checkouts' => 1], $buckets[5]['active']['values']);
        $this->assertEquals(['revenue' => 180000.0, 'success_payments' => 1, 'total_checkouts' => 0], $buckets[6]['active']['values']);
        $this->assertEquals(['revenue' => 70000.0, 'success_payments' => 1, 'total_checkouts' => 0], $buckets[0]['active']['values']);
        $this->assertEquals(90000.0, $buckets[1]['comparison']['values']['revenue']);

        $paid = $this->aggregate('2026-11-04', '2026-11-10', TransactionDateBasis::Paid);
        $created = $this->aggregate('2026-11-04', '2026-11-10', TransactionDateBasis::Created);

        $this->assertEquals($paid['revenue'], $this->sum($buckets, 'revenue'));
        $this->assertSame($paid['success_payments'], $this->sum($buckets, 'success_payments'));
        $this->assertSame($created['total_checkouts'], $this->sum($buckets, 'total_checkouts'));
        $this->assertEquals(['revenue' => 350000.0, 'success_payments' => 3, 'total_checkouts' => 3], $trend['totals']['values']);
    }

    public function test_ac01_another_granularity_regroups_the_same_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->transaction('2026-11-04 08:00:00', '2026-11-04 08:30:00', 100000);
        $this->transaction('2026-11-09 20:00:00', '2026-11-10 09:00:00', 200000);

        $weekly = $this->trend('preset=last_7_days&granularity=weekly');

        $this->assertSame('weekly', $weekly['granularity']);
        $this->assertSame('daily', $weekly['default_granularity']);
        $this->assertSame(['04 - 08 Nov 2026', '09 - 10 Nov 2026'], array_column(array_column($weekly['buckets'], 'active'), 'label'));
        $this->assertEquals(['revenue' => 100000.0, 'success_payments' => 1, 'total_checkouts' => 1], $weekly['buckets'][0]['active']['values']);
        $this->assertEquals(['revenue' => 200000.0, 'success_payments' => 1, 'total_checkouts' => 1], $weekly['buckets'][1]['active']['values']);

        // The comparison weeks take the same number of days from the comparison start.
        $this->assertSame('2026-10-28T00:00:00+07:00', $weekly['buckets'][0]['comparison']['start_at']);
        $this->assertSame('2026-11-02T00:00:00+07:00', $weekly['buckets'][0]['comparison']['end_at']);
        $this->assertSame('2026-11-04T00:00:00+07:00', $weekly['buckets'][1]['comparison']['end_at']);

        $hourly = $this->trend('preset=last_7_days&granularity=hourly');

        $this->assertCount(7 * 24, $hourly['buckets']);
        $this->assertEquals(300000.0, $this->sum($hourly['buckets'], 'revenue'));
        $this->assertSame('09 Nov 2026 20.00', $hourly['buckets'][5 * 24 + 20]['active']['label']);
        $this->assertSame(1, $hourly['buckets'][5 * 24 + 20]['active']['values']['total_checkouts']);
    }

    public function test_ac02_buckets_after_the_cutoff_are_unavailable_and_complete_empty_buckets_are_zero(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
        $this->coverage('2026-11-11', '2026-11-11', '2026-11-11 14:00:00');
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-10 13:00:00', ['revenue' => 400000, 'success_payments' => 2, 'total_checkouts' => 3]);
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-10 14:00:00', ['revenue' => 550000, 'success_payments' => 3, 'total_checkouts' => 5]);

        $this->transaction('2026-11-11 10:15:00', '2026-11-11 10:20:00', 150000);
        $this->transaction('2026-11-11 14:10:00', '2026-11-11 14:15:00', 999000);

        $trend = $this->trend('preset=today');
        $buckets = $trend['buckets'];

        $this->assertSame('hourly', $trend['granularity']);
        $this->assertCount(24, $buckets);
        $this->assertSame('11 Nov 2026 13.00', $buckets[13]['active']['label']);
        $this->assertSame('2026-11-11T13:00:00+07:00', $buckets[13]['active']['start_at']);
        $this->assertSame('2026-11-10T13:00:00+07:00', $buckets[13]['comparison']['start_at']);
        $this->assertSame('10 Nov 2026 13.00', $buckets[13]['comparison']['label']);

        $this->assertEquals(['revenue' => 150000.0, 'success_payments' => 1, 'total_checkouts' => 1], $buckets[10]['active']['values']);

        $this->assertSame('complete', $buckets[13]['active']['availability']);
        $this->assertEquals(['revenue' => 0.0, 'success_payments' => 0, 'total_checkouts' => 0], $buckets[13]['active']['values']);

        $this->assertSame('unavailable', $buckets[14]['active']['availability']);
        $this->assertNull($buckets[14]['active']['values']);
        $this->assertNull($buckets[14]['active']['as_of']);
        $this->assertSame('unavailable', $buckets[14]['comparison']['availability']);
        $this->assertNull($buckets[14]['comparison']['values']);

        $this->assertSame('partial', $trend['totals']['availability']);
        $this->assertSame('2026-11-11T14:00:00+07:00', $trend['totals']['as_of']);
        $this->assertEquals(150000.0, $trend['totals']['values']['revenue']);
    }

    public function test_ac02_the_equivalent_hour_comparison_reads_recorded_snapshots_and_leaves_missing_points_empty(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
        $this->coverage('2026-11-11', '2026-11-11', '2026-11-11 14:00:00');
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-10 13:00:00', ['revenue' => 400000, 'success_payments' => 2, 'total_checkouts' => 3]);
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-10 14:00:00', ['revenue' => 550000, 'success_payments' => 3, 'total_checkouts' => 5]);

        // The latest state of yesterday differs from what was recorded; the recorded values win.
        $this->transaction('2026-11-10 13:20:00', '2026-11-10 13:25:00', 777000);

        $buckets = $this->trend('preset=today')['buckets'];

        $this->assertSame('snapshot', $buckets[13]['comparison']['source']);
        $this->assertSame('complete', $buckets[13]['comparison']['availability']);
        $this->assertEquals(['revenue' => 150000.0, 'success_payments' => 1, 'total_checkouts' => 2], $buckets[13]['comparison']['values']);

        // 12.00 - 13.00 needs the 12.00 snapshot, which was never recorded.
        $this->assertSame('unavailable', $buckets[12]['comparison']['availability']);
        $this->assertNull($buckets[12]['comparison']['values']);
        $this->assertSame('2026-11-10T12:00:00+07:00', $buckets[12]['comparison']['start_at']);
    }

    public function test_ac03_total_counts_every_checkout_while_payments_count_one_buyer_row_per_paid_success(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-11-09', '2026-11-09', '2026-11-10 00:00:00');

        $buyer = $this->transaction('2026-11-10 09:00:00', '2026-11-10 09:05:00', 300000);
        $this->transaction('2026-11-10 09:00:00', '2026-11-10 09:05:00', 300000, refId: $buyer->markaz_transaction_id);
        $this->transaction('2026-11-10 09:00:00', '2026-11-10 09:05:00', 300000, refId: $buyer->markaz_transaction_id);
        $this->transaction('2026-11-10 10:00:00', '2026-11-10 10:00:00', 0, claim: true);
        $this->transaction('2026-11-10 11:00:00', null, 250000);
        $this->transaction('2026-11-10 12:00:00', '2026-11-10 12:05:00', 200000, MarkazTransactionStatus::Failed);

        $trend = $this->trend('preset=yesterday');

        $this->assertSame('hourly', $trend['granularity']);
        $this->assertEquals(['revenue' => 300000.0, 'success_payments' => 1, 'total_checkouts' => 4], $trend['totals']['values']);
        $this->assertSame(4, $this->sum($trend['buckets'], 'total_checkouts'));
        $this->assertSame(1, $this->sum($trend['buckets'], 'success_payments'));
        $this->assertEquals(300000.0, $this->sum($trend['buckets'], 'revenue'));
        $this->assertEquals(['revenue' => 300000.0, 'success_payments' => 1, 'total_checkouts' => 1], $trend['buckets'][9]['active']['values']);
        $this->assertEquals(['revenue' => 0.0, 'success_payments' => 0, 'total_checkouts' => 1], $trend['buckets'][10]['active']['values']);
    }

    public function test_ac04_latest_payment_changes_move_active_buckets_without_rewriting_recorded_comparison_buckets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12T14:30:00+07:00'));
        $this->coverage('2026-11-10', '2026-11-12', '2026-11-12 14:00:00');
        $this->coverage('2026-11-07', '2026-11-09', '2026-11-09 14:00:00', ['revenue' => 900000, 'success_payments' => 4, 'total_checkouts' => 6]);
        $this->coverage('2026-11-09', '2026-11-09', '2026-11-09 14:00:00', ['revenue' => 500000, 'success_payments' => 2, 'total_checkouts' => 3]);

        $transaction = $this->transaction('2026-11-10 09:00:00', '2026-11-10 09:05:00', 200000);
        $this->transaction('2026-11-09 10:00:00', '2026-11-09 10:05:00', 999000);
        $this->transaction('2026-11-08 10:00:00', '2026-11-08 10:05:00', 80000);

        $query = 'preset=date_range&date_from=2026-11-10&date_to=2026-11-12';
        $recorded = ['revenue' => 500000.0, 'success_payments' => 2, 'total_checkouts' => 3];

        $before = $this->trend($query)['buckets'];

        $this->assertEquals(200000.0, $before[0]['active']['values']['revenue']);
        $this->assertEquals(0.0, $before[2]['active']['values']['revenue']);
        $this->assertSame('partial', $before[2]['active']['availability']);
        $this->assertSame('latest', $before[1]['comparison']['source']);
        $this->assertEquals(80000.0, $before[1]['comparison']['values']['revenue']);
        $this->assertSame('snapshot', $before[2]['comparison']['source']);
        $this->assertSame('2026-11-09T14:00:00+07:00', $before[2]['comparison']['as_of']);
        $this->assertEquals($recorded, $before[2]['comparison']['values']);

        $transaction->update(['paid_date' => '2026-11-12', 'paid_time' => '10:00:00', 'markaz_paid_at' => '2026-11-12 10:00:00']);
        $moved = $this->trend($query)['buckets'];

        $this->assertEquals(['revenue' => 0.0, 'success_payments' => 0, 'total_checkouts' => 1], $moved[0]['active']['values']);
        $this->assertEquals(['revenue' => 200000.0, 'success_payments' => 1, 'total_checkouts' => 0], $moved[2]['active']['values']);
        $this->assertEquals(200000.0, $this->aggregate('2026-11-12', '2026-11-12', TransactionDateBasis::Paid, '2026-11-12 14:00:00')['revenue']);
        $this->assertEquals($recorded, $moved[2]['comparison']['values']);

        $transaction->update(['effective_status' => MarkazTransactionStatus::Failed->value]);
        $failed = $this->trend($query)['buckets'];

        $this->assertEquals(['revenue' => 0.0, 'success_payments' => 0, 'total_checkouts' => 1], $failed[0]['active']['values']);
        $this->assertEquals(['revenue' => 0.0, 'success_payments' => 0, 'total_checkouts' => 0], $failed[2]['active']['values']);
        $this->assertEquals($recorded, $failed[2]['comparison']['values']);
    }

    public function test_unknown_coverage_returns_unavailable_buckets_instead_of_zero(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
        $this->transaction('2026-11-10 09:00:00', '2026-11-10 09:05:00', 300000);

        $trend = $this->trend('preset=yesterday');

        $this->assertSame('unavailable', $trend['totals']['availability']);
        $this->assertNull($trend['totals']['values']);
        $this->assertSame([], array_filter(array_column(array_column($trend['buckets'], 'active'), 'values')));
        $this->assertSame('unavailable', $trend['buckets'][0]['comparison']['availability']);
    }

    public function test_default_granularity_follows_the_preset(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));

        foreach ([
            'today' => 'hourly',
            'yesterday' => 'hourly',
            'last_7_days' => 'daily',
            'last_30_days' => 'daily',
            'last_90_days' => 'weekly',
            'last_year' => 'monthly',
        ] as $preset => $granularity) {
            $this->assertSame($granularity, $this->trend("preset={$preset}")['granularity'], $preset);
        }

        $lastYear = $this->trend('preset=last_year');

        $this->assertCount(11, $lastYear['buckets']);
        $this->assertSame('Jan 2026', $lastYear['buckets'][0]['active']['label']);
        $this->assertSame('Jan 2025', $lastYear['buckets'][0]['comparison']['label']);
        $this->assertSame(['daily', 'weekly', 'monthly'], $lastYear['granularities']);
    }

    public function test_the_granularity_is_validated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));

        $this->actingAs($this->manager)->getJson(self::URL.'?preset=last_90_days&granularity=hourly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['granularity' => 'Rentang tanggal untuk pengelompokan hourly maksimal 31 hari.']);

        $this->actingAs($this->manager)->getJson(self::URL.'?preset=today&granularity=yearly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['granularity' => 'Pengelompokan tidak valid.']);

        $this->actingAs($this->manager)->getJson(self::URL.'?date_from=2026-11-01&date_to=2026-11-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['preset' => 'Preset periode wajib diisi.']);

        $this->actingAs($this->manager)->getJson('/api/performance/trend?metric=revenue&period=hourly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    }

    public function test_the_series_describe_axes_and_the_initial_legend(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));

        $series = collect($this->trend('preset=today')['series'])->keyBy('key');

        $this->assertEquals(['revenue', 'comparison_revenue', 'success_payments', 'total_checkouts'], $series->keys()->all());
        $this->assertSame('currency', $series['comparison_revenue']['axis']);
        $this->assertSame('comparison', $series['comparison_revenue']['side']);
        $this->assertSame('count', $series['success_payments']['axis']);
        $this->assertSame('created', $series['total_checkouts']['date_basis']);
        $this->assertFalse($series['total_checkouts']['visible']);
    }

    public function test_guests_cannot_read_the_trend(): void
    {
        $this->getJson(self::URL.'?preset=today')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function trend(string $query): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.'?platforms[]=app-a&'.$query)->assertOk()->json('data');
    }

    private function transaction(
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->for($this->platform)->create([
            'markaz_customer_id' => fake()->numberBetween(1, 999999),
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $netNominal + 2000,
            'admin_fee' => 2000,
            'net_nominal' => $netNominal,
            'status_message' => $status->label(),
        ]);
    }

    /**
     * A complete transactions snapshot, which also tells the period resolver the complete-data cutoff.
     *
     * @param  array<string, int|float>  $payload
     */
    private function coverage(string $from, string $to, string $cutoffAt, array $payload = []): void
    {
        DashboardSnapshot::factory()->for($this->platform)->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'payload' => $payload,
            'recorded_at' => $cutoffAt,
        ]);
    }

    /**
     * The shared period aggregation of the stored transactions.
     *
     * @return array<string, mixed>
     */
    private function aggregate(string $from, string $to, TransactionDateBasis $basis, ?string $completeUntil = null): array
    {
        $rows = app(DashboardTransactionRepository::class)->contractRows(
            [$this->platform->id],
            CarbonImmutable::parse($from, 'Asia/Jakarta'),
            CarbonImmutable::parse($to, 'Asia/Jakarta')->addDay(),
        );

        return app(TransactionAggregationService::class)->aggregate(
            $rows,
            new AggregationFilter([$this->platform->id], $from, $to, $basis, $completeUntil ?? CarbonImmutable::parse($to, 'Asia/Jakarta')->addDay()),
        )['metrics'];
    }

    /**
     * @param  list<array<string, mixed>>  $buckets
     */
    private function sum(array $buckets, string $value): int|float
    {
        return array_sum(array_map(fn (array $bucket) => $bucket['active']['values'][$value] ?? 0, $buckets));
    }
}
