<?php

namespace Tests\Feature\Database;

use App\Enums\DashboardDataset;
use App\Enums\MarkazTransactionStatus;
use App\Enums\TransactionDateBasis;
use App\Models\DashboardSnapshot;
use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Transaction;
use App\Services\ComparisonSnapshotService;
use App\Services\TransactionAggregationService;
use App\Support\TransactionAggregation\AggregationFilter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FIX-15-BE-03: dashboard_snapshots keeps values recorded at a cutoff, separate from the daily
 * downloaders total, and only complete snapshots set the complete-data boundary.
 */
class DashboardSnapshotSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-15';

    private const CUTOFF_13 = '2026-11-15 13:00:00';

    private const CUTOFF_14 = '2026-11-15 14:00:00';

    public function test_schema_has_the_snapshot_fields_and_keeps_the_daily_downloader_key(): void
    {
        $this->assertTrue(Schema::hasColumns('dashboard_snapshots', [
            'id', 'platform_id', 'dataset', 'date_from', 'date_to', 'cutoff_at', 'is_complete', 'payload', 'recorded_at',
        ]));
        $this->assertTrue(Schema::hasColumns('downloaders', ['platform_id', 'date', 'total']));

        $indexes = collect(Schema::getIndexes('dashboard_snapshots'))->keyBy('name');
        $this->assertTrue($indexes['dashboard_snapshots_period_cutoff_unique']['unique']);
        $this->assertSame(['platform_id', 'dataset', 'date_from', 'date_to', 'cutoff_at'], $indexes['dashboard_snapshots_period_cutoff_unique']['columns']);
        $this->assertTrue(collect(Schema::getIndexes('downloaders'))->contains(
            fn (array $index) => $index['unique'] && $index['columns'] === ['platform_id', 'date'],
        ));
    }

    public function test_ac01_the_daily_total_and_two_cutoffs_of_the_same_day_are_read_separately(): void
    {
        $platform = Platform::factory()->create();
        Downloader::factory()->create(['platform_id' => $platform->id, 'date' => self::DAY, 'total' => 120]);
        $this->downloaderSnapshot($platform, self::CUTOFF_13, 70);
        $this->downloaderSnapshot($platform, self::CUTOFF_14, 85);

        $daily = Downloader::where('platform_id', $platform->id)->whereDate('date', self::DAY)->sole();
        $snapshots = DashboardSnapshot::where('platform_id', $platform->id)
            ->forPeriod(DashboardDataset::Downloaders, self::DAY, self::DAY)
            ->orderBy('cutoff_at')
            ->get();

        $this->assertSame(120, $daily->total);
        $this->assertCount(2, $snapshots);
        $this->assertSame(['2026-11-15T13:00:00+07:00', '2026-11-15T14:00:00+07:00'], $snapshots->map(fn ($s) => $s->cutoff_at->toIso8601String())->all());
        $this->assertSame([70, 85], $snapshots->map(fn ($s) => $s->payload['total'])->all());
        $this->assertSame([self::DAY => 70], $snapshots[0]->payload['daily']);

        // A later daily upsert of downloaders does not rewrite the recorded cutoffs.
        $daily->update(['total' => 140]);
        $this->assertSame([70, 85], DashboardSnapshot::orderBy('cutoff_at')->pluck('payload')->map(fn ($p) => $p['total'])->all());
    }

    public function test_ac01_a_cutoff_is_recorded_once_per_platform_dataset_and_period(): void
    {
        $platform = Platform::factory()->create();
        $this->downloaderSnapshot($platform, self::CUTOFF_13, 70);
        DashboardSnapshot::factory()->create(['platform_id' => $platform->id, 'cutoff_at' => self::CUTOFF_13]);

        $this->assertSame(2, DashboardSnapshot::where('cutoff_at', self::CUTOFF_13)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->downloaderSnapshot($platform, self::CUTOFF_13, 71);
    }

    public function test_ac02_complete_transaction_snapshot_covers_created_and_paid_dates_and_keeps_its_recorded_state(): void
    {
        $platform = Platform::factory()->create();
        $paidFromYesterday = $this->transaction($platform, '2026-11-14 22:00:00', MarkazTransactionStatus::Success, '2026-11-15 09:00:00', customer: 1);
        $this->transaction($platform, '2026-11-15 10:00:00', MarkazTransactionStatus::Pending);
        $this->transaction($platform, '2026-11-15 11:00:00', MarkazTransactionStatus::Success, '2026-11-15 11:05:00', customer: 2);
        $this->transaction($platform, '2026-11-15 13:30:00', MarkazTransactionStatus::Success, '2026-11-15 13:35:00', customer: 3);

        $this->transactionSnapshot($platform, self::CUTOFF_13, isComplete: true);
        $this->transactionSnapshot($platform, self::CUTOFF_14, isComplete: false);

        $at13 = $this->snapshotAt($platform, self::CUTOFF_13);

        // Created basis counts only checkouts created on the day; paid basis includes the payment of yesterday's checkout.
        $this->assertSame(2, $at13->payload['total_checkouts']);
        $this->assertSame(1, $at13->payload['success_purchases']);
        $this->assertSame(1, $at13->payload['status_breakdown']['pending']['count']);
        $this->assertSame(2, $at13->payload['success_payments']);
        $this->assertEquals(360000, $at13->payload['revenue']);
        $this->assertSame(2, $at13->payload['unique_buyers']);

        $this->assertSame('2026-11-15T13:00:00+07:00', DashboardSnapshot::latestCompleteCutoff([$platform->id], DashboardDataset::Transactions, self::DAY, self::DAY)?->toIso8601String());

        $comparisons = new ComparisonSnapshotService(new TransactionAggregationService);
        $rows = DashboardSnapshot::all()->map->toComparisonRow()->all();
        $complete = $comparisons->read($rows, [$platform->id], 'transactions', self::DAY, self::DAY, '2026-11-15T13:00:00+07:00');
        $partial = $comparisons->read($rows, [$platform->id], 'transactions', self::DAY, self::DAY, '2026-11-15T14:00:00+07:00');
        $this->assertSame('complete', $complete['availability']);
        $this->assertSame(2, $complete['values']['success_payments']);
        $this->assertSame('unavailable', $partial['availability']);
        $this->assertNull($partial['values']);

        // The latest stored transaction changes status; the 13.00 snapshot still shows the recorded state.
        $paidFromYesterday->update(['effective_status' => MarkazTransactionStatus::Failed->value]);

        $this->assertSame(1, $this->paidMetrics($platform, self::CUTOFF_13)['success_payments']);
        $stored = $this->snapshotAt($platform, self::CUTOFF_13);
        $this->assertSame(2, $stored->payload['success_payments']);
        $this->assertEquals(360000, $stored->payload['revenue']);
        $this->assertTrue($stored->is_complete);
    }

    public function test_the_shared_boundary_is_the_earliest_latest_complete_cutoff_of_the_selected_platforms(): void
    {
        [$a, $b] = Platform::factory()->count(2)->create();
        $this->downloaderSnapshot($a, self::CUTOFF_14, 85);
        $this->downloaderSnapshot($b, self::CUTOFF_13, 30);

        $this->assertSame('14:00', $this->completeCutoff([$a->id]));
        $this->assertSame('13:00', $this->completeCutoff([$b->id]));
        $this->assertSame('13:00', $this->completeCutoff([$a->id, $b->id]));
        $this->assertSame('13:00', $this->completeCutoff([$b->id, $a->id, $a->id]));
    }

    public function test_the_latest_complete_cutoff_is_picked_when_every_platform_has_several_cutoffs(): void
    {
        [$a, $b] = Platform::factory()->count(2)->create();

        // Inserted out of order so the result never depends on row or group order.
        foreach ([[$b, self::CUTOFF_14], [$a, self::CUTOFF_13], [$b, self::CUTOFF_13], [$a, self::CUTOFF_14]] as [$platform, $cutoffAt]) {
            $this->downloaderSnapshot($platform, $cutoffAt, 50);
        }
        $this->downloaderSnapshot($a, '2026-11-15 12:00:00', 40);

        $this->assertSame('14:00', $this->completeCutoff([$a->id]));
        $this->assertSame('14:00', $this->completeCutoff([$a->id, $b->id]));
    }

    public function test_incomplete_snapshots_and_platforms_without_a_complete_snapshot_never_set_the_boundary(): void
    {
        [$a, $b, $partialOnly, $missing] = Platform::factory()->count(4)->create();
        $this->downloaderSnapshot($a, self::CUTOFF_13, 70);
        $this->downloaderSnapshot($a, self::CUTOFF_14, 85);
        $this->downloaderSnapshot($b, self::CUTOFF_13, 30);
        $this->downloaderSnapshot($b, '2026-11-15 15:00:00', 40, isComplete: false);
        $this->downloaderSnapshot($partialOnly, self::CUTOFF_14, 12, isComplete: false);

        $this->assertSame('13:00', $this->completeCutoff([$b->id]));
        $this->assertSame('13:00', $this->completeCutoff([$a->id, $b->id]));
        $this->assertNull($this->completeCutoff([$a->id, $partialOnly->id]));
        $this->assertNull($this->completeCutoff([$a->id, $missing->id]));
        $this->assertNull($this->completeCutoff([]));
        $this->assertNull(DashboardSnapshot::latestCompleteCutoff([$a->id, $b->id], DashboardDataset::Transactions, self::DAY, self::DAY));
        $this->assertNull(DashboardSnapshot::latestCompleteCutoff([$a->id], DashboardDataset::Downloaders, '2026-11-16', '2026-11-16'));
    }

    public function test_complete_zero_partial_and_missing_snapshots_read_as_recorded(): void
    {
        [$withData, $empty, $partialOnly, $missing] = Platform::factory()->count(4)->create();
        $this->downloaderSnapshot($withData, self::CUTOFF_13, 70);
        $this->downloaderSnapshot($empty, self::CUTOFF_13, 0);
        $this->downloaderSnapshot($partialOnly, self::CUTOFF_13, 12, isComplete: false);

        $comparisons = new ComparisonSnapshotService(new TransactionAggregationService);
        $rows = DashboardSnapshot::all()->map->toComparisonRow()->all();
        $read = fn (Platform $platform) => $comparisons->read($rows, [$platform->id], 'downloaders', self::DAY, self::DAY, '2026-11-15T13:00:00+07:00');

        $this->assertSame(['availability' => 'complete', 'total' => 70], ['availability' => $read($withData)['availability'], 'total' => $read($withData)['values']['total']]);
        $this->assertSame(0, $read($empty)['values']['total']);
        $this->assertSame('complete', $read($empty)['availability']);

        foreach ([$partialOnly, $missing] as $platform) {
            $this->assertSame('unavailable', $read($platform)['availability']);
            $this->assertNull($read($platform)['values']);
        }

        // The partial snapshot stays stored and recognizable, but never sets the complete boundary.
        $this->assertFalse($this->snapshotAt($partialOnly, self::CUTOFF_13, DashboardDataset::Downloaders)->is_complete);
        $this->assertNull(DashboardSnapshot::latestCompleteCutoff([$partialOnly->id], DashboardDataset::Downloaders, self::DAY, self::DAY));
        $this->assertNull(DashboardSnapshot::latestCompleteCutoff([$withData->id, $missing->id], DashboardDataset::Downloaders, self::DAY, self::DAY));
    }

    /**
     * @param  list<int>  $platformIds
     */
    private function completeCutoff(array $platformIds): ?string
    {
        return DashboardSnapshot::latestCompleteCutoff($platformIds, DashboardDataset::Downloaders, self::DAY, self::DAY)?->format('H:i');
    }

    private function downloaderSnapshot(Platform $platform, string $cutoffAt, int $total, bool $isComplete = true): DashboardSnapshot
    {
        return DashboardSnapshot::factory()->downloaders([self::DAY => $total])->create([
            'platform_id' => $platform->id,
            'cutoff_at' => $cutoffAt,
            'is_complete' => $isComplete,
            'recorded_at' => $cutoffAt,
        ]);
    }

    /**
     * Records the state stored at the cutoff the way a checkpoint job would: created-date metrics and
     * paid-date metrics of the period, side by side in one payload.
     */
    private function transactionSnapshot(Platform $platform, string $cutoffAt, bool $isComplete): DashboardSnapshot
    {
        return DashboardSnapshot::factory()->create([
            'platform_id' => $platform->id,
            'dataset' => DashboardDataset::Transactions,
            'cutoff_at' => $cutoffAt,
            'is_complete' => $isComplete,
            'payload' => [
                ...$this->metrics($platform, $cutoffAt, TransactionDateBasis::Created),
                ...$this->paidMetrics($platform, $cutoffAt),
            ],
            'recorded_at' => $cutoffAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paidMetrics(Platform $platform, string $cutoffAt): array
    {
        return $this->metrics($platform, $cutoffAt, TransactionDateBasis::Paid);
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(Platform $platform, string $cutoffAt, TransactionDateBasis $basis): array
    {
        $rows = Transaction::where('platform_id', $platform->id)->get()->map(fn (Transaction $transaction) => [
            'platform_id' => $transaction->platform_id,
            'markaz_transaction_id' => $transaction->markaz_transaction_id,
            'markaz_ref_id' => $transaction->markaz_ref_id,
            'markaz_customer_id' => $transaction->markaz_customer_id,
            'markaz_created_at' => $transaction->markaz_created_at,
            'paid_date' => $transaction->paid_date,
            'paid_time' => $transaction->paid_time,
            'source_status' => $transaction->source_status,
            'effective_status' => $transaction->effective_status,
            'is_claim_coupon' => $transaction->is_claim_coupon,
            'nominal' => $transaction->nominal,
            'net_nominal' => $transaction->net_nominal,
        ]);

        $filter = new AggregationFilter([$platform->id], self::DAY, self::DAY, $basis, $cutoffAt);

        return (new TransactionAggregationService)->aggregate($rows, $filter)['metrics'];
    }

    private function transaction(Platform $platform, string $createdAt, MarkazTransactionStatus $status, ?string $paidAt = null, int $customer = 9): Transaction
    {
        return Transaction::factory()->create([
            'platform_id' => $platform->id,
            'markaz_customer_id' => $customer,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paidAt === null ? null : substr($paidAt, 0, 10),
            'paid_time' => $paidAt === null ? null : substr($paidAt, 11),
            'source_status' => $status->value,
            'status_message' => $status->label(),
            'nominal' => 182000,
            'admin_fee' => 2000,
            'net_nominal' => 180000,
        ]);
    }

    private function snapshotAt(Platform $platform, string $cutoffAt, DashboardDataset $dataset = DashboardDataset::Transactions): DashboardSnapshot
    {
        return DashboardSnapshot::where('platform_id', $platform->id)
            ->forPeriod($dataset, self::DAY, self::DAY)
            ->where('cutoff_at', $cutoffAt)
            ->sole();
    }
}
