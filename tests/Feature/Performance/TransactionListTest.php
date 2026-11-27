<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Models\Account;
use App\Models\Platform;
use App\Models\Role;
use App\Models\Transaction;
use App\Repositories\TransactionListRepository;
use App\Services\TransactionListService;
use App\Support\PerformanceFilter;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * FIX-13-BE-01: Daftar Transaksi queries, filters, pagination, and the detail lookup.
 */
class TransactionListTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/transactions';

    private const NOVEMBER = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'];

    private Account $manager;

    private Platform $platformA;

    private Platform $platformB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);
    }

    public function test_ac01_rows_have_the_table_columns_in_order_newest_first_on_the_created_date_by_default(): void
    {
        $this->trx('OLD', ['markaz_created_at' => '2026-11-03 08:00:00', 'paid_date' => '2026-11-20', 'paid_time' => '10:00:00']);
        $this->trx('NEW', ['markaz_created_at' => '2026-11-10 09:00:00', 'paid_date' => '2026-11-10', 'paid_time' => '09:05:00']);
        $this->trx('OUTSIDE', ['markaz_created_at' => '2026-10-31 23:59:59', 'paid_date' => '2026-11-02', 'paid_time' => '10:00:00']);

        $response = $this->list()->assertOk();

        $this->assertSame(['NEW', 'OLD'], array_column($response->json('data'), 'trx_id'));
        $this->assertSame('created', $response->json('meta.date_basis'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(
            ['id', 'trx_id', 'created_at', 'paid_date', 'paid_time', 'platform', 'product', 'customer_name', 'payment_method', 'nominal', 'status', 'is_claim_coupon', 'group_member_count'],
            array_keys($response->json('data.0')),
        );
        $this->assertSame('2026-11-10T09:00:00+07:00', $response->json('data.0.created_at'));
        $this->assertArrayNotHasKey('customer_email', $response->json('data.0'));
        $this->assertArrayNotHasKey('customer', $response->json('data.0'));

        // Tanggal Dibayar: rows paid in the period, newest payment first, whatever their creation date.
        $paid = $this->list(['date_basis' => 'paid'])->assertOk();
        $this->assertSame(['OLD', 'NEW', 'OUTSIDE'], array_column($paid->json('data'), 'trx_id'));
        $this->assertSame('paid', $paid->json('meta.date_basis'));
    }

    public function test_ac01_platform_range_status_method_and_search_combine(): void
    {
        $match = ['markaz_created_at' => '2026-11-12 10:00:00', 'customer_name' => 'Budi Santoso', 'payment_method' => 'QRIS'];
        $this->trx('A-MATCH', [...$match, ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null, 'paid_time' => null]);
        $this->trx('A-OTHER-METHOD', [...$match, 'payment_method' => 'BCA', ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null]);
        $this->trx('A-OTHER-STATUS', $match);
        $this->trx('A-OTHER-NAME', [...$match, 'customer_name' => 'Siti', ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null]);
        $this->trx('A-OUTSIDE', [...$match, 'markaz_created_at' => '2026-10-12 10:00:00', ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null]);
        $this->trx('B-MATCH', [...$match, ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null], $this->platformB);

        $response = $this->list([
            'platforms' => ['app-a'],
            'statuses' => ['pending'],
            'payment_methods' => [' qris '],
            'search' => 'budi',
        ])->assertOk();

        $this->assertSame(['A-MATCH'], array_column($response->json('data'), 'trx_id'));
        $this->assertSame(['app-a'], $response->json('meta.filter.platforms'));

        // Without the local filters the main filter is kept: every row of platform A in November.
        $reset = $this->list(['platforms' => ['app-a']])->assertOk();
        $this->assertSame(4, $reset->json('meta.total'));
    }

    public function test_search_covers_the_id_name_email_and_phone(): void
    {
        $this->trx('app-a-777', ['customer_name' => 'Rina', 'customer_email' => 'rina@example.test', 'customer_phone' => '081234']);
        $this->trx('app-a-888', ['customer_name' => 'Dodi', 'customer_email' => 'dodi@example.test', 'customer_phone' => '089999']);

        foreach (['a-777', 'RINA', 'rina@example', '0812'] as $search) {
            $this->assertSame(['app-a-777'], array_column($this->list(['search' => $search])->json('data'), 'trx_id'), $search);
        }

        $this->assertSame([], $this->list(['search' => '100%_'])->json('data'));
    }

    public function test_ac02_every_page_together_equals_the_export_query_without_a_page_limit(): void
    {
        foreach (range(1, 100) as $i) {
            // Few distinct creation times, so the id decides the order of most rows.
            $this->trx('T-'.$i, ['markaz_created_at' => '2026-11-'.str_pad((string) (($i % 4) + 1), 2, '0', STR_PAD_LEFT).' 10:00:00']);
        }

        $paged = [];
        foreach (range(1, 4) as $page) {
            $response = $this->list(['per_page' => 25, 'page' => $page])->assertOk();
            $this->assertSame(4, $response->json('meta.last_page'));
            $this->assertCount(25, $response->json('data'));
            $paged = [...$paged, ...array_column($response->json('data'), 'id')];
        }

        $service = app(TransactionListService::class);
        $filter = $service->filter(PerformanceFilter::dateRange(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')));
        $all = app(TransactionListRepository::class)->query($filter)->pluck('transactions.id')->all();

        $this->assertCount(100, array_unique($paged));
        $this->assertSame($all, $paged);
    }

    public function test_ac04_one_row_per_group_and_the_detail_shows_members_and_the_claim_flag(): void
    {
        $buyer = $this->trx('GRP-1', ['markaz_transaction_id' => 5001, 'nominal' => 300000, 'net_nominal' => 300000]);
        $this->trx('GRP-1-M1', ['markaz_transaction_id' => 5002, 'markaz_ref_id' => 5001, 'customer_name' => 'Anggota 1']);
        $this->trx('GRP-1-M2', ['markaz_transaction_id' => 5003, 'markaz_ref_id' => 5001, 'customer_name' => 'Anggota 2']);
        $claim = $this->trx('CLAIM-1', ['markaz_transaction_id' => 5004, 'is_claim_coupon' => true, 'nominal' => 0, 'net_nominal' => 0]);
        // A member on another platform with the same Markaz reference is not part of this group.
        $this->trx('B-M', ['markaz_transaction_id' => 6001, 'markaz_ref_id' => 5001], $this->platformB);

        $rows = $this->list(['platforms' => ['app-a']])->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['GRP-1', 'CLAIM-1'], array_column($rows, 'trx_id'));
        $this->assertSame(2, collect($rows)->firstWhere('trx_id', 'GRP-1')['group_member_count']);

        $group = $this->detail($buyer)->assertOk();
        $this->assertSame('buyer', $group->json('data.group.role'));
        $this->assertSame(['GRP-1-M1', 'GRP-1-M2'], array_column($group->json('data.group.members'), 'trx_id'));
        $this->assertSame(300000.0, (float) $group->json('data.financials.revenue_contribution'));

        $claimDetail = $this->detail($claim)->assertOk();
        $this->assertTrue($claimDetail->json('data.is_claim_coupon'));
        $this->assertNull($claimDetail->json('data.financials.revenue_contribution'), 'A claim is not a payment.');
        $this->assertNull($claimDetail->json('data.group.role'));

        $member = Transaction::where('trx_id', 'GRP-1-M1')->first();
        $memberDetail = $this->detail($member)->assertOk();
        $this->assertSame('member', $memberDetail->json('data.group.role'));
        $this->assertSame('GRP-1', $memberDetail->json('data.group.buyer.trx_id'));
        $this->assertNull($memberDetail->json('data.financials.revenue_contribution'), 'Group members add no revenue.');
    }

    public function test_ac05_the_status_filter_and_label_use_the_report_status(): void
    {
        $this->trx('RAW-PENDING', [...$this->statuses(MarkazTransactionStatus::Pending, MarkazTransactionStatus::Expired), 'paid_date' => null]);
        $this->trx('PENDING', [...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null]);
        $this->trx('FAILED', $this->statuses(MarkazTransactionStatus::Failed));
        $this->trx('CANCELED', $this->statuses(MarkazTransactionStatus::Canceled));
        $this->trx('SUCCESS', $this->statuses(MarkazTransactionStatus::Success));
        $this->trx('UNKNOWN', ['source_status' => null, 'effective_status' => null, 'paid_date' => null]);

        $expired = $this->list(['statuses' => ['expired']])->assertOk()->json('data');
        $this->assertSame(['RAW-PENDING'], array_column($expired, 'trx_id'));
        $this->assertSame(['code' => 2, 'label' => 'Expired'], $expired[0]['status']);

        // Tidak Berhasil = Expired, Canceled, and Failed.
        $unsuccessful = $this->list(['statuses' => ['expired', 'canceled', 'failed']])->json('data');
        $this->assertEqualsCanonicalizing(['RAW-PENDING', 'FAILED', 'CANCELED'], array_column($unsuccessful, 'trx_id'));

        $unknown = $this->list(['statuses' => ['unavailable']])->json('data');
        $this->assertSame(['UNKNOWN'], array_column($unknown, 'trx_id'));
        $this->assertSame(['code' => null, 'label' => 'Status belum tersedia'], $unknown[0]['status']);

        $this->assertEqualsCanonicalizing(['PENDING', 'UNKNOWN'], array_column($this->list(['statuses' => ['pending', 'unavailable']])->json('data'), 'trx_id'));
    }

    public function test_ac05_paid_date_is_the_last_valid_one_or_unavailable_and_the_paid_basis_only_selects_valid_dates_in_range(): void
    {
        // The latest Markaz payload has no paid date anymore; the last valid report date stays.
        $this->trx('KEPT', ['markaz_created_at' => '2026-11-11 20:00:00', 'paid_date' => '2026-11-12', 'paid_time' => '08:15:00', 'markaz_paid_at' => null]);
        $this->trx('NEVER', [...$this->statuses(MarkazTransactionStatus::Pending), 'markaz_created_at' => '2026-11-12 09:00:00', 'paid_date' => null, 'paid_time' => null]);
        $this->trx('PAID-DEC', ['markaz_created_at' => '2026-11-30 23:00:00', 'paid_date' => '2026-12-01', 'paid_time' => '00:10:00']);

        $rows = collect($this->list()->json('data'))->keyBy('trx_id');
        $this->assertSame('2026-11-12', $rows['KEPT']['paid_date']);
        $this->assertSame('08:15:00', $rows['KEPT']['paid_time']);
        $this->assertNull($rows['NEVER']['paid_date']);
        $this->assertNull($rows['NEVER']['paid_time']);

        $paid = $this->list(['date_basis' => 'paid', 'date_from' => '2026-11-12', 'date_to' => '2026-11-30'])->json('data');
        $this->assertSame(['KEPT'], array_column($paid, 'trx_id'));
    }

    public function test_ac06_the_detail_shows_the_five_financial_details_and_pending_has_no_revenue_contribution(): void
    {
        $money = ['product_price' => 200000, 'voucher_discount' => 20000, 'admin_fee' => 3000, 'nominal' => 183000, 'net_nominal' => 180000];
        $success = $this->trx('SUCCESS', $money);
        $pending = $this->trx('PENDING', [...$money, ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null, 'paid_time' => null]);
        // A Success without a valid paid date does not count in Omzet yet.
        $undated = $this->trx('UNDATED', [...$money, 'paid_date' => null, 'paid_time' => null]);

        $this->assertEquals([
            'product_price' => 200000,
            'voucher_discount' => 20000,
            'admin_fee' => 3000,
            'nominal' => 183000,
            'revenue_contribution' => 180000,
        ], $this->detail($success)->assertOk()->json('data.financials'));

        $pendingFinancials = $this->detail($pending)->json('data.financials');
        $this->assertEquals(183000, $pendingFinancials['nominal']);
        $this->assertNull($pendingFinancials['revenue_contribution']);
        $this->assertNull($this->detail($undated)->json('data.financials.revenue_contribution'));

        // The table column Nominal is the billed nominal.
        $this->assertEquals(183000, collect($this->list()->json('data'))->firstWhere('trx_id', 'PENDING')['nominal']);
    }

    public function test_ac03_detail_has_product_type_promo_codes_and_contacts_for_an_allowed_account(): void
    {
        $full = $this->trx('FULL', [
            'product_type' => Transaction::TYPE_RENEWAL,
            'promo_code' => ['cpns10', 'RESELLER1'],
            'customer_name' => 'Rina',
            'customer_email' => 'rina@example.test',
            'customer_phone' => '081234567',
        ]);
        $noPhone = $this->trx('NO-PHONE', ['customer_phone' => null, 'customer_email' => 'dodi@example.test']);

        $detail = $this->detail($full)->assertOk();
        $this->assertSame('Perpanjangan', $detail->json('data.product_type'));
        $this->assertSame(['cpns10', 'RESELLER1'], $detail->json('data.promo_codes'));
        $this->assertSame('rina@example.test', $detail->json('data.customer.email'));
        $this->assertSame('081234567', $detail->json('data.customer.phone'));

        $this->assertNull($this->detail($noPhone)->json('data.customer.phone'));
        $this->assertSame('dodi@example.test', $this->detail($noPhone)->json('data.customer.email'));

        // The current Analisis Performa rule: every active account, Freelance included, may open the detail.
        $this->actingAs($this->account(Role::FREELANCE))->getJson(self::URL.'/'.$full->id)->assertOk();
    }

    public function test_ac03_an_unavailable_id_is_not_found_and_a_denied_session_gets_no_contact(): void
    {
        $transaction = $this->trx('FULL', ['customer_email' => 'rina@example.test', 'customer_phone' => '081234567']);
        $legacy = Transaction::factory()->forPlatform($this->platformA)->create(['markaz_transaction_id' => null, 'markaz_created_at' => null]);

        $this->actingAs($this->manager)->getJson(self::URL.'/999999')->assertNotFound();
        $this->actingAs($this->manager)->getJson(self::URL.'/'.$legacy->id)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $guest = $this->getJson(self::URL.'/'.$transaction->id)->assertUnauthorized();
        $this->assertStringNotContainsString('rina@example.test', $guest->getContent());
        $this->getJson(self::URL)->assertUnauthorized();

        $inactive = $this->account(Role::EMPLOYEE);
        $inactive->forceFill(['is_active' => false])->save();
        $denied = $this->actingAs($inactive)->getJson(self::URL.'/'.$transaction->id)->assertForbidden();
        $this->assertStringNotContainsString('rina@example.test', $denied->getContent());
        $this->actingAs($inactive)->getJson(self::URL)->assertForbidden();
    }

    public function test_product_type_product_and_promo_filters(): void
    {
        $this->trx('PROMO-A', ['promo_code' => ['cpns10'], 'product' => 'Paket A', 'product_type' => Transaction::TYPE_FIRST]);
        $this->trx('PROMO-B', ['promo_code' => ['UTBK_5', 'RES1'], 'product' => 'Paket B', 'product_type' => Transaction::TYPE_RENEWAL, ...$this->statuses(MarkazTransactionStatus::Pending), 'paid_date' => null]);
        $this->trx('NO-PROMO', ['promo_code' => null, 'product' => 'Paket A', 'product_type' => Transaction::TYPE_RENEWAL]);
        $this->trx('EMPTY-PROMO', ['promo_code' => [], 'product' => 'Paket B', 'product_type' => Transaction::TYPE_FIRST]);
        $this->trx('BLANK-PROMO', ['promo_code' => ['', null], 'product' => 'Paket B', 'product_type' => Transaction::TYPE_FIRST]);
        $this->trx('LIKE-TRAP', ['promo_code' => ['UTBKX5'], 'product' => 'Paket B', 'product_type' => Transaction::TYPE_FIRST]);

        $ids = fn (array $query) => array_column($this->list($query)->json('data'), 'trx_id');

        $this->assertEqualsCanonicalizing(['PROMO-A', 'PROMO-B', 'LIKE-TRAP'], $ids(['promo' => 'with']));
        $this->assertEqualsCanonicalizing(['NO-PROMO', 'EMPTY-PROMO', 'BLANK-PROMO'], $ids(['promo' => 'without']));
        $this->assertEqualsCanonicalizing(['PROMO-A', 'PROMO-B'], $ids(['promo_codes' => ['CPNS10', ' utbk_5 ']]), 'Pending rows have no promo usages, so the stored payload is read.');
        $this->assertEqualsCanonicalizing(['PROMO-A', 'NO-PROMO'], $ids(['products' => ['Paket A']]));
        $this->assertEqualsCanonicalizing(['PROMO-B', 'NO-PROMO'], $ids(['product_types' => [Transaction::TYPE_RENEWAL]]));
        $this->assertSame(['PROMO-B'], $ids(['product_types' => [Transaction::TYPE_RENEWAL], 'promo' => 'with', 'statuses' => ['pending']]));
    }

    public function test_options_list_the_values_of_filter_lainnya_in_the_main_period(): void
    {
        $this->trx('A', ['product' => 'Paket A', 'payment_method' => 'QRIS', 'product_type' => Transaction::TYPE_FIRST, 'promo_code' => ['cpns10']]);
        $this->trx('B', ['product' => 'Paket B', 'payment_method' => ' qris ', 'product_type' => Transaction::TYPE_RENEWAL, 'promo_code' => ['CPNS10', 'RES1']]);
        $this->trx('OUT', ['product' => 'Paket Lama', 'payment_method' => 'BCA', 'markaz_created_at' => '2026-10-01 10:00:00', 'paid_date' => '2026-10-01']);

        $options = $this->actingAs($this->manager)->getJson(self::URL.'/options?'.http_build_query(self::NOVEMBER))->assertOk()->json('data');

        $this->assertSame(['Paket A', 'Paket B'], $options['products']);
        $this->assertCount(1, $options['payment_methods']);
        $this->assertSame('qris', mb_strtolower($options['payment_methods'][0]));
        $this->assertSame([Transaction::TYPE_FIRST, Transaction::TYPE_RENEWAL], $options['product_types']);
        $this->assertSame(['CPNS10', 'RES1'], $options['promo_codes']);
    }

    public function test_a_preset_uses_the_resolved_main_period(): void
    {
        $this->trx('IN', ['markaz_created_at' => '2026-11-05 10:00:00']);
        $this->trx('OUT', ['markaz_created_at' => '2026-11-06 10:00:00']);

        $response = $this->actingAs($this->manager)->getJson(self::URL.'?'.http_build_query([
            'preset' => 'date_range', 'date_from' => '2026-11-05', 'date_to' => '2026-11-05',
        ]))->assertOk();

        $this->assertSame(['IN'], array_column($response->json('data'), 'trx_id'));
        $this->assertSame('2026-11-05', $response->json('meta.filter.date_from'));
    }

    public function test_invalid_list_parameters_are_rejected(): void
    {
        $this->list(['statuses' => ['paid']])->assertUnprocessable()->assertJsonValidationErrors('statuses.0');
        $this->list(['date_basis' => 'synced'])->assertUnprocessable()->assertJsonValidationErrors('date_basis');
        $this->list(['per_page' => 101])->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->list(['promo' => 'without', 'promo_codes' => ['CPNS10']])->assertUnprocessable()->assertJsonValidationErrors('promo_codes');
        $this->list(['platforms' => ['unknown']])->assertUnprocessable()->assertJsonValidationErrors('platforms.0');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function list(array $query = []): TestResponse
    {
        return $this->actingAs($this->manager)->getJson(self::URL.'?'.http_build_query([...self::NOVEMBER, ...$query]));
    }

    private function detail(Transaction $transaction): TestResponse
    {
        return $this->actingAs($this->manager)->getJson(self::URL.'/'.$transaction->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function trx(string $trxId, array $overrides = [], ?Platform $platform = null): Transaction
    {
        return Transaction::factory()->forPlatform($platform ?? $this->platformA)->create([
            'trx_id' => $trxId,
            'markaz_created_at' => '2026-11-12 10:00:00',
            'paid_date' => '2026-11-12',
            'paid_time' => '10:05:00',
            'net_nominal' => 100000,
            ...$overrides,
        ]);
    }

    /**
     * @return array{source_status: int, effective_status: int|null}
     */
    private function statuses(MarkazTransactionStatus $source, ?MarkazTransactionStatus $effective = null): array
    {
        return ['source_status' => $source->value, 'effective_status' => $effective?->value];
    }

    private function account(string $role): Account
    {
        return Account::create([
            'username' => strtolower($role).'-user',
            'role_id' => Role::where('code_role', $role)->value('id'),
            'fullname' => 'Akun '.$role,
            'password' => 'Rahasia123!',
        ]);
    }
}
