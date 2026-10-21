<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AccountSeeder::class,
            FunnelSeeder::class,
            PromoSourceSeeder::class,
        ]);

        // Demo performance data for local development only, so tests keep a clean database.
        if (app()->isLocal()) {
            fake()->seed(2026);

            $this->call([
                PlatformSeeder::class,
                DownloaderSeeder::class,
                TransactionSeeder::class,
            ]);
        }
    }
}
