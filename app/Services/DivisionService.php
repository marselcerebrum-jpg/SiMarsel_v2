<?php

namespace App\Services;

use App\Models\Division;
use Illuminate\Database\Eloquent\Collection;

class DivisionService
{
    /**
     * @return Collection<int, Division>
     */
    public function list(): Collection
    {
        return Division::orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Division
    {
        return Division::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Division $division, array $data): Division
    {
        $division->update($data);

        return $division;
    }

    public function delete(Division $division): void
    {
        $division->delete();
    }
}
