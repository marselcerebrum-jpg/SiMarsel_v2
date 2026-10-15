<?php

namespace Database\Seeders;

use App\Models\Platform;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public const PLATFORMS = [
        'CEREBRUM',
        'JADIASN',
        'JADIBUMN',
        'JADIPOLISI',
        'JADIPRAJURIT',
        'JADISEKDIN',
        'JADIBEASISWA',
        'PSIKOTESKERJA',
        'JAGOTPA',
        'JADIPPPK',
        'JADIOJK',
        'JADIPCPM',
        'TOEFLACADEMY',
    ];

    /**
     * Seed the Markaz platforms; the slug is the lowercase platform name.
     */
    public function run(): void
    {
        foreach (self::PLATFORMS as $name) {
            Platform::updateOrCreate(['slug' => strtolower($name)], ['name' => $name]);
        }
    }
}
