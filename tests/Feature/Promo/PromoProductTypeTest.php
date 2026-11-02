<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Platform;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoProductTypeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-analysis/product-types';

    private const SEPTEMBER = '?year=2026&month=9';

    private Account $manager;

    private Platform $jadiasn;

    private Platform $cerebrum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 8, 1, 8));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
        app(PromoCodeService::class)->register($this->jadiasn->id, 'APP26');
        $this->travelTo(CarbonImmutable::create(2026, 10, 15, 8));
    }

    /**
     * @param  list<string>|null  $codes
     */
    private function transaction(?array $codes, float $nominal, ?string $productType, ?Platform $platform = null, string $paidDate = '2026-09-10'): Transaction
    {
        return Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'promo_code' => $codes,
            'nominal' => $nominal,
            'product_type' => $productType,
        ]);
    }

    /**
     * September 2026: 6 promo transactions (1.950.000) and 1 without promo.
     */
    private function seedProductTypeData(): void
    {
        $this->transaction(['APP26'], 100000, Transaction::TYPE_FIRST);
        // Two codes: one transaction with its full nominal.
        $this->transaction(['APP26', 'RESELLER01'], 200000, Transaction::TYPE_FIRST);
        $this->transaction(['', 'RESELLER01'], 300000, Transaction::TYPE_RENEWAL);
        // Other and empty product types are never put under Perdana or Perpanjangan.
        $this->transaction(['UNREG26'], 400000, 'Bundling', $this->cerebrum);
        $this->transaction(['APP26'], 450000, null);
        $this->transaction(['APP26'], 500000, '  ');
        // Not a promo transaction.
        $this->transaction(null, 999999, Transaction::TYPE_FIRST);
    }

    private function breakdown(string $query = self::SEPTEMBER): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.$query)->assertOk()->json('data');
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int|float, 3: int|float, 4: int|float}> name => [key, transactions, revenue, transaction %, revenue %]
     */
    private function rows(array $breakdown): array
    {
        return collect($breakdown['product_types'])
            ->mapWithKeys(fn (array $row) => [$row['name'] => [$row['key'], $row['transactions'], $row['revenue'], $row['transaction_percentage'], $row['revenue_percentage']]])
            ->all();
    }

    public function test_guest_cannot_view_the_product_type_breakdown(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_promo_transactions_and_revenue_per_product_type(): void
    {
        $this->seedProductTypeData();

        $breakdown = $this->breakdown();

        $this->assertEquals([
            'Perdana' => ['first_purchase', 2, 300000, 33.33, 15.38],
            'Perpanjangan' => ['renewal', 1, 300000, 16.67, 15.38],
            'Bundling' => ['other', 1, 400000, 16.67, 20.51],
            'Tidak diketahui' => ['unknown', 2, 950000, 33.33, 48.72],
        ], $this->rows($breakdown));
        $this->assertSame(['first_purchase', 'renewal', 'other', 'unknown'], array_column($breakdown['product_types'], 'key'));
        $this->assertEquals(['transactions' => 6, 'revenue' => 1950000], $breakdown['totals']);
        $this->assertArrayNotHasKey('usages', $breakdown['product_types'][0]);
    }

    public function test_totals_match_the_promo_kpis(): void
    {
        $this->seedProductTypeData();

        $breakdown = $this->breakdown();
        $kpis = $this->getJson('/api/promo-analysis/summary'.self::SEPTEMBER)->json('data.kpis');

        $this->assertSame($kpis['promo_transactions']['value'], $breakdown['totals']['transactions']);
        $this->assertEquals($kpis['promo_revenue']['value'], $breakdown['totals']['revenue']);
        $this->assertSame($breakdown['totals']['transactions'], array_sum(array_column($breakdown['product_types'], 'transactions')));
    }

    public function test_perdana_and_perpanjangan_are_always_listed(): void
    {
        $this->transaction(['APP26'], 100000, 'Bundling');

        $this->assertEquals([
            'Perdana' => ['first_purchase', 0, 0, 0, 0],
            'Perpanjangan' => ['renewal', 0, 0, 0, 0],
            'Bundling' => ['other', 1, 100000, 100, 100],
        ], $this->rows($this->breakdown()));
    }

    public function test_anomalous_and_failed_transactions_are_left_out(): void
    {
        $this->seedProductTypeData();
        $anomaly = $this->transaction(['APP26'], 70000, Transaction::TYPE_FIRST);
        $anomaly->update(['promo_code' => ['APP26', 'RESELLER01', 'EXTRA']]);
        $this->transaction(['APP26'], 80000, Transaction::TYPE_FIRST)->update(['status_message' => 'failed']);

        $this->assertSame([2, 300000], array_slice($this->rows($this->breakdown())['Perdana'], 1, 2));
    }

    public function test_shared_promo_analysis_filters_apply(): void
    {
        $this->seedProductTypeData();
        $this->transaction(['APP26'], 60000, Transaction::TYPE_RENEWAL, paidDate: '2026-10-01');

        // A code search keeps only the transactions that used a matching code.
        $search = $this->breakdown(self::SEPTEMBER.'&search=reseller');
        $this->assertEquals(['transactions' => 2, 'revenue' => 500000], $search['totals']);
        $this->assertSame([1, 1], [$this->rows($search)['Perdana'][1], $this->rows($search)['Perpanjangan'][1]]);

        $this->assertEquals(['transactions' => 1, 'revenue' => 400000], $this->breakdown(self::SEPTEMBER.'&platforms[]=cerebrum')['totals']);
        $this->assertEquals(['transactions' => 1, 'revenue' => 60000], $this->breakdown('?date_from=2026-10-01&date_to=2026-10-31')['totals']);

        Transaction::factory()->for($this->jadiasn)->withoutPaymentMethod()->create([
            'paid_date' => '2026-09-25', 'promo_code' => ['APP26'], 'nominal' => 70000, 'product_type' => Transaction::TYPE_RENEWAL,
        ]);
        $unknownMethod = $this->breakdown(self::SEPTEMBER.'&payment_methods[]=Tidak%20diketahui');
        $this->assertSame([0, 1], [$this->rows($unknownMethod)['Perdana'][1], $this->rows($unknownMethod)['Perpanjangan'][1]]);

        $this->getJson(self::URL.'?years[]=2026')->assertUnprocessable()->assertJsonValidationErrors('years');
    }

    public function test_empty_result(): void
    {
        $breakdown = $this->breakdown();

        $this->assertTrue($breakdown['is_empty']);
        $this->assertSame('Data tidak tersedia untuk filter yang dipilih.', $breakdown['message']);
        $this->assertSame(['Perdana', 'Perpanjangan'], array_column($breakdown['product_types'], 'name'));
        $this->assertEquals(['transactions' => 0, 'revenue' => 0], $breakdown['totals']);
    }
}
