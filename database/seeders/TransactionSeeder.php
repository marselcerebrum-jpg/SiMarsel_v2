<?php

namespace Database\Seeders;

use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Services\PromoCodeUsageService;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class TransactionSeeder extends Seeder
{
    /**
     * Codes not registered in the promo master, used only in the last days: like codes that first arrive
     * with a Markaz sync, so they show up in the unregistered-code alert. Spelling varies as Markaz writes it.
     */
    public const NEW_CODES = ['ADMINRINA', 'adminrina', 'ADMINFAJAR', 'BIMBELSKD26', 'LIVEJUMAT'];

    public const NEW_CODE_DAYS = 14;

    /**
     * Seed Markaz-like transactions per platform per day for the same days as the downloaders, with the
     * promo codes of PromoCodeSeeder. Skipped when transactions already exist, so running the seeder again
     * does not double the data.
     */
    public function run(PromoCodeUsageService $promoCodeUsageService): void
    {
        if (Transaction::exists()) {
            $this->command?->warn('Transactions already exist, skipping TransactionSeeder.');

            return;
        }

        $days = CarbonPeriod::create(today()->subMonths(DownloaderSeeder::MONTHS), today());
        $customers = array_map(fn () => TransactionFactory::customerEmail(), range(1, 1500));
        $now = now();

        Platform::all()->each(function (Platform $platform) use ($days, $customers, $now) {
            $codes = PromoCode::where('platform_id', $platform->id)
                ->where('code', '!=', PromoCodeSeeder::RESELLER_CODE)
                ->pluck('code')
                ->all();
            $rows = [];

            foreach ($days as $day) {
                foreach (range(1, fake()->numberBetween(2, 15)) as $ignored) {
                    $promoCode = $this->promoCode($codes, $day);

                    $rows[] = [
                        ...Transaction::factory()
                            ->forPlatform($platform)
                            ->when(fake()->boolean(10), fn (TransactionFactory $factory) => $factory->failed())
                            ->when(fake()->boolean(3), fn (TransactionFactory $factory) => $factory->withoutPaymentMethod())
                            ->raw([
                                'paid_date' => $day->toDateString(),
                                'customer_email' => fake()->randomElement($customers),
                            ]),
                        'promo_code' => $promoCode === null ? null : json_encode($promoCode),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                Transaction::insert($chunk);
            }
        });

        // insert() skips the observer, so the usages are processed the same way a synced transaction is.
        Transaction::query()
            ->whereNotNull('promo_code')
            ->chunkById(500, function (Collection $transactions) use ($promoCodeUsageService) {
                // Not each(): normalize() returns false for an anomaly, which would stop the chunk.
                foreach ($transactions as $transaction) {
                    $promoCodeUsageService->normalize($transaction);
                }
            });
    }

    /**
     * The promo_code payload of one transaction: mostly no code, otherwise one registered code of the platform,
     * sometimes written differently, combined with a reseller marker or a second code, a new code in the last
     * days, or rarely three codes (an anomaly).
     *
     * @param  list<string>  $codes
     * @return list<string>|null
     */
    private function promoCode(array $codes, CarbonInterface $day): ?array
    {
        if ($codes === [] || ! fake()->boolean(35)) {
            return null;
        }

        $code = fake()->randomElement($codes);

        return match (true) {
            $day->gte(today()->subDays(self::NEW_CODE_DAYS)) && fake()->boolean(20) => [fake()->randomElement(self::NEW_CODES)],
            fake()->boolean(2) => fake()->randomElements($codes, 3, true),
            fake()->boolean(10) => [PromoCodeSeeder::RESELLER_CODE, $code],
            count($codes) > 1 && fake()->boolean(6) => fake()->randomElements($codes, 2),
            fake()->boolean(8) => [fake()->randomElement([strtolower($code), ' '.$code.' '])],
            default => [$code],
        };
    }
}
