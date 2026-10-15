<?php

namespace Tests\Feature\Seeders;

use App\Models\Account;
use App\Models\Downloader;
use App\Models\Platform;
use App\Models\Transaction;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_skips_demo_data_outside_local(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('accounts', ['username' => 'manager']);
        $this->assertSame(0, Platform::count());
        $this->assertSame(0, Downloader::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_database_seeder_fills_markaz_like_demo_data_in_local(): void
    {
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            array_map('strtolower', PlatformSeeder::PLATFORMS),
            Platform::orderBy('id')->pluck('slug')->all(),
        );
        $this->assertDatabaseHas('platforms', ['slug' => 'jadiasn', 'name' => 'JADIASN']);

        $days = (int) today()->subMonths(6)->diffInDays(today()) + 1;
        $this->assertSame($days * count(PlatformSeeder::PLATFORMS), Downloader::count());
        $this->assertSame(0, Downloader::where('total', '<', 40)->count());

        $this->assertGreaterThan(0, Transaction::where('status_message', '!=', Transaction::STATUS_SUCCESS)->count());
        $this->assertGreaterThan(0, Transaction::whereNull('payment_method')->count());
        $this->assertSame(Transaction::count(), Transaction::distinct()->count('trx_id'));

        Transaction::with('platform')->limit(200)->get()->each(function (Transaction $transaction) {
            $this->assertMatchesRegularExpression('/^'.$transaction->platform->slug.'-\d+$/', $transaction->trx_id);
            $this->assertStringEndsWith('@gmail.com', $transaction->customer_email);
        });

        $query = '?date_from='.today()->startOfMonth()->toDateString().'&date_to='.today()->toDateString();
        $this->actingAs(Account::where('username', 'manager')->first());

        $this->getJson('/api/performance/summary'.$query)->assertOk()->assertJsonPath('data.is_empty', false);
        $this->getJson('/api/performance/payment-methods'.$query)->assertOk()->assertJsonPath('data.is_empty', false);
        $this->getJson('/api/performance/summary'.$query.'&platform=jadiasn')->assertOk()->assertJsonPath('data.is_empty', false);
    }

    public function test_seeding_again_does_not_duplicate_demo_data(): void
    {
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);
        $counts = [Platform::count(), Downloader::count(), Transaction::count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [Platform::count(), Downloader::count(), Transaction::count()]);
    }
}
