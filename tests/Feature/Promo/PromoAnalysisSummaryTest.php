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

class PromoAnalysisSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-analysis/summary';

    private Account $manager;

    private Platform $jadiasn;

    private Platform $cerebrum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 10, 15, 8));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
    }

    /**
     * @param  list<string>|null  $codes
     */
    private function transaction(
        string $paidDate,
        ?array $codes,
        float $nominal,
        ?string $paymentMethod = 'BCA',
        ?Platform $platform = null,
        string $status = Transaction::STATUS_SUCCESS,
    ): void {
        Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'promo_code' => $codes,
            'nominal' => $nominal,
            'payment_method' => $paymentMethod,
            'status_message' => $status,
        ]);
    }

    /**
     * September 2026: 3 promo transactions (700.000) and 2 without promo (800.000); August 2026: 1 and 1.
     */
    private function seedPromoData(): void
    {
        app(PromoCodeService::class)->register($this->jadiasn->id, 'CPNS26');

        $this->transaction('2026-09-05', ['CPNS26'], 100000);
        $this->transaction('2026-09-10', [' cpns26 ', 'RESELLER01'], 200000, 'QRIS');
        $this->transaction('2026-09-12', null, 300000);
        $this->transaction('2026-09-15', ['NEWCODE'], 400000, ' BCA ', $this->cerebrum);
        $this->transaction('2026-09-20', null, 500000, null);
        $this->transaction('2026-09-21', ['CPNS26'], 999999, 'OVO', status: 'failed');

        $this->transaction('2026-08-10', ['CPNS26'], 150000);
        $this->transaction('2026-08-11', null, 50000, 'GOPAY');

        $this->transaction('2026-10-01', ['OTHER26'], 70000);
    }

    private function summary(string $query): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.$query)->assertOk()->json('data');
    }

    /**
     * @return array<string, int|float|null>
     */
    private function values(string $query): array
    {
        return array_map(fn (array $kpi) => $kpi['value'], $this->summary($query)['kpis']);
    }

    public function test_guest_cannot_view_the_promo_analysis(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson('/api/promo-analysis/filters')->assertUnauthorized();
    }

    public function test_month_summary_counts_unique_transactions_and_compares_with_the_previous_month(): void
    {
        $this->seedPromoData();

        $summary = $this->summary('?year=2026&month=9');

        $this->assertSame(['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], [
            'date_from' => $summary['filter']['date_from'], 'date_to' => $summary['filter']['date_to'],
        ]);
        $this->assertSame(['2026-08-01', '2026-08-31'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);
        $this->assertFalse($summary['is_empty']);

        $this->assertEquals([
            'promo_transactions' => ['value' => 3, 'previous_value' => 1, 'change_percentage' => 200, 'trend' => 'up'],
            'non_promo_transactions' => ['value' => 2, 'previous_value' => 1, 'change_percentage' => 100, 'trend' => 'up'],
            'promo_revenue' => ['value' => 700000, 'previous_value' => 150000, 'change_percentage' => 366.67, 'trend' => 'up'],
            'non_promo_revenue' => ['value' => 800000, 'previous_value' => 50000, 'change_percentage' => 1500, 'trend' => 'up'],
            'promo_transaction_percentage' => ['value' => 60, 'previous_value' => 50, 'change_percentage' => 20, 'trend' => 'up'],
            // CPNS26 (registered) and RESELLER01 on JADIASN, NEWCODE (unregistered) on CEREBRUM.
            'used_promo_codes' => ['value' => 3, 'previous_value' => 1, 'change_percentage' => 200, 'trend' => 'up'],
        ], $summary['kpis']);
    }

    public function test_a_transaction_with_two_codes_counts_once(): void
    {
        $this->transaction('2026-09-10', ['CPNS26', 'RESELLER01'], 200000);

        $this->assertEquals([
            'promo_transactions' => 1,
            'non_promo_transactions' => 0,
            'promo_revenue' => 200000,
            'non_promo_revenue' => 0,
            'promo_transaction_percentage' => 100,
            'used_promo_codes' => 2,
        ], $this->values('?year=2026&month=9'));
    }

    public function test_date_range_is_compared_with_the_same_number_of_days_before_it(): void
    {
        $this->seedPromoData();
        $this->transaction('2026-08-28', ['CPNS26'], 10000);
        $this->transaction('2026-08-27', ['CPNS26'], 10000);

        $summary = $this->summary('?date_from=2026-09-05&date_to=2026-09-12');

        $this->assertSame(['2026-08-28', '2026-09-04'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);
        $this->assertSame([2, 1], [$summary['kpis']['promo_transactions']['value'], $summary['kpis']['non_promo_transactions']['value']]);
        $this->assertSame([1, 0], [$summary['kpis']['promo_transactions']['previous_value'], $summary['kpis']['non_promo_transactions']['previous_value']]);
    }

    public function test_default_period_is_the_current_month(): void
    {
        $this->seedPromoData();

        $summary = $this->summary('');

        $this->assertSame(['current_month', '2026-10-01', '2026-10-15'], [
            $summary['filter']['mode'], $summary['filter']['date_from'], $summary['filter']['date_to'],
        ]);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);
        $this->assertSame([1, 3], [$summary['kpis']['promo_transactions']['value'], $summary['kpis']['promo_transactions']['previous_value']]);
    }

    public function test_platform_and_payment_method_filters(): void
    {
        $this->seedPromoData();

        $this->assertSame([1, 0, 1], array_values(array_intersect_key(
            $this->values('?year=2026&month=9&platforms[]=cerebrum'),
            array_flip(['promo_transactions', 'non_promo_transactions', 'used_promo_codes']),
        )));

        // Payment methods match case- and whitespace-insensitively: "BCA" also matches " BCA ".
        $bca = $this->values('?year=2026&month=9&payment_methods[]=bca');
        $this->assertSame([2, 1], [$bca['promo_transactions'], $bca['non_promo_transactions']]);

        $several = $this->values('?year=2026&month=9&payment_methods[]=QRIS&payment_methods[]=Tidak%20diketahui');
        $this->assertSame([1, 1], [$several['promo_transactions'], $several['non_promo_transactions']]);

        $summary = $this->summary('?year=2026&month=9&platforms[]=jadiasn&payment_methods[]=BCA');
        $this->assertSame([1, 1], [$summary['kpis']['promo_transactions']['value'], $summary['kpis']['non_promo_transactions']['value']]);
        $this->assertSame([['jadiasn'], ['BCA']], [$summary['filter']['platforms'], $summary['filter']['payment_methods']]);
    }

    public function test_code_search_narrows_the_transactions_and_finds_unregistered_codes(): void
    {
        $this->seedPromoData();

        // Matches " cpns26 " and "CPNS26"; the reseller code in the same transaction is not counted as a used code.
        $summary = $this->summary('?year=2026&month=9&search=cpns');
        $this->assertEquals([
            'promo_transactions' => 2,
            'non_promo_transactions' => 0,
            'promo_revenue' => 300000,
            'non_promo_revenue' => 0,
            'promo_transaction_percentage' => 100,
            'used_promo_codes' => 1,
        ], array_map(fn (array $kpi) => $kpi['value'], $summary['kpis']));
        $this->assertSame('cpns', $summary['filter']['search']);

        $unregistered = $this->values('?year=2026&month=9&search=NEWCODE');
        $this->assertSame([1, 1], [$unregistered['promo_transactions'], $unregistered['used_promo_codes']]);

        $this->assertSame(0, $this->values('?year=2026&month=9&search=UNKNOWN')['promo_transactions']);
    }

    public function test_empty_result(): void
    {
        $summary = $this->summary('?year=2026&month=9');

        $this->assertTrue($summary['is_empty']);
        $this->assertSame('Data tidak tersedia untuk filter yang dipilih.', $summary['message']);
        $this->assertNull($summary['kpis']['promo_transaction_percentage']['value']);
        $this->assertSame(0, $summary['kpis']['used_promo_codes']['value']);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->manager);

        $prohibited = 'Filter tahun tidak tersedia di Analisis Promo. Pilih rentang tanggal, atau bulan & tahun.';
        $this->getJson(self::URL.'?years[]=2026')->assertUnprocessable()->assertJsonValidationErrors(['years' => $prohibited]);
        $this->getJson(self::URL.'?year_from=2025&year_to=2026')->assertUnprocessable()->assertJsonValidationErrors(['year_from', 'year_to']);

        $this->getJson(self::URL.'?date_from=2026-09-01&date_to=2026-09-30&year=2026&month=9')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter' => 'Pilih salah satu filter periode saja: rentang tanggal, atau bulan & tahun.']);

        $this->getJson(self::URL.'?payment_methods=BCA')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_methods' => 'Metode pembayaran harus berupa daftar.']);
        $this->getJson(self::URL.'?search='.str_repeat('A', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search' => 'Pencarian kode promo maksimal 100 karakter.']);
        $this->getJson(self::URL.'?platforms[]=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platforms.0' => 'Platform tidak ditemukan.']);
    }

    public function test_filter_options_list_global_platforms_and_payment_methods(): void
    {
        $this->seedPromoData();

        $options = $this->actingAs($this->manager)->getJson('/api/promo-analysis/filters')->assertOk()->json('data');

        $this->assertSame(Platform::orderBy('id')->pluck('slug')->all(), array_column($options['platforms'], 'slug'));
        // Only successful transactions; " BCA " and "BCA" are one method and an empty method is "Tidak diketahui".
        $this->assertSame(['BCA', 'GOPAY', 'QRIS', 'Tidak diketahui'], $options['payment_methods']);
    }
}
