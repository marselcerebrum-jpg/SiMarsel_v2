<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FIX-15-BE-03 migration on data stored before it: daily downloaders, transactions, and promo usages.
 */
class DashboardSnapshotMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_19_112726_create_dashboard_snapshots_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_existing_downloaders_transactions_and_promo_usages_survive_the_migration(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('dashboard_snapshots'));

        $platformId = DB::table('platforms')->insertGetId(['slug' => 'cerebrum', 'name' => 'Cerebrum', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('downloaders')->insert(['platform_id' => $platformId, 'date' => '2026-11-15', 'total' => 120, 'created_at' => now(), 'updated_at' => now()]);
        $transactionId = DB::table('transactions')->insertGetId([
            'platform_id' => $platformId,
            'markaz_user_id' => 445566,
            'trx_id' => 'cerebrum-476770',
            'nominal' => 182000,
            'status_message' => 'Pending',
            'source_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $usageId = DB::table('transaction_promo_codes')->insertGetId([
            'transaction_id' => $transactionId,
            'raw_code' => 'CPNS26',
            'code_position' => 1,
            'platform_id_snapshot' => $platformId,
            'code_snapshot' => 'CPNS26',
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertSame(120, (int) DB::table('downloaders')->where('platform_id', $platformId)->value('total'));
        $this->assertNull(DB::table('transactions')->find($transactionId)->paid_date);
        $this->assertSame($transactionId, (int) DB::table('transaction_promo_codes')->find($usageId)->transaction_id);
        $this->assertSame(0, DB::table('dashboard_snapshots')->count());

        DB::table('dashboard_snapshots')->insert([
            'platform_id' => $platformId,
            'dataset' => 'downloaders',
            'date_from' => '2026-11-15',
            'date_to' => '2026-11-15',
            'cutoff_at' => '2026-11-15 13:00:00',
            'is_complete' => true,
            'payload' => json_encode(['total' => 70, 'daily' => ['2026-11-15' => 70]]),
            'recorded_at' => '2026-11-15 13:04:00',
        ]);
        $this->assertSame(120, (int) DB::table('downloaders')->where('platform_id', $platformId)->value('total'));

        $this->expectException(QueryException::class);
        DB::table('platforms')->where('id', $platformId)->delete();
    }

    public function test_a_snapshot_needs_an_existing_platform_and_rollback_drops_only_the_snapshots(): void
    {
        try {
            DB::table('dashboard_snapshots')->insert([
                'platform_id' => 999999, 'dataset' => 'transactions', 'date_from' => '2026-11-15', 'date_to' => '2026-11-15',
                'cutoff_at' => '2026-11-15 13:00:00', 'is_complete' => false, 'payload' => '{}', 'recorded_at' => '2026-11-15 13:04:00',
            ]);
            $this->fail('A snapshot must belong to an existing platform.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('dashboard_snapshots')->count());
        }

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('dashboard_snapshots'));
        $this->assertTrue(Schema::hasTable('downloaders'));
        $this->assertTrue(Schema::hasTable('sync_logs'));
    }
}
