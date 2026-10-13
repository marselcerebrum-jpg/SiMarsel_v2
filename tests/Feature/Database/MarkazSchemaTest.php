<?php

namespace Tests\Feature\Database;

use App\Models\Downloader;
use App\Models\MarkazApiToken;
use App\Models\Platform;
use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarkazSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_trx_id_must_be_unique(): void
    {
        Transaction::factory()->create(['trx_id' => 'TRX-001']);

        $this->expectException(UniqueConstraintViolationException::class);

        Transaction::factory()->create(['trx_id' => 'TRX-001']);
    }

    public function test_downloader_platform_and_date_must_be_unique(): void
    {
        $platform = Platform::factory()->create();
        Downloader::factory()->for($platform)->create(['date' => '2026-10-01']);

        $this->expectException(UniqueConstraintViolationException::class);

        Downloader::factory()->for($platform)->create(['date' => '2026-10-01']);
    }

    public function test_downloader_upsert_keeps_one_row_per_platform_and_date(): void
    {
        $platform = Platform::factory()->create();
        $other = Platform::factory()->create();

        foreach ([10, 25] as $total) {
            Downloader::upsert(
                [['platform_id' => $platform->id, 'date' => '2026-10-01', 'total' => $total]],
                ['platform_id', 'date'],
                ['total'],
            );
        }
        Downloader::factory()->for($other)->create(['date' => '2026-10-01', 'total' => 5]);

        $this->assertDatabaseCount('downloaders', 2);
        $this->assertSame(25, Downloader::where('platform_id', $platform->id)->value('total'));
    }

    public function test_markaz_api_token_is_encrypted_and_hidden(): void
    {
        $token = MarkazApiToken::create([
            'token' => 'secret-markaz-token',
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertNotSame('secret-markaz-token', DB::table('markaz_api_tokens')->value('token'));
        $this->assertSame('secret-markaz-token', $token->fresh()->token);
        $this->assertArrayNotHasKey('token', $token->toArray());
    }
}
