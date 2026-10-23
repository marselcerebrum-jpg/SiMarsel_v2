<?php

namespace Database\Seeders;

use App\Models\TaskCategory;
use Illuminate\Database\Seeder;

class TaskCategorySeeder extends Seeder
{
    /**
     * Category name => whether its tasks must pass QA before Done.
     */
    public const CATEGORIES = [
        'Konten' => true,
        'Desain' => true,
        'Development' => true,
        'Campaign' => true,
        'Riset' => false,
        'Administrasi' => false,
        'Operasional' => false,
    ];

    /**
     * Seed the default task categories and their QA configuration.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $name => $requiresReview) {
            TaskCategory::updateOrCreate(['name' => $name], ['requires_review' => $requiresReview]);
        }
    }
}
