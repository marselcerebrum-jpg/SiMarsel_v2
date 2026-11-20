<?php

namespace Tests\Feature\Database;

use App\Enums\MarkazTransactionStatus;
use App\Models\Platform;
use App\Models\Transaction;
use App\Models\TransactionPromoCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FIX-15-BE-02: transactions stores every Markaz status and is keyed by (platform_id, markaz_transaction_id).
 */
class DashboardTransactionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ac01_pending_is_stored_without_a_payment_date(): void
    {
        $pending = Transaction::factory()->pending()->create([
            'markaz_created_at' => '2026-11-10 23:50:00',
            'synced_at' => '2026-11-11 00:05:00',
        ])->fresh();

        $this->assertNull($pending->paid_date);
        $this->assertNull($pending->paid_time);
        $this->assertNull($pending->markaz_paid_at);
        $this->assertNull($pending->paidAt());
        $this->assertSame(MarkazTransactionStatus::Pending->value, $pending->source_status);
        $this->assertSame('2026-11-10 23:50:00', $pending->markaz_created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-11 00:05:00', $pending->synced_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $pending->promoCodeUsages()->count());
    }

    public function test_ac01_success_keeps_its_last_valid_payment_date_when_markaz_paid_at_becomes_null(): void
    {
        $success = Transaction::factory()->create([
            'source_status' => MarkazTransactionStatus::Success->value,
            'markaz_created_at' => '2026-11-10 23:50:00',
            'markaz_paid_at' => '2026-11-11 00:10:00',
            'paid_date' => '2026-11-11',
            'paid_time' => '00:10:00',
            'synced_at' => '2026-11-11 00:15:00',
        ]);

        $success->update(['markaz_paid_at' => null, 'synced_at' => '2026-11-11 01:15:00']);
        $stored = $success->fresh();

        $this->assertNull($stored->markaz_paid_at);
        $this->assertSame('2026-11-11', $stored->paid_date->toDateString());
        $this->assertSame('00:10:00', $stored->paid_time);
        $this->assertSame('2026-11-11 00:10:00', $stored->paidAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10', $stored->markaz_created_at->toDateString());
    }

    public function test_ac02_the_same_markaz_id_and_trx_id_are_stored_once_per_platform(): void
    {
        [$platformA, $platformB] = Platform::factory()->count(2)->create();

        $first = Transaction::factory()->create(['platform_id' => $platformA->id, 'markaz_transaction_id' => '476770', 'trx_id' => 'TRX-476770']);
        $second = Transaction::factory()->create(['platform_id' => $platformB->id, 'markaz_transaction_id' => '476770', 'trx_id' => 'TRX-476770']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Transaction::where('trx_id', 'TRX-476770')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        Transaction::factory()->create(['platform_id' => $platformA->id, 'markaz_transaction_id' => '476770', 'trx_id' => 'TRX-OTHER']);
    }

    public function test_ac02_history_without_a_verified_source_id_may_share_a_platform(): void
    {
        $platform = Platform::factory()->create();

        Transaction::factory()->count(2)->create(['platform_id' => $platform->id, 'markaz_transaction_id' => null]);

        $this->assertSame(2, Transaction::where('platform_id', $platform->id)->whereNull('markaz_transaction_id')->count());
    }

    public function test_ac02_indexes_follow_the_source_key_and_report_dates(): void
    {
        $indexes = collect(Schema::getIndexes('transactions'))->keyBy(fn (array $index) => implode(',', $index['columns']));

        $this->assertTrue($indexes['platform_id,markaz_transaction_id']['unique']);
        $this->assertFalse($indexes['trx_id']['unique']);
        $this->assertFalse($indexes['platform_id,markaz_ref_id']['unique']);
        $this->assertFalse($indexes['markaz_created_at']['unique']);
        $this->assertFalse($indexes['paid_date,paid_time']['unique']);
    }

    public function test_ac03_group_and_claim_fields_are_read_back(): void
    {
        $platform = Platform::factory()->create();
        $buyer = Transaction::factory()->create([
            'platform_id' => $platform->id,
            'markaz_transaction_id' => '900001',
            'markaz_customer_id' => 445566,
            'markaz_ref_id' => null,
            'markaz_created_at' => '2026-11-10 09:00:00',
            'markaz_paid_at' => '2026-11-10 09:05:00',
            'paid_date' => '2026-11-10',
            'paid_time' => '09:05:00',
            'source_status' => MarkazTransactionStatus::Pending->value,
            'effective_status' => MarkazTransactionStatus::Success->value,
            'product_price' => 190000,
            'voucher_discount' => 10000,
            'admin_fee' => 2000,
            'nominal' => 182000,
            'net_nominal' => 180000,
            'customer_name' => 'Amah Sari',
            'customer_email' => 'amahs7391@gmail.com',
            'customer_phone' => '081234567890',
            'synced_at' => '2026-11-10 09:10:00',
        ]);
        $member = Transaction::factory()->create([
            'platform_id' => $platform->id,
            'markaz_transaction_id' => '900002',
            'markaz_ref_id' => '900001',
        ]);
        $claim = Transaction::factory()->create([
            'platform_id' => $platform->id,
            'is_claim_coupon' => true,
            'product_price' => 0,
            'voucher_discount' => 0,
            'admin_fee' => 0,
            'nominal' => 0,
            'net_nominal' => 0,
        ]);

        $stored = $buyer->fresh();
        $this->assertSame('900001', $stored->markaz_transaction_id);
        $this->assertSame(445566, $stored->markaz_customer_id);
        $this->assertNull($stored->markaz_ref_id);
        $this->assertSame('2026-11-10 09:05:00', $stored->markaz_paid_at->format('Y-m-d H:i:s'));
        $this->assertSame(MarkazTransactionStatus::Success, MarkazTransactionStatus::forReport($stored->effective_status, $stored->source_status));
        $this->assertFalse($stored->is_claim_coupon);
        $this->assertSame(['190000.00', '10000.00', '2000.00', '182000.00', '180000.00'], [
            $stored->product_price, $stored->voucher_discount, $stored->admin_fee, $stored->nominal, $stored->net_nominal,
        ]);
        $this->assertSame(['Amah Sari', 'amahs7391@gmail.com', '081234567890'], [
            $stored->customer_name, $stored->customer_email, $stored->customer_phone,
        ]);

        $this->assertSame(
            [$member->id],
            Transaction::where('platform_id', $platform->id)->where('markaz_ref_id', $stored->markaz_transaction_id)->pluck('id')->all(),
        );
        $this->assertTrue($claim->fresh()->is_claim_coupon);
        $this->assertFalse(Transaction::factory()->create()->fresh()->is_claim_coupon);
    }

    public function test_ac03_promo_usages_keep_their_foreign_key_to_the_local_transaction_id(): void
    {
        $transaction = Transaction::factory()->create(['promo_code' => ['CPNS26']]);
        $usage = TransactionPromoCode::where('transaction_id', $transaction->id)->sole();

        $foreignKey = collect(Schema::getForeignKeys('transaction_promo_codes'))
            ->firstWhere('columns', ['transaction_id']);

        $this->assertSame('transactions', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertTrue($usage->transaction->is($transaction));
    }
}
