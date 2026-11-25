<?php

namespace App\Console\Commands;

use App\Services\CreativeDeskDashboardDataValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('creative-desk:validate-dashboard-data')]
#[Description('Check (read-only) CreativeDesk Dashboard data sources, schema readiness, grain integrity, and account references (SAV-15)')]
class ValidateCreativeDeskDashboardData extends Command
{
    public function handle(CreativeDeskDashboardDataValidator $validator): int
    {
        $results = $validator->validate();

        $this->table(['Pemeriksaan', 'Hasil', 'Detail'], array_map(fn (array $result) => array_values($result), $results));

        if (! $validator->passed($results)) {
            $this->error('Validasi menemukan gap/FAIL. Tidak ada perubahan database yang dilakukan.');

            return self::FAILURE;
        }

        $this->info('Validasi sumber data CreativeDesk Dashboard lulus (read-only).');

        return self::SUCCESS;
    }
}
