<?php

namespace Database\Seeders;

use App\Models\Funnel;
use Illuminate\Database\Seeder;

class FunnelSeeder extends Seeder
{
    public const FUNNELS = ['Aplikasi', 'Marketing', 'Sales', 'Ads', 'Live', 'Lainnya'];

    /**
     * Seed the initial promo funnels. "Tanpa Funnel" is an analysis group, not a record.
     */
    public function run(): void
    {
        foreach (self::FUNNELS as $name) {
            Funnel::firstOrCreate(['name' => $name]);
        }
    }
}
