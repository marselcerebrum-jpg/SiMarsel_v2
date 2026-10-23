<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoFunnelBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-analysis/funnels';

    private const SEPTEMBER = '?year=2026&month=9';

    private Account $manager;

    private Platform $jadiasn;

    private Platform $cerebrum;

    protected function setUp(): void
    {
        parent::setUp();

        // Codes are registered before the transactions, so the transactions get their funnel snapshot.
        $this->travelTo(CarbonImmutable::create(2026, 8, 1, 8));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
    }

    private function register(string $code, ?string $funnel): PromoCode
    {
        return app(PromoCodeService::class)->register($this->jadiasn->id, $code, $funnel ? Funnel::where('name', $funnel)->value('id') : null);
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
     * September 2026: 7 transactions, 2.800.000 revenue.
     */
    private function seedFunnelData(): void
    {
        $this->register('APP26', 'Aplikasi');
        $this->register('MKT26', 'Marketing');
        $this->register('RESELLER01', 'Sales');
        $this->register('NOFUNNEL26', null);

        $this->transaction(['APP26'], 100000);
        // Two codes: the funnel and the full revenue follow the code in position 1, not the reseller code.
        $this->transaction(['APP26', 'RESELLER01'], 200000);
        // A reseller code on its own decides the funnel, also when Markaz leaves position 1 empty.
        $this->transaction(['RESELLER01'], 300000);
        $this->transaction(['', 'RESELLER01'], 400000);
        // Position 1 without a funnel is Tanpa Funnel, even when position 2 has one; so is an unregistered code.
        $this->transaction(['NOFUNNEL26', 'MKT26'], 500000);
        $this->transaction(['UNREG26'], 600000);
        $this->transaction(null, 700000);

        $this->transaction(['MKT26'], 999999, status: 'failed');
        $this->transaction(['MKT26'], 999999, '2026-10-01');
    }

    private function breakdown(string $query = self::SEPTEMBER): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.$query)->assertOk()->json('data');
    }

    /**
     * @return array<string, array{0: int, 1: int|float, 2: int|float}> Name => [transactions, revenue, contribution].
     */
    private function rows(array $breakdown): array
    {
        return collect($breakdown['funnels'])
            ->mapWithKeys(fn (array $row) => [$row['name'] => [$row['transactions'], $row['revenue'], $row['contribution_percentage']]])
            ->all();
    }

    public function test_guest_cannot_view_the_funnel_breakdown(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_each_transaction_falls_in_the_funnel_of_its_main_code(): void
    {
        $this->seedFunnelData();

        $breakdown = $this->breakdown();

        $this->assertEquals([
            'Aplikasi' => [2, 300000, 10.71],
            'Marketing' => [0, 0, 0],
            'Sales' => [2, 700000, 25],
            'Ads' => [0, 0, 0],
            'Live' => [0, 0, 0],
            'Lainnya' => [0, 0, 0],
            'Tanpa Funnel' => [2, 1100000, 39.29],
            'Tanpa Kode' => [1, 700000, 25],
        ], $this->rows($breakdown));

        $this->assertEquals(['transactions' => 7, 'revenue' => 2800000], $breakdown['totals']);
        $this->assertSame(
            ['funnel', 'funnel', 'funnel', 'funnel', 'funnel', 'funnel', 'no_funnel', 'no_code'],
            array_column($breakdown['funnels'], 'key'),
        );
        $this->assertSame(
            ['id' => Funnel::where('name', 'Aplikasi')->value('id'), 'name' => 'Aplikasi'],
            $breakdown['funnels'][0]['funnel'],
        );
        $this->assertNull($breakdown['funnels'][6]['funnel']);
    }

    public function test_groups_add_up_to_every_filtered_transaction_like_the_kpi_summary(): void
    {
        $this->seedFunnelData();

        $breakdown = $this->breakdown();
        $kpis = $this->getJson('/api/promo-analysis/summary'.self::SEPTEMBER)->json('data.kpis');

        $this->assertSame($breakdown['totals']['transactions'], array_sum(array_column($breakdown['funnels'], 'transactions')));
        $this->assertEquals($breakdown['totals']['revenue'], array_sum(array_column($breakdown['funnels'], 'revenue')));
        $this->assertEquals(
            $kpis['promo_revenue']['value'] + $kpis['non_promo_revenue']['value'],
            $breakdown['totals']['revenue'],
        );
        $this->assertSame(
            $kpis['promo_transactions']['value'] + $kpis['non_promo_transactions']['value'],
            $breakdown['totals']['transactions'],
        );
    }

    public function test_a_renamed_funnel_keeps_its_transactions_under_the_current_name(): void
    {
        $this->seedFunnelData();
        Funnel::where('name', 'Aplikasi')->first()->update(['name' => 'Aplikasi Mobile']);

        $this->assertSame([2, 300000], array_slice($this->rows($this->breakdown())['Aplikasi Mobile'], 0, 2));
    }

    public function test_a_funnel_removed_from_the_master_is_shown_under_its_snapshot_name(): void
    {
        $webinar = Funnel::factory()->create(['name' => 'Webinar']);
        $promoCode = app(PromoCodeService::class)->register($this->jadiasn->id, 'WEB26', $webinar->id);
        $this->transaction(['WEB26'], 250000);
        $this->transaction(['UNREG26'], 750000);

        $promoCode->update(['funnel_id' => null]);
        $webinar->delete();

        $breakdown = $this->breakdown();
        $row = collect($breakdown['funnels'])->firstWhere('key', 'deleted_funnel');

        $this->assertSame(['deleted_funnel', null, 'Webinar', 1], [$row['key'], $row['funnel'], $row['name'], $row['transactions']]);
        $this->assertEquals([250000, 25], [$row['revenue'], $row['contribution_percentage']]);
        $this->assertSame([1, 750000], array_slice($this->rows($breakdown)['Tanpa Funnel'], 0, 2));
        $this->assertSame('no_funnel', $breakdown['funnels'][count($breakdown['funnels']) - 2]['key']);
    }

    public function test_shared_promo_analysis_filters_apply(): void
    {
        $this->seedFunnelData();
        $this->transaction(['APP26'], 50000, platform: $this->cerebrum);

        // A search narrows the transactions; the funnel still follows each transaction's main code.
        $search = $this->rows($this->breakdown(self::SEPTEMBER.'&search=reseller'));
        $this->assertSame([[1, 2, 0, 0], 900000], [
            [$search['Aplikasi'][0], $search['Sales'][0], $search['Tanpa Funnel'][0], $search['Tanpa Kode'][0]],
            $search['Aplikasi'][1] + $search['Sales'][1],
        ]);

        // APP26 is only registered on JADIASN, so on CEREBRUM it is an unregistered code.
        $cerebrum = $this->breakdown(self::SEPTEMBER.'&platforms[]=cerebrum');
        $this->assertSame([0, 1], [$this->rows($cerebrum)['Aplikasi'][0], $this->rows($cerebrum)['Tanpa Funnel'][0]]);
        $this->assertSame(['cerebrum'], $cerebrum['filter']['platforms']);

        $this->assertSame(1, $this->rows($this->breakdown('?year=2026&month=10'))['Marketing'][0]);

        Transaction::factory()->for($this->jadiasn)->withoutPaymentMethod()->create(['paid_date' => '2026-09-25', 'promo_code' => ['MKT26']]);
        $unknownMethod = $this->breakdown('?date_from=2026-09-01&date_to=2026-09-30&payment_methods[]=Tidak%20diketahui');
        $this->assertSame([1, 1], [$unknownMethod['totals']['transactions'], $this->rows($unknownMethod)['Marketing'][0]]);

        $this->getJson(self::URL.'?years[]=2026')->assertUnprocessable()->assertJsonValidationErrors('years');
    }

    public function test_empty_result_lists_every_group_with_zero(): void
    {
        $breakdown = $this->breakdown();

        $this->assertTrue($breakdown['is_empty']);
        $this->assertSame('Data tidak tersedia untuk filter yang dipilih.', $breakdown['message']);
        $this->assertCount(8, $breakdown['funnels']);
        $this->assertSame([0], array_values(array_unique(array_column($breakdown['funnels'], 'transactions'))));
        $this->assertSame([0], array_values(array_unique(array_column($breakdown['funnels'], 'contribution_percentage'))));
    }
}
