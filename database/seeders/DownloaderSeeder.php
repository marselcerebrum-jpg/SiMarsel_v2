<?php

namespace Database\Seeders;

use App\Models\Downloader;
use App\Models\Platform;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class DownloaderSeeder extends Seeder
{
    public const MONTHS = 6;

    /**
     * Seed one downloader total per platform per day for the last months up to today.
     * The minimum daily total (40) is above the most transactions a day gets, so conversion stays below 100%.
     */
    public function run(): void
    {
        $days = CarbonPeriod::create(today()->subMonths(self::MONTHS), today());
        $now = now();

        Platform::all()->each(function (Platform $platform) use ($days, $now) {
            $rows = [];

            foreach ($days as $day) {
                $rows[] = [
                    ...Downloader::factory()->raw(['platform_id' => $platform->id, 'date' => $day->toDateString()]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Downloader::upsert($rows, ['platform_id', 'date'], ['total', 'updated_at']);
        });
    }
}
