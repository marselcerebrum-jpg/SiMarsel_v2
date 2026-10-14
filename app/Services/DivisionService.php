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
        return Division::withCount('accounts')->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Division
    {
        return Division::create($data)->loadCount('accounts');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Division $division, array $data): Division
    {
        $division->update($data);

        return $division->loadCount('accounts');
    }

    public function delete(Division $division): void
    {
        $division->delete();
    }
}
