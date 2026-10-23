<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoSource;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCodeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-analysis/codes';

    private const DETAIL_URL = '/api/promo-analysis/codes/usages';

    private const SEPTEMBER = '?year=2026&month=9';

    private Account $manager;

    private Platform $jadiasn;

    private Platform $cerebrum;

    private PromoCode $appCode;

    protected function setUp(): void
    {
        parent::setUp();

        // Codes are registered before the transactions, so the transactions get their metadata snapshot.
        $this->travelTo(CarbonImmutable::create(2026, 8, 1, 8));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
    }

    private function register(string $code, string $funnel, string $source): PromoCode
    {
        return app(PromoCodeService::class)->register(
            $this->jadiasn->id,
            $code,
            Funnel::where('name', $funnel)->value('id'),
            PromoSource::where('name', $source)->value('id'),
        );
    }

    /**
     * @param  list<string>|null  $codes
     */
    private function transaction(?array $codes, float $nominal, string $paidDate, string $paidTime = '10:00:00', ?Platform $platform = null): Transaction
    {
        return Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'paid_time' => $paidTime,
            'promo_code' => $codes,
            'nominal' => $nominal,
        ]);
    }

    /**
     * September 2026: 6 transactions (2.100.000) with 7 code usages.
     */
    private function seedCodeData(): void
    {
        $this->appCode = $this->register('APP26', 'Aplikasi', 'Instagram');
        $this->register('RESELLER01', 'Sales', 'Reseller');

        $this->transaction(['APP26'], 100000, '2026-09-05');
        // Two codes: one usage each; the main code gets Rp0 and the second code the full nominal.
        $this->transaction(['APP26', 'RESELLER01'], 200000, '2026-09-10', '09:00:00');
        $this->transaction(['', 'RESELLER01'], 300000, '2026-09-10', '11:00:00');
        // The same unregistered code twice in one transaction: two usages of one transaction.
        $this->transaction(['UNREG26', ' unreg26 '], 400000, '2026-09-12');
        $this->transaction(['UNREG26'], 500000, '2026-09-12', platform: $this->cerebrum);
        $this->transaction(null, 600000, '2026-09-20');
    }

    private function summary(string $query = self::SEPTEMBER): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.$query)->assertOk()->json();
    }

    private function detail(string $code, ?Platform $platform = null, string $query = self::SEPTEMBER): array
    {
        return $this->actingAs($this->manager)
            ->getJson(self::DETAIL_URL.$query.'&platform_id='.($platform ?? $this->jadiasn)->id.'&code='.urlencode($code))
            ->assertOk()
            ->json();
    }

    /**
     * @return list<array{0: string, 1: string, 2: int, 3: int|float, 4: int|float}> [code, platform, usages, revenue, contribution]
     */
    private function rows(array $summary): array
    {
        return array_map(fn (array $row) => [$row['code'], $row['platform']['slug'], $row['usages'], $row['revenue'], $row['contribution_percentage']], $summary['data']);
    }

    public function test_guest_cannot_view_the_code_summary_or_detail(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson(self::DETAIL_URL)->assertUnauthorized();
    }

    public function test_usages_and_attributed_revenue_per_code(): void
    {
        $this->seedCodeData();

        $summary = $this->summary();

        // Sorted by revenue by default, then by code.
        $this->assertEquals([
            ['RESELLER01', 'jadiasn', 2, 500000, 23.81],
            ['UNREG26', 'cerebrum', 1, 500000, 23.81],
            ['UNREG26', 'jadiasn', 2, 400000, 19.05],
            ['APP26', 'jadiasn', 2, 100000, 4.76],
        ], $this->rows($summary));

        $this->assertEquals(['transactions' => 6, 'revenue' => 2100000, 'usages' => 7, 'code_revenue' => 1500000], $summary['totals']);
        $this->assertSame(4, $summary['meta']['total']);

        $app = $summary['data'][3];
        $this->assertSame([true, $this->appCode->id, ['APP26']], [$app['is_registered'], $app['promo_code_id'], $app['raw_codes']]);
        $this->assertSame(
            [['id' => Funnel::where('name', 'Aplikasi')->value('id'), 'name' => 'Aplikasi'], ['id' => PromoSource::where('name', 'Instagram')->value('id'), 'name' => 'Instagram']],
            [$app['funnel'], $app['source']],
        );
        $this->assertSame(['platform_id' => $this->jadiasn->id, 'code' => 'APP26'], $app['detail']);
        $this->assertArrayNotHasKey('type', $app);

        // Unregistered codes stay in the rekap, with every raw value Markaz sent.
        $unregistered = $summary['data'][2];
        $this->assertSame([false, null, [' unreg26 ', 'UNREG26'], null, null], [
            $unregistered['is_registered'], $unregistered['promo_code_id'], $unregistered['raw_codes'], $unregistered['funnel'], $unregistered['source'],
        ]);
    }

    public function test_a_code_is_split_by_the_historical_metadata_of_each_usage(): void
    {
        $this->seedCodeData();
        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 8));
        app(PromoCodeService::class)->updateMetadata($this->appCode, Funnel::where('name', 'Marketing')->value('id'), $this->appCode->source_id);
        $this->transaction(['APP26'], 50000, '2026-09-20');

        $app = collect($this->summary(self::SEPTEMBER.'&search=app26')['data'])
            ->map(fn (array $row) => [$row['funnel']['name'], $row['usages'], $row['revenue']])
            ->sortBy(0)
            ->values()
            ->all();

        $this->assertEquals([['Aplikasi', 2, 100000], ['Marketing', 1, 50000]], $app);
    }

    public function test_sorting_and_pagination(): void
    {
        $this->seedCodeData();

        $this->assertSame(
            ['APP26', 'RESELLER01', 'UNREG26', 'UNREG26'],
            array_column($this->summary(self::SEPTEMBER.'&sort=code&direction=asc')['data'], 'code'),
        );
        $this->assertSame(['cerebrum', 'jadiasn'], array_map(
            fn (array $row) => $row['platform']['slug'],
            array_slice($this->summary(self::SEPTEMBER.'&sort=code&direction=asc')['data'], 2),
        ));
        $this->assertSame(['APP26', 'UNREG26'], array_column($this->summary(self::SEPTEMBER.'&sort=revenue&direction=asc&per_page=2')['data'], 'code'));
        $this->assertSame('RESELLER01', $this->summary(self::SEPTEMBER.'&sort=source&direction=desc')['data'][0]['code']);

        $page = $this->summary(self::SEPTEMBER.'&per_page=3&page=2');
        $this->assertSame([['APP26'], 2, 4], [array_column($page['data'], 'code'), $page['meta']['current_page'], $page['meta']['total']]);

        $this->actingAs($this->manager)->getJson(self::URL.'?sort=type')
            ->assertUnprocessable()->assertJsonValidationErrors(['sort' => 'Urutan tidak valid.']);
        $this->getJson(self::URL.'?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page' => 'Jumlah per halaman harus di antara 1 dan 100.']);
    }

    public function test_search_and_shared_filters_apply(): void
    {
        $this->seedCodeData();

        // Only matching codes; the share still uses every filtered transaction's revenue.
        $this->assertEquals(
            [['UNREG26', 'cerebrum', 1, 500000, 55.56], ['UNREG26', 'jadiasn', 2, 400000, 44.44]],
            $this->rows($this->summary(self::SEPTEMBER.'&search=unreg')),
        );
        $this->assertSame([['UNREG26', 'cerebrum']], array_map(
            fn (array $row) => [$row['code'], $row['platform']['slug']],
            $this->summary(self::SEPTEMBER.'&platforms[]=cerebrum')['data'],
        ));
        $this->assertSame(0, $this->summary('?year=2026&month=10')['meta']['total']);
        $this->assertTrue($this->summary('?year=2026&month=10')['is_empty']);
        $this->getJson(self::URL.'?years[]=2026')->assertUnprocessable()->assertJsonValidationErrors('years');
    }

    public function test_detail_lists_the_transactions_of_a_code_grouped_per_date(): void
    {
        $this->seedCodeData();

        $detail = $this->detail('RESELLER01');

        $this->assertSame(['code' => 'RESELLER01', 'is_registered' => true, 'platform' => ['id' => $this->jadiasn->id, 'slug' => 'jadiasn', 'name' => 'JADIASN']], $detail['code']);
        $this->assertEquals(['transactions' => 2, 'usages' => 2, 'revenue' => 500000], $detail['totals']);
        $this->assertCount(1, $detail['data']);

        $day = $detail['data'][0];
        $this->assertEquals(['2026-09-10', 2, 2, 500000], [$day['date'], $day['transactions_count'], $day['usages'], $day['revenue']]);

        // Newest transaction first; both have the reseller code in position 2, so it gets the full nominal.
        $this->assertEquals([
            ['11:00:00', [2], ['RESELLER01'], 1, 300000, 300000],
            ['09:00:00', [2], ['RESELLER01'], 1, 200000, 200000],
        ], array_map(fn (array $row) => [$row['paid_time'], $row['code_positions'], $row['raw_codes'], $row['usages'], $row['revenue'], $row['transaction_nominal']], $day['transactions']));
        $this->assertSame(['Sales', 'Reseller', 'JADIASN'], [$day['transactions'][0]['funnel']['name'], $day['transactions'][0]['source']['name'], $day['transactions'][0]['platform']['name']]);
        $this->assertMatchesRegularExpression('/^markaz-\d+$/', $day['transactions'][0]['trx_id']);
    }

    public function test_detail_of_a_main_code_and_an_unregistered_code(): void
    {
        $this->seedCodeData();

        // As the main code of a two-code transaction APP26 gets one usage and Rp0 there.
        $app = $this->detail('APP26');
        $this->assertEquals([['2026-09-10', 1, 0], ['2026-09-05', 1, 100000]], array_map(fn (array $day) => [$day['date'], $day['usages'], $day['revenue']], $app['data']));
        $this->assertSame([1], $app['data'][0]['transactions'][0]['code_positions']);

        // Searched as typed; the code is normalized. One transaction with the code twice.
        $unregistered = $this->detail(' unreg26 ');
        $this->assertSame(['UNREG26', false], [$unregistered['code']['code'], $unregistered['code']['is_registered']]);
        $this->assertEquals(['transactions' => 1, 'usages' => 2, 'revenue' => 400000], $unregistered['totals']);
        $row = $unregistered['data'][0]['transactions'][0];
        $this->assertEquals([[1, 2], ['UNREG26', ' unreg26 '], 2, 400000, null], [$row['code_positions'], $row['raw_codes'], $row['usages'], $row['revenue'], $row['funnel']]);
    }

    public function test_detail_totals_match_the_rekap_and_days_keep_their_totals_across_pages(): void
    {
        $this->seedCodeData();
        $this->transaction(['RESELLER01'], 150000, '2026-09-10', '15:00:00');

        $rekap = collect($this->summary()['data'])->firstWhere('code', 'RESELLER01');
        $detail = $this->detail('RESELLER01', query: self::SEPTEMBER.'&per_page=2');

        $this->assertEquals([$rekap['usages'], $rekap['revenue']], [$detail['totals']['usages'], $detail['totals']['revenue']]);
        // The page shows two of the three transactions of that date, but the date keeps the whole day's totals.
        $this->assertEquals([3, 3, 650000, 2], [
            $detail['data'][0]['transactions_count'], $detail['data'][0]['usages'], $detail['data'][0]['revenue'], count($detail['data'][0]['transactions']),
        ]);
        $this->assertSame([3, 2, 2], [$detail['meta']['total'], $detail['meta']['per_page'], $detail['meta']['last_page']]);
    }

    public function test_detail_uses_the_shared_filters_and_validates_the_code(): void
    {
        $this->seedCodeData();

        $october = $this->detail('RESELLER01', query: '?year=2026&month=10');
        $this->assertSame([true, [], 0], [$october['is_empty'], $october['data'], $october['totals']['usages']]);

        // Like the rekap, a search only keeps the matching codes, so another code's detail is empty.
        $this->assertSame(0, $this->detail('RESELLER01', query: self::SEPTEMBER.'&search=app26')['totals']['usages']);

        Transaction::factory()->for($this->jadiasn)->withoutPaymentMethod()->create(['paid_date' => '2026-09-25', 'promo_code' => ['APP26']]);
        $this->assertSame(1, $this->detail('APP26', query: self::SEPTEMBER.'&payment_methods[]=Tidak%20diketahui')['totals']['transactions']);

        $this->actingAs($this->manager)->getJson(self::DETAIL_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform_id' => 'Platform wajib diisi.', 'code' => 'Kode promo wajib diisi.']);
        $this->getJson(self::DETAIL_URL.'?platform_id=999&code=APP26')
            ->assertUnprocessable()->assertJsonValidationErrors(['platform_id' => 'Platform tidak ditemukan.']);
    }
}
