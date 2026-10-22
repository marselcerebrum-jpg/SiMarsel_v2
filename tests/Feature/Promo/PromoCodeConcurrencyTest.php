<?php

namespace Tests\Feature\Promo;

use App\Exceptions\PromoCodeInUseException;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Services\PromoCodeService;
use App\Services\PromoCodeUsageService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two real database connections at the same time, to prove that a promo code's identity cannot change
 * once a transaction uses it, even when both happen concurrently. Needs MySQL row locks.
 */
class PromoCodeConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const SECOND_CONNECTION = 'mysql_concurrent';

    /**
     * MySQL error code for "Lock wait timeout exceeded": the statement waited for the other connection.
     */
    private const LOCK_WAIT_TIMEOUT = 1205;

    private Platform $platform;

    private PromoCode $promoCode;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql') {
            $this->markTestSkipped('Butuh MySQL: test ini memakai dua koneksi database bersamaan dan row lock, yang tidak ada di SQLite.');
        }

        parent::setUp();

        config(['database.connections.'.self::SECOND_CONNECTION => config('database.connections.mysql')]);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->platform = Platform::factory()->create(['slug' => 'jadiasn', 'name' => 'JADIASN']);
        $this->promoCode = app(PromoCodeService::class)->create($this->platform->id, 'CPNS26');
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $second = $this->second();

            while ($second->transactionLevel() > 0) {
                $second->rollBack();
            }

            DB::purge(self::SECOND_CONNECTION);

            // These rows were committed for the second connection to see; remove them so the
            // transaction-based tests that run afterwards start from an empty database.
            foreach (['transaction_promo_codes', 'promo_code_versions', 'promo_codes', 'transactions', 'platforms'] as $table) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    private function second(): Connection
    {
        return DB::connection(self::SECOND_CONNECTION);
    }

    private function assertWaitedForTheOtherConnection(callable $action): void
    {
        try {
            $action();
            $this->fail('Proses ini seharusnya menunggu koneksi lain selesai.');
        } catch (QueryException $exception) {
            $this->assertSame(self::LOCK_WAIT_TIMEOUT, $exception->errorInfo[1] ?? null);
        }
    }

    public function test_identity_change_waits_for_a_usage_being_inserted_and_is_then_rejected(): void
    {
        $transaction = Transaction::factory()->for($this->platform)->create(['promo_code' => null]);

        // Another process is normalizing a transaction that uses CPNS26 and has not committed yet.
        $this->second()->beginTransaction();
        $this->second()->table('transaction_promo_codes')->insert([
            'transaction_id' => $transaction->id,
            'promo_code_id' => $this->promoCode->id,
            'raw_code' => 'CPNS26',
            'code_position' => 1,
            'platform_id_snapshot' => $this->platform->id,
            'code_snapshot' => 'CPNS26',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertWaitedForTheOtherConnection(
            fn () => app(PromoCodeService::class)->update($this->promoCode, ['code' => 'CPNS27']),
        );

        $this->second()->commit();

        try {
            app(PromoCodeService::class)->update($this->promoCode, ['code' => 'CPNS27']);
            $this->fail('Kode yang sudah dipakai seharusnya tidak bisa diganti.');
        } catch (PromoCodeInUseException $exception) {
            $this->assertStringContainsString('sudah digunakan oleh 1 transaksi', $exception->getMessage());
        }

        $this->assertSame('CPNS26', $this->promoCode->fresh()->code);
    }

    public function test_normalization_waits_while_the_identity_is_being_changed_and_never_links_the_renamed_code(): void
    {
        // Another process is renaming CPNS26 to CPNS27 and has not committed yet.
        $this->second()->beginTransaction();
        $this->second()->table('promo_codes')->where('id', $this->promoCode->id)->lockForUpdate()->first();
        $this->second()->table('promo_codes')->where('id', $this->promoCode->id)->update(['code' => 'CPNS27']);

        $this->assertWaitedForTheOtherConnection(
            fn () => Transaction::factory()->for($this->platform)->create(['promo_code' => ['CPNS26']]),
        );

        $this->second()->commit();

        $transaction = Transaction::query()->latest('id')->firstOrFail();
        app(PromoCodeUsageService::class)->normalize($transaction);

        $usage = $transaction->promoCodeUsages()->firstOrFail();
        $this->assertNull($usage->promo_code_id);
        $this->assertSame('CPNS26', $usage->code_snapshot);
    }

    public function test_deleting_waits_for_a_usage_being_inserted_and_is_then_rejected(): void
    {
        $transaction = Transaction::factory()->for($this->platform)->create(['promo_code' => null]);

        $this->second()->beginTransaction();
        $this->second()->table('transaction_promo_codes')->insert([
            'transaction_id' => $transaction->id,
            'promo_code_id' => $this->promoCode->id,
            'raw_code' => 'CPNS26',
            'code_position' => 1,
            'platform_id_snapshot' => $this->platform->id,
            'code_snapshot' => 'CPNS26',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertWaitedForTheOtherConnection(fn () => app(PromoCodeService::class)->delete($this->promoCode));

        $this->second()->commit();

        $this->expectException(PromoCodeInUseException::class);
        app(PromoCodeService::class)->delete($this->promoCode);
    }
}
