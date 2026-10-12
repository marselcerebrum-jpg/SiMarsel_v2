<?php

namespace App\Services;

use App\Models\Division;

class DivisionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Division
    {
        return Division::create($data);
    }
}
