<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Task category master; requires_review is the QA configuration applied to tasks of this category.
 */
#[Fillable(['name', 'requires_review'])]
class TaskCategory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_review' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'category_id');
    }
}
