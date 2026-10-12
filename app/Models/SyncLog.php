<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'platform_id',
    'sync_type',
    'trigger_type',
    'date_from',
    'date_to',
    'status',
    'records_fetched',
    'records_created',
    'records_updated',
    'error_message',
    'started_at',
    'finished_at',
])]
class SyncLog extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'records_fetched' => 'integer',
            'records_created' => 'integer',
            'records_updated' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
