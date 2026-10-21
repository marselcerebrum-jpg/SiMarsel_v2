<?php

namespace Tests\Feature\Database;

use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoCodeVersion;
use App\Models\PromoSource;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
use App\Services\PromoCodeService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromoCodeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function usage(Transaction $transaction, int $position, ?PromoCode $promoCode = null): TransactionPromoCode
    {
        return TransactionPromoCode::create([
            'transaction_id' => $transaction->id,
            'promo_code_id' => $promoCode?->id,
            'raw_code' => 'RAW',
            'code_position' => $position,
            'platform_id_snapshot' => $transaction->platform_id,
            'code_snapshot' => 'RAW',
        ]);
    }

    public function test_promo_code_has_no_type_or_active_status_column(): void
    {
        $this->assertSame(
            ['id', 'platform_id', 'code', 'funnel_id', 'source_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('promo_codes'),
        );
    }

    public function test_code_is_unique_per_platform_only(): void
    {
        [$platformA, $platformB] = Platform::factory()->count(2)->create();
        PromoCode::factory()->for($platformA)->create(['code' => 'CPNS26']);

        PromoCode::factory()->for($platformB)->create(['code' => 'CPNS26']);
        $this->assertSame(2, PromoCode::where('code', 'CPNS26')->count());

        $this->expectException(QueryException::class);
        PromoCode::factory()->for($platformA)->create(['code' => ' cpns26 ']);
    }

    public function test_funnel_and_source_are_optional(): void
    {
        $promoCode = PromoCode::factory()->create(['funnel_id' => null, 'source_id' => null]);

        $this->assertNull($promoCode->fresh()->funnel_id);
        $this->assertNull($promoCode->fresh()->source_id);
    }

    public function test_funnel_or_source_used_by_a_promo_code_cannot_be_deleted(): void
    {
        $funnel = Funnel::factory()->create();
        PromoCode::factory()->create(['funnel_id' => $funnel->id]);

        $this->expectException(QueryException::class);
        $funnel->delete();
    }

    public function test_versions_are_deleted_with_their_promo_code_and_keep_names_when_a_funnel_is_deleted(): void
    {
        $funnel = Funnel::factory()->create(['name' => 'Webinar']);
        $source = PromoSource::factory()->create(['name' => 'Instagram']);
        $promoCode = app(PromoCodeService::class)->create(Platform::factory()->create()->id, 'CPNS26', $funnel->id, $source->id);

        // The promo code no longer uses the funnel, only its old version does.
        $promoCode->update(['funnel_id' => null]);
        $funnel->delete();

        $version = $promoCode->versions()->first();
        $this->assertNull($version->funnel_id);
        $this->assertSame('Webinar', $version->funnel_name_snapshot);

        $promoCode->delete();
        $this->assertSame(0, PromoCodeVersion::count());
    }

    public function test_one_usage_per_transaction_and_code_position(): void
    {
        $transaction = Transaction::factory()->create();
        $this->usage($transaction, TransactionPromoCode::POSITION_PRIMARY);
        $this->usage($transaction, TransactionPromoCode::POSITION_SECONDARY);

        $this->expectException(QueryException::class);
        $this->usage($transaction, TransactionPromoCode::POSITION_PRIMARY);
    }

    public function test_usages_are_deleted_with_their_transaction(): void
    {
        $transaction = Transaction::factory()->create();
        $this->usage($transaction, TransactionPromoCode::POSITION_PRIMARY);

        $transaction->delete();

        $this->assertSame(0, TransactionPromoCode::count());
    }

    public function test_promo_code_used_by_a_transaction_cannot_be_deleted(): void
    {
        $transaction = Transaction::factory()->create();
        $promoCode = PromoCode::factory()->create(['platform_id' => $transaction->platform_id]);
        $this->usage($transaction, TransactionPromoCode::POSITION_PRIMARY, $promoCode);

        $this->expectException(QueryException::class);
        $promoCode->delete();
    }
}
