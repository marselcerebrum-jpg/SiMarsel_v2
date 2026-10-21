<?php

namespace Tests\Feature\Promo;

use App\Models\Account;
use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoSource;
use App\Models\Role;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FunnelSeeder;
use Database\Seeders\PromoSourceSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromoMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private Account $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->employee = Account::create([
            'role_id' => Role::where('code_role', Role::EMPLOYEE)->value('id'),
            'fullname' => 'Sales Marketing',
            'username' => 'sales',
            'password' => 'Employee123!',
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: class-string<Funnel|PromoSource>, 2: string, 3: string}>
     */
    public static function masters(): array
    {
        return [
            'funnel' => ['/api/funnels', Funnel::class, 'Funnel', 'Nama funnel'],
            'source' => ['/api/promo-sources', PromoSource::class, 'Source', 'Nama source'],
        ];
    }

    /**
     * @param  class-string<Funnel|PromoSource>  $model
     */
    private function promoCodeUsing(string $model, Model $master): PromoCode
    {
        return app(PromoCodeService::class)->create(
            Platform::factory()->create()->id,
            'CPNS26',
            $model === Funnel::class ? $master->id : null,
            $model === PromoSource::class ? $master->id : null,
        );
    }

    public function test_initial_funnels_and_sources_are_seeded_without_system_groups(): void
    {
        $this->assertSame(FunnelSeeder::FUNNELS, Funnel::orderBy('id')->pluck('name')->all());
        $this->assertSame(PromoSourceSeeder::SOURCES, PromoSource::orderBy('id')->pluck('name')->all());
        $this->assertSame(0, Funnel::where('name', 'Tanpa Funnel')->count());
        $this->assertSame(0, PromoSource::where('name', 'Tanpa Sumber')->count());

        $this->seed([FunnelSeeder::class, PromoSourceSeeder::class]);
        $this->assertSame(6, Funnel::count());
        $this->assertSame(9, PromoSource::count());
    }

    #[DataProvider('masters')]
    public function test_guest_cannot_access_master_endpoints(string $url, string $model): void
    {
        $id = $model::first()->id;

        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, ['name' => 'Baru'])->assertUnauthorized();
        $this->getJson("{$url}/{$id}")->assertUnauthorized();
        $this->patchJson("{$url}/{$id}", ['name' => 'Baru'])->assertUnauthorized();
        $this->deleteJson("{$url}/{$id}")->assertUnauthorized();
    }

    #[DataProvider('masters')]
    public function test_any_logged_in_user_can_list_with_usage_count(string $url, string $model): void
    {
        $master = $model::first();
        $this->promoCodeUsing($model, $master);

        $this->actingAs($this->employee)
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount($model::count(), 'data')
            ->assertJsonPath('data.0', ['id' => $master->id, 'name' => $master->name, 'promo_codes_count' => 1])
            ->assertJsonPath('data.1.promo_codes_count', 0);

        $this->getJson("{$url}/{$master->id}")
            ->assertOk()
            ->assertJsonPath('data.promo_codes_count', 1);
    }

    #[DataProvider('masters')]
    public function test_any_logged_in_user_can_create_update_and_delete(string $url, string $model, string $label): void
    {
        $this->actingAs($this->employee);

        $id = $this->postJson($url, ['name' => '  Webinar  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Webinar')
            ->assertJsonPath('data.promo_codes_count', 0)
            ->assertJsonPath('message', "{$label} berhasil dibuat.")
            ->json('data.id');

        $this->patchJson("{$url}/{$id}", ['name' => 'Webinar CPNS'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Webinar CPNS')
            ->assertJsonPath('message', "{$label} berhasil diperbarui.");

        // Saving its own name again is not a duplicate.
        $this->putJson("{$url}/{$id}", ['name' => 'Webinar CPNS'])->assertOk();

        $this->deleteJson("{$url}/{$id}")
            ->assertOk()
            ->assertExactJson(['message' => "{$label} berhasil dihapus."]);
        $this->assertNull($model::find($id));
    }

    #[DataProvider('masters')]
    public function test_name_is_required_unique_and_not_a_system_group(string $url, string $model, string $label, string $attribute): void
    {
        $this->actingAs($this->employee);
        $existing = $model::first();

        $this->postJson($url, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => "{$attribute} wajib diisi."]);
        $this->postJson($url, ['name' => $existing->name])
            ->assertJsonValidationErrors(['name' => "{$attribute} sudah digunakan."]);
        $this->postJson($url, ['name' => str_repeat('a', 101)])
            ->assertJsonValidationErrors(['name' => "{$attribute} maksimal 100 karakter."]);

        foreach (['Tanpa Kode', 'tanpa funnel', ' TANPA SUMBER '] as $systemGroup) {
            $this->postJson($url, ['name' => $systemGroup])
                ->assertJsonValidationErrors(['name' => "{$attribute} tidak boleh memakai nama kelompok sistem (Tanpa Kode, Tanpa Funnel, Tanpa Sumber)."]);
        }

        $other = $model::where('id', '!=', $existing->id)->first();
        $this->patchJson("{$url}/{$other->id}", ['name' => $existing->name])
            ->assertJsonValidationErrors(['name' => "{$attribute} sudah digunakan."]);
    }

    #[DataProvider('masters')]
    public function test_master_used_by_a_promo_code_cannot_be_deleted(string $url, string $model, string $label): void
    {
        $master = $model::first();
        $this->promoCodeUsing($model, $master);

        $this->actingAs($this->employee)
            ->deleteJson("{$url}/{$master->id}")
            ->assertStatus(409)
            ->assertExactJson([
                'message' => "{$label} tidak dapat dihapus karena masih digunakan oleh 1 kode promo. Ganti "
                    .mb_strtolower($label).' pada kode promo tersebut terlebih dahulu.',
            ]);

        $this->assertNotNull($model::find($master->id));
    }

    #[DataProvider('masters')]
    public function test_master_only_in_history_can_be_deleted_and_snapshots_keep_its_name(string $url, string $model): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $master = $model::create(['name' => 'Webinar Lama']);
        $promoCode = $this->promoCodeUsing($model, $master);
        $transaction = Transaction::factory()->for($promoCode->platform)->create([
            'paid_date' => '2026-09-10',
            'promo_code' => ['CPNS26'],
        ]);
        $this->travelTo(CarbonImmutable::create(2026, 9, 20, 8));

        // The promo code moves on; the old version and the usage still remember the master.
        app(PromoCodeService::class)->updateMetadata($promoCode, null, null);

        $this->actingAs($this->employee)->deleteJson("{$url}/{$master->id}")->assertOk();

        $usage = $transaction->promoCodeUsages()->first();
        $snapshot = $model === Funnel::class
            ? [$usage->funnel_id_snapshot, $usage->funnel_name_snapshot]
            : [$usage->source_id_snapshot, $usage->source_name_snapshot];
        $this->assertSame([null, 'Webinar Lama'], $snapshot);
    }

    #[DataProvider('masters')]
    public function test_renaming_a_master_keeps_historical_snapshots(string $url, string $model): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 1, 8));
        $master = $model::create(['name' => 'Webinar Lama']);
        $promoCode = $this->promoCodeUsing($model, $master);
        $transaction = Transaction::factory()->for($promoCode->platform)->create([
            'paid_date' => '2026-09-10',
            'promo_code' => ['CPNS26'],
        ]);
        $this->travelTo(CarbonImmutable::create(2026, 9, 20, 8));

        $this->actingAs($this->employee)->patchJson("{$url}/{$master->id}", ['name' => 'Webinar Baru'])->assertOk();

        $usage = $transaction->promoCodeUsages()->first();
        $version = $promoCode->versions()->first();
        $this->assertSame('Webinar Lama', $model === Funnel::class ? $usage->funnel_name_snapshot : $usage->source_name_snapshot);
        $this->assertSame('Webinar Lama', $model === Funnel::class ? $version->funnel_name_snapshot : $version->source_name_snapshot);
    }

    public function test_platforms_are_listed_read_only(): void
    {
        $jadiasn = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        Platform::factory()->create(['slug' => 'cerebrum', 'name' => 'CEREBRUM']);

        $this->getJson('/api/platforms')->assertUnauthorized();

        $this->actingAs($this->employee)
            ->getJson('/api/platforms')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', ['id' => $jadiasn->id, 'slug' => 'jadiasn', 'name' => 'JADIASN']);

        $this->postJson('/api/platforms', ['slug' => 'baru', 'name' => 'BARU'])->assertMethodNotAllowed();
        $this->patchJson("/api/platforms/{$jadiasn->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/platforms/{$jadiasn->id}")->assertNotFound();
        $this->assertSame(2, Platform::count());
    }
}
