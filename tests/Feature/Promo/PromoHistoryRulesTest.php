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
        $this->assertSame($original['funnel_id_snapshot'], $transaction->promoCodeUsages()->first()->funnel_id_snapshot);
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
}
