<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * FIX-15-BE-02 migration on a clean schema and on data stored before it (transactions and promo usages).
 */
class DashboardTransactionMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_17_135819_add_dashboard_fields_and_source_identity_to_transactions_table.php';

    private const NEW_COLUMNS = [
        'markaz_transaction_id', 'markaz_customer_id', 'markaz_ref_id', 'markaz_created_at', 'markaz_paid_at',
        'source_status', 'effective_status', 'is_claim_coupon', 'product_price', 'voucher_discount',
        'admin_fee', 'net_nominal', 'customer_name', 'customer_phone', 'synced_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_clean_schema_has_the_dashboard_fields_and_optional_payment_date(): void
    {
        $this->assertTrue(Schema::hasColumns('transactions', self::NEW_COLUMNS));
        $this->assertTrue($this->column('paid_date')['nullable']);
        $this->assertTrue($this->column('markaz_user_id')['nullable']);
        $this->assertFalse($this->column('is_claim_coupon')['nullable']);
    }

    public function test_clean_schema_stores_pending_and_keys_source_ids_per_platform(): void
    {
        $platformA = $this->legacyPlatform();
        $platformB = $this->legacyPlatform('jadiasn');
        $source = ['markaz_transaction_id' => 476770, 'markaz_user_id' => null, 'paid_date' => null, 'paid_time' => null, 'source_status' => 0];

        $pendingId = $this->legacyTransaction($platformA, 'TRX-476770', $source);
        $this->legacyTransaction($platformB, 'TRX-476770', $source);

        $this->assertNull(DB::table('transactions')->find($pendingId)->paid_date);
        $this->assertSame(2, DB::table('transactions')->where('markaz_transaction_id', 476770)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->legacyTransaction($platformA, 'TRX-OTHER', ['markaz_transaction_id' => 476770]);
    }

    public function test_ac03_existing_transactions_and_promo_usages_survive_the_migration(): void
    {
        $this->rollBack();
        $this->assertFalse(Schema::hasColumn('transactions', 'markaz_transaction_id'));

        $platformId = $this->legacyPlatform();
        $transactionId = $this->legacyTransaction($platformId, 'cerebrum-476770');
        $otherId = $this->legacyTransaction($platformId, 'cerebrum-476771');
        $usageId = DB::table('transaction_promo_codes')->insertGetId([
            'transaction_id' => $transactionId,
            'raw_code' => 'CPNS26',
            'code_position' => 1,
            'platform_id_snapshot' => $platformId,
            'code_snapshot' => 'CPNS26',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migrateUp();

        $stored = DB::table('transactions')->find($transactionId);
        $this->assertSame('cerebrum-476770', $stored->trx_id);
        $this->assertSame(445566, (int) $stored->markaz_user_id);
        $this->assertSame('2026-11-10', $stored->paid_date);
        $this->assertSame('09:05:00', $stored->paid_time);
        $this->assertSame(2, DB::table('transactions')->count());
        $this->assertNotNull(DB::table('transactions')->find($otherId));

        // History never recorded these source values; they stay empty instead of copying local timestamps.
        foreach (array_diff(self::NEW_COLUMNS, ['is_claim_coupon']) as $column) {
            $this->assertNull($stored->{$column}, "{$column} must stay NULL on history.");
        }
        $this->assertEquals(0, $stored->is_claim_coupon);

        $usage = DB::table('transaction_promo_codes')->find($usageId);
        $this->assertSame($transactionId, (int) $usage->transaction_id);

        // A Pending row without paid_date can join the migrated history, next to its promo usages.
        $pendingId = $this->legacyTransaction($platformId, 'cerebrum-476772', [
            'markaz_transaction_id' => 476772, 'markaz_user_id' => null, 'paid_date' => null, 'paid_time' => null, 'source_status' => 0,
        ]);
        $this->assertNull(DB::table('transactions')->find($pendingId)->paid_date);
        $this->assertSame(1, DB::table('transaction_promo_codes')->where('transaction_id', $transactionId)->count());

        $foreignKey = collect(Schema::getForeignKeys('transaction_promo_codes'))->firstWhere('columns', ['transaction_id']);
        $this->assertSame('transactions', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);

        DB::table('transactions')->where('id', $transactionId)->delete();
        $this->assertNull(DB::table('transaction_promo_codes')->find($usageId));

        $this->expectException(QueryException::class);
        DB::table('transaction_promo_codes')->insert([
            'transaction_id' => 999999,
            'raw_code' => 'CPNS26',
            'code_position' => 1,
            'platform_id_snapshot' => $platformId,
            'code_snapshot' => 'CPNS26',
        ]);
    }

    public function test_rollback_is_refused_while_a_transaction_has_no_payment_date(): void
    {
        $platformId = $this->legacyPlatform();
        $pendingId = $this->legacyTransaction($platformId, 'cerebrum-500001', ['paid_date' => null, 'paid_time' => null, 'source_status' => 0]);

        try {
            $this->rollBack();
            $this->fail('Rollback must not drop Pending rows or force a payment date on them.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('paid_date', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('transactions', 'markaz_transaction_id'));
        $this->assertTrue($this->column('paid_date')['nullable']);
        $this->assertNull(DB::table('transactions')->find($pendingId)->paid_date);
    }

    public function test_rollback_is_refused_while_a_transaction_has_no_legacy_user_id(): void
    {
        $this->legacyTransaction($this->legacyPlatform(), 'TRX-2', ['markaz_user_id' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('markaz_user_id');
        $this->rollBack();
    }

    public function test_rollback_is_refused_while_a_trx_id_is_shared_by_two_platforms(): void
    {
        $this->legacyTransaction($this->legacyPlatform(), 'TRX-1', ['markaz_transaction_id' => 1]);
        $this->legacyTransaction($this->legacyPlatform('jadiasn'), 'TRX-1', ['markaz_transaction_id' => 1]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('trx_id');
        $this->rollBack();
    }

    public function test_rollback_restores_the_previous_schema_when_the_data_allows_it(): void
    {
        $platformId = $this->legacyPlatform();
        $transactionId = $this->legacyTransaction($platformId, 'cerebrum-600001', ['markaz_transaction_id' => 600001]);

        $this->rollBack();

        $this->assertFalse(Schema::hasColumns('transactions', ['markaz_transaction_id']));
        $this->assertFalse($this->column('paid_date')['nullable']);
        $this->assertFalse($this->column('markaz_user_id')['nullable']);
        $this->assertSame('cerebrum-600001', DB::table('transactions')->find($transactionId)->trx_id);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->legacyTransaction($platformId, 'cerebrum-600001');
    }

    private function rollBack(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
    }

    private function migrateUp(): void
    {
        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $name): array
    {
        return collect(Schema::getColumns('transactions'))->firstWhere('name', $name);
    }

    private function legacyPlatform(string $slug = 'cerebrum'): int
    {
        return DB::table('platforms')->insertGetId([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function legacyTransaction(int $platformId, string $trxId, array $overrides = []): int
    {
        return DB::table('transactions')->insertGetId([
            'platform_id' => $platformId,
            'markaz_user_id' => 445566,
            'trx_id' => $trxId,
            'paid_date' => '2026-11-10',
            'paid_time' => '09:05:00',
            'payment_method' => 'QRIS',
            'nominal' => 182000,
            'product' => 'Bimbel Persiapan UTBK SNBT 2027 - Masa Aktif 1 Tahun',
            'product_type' => 'Perdana',
            'customer_email' => 'amahs7391@gmail.com',
            'status_message' => 'Success',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }
}
