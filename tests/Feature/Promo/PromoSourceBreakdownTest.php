<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoSource;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PromoSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoSourceBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-analysis/sources';

    private const SEPTEMBER = '?year=2026&month=9';

    private Account $manager;

    private Platform $jadiasn;

    private Platform $cerebrum;

    protected function setUp(): void
    {
        parent::setUp();

        // Codes are registered before the transactions, so the transactions get their source snapshot.
        $this->travelTo(CarbonImmutable::create(2026, 8, 1, 8));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
    }

    private function register(string $code, ?string $source): PromoCode
    {
        return app(PromoCodeService::class)->register($this->jadiasn->id, $code, null, $source ? PromoSource::where('name', $source)->value('id') : null);
    }

    /**
     * @param  list<string>|null  $codes
     */
    private function transaction(?array $codes, float $nominal, string $paidDate = '2026-09-10', ?Platform $platform = null, string $status = Transaction::STATUS_SUCCESS): void
    {
        Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'promo_code' => $codes,
            'nominal' => $nominal,
            'status_message' => $status,
        ]);
    }

    /**
     * September 2026: 6 transactions (2.100.000), 7 code usages, 600.000 of it without a code.
     */
    private function seedSourceData(): void
    {
        $this->register('APP26', 'Instagram');
        $this->register('RESELLER01', 'Reseller');
        $this->register('TT26', 'TikTok');
        $this->register('NOSRC26', null);

        $this->transaction(['APP26'], 100000);
        // Two codes: both add a usage; the main code gets Rp0 and the reseller code the full nominal.
        $this->transaction(['APP26', 'RESELLER01'], 200000);
        // A code on its own gets the full nominal, also when Markaz leaves position 1 empty.
        $this->transaction(['', 'RESELLER01'], 300000);
        // A code without a source, or an unregistered code, is Tanpa Sumber.
        $this->transaction(['TT26', 'NOSRC26'], 400000);
        $this->transaction(['UNREG26'], 500000);
        // No code: no usage and no source revenue, but still in the revenue total.
        $this->transaction(null, 600000);

        $this->transaction(['APP26'], 999999, status: 'failed');
        $this->transaction(['TT26'], 999999, '2026-10-01');
    }

    private function breakdown(string $query = self::SEPTEMBER): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.$query)->assertOk()->json('data');
    }

    /**
     * @return array<string, array{0: int, 1: int|float, 2: int|float}> Name => [usages, revenue, contribution].
     */
    private function rows(array $breakdown): array
    {
        return collect($breakdown['sources'])
            ->mapWithKeys(fn (array $row) => [$row['name'] => [$row['usages'], $row['revenue'], $row['contribution_percentage']]])
            ->all();
    }

    public function test_guest_cannot_view_the_source_breakdown(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_usages_and_revenue_are_credited_to_the_source_of_each_code(): void
    {
        $this->seedSourceData();
        PromoSource::factory()->create(['name' => 'Podcast']);

        $breakdown = $this->breakdown();

        $this->assertEquals([
            'Aplikasi' => [0, 0, 0],
            'Instagram' => [2, 100000, 4.76],
            'TikTok' => [1, 0, 0],
            'Telegram' => [0, 0, 0],
            'Sales' => [0, 0, 0],
            'Ads' => [0, 0, 0],
            'Live' => [0, 0, 0],
            'Reseller' => [2, 500000, 23.81],
            'Lainnya' => [0, 0, 0],
            // The source list follows the master, so a new source is listed too.
            'Podcast' => [0, 0, 0],
            'Tanpa Sumber' => [2, 900000, 42.86],
        ], $this->rows($breakdown));

        // Usages may exceed the transactions; source revenue leaves out the transaction without a code.
        $this->assertEquals(['transactions' => 6, 'revenue' => 2100000, 'usages' => 7, 'source_revenue' => 1500000], $breakdown['totals']);
        $this->assertSame([...array_fill(0, 10, 'source'), 'no_source'], array_column($breakdown['sources'], 'key'));
        $this->assertSame(
            ['id' => PromoSource::where('name', 'Instagram')->value('id'), 'name' => 'Instagram'],
            $breakdown['sources'][1]['source'],
        );
        $this->assertNull($breakdown['sources'][10]['source']);
        $this->assertNotContains('Tanpa Kode', array_column($breakdown['sources'], 'name'));
    }

    public function test_revenue_total_matches_the_kpi_summary_and_source_shares_stay_below_it(): void
    {
        $this->seedSourceData();

        $breakdown = $this->breakdown();
        $kpis = $this->getJson('/api/promo-analysis/summary'.self::SEPTEMBER)->json('data.kpis');

        $this->assertEquals($kpis['promo_revenue']['value'] + $kpis['non_promo_revenue']['value'], $breakdown['totals']['revenue']);
        // Every promo transaction credits its full nominal to exactly one source.
        $this->assertEquals($kpis['promo_revenue']['value'], $breakdown['totals']['source_revenue']);
        $this->assertEquals($breakdown['totals']['source_revenue'], array_sum(array_column($breakdown['sources'], 'revenue')));
        $this->assertEqualsWithDelta(71.43, array_sum(array_column($breakdown['sources'], 'contribution_percentage')), 0.01);
    }

    public function test_a_search_counts_only_the_matching_codes(): void
    {
        $this->seedSourceData();

        // The reseller code keeps the full nominal of both its transactions; the main code APP26 is not counted.
        $reseller = $this->rows($this->breakdown(self::SEPTEMBER.'&search=reseller'));
        $this->assertEquals([[2, 500000, 100], [0, 0, 0]], [$reseller['Reseller'], $reseller['Instagram']]);

        // APP26 as the main code of a two-code transaction still gets Rp0 there.
        $app = $this->breakdown(self::SEPTEMBER.'&search=app26');
        $this->assertEquals([2, 100000, 33.33], $this->rows($app)['Instagram']);
        $this->assertEquals(['transactions' => 2, 'revenue' => 300000, 'usages' => 2, 'source_revenue' => 100000], $app['totals']);
    }

    public function test_a_source_removed_from_the_master_is_shown_under_its_snapshot_name(): void
    {
        $webinar = PromoSource::factory()->create(['name' => 'Webinar']);
        $promoCode = app(PromoCodeService::class)->register($this->jadiasn->id, 'WEB26', null, $webinar->id);
        $this->transaction(['WEB26'], 250000);
        $this->transaction(['UNREG26'], 750000);

        $promoCode->update(['source_id' => null]);
        $webinar->delete();

        $breakdown = $this->breakdown();
        $row = collect($breakdown['sources'])->firstWhere('key', 'deleted_source');

        $this->assertSame(['deleted_source', null, 'Webinar', 1], [$row['key'], $row['source'], $row['name'], $row['usages']]);
        $this->assertEquals([250000, 25], [$row['revenue'], $row['contribution_percentage']]);
        $this->assertEquals([1, 750000, 75], $this->rows($breakdown)['Tanpa Sumber']);
        $this->assertSame('no_source', $breakdown['sources'][count($breakdown['sources']) - 1]['key']);
    }

    public function test_a_renamed_source_keeps_its_usages_under_the_current_name(): void
    {
        $this->seedSourceData();
        PromoSource::where('name', 'Instagram')->first()->update(['name' => 'Instagram Ads']);

        $this->assertEquals([2, 100000, 4.76], $this->rows($this->breakdown())['Instagram Ads']);
    }

    public function test_shared_promo_analysis_filters_apply(): void
    {
        $this->seedSourceData();
        $this->transaction(['APP26'], 50000, platform: $this->cerebrum);

        // APP26 is only registered on JADIASN, so on CEREBRUM it is an unregistered code.
        $cerebrum = $this->breakdown(self::SEPTEMBER.'&platforms[]=cerebrum');
        $this->assertSame([0, 1], [$this->rows($cerebrum)['Instagram'][0], $this->rows($cerebrum)['Tanpa Sumber'][0]]);
        $this->assertSame(['cerebrum'], $cerebrum['filter']['platforms']);

        $this->assertEquals([1, 999999, 100], $this->rows($this->breakdown('?year=2026&month=10'))['TikTok']);

        Transaction::factory()->for($this->jadiasn)->withoutPaymentMethod()->create(['paid_date' => '2026-09-25', 'promo_code' => ['APP26']]);
        $unknownMethod = $this->breakdown('?date_from=2026-09-01&date_to=2026-09-30&payment_methods[]=Tidak%20diketahui');
        $this->assertSame([1, 1], [$unknownMethod['totals']['transactions'], $this->rows($unknownMethod)['Instagram'][0]]);

        $this->getJson(self::URL.'?years[]=2026')->assertUnprocessable()->assertJsonValidationErrors('years');
    }

    public function test_empty_result_lists_every_source_with_zero(): void
    {
        $breakdown = $this->breakdown();

        $this->assertTrue($breakdown['is_empty']);
        $this->assertSame('Data tidak tersedia untuk filter yang dipilih.', $breakdown['message']);
        $this->assertCount(count(PromoSourceSeeder::SOURCES) + 1, $breakdown['sources']);
        $this->assertSame([0], array_values(array_unique(array_column($breakdown['sources'], 'usages'))));
        $this->assertEquals(['transactions' => 0, 'revenue' => 0, 'usages' => 0, 'source_revenue' => 0], $breakdown['totals']);
    }
}
