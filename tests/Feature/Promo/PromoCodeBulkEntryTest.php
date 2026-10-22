<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoCodeVersion;
use App\Models\PromoSource;
use App\Models\Role;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PromoCodeBulkEntryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-codes/bulk';

    private Account $employee;

    private Platform $jadiasn;

    private Platform $cerebrum;

    private Funnel $aplikasi;

    private PromoSource $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $this->seed(DatabaseSeeder::class);

        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Sales Marketing',
            'username' => 'sales',
            'password' => 'Employee123!',
        ]);
        $this->jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->cerebrum = Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);
        $this->aplikasi = Funnel::where('name', 'Aplikasi')->first();
        $this->instagram = PromoSource::where('name', 'Instagram')->first();
    }

    /**
     * @param  list<array<string, mixed>|null>  $rows
     */
    private function bulk(array $rows): TestResponse
    {
        return $this->actingAs($this->employee)->postJson(self::URL, ['rows' => $rows]);
    }

    public function test_guest_cannot_use_bulk_entry(): void
    {
        $this->postJson(self::URL, ['rows' => [['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id]]])
            ->assertUnauthorized();

        $this->assertSame(0, PromoCode::count());
    }

    public function test_several_rows_are_saved_in_one_submission_with_a_first_version_each(): void
    {
        $this->bulk([
            ['code' => ' cpns26 ', 'platform_id' => $this->jadiasn->id, 'funnel_id' => $this->aplikasi->id, 'source_id' => $this->instagram->id],
            ['code' => 'BUMN26', 'platform_id' => $this->cerebrum->id],
            ['code' => 'RESELLER01', 'platform_id' => $this->jadiasn->id, 'funnel_id' => $this->aplikasi->id, 'source_id' => null],
        ])
            ->assertOk()
            ->assertJsonPath('data.summary', ['total' => 3, 'succeeded' => 3, 'failed' => 0])
            ->assertJsonPath('data.rows.0.row', 1)
            ->assertJsonPath('data.rows.0.status', 'created')
            ->assertJsonPath('data.rows.0.code', 'CPNS26')
            ->assertJsonPath('data.rows.0.errors', [])
            ->assertJsonPath('data.rows.0.promo_code.platform.name', 'JADIASN')
            ->assertJsonPath('data.rows.0.promo_code.funnel.name', 'Aplikasi')
            ->assertJsonPath('data.rows.0.promo_code.source.name', 'Instagram')
            ->assertJsonPath('data.rows.1.promo_code.funnel', null)
            ->assertJsonPath('data.rows.1.promo_code.source', null);

        $this->assertSame(3, PromoCode::count());
        $this->assertSame(3, PromoCodeVersion::count());
        $this->assertSame(
            ['2026-09-01 08:00:00'],
            PromoCodeVersion::pluck('effective_from')->map->toDateTimeString()->unique()->values()->all(),
        );
    }

    public function test_bulk_entry_has_no_type_or_status_field(): void
    {
        $item = $this->bulk([['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id, 'type' => 'reseller', 'is_active' => false]])
            ->assertOk()
            ->json('data.rows.0.promo_code');

        $this->assertArrayNotHasKey('type', $item);
        $this->assertArrayNotHasKey('is_active', $item);
        $this->assertArrayNotHasKey('status', $item);
    }

    public function test_valid_rows_are_saved_even_when_other_rows_are_invalid(): void
    {
        $this->bulk([
            ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id],
            ['code' => '', 'platform_id' => null, 'funnel_id' => $this->aplikasi->id],
            ['code' => 'BUMN26', 'platform_id' => 9999, 'funnel_id' => 9999, 'source_id' => 9999],
            ['code' => 'POLRI26', 'platform_id' => $this->jadiasn->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.summary', ['total' => 4, 'succeeded' => 2, 'failed' => 2])
            ->assertJsonPath('data.rows.1.status', 'failed')
            ->assertJsonPath('data.rows.1.promo_code', null)
            ->assertJsonPath('data.rows.1.errors', [
                'code' => ['Kode promo wajib diisi.'],
                'platform_id' => ['Platform wajib diisi.'],
            ])
            ->assertJsonPath('data.rows.2.errors', [
                'platform_id' => ['Platform tidak ditemukan.'],
                'funnel_id' => ['Funnel tidak ditemukan.'],
                'source_id' => ['Source tidak ditemukan.'],
            ])
            ->assertJsonPath('data.rows.3.status', 'created');

        $this->assertSame(['CPNS26', 'POLRI26'], PromoCode::orderBy('id')->pluck('code')->all());
    }

    public function test_existing_codes_are_invalid_and_never_duplicated(): void
    {
        app(PromoCodeService::class)->create($this->jadiasn->id, 'CPNS26', $this->aplikasi->id);

        $this->bulk([
            ['code' => 'cpns26', 'platform_id' => $this->jadiasn->id],
            ['code' => 'CPNS26', 'platform_id' => $this->cerebrum->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 1, 'failed' => 1])
            ->assertJsonPath('data.rows.0.errors', ['code' => ['Kode promo CPNS26 sudah terdaftar di platform JADIASN.']])
            ->assertJsonPath('data.rows.1.status', 'created');

        $this->assertSame(1, PromoCode::where('platform_id', $this->jadiasn->id)->count());
        $this->assertSame($this->aplikasi->id, PromoCode::where('platform_id', $this->jadiasn->id)->value('funnel_id'));
    }

    public function test_the_same_code_twice_in_one_submission_keeps_the_first_row(): void
    {
        $this->bulk([
            ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id, 'funnel_id' => $this->aplikasi->id],
            ['code' => ' Cpns26', 'platform_id' => $this->jadiasn->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 1, 'failed' => 1])
            ->assertJsonPath('data.rows.1.errors', ['code' => ['Duplikat dengan baris 1.']]);

        $this->assertSame(1, PromoCode::count());
    }

    public function test_a_duplicate_of_a_row_that_failed_is_not_blocked(): void
    {
        $this->bulk([
            ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id, 'funnel_id' => 9999],
            ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id],
        ])
            ->assertJsonPath('data.rows.0.status', 'failed')
            ->assertJsonPath('data.rows.1.status', 'created');
    }

    public function test_submitting_the_same_rows_again_creates_nothing_new(): void
    {
        $rows = [
            ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id],
            ['code' => 'BUMN26', 'platform_id' => $this->cerebrum->id],
        ];

        $this->bulk($rows)->assertJsonPath('data.summary.succeeded', 2);
        $this->bulk($rows)->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 0, 'failed' => 2]);

        $this->assertSame(2, PromoCode::count());
        $this->assertSame(2, PromoCodeVersion::count());
    }

    public function test_empty_rows_are_ignored_and_keep_the_form_row_numbers(): void
    {
        $this->bulk([
            ['code' => 'CPNS26', 'platform_id' => $this->jadiasn->id],
            ['code' => '', 'platform_id' => null, 'funnel_id' => null, 'source_id' => null],
            null,
            ['code' => 'X', 'platform_id' => 9999],
        ])
            ->assertOk()
            ->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 1, 'failed' => 1])
            ->assertJsonPath('data.rows.1.row', 4);
    }

    public function test_bulk_entry_links_earlier_unmapped_usages_like_single_entry(): void
    {
        $transaction = Transaction::factory()->for($this->jadiasn)->create([
            'paid_date' => '2026-08-20',
            'promo_code' => ['NEWCODE'],
        ]);

        $this->bulk([['code' => 'NEWCODE', 'platform_id' => $this->jadiasn->id, 'source_id' => $this->instagram->id]])
            ->assertJsonPath('data.rows.0.promo_code.usages_count', 1);

        $this->assertSame('Instagram', $transaction->promoCodeUsages()->first()->source_name_snapshot);
    }

    public function test_the_submission_shape_is_validated(): void
    {
        $this->actingAs($this->employee);

        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rows' => 'Isi minimal satu baris kode promo.']);

        $this->postJson(self::URL, ['rows' => ['CPNS26']])
            ->assertJsonValidationErrors(['rows.0' => 'Setiap baris kode promo harus berisi kolom kode, platform, funnel, dan source.']);

        $this->postJson(self::URL, ['rows' => array_fill(0, 501, ['code' => 'X', 'platform_id' => $this->jadiasn->id])])
            ->assertJsonValidationErrors(['rows' => 'Maksimal 500 baris kode promo dalam sekali simpan.']);

        $this->assertSame(0, PromoCode::count());
    }
}
