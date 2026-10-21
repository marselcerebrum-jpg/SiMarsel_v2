<?php

namespace App\Models;

use Database\Factories\FunnelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Funnel extends Model
{
    /** @use HasFactory<FunnelFactory> */
    use HasFactory;

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }
}
