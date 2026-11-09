<?php

namespace Database\Seeders;

use App\Models\TaskCategory;
use Illuminate\Database\Seeder;

class TaskCategorySeeder extends Seeder
{
    /**
     * Category name => [whether its tasks must pass QA before Done, whether they need evidence before submission].
     */
    public const CATEGORIES = [
        'Konten' => [true, true],
        'Desain' => [true, true],
        'Development' => [true, true],
        'Campaign' => [true, true],
        'Riset' => [false, true],
        'Administrasi' => [false, false],
        'Operasional' => [false, false],
    ];

    /**
     * Seed the default task categories and their QA and evidence configuration.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $name => [$requiresReview, $evidenceRequired]) {
            TaskCategory::updateOrCreate(['name' => $name], [
                'requires_review' => $requiresReview,
                'evidence_required' => $evidenceRequired,
            ]);
        }
    }
}
