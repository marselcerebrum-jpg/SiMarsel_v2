<?php

namespace App\Services;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Collection;

class PlatformService
{
    /**
     * The global master platforms, read-only for every module.
     *
     * @return Collection<int, Platform>
     */
    public function list(): Collection
    {
        return Platform::query()->orderBy('id')->get();
    }
}
