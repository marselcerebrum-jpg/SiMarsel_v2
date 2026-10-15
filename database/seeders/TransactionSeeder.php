<?php

namespace Database\Seeders;

use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonPeriod;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    /**
     * Seed Markaz-like transactions per platform per day for the same days as the downloaders.
     * Skipped when transactions already exist, so running the seeder again does not double the data.
     */
    public function run(): void
    {
        if (Transaction::exists()) {
            $this->command?->warn('Transactions already exist, skipping TransactionSeeder.');

            return;
        }

        $days = CarbonPeriod::create(today()->subMonths(DownloaderSeeder::MONTHS), today());
        $customers = array_map(fn () => TransactionFactory::customerEmail(), range(1, 1500));
        $now = now();

        Platform::all()->each(function (Platform $platform) use ($days, $customers, $now) {
            $rows = [];

            foreach ($days as $day) {
                foreach (range(1, fake()->numberBetween(2, 15)) as $ignored) {
                    $rows[] = [
                        ...Transaction::factory()
                            ->forPlatform($platform)
                            ->when(fake()->boolean(10), fn (TransactionFactory $factory) => $factory->failed())
                            ->when(fake()->boolean(3), fn (TransactionFactory $factory) => $factory->withoutPaymentMethod())
                            ->raw([
                                'paid_date' => $day->toDateString(),
                                'customer_email' => fake()->randomElement($customers),
                            ]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Transaction::insert($chunk);
            }
        });
    }
}
