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
use App\Models\TransactionPromoCode;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCodeApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/promo-codes';

    private Account $employee;

    private Platform $jadiasn;

    private Platform $cerebrum;

    private Funnel $aplikasi;

    private Funnel $marketing;

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
        $this->marketing = Funnel::where('name', 'Marketing')->first();
        $this->instagram = PromoSource::where('name', 'Instagram')->first();
    }

    private function promoCode(string $code, ?Platform $platform = null, ?Funnel $funnel = null, ?PromoSource $source = null): PromoCode
    {
        return app(PromoCodeService::class)->create(($platform ?? $this->jadiasn)->id, $code, $funnel?->id, $source?->id);
    }

    private function transactionUsing(string $code, string $paidDate = '2026-09-10', ?Platform $platform = null): Transaction
    {
        return Transaction::factory()->for($platform ?? $this->jadiasn)->create([
            'paid_date' => $paidDate,
            'paid_time' => '10:00:00',
            'promo_code' => [$code],
        ]);
    }

    public function test_guest_cannot_access_promo_code_endpoints(): void
    {
        $id = $this->promoCode('CPNS26')->id;

        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, ['code' => 'X', 'platform_id' => $this->jadiasn->id])->assertUnauthorized();
        $this->getJson(self::URL."/{$id}")->assertUnauthorized();
        $this->patchJson(self::URL."/{$id}", ['funnel_id' => null])->assertUnauthorized();
        $this->deleteJson(self::URL."/{$id}")->assertUnauthorized();
    }

    public function test_list_shows_code_platform_funnel_and_source_without_type_or_status(): void
    {
        $code = $this->promoCode('CPNS26', funnel: $this->aplikasi, source: $this->instagram);
        $this->transactionUsing('CPNS26');

        $item = $this->actingAs($this->employee)
            ->getJson(self::URL)
            ->assertOk()
            ->json('data.0');

        $this->assertSame([
            'id' => $code->id,
            'code' => 'CPNS26',
            'platform' => ['id' => $this->jadiasn->id, 'slug' => 'jadiasn', 'name' => 'JADIASN'],
            'funnel' => ['id' => $this->aplikasi->id, 'name' => 'Aplikasi'],
            'source' => ['id' => $this->instagram->id, 'name' => 'Instagram'],
            'usages_count' => 1,
        ], array_intersect_key($item, array_flip(['id', 'code', 'platform', 'funnel', 'source', 'usages_count'])));
        $this->assertArrayNotHasKey('type', $item);
        $this->assertArrayNotHasKey('status', $item);
        $this->assertArrayNotHasKey('is_active', $item);
    }

    public function test_list_supports_search_filter_and_pagination(): void
    {
        $this->promoCode('CPNS26', funnel: $this->aplikasi);
        $this->promoCode('BUMN26', $this->cerebrum, source: $this->instagram);
        $this->promoCode('POLRI26', funnel: $this->marketing);

        $this->actingAs($this->employee);

        $this->getJson(self::URL.'?per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'POLRI26')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $this->getJson(self::URL.'?per_page=2&page=2')->assertJsonPath('data.0.code', 'CPNS26');

        $this->getJson(self::URL.'?search=cpns')->assertJsonPath('data.*.code', ['CPNS26']);
        $this->getJson(self::URL.'?search=cerebrum')->assertJsonPath('data.*.code', ['BUMN26']);
        $this->getJson(self::URL.'?search=Marketing')->assertJsonPath('data.*.code', ['POLRI26']);
        $this->getJson(self::URL.'?search=instagram')->assertJsonPath('data.*.code', ['BUMN26']);
        $this->getJson(self::URL.'?platform_id='.$this->jadiasn->id)->assertJsonPath('data.*.code', ['POLRI26', 'CPNS26']);

        $this->getJson(self::URL.'?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page' => 'Jumlah per halaman harus di antara 1 dan 100.']);
    }

    public function test_single_entry_creates_a_code_with_its_first_version(): void
    {
        $response = $this->actingAs($this->employee)
            ->postJson(self::URL, ['code' => ' cpns26 ', 'platform_id' => $this->jadiasn->id, 'funnel_id' => $this->aplikasi->id])
            ->assertCreated()
            ->assertJsonPath('message', 'Kode promo berhasil dibuat.')
            ->assertJsonPath('data.code', 'CPNS26')
            ->assertJsonPath('data.funnel.name', 'Aplikasi')
            ->assertJsonPath('data.source', null)
            ->assertJsonPath('data.usages_count', 0)
            ->assertJsonCount(1, 'data.versions');

        $this->assertSame('2026-09-01T08:00:00+07:00', $response->json('data.versions.0.effective_from'));
        $this->assertNull($response->json('data.versions.0.effective_until'));
    }

    public function test_funnel_and_source_are_optional_but_code_and_platform_are_required(): void
    {
        $this->actingAs($this->employee);

        $this->postJson(self::URL, ['code' => 'RESELLER01', 'platform_id' => $this->jadiasn->id, 'funnel_id' => null, 'source_id' => null])
            ->assertCreated();

        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode promo wajib diisi.', 'platform_id' => 'Platform wajib diisi.']);

        $this->postJson(self::URL, ['code' => 'X', 'platform_id' => 9999, 'funnel_id' => 9999, 'source_id' => 9999])
            ->assertJsonValidationErrors([
                'platform_id' => 'Platform tidak ditemukan.',
                'funnel_id' => 'Funnel tidak ditemukan.',
                'source_id' => 'Source tidak ditemukan.',
            ]);
    }

    public function test_code_is_unique_per_platform_with_a_clear_message(): void
    {
        $this->promoCode('CPNS26');
        $this->actingAs($this->employee);

        $this->postJson(self::URL, ['code' => 'Cpns26 ', 'platform_id' => $this->jadiasn->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode promo CPNS26 sudah terdaftar di platform JADIASN.']);

        $this->postJson(self::URL, ['code' => 'CPNS26', 'platform_id' => $this->cerebrum->id])->assertCreated();
    }

    public function test_single_entry_links_earlier_unmapped_usages(): void
    {
        $transaction = $this->transactionUsing('NEWCODE');

        $this->actingAs($this->employee)
            ->postJson(self::URL, [
                'code' => 'newcode',
                'platform_id' => $this->jadiasn->id,
                'funnel_id' => $this->aplikasi->id,
                'source_id' => $this->instagram->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.usages_count', 1);

        $usage = $transaction->promoCodeUsages()->first();
        $this->assertSame(['Aplikasi', 'Instagram'], [$usage->funnel_name_snapshot, $usage->source_name_snapshot]);
    }

    public function test_show_lists_the_metadata_versions_newest_first(): void
    {
        $code = $this->promoCode('CPNS26', funnel: $this->aplikasi);
        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 9, 30));
        app(PromoCodeService::class)->updateMetadata($code, $this->marketing->id, null);

        $this->actingAs($this->employee)
            ->getJson(self::URL."/{$code->id}")
            ->assertOk()
            ->assertJsonPath('data.versions.0.funnel', ['id' => $this->marketing->id, 'name' => 'Marketing'])
            ->assertJsonPath('data.versions.0.effective_until', null)
            ->assertJsonPath('data.versions.1.funnel.name', 'Aplikasi')
            ->assertJsonPath('data.versions.1.effective_until', '2026-09-15T09:30:00+07:00');
    }

    public function test_editing_funnel_or_source_starts_a_new_version_and_keeps_classified_snapshots(): void
    {
        $code = $this->promoCode('CPNS26', funnel: $this->aplikasi, source: $this->instagram);
        $before = $this->transactionUsing('CPNS26', '2026-09-10');

        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 9, 30));
        $this->actingAs($this->employee)
            ->patchJson(self::URL."/{$code->id}", ['funnel_id' => $this->marketing->id, 'source_id' => null])
            ->assertOk()
            ->assertJsonPath('message', 'Kode promo berhasil diperbarui.')
            ->assertJsonPath('data.funnel.name', 'Marketing')
            ->assertJsonPath('data.source', null)
            ->assertJsonCount(2, 'data.versions')
            ->assertJsonPath('data.versions.0.effective_from', '2026-09-15T09:30:00+07:00');

        $after = $this->transactionUsing('CPNS26', '2026-09-16');

        $this->assertSame(['Aplikasi', 'Instagram'], [
            $before->promoCodeUsages()->first()->funnel_name_snapshot,
            $before->promoCodeUsages()->first()->source_name_snapshot,
        ]);
        $this->assertSame(['Marketing', null], [
            $after->promoCodeUsages()->first()->funnel_name_snapshot,
            $after->promoCodeUsages()->first()->source_name_snapshot,
        ]);
    }

    public function test_saving_unchanged_metadata_creates_no_version(): void
    {
        $code = $this->promoCode('CPNS26', funnel: $this->aplikasi);

        $this->travelTo(CarbonImmutable::create(2026, 9, 15));
        $this->actingAs($this->employee)
            ->putJson(self::URL."/{$code->id}", [
                'code' => 'CPNS26',
                'platform_id' => $this->jadiasn->id,
                'funnel_id' => $this->aplikasi->id,
                'source_id' => null,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.versions');
    }

    public function test_code_and_platform_of_an_unused_code_can_be_edited(): void
    {
        $code = $this->promoCode('CPNS26');
        $this->promoCode('BUMN26');
        $this->actingAs($this->employee);

        $this->patchJson(self::URL."/{$code->id}", ['code' => 'BUMN26'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode promo BUMN26 sudah terdaftar di platform JADIASN.']);

        $this->travelTo(CarbonImmutable::create(2026, 9, 15));
        $this->patchJson(self::URL."/{$code->id}", ['code' => 'cpns27', 'platform_id' => $this->cerebrum->id])
            ->assertOk()
            ->assertJsonPath('data.code', 'CPNS27')
            ->assertJsonPath('data.platform.slug', 'cerebrum')
            ->assertJsonPath('data.versions.0.code', 'CPNS27')
            ->assertJsonPath('data.versions.0.platform_id', $this->cerebrum->id);
    }

    public function test_code_and_platform_of_a_used_code_cannot_be_edited_but_funnel_can(): void
    {
        $code = $this->promoCode('CPNS26');
        $this->transactionUsing('CPNS26');
        $this->actingAs($this->employee);

        $this->patchJson(self::URL."/{$code->id}", ['code' => 'CPNS27'])
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Kode promo dan platform tidak dapat diubah karena kode ini sudah digunakan oleh 1 transaksi. '
                    .'Funnel dan source tetap bisa diubah; buat kode promo baru jika kodenya berbeda.',
            ]);
        $this->patchJson(self::URL."/{$code->id}", ['platform_id' => $this->cerebrum->id])->assertStatus(409);
        $this->assertSame(['CPNS26', $this->jadiasn->id], [$code->fresh()->code, $code->fresh()->platform_id]);

        $this->patchJson(self::URL."/{$code->id}", ['funnel_id' => $this->aplikasi->id])
            ->assertOk()
            ->assertJsonPath('data.funnel.name', 'Aplikasi');
    }

    public function test_unused_code_is_deleted_with_its_own_versions(): void
    {
        $code = $this->promoCode('CPNS26', funnel: $this->aplikasi);
        app(PromoCodeService::class)->updateMetadata($code, $this->marketing->id, null);
        $other = $this->promoCode('BUMN26');

        $this->actingAs($this->employee)
            ->deleteJson(self::URL."/{$code->id}")
            ->assertOk()
            ->assertExactJson(['message' => 'Kode promo berhasil dihapus.']);

        $this->assertNull(PromoCode::find($code->id));
        $this->assertSame(0, PromoCodeVersion::where('promo_code_id', $code->id)->count());
        $this->assertSame(1, $other->versions()->count());
    }

    public function test_used_code_cannot_be_deleted_and_its_history_stays(): void
    {
        $code = $this->promoCode('CPNS26', funnel: $this->aplikasi);
        $this->transactionUsing('CPNS26');
        $this->transactionUsing('CPNS26');

        $this->actingAs($this->employee)
            ->deleteJson(self::URL."/{$code->id}")
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Kode promo tidak dapat dihapus karena sudah digunakan oleh 2 transaksi.']);

        $this->assertNotNull(PromoCode::find($code->id));
        $this->assertSame(1, $code->versions()->count());
        $this->assertSame(2, TransactionPromoCode::where('promo_code_id', $code->id)->count());
    }
}
