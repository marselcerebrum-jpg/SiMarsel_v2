<?php

namespace App\Models;

use Database\Factories\PromoSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class PromoSource extends Model
{
    /** @use HasFactory<PromoSourceFactory> */
    use HasFactory;

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'source_id');
    }
}
