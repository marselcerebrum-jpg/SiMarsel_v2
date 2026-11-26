<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CreativeDeskOptionSeeder extends Seeder
{
    /**
     * Default dropdown master options for Create Konten. Existing IDs are left untouched so customized labels persist.
     */
    public function run(): void
    {
        $now = now();

        $defaults = [
            'app' => [
                ['JadiASN', ''],
                ['JadiSEKDIN', ''],
                ['JadiBUMN', ''],
                ['JadiPCPM', ''],
                ['JadiPrajurit', ''],
                ['JadiPolisi', ''],
                ['JadiBeasiswa', ''],
                ['Cerebrum', ''],
                ['JagoTPA', ''],
                ['TOEFL Academy', ''],
            ],
            'talentName' => [
                ['Talent 1', ''],
                ['Talent 2', ''],
            ],
            'script' => [
                ['Draft', 'Draft'],
                ['Skrip ready', 'Ready'],
            ],
            'talent' => [
                ['Belum take', 'Belum'],
                ['Done', 'Done'],
                ['Tidak perlu', 'Tidak perlu'],
            ],
            'creative' => [
                ['Belum diedit', 'Belum'],
                ['Done', 'Done'],
            ],
            'qc' => [
                ['Belum QC', ''],
                ['Done', 'Done'],
                ['Revisi', 'Revisi'],
            ],
            'priority' => [
                ['Reguler', 'Reguler'],
                ['Trend', 'Trend'],
                ['Urgent', 'Urgent'],
            ],
        ];

        $rows = [];
        foreach ($defaults as $key => $items) {
            foreach ($items as $sort => [$label, $semantic]) {
                $rows[] = [
                    'id' => "{$key}:".($sort + 1),
                    'key' => $key,
                    'label' => $label,
                    'semantic' => $semantic,
                    'sort' => $sort,
                    'archived' => false,
                    'category' => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('options')->insertOrIgnore($rows);
    }
}
