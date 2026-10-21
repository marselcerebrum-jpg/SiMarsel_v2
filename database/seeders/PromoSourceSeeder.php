<?php

namespace Database\Seeders;

use App\Models\PromoSource;
use Illuminate\Database\Seeder;

class PromoSourceSeeder extends Seeder
{
    public const SOURCES = ['Aplikasi', 'Instagram', 'TikTok', 'Telegram', 'Sales', 'Ads', 'Live', 'Reseller', 'Lainnya'];

    /**
     * Seed the initial promo sources. "Tanpa Sumber" is an analysis group, not a record.
     */
    public function run(): void
    {
        foreach (self::SOURCES as $name) {
            PromoSource::firstOrCreate(['name' => $name]);
        }
    }
}
