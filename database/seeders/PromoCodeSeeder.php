<?php

namespace Database\Seeders;

use App\Models\Funnel;
use App\Models\Platform;
use App\Models\PromoCode;
use App\Models\PromoSource;
use App\Services\PromoCodeService;
use Illuminate\Database\Seeder;

class PromoCodeSeeder extends Seeder
{
    /**
     * Admin codes registered on several platforms, each platform with its own classification.
     */
    public const SHARED_CODES = ['ADMINHANA', 'ADMINNIZAR', 'ADMINSALMA'];

    /**
     * Program codes per platform slug, next to the "ADMIN" + platform code every platform gets.
     */
    public const PROGRAM_CODES = [
        'cerebrum' => ['BIMBELSNBT', 'BIMBELTKA'],
        'jadiasn' => ['BIMBELCPNS'],
        'jadibumn' => ['BIMBELBUMN'],
        'jadipolisi' => ['BIMBELPOLRI'],
        'jadiprajurit' => ['BIMBELTNI'],
        'jadisekdin' => ['BIMBELSEKDIN'],
        'jadibeasiswa' => ['BIMBELLPDP'],
        'psikoteskerja' => ['LATIHANPSIKOTES'],
        'jagotpa' => ['LATIHANTPA'],
        'jadipppk' => ['BIMBELPPPK'],
        'jadiojk' => ['BIMBELOJK'],
        'jadipcpm' => ['BIMBELPCPM'],
        'toeflacademy' => ['TOEFLITP'],
    ];

    /**
     * Reseller marker written by Markaz in position 1; registered without a classification.
     */
    public const RESELLER_CODE = 'RES';

    /**
     * Funnel and source names as used in the promo master; null leaves it empty.
     */
    public const CLASSIFICATIONS = [
        ['Artikel', null],
        ['Marketing', 'Sosmed'],
        ['Aplikasi', 'Slider'],
        ['Live', 'Live'],
        [null, null],
    ];

    /**
     * Seed demo promo code masters with their metadata version, for the codes TransactionSeeder writes.
     * Skipped when promo codes already exist, so running the seeder again does not fail on duplicates.
     */
    public function run(PromoCodeService $promoCodeService): void
    {
        if (PromoCode::exists()) {
            $this->command?->warn('Promo codes already exist, skipping PromoCodeSeeder.');

            return;
        }

        $names = collect(self::CLASSIFICATIONS);
        $funnels = $names->pluck(0)->filter()->unique()->mapWithKeys(fn (string $name) => [$name => Funnel::firstOrCreate(['name' => $name])->id]);
        $sources = $names->pluck(1)->filter()->unique()->mapWithKeys(fn (string $name) => [$name => PromoSource::firstOrCreate(['name' => $name])->id]);

        Platform::all()->each(function (Platform $platform) use ($promoCodeService, $funnels, $sources) {
            $codes = [
                'ADMIN'.strtoupper($platform->slug) => ['Artikel', null],
                self::RESELLER_CODE => [null, null],
            ];

            foreach (self::PROGRAM_CODES[$platform->slug] ?? [] as $code) {
                $codes[$code] = fake()->randomElement(self::CLASSIFICATIONS);
            }

            foreach (self::SHARED_CODES as $code) {
                if (fake()->boolean(60)) {
                    $codes[$code] = fake()->randomElement(self::CLASSIFICATIONS);
                }
            }

            foreach ($codes as $code => [$funnel, $source]) {
                $promoCodeService->create(
                    $platform->id,
                    $code,
                    $funnel === null ? null : $funnels[$funnel],
                    $source === null ? null : $sources[$source],
                );
            }
        });
    }
}
