<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoSource;
use App\Models\Transaction;
use App\Repositories\UnregisteredPromoCodeRepository;
use App\Services\PerformanceSummaryService;
use App\Services\PromoAnalysisService;
use App\Services\PromoCodeService;
use App\Services\PromoCodeUsageService;
use App\Support\PerformanceFilter;
use App\Support\PromoAnalysisFilter;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromoHistoryRulesTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    private Funnel $funnel;

    private PromoSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00'));
        $this->platform = Platform::factory()->create();
        $this->funnel = Funnel::factory()->create(['name' => 'Marketing']);
        $this->source = PromoSource::factory()->create(['name' => 'Instagram']);
    }

    private function register(string $code = 'HEMAT', bool $metadata = true)
    {
        return app(PromoCodeService::class)->register($this->platform->id, $code,
            $metadata ? $this->funnel->id : null, $metadata ? $this->source->id : null);
    }

    private function transaction(mixed $codes = ['HEMAT'], string $status = 'Success'): Transaction
    {
        return Transaction::factory()->for($this->platform)->create([
            'promo_code' => $codes, 'paid_date' => '2026-08-10', 'paid_time' => '10:00:00',
            'status_message' => $status, 'nominal' => 100000,
        ]);
    }

    private function filter(): PromoAnalysisFilter
    {
        return new PromoAnalysisFilter(PerformanceFilter::month(2026, 8));
    }

    public function test_anomalous_updates_keep_valid_history_but_leave_all_promo_aggregates_until_recovered(): void
    {
        $this->register();
        $transaction = $this->transaction();
        $original = $transaction->promoCodeUsages()->sole()->toArray();
        $transaction->update(['promo_code' => ['HEMAT', 'RES', 'X']]);

        $this->assertTrue($transaction->fresh()->promo_code_anomaly);
        $this->assertSame($original, $transaction->promoCodeUsages()->sole()->toArray());
        $this->assertSame(['HEMAT', 'RES', 'X'], $transaction->fresh()->promo_code);

        $analysis = app(PromoAnalysisService::class);
        $kpis = $analysis->summarize($this->filter())['kpis'];
        $this->assertSame(0, $kpis['promo_transactions']['value']);
        $this->assertSame(0, $kpis['non_promo_transactions']['value']);
        $this->assertSame(0, $kpis['used_promo_codes']['value']);
        $this->assertSame(0, $analysis->funnelBreakdown($this->filter())['totals']['transactions']);
        $this->assertSame(0, $analysis->sourceBreakdown($this->filter())['totals']['usages']);
        $general = app(PerformanceSummaryService::class)->summarize($this->filter()->period);
        $this->assertSame(1, $general['kpis']['total_transactions']['value']);

        $transaction->update(['promo_code' => ['HEMAT', 'RES']]);
        $this->assertFalse($transaction->fresh()->promo_code_anomaly);
        $this->assertSame($original['id'], $transaction->promoCodeUsages()->first()->id);
        $this->assertSame($this->funnel->id, $transaction->promoCodeUsages()->first()->funnel_id_snapshot);
        app(PromoCodeUsageService::class)->normalize($transaction->fresh());
        $this->assertSame(2, $transaction->promoCodeUsages()->count());
        $this->assertSame(1, $analysis->summarize($this->filter())['kpis']['promo_transactions']['value']);
    }

    public function test_new_anomalies_and_invalid_formats_are_not_no_code_transactions(): void
    {
        $this->transaction(['A', 'B', 'C']);
        $this->transaction(['code' => 'A']);
        $this->assertSame(2, Transaction::where('promo_code_anomaly', true)->count());
        $kpis = app(PromoAnalysisService::class)->summarize($this->filter())['kpis'];
        $this->assertSame(0, $kpis['non_promo_transactions']['value']);
        $this->assertSame(0, app(UnregisteredPromoCodeRepository::class)->paginate(null, null, 20)->total());
    }

    public function test_invalid_updates_keep_unknown_history_out_of_alerts_and_registration_backfill(): void
    {
        $transaction = $this->transaction(['UNKNOWN']);
        $transaction->update(['promo_code' => ['UNKNOWN', 'RES', 'X']]);
        $this->assertSame(1, $transaction->promoCodeUsages()->count());
        $this->assertSame(0, app(UnregisteredPromoCodeRepository::class)->paginate(null, null, 20)->total());
        $this->assertTrue(app(UnregisteredPromoCodeRepository::class)->rawCodes([
            ['platform_id' => $this->platform->id, 'code' => 'UNKNOWN'],
        ])->isEmpty());
        $this->register('UNKNOWN');
        $this->assertNull($transaction->promoCodeUsages()->sole()->promo_code_id);
        $transaction->update(['promo_code' => ['UNKNOWN']]);
        $this->assertNotNull($transaction->promoCodeUsages()->sole()->promo_code_id);
    }

    public function test_late_transaction_uses_current_master_and_identical_resync_keeps_that_snapshot(): void
    {
        $code = $this->register();
        $transaction = $this->transaction();
        $this->assertSame($this->funnel->id, $transaction->promoCodeUsages()->sole()->funnel_id_snapshot);
        $this->travelTo(CarbonImmutable::parse('2026-09-02 08:00:00'));
        $sales = Funnel::factory()->create(['name' => 'Sales']);
        app(PromoCodeService::class)->updateMetadata($code, $sales->id, null);
        $transaction->update(['paid_time' => '12:00:00']);
        $this->assertSame($this->funnel->id, $transaction->promoCodeUsages()->sole()->funnel_id_snapshot);
        $new = $this->transaction();
        $this->assertSame($sales->id, $new->promoCodeUsages()->sole()->funnel_id_snapshot);
    }

    public function test_empty_and_partial_mapped_snapshots_stay_frozen_after_master_is_completed(): void
    {
        $code = $this->register(metadata: false);
        $empty = $this->transaction();
        $this->travelTo(CarbonImmutable::parse('2026-09-02 08:00:00'));
        app(PromoCodeService::class)->updateMetadata($code, $this->funnel->id, null);
        $partial = $this->transaction();
        $this->travelTo(CarbonImmutable::parse('2026-09-03 08:00:00'));
        app(PromoCodeService::class)->updateMetadata($code, $this->funnel->id, $this->source->id);

        foreach ([$empty, $partial] as $transaction) {
            $transaction->update(['paid_date' => '2026-09-04']);
            app(PromoCodeUsageService::class)->normalize($transaction->fresh());
        }
        $this->assertNull($empty->promoCodeUsages()->sole()->funnel_id_snapshot);
        $this->assertNull($empty->promoCodeUsages()->sole()->source_id_snapshot);
        $this->assertSame($this->funnel->id, $partial->promoCodeUsages()->sole()->funnel_id_snapshot);
        $this->assertNull($partial->promoCodeUsages()->sole()->source_id_snapshot);
        $this->assertSame($this->source->id, $this->transaction()->promoCodeUsages()->sole()->source_id_snapshot);
    }

    public function test_success_filter_handles_both_status_transitions_and_backfill(): void
    {
        $transaction = $this->transaction(['UNKNOWN'], 'failed');
        $repository = app(UnregisteredPromoCodeRepository::class);
        $this->assertSame(0, $transaction->promoCodeUsages()->count());
        $transaction->update(['status_message' => 'Success']);
        $this->assertSame(1, $repository->paginate(null, null, 20)->total());
        $transaction->update(['status_message' => 'failed']);
        $this->assertSame(0, $repository->paginate(null, null, 20)->total());
        $this->assertTrue($repository->rawCodes([
            ['platform_id' => $this->platform->id, 'code' => 'UNKNOWN'],
        ])->isEmpty());
        $this->register('UNKNOWN');
        $this->assertNull($transaction->promoCodeUsages()->sole()->promo_code_id);
        $transaction->update(['status_message' => 'Success']);
        $this->assertSame($this->funnel->id, $transaction->promoCodeUsages()->sole()->funnel_id_snapshot);
        $this->assertSame(0, $repository->paginate(null, null, 20)->total());
    }

    public function test_second_position_alone_has_source_and_full_revenue_but_no_funnel(): void
    {
        $this->register('RES');
        $transaction = $this->transaction(['', 'RES']);
        $this->assertSame(2, $transaction->promoCodeUsages()->sole()->code_position);
        $analysis = app(PromoAnalysisService::class);
        $groups = $analysis->funnelBreakdown($this->filter());
        $this->assertSame(1, collect($groups['funnels'])->firstWhere('key', 'no_funnel')['transactions']);
        $this->assertSame(0, collect($groups['funnels'])->firstWhere('key', 'no_code')['transactions']);
        $source = collect($analysis->sourceBreakdown($this->filter())['sources'])->firstWhere('name', 'Instagram');
        $this->assertSame(1, $source['usages']);
        $this->assertEquals(100000, $source['revenue']);
    }

    public function test_renaming_both_masters_changes_report_labels_without_mutating_snapshots(): void
    {
        $this->register();
        $transaction = $this->transaction();
        $this->funnel->update(['name' => 'Digital Marketing']);
        $this->source->update(['name' => 'Instagram Organic']);
        $usage = $transaction->promoCodeUsages()->sole();
        $this->assertSame('Marketing', $usage->funnel_name_snapshot);
        $this->assertSame('Instagram', $usage->source_name_snapshot);
        $analysis = app(PromoAnalysisService::class);
        $this->assertSame(1, collect($analysis->funnelBreakdown($this->filter())['funnels'])->firstWhere('name', 'Digital Marketing')['transactions']);
        $this->assertSame(1, collect($analysis->sourceBreakdown($this->filter())['sources'])->firstWhere('name', 'Instagram Organic')['usages']);
        $new = $this->transaction()->promoCodeUsages()->sole();
        $this->assertSame('Digital Marketing', $new->funnel_name_snapshot);
        $this->assertSame('Instagram Organic', $new->source_name_snapshot);
    }

    public function test_normalization_does_not_modify_source_timestamps(): void
    {
        $transaction = $this->transaction();
        $timestamps = [$transaction->created_at, $transaction->updated_at];
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
        app(PromoCodeUsageService::class)->normalize($transaction);
        $this->assertEquals($timestamps, [$transaction->fresh()->created_at, $transaction->fresh()->updated_at]);
    }

    public function test_deployment_marks_existing_anomalies_without_reclassifying_history_or_source_timestamps(): void
    {
        $this->register();
        $transaction = $this->transaction();
        $snapshot = $transaction->promoCodeUsages()->sole()->toArray();
        $timestamps = [$transaction->created_at, $transaction->updated_at];
        DB::table('transactions')->where('id', $transaction->id)->update(['promo_code' => json_encode(['HEMAT', 'RES', 'X'])]);
        $migration = require database_path('migrations/2026_10_30_000001_add_promo_code_anomaly_to_transactions.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($transaction->fresh()->promo_code_anomaly);
        $this->assertEquals($timestamps, [$transaction->fresh()->created_at, $transaction->fresh()->updated_at]);
        $this->assertSame($snapshot, $transaction->promoCodeUsages()->sole()->toArray());
    }

    public function test_bulk_echoes_stable_ids_and_rejects_invalid_metadata_without_blocking_valid_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $account = Account::where('username', 'manager')->firstOrFail();
        $response = $this->actingAs($account)->postJson('/api/promo-codes/bulk', ['rows' => [
            ['client_row_id' => 'bad', 'code' => 'BAD', 'platform_id' => $this->platform->id, 'funnel_id' => 'Marketng'],
            ['client_row_id' => 'good', 'code' => 'GOOD', 'platform_id' => $this->platform->id, 'funnel_id' => null],
        ]])->assertOk();
        $response->assertJsonPath('data.rows.0.client_row_id', 'bad')
            ->assertJsonPath('data.rows.0.status', 'failed')
            ->assertJsonPath('data.rows.1.client_row_id', 'good')
            ->assertJsonPath('data.rows.1.status', 'created');
        $this->assertDatabaseMissing('promo_codes', ['code' => 'BAD']);
        $this->assertDatabaseHas('promo_codes', ['code' => 'GOOD', 'funnel_id' => null]);
    }

    public function test_bulk_rejects_duplicate_or_structured_correlation_ids(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(Account::where('username', 'manager')->firstOrFail());
        $this->postJson('/api/promo-codes/bulk', ['rows' => [
            ['client_row_id' => 'same', 'code' => 'A', 'platform_id' => $this->platform->id],
            ['client_row_id' => 'same', 'code' => 'B', 'platform_id' => $this->platform->id],
        ]])->assertUnprocessable()->assertJsonValidationErrors('rows.0.client_row_id');
        $this->postJson('/api/promo-codes/bulk', ['rows' => [
            ['client_row_id' => ['unexpected'], 'code' => 'A', 'platform_id' => $this->platform->id],
        ]])->assertUnprocessable()->assertJsonValidationErrors('rows.0.client_row_id');
    }
}
