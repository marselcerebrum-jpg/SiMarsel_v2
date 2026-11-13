<?php

namespace App\Console\Commands;

use App\Services\TaskAssignmentBackfillService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:backfill-assignments {--apply : Create the missing assignments instead of only reporting}')]
#[Description('Compare tasks.pic_id with task_assignments and backfill PICs that have no assignment yet')]
class BackfillTaskAssignments extends Command
{
    public function handle(TaskAssignmentBackfillService $backfill): int
    {
        $this->summary('Sebelum', $backfill->report());

        if (! $this->option('apply')) {
            $this->line('Mode laporan. Jalankan dengan --apply untuk membuat assignment yang belum ada.');

            return self::SUCCESS;
        }

        $created = $backfill->apply();
        $this->info("{$created} assignment dibuat.");
        $this->summary('Sesudah', $backfill->report());

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $report
     */
    private function summary(string $title, array $report): void
    {
        $this->line($title.':');
        $this->table(['Metrik', 'Jumlah'], collect($report)->map(fn (int $count, string $metric) => [$metric, $count])->values()->all());
    }
}
